<?php
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin', 'staff');
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_building') {
        $name    = trim($_POST['name'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $floors  = (int)($_POST['floors'] ?? 1);
        if ($name) {
            $db->prepare("INSERT INTO buildings (name, address, floors) VALUES (?,?,?)")->execute([$name, $address, $floors]);
            logActivity($_SESSION['user_id'], 'add_building', "Thêm tòa nhà: $name");
            setFlash('success', "Đã thêm tòa nhà '$name'");
        }
        header('Location: rooms.php'); exit;
    }

    if ($action === 'add_room') {
        $buildingId     = (int)$_POST['building_id'];
        $roomNumber     = trim($_POST['room_number'] ?? '');
        $type           = $_POST['type'] ?? 'standard';
        $capacity       = (int)($_POST['capacity'] ?? 4);
        $pricePerMonth  = (float)($_POST['price_per_month'] ?? 0);
        $description    = trim($_POST['description'] ?? '');
        if ($buildingId && $roomNumber) {
            $db->prepare("INSERT INTO rooms (building_id, room_number, type, capacity, price_per_month, description, status) VALUES (?,?,?,?,?,?,'available')")
               ->execute([$buildingId, $roomNumber, $type, $capacity, $pricePerMonth, $description]);
            logActivity($_SESSION['user_id'], 'add_room', "Thêm phòng $roomNumber");
            setFlash('success', "Đã thêm phòng $roomNumber");
        }
        header('Location: rooms.php'); exit;
    }

    if ($action === 'set_status') {
        $roomId = (int)$_POST['room_id'];
        $status = $_POST['status'] ?? '';
        if (in_array($status, ['available','full','maintenance'])) {
            $db->prepare("UPDATE rooms SET status=? WHERE id=?")->execute([$status, $roomId]);
            logActivity($_SESSION['user_id'], 'update_room_status', "Cập nhật trạng thái phòng $roomId -> $status");
            setFlash('info', 'Đã cập nhật trạng thái phòng.');
        }
        header('Location: rooms.php'); exit;
    }
}

$buildings = $db->query("SELECT * FROM buildings ORDER BY name")->fetchAll();

$buildingFilter = $_GET['building'] ?? '';
$search = trim($_GET['search'] ?? '');
$where = "WHERE 1=1"; $params = [];
if ($buildingFilter) { $where .= " AND r.building_id=?"; $params[] = $buildingFilter; }
if ($search) { $where .= " AND r.room_number LIKE ?"; $params[] = "%$search%"; }

$stmt = $db->prepare("SELECT r.*, b.name as building_name,
    (SELECT COUNT(*) FROM student_profiles sp WHERE sp.room_id=r.id) as occupied
    FROM rooms r JOIN buildings b ON r.building_id=b.id $where ORDER BY b.name, r.room_number");
$stmt->execute($params);
$rooms = $stmt->fetchAll();
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="container-fluid px-0">
<?php showFlash(); ?>

<div class="page-header">
    <div class="page-title"><i class="bi bi-door-closed-fill"></i> Quản Lý Phòng Ở</div>
    <div class="d-flex gap-2">
        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addBuildingModal">
            <i class="bi bi-plus-lg me-1"></i>Thêm Tòa
        </button>
        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addRoomModal">
            <i class="bi bi-plus-lg me-1"></i>Thêm Phòng
        </button>
    </div>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
<?php
$rStats = $db->query("SELECT status, COUNT(*) as cnt FROM rooms GROUP BY status")->fetchAll();
$rMap = array_column($rStats, 'cnt', 'status');
?>
    <div class="col-sm-4">
        <div class="stat-card"><div class="stat-icon green"><i class="bi bi-door-open-fill"></i></div>
            <div class="stat-info"><div class="stat-label">Còn Trống</div><div class="stat-value"><?= $rMap['available'] ?? 0 ?></div></div></div>
    </div>
    <div class="col-sm-4">
        <div class="stat-card"><div class="stat-icon orange"><i class="bi bi-people-fill"></i></div>
            <div class="stat-info"><div class="stat-label">Đang Đầy</div><div class="stat-value"><?= $rMap['full'] ?? 0 ?></div></div></div>
    </div>
    <div class="col-sm-4">
        <div class="stat-card"><div class="stat-icon blue"><i class="bi bi-wrench-adjustable-fill"></i></div>
            <div class="stat-info"><div class="stat-label">Bảo Trì</div><div class="stat-value"><?= $rMap['maintenance'] ?? 0 ?></div></div></div>
    </div>
</div>

<!-- Filter -->
<div class="card mb-4"><div class="card-body py-3">
    <form method="GET" class="d-flex gap-2 flex-wrap">
        <select name="building" class="form-select" style="max-width:200px;">
            <option value="">Tất cả tòa</option>
            <?php foreach ($buildings as $b): ?>
            <option value="<?= $b['id'] ?>" <?= $buildingFilter==$b['id']?'selected':'' ?>><?= htmlspecialchars($b['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="search" class="form-control" style="max-width:200px;" placeholder="Số phòng..." value="<?= htmlspecialchars($search) ?>">
        <button class="btn btn-primary"><i class="bi bi-search"></i></button>
        <a href="rooms.php" class="btn btn-outline-secondary">Reset</a>
    </form>
</div></div>

<div class="card"><div class="card-body p-0">
    <div class="table-responsive">
    <table class="table mb-0">
        <thead><tr>
            <th>Phòng</th><th>Tòa Nhà</th><th>Loại</th><th>Sức Chứa</th><th>Giá/Tháng</th><th>Trạng Thái</th><th>Thao Tác</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rooms as $room): ?>
        <tr>
            <td><strong><?= htmlspecialchars($room['room_number']) ?></strong></td>
            <td><?= htmlspecialchars($room['building_name']) ?></td>
            <td><?= statusBadge($room['type']) ?></td>
            <td><?= $room['occupied'] ?> / <?= $room['capacity'] ?> người</td>
            <td><strong><?= formatMoney($room['price_per_month']) ?></strong></td>
            <td><?= statusBadge($room['status']) ?></td>
            <td>
                <form method="POST" class="d-inline-flex gap-1">
                    <input type="hidden" name="action" value="set_status">
                    <input type="hidden" name="room_id" value="<?= $room['id'] ?>">
                    <select name="status" class="form-select form-select-sm" style="width:130px;">
                        <option value="available" <?= $room['status']==='available'?'selected':'' ?>>Còn trống</option>
                        <option value="full" <?= $room['status']==='full'?'selected':'' ?>>Đầy</option>
                        <option value="maintenance" <?= $room['status']==='maintenance'?'selected':'' ?>>Bảo trì</option>
                    </select>
                    <button class="btn btn-sm btn-outline-primary">Lưu</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rooms): ?>
        <tr><td colspan="7" class="text-center py-4 text-muted">Chưa có phòng nào</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div></div>
</div>

<!-- Add Building Modal -->
<div class="modal fade" id="addBuildingModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Thêm Tòa Nhà</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <form method="POST">
            <input type="hidden" name="action" value="add_building">
            <div class="modal-body">
                <div class="mb-3"><label class="form-label">Tên Tòa <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" placeholder="Tòa A, Khu B..." required></div>
                <div class="mb-3"><label class="form-label">Địa chỉ</label>
                    <input type="text" name="address" class="form-control"></div>
                <div class="mb-3"><label class="form-label">Số tầng</label>
                    <input type="number" name="floors" class="form-control" value="5" min="1"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                <button type="submit" class="btn btn-primary">Thêm Tòa</button>
            </div>
        </form>
    </div></div>
</div>

<!-- Add Room Modal -->
<div class="modal fade" id="addRoomModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Thêm Phòng Mới</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <form method="POST">
            <input type="hidden" name="action" value="add_room">
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label">Tòa Nhà <span class="text-danger">*</span></label>
                        <select name="building_id" class="form-select" required>
                            <option value="">-- Chọn tòa --</option>
                            <?php foreach ($buildings as $b): ?>
                            <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Số Phòng <span class="text-danger">*</span></label>
                        <input type="text" name="room_number" class="form-control" placeholder="101" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Loại Phòng</label>
                        <select name="type" class="form-select">
                            <option value="standard">Tiêu chuẩn</option>
                            <option value="premium">Cao cấp</option>
                            <option value="vip">VIP</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Sức Chứa</label>
                        <input type="number" name="capacity" class="form-control" value="4" min="1" max="20">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Giá/Tháng (VNĐ)</label>
                        <input type="number" name="price_per_month" class="form-control" placeholder="800000" min="0">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Mô tả</label>
                        <textarea name="description" class="form-control" rows="2"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                <button type="submit" class="btn btn-primary">Thêm Phòng</button>
            </div>
        </form>
    </div></div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
