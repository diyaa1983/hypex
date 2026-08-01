<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/sal_customer_order.php');
header('Content-Type: application/json; charset=utf-8');
if (!is_logged_in() || (!user_can('sales_customer_orders') && !user_can('sales_customer_orders_approve'))) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'forbidden']); exit; }
$pdo=db(); sal_customer_order_ensure_schema($pdo); $order=sal_customer_order_fetch($pdo,(int)($_GET['id']??0));
if (!$order) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'not_found']); exit; }
echo json_encode(['ok'=>true,'order'=>$order],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
