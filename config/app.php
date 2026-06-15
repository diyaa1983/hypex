<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

$appLocalFile = __DIR__ . '/app.local.php';
if (is_file($appLocalFile)) {
    require $appLocalFile;
}

/** مسار التطبيق على السيرفر: '' للنطاق الفرعي (manager.gppjo.com) أو '/manager' لمجلد فرعي. */
if (!defined('APP_URL_BASE')) {
    define('APP_URL_BASE', app_detect_url_base());
}

/** المنطقة الزمنية للتطبيق (تاريخ اليوم، النسخ الاحتياطي، التقارير). */
if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', 'Asia/Amman');
}

/** حفظ GPS عند الترحيل وتتبع موقع المستخدم — false = معطّل بالكامل. */
if (!defined('APP_GPS_ENABLED')) {
    define('APP_GPS_ENABLED', false);
}

/**
 * يكتشف المسار الأساسي من عنوان السكربت الحالي.
 * - مثال: /manager/login.php => /manager
 * - مثال: /login.php => ''
 */
function app_detect_url_base(): string
{
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($scriptName === '') {
        // عند عدم توفر معلومات الطلب (مثل بعض تشغيلات CLI) نحافظ على السلوك التاريخي.
        return '/manager';
    }

    $dir = str_replace('\\', '/', dirname($scriptName));
    if ($dir === '' || $dir === '.' || $dir === '/' || $dir === '\\') {
        return '';
    }

    return '/' . trim($dir, '/');
}

function app_url(string $path = ''): string
{
    $base = rtrim(APP_URL_BASE, '/');
    $path = ltrim($path, '/');
    return $base . ($path !== '' ? '/' . $path : '');
}

/** رابط كامل مع النطاق (لرسائل البريد). */
function app_absolute_url(string $path = ''): string
{
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
    $scheme = $isHttps ? 'https' : 'http';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        $host = 'localhost';
    }
    $rel = app_url($path);
    if ($rel === '' || $rel[0] !== '/') {
        $rel = '/' . ltrim($rel, '/');
    }

    return $scheme . '://' . $host . $rel;
}

function app_path(string $path = ''): string
{
    $path = ltrim($path, DIRECTORY_SEPARATOR);
    return APP_ROOT . ($path !== '' ? DIRECTORY_SEPARATOR . $path : '');
}
