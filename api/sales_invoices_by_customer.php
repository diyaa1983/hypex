<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/sal_return_invoices.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !user_can('sales_returns')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$customerId = (int) ($_GET['customer_id'] ?? 0);
if ($customerId < 1) {
    echo json_encode(['ok' => true, 'invoices' => []]);
    exit;
}

$pdo = db();
require_once app_path('includes/sal_invoice_schema.php');
require_once app_path('includes/crm_customer_ledger.php');
sal_invoice_ensure_schema($pdo);
crm_ledger_ensure_schema($pdo);

$rows = sal_return_invoices_for_customer($pdo, $customerId);

echo json_encode(['ok' => true, 'invoices' => $rows], JSON_UNESCAPED_UNICODE);
