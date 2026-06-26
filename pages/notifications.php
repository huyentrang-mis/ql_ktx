<?php
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$user = currentUser();
$db   = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'mark_read') {
        $nid = (int)$_POST['notif_id'];
        $db->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?")->execute([$nid, $user['id']]);
    }
    if ($action === 'mark_all_read') {
        $db->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$user['id']]);
    }
    header('Location: notifications.php'); exit;
}

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$total = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=?");
$total->execute([$user['id']]);
$totalCount = $total->fetchColumn();
$totalPages = ceil($totalCount / $perPage);

$stmt = $db->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute([$user['id']]);
$notifs = $stmt->fetchAll();

$unread = getUnreadCount($user['id']);
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="container-fluid px-0">

<div class="page-header">
    <div class="page-title">
        <i class="bi bi-bell-fill"></i> Thông Báo
        <?php if ($unread): ?><span class="badge-count" style="display:inline-flex;font-size:13px;padding:2px 8px;border-radius:10px;"><?= $unread ?></span><?php endif; ?>
    </div>
    <?php if ($unread): ?>
    <form method="POST">
        <input type="hidden" name="action" value="mark_all_read">
        <button type="submit" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-check-all me-1"></i>Đánh dấu tất cả đã đọc
        </button>
    </form>
    <?php endif; ?>
</div>

<div class="card"><div class="card-body p-0">
<?php if ($notifs): ?>
<?php foreach ($notifs as $notif): ?>
<div class="d-flex align-items-start gap-3 p-3 <?= !$notif['is_read']?'':'opacity-75' ?>"
     style="border-bottom:1px solid var(--border);<?= !$notif['is_read']?'background:#f0f4ff;':'' ?>">
    <?php $iconMap = ['success'=>'check-circle-fill text-success','warning'=>'exclamation-triangle-fill text-warning','danger'=>'x-circle-fill text-danger','info'=>'info-circle-fill text-primary']; ?>
    <i class="bi bi-<?= $iconMap[$notif['type']] ?? 'bell-fill text-secondary' ?>" style="font-size:1.4rem;margin-top:2px;flex-shrink:0;"></i>
    <div class="flex-grow-1">
        <div style="font-weight:<?= !$notif['is_read']?'600':'400' ?>;"><?= htmlspecialchars($notif['title']) ?></div>
        <div style="font-size:13px;color:var(--text-muted);"><?= htmlspecialchars($notif['message']) ?></div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:4px;"><?= formatDateTime($notif['created_at']) ?></div>
    </div>
    <?php if (!$notif['is_read']): ?>
    <form method="POST">
        <input type="hidden" name="action" value="mark_read">
        <input type="hidden" name="notif_id" value="<?= $notif['id'] ?>">
        <button type="submit" class="btn btn-xs btn-outline-secondary" style="font-size:11px;padding:2px 8px;white-space:nowrap;">
            Đã đọc
        </button>
    </form>
    <?php endif; ?>
</div>
<?php endforeach; ?>
<?php else: ?>
<div class="empty-state py-5">
    <i class="bi bi-bell-slash"></i>
    <p>Không có thông báo nào</p>
</div>
<?php endif; ?>
</div></div>

<?php if ($totalPages > 1): ?>
<nav class="mt-3"><ul class="pagination justify-content-center">
    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
    <li class="page-item <?= $p==$page?'active':'' ?>">
        <a class="page-link" href="?page=<?= $p ?>"><?= $p ?></a>
    </li>
    <?php endfor; ?>
</ul></nav>
<?php endif; ?>

</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
