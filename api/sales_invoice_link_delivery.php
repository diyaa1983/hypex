<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/sal_delivery_invoice_link.php');
require_once app_path('includes/crm_customer_ledger.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !user_can('sales_invoices')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!verify_csrf($_POST['_csrf'] ?? null)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'csrf'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
sal_invoice_ensure_schema($pdo);
sal_delivery_invoice_link_ensure($pdo);

$invoiceId = (int) ($_POST['invoice_id'] ?? 0);
$deliveryId = (int) ($_POST['delivery_id'] ?? 0);

if ($invoiceId < 1 || $deliveryId < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'بيانات الربط غير مكتملة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$inv = $pdo->prepare('SELECT id, customer_id, warehouse_id FROM sal_invoice WHERE id = ? LIMIT 1');
$inv->execute([$invoiceId]);
$invRow = $inv->fetch(PDO::FETCH_ASSOC);
if (!$invRow) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'الفاتورة غير موجودة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$linkCheck = sal_invoice_validate_delivery_link(
    $pdo,
    $deliveryId,
    (int) ($invRow['customer_id'] ?? 0),
    isset($invRow['warehouse_id']) ? (int) $invRow['warehouse_id'] : null,
    $invoiceId
);
if (!$linkCheck['ok']) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $linkCheck['error'] ?? 'تعذر الربط.'], JSON_UNESCAPED_UNICODE);
    exit;
}

sal_invoice_set_delivery_id($pdo, $invoiceId, $deliveryId);

$dn = $pdo->prepare('SELECT delivery_no FROM sal_delivery WHERE id = ? LIMIT 1');
$dn->execute([$deliveryId]);

echo json_encode([
    'ok' => true,
    'delivery_id' => $deliveryId,
    'delivery_no' => (string) ($dn->fetchColumn() ?: ''),
    'message' => 'تم ربط الفاتورة بسند التسليم.',
], JSON_UNESCAPED_UNICODE);
