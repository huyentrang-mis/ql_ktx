<?php
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');
$db = getDB();

$search = trim($_GET['search'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;

$where = "WHERE 1=1"; $params = [];
if ($search) {
    $where .= " AND (al.action LIKE ? OR al.description LIKE ? OR u.full_name LIKE ?)";
    $params = array_fill(0, 3, "%$search%");
}

$total = $db->prepare("SELECT COUNT(*) FROM activity_logs al LEFT JOIN users u ON al.user_id=u.id $where");
$total->execute($params);
$totalCount = $total->fetchColumn();
$totalPages = ceil($totalCount / $perPage);
$offset = ($page - 1) * $perPage;

$stmt = $db->prepare("SELECT al.*, u.full_name, u.role FROM activity_logs al LEFT JOIN users u ON al.user_id=u.id $where ORDER BY al.created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$logs = $stmt->fetchAll();
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="container-fluid px-0">

<div class="page-header">
    <div class="page-title"><i class="bi bi-journal-text"></i> Nhật Ký Hoạt Động</div>
    <span style="font-size:13px;color:var(--text-muted);"><?= number_format($totalCount) ?> bản ghi</span>
</div>

<div class="card mb-3"><div class="card-body py-3">
    <form method="GET" class="d-flex gap-2">
        <input type="text" name="search" class="form-control" style="max-width:300px;"
            placeholder="Tìm hành động, mô tả, người dùng..." value="<?= htmlspecialchars($search) ?>">
        <button class="btn btn-primary"><i class="bi bi-search me-1"></i>Tìm</button>
        <a href="logs.php" class="btn btn-outline-secondary">Reset</a>
    </form>
</div></div>

<div class="card"><div class="card-body p-0">
    <div class="table-responsive">
    <table class="table mb-0">
        <thead><tr>
            <th>Thời Gian</th><th>Người Dùng</th><th>Hành Động</th><th>Mô Tả</th><th>IP</th>
        </tr></thead>
        <tbody>
        <?php foreach ($logs as $log): ?>
        <tr>
            <td style="font-size:12px;color:var(--text-muted);white-space:nowrap;"><?= formatDateTime($log['created_at']) ?></td>
            <td>
                <?php if ($log['full_name']): ?>
                <div style="font-size:13px;font-weight:500;"><?= htmlspecialchars($log['full_name']) ?></div>
                <?php if ($log['role']): ?><div><?= statusBadge($log['role']) ?></div><?php endif; ?>
                <?php else: ?>
                <span style="color:var(--text-muted);font-size:12px;">Hệ thống</span>
                <?php endif; ?>
            </td>
            <td><code style="font-size:12px;background:#f0f4ff;padding:2px 6px;border-radius:4px;"><?= htmlspecialchars($log['action']) ?></code></td>
            <td style="font-size:13px;"><?= htmlspecialchars($log['description'] ?? '') ?></td>
            <td style="font-size:12px;color:var(--text-muted);"><?= htmlspecialchars($log['ip_address'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$logs): ?>
        <tr><td colspan="5" class="text-center py-4 text-muted">Không có dữ liệu</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div></div>

<?php if ($totalPages > 1): ?>
<nav class="mt-3"><ul class="pagination justify-content-center">
    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
    <li class="page-item <?= $p==$page?'active':'' ?>">
        <a class="page-link" href="?page=<?= $p ?>&search=<?= urlencode($search) ?>"><?= $p ?></a>
    </li>
    <?php endfor; ?>
</ul></nav>
<?php endif; ?>

</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
