<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';

$bootEsc = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

if (PHP_VERSION_ID < 80000) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><title>خطأ توافق</title>';
    echo '<body style="font-family:system-ui;padding:2rem;line-height:1.8">';
    echo '<h2 style="margin:0 0 .75rem">تعذر تشغيل النظام</h2>';
    echo '<p style="margin:0">نسخة PHP الحالية هي <strong>' . $bootEsc(PHP_VERSION) . '</strong>،';
    echo ' بينما النظام يحتاج <strong>PHP 8.0+</strong> (الموصى به 8.1 أو 8.2).</p>';
    echo '</body></html>';
    exit;
}

$appDebugMode = false;
if (defined('APP_DEBUG') && APP_DEBUG) {
    $appDebugMode = true;
}
if (isset($_GET['debug']) && (string) $_GET['debug'] === '1') {
    $appDebugMode = true;
}

error_reporting(E_ALL);
@ini_set('log_errors', '1');
@ini_set('display_errors', $appDebugMode ? '1' : '0');

set_exception_handler(static function (Throwable $e) use ($appDebugMode, $bootEsc): void {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    if ($appDebugMode) {
        echo '<pre style="direction:ltr;text-align:left;white-space:pre-wrap;padding:1rem;background:#f8fafc;border:1px solid #e2e8f0">';
        echo $bootEsc((string) $e);
        echo '</pre>';
        return;
    }

    echo '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><title>خطأ داخلي</title>';
    echo '<body style="font-family:system-ui;padding:2rem;line-height:1.8">';
    echo '<h2 style="margin:0 0 .75rem">حدث خطأ داخلي</h2>';
    echo '<p style="margin:0">تحقق من إعدادات قاعدة البيانات في <code>config/database.local.php</code>، ';
    echo 'وتأكد من نسخة PHP المناسبة. إذا استمرت المشكلة راجع ملف <code>error_log</code> في cPanel.</p>';
    echo '</body></html>';
});

register_shutdown_function(static function () use ($appDebugMode, $bootEsc): void {
    $e = error_get_last();
    if (!is_array($e)) {
        return;
    }
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array((int) ($e['type'] ?? 0), $fatalTypes, true)) {
        return;
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    if ($appDebugMode) {
        echo '<pre style="direction:ltr;text-align:left;white-space:pre-wrap;padding:1rem;background:#fef2f2;border:1px solid #fecaca">';
        echo 'Fatal: ' . $bootEsc((string) ($e['message'] ?? '')) . "\n";
        echo 'File: ' . $bootEsc((string) ($e['file'] ?? '')) . "\n";
        echo 'Line: ' . $bootEsc((string) ($e['line'] ?? ''));
        echo '</pre>';
        return;
    }

    echo '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><title>خطأ داخلي</title>';
    echo '<body style="font-family:system-ui;padding:2rem;line-height:1.8">';
    echo '<h2 style="margin:0 0 .75rem">تعذر إكمال الطلب</h2>';
    echo '<p style="margin:0">حصل خطأ على الخادم. راجع ملف <code>error_log</code> في cPanel، ';
    echo 'أو افتح الرابط مع <code>?debug=1</code> لعرض التفاصيل مؤقتاً.</p>';
    echo '</body></html>';
});

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once app_path('includes/helpers.php');
require_once app_path('includes/app_gps.php');
require_once app_path('includes/db.php');
require_once app_path('includes/company_settings.php');
require_once app_path('includes/document_header.php');
require_once app_path('includes/auth.php');
require_once app_path('includes/date_defaults.php');
app_apply_timezone();
