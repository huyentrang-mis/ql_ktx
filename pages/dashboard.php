<?php
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$user = currentUser();
$stats = getDashboardStats();
$db = getDB();

// Monthly revenue for chart
$monthlyRevenue = $stats['monthly_revenue'];
$chartLabels = json_encode(array_map(function($r) {
    $d = DateTime::createFromFormat('Y-m', $r['month']);
    return $d ? $d->format('T/Y') : $r['month'];
}, $monthlyRevenue));
$chartData = json_encode(array_column($monthlyRevenue, 'total'));

// Payment methods
$pmStmt = $db->query("SELECT payment_method, COUNT(*) as cnt FROM payments WHERE payment_status='completed' GROUP BY payment_method");
$pmRows = $pmStmt->fetchAll();
$pmLabels = json_encode(array_map(fn($r) => [
    'qr_code'=>'Mã QR','cash'=>'Tiền mặt','bank_transfer'=>'Chuyển khoản','momo'=>'MoMo','zalopay'=>'ZaloPay'
][$r['payment_method']] ?? $r['payment_method'], $pmRows));
$pmData = json_encode(array_column($pmRows, 'cnt'));

// Occupancy
$occLabels = json_encode(['Còn chỗ','Đầy','Bảo trì']);
$occData = json_encode([
    $db->query("SELECT COUNT(*) FROM rooms WHERE status='available'")->fetchColumn(),
    $db->query("SELECT COUNT(*) FROM rooms WHERE status='full'")->fetchColumn(),
    $db->query("SELECT COUNT(*) FROM rooms WHERE status='maintenance'")->fetchColumn(),
]);

// Student-specific: get my invoices
$myInvoices = [];
if ($user['role'] === 'student') {
    $stmt = $db->prepare("SELECT i.*, r.room_number, b.name as building_name FROM invoices i 
        JOIN rooms r ON i.room_id=r.id JOIN buildings b ON r.building_id=b.id
        WHERE i.student_id=? ORDER BY i.created_at DESC LIMIT 5");
    $stmt->execute([$user['id']]);
    $myInvoices = $stmt->fetchAll();
}

// Recent activities (admin/staff)
$recentActivities = [];
if (in_array($user['role'], ['admin','staff'])) {
    $recentActivities = $db->query("SELECT al.*, u.full_name FROM activity_logs al 
        LEFT JOIN users u ON al.user_id=u.id ORDER BY al.created_at DESC LIMIT 8")->fetchAll();
}
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="container-fluid px-0">

<?php showFlash(); ?>

<div class="page-header">
    <div class="page-title">
        <i class="bi bi-grid-1x2-fill"></i>
        Dashboard
        <span style="font-size:14px;font-weight:400;color:var(--text-muted);">— Xin chào, <?= htmlspecialchars($user['full_name']) ?>!</span>
    </div>
    <span style="font-size:13px;color:var(--text-muted);">
        <i class="bi bi-calendar3 me-1"></i><?= date('l, d/m/Y') ?>
    </span>
</div>

<!-- STATS CARDS -->
<?php if ($user['role'] === 'student'): ?>
<?php
$db2 = getDB();
$sp = $db2->prepare("SELECT sp.*, r.room_number, r.price_per_month, b.name as building_name FROM student_profiles sp 
    LEFT JOIN rooms r ON sp.room_id=r.id LEFT JOIN buildings b ON r.building_id=b.id WHERE sp.user_id=?");
$sp->execute([$user['id']]);
$profile = $sp->fetch();
$pendingCount = $db2->prepare("SELECT COUNT(*) FROM invoices WHERE student_id=? AND status IN ('pending','overdue')");
$pendingCount->execute([$user['id']]);
$myPending = $pendingCount->fetchColumn();
$totalPaid = $db2->prepare("SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN invoices i ON p.invoice_id=i.id WHERE i.student_id=? AND p.payment_status='completed'");
$totalPaid->execute([$user['id']]);
$myTotalPaid = $totalPaid->fetchColumn();
?>
<div class="row g-4 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon blue"><i class="bi bi-house-fill"></i></div>
            <div class="stat-info">
                <div class="stat-label">Phòng Của Tôi</div>
                <div class="stat-value"><?= $profile['room_number'] ?? '—' ?></div>
                <div class="stat-sub"><?= $profile['building_name'] ?? 'Chưa xếp phòng' ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon orange"><i class="bi bi-receipt-cutoff"></i></div>
            <div class="stat-info">
                <div class="stat-label">Hóa Đơn Chờ</div>
                <div class="stat-value"><?= $myPending ?></div>
                <div class="stat-sub">hóa đơn chưa thanh toán</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon green"><i class="bi bi-check-circle-fill"></i></div>
            <div class="stat-info">
                <div class="stat-label">Tổng Đã Thanh Toán</div>
                <div class="stat-value" style="font-size:18px;"><?= formatMoney($myTotalPaid) ?></div>
                <div class="stat-sub">tổng cộng</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon purple"><i class="bi bi-calendar-month-fill"></i></div>
            <div class="stat-info">
                <div class="stat-label">Tiền Phòng/Tháng</div>
                <div class="stat-value" style="font-size:18px;"><?= $profile ? formatMoney($profile['price_per_month']) : '—' ?></div>
                <div class="stat-sub">mỗi tháng</div>
            </div>
        </div>
    </div>
</div>

<?php else: ?>
<div class="row g-4 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon blue"><i class="bi bi-people-fill"></i></div>
            <div class="stat-info">
                <div class="stat-label">Sinh Viên</div>
                <div class="stat-value"><?= number_format($stats['total_students']) ?></div>
                <div class="stat-sub">đang ở ký túc xá</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon green"><i class="bi bi-door-closed-fill"></i></div>
            <div class="stat-info">
                <div class="stat-label">Phòng Còn Trống</div>
                <div class="stat-value"><?= $stats['available_rooms'] ?></div>
                <div class="stat-sub">/ <?= $stats['total_rooms'] ?> tổng số phòng</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon orange"><i class="bi bi-receipt-cutoff"></i></div>
            <div class="stat-info">
                <div class="stat-label">Hóa Đơn Chờ</div>
                <div class="stat-value"><?= $stats['pending_invoices'] ?></div>
                <div class="stat-sub"><?= $stats['overdue_invoices'] ?> quá hạn</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon purple"><i class="bi bi-cash-stack"></i></div>
            <div class="stat-info">
                <div class="stat-label">Doanh Thu Tháng</div>
                <div class="stat-value" style="font-size:18px;"><?= formatMoney($stats['total_revenue_month']) ?></div>
                <div class="stat-sub">tháng <?= date('m/Y') ?></div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- CHARTS ROW -->
<?php if ($user['role'] !== 'student'): ?>
<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header">
                <i class="bi bi-graph-up-arrow"></i> Doanh Thu 6 Tháng Gần Nhất
            </div>
            <div class="card-body">
                <canvas id="revenueChart" height="90"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-pie-chart-fill"></i> Tình Trạng Phòng</div>
            <div class="card-body d-flex flex-column align-items-center justify-content-center">
                <canvas id="occupancyChart" height="140"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- RECENT PAYMENTS + ACTIVITY -->
<div class="row g-4">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-credit-card-fill"></i> Thanh Toán Gần Đây
                <a href="payments.php" class="ms-auto btn btn-sm btn-outline-primary" style="font-size:12px;">Xem tất cả</a>
            </div>
            <div class="card-body p-0">
                <?php if ($stats['recent_payments']): ?>
                <table class="table mb-0">
                    <thead><tr>
                        <th>Mã HĐ</th><th>Sinh Viên</th><th>Số Tiền</th><th>Thời Gian</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($stats['recent_payments'] as $pay): ?>
                    <tr>
                        <td><span class="text-mono" style="font-size:12px;"><?= $pay['invoice_code'] ?></span></td>
                        <td><?= htmlspecialchars($pay['full_name']) ?></td>
                        <td><strong style="color:var(--success);"><?= formatMoney($pay['amount']) ?></strong></td>
                        <td style="font-size:12px;color:var(--text-muted);"><?= formatDateTime($pay['paid_at']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="empty-state"><i class="bi bi-credit-card"></i><p>Chưa có thanh toán nào</p></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header"><i class="bi bi-journal-text"></i> Hoạt Động Gần Đây</div>
            <div class="card-body p-0">
                <div style="max-height:280px;overflow-y:auto;">
                <?php foreach ($recentActivities as $act): ?>
                <div style="padding:12px 20px;border-bottom:1px solid var(--border);font-size:12.5px;">
                    <strong><?= htmlspecialchars($act['full_name'] ?? 'Hệ thống') ?></strong>
                    <span style="color:var(--text-muted);"> — <?= htmlspecialchars($act['action']) ?></span>
                    <div style="color:var(--text-muted);font-size:11px;margin-top:2px;"><?= formatDateTime($act['created_at']) ?></div>
                </div>
                <?php endforeach; ?>
                <?php if (!$recentActivities): ?>
                <div class="empty-state"><i class="bi bi-journal"></i><p>Chưa có hoạt động</p></div>
                <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php else: ?>
<!-- STUDENT DASHBOARD: Invoice List -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-receipt-cutoff"></i> Hóa Đơn Của Tôi
        <a href="my_invoices.php" class="ms-auto btn btn-sm btn-outline-primary" style="font-size:12px;">Xem tất cả</a>
    </div>
    <div class="card-body p-0">
        <?php if ($myInvoices): ?>
        <table class="table mb-0">
            <thead><tr>
                <th>Mã Hóa Đơn</th><th>Phòng</th><th>Kỳ</th><th>Số Tiền</th><th>Hạn</th><th>Trạng Thái</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($myInvoices as $inv): ?>
            <tr>
                <td class="text-mono" style="font-size:12px;"><?= $inv['invoice_code'] ?></td>
                <td><?= $inv['building_name'] ?> - <?= $inv['room_number'] ?></td>
                <td><?= date('m/Y', strtotime($inv['billing_month'])) ?></td>
                <td><strong><?= formatMoney($inv['total_amount']) ?></strong></td>
                <td style="font-size:12px;"><?= formatDate($inv['due_date']) ?></td>
                <td><?= statusBadge($inv['status']) ?></td>
                <td>
                    <?php if (in_array($inv['status'], ['pending','partial','overdue'])): ?>
                    <a href="pay_invoice.php?id=<?= $inv['id'] ?>" class="btn btn-sm btn-primary">
                        <i class="bi bi-qr-code me-1"></i>Thanh Toán
                    </a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="empty-state"><i class="bi bi-receipt"></i><p>Không có hóa đơn nào</p></div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

</div>

<script>
window.chartLabels = <?= $chartLabels ?>;
window.chartData = <?= $chartData ?>;
window.methodLabels = <?= $pmLabels ?>;
window.methodData = <?= $pmData ?>;
window.occupancyLabels = <?= $occLabels ?>;
window.occupancyData = <?= $occData ?>;
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>