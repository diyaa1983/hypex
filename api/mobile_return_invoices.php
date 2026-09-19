<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/mobile_auth.php');
require_once app_path('includes/mobile_return.php');
require_once app_path('includes/sal_return_schema.php');
require_once app_path('includes/sal_return_invoices.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !user_can_mobile_sales_returns()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'ليس لديك صلاحية.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$customerId = (int) ($_GET['customer_id'] ?? 0);
if ($customerId < 1) {
    echo json_encode(['ok' => true, 'invoices' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
sal_return_ensure_schema($pdo);

$invoices = sal_return_invoices_for_customer($pdo, $customerId);
foreach ($invoices as &$row) {
    $row['invoice_date'] = format_date_dmY((string) ($row['invoice_date'] ?? ''));
}
unset($row);

echo json_encode(['ok' => true, 'invoices' => $invoices], JSON_UNESCAPED_UNICODE);
