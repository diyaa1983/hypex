<?php
declare(strict_types=1);

/**
 * مهمة يومية: إرسال تنبيه بريد للشيكات الواردة والصادرة المستحقة.
 *
 * Windows (مجدول المهام — مرة يومياً الساعة 8:00):
 *   C:\xampp\php\php.exe C:\xampp\htdocs\manager\cron\check_due_emails.php
 *
 * Linux cron:
 *   0 8 * * * /usr/bin/php /path/to/manager/cron/check_due_emails.php
 */

require dirname(__DIR__) . '/config/app.php';
require_once app_path('includes/helpers.php');
require_once app_path('includes/db.php');
require_once app_path('includes/fin_check_due_email.php');
require_once app_path('includes/fin_out_check_due_email.php');

$pdo = db();
$incoming = fin_check_due_email_run($pdo, null);
$outgoing = fin_out_check_due_email_run($pdo, null);
$result = [
    'ok' => !empty($incoming['ok']) && !empty($outgoing['ok']),
    'incoming' => $incoming,
    'outgoing' => $outgoing,
    'sent' => (int) ($incoming['sent'] ?? 0) + (int) ($outgoing['sent'] ?? 0),
    'emails' => (int) ($incoming['emails'] ?? 0) + (int) ($outgoing['emails'] ?? 0),
];

if (PHP_SAPI === 'cli') {
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(($result['ok'] ?? false) ? 0 : 1);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($result, JSON_UNESCAPED_UNICODE);
