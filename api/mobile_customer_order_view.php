<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/sal_customer_order.php');
require_once app_path('includes/crm_sales_rep_schema.php');
require_once app_path('includes/document_header.php');
header('Content-Type: application/json; charset=utf-8');
if (!is_logged_in() || !mobile_can_access_customer_order_api()) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'forbidden']); exit; }
$pdo=db(); sal_customer_order_ensure_schema($pdo); $order=sal_customer_order_fetch($pdo,(int)($_GET['id']??0));
$rep = user_is_system_admin() ? null : crm_mobile_scoped_sales_rep_id($pdo);
if (!$order || (!user_is_system_admin() && ($rep === null || (int)$order['sales_rep_id'] !== $rep))) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'not_found']); exit; }
$order = document_header_attach_brand($order, $pdo);
echo json_encode(['ok'=>true,'order'=>$order],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
