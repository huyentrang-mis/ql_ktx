<?php
require_once __DIR__ . '/../includes/functions.php';
if (isLoggedIn()) { header('Location: ' . BASE_URL . '/pages/dashboard.php'); exit; }

$step = $_GET['step'] ?? 'request';
$error = $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = getDB();

    if ($step === 'request') {
        $email = trim($_POST['email'] ?? '');
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Email không hợp lệ.';
        } else {
            $stmt = $db->prepare("SELECT * FROM users WHERE email=? AND status='active'");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            if ($user) {
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
                $db->prepare("UPDATE users SET reset_token=?, reset_token_expires=? WHERE id=?")->execute([$token, $expires, $user['id']]);
                // In production, send email. Here we simulate:
                $success = 'Link đặt lại mật khẩu đã được gửi đến email của bạn. <br><small class="text-muted">(Demo: <a href="?step=reset&token='.$token.'">Click tại đây để đặt lại</a>)</small>';
                logActivity($user['id'], 'forgot_password', 'Yêu cầu đặt lại mật khẩu');
            } else {
                $success = 'Nếu email tồn tại, chúng tôi đã gửi link đặt lại mật khẩu.';
            }
        }
    } elseif ($step === 'reset') {
        $token = $_POST['token'] ?? '';
        $newPass = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (strlen($newPass) < 8) { $error = 'Mật khẩu phải có ít nhất 8 ký tự.'; }
        elseif ($newPass !== $confirm) { $error = 'Mật khẩu xác nhận không khớp.'; }
        else {
            $stmt = $db->prepare("SELECT * FROM users WHERE reset_token=? AND reset_token_expires > NOW()");
            $stmt->execute([$token]);
            $user = $stmt->fetch();
            if ($user) {
                $hash = password_hash($newPass, PASSWORD_BCRYPT);
                $db->prepare("UPDATE users SET password=?, reset_token=NULL, reset_token_expires=NULL WHERE id=?")->execute([$hash, $user['id']]);
                logActivity($user['id'], 'password_reset', 'Đặt lại mật khẩu thành công');
                header('Location: ' . BASE_URL . '/pages/login.php?reset=1');
                exit;
            } else {
                $error = 'Link đặt lại mật khẩu không hợp lệ hoặc đã hết hạn.';
            }
        }
    }
}

$tokenValid = false;
if ($step === 'reset') {
    $token = $_GET['token'] ?? $_POST['token'] ?? '';
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM users WHERE reset_token=? AND reset_token_expires > NOW()");
    $stmt->execute([$token]);
    $tokenValid = (bool)$stmt->fetch();
    if (!$tokenValid && empty($error)) $error = 'Link không hợp lệ hoặc đã hết hạn.';
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Quên Mật Khẩu - <?= SITE_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body class="auth-body">
<div class="auth-card">
    <div class="auth-logo">
        <div class="logo-icon"><i class="bi bi-key-fill"></i></div>
        <h1><?= $step === 'reset' ? 'Đặt Lại Mật Khẩu' : 'Quên Mật Khẩu' ?></h1>
        <p><?= $step === 'reset' ? 'Nhập mật khẩu mới của bạn' : 'Nhập email để nhận link đặt lại' ?></p>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= $error ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
    <div class="alert alert-success"><i class="bi bi-check-circle-fill me-2"></i><?= $success ?></div>
    <?php endif; ?>

    <?php if ($step === 'request' && !$success): ?>
    <form method="POST">
        <div class="mb-4">
            <label class="form-label">Địa chỉ Email <span class="text-danger">*</span></label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-envelope-fill"></i></span>
                <input type="email" name="email" class="form-control" placeholder="email@domain.com" required autofocus>
            </div>
        </div>
        <button type="submit" class="btn btn-primary w-100 py-2">
            <i class="bi bi-send-fill me-2"></i>Gửi Link Đặt Lại
        </button>
    </form>

    <?php elseif ($step === 'reset' && $tokenValid): ?>
    <form method="POST">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <div class="mb-3">
            <label class="form-label">Mật khẩu mới <span class="text-danger">*</span></label>
            <input type="password" name="new_password" class="form-control" placeholder="Tối thiểu 8 ký tự" required>
        </div>
        <div class="mb-4">
            <label class="form-label">Xác nhận mật khẩu <span class="text-danger">*</span></label>
            <input type="password" name="confirm_password" class="form-control" placeholder="Nhập lại mật khẩu" required>
        </div>
        <button type="submit" class="btn btn-success w-100 py-2">
            <i class="bi bi-shield-check-fill me-2"></i>Đặt Lại Mật Khẩu
        </button>
    </form>
    <?php endif; ?>

    <div class="text-center mt-4" style="font-size:13px;">
        <a href="<?= BASE_URL ?>/pages/login.php" style="color:var(--primary-light);text-decoration:none;">
            <i class="bi bi-arrow-left me-1"></i>Quay lại đăng nhập
        </a>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>