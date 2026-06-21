<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/pur_order_convert.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !user_can_purchase_orders() || !user_can_action('action_convert_purchase_order')) {
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
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'انتهت صلاحية الجلسة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$orderId = (int) ($_POST['order_id'] ?? $_POST['invoice_id'] ?? 0);
$pdo = db();
$result = pur_order_convert_to_invoice($pdo, $orderId);

if (!$result['ok']) {
    echo json_encode(['ok' => false, 'message' => $result['message'] ?? 'تعذر التحويل.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once app_path('includes/nav_helpers.php');
$invoiceUrl = app_url('index.php?r=purchase_invoices&id=' . (int) ($result['invoice_id'] ?? 0));

echo json_encode([
    'ok' => true,
    'message' => 'تم إنشاء فاتورة شراء من الطلب.',
    'invoice_id' => (int) ($result['invoice_id'] ?? 0),
    'invoice_no' => (string) ($result['invoice_no'] ?? ''),
    'redirect_url' => $invoiceUrl,
], JSON_UNESCAPED_UNICODE);
