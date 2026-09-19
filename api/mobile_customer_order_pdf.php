<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/sal_customer_order.php');
require_once app_path('includes/crm_sales_rep_schema.php');
require_once app_path('includes/mobile_customer_order_print.php');
require_once app_path('includes/mobile_dompdf.php');

if (!is_logged_in() || !mobile_can_access_customer_order_api()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'forbidden';
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
if ($id < 1) {
    http_response_code(400);
    echo 'invalid_id';
    exit;
}

$pdo = db();
sal_customer_order_ensure_schema($pdo);
$order = sal_customer_order_fetch($pdo, $id);
$rep = user_is_system_admin() ? null : crm_mobile_scoped_sales_rep_id($pdo);
if (
    !$order
    || (!user_is_system_admin() && ($rep === null || (int) ($order['sales_rep_id'] ?? 0) !== $rep))
) {
    http_response_code(404);
    echo 'not_found';
    exit;
}

require_once app_path('includes/document_header.php');
$order = document_header_attach_brand($order, $pdo);
$html = mobile_customer_order_print_html($pdo, $order);
$no = trim((string) ($order['order_no'] ?? ''));
$fname = $no !== '' ? 'طلب شراء - ' . $no . '.pdf' : 'طلب شراء.pdf';
mobile_dompdf_stream_pdf($html, $fname);
