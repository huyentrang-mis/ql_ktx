<?php
/**
 * room_register.php
 * Sinh viên xem danh sách phòng và gửi yêu cầu đăng ký / đổi / huỷ phòng
 */
require_once __DIR__ . '/../includes/functions.php';
requireRole('student');

$user = currentUser();
$db   = getDB();

// ── Lấy thông tin phòng hiện tại của sinh viên ──────────────────────────────
$spStmt = $db->prepare(
    "SELECT sp.*, r.room_number, r.type as room_type, r.capacity, r.price_per_month,
            b.name as building_name, b.address,
            (SELECT COUNT(*) FROM student_profiles sp2 WHERE sp2.room_id = r.id) as occupied
     FROM   student_profiles sp
     LEFT JOIN rooms r ON sp.room_id = r.id
     LEFT JOIN buildings b ON r.building_id = b.id
     WHERE  sp.user_id = ?"
);
$spStmt->execute([$user['id']]);
$myProfile = $spStmt->fetch();

// ── Kiểm tra yêu cầu đang chờ xử lý ────────────────────────────────────────
$pendingStmt = $db->prepare(
    "SELECT rr.*, 
            fr.room_number as from_room_number, fb.name as from_building,
            tr.room_number as to_room_number,   tb.name as to_building
     FROM   room_requests rr
     LEFT JOIN rooms    fr ON rr.from_room_id = fr.id
     LEFT JOIN buildings fb ON fr.building_id  = fb.id
     LEFT JOIN rooms    tr ON rr.to_room_id   = tr.id
     LEFT JOIN buildings tb ON tr.building_id  = tb.id
     WHERE  rr.student_id = ? AND rr.status = 'pending'
     ORDER  BY rr.created_at DESC LIMIT 1"
);
$pendingStmt->execute([$user['id']]);
$pendingReq = $pendingStmt->fetch();

// ── Xử lý POST: gửi yêu cầu ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action']  ?? '';
    $roomId   = (int)($_POST['room_id'] ?? 0);
    $reason   = trim($_POST['reason'] ?? '');

    // Chặn nếu đang có yêu cầu pending
    if ($pendingReq) {
        setFlash('warning', 'Bạn đang có yêu cầu chờ xử lý. Vui lòng đợi admin duyệt trước khi gửi yêu cầu mới.');
        header('Location: room_register.php'); exit;
    }

    if ($action === 'register' && $roomId) {
        // Kiểm tra phòng còn chỗ
        $room = $db->prepare("SELECT r.*, b.name as building_name,
            (SELECT COUNT(*) FROM student_profiles sp WHERE sp.room_id=r.id) as occupied
            FROM rooms r JOIN buildings b ON r.building_id=b.id WHERE r.id=?");
        $room->execute([$roomId]);
        $room = $room->fetch();

        if (!$room || $room['status'] !== 'available' || $room['occupied'] >= $room['capacity']) {
            setFlash('danger', 'Phòng này không còn chỗ trống hoặc đang bảo trì.');
        } elseif ($myProfile && $myProfile['room_id'] == $roomId) {
            setFlash('warning', 'Bạn đang ở phòng này rồi.');
        } else {
            $type        = $myProfile && $myProfile['room_id'] ? 'change' : 'register';
            $fromRoomId  = ($type === 'change') ? $myProfile['room_id'] : null;

            $db->prepare(
                "INSERT INTO room_requests (student_id, request_type, from_room_id, to_room_id, reason, status)
                 VALUES (?, ?, ?, ?, ?, 'pending')"
            )->execute([$user['id'], $type, $fromRoomId, $roomId, $reason]);

            $reqId = $db->lastInsertId();

            // Thông báo cho tất cả admin + staff
            $admins = $db->query("SELECT id FROM users WHERE role IN ('admin','staff') AND status='active'")->fetchAll();
            $label  = $type === 'change' ? 'Đổi phòng' : 'Đăng ký phòng';
            foreach ($admins as $admin) {
                sendNotification(
                    $admin['id'],
                    "[$label] {$user['full_name']}",
                    "{$user['full_name']} gửi yêu cầu $label → {$room['building_name']} - Phòng {$room['room_number']}",
                    'info', $reqId, 'room_request'
                );
            }
            // Thông báo cho chính sinh viên
            sendNotification(
                $user['id'],
                'Yêu cầu đã được gửi',
                "Yêu cầu $label phòng {$room['room_number']} đang chờ admin duyệt.",
                'info', $reqId, 'room_request'
            );

            logActivity($user['id'], 'room_request_' . $type, "Yêu cầu $type phòng ID $roomId");
            setFlash('success', 'Yêu cầu đã được gửi! Admin sẽ duyệt trong thời gian sớm nhất.');
        }
        header('Location: room_register.php'); exit;
    }

    if ($action === 'cancel_room') {
        // Yêu cầu huỷ phòng hiện tại
        if (!$myProfile || !$myProfile['room_id']) {
            setFlash('warning', 'Bạn chưa có phòng để huỷ.');
        } else {
            $db->prepare(
                "INSERT INTO room_requests (student_id, request_type, from_room_id, to_room_id, reason, status)
                 VALUES (?, 'cancel', ?, NULL, ?, 'pending')"
            )->execute([$user['id'], $myProfile['room_id'], $reason]);

            $reqId = $db->lastInsertId();
            $admins = $db->query("SELECT id FROM users WHERE role IN ('admin','staff') AND status='active'")->fetchAll();
            foreach ($admins as $admin) {
                sendNotification($admin['id'],
                    "[Huỷ phòng] {$user['full_name']}",
                    "{$user['full_name']} yêu cầu huỷ phòng {$myProfile['room_number']} ({$myProfile['building_name']})",
                    'warning', $reqId, 'room_request');
            }
            sendNotification($user['id'], 'Yêu cầu huỷ phòng đã gửi',
                'Yêu cầu huỷ phòng đang chờ admin xác nhận.', 'warning', $reqId, 'room_request');

            logActivity($user['id'], 'room_request_cancel', "Yêu cầu huỷ phòng ID {$myProfile['room_id']}");
            setFlash('success', 'Yêu cầu huỷ phòng đã được gửi. Đang chờ admin xác nhận.');
        }
        header('Location: room_register.php'); exit;
    }

    if ($action === 'cancel_request') {
        // Huỷ yêu cầu đang pending
        $reqIdToCancel = (int)($_POST['req_id'] ?? 0);
        $checkReq = $db->prepare("SELECT * FROM room_requests WHERE id=? AND student_id=? AND status='pending'");
        $checkReq->execute([$reqIdToCancel, $user['id']]);
        if ($checkReq->fetch()) {
            $db->prepare("DELETE FROM room_requests WHERE id=?")->execute([$reqIdToCancel]);
            setFlash('info', 'Đã huỷ yêu cầu.');
        }
        header('Location: room_register.php'); exit;
    }
}

// ── Lấy danh sách phòng ──────────────────────────────────────────────────────
$buildingFilter = $_GET['building'] ?? '';
$typeFilter     = $_GET['type']     ?? '';
$statusFilter   = $_GET['status']   ?? 'available';

$where  = "WHERE 1=1";
$params = [];
if ($buildingFilter) { $where .= " AND r.building_id=?"; $params[] = $buildingFilter; }
if ($typeFilter)     { $where .= " AND r.type=?";        $params[] = $typeFilter; }
if ($statusFilter)   { $where .= " AND r.status=?";      $params[] = $statusFilter; }

$roomsStmt = $db->prepare(
    "SELECT r.*, b.name as building_name, b.address,
            (SELECT COUNT(*) FROM student_profiles sp WHERE sp.room_id = r.id) as occupied
     FROM   rooms r
     JOIN   buildings b ON r.building_id = b.id
     $where
     ORDER  BY b.name, r.room_number"
);
$roomsStmt->execute($params);
$rooms = $roomsStmt->fetchAll();

$buildings = $db->query("SELECT * FROM buildings ORDER BY name")->fetchAll();

// ── Lịch sử yêu cầu của sinh viên ───────────────────────────────────────────
$historyStmt = $db->prepare(
    "SELECT rr.*,
            fr.room_number as from_room_number, fb.name as from_building,
            tr.room_number as to_room_number,   tb.name as to_building,
            u.full_name as reviewer_name
     FROM   room_requests rr
     LEFT JOIN rooms    fr ON rr.from_room_id = fr.id
     LEFT JOIN buildings fb ON fr.building_id  = fb.id
     LEFT JOIN rooms    tr ON rr.to_room_id   = tr.id
     LEFT JOIN buildings tb ON tr.building_id  = tb.id
     LEFT JOIN users    u  ON rr.reviewed_by  = u.id
     WHERE  rr.student_id = ?
     ORDER  BY rr.created_at DESC LIMIT 10"
);
$historyStmt->execute([$user['id']]);
$history = $historyStmt->fetchAll();
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="container-fluid px-0">
<?php showFlash(); ?>

<div class="page-header">
    <div class="page-title"><i class="bi bi-door-open-fill"></i> Đăng Ký Phòng Ở</div>
</div>

<!-- ══ PHÒNG HIỆN TẠI ══════════════════════════════════════════════════════ -->
<div class="row g-3 mb-4">
    <div class="col-lg-7">
        <?php if ($myProfile && $myProfile['room_id']): ?>
        <div class="card h-100" style="border-left:4px solid var(--success);">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <div style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--text-muted);letter-spacing:.8px;">Phòng Hiện Tại</div>
                        <div style="font-size:1.6rem;font-weight:800;color:var(--success);">
                            <?= $myProfile['building_name'] ?> – <?= $myProfile['room_number'] ?>
                        </div>
                    </div>
                    <span class="badge bg-success" style="font-size:13px;padding:6px 14px;">Đang ở</span>
                </div>
                <div class="row g-2" style="font-size:13px;">
                    <div class="col-6">
                        <span style="color:var(--text-muted);">Loại phòng:</span>
                        <?= statusBadge($myProfile['room_type']) ?>
                    </div>
                    <div class="col-6">
                        <span style="color:var(--text-muted);">Sức chứa:</span>
                        <strong><?= $myProfile['occupied'] ?> / <?= $myProfile['capacity'] ?> người</strong>
                    </div>
                    <div class="col-6">
                        <span style="color:var(--text-muted);">Địa chỉ:</span>
                        <?= htmlspecialchars($myProfile['address'] ?? '—') ?>
                    </div>
                    <div class="col-6">
                        <span style="color:var(--text-muted);">Giá thuê:</span>
                        <strong style="color:var(--primary);"><?= formatMoney($myProfile['price_per_month']) ?>/tháng</strong>
                    </div>
                </div>
                <?php if (!$pendingReq): ?>
                <div class="mt-3 pt-3" style="border-top:1px solid var(--border);">
                    <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelRoomModal">
                        <i class="bi bi-x-circle me-1"></i>Yêu Cầu Huỷ Phòng
                    </button>
                    <small class="text-muted ms-2">Muốn đổi phòng? Chọn phòng mới bên dưới.</small>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="card h-100 text-center" style="border:2px dashed var(--border);">
            <div class="card-body py-4">
                <i class="bi bi-house-slash" style="font-size:2.5rem;color:var(--text-muted);"></i>
                <h5 class="mt-3">Chưa Có Phòng</h5>
                <p style="color:var(--text-muted);font-size:13px;">Chọn phòng bên dưới và gửi yêu cầu đăng ký.</p>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-5">
        <?php if ($pendingReq): ?>
        <!-- Yêu cầu đang chờ -->
        <div class="card h-100" style="border-left:4px solid #f59e0b;">
            <div class="card-body">
                <div style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--text-muted);letter-spacing:.8px;margin-bottom:8px;">
                    Yêu Cầu Đang Chờ Duyệt
                </div>
                <div class="d-flex align-items-center gap-2 mb-3">
                    <div style="width:36px;height:36px;border-radius:50%;background:#fef3c7;display:flex;align-items:center;justify-content:center;">
                        <i class="bi bi-hourglass-split" style="color:#d97706;font-size:1.1rem;"></i>
                    </div>
                    <div>
                        <?= statusBadge($pendingReq['request_type']) ?>
                        <div style="font-size:12px;color:var(--text-muted);">Gửi lúc <?= date('d/m/Y H:i', strtotime($pendingReq['created_at'])) ?></div>
                    </div>
                </div>
                <table style="font-size:13px;width:100%;">
                    <?php if ($pendingReq['from_room_number']): ?>
                    <tr>
                        <td style="color:var(--text-muted);padding:2px 0;width:45%;">Từ phòng:</td>
                        <td><strong><?= $pendingReq['from_building'] ?> – <?= $pendingReq['from_room_number'] ?></strong></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($pendingReq['to_room_number']): ?>
                    <tr>
                        <td style="color:var(--text-muted);padding:2px 0;">Đến phòng:</td>
                        <td><strong><?= $pendingReq['to_building'] ?> – <?= $pendingReq['to_room_number'] ?></strong></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($pendingReq['reason']): ?>
                    <tr>
                        <td style="color:var(--text-muted);padding:2px 0;">Lý do:</td>
                        <td><?= htmlspecialchars($pendingReq['reason']) ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
                <div class="mt-3 pt-3" style="border-top:1px solid var(--border);">
                    <form method="POST" onsubmit="return confirm('Huỷ yêu cầu này?')">
                        <input type="hidden" name="action" value="cancel_request">
                        <input type="hidden" name="req_id" value="<?= $pendingReq['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-x-lg me-1"></i>Huỷ Yêu Cầu Này
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php else: ?>
        <!-- Hướng dẫn -->
        <div class="card h-100" style="background:var(--primary-ultra-light);border:1px solid var(--primary-light)30;">
            <div class="card-body">
                <div style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--primary);letter-spacing:.8px;margin-bottom:12px;">
                    <i class="bi bi-info-circle-fill me-1"></i>Hướng Dẫn
                </div>
                <div style="font-size:13px;color:var(--text-muted);line-height:1.8;">
                    <div class="mb-2"><i class="bi bi-1-circle-fill me-2" style="color:var(--primary);"></i>Lọc và xem danh sách phòng bên dưới</div>
                    <div class="mb-2"><i class="bi bi-2-circle-fill me-2" style="color:var(--primary);"></i>Nhấn <strong>"Đăng ký"</strong> vào phòng muốn ở</div>
                    <div class="mb-2"><i class="bi bi-3-circle-fill me-2" style="color:var(--primary);"></i>Điền lý do và xác nhận gửi yêu cầu</div>
                    <div class="mb-2"><i class="bi bi-4-circle-fill me-2" style="color:var(--primary);"></i>Đợi admin xét duyệt (thường trong 24h)</div>
                    <div><i class="bi bi-5-circle-fill me-2" style="color:var(--primary);"></i>Bạn sẽ nhận thông báo khi có kết quả</div>
                </div>
                <?php if ($myProfile && $myProfile['room_id']): ?>
                <div class="mt-3 p-2 rounded" style="background:#fff3cd;font-size:12px;color:#92400e;">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                    Bạn đang có phòng. Khi đăng ký phòng mới, hệ thống sẽ gửi yêu cầu <strong>đổi phòng</strong>.
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ══ BỘ LỌC ═══════════════════════════════════════════════════════════════ -->
<div class="card mb-4">
    <div class="card-body py-3">
        <form method="GET" class="d-flex gap-2 flex-wrap align-items-end">
            <div>
                <label class="form-label mb-1" style="font-size:12px;">Tòa nhà</label>
                <select name="building" class="form-select form-select-sm" style="width:150px;">
                    <option value="">Tất cả tòa</option>
                    <?php foreach ($buildings as $b): ?>
                    <option value="<?= $b['id'] ?>" <?= $buildingFilter == $b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label mb-1" style="font-size:12px;">Loại phòng</label>
                <select name="type" class="form-select form-select-sm" style="width:140px;">
                    <option value="">Tất cả loại</option>
                    <option value="standard" <?= $typeFilter === 'standard' ? 'selected' : '' ?>>Tiêu chuẩn</option>
                    <option value="premium"  <?= $typeFilter === 'premium'  ? 'selected' : '' ?>>Cao cấp</option>
                    <option value="vip"      <?= $typeFilter === 'vip'      ? 'selected' : '' ?>>VIP</option>
                </select>
            </div>
            <div>
                <label class="form-label mb-1" style="font-size:12px;">Trạng thái</label>
                <select name="status" class="form-select form-select-sm" style="width:150px;">
                    <option value="">Tất cả</option>
                    <option value="available"   <?= $statusFilter === 'available'   ? 'selected' : '' ?>>Còn chỗ</option>
                    <option value="full"        <?= $statusFilter === 'full'        ? 'selected' : '' ?>>Đầy</option>
                    <option value="maintenance" <?= $statusFilter === 'maintenance' ? 'selected' : '' ?>>Bảo trì</option>
                </select>
            </div>
            <div style="padding-top:20px;">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Lọc</button>
                <a href="room_register.php" class="btn btn-sm btn-outline-secondary ms-1">Xóa lọc</a>
            </div>
            <div style="padding-top:20px;margin-left:auto;">
                <span style="font-size:13px;color:var(--text-muted);"><?= count($rooms) ?> phòng</span>
            </div>
        </form>
    </div>
</div>

<!-- ══ DANH SÁCH PHÒNG (card grid) ══════════════════════════════════════════ -->
<div class="row g-3 mb-4" id="roomGrid">
<?php if ($rooms): foreach ($rooms as $room):
    $isFull     = $room['status'] === 'full' || $room['occupied'] >= $room['capacity'];
    $isMaint    = $room['status'] === 'maintenance';
    $isMyRoom   = $myProfile && $myProfile['room_id'] == $room['id'];
    $canRegister = !$isFull && !$isMaint && !$pendingReq && !$isMyRoom;
    $pct        = $room['capacity'] > 0 ? round($room['occupied'] / $room['capacity'] * 100) : 0;
    $typeColors  = ['standard' => '#3b82f6', 'premium' => '#8b5cf6', 'vip' => '#f59e0b'];
    $typeColor   = $typeColors[$room['type']] ?? '#64748b';
?>
<div class="col-sm-6 col-lg-4 col-xl-3">
    <div class="card h-100 room-card <?= $isMyRoom ? 'my-room' : '' ?> <?= $isMaint ? 'maint-room' : '' ?>"
         style="border-top:3px solid <?= $typeColor ?>;<?= $isMaint ? 'opacity:.65;' : '' ?>">
        <div class="card-body pb-2">
            <!-- Header -->
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <div style="font-size:1.25rem;font-weight:800;"><?= htmlspecialchars($room['building_name']) ?> – <?= $room['room_number'] ?></div>
                    <div style="font-size:12px;color:var(--text-muted);"><?= htmlspecialchars($room['address'] ?? '') ?></div>
                </div>
                <?php if ($isMyRoom): ?>
                    <span class="badge bg-success"><i class="bi bi-house-fill me-1"></i>Phòng tôi</span>
                <?php else: ?>
                    <?= statusBadge($room['status']) ?>
                <?php endif; ?>
            </div>

            <!-- Type badge -->
            <div class="mb-3">
                <?= statusBadge($room['type']) ?>
            </div>

            <!-- Stats -->
            <div class="row g-2 mb-3" style="font-size:13px;">
                <div class="col-6">
                    <div style="color:var(--text-muted);font-size:11px;">SỨC CHỨA</div>
                    <div style="font-weight:700;"><?= $room['occupied'] ?> / <?= $room['capacity'] ?> người</div>
                </div>
                <div class="col-6">
                    <div style="color:var(--text-muted);font-size:11px;">GIÁ/THÁNG</div>
                    <div style="font-weight:700;color:var(--primary);"><?= formatMoney($room['price_per_month']) ?></div>
                </div>
            </div>

            <!-- Occupancy bar -->
            <div class="mb-3">
                <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--text-muted);margin-bottom:4px;">
                    <span>Lấp đầy</span><span><?= $pct ?>%</span>
                </div>
                <div style="height:6px;background:#e5e7eb;border-radius:99px;overflow:hidden;">
                    <div style="height:100%;width:<?= $pct ?>%;background:<?= $pct >= 100 ? '#ef4444' : ($pct >= 70 ? '#f59e0b' : '#22c55e') ?>;border-radius:99px;transition:width .4s;"></div>
                </div>
            </div>

            <?php if ($room['description']): ?>
            <div style="font-size:12px;color:var(--text-muted);margin-bottom:8px;"><?= htmlspecialchars($room['description']) ?></div>
            <?php endif; ?>
        </div>

        <!-- Action -->
        <div class="card-footer bg-transparent" style="padding:10px 16px;">
            <?php if ($isMyRoom): ?>
                <button class="btn btn-sm btn-outline-secondary w-100" disabled>
                    <i class="bi bi-house-fill me-1"></i>Đang ở đây
                </button>
            <?php elseif ($isMaint): ?>
                <button class="btn btn-sm btn-outline-secondary w-100" disabled>
                    <i class="bi bi-wrench me-1"></i>Đang bảo trì
                </button>
            <?php elseif ($isFull): ?>
                <button class="btn btn-sm btn-outline-danger w-100" disabled>
                    <i class="bi bi-x-circle me-1"></i>Phòng đã đầy
                </button>
            <?php elseif ($pendingReq): ?>
                <button class="btn btn-sm btn-secondary w-100" disabled title="Đang có yêu cầu chờ duyệt">
                    <i class="bi bi-hourglass-split me-1"></i>Đang chờ duyệt
                </button>
            <?php else: ?>
                <button class="btn btn-sm btn-primary w-100 btn-register-room"
                    data-bs-toggle="modal" data-bs-target="#confirmModal"
                    data-room-id="<?= $room['id'] ?>"
                    data-room-name="<?= htmlspecialchars($room['building_name']) ?> – Phòng <?= $room['room_number'] ?>"
                    data-room-type="<?= $room['type'] ?>"
                    data-room-price="<?= formatMoney($room['price_per_month']) ?>"
                    data-room-cap="<?= $room['occupied'] ?>/<?= $room['capacity'] ?> người"
                    data-has-room="<?= ($myProfile && $myProfile['room_id']) ? '1' : '0' ?>">
                    <i class="bi bi-send-fill me-1"></i>
                    <?= ($myProfile && $myProfile['room_id']) ? 'Yêu Cầu Đổi Phòng' : 'Đăng Ký Phòng Này' ?>
                </button>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; else: ?>
<div class="col-12">
    <div class="card"><div class="empty-state py-5">
        <i class="bi bi-building-slash"></i>
        <p>Không tìm thấy phòng nào phù hợp</p>
        <a href="room_register.php" class="btn btn-sm btn-outline-primary mt-2">Xóa bộ lọc</a>
    </div></div>
</div>
<?php endif; ?>
</div>

<!-- ══ LỊCH SỬ YÊU CẦU ═══════════════════════════════════════════════════════ -->
<?php if ($history): ?>
<div class="card">
    <div class="card-header"><i class="bi bi-clock-history"></i> Lịch Sử Yêu Cầu Phòng</div>
    <div class="card-body p-0">
        <table class="table mb-0">
            <thead><tr>
                <th>Ngày Gửi</th><th>Loại</th><th>Từ Phòng</th><th>Đến Phòng</th>
                <th>Lý Do</th><th>Trạng Thái</th><th>Phản Hồi Admin</th>
            </tr></thead>
            <tbody>
            <?php foreach ($history as $h): ?>
            <tr>
                <td style="font-size:12px;color:var(--text-muted);"><?= date('d/m/Y H:i', strtotime($h['created_at'])) ?></td>
                <td><?= statusBadge($h['request_type']) ?></td>
                <td style="font-size:13px;">
                    <?= $h['from_room_number'] ? htmlspecialchars("{$h['from_building']} – {$h['from_room_number']}") : '—' ?>
                </td>
                <td style="font-size:13px;">
                    <?= $h['to_room_number'] ? htmlspecialchars("{$h['to_building']} – {$h['to_room_number']}") : '—' ?>
                </td>
                <td style="font-size:12px;color:var(--text-muted);max-width:160px;">
                    <?= htmlspecialchars($h['reason'] ?? '—') ?>
                </td>
                <td><?= statusBadge($h['status']) ?></td>
                <td style="font-size:12px;">
                    <?php if ($h['review_note']): ?>
                        <span style="color:var(--text-muted);"><?= htmlspecialchars($h['review_note']) ?></span>
                        <?php if ($h['reviewer_name']): ?>
                        <div style="font-size:11px;color:var(--text-muted);">– <?= htmlspecialchars($h['reviewer_name']) ?>
                            (<?= date('d/m', strtotime($h['reviewed_at'])) ?>)</div>
                        <?php endif; ?>
                    <?php else: ?>
                        <span style="color:var(--text-muted);">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
</div>

<!-- ══ MODAL: Xác nhận đăng ký / đổi phòng ══════════════════════════════════ -->
<div class="modal fade" id="confirmModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--primary);color:#fff;">
                <h5 class="modal-title" id="confirmModalTitle">
                    <i class="bi bi-send-fill me-2"></i>Xác Nhận Đăng Ký Phòng
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="register">
                <input type="hidden" name="room_id" id="modalRoomId">
                <div class="modal-body">
                    <!-- Room summary -->
                    <div class="p-3 rounded mb-3" style="background:var(--primary-ultra-light);border:1px solid var(--primary-light)30;">
                        <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.8px;">Phòng Muốn Đăng Ký</div>
                        <div style="font-size:1.2rem;font-weight:800;color:var(--primary);margin:4px 0;" id="modalRoomName"></div>
                        <div class="d-flex gap-3" style="font-size:13px;" id="modalRoomMeta"></div>
                    </div>

                    <!-- Warning nếu đổi phòng -->
                    <div id="changeWarning" class="alert alert-warning mb-3" style="display:none;font-size:13px;">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong>Lưu ý:</strong> Bạn đang có phòng hiện tại. Đây sẽ là yêu cầu <strong>đổi phòng</strong>.
                        Admin sẽ xét duyệt và cập nhật phòng cho bạn.
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Lý Do Đăng Ký <span class="text-danger">*</span></label>
                        <textarea name="reason" class="form-control" rows="3"
                            placeholder="Vd: Gần chỗ học, muốn ở với bạn bè, phòng hiện tại quá đông..." required></textarea>
                        <div class="form-text">Lý do rõ ràng giúp admin duyệt nhanh hơn.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x me-1"></i>Đóng
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-send-fill me-1"></i>Gửi Yêu Cầu
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ══ MODAL: Xác nhận huỷ phòng ══════════════════════════════════════════ -->
<div class="modal fade" id="cancelRoomModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background:#ef4444;color:#fff;">
                <h5 class="modal-title"><i class="bi bi-x-circle-fill me-2"></i>Yêu Cầu Huỷ Phòng</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="cancel_room">
                <div class="modal-body">
                    <div class="alert alert-danger mb-3" style="font-size:13px;">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong>Lưu ý:</strong> Sau khi huỷ phòng và được admin duyệt, bạn sẽ không còn được ở phòng này.
                        Bạn có thể đăng ký phòng mới sau đó.
                    </div>
                    <?php if ($myProfile && $myProfile['room_id']): ?>
                    <div class="p-3 rounded mb-3" style="background:#fff5f5;border:1px solid #fecaca;">
                        <div style="font-size:11px;color:#dc2626;text-transform:uppercase;letter-spacing:.8px;">Phòng Sẽ Huỷ</div>
                        <div style="font-size:1.1rem;font-weight:700;color:#dc2626;">
                            <?= $myProfile['building_name'] ?> – Phòng <?= $myProfile['room_number'] ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label">Lý Do Huỷ Phòng <span class="text-danger">*</span></label>
                        <textarea name="reason" class="form-control" rows="3"
                            placeholder="Vd: Ra trường, chuyển trọ ngoài, về quê..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Không Huỷ</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-x-circle me-1"></i>Gửi Yêu Cầu Huỷ
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.room-card { transition: transform .18s, box-shadow .18s; }
.room-card:hover { transform: translateY(-3px); box-shadow: 0 8px 24px rgba(0,0,0,.1); }
.room-card.my-room { box-shadow: 0 0 0 2px var(--success); }
</style>

<script>
// Populate confirm modal
document.getElementById('confirmModal').addEventListener('show.bs.modal', function(e) {
    const btn = e.relatedTarget;
    document.getElementById('modalRoomId').value   = btn.dataset.roomId;
    document.getElementById('modalRoomName').textContent = btn.dataset.roomName;
    document.getElementById('modalRoomMeta').innerHTML =
        `<span><i class="bi bi-tag-fill me-1"></i>${btn.dataset.roomType}</span>
         <span><i class="bi bi-people-fill me-1"></i>${btn.dataset.roomCap}</span>
         <span><i class="bi bi-cash me-1"></i>${btn.dataset.roomPrice}/tháng</span>`;

    const hasRoom = btn.dataset.hasRoom === '1';
    document.getElementById('changeWarning').style.display = hasRoom ? 'block' : 'none';
    document.getElementById('confirmModalTitle').innerHTML = hasRoom
        ? '<i class="bi bi-arrow-left-right me-2"></i>Xác Nhận Đổi Phòng'
        : '<i class="bi bi-send-fill me-2"></i>Xác Nhận Đăng Ký Phòng';
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
