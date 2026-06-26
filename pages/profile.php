<?php
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$user = currentUser();
$db   = getDB();

$error = $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $fullName = trim($_POST['full_name'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');
        $email    = trim($_POST['email'] ?? '');

        if (!$fullName || !$email) {
            $error = 'Họ tên và email không được để trống.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Email không hợp lệ.';
        } else {
            $check = $db->prepare("SELECT id FROM users WHERE email=? AND id!=?");
            $check->execute([$email, $user['id']]);
            if ($check->fetch()) {
                $error = 'Email đã được dùng bởi tài khoản khác.';
            } else {
                $db->prepare("UPDATE users SET full_name=?, phone=?, email=? WHERE id=?")
                   ->execute([$fullName, $phone, $email, $user['id']]);
                $_SESSION['full_name'] = $fullName;
                $_SESSION['email']     = $email;
                logActivity($user['id'], 'update_profile', 'Cập nhật hồ sơ cá nhân');
                $success = 'Cập nhật thông tin thành công!';
                $user    = currentUser(); // refresh
            }
        }
    }

    if ($action === 'change_password') {
        $currentPw = $_POST['current_password'] ?? '';
        $newPw     = $_POST['new_password'] ?? '';
        $confirmPw = $_POST['confirm_password'] ?? '';

        if (!password_verify($currentPw, $user['password'])) {
            $error = 'Mật khẩu hiện tại không đúng.';
        } elseif (strlen($newPw) < 8) {
            $error = 'Mật khẩu mới phải có ít nhất 8 ký tự.';
        } elseif ($newPw !== $confirmPw) {
            $error = 'Mật khẩu xác nhận không khớp.';
        } else {
            $hash = password_hash($newPw, PASSWORD_BCRYPT);
            $db->prepare("UPDATE users SET password=? WHERE id=?")->execute([$hash, $user['id']]);
            logActivity($user['id'], 'change_password', 'Đổi mật khẩu thành công');
            $success = 'Đổi mật khẩu thành công!';
        }
    }
}

// Student profile
$sp = null;
if ($user['role'] === 'student') {
    $spStmt = $db->prepare("SELECT * FROM student_profiles WHERE user_id=?");
    $spStmt->execute([$user['id']]);
    $sp = $spStmt->fetch();
}
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="container-fluid px-0">
<?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($success) ?></div><?php endif; ?>

<div class="page-header">
    <div class="page-title"><i class="bi bi-person-circle"></i> Hồ Sơ Cá Nhân</div>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card text-center">
            <div class="card-body py-4">
                <div style="width:80px;height:80px;border-radius:50%;background:var(--primary);color:#fff;
                    font-size:2rem;font-weight:700;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                    <?= mb_substr($user['full_name'], 0, 1) ?>
                </div>
                <h5><?= htmlspecialchars($user['full_name']) ?></h5>
                <p style="color:var(--text-muted);">@<?= htmlspecialchars($user['username']) ?></p>
                <?= statusBadge($user['role']) ?>
                <hr>
                <table class="table table-borderless text-start" style="font-size:13px;">
                    <tr><td style="color:var(--text-muted);">Email</td><td><?= htmlspecialchars($user['email']) ?></td></tr>
                    <tr><td style="color:var(--text-muted);">Điện thoại</td><td><?= htmlspecialchars($user['phone'] ?? '—') ?></td></tr>
                    <tr><td style="color:var(--text-muted);">Trạng thái</td><td><?= statusBadge($user['status']) ?></td></tr>
                    <tr><td style="color:var(--text-muted);">Tạo lúc</td><td><?= formatDate($user['created_at']) ?></td></tr>
                    <?php if ($user['last_login']): ?>
                    <tr><td style="color:var(--text-muted);">Đăng nhập cuối</td><td><?= formatDateTime($user['last_login']) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($sp): ?>
                    <tr><td style="color:var(--text-muted);">Mã sinh viên</td><td class="text-mono"><?= $sp['student_code'] ?></td></tr>
                    <tr><td style="color:var(--text-muted);">Trường học</td><td><?= htmlspecialchars($sp['university'] ?? '—') ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <!-- Update Profile -->
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-pencil-fill"></i> Cập Nhật Thông Tin</div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="update_profile">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Họ và Tên <span class="text-danger">*</span></label>
                            <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($user['full_name']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Số Điện Thoại</label>
                            <input type="tel" name="phone" class="form-control" value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary mt-3">
                        <i class="bi bi-save me-1"></i>Lưu Thay Đổi
                    </button>
                </form>
            </div>
        </div>

        <!-- Change Password -->
        <div class="card">
            <div class="card-header"><i class="bi bi-lock-fill"></i> Đổi Mật Khẩu</div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="change_password">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Mật Khẩu Hiện Tại <span class="text-danger">*</span></label>
                            <input type="password" name="current_password" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Mật Khẩu Mới <span class="text-danger">*</span></label>
                            <input type="password" name="new_password" class="form-control" placeholder="Tối thiểu 8 ký tự" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Xác Nhận Mật Khẩu <span class="text-danger">*</span></label>
                            <input type="password" name="confirm_password" class="form-control" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-warning mt-3">
                        <i class="bi bi-key-fill me-1"></i>Đổi Mật Khẩu
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
