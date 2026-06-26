<?php
/**
 * invoice_detail.php
 * Xem chi tiết + in hóa đơn
 */
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$user = currentUser();
$db   = getDB();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . BASE_URL . '/pages/dashboard.php'); exit; }

$stmt = $db->prepare("SELECT i.*, u.full_name, u.email, u.phone, sp.student_code, sp.university,
    r.room_number, r.type as room_type, r.price_per_month,
    b.name as building_name, b.address,
    uc.full_name as created_by_name
    FROM invoices i
    JOIN users u ON i.student_id=u.id
    LEFT JOIN student_profiles sp ON u.id=sp.user_id
    JOIN rooms r ON i.room_id=r.id
    JOIN buildings b ON r.building_id=b.id
    LEFT JOIN users uc ON i.created_by=uc.id
    WHERE i.id=?");
$stmt->execute([$id]);
$invoice = $stmt->fetch();

if (!$invoice) { header('Location: ' . BASE_URL . '/pages/dashboard.php'); exit; }
// Permission check
if ($user['role'] === 'student' && $invoice['student_id'] != $user['id']) {
    header('Location: ' . BASE_URL . '/pages/forbidden.php'); exit;
}

$items = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id=?");
$items->execute([$id]);
$items = $items->fetchAll();

$payments = $db->prepare("SELECT * FROM payments WHERE invoice_id=? ORDER BY paid_at DESC");
$payments->execute([$id]);
$payments = $payments->fetchAll();

$remaining = $invoice['total_amount'] - $invoice['paid_amount'];
$isPrint   = isset($_GET['print']);
?>
<?php if (!$isPrint): include __DIR__ . '/../includes/header.php'; ?>
<div class="container-fluid px-0">
<?php showFlash(); ?>
<div class="page-header">
    <div class="page-title"><i class="bi bi-file-earmark-text-fill"></i> Chi Tiết Hóa Đơn</div>
    <div class="d-flex gap-2">
        <a href="?id=<?= $id ?>&print=1" target="_blank" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-printer-fill me-1"></i>In Hóa Đơn
        </a>
        <?php if (in_array($invoice['status'], ['pending','partial','overdue'])): ?>
        <a href="pay_invoice.php?id=<?= $id ?>" class="btn btn-sm btn-primary">
            <i class="bi bi-qr-code me-1"></i>Thanh Toán
        </a>
        <?php endif; ?>
        <a href="<?= $user['role']==='student' ? 'my_invoices' : 'invoices' ?>.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Quay Lại
        </a>
    </div>
</div>
<?php endif; ?>

<?php if ($isPrint): ?>
<!DOCTYPE html><html lang="vi"><head>
<meta charset="UTF-8"><title>Hóa đơn <?= $invoice['invoice_code'] ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body { font-family: 'Be Vietnam Pro', Arial, sans-serif; font-size: 13px; }
  .invoice-header { text-align: center; margin-bottom: 24px; }
  .invoice-header h2 { font-size: 22px; font-weight: 800; color: #1a3a6b; }
  table { width: 100%; border-collapse: collapse; }
  th, td { border: 1px solid #ddd; padding: 8px 10px; }
  th { background: #f0f4ff; }
  .total-row { font-weight: 700; font-size: 14px; background: #f8f9fd; }
  @media print { .no-print { display: none; } }
</style>
</head><body>
<div class="no-print mb-3 p-2" style="background:#fff3cd;">
  <button onclick="window.print()" class="btn btn-sm btn-primary me-2"><i class="bi bi-printer"></i> In</button>
  <button onclick="window.close()" class="btn btn-sm btn-outline-secondary">Đóng</button>
</div>
<?php endif; ?>

<div class="<?= $isPrint ? 'container py-4' : '' ?>">
    <?php if ($isPrint): ?>
    <div class="invoice-header">
        <h2>KỲ TÚC XÁ TRƯỜNG</h2>
        <div>Địa chỉ: <?= htmlspecialchars($invoice['address'] ?? 'N/A') ?></div>
        <hr>
        <h3 style="font-size:18px;font-weight:700;margin-top:12px;">HÓA ĐƠN THANH TOÁN</h3>
        <div style="font-size:12px;color:#666;">Mã: <strong><?= $invoice['invoice_code'] ?></strong>
         | Ngày lập: <?= formatDate($invoice['created_at']) ?></div>
    </div>
    <?php endif; ?>

    <div class="row g-4 mb-4">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-person-fill"></i> Thông Tin Sinh Viên</div>
                <div class="card-body">
                    <table class="table table-borderless mb-0" style="font-size:13px;">
                        <tr><td style="color:var(--text-muted);width:45%;">Họ tên</td><td><strong><?= htmlspecialchars($invoice['full_name']) ?></strong></td></tr>
                        <tr><td style="color:var(--text-muted);">Mã SV</td><td class="text-mono"><?= $invoice['student_code'] ?? '—' ?></td></tr>
                        <tr><td style="color:var(--text-muted);">Email</td><td><?= htmlspecialchars($invoice['email']) ?></td></tr>
                        <tr><td style="color:var(--text-muted);">Điện thoại</td><td><?= htmlspecialchars($invoice['phone'] ?? '—') ?></td></tr>
                        <tr><td style="color:var(--text-muted);">Trường học</td><td><?= htmlspecialchars($invoice['university'] ?? '—') ?></td></tr>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-door-closed-fill"></i> Thông Tin Phòng & Hóa Đơn</div>
                <div class="card-body">
                    <table class="table table-borderless mb-0" style="font-size:13px;">
                        <tr><td style="color:var(--text-muted);width:45%;">Mã hóa đơn</td><td class="text-mono fw-bold"><?= $invoice['invoice_code'] ?></td></tr>
                        <tr><td style="color:var(--text-muted);">Phòng</td><td><strong><?= $invoice['building_name'] ?> - <?= $invoice['room_number'] ?></strong></td></tr>
                        <tr><td style="color:var(--text-muted);">Kỳ</td><td><?= date('m/Y', strtotime($invoice['billing_month'])) ?></td></tr>
                        <tr><td style="color:var(--text-muted);">Hạn TT</td><td><?= formatDate($invoice['due_date']) ?></td></tr>
                        <tr><td style="color:var(--text-muted);">Trạng thái</td><td><?= statusBadge($invoice['status']) ?></td></tr>
                        <tr><td style="color:var(--text-muted);">Người lập</td><td><?= htmlspecialchars($invoice['created_by_name'] ?? 'Hệ thống') ?></td></tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Items Table -->
    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-list-ul"></i> Chi Tiết Khoản Thu</div>
        <div class="card-body p-0">
            <table class="table mb-0">
                <thead><tr>
                    <th>STT</th><th>Khoản Thu</th><th class="text-end">Số Lượng</th>
                    <th class="text-end">Đơn Giá</th><th class="text-end">Thành Tiền</th>
                </tr></thead>
                <tbody>
                <?php foreach ($items as $i => $item): ?>
                <tr>
                    <td style="color:var(--text-muted);"><?= $i+1 ?></td>
                    <td><?= htmlspecialchars($item['description']) ?></td>
                    <td class="text-end"><?= number_format($item['quantity'], 2) ?></td>
                    <td class="text-end"><?= formatMoney($item['unit_price']) ?></td>
                    <td class="text-end"><strong><?= formatMoney($item['amount']) ?></strong></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="total-row" style="background:#f0f4ff;">
                        <td colspan="4" class="text-end fw-bold">TỔNG CỘNG</td>
                        <td class="text-end fw-bold" style="font-size:15px;color:var(--primary);"><?= formatMoney($invoice['total_amount']) ?></td>
                    </tr>
                    <?php if ($invoice['paid_amount'] > 0): ?>
                    <tr>
                        <td colspan="4" class="text-end text-success">Đã thanh toán</td>
                        <td class="text-end text-success">- <?= formatMoney($invoice['paid_amount']) ?></td>
                    </tr>
                    <tr style="background:#fff5f5;">
                        <td colspan="4" class="text-end fw-bold text-danger">CÒN LẠI</td>
                        <td class="text-end fw-bold text-danger" style="font-size:15px;"><?= formatMoney($remaining) ?></td>
                    </tr>
                    <?php endif; ?>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- Payment History -->
    <?php if ($payments): ?>
    <div class="card <?= $isPrint?'mb-4':'' ?>">
        <div class="card-header"><i class="bi bi-clock-history"></i> Lịch Sử Thanh Toán</div>
        <div class="card-body p-0">
            <table class="table mb-0">
                <thead><tr><th>Mã GD</th><th>Phương Thức</th><th>Số Tiền</th><th>Thời Gian</th><th>Ghi Chú</th></tr></thead>
                <tbody>
                <?php foreach ($payments as $p): ?>
                <tr>
                    <td class="text-mono" style="font-size:12px;"><?= $p['payment_code'] ?></td>
                    <td><?= ['qr_code'=>'Mã QR','cash'=>'Tiền mặt','bank_transfer'=>'Chuyển khoản','momo'=>'MoMo','zalopay'=>'ZaloPay'][$p['payment_method']] ?? $p['payment_method'] ?></td>
                    <td><strong style="color:var(--success);"><?= formatMoney($p['amount']) ?></strong></td>
                    <td style="font-size:12px;"><?= formatDateTime($p['paid_at']) ?></td>
                    <td style="font-size:12px;color:var(--text-muted);"><?= htmlspecialchars($p['notes'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isPrint): ?>
    <div style="margin-top:32px;display:flex;justify-content:space-between;font-size:12px;">
        <div style="text-align:center;width:45%;">
            <div style="margin-bottom:40px;">Ngày ........ tháng ........ năm <?= date('Y') ?></div>
            <strong>Sinh Viên</strong><br><small>(Ký, ghi rõ họ tên)</small>
        </div>
        <div style="text-align:center;width:45%;">
            <div style="margin-bottom:40px;">Ngày ........ tháng ........ năm <?= date('Y') ?></div>
            <strong>Thu Ngân / Quản Lý</strong><br><small>(Ký, ghi rõ họ tên)</small>
        </div>
    </div>
    </div></body></html>
    <?php return; ?>
    <?php endif; ?>
</div>

<?php if (!$isPrint): ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<?php endif; ?>
