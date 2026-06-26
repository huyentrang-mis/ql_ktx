<?php
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$user = currentUser();
if ($user['role'] !== 'student') { header('Location: ' . BASE_URL . '/pages/dashboard.php'); exit; }
$db = getDB();

$sp = $db->prepare("SELECT sp.*, r.room_number, r.capacity, r.type, r.price_per_month, r.description as room_desc,
    b.name as building_name, b.address
    FROM student_profiles sp
    LEFT JOIN rooms r ON sp.room_id=r.id LEFT JOIN buildings b ON r.building_id=b.id
    WHERE sp.user_id=?");
$sp->execute([$user['id']]);
$profile = $sp->fetch();

$roommates = [];
if ($profile && $profile['room_id']) {
    $rm = $db->prepare("SELECT u.full_name, u.email, u.phone, sp.student_code
        FROM student_profiles sp JOIN users u ON sp.user_id=u.id
        WHERE sp.room_id=? AND sp.user_id != ? AND u.status='active'");
    $rm->execute([$profile['room_id'], $user['id']]);
    $roommates = $rm->fetchAll();
}
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="container-fluid px-0">

<div class="page-header">
    <div class="page-title"><i class="bi bi-house-fill"></i> Phòng Của Tôi</div>
</div>

<?php if (!$profile || !$profile['room_id']): ?>
<div class="card text-center py-5">
    <div class="card-body">
        <i class="bi bi-house-slash" style="font-size:3rem;color:var(--text-muted);"></i>
        <h4 class="mt-3">Chưa Được Xếp Phòng</h4>
        <p style="color:var(--text-muted);">Vui lòng liên hệ ban quản lý ký túc xá để được xếp phòng.</p>
        <a href="<?= BASE_URL ?>/pages/notifications.php" class="btn btn-primary mt-2">
            <i class="bi bi-bell me-2"></i>Xem Thông Báo
        </a>
    </div>
</div>
<?php else: ?>
<div class="row g-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-door-closed-fill"></i> Thông Tin Phòng</div>
            <div class="card-body">
                <table class="table table-borderless">
                    <tr><td style="color:var(--text-muted);width:40%;">Phòng số</td>
                        <td><strong style="font-size:1.2rem;"><?= htmlspecialchars($profile['room_number']) ?></strong></td></tr>
                    <tr><td style="color:var(--text-muted);">Tòa nhà</td>
                        <td><?= htmlspecialchars($profile['building_name']) ?></td></tr>
                    <tr><td style="color:var(--text-muted);">Địa chỉ</td>
                        <td><?= htmlspecialchars($profile['address'] ?? '—') ?></td></tr>
                    <tr><td style="color:var(--text-muted);">Loại phòng</td>
                        <td><?= statusBadge($profile['type']) ?></td></tr>
                    <tr><td style="color:var(--text-muted);">Sức chứa</td>
                        <td><?= $profile['capacity'] ?> người</td></tr>
                    <tr><td style="color:var(--text-muted);">Giá thuê</td>
                        <td><strong style="color:var(--primary);"><?= formatMoney($profile['price_per_month']) ?>/tháng</strong></td></tr>
                    <?php if ($profile['room_desc']): ?>
                    <tr><td style="color:var(--text-muted);">Mô tả</td>
                        <td><?= htmlspecialchars($profile['room_desc']) ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-person-fill"></i> Thông Tin Sinh Viên</div>
            <div class="card-body">
                <table class="table table-borderless">
                    <tr><td style="color:var(--text-muted);width:40%;">Họ tên</td>
                        <td><strong><?= htmlspecialchars($user['full_name']) ?></strong></td></tr>
                    <tr><td style="color:var(--text-muted);">Mã sinh viên</td>
                        <td class="text-mono"><?= htmlspecialchars($profile['student_code'] ?? '—') ?></td></tr>
                    <tr><td style="color:var(--text-muted);">Email</td>
                        <td><?= htmlspecialchars($user['email']) ?></td></tr>
                    <tr><td style="color:var(--text-muted);">Điện thoại</td>
                        <td><?= htmlspecialchars($user['phone'] ?? '—') ?></td></tr>
                    <tr><td style="color:var(--text-muted);">Trường học</td>
                        <td><?= htmlspecialchars($profile['university'] ?? '—') ?></td></tr>
                </table>
                <a href="profile.php" class="btn btn-outline-primary btn-sm mt-2">
                    <i class="bi bi-pencil-fill me-1"></i>Chỉnh Sửa Hồ Sơ
                </a>
            </div>
        </div>
    </div>

    <?php if ($roommates): ?>
    <div class="col-12">
        <div class="card">
            <div class="card-header"><i class="bi bi-people-fill"></i> Bạn Cùng Phòng (<?= count($roommates) ?> người)</div>
            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead><tr><th>Họ Tên</th><th>Mã SV</th><th>Email</th><th>Điện Thoại</th></tr></thead>
                    <tbody>
                    <?php foreach ($roommates as $rm): ?>
                    <tr>
                        <td><?= htmlspecialchars($rm['full_name']) ?></td>
                        <td class="text-mono" style="font-size:12px;"><?= htmlspecialchars($rm['student_code'] ?? '—') ?></td>
                        <td style="font-size:13px;"><?= htmlspecialchars($rm['email']) ?></td>
                        <td style="font-size:13px;"><?= htmlspecialchars($rm['phone'] ?? '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
