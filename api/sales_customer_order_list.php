<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/sal_customer_order.php');
header('Content-Type: application/json; charset=utf-8');
if (!is_logged_in() || (!user_can('sales_customer_orders') && !user_can('sales_customer_orders_approve'))) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'forbidden']); exit; }
$pdo=db(); sal_customer_order_ensure_schema($pdo);
echo json_encode(['ok'=>true,'orders'=>sal_customer_order_list_fetch($pdo,trim((string)($_GET['q']??'')),null,($_GET['status']??'')?:null)],JSON_UNESCAPED_UNICODE);
