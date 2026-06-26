<?php
require_once __DIR__ . '/../includes/functions.php';

if (isLoggedIn()) {
    header('Location: ' . BASE_URL . '/pages/dashboard.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Vui lòng nhập đầy đủ thông tin.';
    } else {
        $user = login($username, $password);
        if ($user) {
            setFlash('success', 'Chào mừng, ' . htmlspecialchars($user['full_name']) . '!');
            header('Location: ' . BASE_URL . '/pages/dashboard.php');
            exit;
        } else {
            $error = 'Tên đăng nhập hoặc mật khẩu không đúng.';
            logActivity(null, 'login_failed', 'Thử đăng nhập với: ' . $username);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Đăng Nhập - <?= SITE_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body class="auth-body">

<div class="auth-card">
    <div class="auth-logo">
        <div class="logo-icon"><i class="bi bi-building-fill"></i></div>
        <h1>KTX Smart</h1>
        <p>Hệ thống quản lý ký túc xá thông minh</p>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger mb-4">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <?php if (isset($_GET['registered'])): ?>
    <div class="alert alert-success mb-4">
        <i class="bi bi-check-circle-fill me-2"></i>Đăng ký thành công! Vui lòng đăng nhập.
    </div>
    <?php endif; ?>

    <?php if (isset($_GET['reset'])): ?>
    <div class="alert alert-success mb-4">
        <i class="bi bi-check-circle-fill me-2"></i>Mật khẩu đã được đặt lại thành công!
    </div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="mb-3">
            <label class="form-label">Tên đăng nhập hoặc Email</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
                <input type="text" name="username" class="form-control" placeholder="username hoặc email@domain.vn"
                    value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autofocus>
            </div>
        </div>
        <div class="mb-4">
            <label class="form-label">Mật khẩu</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                <input type="password" name="password" id="passwordInput" class="form-control" placeholder="••••••••" required>
                <button type="button" class="input-group-text" onclick="togglePwd()">
                    <i class="bi bi-eye" id="eyeIcon"></i>
                </button>
            </div>
        </div>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="remember">
                <label class="form-check-label" for="remember" style="font-size:13px;">Ghi nhớ đăng nhập</label>
            </div>
            <a href="<?= BASE_URL ?>/pages/forgot_password.php" style="font-size:13px;color:var(--primary-light);text-decoration:none;">
                Quên mật khẩu?
            </a>
        </div>
        <button type="submit" class="btn btn-primary w-100 py-2">
            <i class="bi bi-box-arrow-in-right me-2"></i>Đăng Nhập
        </button>
    </form>

    <div class="text-center mt-4" style="font-size:13px;color:var(--text-muted);">
        Chưa có tài khoản?
        <a href="<?= BASE_URL ?>/pages/register.php" style="color:var(--primary-light);text-decoration:none;font-weight:600;">
            Đăng ký ngay
        </a>
    </div>

    <div class="mt-4 p-3 rounded" style="background:#f8f9fd;font-size:12px;color:var(--text-muted);">
        <strong>Tài khoản demo:</strong><br>
        Admin: <code>admin</code> / <code>password</code><br>
        Nhân viên: <code>staff01</code> / <code>password</code><br>
        Sinh viên: <code>sv001</code> / <code>password</code>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function togglePwd() {
    const inp = document.getElementById('passwordInput');
    const ico = document.getElementById('eyeIcon');
    inp.type = inp.type === 'password' ? 'text' : 'password';
    ico.className = inp.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
}
</script>
</body>
</html>