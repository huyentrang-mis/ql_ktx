<?php
require_once __DIR__ . '/../includes/functions.php';

if (isLoggedIn()) { header('Location: ' . BASE_URL . '/pages/dashboard.php'); exit; }

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'username'   => trim($_POST['username'] ?? ''),
        'email'      => trim($_POST['email'] ?? ''),
        'full_name'  => trim($_POST['full_name'] ?? ''),
        'phone'      => trim($_POST['phone'] ?? ''),
        'password'   => $_POST['password'] ?? '',
        'confirm'    => $_POST['confirm_password'] ?? '',
        'student_code'=> trim($_POST['student_code'] ?? ''),
        'university' => trim($_POST['university'] ?? ''),
    ];

    if (empty($data['username']) || empty($data['email']) || empty($data['full_name']) || empty($data['password'])) {
        $error = 'Vui lòng điền đầy đủ các trường bắt buộc.';
    } elseif (strlen($data['password']) < 8) {
        $error = 'Mật khẩu phải có ít nhất 8 ký tự.';
    } elseif ($data['password'] !== $data['confirm']) {
        $error = 'Mật khẩu xác nhận không khớp.';
    } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Email không hợp lệ.';
    } else {
        $db = getDB();
        $check = $db->prepare("SELECT id FROM users WHERE username=? OR email=?");
        $check->execute([$data['username'], $data['email']]);
        if ($check->fetch()) {
            $error = 'Tên đăng nhập hoặc email đã tồn tại.';
        } else {
            $hash = password_hash($data['password'], PASSWORD_BCRYPT);
            $db->prepare("INSERT INTO users (username,email,password,full_name,phone,role,status) VALUES (?,?,?,?,?,'student','active')")
               ->execute([$data['username'], $data['email'], $hash, $data['full_name'], $data['phone']]);
            $userId = $db->lastInsertId();

            // Create student profile
            $studentCode = $data['student_code'] ?: ('SV' . date('Y') . str_pad($userId, 4, '0', STR_PAD_LEFT));
            $db->prepare("INSERT INTO student_profiles (user_id, student_code, university) VALUES (?,?,?)")
               ->execute([$userId, $studentCode, $data['university']]);

            logActivity($userId, 'register', 'Đăng ký tài khoản mới');
            header('Location: ' . BASE_URL . '/pages/login.php?registered=1');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Đăng Ký - <?= SITE_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body class="auth-body" style="padding: 40px 20px;">

<div class="auth-card" style="max-width:520px;">
    <div class="auth-logo">
        <div class="logo-icon"><i class="bi bi-person-plus-fill"></i></div>
        <h1>Đăng Ký Tài Khoản</h1>
        <p>Tạo tài khoản sinh viên ký túc xá</p>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="row g-3">
            <div class="col-12">
                <label class="form-label">Họ và Tên <span class="text-danger">*</span></label>
                <input type="text" name="full_name" class="form-control" placeholder="Nguyễn Văn A"
                    value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Tên đăng nhập <span class="text-danger">*</span></label>
                <input type="text" name="username" class="form-control" placeholder="username"
                    value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Số điện thoại</label>
                <input type="tel" name="phone" class="form-control" placeholder="0901234567"
                    value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
            </div>
            <div class="col-12">
                <label class="form-label">Email <span class="text-danger">*</span></label>
                <input type="email" name="email" class="form-control" placeholder="email@domain.com"
                    value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Mã sinh viên</label>
                <input type="text" name="student_code" class="form-control" placeholder="SV2024XXXX"
                    value="<?= htmlspecialchars($_POST['student_code'] ?? '') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Trường học</label>
                <input type="text" name="university" class="form-control" placeholder="Đại học ABC"
                    value="<?= htmlspecialchars($_POST['university'] ?? '') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Mật khẩu <span class="text-danger">*</span></label>
                <input type="password" name="password" class="form-control" placeholder="Tối thiểu 8 ký tự" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Xác nhận mật khẩu <span class="text-danger">*</span></label>
                <input type="password" name="confirm_password" class="form-control" placeholder="Nhập lại mật khẩu" required>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary w-100 py-2">
                    <i class="bi bi-person-check-fill me-2"></i>Đăng Ký
                </button>
            </div>
        </div>
    </form>

    <div class="text-center mt-3" style="font-size:13px;color:var(--text-muted);">
        Đã có tài khoản?
        <a href="<?= BASE_URL ?>/pages/login.php" style="color:var(--primary-light);text-decoration:none;font-weight:600;">Đăng nhập</a>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>