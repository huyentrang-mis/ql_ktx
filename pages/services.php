<?php
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $name  = trim($_POST['name'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $price = (float)($_POST['default_price'] ?? 0);
        $unit  = trim($_POST['unit'] ?? '');
        if ($name) {
            $db->prepare("INSERT INTO services (name, description, default_price, unit) VALUES (?,?,?,?)")
               ->execute([$name, $desc, $price, $unit]);
            setFlash('success', "Đã thêm dịch vụ '$name'");
        }
        header('Location: services.php'); exit;
    }
    if ($action === 'toggle') {
        $id = (int)$_POST['id'];
        $db->prepare("UPDATE services SET is_active = NOT is_active WHERE id=?")->execute([$id]);
        setFlash('info', 'Đã cập nhật trạng thái dịch vụ.');
        header('Location: services.php'); exit;
    }
}

$services = $db->query("SELECT * FROM services ORDER BY id")->fetchAll();
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="container-fluid px-0">
<?php showFlash(); ?>

<div class="page-header">
    <div class="page-title"><i class="bi bi-gear-fill"></i> Quản Lý Dịch Vụ</div>
    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addServiceModal">
        <i class="bi bi-plus-lg me-1"></i>Thêm Dịch Vụ
    </button>
</div>

<div class="card"><div class="card-body p-0">
    <table class="table mb-0">
        <thead><tr><th>#</th><th>Tên Dịch Vụ</th><th>Mô Tả</th><th>Giá Mặc Định</th><th>Đơn Vị</th><th>Trạng Thái</th><th>Thao Tác</th></tr></thead>
        <tbody>
        <?php foreach ($services as $sv): ?>
        <tr>
            <td><?= $sv['id'] ?></td>
            <td><strong><?= htmlspecialchars($sv['name']) ?></strong></td>
            <td style="font-size:13px;color:var(--text-muted);"><?= htmlspecialchars($sv['description'] ?? '') ?></td>
            <td><?= formatMoney($sv['default_price']) ?></td>
            <td><?= htmlspecialchars($sv['unit'] ?? '') ?></td>
            <td><?= $sv['is_active'] ? '<span class="badge bg-success">Hoạt động</span>' : '<span class="badge bg-secondary">Tắt</span>' ?></td>
            <td>
                <form method="POST" class="d-inline">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= $sv['id'] ?>">
                    <button type="submit" class="btn btn-sm <?= $sv['is_active']?'btn-outline-warning':'btn-outline-success' ?>">
                        <?= $sv['is_active']?'Tắt':'Bật' ?>
                    </button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$services): ?>
        <tr><td colspan="7" class="text-center py-4 text-muted">Chưa có dịch vụ nào</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div></div>
</div>

<div class="modal fade" id="addServiceModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Thêm Dịch Vụ</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="modal-body">
                <div class="mb-3"><label class="form-label">Tên Dịch Vụ <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" required></div>
                <div class="mb-3"><label class="form-label">Mô Tả</label>
                    <textarea name="description" class="form-control" rows="2"></textarea></div>
                <div class="row g-2">
                    <div class="col-6"><label class="form-label">Giá Mặc Định</label>
                        <input type="number" name="default_price" class="form-control" value="0" min="0"></div>
                    <div class="col-6"><label class="form-label">Đơn Vị</label>
                        <input type="text" name="unit" class="form-control" placeholder="tháng, kWh, m³..."></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                <button type="submit" class="btn btn-primary">Thêm</button>
            </div>
        </form>
    </div></div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
