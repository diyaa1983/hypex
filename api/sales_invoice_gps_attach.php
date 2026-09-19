<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/sal_invoice_gps.php');
require_once app_path('includes/sal_invoice_post.php');
require_once app_path('includes/sal_invoice_schema.php');

require_once app_path('includes/mobile_invoice.php');

header('Content-Type: application/json; charset=utf-8');

$may = is_logged_in() && (
    user_can('sales_invoice_gps')
    || user_can_sales_invoices()
    || user_can('sales_documents_list')
    || mobile_can_post_sales_invoice()
);

if (!$may) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!verify_csrf($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'csrf'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!app_gps_enabled()) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'GPS معطّل في إعدادات النظام.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$invoiceId = (int) ($_POST['invoice_id'] ?? 0);
if ($invoiceId < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'معرّف الفاتورة غير صالح.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
sal_invoice_ensure_schema($pdo);
sal_invoice_gps_ensure_schema($pdo);

if (!sal_invoice_is_posted($pdo, $invoiceId)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'الفاتورة غير مرحّلة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$force = !empty($_POST['force']) && (string) $_POST['force'] !== '0';

if (sal_invoice_gps_has_coords($pdo, $invoiceId) && !$force) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'الفاتورة لديها موقع GPS مسجّل مسبقاً.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$gps = sal_invoice_gps_parse_request($_POST);
if ($gps === null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'إحداثيات GPS غير صالحة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int) (current_user()['id'] ?? 0);
if (!sal_invoice_gps_apply_on_post($pdo, $invoiceId, $gps, $userId > 0 ? $userId : null, $force)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'تعذر حفظ الموقع.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$place = sal_invoice_gps_place_for_invoice($pdo, $invoiceId, true);

echo json_encode([
    'ok' => true,
    'invoice_id' => $invoiceId,
    'place' => $place,
    'message' => 'تم تسجيل موقع الفاتورة.',
], JSON_UNESCAPED_UNICODE);
