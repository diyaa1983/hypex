<?php

declare(strict_types=1);



require_once dirname(__DIR__) . '/includes/bootstrap.php';

require_once app_path('includes/sal_delivery_invoice_link.php');

require_once app_path('includes/sal_invoice_schema.php');



header('Content-Type: application/json; charset=utf-8');



if (!is_logged_in() || !user_can('sales_invoices')) {

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

sal_invoice_ensure_schema($pdo);

sal_delivery_invoice_link_ensure($pdo);



$invoiceId = (int) ($_POST['invoice_id'] ?? 0);

if ($invoiceId < 1) {

    http_response_code(400);

    echo json_encode(['ok' => false, 'error' => 'معرّف الفاتورة غير صالح.'], JSON_UNESCAPED_UNICODE);

    exit;

}



$result = sal_invoice_unlink_delivery($pdo, $invoiceId);

if (!$result['ok']) {

    http_response_code(400);

    echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'تعذر فك الربط.'], JSON_UNESCAPED_UNICODE);

    exit;

}



$dNo = (string) ($result['delivery_no'] ?? '');

$msg = $dNo !== ''

    ? 'تم فك ربط الفاتورة عن سند «' . $dNo . '». الفاتورة وإرسال الضريبة لم يتأثرا.'

    : 'لا يوجد ربط بسند على هذه الفاتورة.';



echo json_encode([

    'ok' => true,

    'delivery_id' => (int) ($result['delivery_id'] ?? 0),

    'delivery_no' => $dNo,

    'message' => $msg,

], JSON_UNESCAPED_UNICODE);

