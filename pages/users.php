<?php
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_user') {
        $username  = trim($_POST['username'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $full_name = trim($_POST['full_name'] ?? '');
        $phone     = trim($_POST['phone'] ?? '');
        $role      = $_POST['role'] ?? 'student';
        $password  = $_POST['password'] ?? '';

        if (!in_array($role, ['admin','staff','student'])) $role = 'student';

        if ($username && $email && $full_name && strlen($password) >= 6) {
            $check = $db->prepare("SELECT id FROM users WHERE username=? OR email=?");
            $check->execute([$username, $email]);
            if ($check->fetch()) {
                setFlash('danger', 'Tên đăng nhập hoặc email đã tồn tại.');
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $db->prepare("INSERT INTO users (username,email,password,full_name,phone,role,status) VALUES (?,?,?,?,?,'$role','active')")
                   ->execute([$username, $email, $hash, $full_name, $phone]);
                $uid = $db->lastInsertId();
                if ($role === 'student') {
                    $code = 'SV' . date('Y') . str_pad($uid, 4, '0', STR_PAD_LEFT);
                    $db->prepare("INSERT INTO student_profiles (user_id, student_code) VALUES (?,?)")->execute([$uid, $code]);
                }
                logActivity($_SESSION['user_id'], 'create_user', "Tạo tài khoản: $username ($role)");
                setFlash('success', "Tạo tài khoản '$username' thành công!");
            }
        } else {
            setFlash('danger', 'Vui lòng điền đầy đủ thông tin (mật khẩu tối thiểu 6 ký tự).');
        }
        header('Location: users.php'); exit;
    }

    if ($action === 'change_role') {
        $uid  = (int)$_POST['user_id'];
        $role = $_POST['role'] ?? '';
        if ($uid && in_array($role, ['admin','staff','student']) && $uid !== (int)$_SESSION['user_id']) {
            $db->prepare("UPDATE users SET role=? WHERE id=?")->execute([$role, $uid]);
            logActivity($_SESSION['user_id'], 'change_role', "Đổi quyền user $uid -> $role");
            setFlash('success', 'Đã cập nhật phân quyền.');
        }
        header('Location: users.php'); exit;
    }

    if ($action === 'reset_password') {
        $uid      = (int)$_POST['user_id'];
        $newPass  = $_POST['new_password'] ?? '';
        if ($uid && strlen($newPass) >= 6) {
            $hash = password_hash($newPass, PASSWORD_BCRYPT);
            $db->prepare("UPDATE users SET password=? WHERE id=?")->execute([$hash, $uid]);
            logActivity($_SESSION['user_id'], 'reset_password', "Reset mật khẩu user $uid");
            setFlash('success', 'Đã đặt lại mật khẩu.');
        }
        header('Location: users.php'); exit;
    }

    if ($action === 'toggle_status') {
        $uid = (int)$_POST['user_id'];
        $new = $_POST['new_status'] ?? '';
        if ($uid && in_array($new, ['active','inactive']) && $uid !== (int)$_SESSION['user_id']) {
            $db->prepare("UPDATE users SET status=? WHERE id=?")->execute([$new, $uid]);
            setFlash('info', 'Đã cập nhật trạng thái.');
        }
        header('Location: users.php'); exit;
    }
}

$roleFilter = $_GET['role'] ?? '';
$search     = trim($_GET['search'] ?? '');
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = 20;

$where = "WHERE 1=1"; $params = [];
if ($roleFilter && in_array($roleFilter, ['admin','staff','student'])) {
    $where .= " AND role=?"; $params[] = $roleFilter;
}
if ($search) {
    $where .= " AND (full_name LIKE ? OR email LIKE ? OR username LIKE ?)";
    $params = array_merge($params, array_fill(0, 3, "%$search%"));
}

$total = $db->prepare("SELECT COUNT(*) FROM users $where");
$total->execute($params);
$totalCount = $total->fetchColumn();
$totalPages = ceil($totalCount / $perPage);
$offset = ($page - 1) * $perPage;

$stmt = $db->prepare("SELECT * FROM users $where ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$users = $stmt->fetchAll();
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="container-fluid px-0">
<?php showFlash(); ?>

<div class="page-header">
    <div class="page-title"><i class="bi bi-person-badge-fill"></i> Quản Lý Tài Khoản & Phân Quyền</div>
    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#createUserModal">
        <i class="bi bi-person-plus-fill me-1"></i>Tạo Tài Khoản
    </button>
</div>

<!-- Role Summary -->
<?php
$roleCounts = $db->query("SELECT role, COUNT(*) as cnt FROM users WHERE status='active' GROUP BY role")->fetchAll();
$rcMap = array_column($roleCounts, 'cnt', 'role');
?>
<div class="row g-3 mb-4">
    <div class="col-sm-4"><div class="stat-card">
        <div class="stat-icon" style="background:#fee2e2;"><i class="bi bi-shield-fill-check" style="color:#dc2626;"></i></div>
        <div class="stat-info"><div class="stat-label">Admin</div><div class="stat-value"><?= $rcMap['admin'] ?? 0 ?></div></div>
    </div></div>
    <div class="col-sm-4"><div class="stat-card">
        <div class="stat-icon blue"><i class="bi bi-person-badge-fill"></i></div>
        <div class="stat-info"><div class="stat-label">Nhân Viên</div><div class="stat-value"><?= $rcMap['staff'] ?? 0 ?></div></div>
    </div></div>
    <div class="col-sm-4"><div class="stat-card">
        <div class="stat-icon green"><i class="bi bi-person-fill"></i></div>
        <div class="stat-info"><div class="stat-label">Sinh Viên</div><div class="stat-value"><?= $rcMap['student'] ?? 0 ?></div></div>
    </div></div>
</div>

<!-- Filter -->
<div class="card mb-3"><div class="card-body py-3">
    <form method="GET" class="d-flex gap-2 flex-wrap">
        <select name="role" class="form-select" style="max-width:160px;">
            <option value="">Tất cả quyền</option>
            <option value="admin" <?= $roleFilter==='admin'?'selected':'' ?>>Admin</option>
            <option value="staff" <?= $roleFilter==='staff'?'selected':'' ?>>Nhân viên</option>
            <option value="student" <?= $roleFilter==='student'?'selected':'' ?>>Sinh viên</option>
        </select>
        <input type="text" name="search" class="form-control" style="max-width:260px;"
            placeholder="Tên, email, username..." value="<?= htmlspecialchars($search) ?>">
        <button class="btn btn-primary"><i class="bi bi-search"></i></button>
        <a href="users.php" class="btn btn-outline-secondary">Reset</a>
    </form>
</div></div>

<div class="card"><div class="card-body p-0">
    <div class="table-responsive">
    <table class="table mb-0">
        <thead><tr>
            <th>Người Dùng</th><th>Email</th><th>Quyền</th><th>Trạng Thái</th><th>Đăng Nhập Cuối</th><th>Thao Tác</th>
        </tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
        <tr>
            <td>
                <div style="font-weight:600;"><?= htmlspecialchars($u['full_name']) ?></div>
                <div style="font-size:12px;color:var(--text-muted);">@<?= htmlspecialchars($u['username']) ?></div>
            </td>
            <td style="font-size:13px;"><?= htmlspecialchars($u['email']) ?></td>
            <td><?= statusBadge($u['role']) ?></td>
            <td><?= statusBadge($u['status']) ?></td>
            <td style="font-size:12px;color:var(--text-muted);"><?= $u['last_login'] ? formatDateTime($u['last_login']) : 'Chưa đăng nhập' ?></td>
            <td>
                <div class="d-flex gap-1 flex-wrap">
                    <?php if ($u['id'] !== (int)$_SESSION['user_id']): ?>
                    <!-- Change Role -->
                    <form method="POST" class="d-inline-flex gap-1">
                        <input type="hidden" name="action" value="change_role">
                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                        <select name="role" class="form-select form-select-sm" style="width:110px;">
                            <option value="admin" <?= $u['role']==='admin'?'selected':'' ?>>Admin</option>
                            <option value="staff" <?= $u['role']==='staff'?'selected':'' ?>>Nhân viên</option>
                            <option value="student" <?= $u['role']==='student'?'selected':'' ?>>Sinh viên</option>
                        </select>
                        <button class="btn btn-sm btn-outline-primary" title="Đổi quyền">
                            <i class="bi bi-shield-check"></i>
                        </button>
                    </form>
                    <!-- Toggle Status -->
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="action" value="toggle_status">
                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                        <input type="hidden" name="new_status" value="<?= $u['status']==='active'?'inactive':'active' ?>">
                        <button type="submit" class="btn btn-sm <?= $u['status']==='active'?'btn-outline-warning':'btn-outline-success' ?>"
                            onclick="return confirm('Đổi trạng thái user này?')" title="<?= $u['status']==='active'?'Khóa':'Mở' ?>">
                            <i class="bi bi-<?= $u['status']==='active'?'lock':'unlock' ?>-fill"></i>
                        </button>
                    </form>
                    <!-- Reset Password -->
                    <button class="btn btn-sm btn-outline-secondary" title="Đặt lại mật khẩu"
                        data-bs-toggle="modal" data-bs-target="#resetPwModal"
                        data-uid="<?= $u['id'] ?>" data-name="<?= htmlspecialchars($u['full_name']) ?>">
                        <i class="bi bi-key-fill"></i>
                    </button>
                    <?php else: ?>
                    <span class="badge bg-secondary">Tài khoản của bạn</span>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div></div>

<?php if ($totalPages > 1): ?>
<nav class="mt-3"><ul class="pagination justify-content-center">
    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
    <li class="page-item <?= $p==$page?'active':'' ?>">
        <a class="page-link" href="?page=<?= $p ?>&role=<?= urlencode($roleFilter) ?>&search=<?= urlencode($search) ?>"><?= $p ?></a>
    </li>
    <?php endfor; ?>
</ul></nav>
<?php endif; ?>
</div>

<!-- Create User Modal -->
<div class="modal fade" id="createUserModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title"><i class="bi bi-person-plus-fill me-2"></i>Tạo Tài Khoản Mới</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <form method="POST">
            <input type="hidden" name="action" value="create_user">
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-12"><label class="form-label">Họ Tên <span class="text-danger">*</span></label>
                        <input type="text" name="full_name" class="form-control" required></div>
                    <div class="col-6"><label class="form-label">Username <span class="text-danger">*</span></label>
                        <input type="text" name="username" class="form-control" required></div>
                    <div class="col-6"><label class="form-label">Phân Quyền</label>
                        <select name="role" class="form-select">
                            <option value="student">Sinh viên</option>
                            <option value="staff">Nhân viên</option>
                            <option value="admin">Admin</option>
                        </select></div>
                    <div class="col-12"><label class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control" required></div>
                    <div class="col-6"><label class="form-label">Số điện thoại</label>
                        <input type="tel" name="phone" class="form-control"></div>
                    <div class="col-6"><label class="form-label">Mật khẩu <span class="text-danger">*</span></label>
                        <input type="password" name="password" class="form-control" placeholder="≥ 6 ký tự" required></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                <button type="submit" class="btn btn-primary">Tạo Tài Khoản</button>
            </div>
        </form>
    </div></div>
</div>

<!-- Reset Password Modal -->
<div class="modal fade" id="resetPwModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title"><i class="bi bi-key-fill me-2"></i>Đặt Lại Mật Khẩu</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <form method="POST">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="user_id" id="resetPwUserId">
            <div class="modal-body">
                <p>Đặt lại mật khẩu cho: <strong id="resetPwName"></strong></p>
                <label class="form-label">Mật khẩu mới <span class="text-danger">*</span></label>
                <input type="password" name="new_password" class="form-control" placeholder="Tối thiểu 6 ký tự" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                <button type="submit" class="btn btn-primary">Đặt Lại</button>
            </div>
        </form>
    </div></div>
</div>

<script>
document.getElementById('resetPwModal').addEventListener('show.bs.modal', function(e) {
    const btn = e.relatedTarget;
    document.getElementById('resetPwUserId').value = btn.dataset.uid;
    document.getElementById('resetPwName').textContent = btn.dataset.name;
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
