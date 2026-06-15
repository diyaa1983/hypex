<?php

declare(strict_types=1);



require_once dirname(__DIR__) . '/includes/bootstrap.php';

require_once app_path('includes/sal_delivery_invoice_link.php');

require_once app_path('includes/sal_delivery_schema.php');



header('Content-Type: application/json; charset=utf-8');



if (!is_logged_in() || !user_can('sales_delivery') || !user_can('sales_invoices')) {

    http_response_code(403);

    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);

    exit;

}



if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    http_response_code(405);

    echo json_encode(['ok' => false, 'error' => 'method'], JSON_UNESCAPED_UNICODE);

    exit;

}



if (!verify_csrf($_POST['_csrf'] ?? null)) {

    http_response_code(400);

    echo json_encode(['ok' => false, 'error' => 'csrf'], JSON_UNESCAPED_UNICODE);

    exit;

}



$pdo = db();

sal_delivery_ensure_schema($pdo);

sal_delivery_invoice_link_ensure($pdo);



$deliveryId = (int) ($_POST['delivery_id'] ?? 0);

if ($deliveryId < 1) {

    http_response_code(400);

    echo json_encode(['ok' => false, 'error' => 'معرّف السند غير صالح.'], JSON_UNESCAPED_UNICODE);

    exit;

}



$result = sal_delivery_unlink_invoice($pdo, $deliveryId);

if (!$result['ok']) {

    http_response_code(400);

    echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'تعذر فك الربط.'], JSON_UNESCAPED_UNICODE);

    exit;

}



$invNo = (string) ($result['invoice_no'] ?? '');

$msg = $invNo !== ''

    ? 'تم فك ربط السند عن فاتورة «' . $invNo . '».'

    : 'لا يوجد ربط على هذا السند.';



echo json_encode([

    'ok' => true,

    'invoice_id' => (int) ($result['invoice_id'] ?? 0),

    'invoice_no' => $invNo,

    'message' => $msg,

], JSON_UNESCAPED_UNICODE);

