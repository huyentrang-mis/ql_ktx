<?php
/**
 * check_payment.php
 * AJAX endpoint: poll trạng thái hóa đơn cho QR polling
 */
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

header('Content-Type: application/json');

$invoiceId = (int)($_GET['invoice_id'] ?? 0);
if (!$invoiceId) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid invoice']);
    exit;
}

$db   = getDB();
$user = currentUser();

$stmt = $db->prepare("SELECT i.*, u.full_name FROM invoices i JOIN users u ON i.student_id=u.id WHERE i.id=?");
$stmt->execute([$invoiceId]);
$invoice = $stmt->fetch();

if (!$invoice) {
    echo json_encode(['status' => 'error', 'message' => 'Not found']);
    exit;
}

// Students can only check their own invoices
if ($user['role'] === 'student' && $invoice['student_id'] != $user['id']) {
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

$remaining = $invoice['total_amount'] - $invoice['paid_amount'];

echo json_encode([
    'status'        => $invoice['status'],
    'paid_amount'   => (float)$invoice['paid_amount'],
    'total_amount'  => (float)$invoice['total_amount'],
    'remaining'     => (float)$remaining,
    'is_paid'       => $invoice['status'] === 'paid',
]);
