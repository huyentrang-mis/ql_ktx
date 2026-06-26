<?php
/**
 * room_requests.php
 * Admin / Staff duyệt yêu cầu đăng ký / đổi / huỷ phòng từ sinh viên
 */
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin', 'staff');

$user = currentUser();
$db   = getDB();

// ── Xử lý duyệt / từ chối ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = $_POST['action']     ?? '';
    $reqId     = (int)($_POST['req_id'] ?? 0);
    $reviewNote = trim($_POST['review_note'] ?? '');

    if (!in_array($action, ['approve', 'reject']) || !$reqId) {
        setFlash('danger', 'Thao tác không hợp lệ.');
        header('Location: room_requests.php'); exit;
    }

    // Lấy yêu cầu
    $reqStmt = $db->prepare(
        "SELECT rr.*, u.full_name as student_name, u.email as student_email,
                fr.room_number as from_room_number, fb.name as from_building_name,
                tr.room_number as to_room_number,   tb.name as to_building_name,
                tr.capacity as to_capacity, tr.status as to_status,
                (SELECT COUNT(*) FROM student_profiles sp WHERE sp.room_id = rr.to_room_id) as to_occupied
         FROM   room_requests rr
         JOIN   users u ON rr.student_id = u.id
         LEFT JOIN rooms    fr ON rr.from_room_id = fr.id
         LEFT JOIN buildings fb ON fr.building_id  = fb.id
         LEFT JOIN rooms    tr ON rr.to_room_id   = tr.id
         LEFT JOIN buildings tb ON tr.building_id  = tb.id
         WHERE  rr.id = ? AND rr.status = 'pending'"
    );
    $reqStmt->execute([$reqId]);
    $req = $reqStmt->fetch();

    if (!$req) {
        setFlash('warning', 'Yêu cầu không tồn tại hoặc đã được xử lý.');
        header('Location: room_requests.php'); exit;
    }

    if ($action === 'approve') {
        // ── DUYỆT ──────────────────────────────────────────────────────────
        $db->beginTransaction();
        try {
            if ($req['request_type'] === 'register' || $req['request_type'] === 'change') {
                // Kiểm tra phòng mới còn chỗ không
                if ($req['to_status'] !== 'available' && $req['to_occupied'] >= $req['to_capacity']) {
                    $db->rollBack();
                    setFlash('danger', 'Phòng muốn đăng ký đã đầy hoặc đang bảo trì. Không thể duyệt.');
                    header('Location: room_requests.php'); exit;
                }

                // Nếu đổi phòng: giải phóng phòng cũ
                if ($req['request_type'] === 'change' && $req['from_room_id']) {
                    $db->prepare("UPDATE student_profiles SET room_id = NULL WHERE user_id = ?")
                       ->execute([$req['student_id']]);
                    // Cập nhật trạng thái phòng cũ
                    _updateRoomStatus($db, $req['from_room_id']);
                }

                // Gán phòng mới
                $db->prepare("UPDATE student_profiles SET room_id = ?, move_in_date = CURDATE() WHERE user_id = ?")
                   ->execute([$req['to_room_id'], $req['student_id']]);

                // Cập nhật trạng thái phòng mới
                _updateRoomStatus($db, $req['to_room_id']);

                $msg = "Yêu cầu {$req['request_type']} phòng của bạn đã được <strong>duyệt</strong>! "
                     . "Bạn đã được xếp vào phòng {$req['to_room_number']} ({$req['to_building_name']}).";
                $notifType = 'success';

            } elseif ($req['request_type'] === 'cancel') {
                // Huỷ phòng: xoá room_id khỏi profile
                $db->prepare("UPDATE student_profiles SET room_id = NULL, move_out_date = CURDATE() WHERE user_id = ?")
                   ->execute([$req['student_id']]);

                // Cập nhật trạng thái phòng cũ
                if ($req['from_room_id']) {
                    _updateRoomStatus($db, $req['from_room_id']);
                }

                $msg = "Yêu cầu huỷ phòng của bạn đã được xác nhận. "
                     . "Bạn đã rời phòng {$req['from_room_number']} ({$req['from_building_name']}).";
                $notifType = 'info';
            }

            // Cập nhật trạng thái yêu cầu
            $db->prepare(
                "UPDATE room_requests SET status='approved', reviewed_by=?, review_note=?, reviewed_at=NOW()
                 WHERE id=?"
            )->execute([$user['id'], $reviewNote ?: 'Đã duyệt', $reqId]);

            $db->commit();

            // Thông báo cho sinh viên
            sendNotification($req['student_id'], '✅ Yêu cầu phòng được duyệt', $msg, $notifType, $reqId, 'room_request');
            logActivity($user['id'], 'approve_room_request', "Duyệt yêu cầu #$reqId ({$req['request_type']}) của {$req['student_name']}");
            setFlash('success', "Đã duyệt yêu cầu của sinh viên <strong>{$req['student_name']}</strong>.");

        } catch (\Exception $e) {
            $db->rollBack();
            setFlash('danger', 'Lỗi khi xử lý: ' . $e->getMessage());
        }

    } elseif ($action === 'reject') {
        // ── TỪ CHỐI ────────────────────────────────────────────────────────
        $db->prepare(
            "UPDATE room_requests SET status='rejected', reviewed_by=?, review_note=?, reviewed_at=NOW()
             WHERE id=?"
        )->execute([$user['id'], $reviewNote ?: 'Yêu cầu bị từ chối', $reqId]);

        sendNotification(
            $req['student_id'],
            '❌ Yêu cầu phòng bị từ chối',
            "Yêu cầu {$req['request_type']} phòng của bạn đã bị từ chối." . ($reviewNote ? " Lý do: $reviewNote" : ''),
            'danger', $reqId, 'room_request'
        );
        logActivity($user['id'], 'reject_room_request', "Từ chối yêu cầu #$reqId của {$req['student_name']}");
        setFlash('warning', "Đã từ chối yêu cầu của sinh viên <strong>{$req['student_name']}</strong>.");
    }

    header('Location: room_requests.php'); exit;
}

// ── Helper: Cập nhật trạng thái phòng ────────────────────────────────────────
function _updateRoomStatus($db, $roomId) {
    $row = $db->prepare(
        "SELECT r.capacity,
                (SELECT COUNT(*) FROM student_profiles sp WHERE sp.room_id = r.id) as occupied
         FROM rooms r WHERE r.id = ?"
    );
    $row->execute([$roomId]);
    $r = $row->fetch();
    if ($r) {
        $newStatus = ($r['occupied'] >= $r['capacity']) ? 'full' : 'available';
        $db->prepare("UPDATE rooms SET status=? WHERE id=?")->execute([$newStatus, $roomId]);
    }
}

// ── Query danh sách yêu cầu ──────────────────────────────────────────────────
$statusFilter = $_GET['status'] ?? 'pending';
$typeFilter   = $_GET['type']   ?? '';
$search       = trim($_GET['search'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 20;

$where  = "WHERE 1=1";
$params = [];

if ($statusFilter) { $where .= " AND rr.status=?";       $params[] = $statusFilter; }
if ($typeFilter)   { $where .= " AND rr.request_type=?"; $params[] = $typeFilter; }
if ($search) {
    $where .= " AND (u.full_name LIKE ? OR u.email LIKE ? OR sp.student_code LIKE ?)";
    $params = array_merge($params, array_fill(0, 3, "%$search%"));
}

$totalStmt = $db->prepare(
    "SELECT COUNT(*) FROM room_requests rr
     JOIN users u ON rr.student_id = u.id
     LEFT JOIN student_profiles sp ON u.id = sp.user_id
     $where"
);
$totalStmt->execute($params);
$totalCount = $totalStmt->fetchColumn();
$totalPages = ceil($totalCount / $perPage);
$offset     = ($page - 1) * $perPage;

$listStmt = $db->prepare(
    "SELECT rr.*,
            u.full_name, u.email, u.phone, sp.student_code,
            fr.room_number as from_room_number, fb.name as from_building,
            tr.room_number as to_room_number,   tb.name as to_building,
            tr.type as to_room_type, tr.price_per_month,
            tr.capacity as to_capacity,
            (SELECT COUNT(*) FROM student_profiles sp2 WHERE sp2.room_id = rr.to_room_id) as to_occupied,
            rv.full_name as reviewer_name
     FROM   room_requests rr
     JOIN   users u  ON rr.student_id  = u.id
     LEFT JOIN student_profiles sp ON u.id = sp.user_id
     LEFT JOIN rooms    fr ON rr.from_room_id = fr.id
     LEFT JOIN buildings fb ON fr.building_id  = fb.id
     LEFT JOIN rooms    tr ON rr.to_room_id   = tr.id
     LEFT JOIN buildings tb ON tr.building_id  = tb.id
     LEFT JOIN users    rv ON rr.reviewed_by  = rv.id
     $where
     ORDER  BY FIELD(rr.status,'pending','approved','rejected'), rr.created_at DESC
     LIMIT $perPage OFFSET $offset"
);
$listStmt->execute($params);
$requests = $listStmt->fetchAll();

// Counts cho badge
$countPending  = $db->query("SELECT COUNT(*) FROM room_requests WHERE status='pending'")->fetchColumn();
$countApproved = $db->query("SELECT COUNT(*) FROM room_requests WHERE status='approved'")->fetchColumn();
$countRejected = $db->query("SELECT COUNT(*) FROM room_requests WHERE status='rejected'")->fetchColumn();
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="container-fluid px-0">
<?php showFlash(); ?>

<div class="page-header">
    <div class="page-title"><i class="bi bi-clipboard2-check-fill"></i> Duyệt Yêu Cầu Phòng</div>
    <span style="font-size:13px;color:var(--text-muted);"><?= number_format($totalCount) ?> yêu cầu</span>
</div>

<!-- Stat tabs -->
<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <a href="?status=pending" class="text-decoration-none">
        <div class="stat-card <?= $statusFilter==='pending'?'border-warning':'' ?>" style="<?= $statusFilter==='pending'?'border:2px solid #f59e0b;':'' ?>">
            <div class="stat-icon orange"><i class="bi bi-hourglass-split"></i></div>
            <div class="stat-info">
                <div class="stat-label">Chờ Duyệt</div>
                <div class="stat-value"><?= $countPending ?></div>
            </div>
        </div></a>
    </div>
    <div class="col-sm-4">
        <a href="?status=approved" class="text-decoration-none">
        <div class="stat-card <?= $statusFilter==='approved'?'border-success':'' ?>" style="<?= $statusFilter==='approved'?'border:2px solid #22c55e;':'' ?>">
            <div class="stat-icon green"><i class="bi bi-check-circle-fill"></i></div>
            <div class="stat-info">
                <div class="stat-label">Đã Duyệt</div>
                <div class="stat-value"><?= $countApproved ?></div>
            </div>
        </div></a>
    </div>
    <div class="col-sm-4">
        <a href="?status=rejected" class="text-decoration-none">
        <div class="stat-card <?= $statusFilter==='rejected'?'border-danger':'' ?>" style="<?= $statusFilter==='rejected'?'border:2px solid #ef4444;':'' ?>">
            <div class="stat-icon" style="background:#fee2e2;color:#dc2626;"><i class="bi bi-x-circle-fill"></i></div>
            <div class="stat-info">
                <div class="stat-label">Từ Chối</div>
                <div class="stat-value"><?= $countRejected ?></div>
            </div>
        </div></a>
    </div>
</div>

<!-- Filter bar -->
<div class="card mb-4"><div class="card-body py-3">
    <form method="GET" class="d-flex gap-2 flex-wrap align-items-end">
        <div>
            <label class="form-label mb-1" style="font-size:12px;">Trạng thái</label>
            <select name="status" class="form-select form-select-sm" style="width:150px;">
                <option value="">Tất cả</option>
                <option value="pending"  <?= $statusFilter==='pending'  ?'selected':'' ?>>Chờ duyệt</option>
                <option value="approved" <?= $statusFilter==='approved' ?'selected':'' ?>>Đã duyệt</option>
                <option value="rejected" <?= $statusFilter==='rejected' ?'selected':'' ?>>Từ chối</option>
            </select>
        </div>
        <div>
            <label class="form-label mb-1" style="font-size:12px;">Loại yêu cầu</label>
            <select name="type" class="form-select form-select-sm" style="width:160px;">
                <option value="">Tất cả loại</option>
                <option value="register" <?= $typeFilter==='register'?'selected':'' ?>>Đăng ký phòng</option>
                <option value="change"   <?= $typeFilter==='change'  ?'selected':'' ?>>Đổi phòng</option>
                <option value="cancel"   <?= $typeFilter==='cancel'  ?'selected':'' ?>>Huỷ phòng</option>
            </select>
        </div>
        <div>
            <label class="form-label mb-1" style="font-size:12px;">Tìm kiếm</label>
            <input type="text" name="search" class="form-control form-control-sm" style="width:220px;"
                placeholder="Tên, email, mã SV..." value="<?= htmlspecialchars($search) ?>">
        </div>
        <div style="padding-top:20px;">
            <button class="btn btn-sm btn-primary"><i class="bi bi-search me-1"></i>Lọc</button>
            <a href="room_requests.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
        </div>
    </form>
</div></div>

<!-- Danh sách yêu cầu -->
<?php if ($requests): ?>
<div class="row g-3">
<?php foreach ($requests as $req): ?>
<?php
    $isPending  = $req['status'] === 'pending';
    $isApproved = $req['status'] === 'approved';
    $typeColors = ['register'=>'var(--primary)','change'=>'#f59e0b','cancel'=>'#ef4444'];
    $borderColor = $isPending ? '#f59e0b' : ($isApproved ? '#22c55e' : '#ef4444');
?>
<div class="col-lg-6">
<div class="card" style="border-left:4px solid <?= $borderColor ?>;">
    <div class="card-body">
        <!-- Header row -->
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
                <div class="d-flex align-items-center gap-2 mb-1">
                    <?= statusBadge($req['request_type']) ?>
                    <?= statusBadge($req['status']) ?>
                </div>
                <div style="font-weight:700;font-size:1rem;"><?= htmlspecialchars($req['full_name']) ?></div>
                <div style="font-size:12px;color:var(--text-muted);">
                    <?= htmlspecialchars($req['email']) ?>
                    <?php if ($req['student_code']): ?> · <span class="text-mono"><?= $req['student_code'] ?></span><?php endif; ?>
                    <?php if ($req['phone']): ?> · <?= $req['phone'] ?><?php endif; ?>
                </div>
            </div>
            <div style="font-size:12px;color:var(--text-muted);text-align:right;">
                #<?= $req['id'] ?><br>
                <?= date('d/m/Y H:i', strtotime($req['created_at'])) ?>
            </div>
        </div>

        <!-- Room info -->
        <div class="row g-2 mb-3">
            <?php if ($req['from_room_number']): ?>
            <div class="col-<?= $req['to_room_number'] ? '6' : '12' ?>">
                <div class="p-2 rounded" style="background:#fff5f5;border:1px solid #fecaca;">
                    <div style="font-size:10px;color:#dc2626;text-transform:uppercase;font-weight:600;letter-spacing:.6px;">Phòng Hiện Tại</div>
                    <div style="font-weight:700;font-size:15px;color:#dc2626;">
                        <?= htmlspecialchars($req['from_building']) ?> – <?= $req['from_room_number'] ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($req['to_room_number']): ?>
            <?php $toFull = $req['to_occupied'] >= $req['to_capacity']; ?>
            <div class="col-<?= $req['from_room_number'] ? '6' : '12' ?>">
                <div class="p-2 rounded" style="background:#f0fdf4;border:1px solid #bbf7d0;<?= $toFull?'border-color:#fca5a5;background:#fff5f5;':'' ?>">
                    <div style="font-size:10px;color:#16a34a;text-transform:uppercase;font-weight:600;letter-spacing:.6px;">
                        Phòng Muốn Đăng Ký <?= $toFull ? '⚠️ ĐÃ ĐẦY' : '' ?>
                    </div>
                    <div style="font-weight:700;font-size:15px;color:#16a34a;">
                        <?= htmlspecialchars($req['to_building']) ?> – <?= $req['to_room_number'] ?>
                    </div>
                    <div style="font-size:12px;color:var(--text-muted);">
                        <?= statusBadge($req['to_room_type'] ?? 'standard') ?>
                        · <?= $req['to_occupied'] ?>/<?= $req['to_capacity'] ?> người
                        · <?= formatMoney($req['price_per_month'] ?? 0) ?>/tháng
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Lý do -->
        <?php if ($req['reason']): ?>
        <div class="mb-3 p-2 rounded" style="background:#f8f9fd;border:1px solid var(--border);font-size:13px;">
            <span style="color:var(--text-muted);">Lý do:</span>
            <?= htmlspecialchars($req['reason']) ?>
        </div>
        <?php endif; ?>

        <!-- Review info (nếu đã xử lý) -->
        <?php if (!$isPending): ?>
        <div class="mb-3 p-2 rounded" style="background:<?= $isApproved?'#f0fdf4':'#fff5f5' ?>;border:1px solid <?= $isApproved?'#bbf7d0':'#fecaca' ?>;font-size:13px;">
            <div style="font-weight:600;color:<?= $isApproved?'#16a34a':'#dc2626' ?>;margin-bottom:2px;">
                <?= $isApproved ? '✅ Đã duyệt' : '❌ Đã từ chối' ?>
                <?php if ($req['reviewer_name']): ?>· bởi <?= htmlspecialchars($req['reviewer_name']) ?><?php endif; ?>
                <?php if ($req['reviewed_at']): ?>· <?= date('d/m/Y H:i', strtotime($req['reviewed_at'])) ?><?php endif; ?>
            </div>
            <?php if ($req['review_note']): ?>
            <div style="color:var(--text-muted);"><?= htmlspecialchars($req['review_note']) ?></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Action buttons (chỉ khi pending) -->
        <?php if ($isPending): ?>
        <div class="d-flex gap-2">
            <button class="btn btn-success flex-fill btn-action-approve"
                data-req-id="<?= $req['id'] ?>"
                data-student-name="<?= htmlspecialchars($req['full_name']) ?>"
                data-type="<?= $req['request_type'] ?>"
                data-to-room="<?= $req['to_room_number'] ? htmlspecialchars($req['to_building'].' – '.$req['to_room_number']) : '' ?>"
                data-from-room="<?= $req['from_room_number'] ? htmlspecialchars($req['from_building'].' – '.$req['from_room_number']) : '' ?>"
                data-bs-toggle="modal" data-bs-target="#reviewModal">
                <i class="bi bi-check-circle-fill me-1"></i>Duyệt
            </button>
            <button class="btn btn-outline-danger flex-fill btn-action-reject"
                data-req-id="<?= $req['id'] ?>"
                data-student-name="<?= htmlspecialchars($req['full_name']) ?>"
                data-type="<?= $req['request_type'] ?>"
                data-bs-toggle="modal" data-bs-target="#rejectModal">
                <i class="bi bi-x-circle-fill me-1"></i>Từ Chối
            </button>
        </div>
        <?php endif; ?>
    </div>
</div>
</div>
<?php endforeach; ?>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<nav class="mt-4"><ul class="pagination justify-content-center">
    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
    <li class="page-item <?= $p==$page?'active':'' ?>">
        <a class="page-link" href="?page=<?= $p ?>&status=<?= urlencode($statusFilter) ?>&type=<?= urlencode($typeFilter) ?>&search=<?= urlencode($search) ?>"><?= $p ?></a>
    </li>
    <?php endfor; ?>
</ul></nav>
<?php endif; ?>

<?php else: ?>
<div class="card"><div class="empty-state py-5">
    <i class="bi bi-clipboard2-x"></i>
    <p>Không có yêu cầu nào<?= $statusFilter === 'pending' ? ' đang chờ duyệt' : '' ?></p>
    <?php if ($statusFilter || $typeFilter || $search): ?>
    <a href="room_requests.php" class="btn btn-sm btn-outline-primary mt-2">Xóa bộ lọc</a>
    <?php endif; ?>
</div></div>
<?php endif; ?>

</div><!-- /container -->

<!-- ══ MODAL: Duyệt ══════════════════════════════════════════════════════════ -->
<div class="modal fade" id="reviewModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background:#16a34a;color:#fff;">
                <h5 class="modal-title"><i class="bi bi-check-circle-fill me-2"></i>Xác Nhận Duyệt Yêu Cầu</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="req_id" id="approveReqId">
                <div class="modal-body">
                    <div class="p-3 rounded mb-3" style="background:#f0fdf4;border:1px solid #bbf7d0;">
                        <div id="approveSummary" style="font-size:14px;"></div>
                    </div>
                    <div class="alert alert-info mb-3" style="font-size:13px;">
                        <i class="bi bi-info-circle-fill me-2"></i>
                        Sau khi duyệt, hệ thống sẽ <strong>tự động cập nhật phòng</strong> cho sinh viên
                        và gửi thông báo.
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Ghi chú (tuỳ chọn)</label>
                        <textarea name="review_note" class="form-control" rows="2"
                            placeholder="Nhắc nhở, hướng dẫn thêm cho sinh viên..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-circle-fill me-1"></i>Xác Nhận Duyệt
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ══ MODAL: Từ chối ════════════════════════════════════════════════════════ -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background:#dc2626;color:#fff;">
                <h5 class="modal-title"><i class="bi bi-x-circle-fill me-2"></i>Từ Chối Yêu Cầu</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="req_id" id="rejectReqId">
                <div class="modal-body">
                    <div class="p-3 rounded mb-3" style="background:#fff5f5;border:1px solid #fecaca;">
                        <div id="rejectSummary" style="font-size:14px;"></div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Lý Do Từ Chối <span class="text-danger">*</span></label>
                        <textarea name="review_note" class="form-control" rows="3"
                            placeholder="Giải thích rõ lý do để sinh viên hiểu..." required></textarea>
                        <div class="form-text">Sinh viên sẽ nhận được lý do này qua thông báo.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-x-circle-fill me-1"></i>Xác Nhận Từ Chối
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Populate approve modal
document.querySelectorAll('.btn-action-approve').forEach(btn => {
    btn.addEventListener('click', function() {
        document.getElementById('approveReqId').value = this.dataset.reqId;
        const typeMap = { register: 'Đăng ký phòng', change: 'Đổi phòng', cancel: 'Huỷ phòng' };
        let html = `<div><strong>${this.dataset.studentName}</strong></div>
                    <div style="color:var(--text-muted);font-size:13px;">Loại: <strong>${typeMap[this.dataset.type] || this.dataset.type}</strong></div>`;
        if (this.dataset.fromRoom) html += `<div style="font-size:13px;">Từ phòng: <strong>${this.dataset.fromRoom}</strong></div>`;
        if (this.dataset.toRoom)   html += `<div style="font-size:13px;">Đến phòng: <strong style="color:#16a34a;">${this.dataset.toRoom}</strong></div>`;
        document.getElementById('approveSummary').innerHTML = html;
    });
});

// Populate reject modal
document.querySelectorAll('.btn-action-reject').forEach(btn => {
    btn.addEventListener('click', function() {
        document.getElementById('rejectReqId').value = this.dataset.reqId;
        const typeMap = { register: 'Đăng ký phòng', change: 'Đổi phòng', cancel: 'Huỷ phòng' };
        document.getElementById('rejectSummary').innerHTML =
            `<div><strong>${this.dataset.studentName}</strong></div>
             <div style="color:#dc2626;font-size:13px;">Loại: <strong>${typeMap[this.dataset.type] || this.dataset.type}</strong></div>`;
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
