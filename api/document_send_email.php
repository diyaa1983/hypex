<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/company_smtp.php');
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

$toEmail = trim((string) ($_POST['to_email'] ?? ''));
if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'البريد الإلكتروني غير صالح.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$file = $_FILES['pdf'] ?? null;
if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'ملف PDF مفقود أو غير صالح.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$pdfBytes = @file_get_contents((string) $file['tmp_name']);
if (!is_string($pdfBytes) || $pdfBytes === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'تعذر قراءة ملف PDF.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (strlen($pdfBytes) > 12 * 1024 * 1024) {
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

$subject = trim((string) ($_POST['subject'] ?? ''));
if ($subject === '') {
    $subject = $docTitle . ($docNo !== '' ? ' رقم ' . $docNo : '');
    if ($companyName !== '') {
        $subject .= ' — ' . $companyName;
    }
}
$bodyText = trim((string) ($_POST['body'] ?? ''));
if ($bodyText === '') {
    $bodyText = 'مرفق نسخة PDF لـ ' . $docTitle . ($docNo !== '' ? ' رقم ' . $docNo : '') . '.';
}
$bodyHtml = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;direction:rtl;color:#0f172a;">'
    . '<p>' . nl2br(htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8')) . '</p>'
    . ($companyName !== '' ? '<p style="margin-top:1rem;color:#64748b;">' . htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') . '</p>' : '')
    . '</div>';

if (!company_smtp_is_configured()) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'إعدادات البريد غير مهيأة. افتح «الإعدادات → عام» وأكمل قسم إعدادات SMTP.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = company_smtp_send(
    $toEmail,
    $subject,
    $bodyHtml,
    [
        'name' => $attachName,
        'data' => $pdfBytes,
        'mime' => 'application/pdf',
    ]
);

if (!($result['ok'] ?? false)) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $result['error'] ?? 'تعذر إرسال البريد.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'message' => 'تم إرسال البريد بنجاح إلى ' . $toEmail,
], JSON_UNESCAPED_UNICODE);
