<?php
require_once __DIR__ . '/../config/database.php';

// Session management
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---- AUTH HELPERS ----
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/pages/login.php');
        exit;
    }
}

function requireRole(...$roles) {
    requireLogin();
    if (!in_array($_SESSION['role'], $roles)) {
        header('Location: ' . BASE_URL . '/pages/forbidden.php');
        exit;
    }
}

function currentUser() {
    if (!isLoggedIn()) return null;
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}

function login($username, $password) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE (username=? OR email=?) AND status='active'");
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['email'] = $user['email'];
        // Update last login
        $db->prepare("UPDATE users SET last_login=NOW() WHERE id=?")->execute([$user['id']]);
        logActivity($user['id'], 'login', 'Đăng nhập hệ thống');
        return $user;
    }
    return false;
}

function logout() {
    if (isLoggedIn()) {
        logActivity($_SESSION['user_id'], 'logout', 'Đăng xuất hệ thống');
    }
    session_destroy();
    header('Location: ' . BASE_URL . '/pages/login.php');
    exit;
}

// ---- QR CODE HELPERS ----
function generateQRCode($data, $filename) {
    // Using QR Server API (no library needed)
    $encoded = urlencode($data);
    $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data={$encoded}";
    $savePath = __DIR__ . '/../uploads/qr/' . $filename . '.png';
    
    // Download QR image
    $ctx = stream_context_create(['http' => ['timeout' => 10]]);
    $img = @file_get_contents($qrUrl, false, $ctx);
    if ($img !== false) {
        file_put_contents($savePath, $img);
        return BASE_URL . '/uploads/qr/' . $filename . '.png';
    }
    // Fallback: return direct URL
    return $qrUrl;
}

function generateQRData($invoice) {
    // VietQR standard format for bank transfer
    $bankCode = "970436"; // Vietcombank
    $accountNo = "1234567890";
    $accountName = "KY TUC XA TRUONG";
    $amount = $invoice['total_amount'] - $invoice['paid_amount'];
    $description = "KTX " . $invoice['invoice_code'];
    
    // QR data in standard format
    return json_encode([
        'type' => 'ktx_payment',
        'invoice_code' => $invoice['invoice_code'],
        'amount' => $amount,
        'bank_code' => $bankCode,
        'account_no' => $accountNo,
        'account_name' => $accountName,
        'description' => $description,
        'due_date' => $invoice['due_date'],
        'timestamp' => time()
    ]);
}

function generateVietQR($invoiceCode, $amount) {
    $bankCode = "970436"; // Vietcombank
    $accountNo = "1234567890";
    $description = urlencode("KTX " . $invoiceCode);
    return "https://img.vietqr.io/image/{$bankCode}-{$accountNo}-compact2.png?amount={$amount}&addInfo={$description}&accountName=KY+TUC+XA";
}

// ---- INVOICE HELPERS ----
function generateInvoiceCode() {
    return 'INV' . date('Ymd') . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
}

function generatePaymentCode() {
    return 'PAY' . date('YmdHis') . rand(10, 99);
}

function createInvoice($studentId, $roomId, $billingMonth, $items, $dueDate, $notes = '') {
    $db = getDB();
    
    $totalAmount = array_sum(array_column($items, 'amount'));
    $invoiceCode = generateInvoiceCode();
    
    // Ensure unique code
    while (true) {
        $check = $db->prepare("SELECT id FROM invoices WHERE invoice_code=?");
        $check->execute([$invoiceCode]);
        if (!$check->fetch()) break;
        $invoiceCode = generateInvoiceCode();
    }
    
    $qrData = json_encode([
        'invoice_code' => $invoiceCode,
        'amount' => $totalAmount,
        'student_id' => $studentId,
        'billing_month' => $billingMonth,
    ]);
    
    $stmt = $db->prepare("INSERT INTO invoices 
        (invoice_code, student_id, room_id, billing_month, total_amount, due_date, qr_data, notes, status, created_by)
        VALUES (?,?,?,?,?,?,?,?,'pending',?)");
    $stmt->execute([$invoiceCode, $studentId, $roomId, $billingMonth, $totalAmount, $dueDate, $qrData, $notes, $_SESSION['user_id'] ?? null]);
    
    $invoiceId = $db->lastInsertId();
    
    // Insert items
    foreach ($items as $item) {
        $db->prepare("INSERT INTO invoice_items (invoice_id, service_id, description, quantity, unit_price, amount) VALUES (?,?,?,?,?,?)")
           ->execute([$invoiceId, $item['service_id'] ?? null, $item['description'], $item['quantity'] ?? 1, $item['unit_price'], $item['amount']]);
    }
    
    return $invoiceId;
}

// ---- NOTIFICATION HELPERS ----
function sendNotification($userId, $title, $message, $type = 'info', $relatedId = null, $relatedType = null) {
    $db = getDB();
    $db->prepare("INSERT INTO notifications (user_id, title, message, type, related_id, related_type) VALUES (?,?,?,?,?,?)")
       ->execute([$userId, $title, $message, $type, $relatedId, $relatedType]);
}

function getUnreadCount($userId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
    $stmt->execute([$userId]);
    return $stmt->fetchColumn();
}

function getNotifications($userId, $limit = 10) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT ?");
    $stmt->execute([$userId, $limit]);
    return $stmt->fetchAll();
}

// ---- LOGGING ----
function logActivity($userId, $action, $description = '') {
    $db = getDB();
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $db->prepare("INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent) VALUES (?,?,?,?,?)")
       ->execute([$userId, $action, $description, $ip, $ua]);
}

// ---- FORMATTING ----
function formatMoney($amount) {
    return number_format($amount, 0, ',', '.') . ' đ';
}

function formatDate($date) {
    if (!$date) return '—';
    return date('d/m/Y', strtotime($date));
}

function formatDateTime($dt) {
    if (!$dt) return '—';
    return date('d/m/Y H:i', strtotime($dt));
}

function statusBadge($status) {
    $map = [
        'pending'  => ['class' => 'warning', 'text' => 'Chờ thanh toán'],
        'partial'  => ['class' => 'info',    'text' => 'Thanh toán 1 phần'],
        'paid'     => ['class' => 'success',  'text' => 'Đã thanh toán'],
        'overdue'  => ['class' => 'danger',   'text' => 'Quá hạn'],
        'cancelled'=> ['class' => 'secondary','text' => 'Đã hủy'],
        'active'   => ['class' => 'success',  'text' => 'Hoạt động'],
        'inactive' => ['class' => 'secondary','text' => 'Không hoạt động'],
        'available'=> ['class' => 'success',  'text' => 'Còn chỗ'],
        'full'     => ['class' => 'danger',   'text' => 'Đầy'],
        'maintenance'=>['class'=>'warning',   'text' => 'Bảo trì'],
        'standard' => ['class' => 'info',     'text' => 'Tiêu chuẩn'],
        'premium'  => ['class' => 'primary',  'text' => 'Cao cấp'],
        'vip'      => ['class' => 'warning',  'text' => 'VIP'],
        'admin'    => ['class' => 'danger',   'text' => 'Admin'],
        'staff'    => ['class' => 'primary',  'text' => 'Nhân viên'],
        'student'  => ['class' => 'success',  'text' => 'Sinh viên'],
        'approved' => ['class' => 'success',  'text' => 'Đã duyệt'],
        'rejected' => ['class' => 'danger',   'text' => 'Từ chối'],
        'register' => ['class' => 'primary',  'text' => 'Đăng ký phòng'],
        'cancel'   => ['class' => 'danger',   'text' => 'Huỷ phòng'],
        'change'   => ['class' => 'warning',  'text' => 'Đổi phòng'],
    ];
    $info = $map[$status] ?? ['class' => 'secondary', 'text' => ucfirst($status)];
    return "<span class='badge bg-{$info['class']}'>{$info['text']}</span>";
}

// ---- FLASH MESSAGES ----
function setFlash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function showFlash() {
    $flash = getFlash();
    if ($flash) {
        echo "<div class='alert alert-{$flash['type']} alert-dismissible fade show' role='alert'>
            {$flash['message']}
            <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
        </div>";
    }
}

// ---- DASHBOARD STATS ----
function getDashboardStats() {
    $db = getDB();
    $stats = [];
    
    $stats['total_students'] = $db->query("SELECT COUNT(*) FROM users WHERE role='student' AND status='active'")->fetchColumn();
    $stats['total_rooms'] = $db->query("SELECT COUNT(*) FROM rooms")->fetchColumn();
    $stats['occupied_rooms'] = $db->query("SELECT COUNT(*) FROM rooms WHERE status='full'")->fetchColumn();
    $stats['available_rooms'] = $db->query("SELECT COUNT(*) FROM rooms WHERE status='available'")->fetchColumn();
    
    $stats['pending_invoices'] = $db->query("SELECT COUNT(*) FROM invoices WHERE status IN ('pending','overdue')")->fetchColumn();
    $stats['total_revenue_month'] = $db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_status='completed' AND MONTH(paid_at)=MONTH(NOW()) AND YEAR(paid_at)=YEAR(NOW())")->fetchColumn();
    $stats['total_revenue_year'] = $db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_status='completed' AND YEAR(paid_at)=YEAR(NOW())")->fetchColumn();
    $stats['overdue_invoices'] = $db->query("SELECT COUNT(*) FROM invoices WHERE status='overdue'")->fetchColumn();
    
    // Monthly revenue for chart (last 6 months)
    $stmt = $db->query("SELECT DATE_FORMAT(paid_at, '%Y-%m') as month, SUM(amount) as total 
        FROM payments WHERE payment_status='completed' AND paid_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY month ORDER BY month");
    $stats['monthly_revenue'] = $stmt->fetchAll();
    
    // Recent payments
    $stmt = $db->query("SELECT p.*, i.invoice_code, u.full_name 
        FROM payments p 
        JOIN invoices i ON p.invoice_id=i.id 
        JOIN users u ON i.student_id=u.id 
        WHERE p.payment_status='completed' 
        ORDER BY p.paid_at DESC LIMIT 5");
    $stats['recent_payments'] = $stmt->fetchAll();
    
    return $stats;
}

// ---- BACKUP ----
function performBackup($type = 'manual') {
    $filename = 'backup_ktx_' . date('Y-m-d_His') . '.sql';
    $savePath = __DIR__ . '/../backup/' . $filename;
    
    $db = getDB();
    $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    
    $sql = "-- KTX Database Backup\n-- Date: " . date('Y-m-d H:i:s') . "\n-- Generated by KTX System\n\n";
    $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";
    
    foreach ($tables as $table) {
        $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
        $create = $db->query("SHOW CREATE TABLE `{$table}`")->fetch();
        $sql .= $create['Create Table'] . ";\n\n";
        
        $rows = $db->query("SELECT * FROM `{$table}`")->fetchAll();
        if ($rows) {
            $cols = array_keys($rows[0]);
            $colList = '`' . implode('`, `', $cols) . '`';
            foreach ($rows as $row) {
                $vals = array_map(fn($v) => $v === null ? 'NULL' : $db->quote((string)$v), array_values($row));
                $sql .= "INSERT INTO `{$table}` ({$colList}) VALUES (" . implode(', ', $vals) . ");\n";
            }
            $sql .= "\n";
        }
    }
    
    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
    
    if (file_put_contents($savePath, $sql)) {
        $fileSize = filesize($savePath);
        $db->prepare("INSERT INTO backup_logs (filename, file_size, backup_type, status, created_by) VALUES (?,?,?,'success',?)")
           ->execute([$filename, $fileSize, $type, $_SESSION['user_id'] ?? null]);
        return ['success' => true, 'filename' => $filename, 'size' => $fileSize];
    }
    
    return ['success' => false];
}