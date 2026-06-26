<?php
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');
$db = getDB();

// Handle backup
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'backup') {
        $result = performBackup('manual');
        if ($result['success']) {
            setFlash('success', 'Sao lưu thành công! File: <strong>' . $result['filename'] . '</strong> (' . number_format($result['size'] / 1024, 1) . ' KB)');
        } else {
            setFlash('danger', 'Sao lưu thất bại. Vui lòng thử lại.');
        }
        header('Location: backup.php');
        exit;
    }
    
    if ($action === 'delete') {
        $file = basename($_POST['filename'] ?? '');
        if ($file && preg_match('/^backup_ktx_[\d_]+\.sql$/', $file)) {
            $path = __DIR__ . '/../backup/' . $file;
            if (file_exists($path)) {
                unlink($path);
                $db->prepare("UPDATE backup_logs SET status='failed' WHERE filename=?")->execute([$file]);
                setFlash('warning', 'Đã xóa file backup: ' . $file);
            }
        }
        header('Location: backup.php');
        exit;
    }
    
    if ($action === 'download') {
        $file = basename($_POST['filename'] ?? '');
        if ($file && preg_match('/^backup_ktx_[\d_]+\.sql$/', $file)) {
            $path = __DIR__ . '/../backup/' . $file;
            if (file_exists($path)) {
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . $file . '"');
                header('Content-Length: ' . filesize($path));
                readfile($path);
                exit;
            }
        }
    }
}

// Get backup files
$backupDir = __DIR__ . '/../backup/';
$files = glob($backupDir . 'backup_ktx_*.sql') ?: [];
rsort($files);

// Get logs
$logs = $db->query("SELECT bl.*, u.full_name FROM backup_logs bl LEFT JOIN users u ON bl.created_by=u.id ORDER BY bl.created_at DESC LIMIT 20")->fetchAll();

// DB size
try {
    $dbSize = $db->query("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size FROM information_schema.tables WHERE table_schema = '" . DB_NAME . "'")->fetchColumn();
} catch(Exception $e) { $dbSize = 0; }
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="container-fluid px-0">
<?php showFlash(); ?>
<div class="page-header">
    <div class="page-title"><i class="bi bi-cloud-arrow-up-fill"></i> Sao Lưu Dữ Liệu</div>
    <form method="POST">
        <input type="hidden" name="action" value="backup">
        <button type="submit" class="btn btn-primary" onclick="startBackup(this)">
            <i class="bi bi-cloud-upload-fill me-2"></i>Sao Lưu Ngay
        </button>
    </form>
</div>

<!-- STATS -->
<div class="row g-4 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon blue"><i class="bi bi-database-fill"></i></div>
            <div class="stat-info">
                <div class="stat-label">Kích Thước DB</div>
                <div class="stat-value"><?= $dbSize ?> MB</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon green"><i class="bi bi-file-zip-fill"></i></div>
            <div class="stat-info">
                <div class="stat-label">Số File Backup</div>
                <div class="stat-value"><?= count($files) ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon orange"><i class="bi bi-hdd-fill"></i></div>
            <div class="stat-info">
                <div class="stat-label">Tổng Dung Lượng</div>
                <div class="stat-value"><?= round(array_sum(array_map('filesize', $files)) / 1024, 1) ?> KB</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon purple"><i class="bi bi-calendar-check-fill"></i></div>
            <div class="stat-info">
                <div class="stat-label">Backup Gần Nhất</div>
                <div class="stat-value" style="font-size:14px;"><?= $files ? date('d/m/Y H:i', filemtime($files[0])) : '—' ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Backup Files -->
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><i class="bi bi-folder-fill"></i> Danh Sách File Backup</div>
            <div class="card-body p-0">
                <?php if ($files): ?>
                <table class="table mb-0">
                    <thead><tr>
                        <th>Tên File</th>
                        <th>Kích Thước</th>
                        <th>Ngày Tạo</th>
                        <th>Thao Tác</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($files as $f): ?>
                    <?php $fname = basename($f); $fsize = filesize($f); $ftime = filemtime($f); ?>
                    <tr>
                        <td>
                            <i class="bi bi-file-earmark-code text-primary me-2"></i>
                            <span class="text-mono" style="font-size:12px;"><?= $fname ?></span>
                        </td>
                        <td><?= number_format($fsize / 1024, 1) ?> KB</td>
                        <td style="font-size:12px;"><?= date('d/m/Y H:i', $ftime) ?></td>
                        <td>
                            <div class="d-flex gap-1">
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="download">
                                    <input type="hidden" name="filename" value="<?= htmlspecialchars($fname) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-primary" title="Tải về">
                                        <i class="bi bi-download"></i>
                                    </button>
                                </form>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="filename" value="<?= htmlspecialchars($fname) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Xóa"
                                        data-confirm="Xóa file backup này?">
                                        <i class="bi bi-trash-fill"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="empty-state">
                    <i class="bi bi-folder-x"></i>
                    <p>Chưa có file backup nào. Nhấn "Sao Lưu Ngay" để tạo backup.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Backup Config & Info -->
    <div class="col-lg-4">
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-gear-fill"></i> Cấu Hình Backup</div>
            <div class="card-body">
                <div class="mb-3">
                    <div class="d-flex justify-content-between mb-1">
                        <span style="font-size:13px;">Tự động backup hàng ngày</span>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" checked disabled>
                        </div>
                    </div>
                    <div class="d-flex justify-content-between mb-1">
                        <span style="font-size:13px;">Thời điểm backup</span>
                        <span class="text-mono" style="font-size:13px;">02:00 AM</span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span style="font-size:13px;">Giữ lại tối đa</span>
                        <span class="fw-bold" style="font-size:13px;">30 file</span>
                    </div>
                </div>
                <hr>
                <div class="alert alert-info" style="font-size:12px;">
                    <i class="bi bi-info-circle-fill me-2"></i>
                    Backup tự động sẽ chạy hàng ngày lúc 2:00 AM và xóa các file cũ hơn 30 ngày.
                </div>
                
                <div class="mb-2" style="font-size:13px;font-weight:600;">Bảng dữ liệu được backup:</div>
                <?php
                $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($tables as $t): ?>
                <div class="d-flex align-items-center gap-2 mb-1" style="font-size:12px;">
                    <i class="bi bi-check-circle-fill text-success"></i>
                    <span class="text-mono"><?= $t ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><i class="bi bi-journal-check"></i> Lịch Sử Backup</div>
            <div class="card-body p-0">
                <div style="max-height:300px;overflow-y:auto;">
                <?php foreach ($logs as $log): ?>
                <div style="padding:10px 16px;border-bottom:1px solid var(--border);font-size:12px;">
                    <div class="d-flex justify-content-between">
                        <span class="text-mono"><?= $log['filename'] ?></span>
                        <?= $log['status']==='success' ? '<span class="badge bg-success">OK</span>' : '<span class="badge bg-danger">Lỗi</span>' ?>
                    </div>
                    <div class="text-muted mt-1">
                        <?= $log['full_name'] ?? 'Hệ thống' ?> — <?= formatDateTime($log['created_at']) ?>
                        | <?= number_format($log['file_size'] / 1024, 1) ?> KB
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (!$logs): ?>
                <div class="empty-state" style="padding:24px;"><i class="bi bi-journal"></i><p>Chưa có lịch sử backup</p></div>
                <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>