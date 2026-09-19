<?php
declare(strict_types=1);

/**
 * CLI: إرسال مستند بالبريد باستخدام إعدادات SMTP للشركة.
 * Usage: php document_email_send.php <userId>
 * stdin JSON:
 *   to_email, subject?, body_html?, body_text?,
 *   attachment?: { name, data_base64, mime? }
 * stdout: JSON سطر واحد
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "cli only\n");
    exit(1);
}

$userId = (int) ($argv[1] ?? 0);
$raw = stream_get_contents(STDIN);
$payload = json_decode($raw !== false && $raw !== '' ? $raw : '{}', true);
if (!is_array($payload)) {
    $payload = [];
}

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once app_path('includes/company_smtp.php');
require_once app_path('includes/company_settings.php');

function cli_out(array $data, int $code = 0): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
    exit($code);
}

if ($userId < 1) {
    cli_out(['ok' => false, 'error' => 'user_id مطلوب.'], 1);
}

$to = trim((string) ($payload['to_email'] ?? ''));
if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    cli_out(['ok' => false, 'error' => 'البريد الإلكتروني للمستلم غير صالح.'], 1);
}

if (!company_smtp_is_configured()) {
    cli_out([
        'ok' => false,
        'error' => 'إعدادات البريد (SMTP) غير مهيأة. افتح الإعدادات → عام وأكمل بيانات المرسل/الخادم.',
    ], 1);
}

$cs = company_settings();
$companyName = trim((string) ($cs['company_name_ar'] ?? ''));

$subject = trim((string) ($payload['subject'] ?? ''));
if ($subject === '') {
    $subject = 'مستند من ' . ($companyName !== '' ? $companyName : 'Hypex');
}

$bodyHtml = (string) ($payload['body_html'] ?? '');
if ($bodyHtml === '') {
    $bodyText = trim((string) ($payload['body_text'] ?? 'مرفق بيانات المستند.'));
    $bodyHtml = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;direction:rtl;color:#0f172a;">'
        . '<p>' . nl2br(htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8')) . '</p>'
        . ($companyName !== ''
            ? '<p style="margin-top:1rem;color:#64748b;">' . htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') . '</p>'
            : '')
        . '</div>';
}

$attachment = null;
$att = $payload['attachment'] ?? null;
if (is_array($att) && !empty($att['data_base64'])) {
    $bin = base64_decode((string) $att['data_base64'], true);
    if ($bin !== false && $bin !== '') {
        $name = preg_replace('/[\\\\\/:*?"<>|\r\n]+/u', '_', (string) ($att['name'] ?? 'document.pdf')) ?: 'document.pdf';
        $attachment = [
            'name' => $name,
            'data' => $bin,
            'mime' => (string) ($att['mime'] ?? 'application/pdf'),
        ];
    }
}

$result = company_smtp_send($to, $subject, $bodyHtml, $attachment);
if (!($result['ok'] ?? false)) {
    cli_out([
        'ok' => false,
        'error' => $result['error'] ?? 'تعذر إرسال البريد.',
    ], 1);
}

cli_out([
    'ok' => true,
    'message' => 'تم إرسال البريد بنجاح إلى ' . $to,
]);
