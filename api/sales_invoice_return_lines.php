<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/sal_return_schema.php');
require_once app_path('includes/sal_invoice_post.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !user_can('sales_returns')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$invoiceId = (int) ($_GET['invoice_id'] ?? 0);
$customerId = (int) ($_GET['customer_id'] ?? 0);

if ($invoiceId < 1) {
    echo json_encode(['ok' => false, 'error' => 'invoice_required']);
    exit;
}

$pdo = db();
sal_return_ensure_schema($pdo);

$invSt = $pdo->prepare('SELECT id, customer_id, invoice_no, status FROM sal_invoice WHERE id = ? LIMIT 1');
$invSt->execute([$invoiceId]);
$inv = $invSt->fetch();
if (!$inv || (string) $inv['status'] !== 'confirmed') {
    echo json_encode(['ok' => false, 'error' => 'invoice_not_found']);
    exit;
}
if ($customerId > 0 && (int) $inv['customer_id'] !== $customerId) {
    echo json_encode(['ok' => false, 'error' => 'customer_mismatch']);
    exit;
}

if (!sal_invoice_is_posted($pdo, $invoiceId)) {
    echo json_encode(
        [
            'ok' => false,
            'error' => 'invoice_not_posted',
            'message' => 'لا يمكن إرجاع إلا فواتير مبيعات مرحّلة. رحّل الفاتورة أولاً من «ترحيل فواتير المبيعات».',
        ],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

require_once app_path('includes/sal_return_invoice_lines.php');

$lines = sal_return_fetch_invoice_lines($pdo, $invoiceId);

echo json_encode(
    [
        'ok' => true,
        'invoice_no' => (string) $inv['invoice_no'],
        'is_posted' => 1,
        'lines' => $lines,
        'message' => null,
    ],
    JSON_UNESCAPED_UNICODE
);
