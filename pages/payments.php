<?php
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin', 'staff');
$db = getDB();

$search  = trim($_GET['search'] ?? '');
$method  = $_GET['method'] ?? '';
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$where  = "WHERE p.payment_status='completed'";
$params = [];
if ($search) {
    $where .= " AND (p.payment_code LIKE ? OR i.invoice_code LIKE ? OR u.full_name LIKE ?)";
    $params = array_fill(0, 3, "%$search%");
}
if ($method) { $where .= " AND p.payment_method=?"; $params[] = $method; }

$total = $db->prepare("SELECT COUNT(*) FROM payments p JOIN invoices i ON p.invoice_id=i.id JOIN users u ON i.student_id=u.id $where");
$total->execute($params);
$totalCount = $total->fetchColumn();
$totalPages  = ceil($totalCount / $perPage);
$offset      = ($page - 1) * $perPage;

$stmt = $db->prepare("SELECT p.*, i.invoice_code, u.full_name, r.room_number, b.name as building_name
    FROM payments p JOIN invoices i ON p.invoice_id=i.id
    JOIN users u ON i.student_id=u.id JOIN rooms r ON i.room_id=r.id JOIN buildings b ON r.building_id=b.id
    $where ORDER BY p.paid_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$payments = $stmt->fetchAll();

$totalRevenue = $db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_status='completed'")->fetchColumn();
$monthRevenue = $db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_status='completed' AND MONTH(paid_at)=MONTH(NOW()) AND YEAR(paid_at)=YEAR(NOW())")->fetchColumn();
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="container-fluid px-0">
<?php showFlash(); ?>

<div class="page-header">
    <div class="page-title"><i class="bi bi-credit-card-fill"></i> Lịch Sử Thanh Toán</div>
</div>

<div class="row g-3 mb-4">
    <div class="col-sm-6">
        <div class="stat-card"><div class="stat-icon green"><i class="bi bi-cash-stack"></i></div>
            <div class="stat-info"><div class="stat-label">Doanh Thu Tháng Này</div>
            <div class="stat-value" style="font-size:18px;"><?= formatMoney($monthRevenue) ?></div></div></div>
    </div>
    <div class="col-sm-6">
        <div class="stat-card"><div class="stat-icon purple"><i class="bi bi-database-fill"></i></div>
            <div class="stat-info"><div class="stat-label">Tổng Doanh Thu</div>
            <div class="stat-value" style="font-size:18px;"><?= formatMoney($totalRevenue) ?></div></div></div>
    </div>
</div>

<div class="card mb-3"><div class="card-body py-3">
    <form method="GET" class="d-flex gap-2 flex-wrap">
        <input type="text" name="search" class="form-control" style="max-width:250px;"
            placeholder="Mã thanh toán, hóa đơn, tên SV..." value="<?= htmlspecialchars($search) ?>">
        <select name="method" class="form-select" style="max-width:180px;">
            <option value="">Tất cả phương thức</option>
            <option value="qr_code" <?= $method==='qr_code'?'selected':'' ?>>Mã QR</option>
            <option value="cash" <?= $method==='cash'?'selected':'' ?>>Tiền mặt</option>
            <option value="bank_transfer" <?= $method==='bank_transfer'?'selected':'' ?>>Chuyển khoản</option>
            <option value="momo" <?= $method==='momo'?'selected':'' ?>>MoMo</option>
        </select>
        <button class="btn btn-primary"><i class="bi bi-search me-1"></i>Tìm</button>
        <a href="payments.php" class="btn btn-outline-secondary">Reset</a>
    </form>
</div></div>

<div class="card"><div class="card-body p-0">
    <div class="table-responsive">
    <table class="table mb-0">
        <thead><tr>
            <th>Mã Thanh Toán</th><th>Hóa Đơn</th><th>Sinh Viên</th><th>Phòng</th>
            <th>Số Tiền</th><th>Phương Thức</th><th>Thời Gian</th>
        </tr></thead>
        <tbody>
        <?php foreach ($payments as $pay): ?>
        <tr>
            <td><span class="text-mono" style="font-size:12px;"><?= $pay['payment_code'] ?></span></td>
            <td><a href="pay_invoice.php?id=<?= $pay['invoice_id'] ?>" class="text-mono" style="font-size:12px;">
                <?= $pay['invoice_code'] ?></a></td>
            <td><?= htmlspecialchars($pay['full_name']) ?></td>
            <td><?= $pay['building_name'] ?> - <?= $pay['room_number'] ?></td>
            <td><strong style="color:var(--success);"><?= formatMoney($pay['amount']) ?></strong></td>
            <td>
                <?php $methodLabels = ['qr_code'=>'<i class="bi bi-qr-code"></i> QR Code','cash'=>'<i class="bi bi-cash"></i> Tiền mặt','bank_transfer'=>'<i class="bi bi-bank"></i> Chuyển khoản','momo'=>'<i class="bi bi-phone"></i> MoMo','zalopay'=>'ZaloPay']; ?>
                <?= $methodLabels[$pay['payment_method']] ?? $pay['payment_method'] ?>
            </td>
            <td style="font-size:12px;color:var(--text-muted);"><?= formatDateTime($pay['paid_at']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$payments): ?>
        <tr><td colspan="7" class="text-center py-4 text-muted">Không có dữ liệu</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div></div>

<?php if ($totalPages > 1): ?>
<nav class="mt-3"><ul class="pagination justify-content-center">
    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
    <li class="page-item <?= $p==$page?'active':'' ?>">
        <a class="page-link" href="?page=<?= $p ?>&search=<?= urlencode($search) ?>&method=<?= urlencode($method) ?>"><?= $p ?></a>
    </li>
    <?php endfor; ?>
</ul></nav>
<?php endif; ?>

</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
