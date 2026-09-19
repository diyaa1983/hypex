<?php
declare(strict_types=1);

/**
 * إعدادات التطبيق على السيرفر (Static IP أو نطاق).
 *
 * 1) انسخ إلى: config/app.local.php
 * 2) لا ترفع app.local.php إلى Git — يبقى على السيرفر (والمحلي) فقط.
 *
 * ── الوصول عبر Static IP ──
 * مثال: http://203.0.113.45/          → DocumentRoot = مجلد المشروع → APP_URL_BASE = ''
 * مثال: http://203.0.113.45/hypex/    → مجلد فرعي في web root     → APP_URL_BASE = '/hypex'
 *
 * ── نطاق فرعي ──
 * مثال: https://manager.example.com/  → APP_URL_BASE = ''
 */

if (!defined('APP_URL_BASE')) {
    // '' إذا كان DocumentRoot يشير مباشرة لمجلد النظام
    define('APP_URL_BASE', '');
}

if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', 'Asia/Amman');
}

/**
 * على السيرفر اجعلها false دائماً.
 * على localhost للتطوير يمكنك true لتخطي reCAPTCHA.
 */
if (!defined('LOGIN_RECAPTCHA_SKIP')) {
    define('LOGIN_RECAPTCHA_SKIP', false);
}

/**
 * true  = إظهار تفاصيل الأخطاء (مؤقت أثناء التركيب فقط)
 * false = وضع الإنتاج
 */
if (!defined('APP_DEBUG')) {
    define('APP_DEBUG', false);
}

/**
 * الترخيص (اختياري):
 * - APP_LICENSE_ENFORCE = true يلزم رقم تفعيل مرتبط بالسيرفر
 * - APP_LICENSE_SECRET سلسلة سرية قوية (16+ محرف) — لا ترفعها لـ Git
 */
if (!defined('APP_LICENSE_ENFORCE')) {
    define('APP_LICENSE_ENFORCE', false);
}

if (!defined('APP_LICENSE_SECRET')) {
    define('APP_LICENSE_SECRET', 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET');
}

/**
 * ── OpenStreetMap / Geocoding (اختياري) ──
 *
 * Nominatim عام (مجاني — محدود):
 *   define('APP_OSM_NOMINATIM_URL', 'https://nominatim.openstreetmap.org/reverse');
 *
 * خرائط Google:
 *   define('APP_GOOGLE_MAPS_API_KEY', 'YOUR_GOOGLE_MAPS_KEY');
 */
// if (!defined('APP_OSM_CONTACT_EMAIL')) {
//     define('APP_OSM_CONTACT_EMAIL', 'admin@yourdomain.com');
// }
// if (!defined('APP_OSM_NOMINATIM_URL')) {
//     define('APP_OSM_NOMINATIM_URL', 'https://nominatim.openstreetmap.org/reverse');
// }
// if (!defined('APP_GOOGLE_MAPS_API_KEY')) {
//     define('APP_GOOGLE_MAPS_API_KEY', '');
// }
