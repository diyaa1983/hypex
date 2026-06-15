<?php
declare(strict_types=1);

/**
 * إعدادات الإنتاج على HostGator (نطاق فرعي).
 *
 * 1) انسخ هذا الملف إلى: config/app.local.php
 * 2) لا ترفع app.local.php إلى Git — يبقى على السيرفر فقط.
 *
 * مثال: manager.gppjo.com → مجلد document root للنطاق الفرعي
 *       APP_URL_BASE = '' (فارغ)
 *
 * إذا وضعت النظام في مجلد فرعي مثل public_html/erp/
 *       APP_URL_BASE = '/erp'
 */

if (!defined('APP_URL_BASE')) {
    define('APP_URL_BASE', '');
}

if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', 'Asia/Amman');
}

/**
 * تخطي reCAPTCHA على localhost أثناء التطوير (يُفعّل تلقائياً لـ localhost).
 * على السيرفر: لا تضف هذا السطر أو اجعله false.
 */
if (!defined('LOGIN_RECAPTCHA_SKIP')) {
    define('LOGIN_RECAPTCHA_SKIP', false);
}

/**
 * وضع التشخيص المؤقت على السيرفر:
 * true  = إظهار تفاصيل الأخطاء (استخدمه مؤقتاً فقط)
 * false = إخفاء التفاصيل للمستخدم
 */
if (!defined('APP_DEBUG')) {
    define('APP_DEBUG', false);
}
