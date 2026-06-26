<?php
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin', 'staff');
$db = getDB();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'assign_room') {
        $userId = (int)$_POST['user_id'];
        $roomId  = (int)$_POST['room_id'];
        // Update student profile room
        $db->prepare("UPDATE student_profiles SET room_id=? WHERE user_id=?")->execute([$roomId, $userId]);
        // Update room status
        $cap = $db->prepare("SELECT capacity, (SELECT COUNT(*) FROM student_profiles WHERE room_id=?) AS used FROM rooms WHERE id=?");
        $cap->execute([$roomId, $roomId]);
        $r = $cap->fetch();
        $newStatus = ($r['used'] >= $r['capacity']) ? 'full' : 'available';
        $db->prepare("UPDATE rooms SET status=? WHERE id=?")->execute([$newStatus, $roomId]);
        logActivity($_SESSION['user_id'], 'assign_room', "Xếp phòng $roomId cho sinh viên $userId");
        setFlash('success', 'Đã xếp phòng thành công!');
        header('Location: students.php');
        exit;
    }

    if ($action === 'toggle_status') {
        $userId    = (int)$_POST['user_id'];
        $newStatus = $_POST['new_status'];
        if (in_array($newStatus, ['active','inactive'])) {
            $db->prepare("UPDATE users SET status=? WHERE id=?")->execute([$newStatus, $userId]);
            logActivity($_SESSION['user_id'], 'toggle_student', "Đổi trạng thái sinh viên $userId -> $newStatus");
            setFlash('info', 'Đã cập nhật trạng thái.');
        }
        header('Location: students.php');
        exit;
    }
}

$search = trim($_GET['search'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$where  = "WHERE u.role='student'";
$params = [];
if ($search) {
    $where .= " AND (u.full_name LIKE ? OR u.email LIKE ? OR sp.student_code LIKE ? OR u.username LIKE ?)";
    $params = array_fill(0, 4, "%$search%");
}

$total = $db->prepare("SELECT COUNT(*) FROM users u LEFT JOIN student_profiles sp ON u.id=sp.user_id $where");
$total->execute($params);
$totalCount = $total->fetchColumn();
$totalPages = ceil($totalCount / $perPage);
$offset = ($page - 1) * $perPage;

$stmt = $db->prepare("SELECT u.*, sp.student_code, sp.university, sp.room_id, r.room_number, b.name as building_name
    FROM users u LEFT JOIN student_profiles sp ON u.id=sp.user_id
    LEFT JOIN rooms r ON sp.room_id=r.id LEFT JOIN buildings b ON r.building_id=b.id
    $where ORDER BY u.created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$students = $stmt->fetchAll();

$rooms = $db->query("SELECT r.*, b.name as building_name FROM rooms r JOIN buildings b ON r.building_id=b.id WHERE r.status='available' ORDER BY b.name, r.room_number")->fetchAll();
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="container-fluid px-0">
<?php showFlash(); ?>

<div class="page-header">
    <div class="page-title"><i class="bi bi-people-fill"></i> Quản Lý Sinh Viên</div>
    <span style="font-size:13px;color:var(--text-muted);"><?= number_format($totalCount) ?> sinh viên</span>
</div>

<div class="card mb-4">
    <div class="card-body py-3">
        <form method="GET" class="d-flex gap-2 flex-wrap">
            <input type="text" name="search" class="form-control" style="max-width:320px;"
                placeholder="Tìm tên, email, mã SV..." value="<?= htmlspecialchars($search) ?>">
            <button class="btn btn-primary"><i class="bi bi-search me-1"></i>Tìm</button>
            <?php if ($search): ?>
            <a href="students.php" class="btn btn-outline-secondary">Xóa lọc</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table mb-0">
            <thead><tr>
                <th>#</th>
                <th>Sinh Viên</th>
                <th>Mã SV</th>
                <th>Email</th>
                <th>Phòng</th>
                <th>Trạng Thái</th>
                <th>Ngày Tạo</th>
                <th>Thao Tác</th>
            </tr></thead>
            <tbody>
            <?php foreach ($students as $i => $sv): ?>
            <tr>
                <td style="color:var(--text-muted);font-size:12px;"><?= ($page-1)*$perPage + $i + 1 ?></td>
                <td>
                    <div style="font-weight:600;"><?= htmlspecialchars($sv['full_name']) ?></div>
                    <div style="font-size:12px;color:var(--text-muted);">@<?= htmlspecialchars($sv['username']) ?></div>
                </td>
                <td><span class="text-mono" style="font-size:12px;"><?= htmlspecialchars($sv['student_code'] ?? '—') ?></span></td>
                <td style="font-size:13px;"><?= htmlspecialchars($sv['email']) ?></td>
                <td>
                    <?php if ($sv['room_number']): ?>
                    <span class="badge bg-success"><?= $sv['building_name'] ?> - <?= $sv['room_number'] ?></span>
                    <?php else: ?>
                    <span class="badge bg-secondary">Chưa có phòng</span>
                    <!-- Assign Room Button -->
                    <button class="btn btn-xs btn-outline-primary ms-1" style="font-size:11px;padding:2px 7px;"
                        data-bs-toggle="modal" data-bs-target="#assignModal"
                        data-userid="<?= $sv['id'] ?>" data-name="<?= htmlspecialchars($sv['full_name']) ?>">
                        Xếp phòng
                    </button>
                    <?php endif; ?>
                </td>
                <td><?= statusBadge($sv['status']) ?></td>
                <td style="font-size:12px;color:var(--text-muted);"><?= formatDate($sv['created_at']) ?></td>
                <td>
                    <div class="d-flex gap-1">
                        <a href="invoices.php?student_id=<?= $sv['id'] ?>" class="btn btn-xs btn-outline-primary" style="font-size:11px;padding:2px 8px;">
                            <i class="bi bi-receipt-cutoff"></i> HĐ
                        </a>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="action" value="toggle_status">
                            <input type="hidden" name="user_id" value="<?= $sv['id'] ?>">
                            <input type="hidden" name="new_status" value="<?= $sv['status']==='active' ? 'inactive' : 'active' ?>">
                            <button type="submit" class="btn btn-xs <?= $sv['status']==='active' ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                                style="font-size:11px;padding:2px 8px;"
                                onclick="return confirm('Đổi trạng thái sinh viên này?')">
                                <?= $sv['status']==='active' ? 'Khóa' : 'Mở' ?>
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$students): ?>
            <tr><td colspan="8" class="text-center py-4 text-muted">Không có sinh viên nào</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<nav class="mt-3">
    <ul class="pagination justify-content-center">
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
        <li class="page-item <?= $p==$page?'active':'' ?>">
            <a class="page-link" href="?page=<?= $p ?>&search=<?= urlencode($search) ?>"><?= $p ?></a>
        </li>
        <?php endfor; ?>
    </ul>
</nav>
<?php endif; ?>

<!-- Assign Room Modal -->
<div class="modal fade" id="assignModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-door-closed-fill me-2"></i>Xếp Phòng</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="assign_room">
                <input type="hidden" name="user_id" id="assignUserId">
                <div class="modal-body">
                    <p>Xếp phòng cho: <strong id="assignStudentName"></strong></p>
                    <div class="mb-3">
                        <label class="form-label">Chọn phòng còn trống</label>
                        <select name="room_id" class="form-select" required>
                            <option value="">-- Chọn phòng --</option>
                            <?php foreach ($rooms as $room): ?>
                            <option value="<?= $room['id'] ?>">
                                <?= htmlspecialchars($room['building_name']) ?> - Phòng <?= $room['room_number'] ?>
                                (<?= $room['type'] ?>, <?= formatMoney($room['price_per_month']) ?>/tháng)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-primary">Xác Nhận Xếp Phòng</button>
                </div>
            </form>
        </div>
    </div>
</div>
</div>

<script>
document.getElementById('assignModal').addEventListener('show.bs.modal', function(e) {
    const btn = e.relatedTarget;
    document.getElementById('assignUserId').value = btn.dataset.userid;
    document.getElementById('assignStudentName').textContent = btn.dataset.name;
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
