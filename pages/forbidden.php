<?php
require_once __DIR__ . '/../includes/functions.php';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Không có quyền truy cập - <?= SITE_NAME ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body class="auth-body">
<div class="auth-card text-center">
    <div style="font-size:60px;color:#e53e3e;margin-bottom:16px;"><i class="bi bi-shield-x-fill"></i></div>
    <h2>Không Có Quyền Truy Cập</h2>
    <p style="color:var(--text-muted);">Bạn không có quyền xem trang này.</p>
    <a href="<?= BASE_URL ?>/pages/dashboard.php" class="btn btn-primary mt-2">
        <i class="bi bi-arrow-left me-2"></i>Về Dashboard
    </a>
</div>
</body>
</html>
