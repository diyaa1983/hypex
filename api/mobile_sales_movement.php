<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/sal_mobile_sales_movement.php');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (
    !user_can('m_sales_movement')
    && !user_can('m_sales_invoices')
    && !user_can('m_customer_orders')
    && !user_is_system_admin()
) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
$from = parse_date_to_iso(trim((string) ($_GET['date_from'] ?? $_GET['from'] ?? ''))) ?? date('Y-m-01');
$to = parse_date_to_iso(trim((string) ($_GET['date_to'] ?? $_GET['to'] ?? ''))) ?? date('Y-m-d');
$customerId = (int) ($_GET['customer_id'] ?? 0);
$itemId = (int) ($_GET['item_id'] ?? 0);

$result = sal_mobile_sales_movement_report($pdo, [
    'from' => $from,
    'to' => $to,
    'customer_id' => $customerId,
    'item_id' => $itemId,
]);

echo json_encode([
    'ok' => true,
    'from' => $from,
    'to' => $to,
    'customer_id' => $customerId,
    'item_id' => $itemId,
    'rows' => $result['rows'],
    'totals' => $result['totals'],
], JSON_UNESCAPED_UNICODE);
