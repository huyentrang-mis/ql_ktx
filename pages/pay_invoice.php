<?php
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$db = getDB();
$user = currentUser();

$invoiceId = (int)($_GET['id'] ?? 0);
if (!$invoiceId) { header('Location: dashboard.php'); exit; }

// Get invoice
$stmt = $db->prepare("SELECT i.*, u.full_name, u.email, u.phone, sp.student_code, r.room_number, r.price_per_month, b.name as building_name
    FROM invoices i JOIN users u ON i.student_id=u.id LEFT JOIN student_profiles sp ON u.id=sp.user_id
    JOIN rooms r ON i.room_id=r.id JOIN buildings b ON r.building_id=b.id WHERE i.id=?");
$stmt->execute([$invoiceId]);
$invoice = $stmt->fetch();

if (!$invoice) { header('Location: dashboard.php'); exit; }
// Students can only view their own
if ($user['role'] === 'student' && $invoice['student_id'] != $user['id']) {
    header('Location: dashboard.php'); exit;
}

// Get items
$items = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id=?");
$items->execute([$invoiceId]);
$items = $items->fetchAll();

// Get payments
$payments = $db->prepare("SELECT * FROM payments WHERE invoice_id=? ORDER BY created_at DESC");
$payments->execute([$invoiceId]);
$payments = $payments->fetchAll();

$remainingAmount = $invoice['total_amount'] - $invoice['paid_amount'];

// Generate/get QR
$qrImageUrl = '';
if ($remainingAmount > 0) {
    $qrImageUrl = generateVietQR($invoice['invoice_code'], $remainingAmount);
}

// Handle manual payment confirmation (staff/admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($user['role'], ['admin','staff'])) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'confirm_payment') {
        $amount = (float)$_POST['amount'];
        $method = $_POST['payment_method'] ?? 'cash';
        $transId = trim($_POST['transaction_id'] ?? '');
        $payNotes = trim($_POST['pay_notes'] ?? '');
        
        if ($amount > 0) {
            $payCode = generatePaymentCode();
            $db->prepare("INSERT INTO payments (invoice_id, payment_code, amount, payment_method, payment_status, transaction_id, payer_name, notes, paid_at)
                VALUES (?,?,?,?,'completed',?,?,?,NOW())")
               ->execute([$invoiceId, $payCode, $amount, $method, $transId, $invoice['full_name'], $payNotes]);
            
            $newPaid = $invoice['paid_amount'] + $amount;
            $newStatus = $newPaid >= $invoice['total_amount'] ? 'paid' : 'partial';
            $db->prepare("UPDATE invoices SET paid_amount=?, status=? WHERE id=?")->execute([$newPaid, $newStatus, $invoiceId]);
            
            // Notify student
            sendNotification($invoice['student_id'], 'Thanh toán thành công', 
                'Hóa đơn ' . $invoice['invoice_code'] . ' đã được xác nhận ' . formatMoney($amount), 
                'success', $invoiceId, 'invoice');
            
            logActivity($user['id'], 'confirm_payment', 'Xác nhận thanh toán ' . formatMoney($amount) . ' cho HĐ ' . $invoice['invoice_code']);
            setFlash('success', 'Xác nhận thanh toán thành công!');
            header('Location: pay_invoice.php?id=' . $invoiceId);
            exit;
        }
    }
}
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="container-fluid px-0">
<?php showFlash(); ?>

<div class="page-header">
    <div class="page-title"><i class="bi bi-qr-code-scan"></i> Thanh Toán Hóa Đơn</div>
    <a href="<?= $user['role']==='student'?'my_invoices':'invoices' ?>.php" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Quay Lại
    </a>
</div>

<div class="row g-4">
    <!-- LEFT: Invoice Details -->
    <div class="col-lg-7">
        <div class="card mb-4">
            <div class="card-header">
                <i class="bi bi-file-text-fill"></i> Chi Tiết Hóa Đơn
                <span class="ms-auto"><?= statusBadge($invoice['status']) ?></span>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-6">
                        <div class="text-muted" style="font-size:12px;">MÃ HÓA ĐƠN</div>
                        <div class="text-mono fw-bold"><?= $invoice['invoice_code'] ?></div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted" style="font-size:12px;">KỲ THANH TOÁN</div>
                        <div class="fw-bold"><?= date('m/Y', strtotime($invoice['billing_month'])) ?></div>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-6">
                        <div class="text-muted" style="font-size:12px;">SINH VIÊN</div>
                        <div class="fw-bold"><?= htmlspecialchars($invoice['full_name']) ?></div>
                        <div class="text-muted" style="font-size:12px;"><?= $invoice['student_code'] ?> | <?= $invoice['email'] ?></div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted" style="font-size:12px;">PHÒNG</div>
                        <div class="fw-bold"><?= $invoice['building_name'] ?> - <?= $invoice['room_number'] ?></div>
                    </div>
                </div>

                <!-- Items -->
                <table class="table table-sm mb-3">
                    <thead><tr>
                        <th>Khoản Thu</th>
                        <th class="text-end">SL</th>
                        <th class="text-end">Đơn Giá</th>
                        <th class="text-end">Thành Tiền</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['description']) ?></td>
                        <td class="text-end"><?= number_format($item['quantity'], 2) ?></td>
                        <td class="text-end"><?= formatMoney($item['unit_price']) ?></td>
                        <td class="text-end"><strong><?= formatMoney($item['amount']) ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="border-top:2px solid var(--border);">
                            <td colspan="3" class="text-end fw-bold">TỔNG CỘNG</td>
                            <td class="text-end fw-bold" style="font-size:16px;"><?= formatMoney($invoice['total_amount']) ?></td>
                        </tr>
                        <?php if ($invoice['paid_amount'] > 0): ?>
                        <tr>
                            <td colspan="3" class="text-end text-success">Đã thanh toán</td>
                            <td class="text-end text-success">- <?= formatMoney($invoice['paid_amount']) ?></td>
                        </tr>
                        <tr>
                            <td colspan="3" class="text-end fw-bold text-danger">CÒN LẠI</td>
                            <td class="text-end fw-bold text-danger" style="font-size:16px;"><?= formatMoney($remainingAmount) ?></td>
                        </tr>
                        <?php endif; ?>
                    </tfoot>
                </table>

                <div class="d-flex justify-content-between text-muted" style="font-size:13px;">
                    <span><i class="bi bi-calendar-event me-1"></i>Hạn: <strong><?= formatDate($invoice['due_date']) ?></strong></span>
                    <span><i class="bi bi-clock me-1"></i>Tạo: <?= formatDateTime($invoice['created_at']) ?></span>
                </div>
            </div>
        </div>

        <!-- Payment History -->
        <?php if ($payments): ?>
        <div class="card">
            <div class="card-header"><i class="bi bi-clock-history"></i> Lịch Sử Thanh Toán</div>
            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead><tr><th>Mã GD</th><th>Phương Thức</th><th>Số Tiền</th><th>Thời Gian</th></tr></thead>
                    <tbody>
                    <?php foreach ($payments as $pay): ?>
                    <tr>
                        <td class="text-mono" style="font-size:12px;"><?= $pay['payment_code'] ?></td>
                        <td><?= [
                            'qr_code'=>'<i class="bi bi-qr-code me-1"></i>Mã QR',
                            'cash'=>'<i class="bi bi-cash me-1"></i>Tiền mặt',
                            'bank_transfer'=>'<i class="bi bi-bank me-1"></i>CK ngân hàng',
                            'momo'=>'<span style="color:#ae2070">MoMo</span>',
                            'zalopay'=>'<span style="color:#0066ff">ZaloPay</span>',
                        ][$pay['payment_method']] ?? $pay['payment_method'] ?></td>
                        <td><strong class="text-success"><?= formatMoney($pay['amount']) ?></strong></td>
                        <td style="font-size:12px;"><?= formatDateTime($pay['paid_at']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- RIGHT: QR Code -->
    <div class="col-lg-5">
        <?php if (in_array($invoice['status'], ['pending','partial','overdue']) && $remainingAmount > 0): ?>
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-qr-code-scan"></i> Quét Mã QR Thanh Toán</div>
            <div class="card-body">
                <div class="qr-container" id="qrPaymentStatus" data-invoice-id="<?= $invoice['id'] ?>">
                    <div class="mb-2">
                        <img src="<?= $qrImageUrl ?>" alt="QR Code" class="img-fluid" style="max-width:220px;" onerror="this.src='https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=<?= urlencode($invoice['invoice_code']) ?>'">
                    </div>
                    <div class="qr-amount"><?= formatMoney($remainingAmount) ?></div>
                    <div class="qr-invoice-code">Mã: <?= $invoice['invoice_code'] ?></div>
                    
                    <div class="qr-steps mt-3">
                        <div class="qr-step">
                            <div class="qr-step-num">1</div>
                            <span>Mở app ngân hàng</span>
                        </div>
                        <div class="qr-step">
                            <div class="qr-step-num">2</div>
                            <span>Quét mã QR</span>
                        </div>
                        <div class="qr-step">
                            <div class="qr-step-num">3</div>
                            <span>Xác nhận TT</span>
                        </div>
                    </div>
                    
                    <div class="alert alert-info mt-3 mb-0 text-start" id="paymentStatusCard" style="font-size:13px;">
                        <i class="bi bi-info-circle me-2"></i>
                        <span id="paymentStatusText">Đang chờ thanh toán...</span>
                        <div class="progress mt-2" style="height:3px;">
                            <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary" style="width:100%"></div>
                        </div>
                    </div>
                </div>
                
                <div class="mt-3 d-flex gap-2">
                    <button class="btn btn-outline-secondary btn-sm flex-fill" onclick="printQR('<?= $invoice['invoice_code'] ?>')">
                        <i class="bi bi-printer me-1"></i>In QR
                    </button>
                    <button class="btn btn-outline-primary btn-sm flex-fill" 
                        onclick="copyToClipboard('<?= $invoice['invoice_code'] ?>', this)">
                        <i class="bi bi-clipboard me-1"></i>Sao chép mã
                    </button>
                </div>
                
                <div class="mt-3 p-3 rounded" style="background:#f8f9fd;font-size:12px;">
                    <strong>Thông tin chuyển khoản:</strong><br>
                    Ngân hàng: <strong>Vietcombank</strong><br>
                    STK: <strong>1234567890</strong><br>
                    Tên: <strong>KY TUC XA TRUONG</strong><br>
                    ND: <strong>KTX <?= $invoice['invoice_code'] ?></strong>
                </div>
            </div>
        </div>

        <!-- Staff: Manual Confirm -->
        <?php if (in_array($user['role'], ['admin','staff'])): ?>
        <div class="card">
            <div class="card-header"><i class="bi bi-check-circle-fill"></i> Xác Nhận Thanh Toán Thủ Công</div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="confirm_payment">
                    <div class="mb-3">
                        <label class="form-label">Số tiền nhận được</label>
                        <input type="number" name="amount" class="form-control" value="<?= $remainingAmount ?>" min="1" step="1000" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Phương thức</label>
                        <select name="payment_method" class="form-select">
                            <option value="cash">Tiền mặt</option>
                            <option value="bank_transfer">Chuyển khoản ngân hàng</option>
                            <option value="qr_code">Mã QR</option>
                            <option value="momo">MoMo</option>
                            <option value="zalopay">ZaloPay</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Mã giao dịch (nếu có)</label>
                        <input type="text" name="transaction_id" class="form-control" placeholder="VD: FT2312345678">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Ghi chú</label>
                        <textarea name="pay_notes" class="form-control" rows="2" placeholder="Ghi chú..."></textarea>
                    </div>
                    <button type="submit" class="btn btn-success w-100">
                        <i class="bi bi-check-circle-fill me-2"></i>Xác Nhận Thanh Toán
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="card">
            <div class="card-body text-center py-5">
                <div style="font-size:64px;color:var(--success);margin-bottom:16px;">
                    <i class="bi bi-check-circle-fill"></i>
                </div>
                <h5 class="fw-bold text-success">Đã Thanh Toán Đầy Đủ</h5>
                <p class="text-muted">Hóa đơn này đã được thanh toán hoàn toàn.</p>
                <div class="text-mono fw-bold" style="font-size:24px;color:var(--primary);"><?= formatMoney($invoice['total_amount']) ?></div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>