<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/sal_customer_order.php');
require_once app_path('includes/crm_sales_rep_schema.php');
header('Content-Type: application/json; charset=utf-8');
if (!is_logged_in() || !mobile_can_access_customer_order_api()) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'forbidden']); exit; }
$pdo=db(); sal_customer_order_ensure_schema($pdo);
$rep = user_is_system_admin() ? null : crm_mobile_scoped_sales_rep_id($pdo);
$status = trim((string) ($_GET['status'] ?? ''));
$status = $status !== '' ? $status : null;
$rows = !user_is_system_admin() && $rep === null
    ? []
    : sal_customer_order_list_fetch($pdo, trim((string) ($_GET['q'] ?? '')), $rep, $status);
echo json_encode(['ok' => true, 'orders' => $rows], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
