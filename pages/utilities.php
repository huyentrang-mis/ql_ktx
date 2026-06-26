<?php
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin', 'staff');
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'record') {
        $roomId     = (int)$_POST['room_id'];
        $type       = $_POST['type'] ?? 'electricity';
        $period     = $_POST['period'] ?? date('Y-m');
        $prevReading = (float)($_POST['prev_reading'] ?? 0);
        $currReading = (float)($_POST['curr_reading'] ?? 0);
        $unitPrice   = (float)($_POST['unit_price'] ?? ($type === 'electricity' ? 3500 : 15000));
        $used        = max(0, $currReading - $prevReading);
        $amount      = $used * $unitPrice;

        $db->prepare("INSERT INTO utility_readings (room_id, type, period, prev_reading, curr_reading, used, unit_price, amount, recorded_by)
            VALUES (?,?,?,?,?,?,?,?,?)")
           ->execute([$roomId, $type, $period, $prevReading, $currReading, $used, $unitPrice, $amount, $_SESSION['user_id']]);

        logActivity($_SESSION['user_id'], 'record_utility', "Ghi $type phòng $roomId: $used đơn vị = " . formatMoney($amount));
        setFlash('success', "Đã ghi chỉ số " . ($type === 'electricity' ? 'điện' : 'nước') . ". Sử dụng: $used đơn vị = " . formatMoney($amount));
        header('Location: utilities.php'); exit;
    }
}

$rooms = $db->query("SELECT r.id, r.room_number, b.name as building_name FROM rooms r JOIN buildings b ON r.building_id=b.id ORDER BY b.name, r.room_number")->fetchAll();

$period = $_GET['period'] ?? date('Y-m');
$readings = $db->prepare("SELECT ur.*, r.room_number, b.name as building_name, u.full_name as recorded_by_name
    FROM utility_readings ur JOIN rooms r ON ur.room_id=r.id JOIN buildings b ON r.building_id=b.id
    LEFT JOIN users u ON ur.recorded_by=u.id WHERE ur.period=? ORDER BY b.name, r.room_number");
$readings->execute([$period]);
$readings = $readings->fetchAll();

$totalElec = array_sum(array_column(array_filter($readings, fn($r) => $r['type']==='electricity'), 'amount'));
$totalWater = array_sum(array_column(array_filter($readings, fn($r) => $r['type']==='water'), 'amount'));
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="container-fluid px-0">
<?php showFlash(); ?>

<div class="page-header">
    <div class="page-title"><i class="bi bi-lightning-charge-fill"></i> Quản Lý Điện - Nước</div>
    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#recordModal">
        <i class="bi bi-plus-lg me-1"></i>Ghi Chỉ Số
    </button>
</div>

<div class="row g-3 mb-4">
    <div class="col-sm-6">
        <div class="stat-card">
            <div class="stat-icon orange"><i class="bi bi-lightning-fill"></i></div>
            <div class="stat-info"><div class="stat-label">Tiền Điện Tháng <?= date('m/Y', strtotime($period . '-01')) ?></div>
            <div class="stat-value" style="font-size:18px;"><?= formatMoney($totalElec) ?></div></div>
        </div>
    </div>
    <div class="col-sm-6">
        <div class="stat-card">
            <div class="stat-icon blue"><i class="bi bi-droplet-fill"></i></div>
            <div class="stat-info"><div class="stat-label">Tiền Nước Tháng <?= date('m/Y', strtotime($period . '-01')) ?></div>
            <div class="stat-value" style="font-size:18px;"><?= formatMoney($totalWater) ?></div></div>
        </div>
    </div>
</div>

<div class="card mb-3"><div class="card-body py-3">
    <form method="GET" class="d-flex gap-2 align-items-center">
        <label class="form-label mb-0">Kỳ:</label>
        <input type="month" name="period" class="form-control" style="max-width:200px;" value="<?= $period ?>">
        <button class="btn btn-primary"><i class="bi bi-search me-1"></i>Xem</button>
    </form>
</div></div>

<div class="card"><div class="card-body p-0">
    <table class="table mb-0">
        <thead><tr>
            <th>Phòng</th><th>Loại</th><th>Chỉ Số Cũ</th><th>Chỉ Số Mới</th><th>Sử Dụng</th><th>Đơn Giá</th><th>Thành Tiền</th><th>Người Ghi</th>
        </tr></thead>
        <tbody>
        <?php foreach ($readings as $r): ?>
        <tr>
            <td><?= $r['building_name'] ?> - <?= $r['room_number'] ?></td>
            <td>
                <?php if ($r['type'] === 'electricity'): ?>
                <span class="badge bg-warning text-dark"><i class="bi bi-lightning-fill"></i> Điện</span>
                <?php else: ?>
                <span class="badge bg-info"><i class="bi bi-droplet-fill"></i> Nước</span>
                <?php endif; ?>
            </td>
            <td><?= number_format($r['prev_reading']) ?></td>
            <td><?= number_format($r['curr_reading']) ?></td>
            <td><strong><?= number_format($r['used']) ?> <?= $r['type']==='electricity'?'kWh':'m³' ?></strong></td>
            <td><?= formatMoney($r['unit_price']) ?>/<?= $r['type']==='electricity'?'kWh':'m³' ?></td>
            <td><strong style="color:var(--primary);"><?= formatMoney($r['amount']) ?></strong></td>
            <td style="font-size:12px;color:var(--text-muted);"><?= htmlspecialchars($r['recorded_by_name'] ?? 'Hệ thống') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$readings): ?>
        <tr><td colspan="8" class="text-center py-4 text-muted">Chưa có dữ liệu kỳ này</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div></div>
</div>

<!-- Record Modal -->
<div class="modal fade" id="recordModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title"><i class="bi bi-lightning-charge-fill me-2"></i>Ghi Chỉ Số Điện/Nước</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <form method="POST">
            <input type="hidden" name="action" value="record">
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Phòng <span class="text-danger">*</span></label>
                        <select name="room_id" class="form-select" required>
                            <option value="">-- Chọn phòng --</option>
                            <?php foreach ($rooms as $room): ?>
                            <option value="<?= $room['id'] ?>"><?= $room['building_name'] ?> - Phòng <?= $room['room_number'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Loại</label>
                        <select name="type" class="form-select" id="utilityType" onchange="updateUnitPrice()">
                            <option value="electricity">⚡ Điện</option>
                            <option value="water">💧 Nước</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Kỳ</label>
                        <input type="month" name="period" class="form-control" value="<?= date('Y-m') ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Chỉ Số Cũ</label>
                        <input type="number" name="prev_reading" class="form-control" step="0.01" value="0" min="0" id="prevReading" oninput="calcUsed()">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Chỉ Số Mới</label>
                        <input type="number" name="curr_reading" class="form-control" step="0.01" value="0" min="0" id="currReading" oninput="calcUsed()">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Đơn Giá (VNĐ)</label>
                        <input type="number" name="unit_price" class="form-control" id="unitPrice" value="3500" min="0">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Sử Dụng / Thành Tiền</label>
                        <div id="calcResult" style="padding:8px 12px;background:#f0f4ff;border-radius:6px;font-weight:600;font-size:13px;">—</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                <button type="submit" class="btn btn-primary">Ghi Chỉ Số</button>
            </div>
        </form>
    </div></div>
</div>

<script>
function updateUnitPrice() {
    const t = document.getElementById('utilityType').value;
    document.getElementById('unitPrice').value = t === 'electricity' ? 3500 : 15000;
    calcUsed();
}
function calcUsed() {
    const prev = parseFloat(document.getElementById('prevReading').value) || 0;
    const curr = parseFloat(document.getElementById('currReading').value) || 0;
    const price = parseFloat(document.getElementById('unitPrice').value) || 0;
    const used = Math.max(0, curr - prev);
    const amount = used * price;
    document.getElementById('calcResult').textContent = `${used.toFixed(2)} đv = ${new Intl.NumberFormat('vi-VN').format(amount)} đ`;
}
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
