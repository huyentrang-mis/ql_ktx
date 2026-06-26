<?php
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$user = currentUser();
if ($user['role'] !== 'student') {
    header('Location: ' . BASE_URL . '/pages/invoices.php'); exit;
}
$db = getDB();

$status = $_GET['status'] ?? '';
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;

$where = "WHERE i.student_id=?"; $params = [$user['id']];
if ($status) { $where .= " AND i.status=?"; $params[] = $status; }

$total = $db->prepare("SELECT COUNT(*) FROM invoices i $where");
$total->execute($params);
$totalCount = $total->fetchColumn();
$totalPages = ceil($totalCount / $perPage);
$offset = ($page - 1) * $perPage;

$stmt = $db->prepare("SELECT i.*, r.room_number, b.name as building_name FROM invoices i
    JOIN rooms r ON i.room_id=r.id JOIN buildings b ON r.building_id=b.id
    $where ORDER BY i.created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$invoices = $stmt->fetchAll();

$pendingTotal = $db->prepare("SELECT COALESCE(SUM(total_amount-paid_amount),0) FROM invoices WHERE student_id=? AND status IN ('pending','overdue')");
$pendingTotal->execute([$user['id']]);
$totalOwed = $pendingTotal->fetchColumn();
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="container-fluid px-0">
<?php showFlash(); ?>

<div class="page-header">
    <div class="page-title"><i class="bi bi-receipt-cutoff"></i> Hóa Đơn Của Tôi</div>
</div>

<?php if ($totalOwed > 0): ?>
<div class="alert alert-warning mb-4" style="border-left:4px solid #f59e0b;">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>
    Bạn còn <strong><?= formatMoney($totalOwed) ?></strong> cần thanh toán.
</div>
<?php endif; ?>

<div class="card mb-3"><div class="card-body py-3">
    <form method="GET" class="d-flex gap-2 flex-wrap">
        <select name="status" class="form-select" style="max-width:200px;">
            <option value="">Tất cả trạng thái</option>
            <option value="pending" <?= $status==='pending'?'selected':'' ?>>Chờ thanh toán</option>
            <option value="partial" <?= $status==='partial'?'selected':'' ?>>Thanh toán 1 phần</option>
            <option value="paid" <?= $status==='paid'?'selected':'' ?>>Đã thanh toán</option>
            <option value="overdue" <?= $status==='overdue'?'selected':'' ?>>Quá hạn</option>
        </select>
        <button class="btn btn-primary"><i class="bi bi-search me-1"></i>Lọc</button>
        <a href="my_invoices.php" class="btn btn-outline-secondary">Xóa lọc</a>
    </form>
</div></div>

<div class="card"><div class="card-body p-0">
<table class="table mb-0">
    <thead><tr>
        <th>Mã Hóa Đơn</th><th>Phòng</th><th>Kỳ</th><th>Tổng Tiền</th><th>Đã TT</th><th>Còn Lại</th><th>Hạn</th><th>Trạng Thái</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($invoices as $inv): ?>
    <tr class="<?= $inv['status']==='overdue'?'table-danger':'' ?>">
        <td class="text-mono" style="font-size:12px;"><?= $inv['invoice_code'] ?></td>
        <td><?= $inv['building_name'] ?> - <?= $inv['room_number'] ?></td>
        <td><?= date('m/Y', strtotime($inv['billing_month'])) ?></td>
        <td><?= formatMoney($inv['total_amount']) ?></td>
        <td style="color:var(--success);"><?= formatMoney($inv['paid_amount']) ?></td>
        <td><strong><?= formatMoney($inv['total_amount'] - $inv['paid_amount']) ?></strong></td>
        <td style="font-size:12px;"><?= formatDate($inv['due_date']) ?></td>
        <td><?= statusBadge($inv['status']) ?></td>
        <td>
            <a href="pay_invoice.php?id=<?= $inv['id'] ?>" class="btn btn-sm <?= in_array($inv['status'],['pending','partial','overdue'])?'btn-primary':'btn-outline-secondary' ?>">
                <?= in_array($inv['status'],['pending','partial','overdue']) ? '<i class="bi bi-qr-code me-1"></i>Thanh Toán' : '<i class="bi bi-eye me-1"></i>Xem' ?>
            </a>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$invoices): ?>
    <tr><td colspan="9" class="text-center py-4 text-muted">
        <i class="bi bi-receipt" style="font-size:2rem;display:block;margin-bottom:8px;"></i>
        Không có hóa đơn nào
    </td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div></div>

<?php if ($totalPages > 1): ?>
<nav class="mt-3"><ul class="pagination justify-content-center">
    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
    <li class="page-item <?= $p==$page?'active':'' ?>">
        <a class="page-link" href="?page=<?= $p ?>&status=<?= urlencode($status) ?>"><?= $p ?></a>
    </li>
    <?php endfor; ?>
</ul></nav>
<?php endif; ?>

</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
