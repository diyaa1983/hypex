<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/sal_customer_order.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || (!user_can('sales_customer_orders') && !user_can('sales_customer_orders_approve') && !user_can('sales_customer_orders_approved'))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
sal_customer_order_ensure_schema($pdo);
$q = trim((string) ($_GET['q'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$status = $status !== '' ? $status : null;
$salesRepId = isset($_GET['sales_rep_id']) && $_GET['sales_rep_id'] !== '' ? (int) $_GET['sales_rep_id'] : null;
$customerId = isset($_GET['customer_id']) && $_GET['customer_id'] !== '' ? (int) $_GET['customer_id'] : null;
if ($salesRepId !== null && $salesRepId < 1) {
    $salesRepId = null;
}
if ($customerId !== null && $customerId < 1) {
    $customerId = null;
}

echo json_encode([
    'ok' => true,
    'orders' => sal_customer_order_list_fetch($pdo, $q, $salesRepId, $status, $customerId),
], JSON_UNESCAPED_UNICODE);
