<?php
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin', 'staff');
$db = getDB();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $studentId = (int)$_POST['student_id'];
        $roomId = (int)$_POST['room_id'];
        $billingMonth = $_POST['billing_month'];
        $dueDate = $_POST['due_date'];
        $notes = $_POST['notes'] ?? '';
        
        // Get room price
        $room = $db->prepare("SELECT * FROM rooms WHERE id=?");
        $room->execute([$roomId]);
        $room = $room->fetch();
        
        $items = [];
        $items[] = ['description'=>'Tiền phòng ' . date('m/Y', strtotime($billingMonth)), 'quantity'=>1, 'unit_price'=>$room['price_per_month'], 'amount'=>$room['price_per_month'], 'service_id'=>1];
        
        // Optional services
        if (!empty($_POST['electricity_used'])) {
            $elecUsed = (float)$_POST['electricity_used'];
            $elecPrice = (float)($_POST['electricity_price'] ?? 3500);
            $elecAmt = $elecUsed * $elecPrice;
            $items[] = ['description'=>'Tiền điện ('.$elecUsed.' kWh)', 'quantity'=>$elecUsed, 'unit_price'=>$elecPrice, 'amount'=>$elecAmt, 'service_id'=>4];
        }
        if (!empty($_POST['water_used'])) {
            $waterUsed = (float)$_POST['water_used'];
            $waterPrice = (float)($_POST['water_price'] ?? 15000);
            $waterAmt = $waterUsed * $waterPrice;
            $items[] = ['description'=>'Tiền nước ('.$waterUsed.' m³)', 'quantity'=>$waterUsed, 'unit_price'=>$waterPrice, 'amount'=>$waterAmt, 'service_id'=>5];
        }
        if (!empty($_POST['internet_fee'])) {
            $items[] = ['description'=>'Phí Internet', 'quantity'=>1, 'unit_price'=>50000, 'amount'=>50000, 'service_id'=>2];
        }
        if (!empty($_POST['cleaning_fee'])) {
            $items[] = ['description'=>'Phí vệ sinh', 'quantity'=>1, 'unit_price'=>30000, 'amount'=>30000, 'service_id'=>3];
        }
        // Custom items
        foreach ($_POST['custom_desc'] ?? [] as $i => $desc) {
            if (!empty($desc) && isset($_POST['custom_amount'][$i])) {
                $amt = (float)$_POST['custom_amount'][$i];
                if ($amt > 0) $items[] = ['description'=>$desc, 'quantity'=>1, 'unit_price'=>$amt, 'amount'=>$amt];
            }
        }
        
        $invoiceId = createInvoice($studentId, $roomId, $billingMonth, $items, $dueDate, $notes);
        
        // Notify student
        sendNotification($studentId, 'Hóa đơn mới', 'Bạn có hóa đơn mới cần thanh toán trước ' . formatDate($dueDate), 'warning', $invoiceId, 'invoice');
        
        logActivity($_SESSION['user_id'], 'create_invoice', 'Tạo hóa đơn ID: ' . $invoiceId);
        setFlash('success', 'Tạo hóa đơn thành công!');
        header('Location: invoices.php');
        exit;
    }
    
    if ($action === 'cancel') {
        $id = (int)$_POST['invoice_id'];
        $db->prepare("UPDATE invoices SET status='cancelled' WHERE id=?")->execute([$id]);
        logActivity($_SESSION['user_id'], 'cancel_invoice', 'Hủy hóa đơn ID: ' . $id);
        setFlash('warning', 'Đã hủy hóa đơn.');
        header('Location: invoices.php');
        exit;
    }
    
    if ($action === 'mark_overdue') {
        $db->query("UPDATE invoices SET status='overdue' WHERE status='pending' AND due_date < CURDATE()");
        setFlash('info', 'Đã cập nhật hóa đơn quá hạn.');
        header('Location: invoices.php');
        exit;
    }
}

// Filters
$status = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;

$where = "WHERE 1=1";
$params = [];
if ($status) { $where .= " AND i.status=?"; $params[] = $status; }
if ($search) { $where .= " AND (i.invoice_code LIKE ? OR u.full_name LIKE ? OR sp.student_code LIKE ?)"; $params = array_merge($params, ["%$search%","%$search%","%$search%"]); }

$total = $db->prepare("SELECT COUNT(*) FROM invoices i JOIN users u ON i.student_id=u.id LEFT JOIN student_profiles sp ON u.id=sp.user_id $where");
$total->execute($params);
$totalCount = $total->fetchColumn();
$totalPages = ceil($totalCount / $perPage);

$offset = ($page - 1) * $perPage;
$stmt = $db->prepare("SELECT i.*, u.full_name, sp.student_code, r.room_number, b.name as building_name
    FROM invoices i JOIN users u ON i.student_id=u.id LEFT JOIN student_profiles sp ON u.id=sp.user_id
    JOIN rooms r ON i.room_id=r.id JOIN buildings b ON r.building_id=b.id
    $where ORDER BY i.created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$invoices = $stmt->fetchAll();

// For create form
$students = $db->query("SELECT u.id, u.full_name, sp.student_code, sp.room_id FROM users u LEFT JOIN student_profiles sp ON u.id=sp.user_id WHERE u.role='student' AND u.status='active'")->fetchAll();
$rooms = $db->query("SELECT r.*, b.name as building_name FROM rooms r JOIN buildings b ON r.building_id=b.id ORDER BY b.name, r.room_number")->fetchAll();
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="container-fluid px-0">
<?php showFlash(); ?>
<div class="page-header">
    <div class="page-title"><i class="bi bi-receipt-cutoff"></i> Quản Lý Hóa Đơn</div>
    <div class="d-flex gap-2">
        <form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="mark_overdue">
            <button type="submit" class="btn btn-warning btn-sm">
                <i class="bi bi-exclamation-circle me-1"></i>Cập Nhật Quá Hạn
            </button>
        </form>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createModal">
            <i class="bi bi-plus-lg me-1"></i>Tạo Hóa Đơn
        </button>
    </div>
</div>

<!-- FILTERS -->
<div class="card mb-4">
    <div class="card-body py-3">
        <form class="row g-2 align-items-end" method="GET">
            <div class="col-md-4">
                <input type="text" name="search" class="form-control" placeholder="Tìm mã HĐ, tên, mã SV..."
                    value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="">Tất cả trạng thái</option>
                    <option value="pending" <?= $status==='pending'?'selected':'' ?>>Chờ thanh toán</option>
                    <option value="partial" <?= $status==='partial'?'selected':'' ?>>TT một phần</option>
                    <option value="paid" <?= $status==='paid'?'selected':'' ?>>Đã thanh toán</option>
                    <option value="overdue" <?= $status==='overdue'?'selected':'' ?>>Quá hạn</option>
                    <option value="cancelled" <?= $status==='cancelled'?'selected':'' ?>>Đã hủy</option>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-search me-1"></i>Tìm
                </button>
                <a href="invoices.php" class="btn btn-outline-secondary ms-1">Xóa lọc</a>
            </div>
        </form>
    </div>
</div>

<!-- TABLE -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead><tr>
                    <th>Mã Hóa Đơn</th>
                    <th>Sinh Viên</th>
                    <th>Phòng</th>
                    <th>Kỳ</th>
                    <th>Tổng Tiền</th>
                    <th>Đã TT</th>
                    <th>Hạn TT</th>
                    <th>Trạng Thái</th>
                    <th>Thao Tác</th>
                </tr></thead>
                <tbody>
                <?php if ($invoices): foreach ($invoices as $inv): ?>
                <tr>
                    <td>
                        <span class="text-mono" style="font-size:12px;"><?= $inv['invoice_code'] ?></span>
                    </td>
                    <td>
                        <div><?= htmlspecialchars($inv['full_name']) ?></div>
                        <small class="text-muted text-mono"><?= $inv['student_code'] ?></small>
                    </td>
                    <td><?= $inv['building_name'] ?> - <?= $inv['room_number'] ?></td>
                    <td><?= date('m/Y', strtotime($inv['billing_month'])) ?></td>
                    <td><strong><?= formatMoney($inv['total_amount']) ?></strong></td>
                    <td><?= formatMoney($inv['paid_amount']) ?></td>
                    <td>
                        <span class="<?= $inv['due_date'] < date('Y-m-d') && $inv['status'] !== 'paid' ? 'text-danger fw-bold' : '' ?>">
                            <?= formatDate($inv['due_date']) ?>
                        </span>
                    </td>
                    <td><?= statusBadge($inv['status']) ?></td>
                    <td>
                        <div class="d-flex gap-1">
                            <a href="invoice_detail.php?id=<?= $inv['id'] ?>" class="btn btn-sm btn-outline-primary" title="Chi tiết">
                                <i class="bi bi-eye-fill"></i>
                            </a>
                            <?php if (in_array($inv['status'], ['pending','partial','overdue'])): ?>
                            <a href="pay_invoice.php?id=<?= $inv['id'] ?>" class="btn btn-sm btn-success" title="Thanh toán QR">
                                <i class="bi bi-qr-code"></i>
                            </a>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="cancel">
                                <input type="hidden" name="invoice_id" value="<?= $inv['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Hủy"
                                    data-confirm="Hủy hóa đơn này?">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="9"><div class="empty-state"><i class="bi bi-receipt"></i><p>Không có hóa đơn nào</p></div></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="card-body border-top d-flex justify-content-between align-items-center py-3">
        <small class="text-muted">Hiển thị <?= count($invoices) ?> / <?= $totalCount ?> hóa đơn</small>
        <nav>
            <ul class="pagination mb-0">
                <?php for ($i=1; $i<=$totalPages; $i++): ?>
                <li class="page-item <?= $i==$page?'active':'' ?>">
                    <a class="page-link" href="?page=<?= $i ?>&status=<?= urlencode($status) ?>&search=<?= urlencode($search) ?>"><?= $i ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>
</div>

<!-- CREATE MODAL -->
<div class="modal fade" id="createModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-receipt-cutoff me-2"></i>Tạo Hóa Đơn Mới</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="create">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Sinh Viên <span class="text-danger">*</span></label>
                            <select name="student_id" class="form-select" required id="studentSelect">
                                <option value="">— Chọn sinh viên —</option>
                                <?php foreach ($students as $s): ?>
                                <option value="<?= $s['id'] ?>" data-room="<?= $s['room_id'] ?>">
                                    <?= htmlspecialchars($s['full_name']) ?> (<?= $s['student_code'] ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phòng <span class="text-danger">*</span></label>
                            <select name="room_id" class="form-select" required id="roomSelect">
                                <option value="">— Chọn phòng —</option>
                                <?php foreach ($rooms as $r): ?>
                                <option value="<?= $r['id'] ?>" data-price="<?= $r['price_per_month'] ?>">
                                    <?= $r['building_name'] ?> - <?= $r['room_number'] ?> (<?= formatMoney($r['price_per_month']) ?>/tháng)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Kỳ thanh toán <span class="text-danger">*</span></label>
                            <input type="month" name="billing_month" class="form-control" value="<?= date('Y-m') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Hạn thanh toán <span class="text-danger">*</span></label>
                            <input type="date" name="due_date" class="form-control" value="<?= date('Y-m-d', strtotime('+15 days')) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Tiền phòng</label>
                            <input type="text" id="roomPrice" class="form-control" readonly placeholder="Tự động">
                        </div>
                        
                        <div class="col-12"><hr class="my-1"><strong>Dịch vụ & Tiện ích</strong></div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Điện dùng (kWh)</label>
                            <input type="number" name="electricity_used" class="form-control" step="0.01" min="0" placeholder="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Đơn giá điện (đ/kWh)</label>
                            <input type="number" name="electricity_price" class="form-control" value="3500">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Nước dùng (m³)</label>
                            <input type="number" name="water_used" class="form-control" step="0.01" min="0" placeholder="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Đơn giá nước (đ/m³)</label>
                            <input type="number" name="water_price" class="form-control" value="15000">
                        </div>
                        <div class="col-md-6">
                            <div class="form-check mt-2">
                                <input type="checkbox" name="internet_fee" class="form-check-input" id="internetCheck" value="1">
                                <label class="form-check-label" for="internetCheck">Phí Internet (50,000 đ)</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check mt-2">
                                <input type="checkbox" name="cleaning_fee" class="form-check-input" id="cleaningCheck" value="1">
                                <label class="form-check-label" for="cleaningCheck">Phí vệ sinh (30,000 đ)</label>
                            </div>
                        </div>
                        
                        <div class="col-12"><hr class="my-1"><strong>Phụ thu khác</strong> <small class="text-muted">(tùy chọn)</small></div>
                        <div class="col-md-7">
                            <input type="text" name="custom_desc[]" class="form-control" placeholder="Mô tả khoản thu">
                        </div>
                        <div class="col-md-5">
                            <input type="number" name="custom_amount[]" class="form-control" placeholder="Số tiền (đ)" min="0">
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label">Ghi chú</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Ghi chú thêm..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-plus-circle me-1"></i>Tạo Hóa Đơn
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Auto-fill room from student selection
document.getElementById('studentSelect').addEventListener('change', function() {
    const roomId = this.options[this.selectedIndex].dataset.room;
    if (roomId) {
        const roomSel = document.getElementById('roomSelect');
        for (let opt of roomSel.options) {
            if (opt.value === roomId) {
                roomSel.value = roomId;
                roomSel.dispatchEvent(new Event('change'));
                break;
            }
        }
    }
});
document.getElementById('roomSelect').addEventListener('change', function() {
    const price = this.options[this.selectedIndex].dataset.price;
    document.getElementById('roomPrice').value = price ? parseInt(price).toLocaleString('vi-VN') + ' đ' : '';
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>