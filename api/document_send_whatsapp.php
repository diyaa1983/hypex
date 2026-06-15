<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/company_whatsapp.php');
require_once app_path('includes/company_settings.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method'], JSON_UNESCAPED_UNICODE);
    exit;
}

$csrf = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!verify_csrf($csrf)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'csrf'], JSON_UNESCAPED_UNICODE);
    exit;
}

$docType = (string) ($_POST['doc_type'] ?? '');
$permissionMap = [
    'sales_invoice'    => 'sales_invoices',
    'purchase_invoice' => 'purchase_invoices',
    'sales_return'     => 'sales_returns',
    'purchase_return'  => 'purchase_returns',
];
if (!isset($permissionMap[$docType])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'doc_type غير مدعوم'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!user_can($permissionMap[$docType])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!company_whatsapp_is_configured()) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'إعدادات WhatsApp غير مهيأة. افتح «الإعدادات → عام» وأكمل قسم إعدادات WhatsApp.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$cfg = company_whatsapp_settings();
$rawPhone = trim((string) ($_POST['to_phone'] ?? ''));
$phone = company_whatsapp_normalize_phone($rawPhone, $cfg['default_country']);
if ($phone === '' || strlen($phone) < 8 || strlen($phone) > 16) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'رقم الهاتف غير صالح.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$file = $_FILES['pdf'] ?? null;
if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'ملف PDF مفقود أو غير صالح.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$tmpPath = (string) $file['tmp_name'];
if (filesize($tmpPath) > 12 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'حجم ملف PDF يتجاوز 12 ميجابايت.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawName = (string) ($file['name'] ?? 'document.pdf');
$attachName = preg_replace('/[\\\\\/:*?"<>|\r\n]+/u', '_', $rawName) ?: 'document.pdf';
if (substr(strtolower($attachName), -4) !== '.pdf') {
    $attachName .= '.pdf';
}

$docTitleMap = [
    'sales_invoice'    => 'فاتورة مبيعات',
    'purchase_invoice' => 'فاتورة مشتريات',
    'sales_return'     => 'مرتجع مبيعات',
    'purchase_return'  => 'مردود مشتريات',
];
$docTitle = $docTitleMap[$docType];
$docNo = trim((string) ($_POST['doc_no'] ?? ''));

$cs = company_settings();
$companyName = (string) ($cs['company_name_ar'] ?? '');

$caption = trim((string) ($_POST['caption'] ?? ''));
if ($caption === '') {
    $caption = $docTitle . ($docNo !== '' ? ' رقم ' . $docNo : '');
    if ($companyName !== '') {
        $caption .= ' — ' . $companyName;
    }
}

$result = company_whatsapp_send_pdf_file($phone, $tmpPath, $attachName, $caption);

if (!($result['ok'] ?? false)) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $result['error'] ?? 'تعذر إرسال الرسالة عبر WhatsApp.',
        'code' => $result['code'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'message' => 'تم الإرسال بنجاح عبر WhatsApp إلى ' . $phone,
    'message_id' => $result['message_id'] ?? null,
    'wa_id' => $result['wa_id'] ?? null,
], JSON_UNESCAPED_UNICODE);
