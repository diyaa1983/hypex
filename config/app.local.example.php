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

/**
 * الترخيص:
 * - فعّل APP_LICENSE_ENFORCE على السيرفر للإلزام برقم تفعيل.
 * - ضع APP_LICENSE_SECRET كسلسلة سرية قوية (لا تقل عن 16 محرفاً).
 */
if (!defined('APP_LICENSE_ENFORCE')) {
    define('APP_LICENSE_ENFORCE', false);
}

if (!defined('APP_LICENSE_SECRET')) {
    define('APP_LICENSE_SECRET', 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET');
}

/**
 * مزامنة البصمة (ZKT):
 * - auto   = تلقائي: Linux → وكيل ZKT | Windows/XAMPP → قراءة مباشرة من att2000.mdb
 * - remote = فرض وكيل ZKT (للاختبار على Windows)
 * - local  = فرض المزامنة المباشرة (للاختبار — لا يعمل على Linux بدون ملف mdb)
 */
// if (!defined('HR_ATT_SYNC_MODE')) {
//     define('HR_ATT_SYNC_MODE', 'auto');
// }

/**
 * ── OpenStreetMap / Geocoding (اختياري — بعد الاشتراك) ──
 *
 * 1) انسخ app.local.php إلى السيرفر إن لم يكن موجوداً.
 * 2) ضع بريدك في APP_OSM_CONTACT_EMAIL (مطلوب من سياسة OSM).
 * 3) إن كان لديك مفتاح API من المزود، ضعه في APP_OSM_NOMINATIM_API_KEY.
 *
 * أمثلة:
 *
 * Nominatim عام (مجاني — محدود):
 *   define('APP_OSM_NOMINATIM_URL', 'https://nominatim.openstreetmap.org/reverse');
 *
 * LocationIQ (مدفوع — مثال):
 *   define('APP_OSM_NOMINATIM_URL', 'https://us1.locationiq.com/v1/reverse?key={key}&lat={lat}&lon={lng}&format=json&accept-language=ar,en&addressdetails=1&namedetails=1');
 *   define('APP_OSM_NOMINATIM_API_KEY', 'YOUR_KEY');
 *
 * بلاطات الخريطة (MapTiler مثال):
 *   define('APP_OSM_TILE_URL', 'https://api.maptiler.com/maps/streets-v2/{z}/{x}/{y}.png?key=YOUR_KEY');
 */
// if (!defined('APP_OSM_CONTACT_EMAIL')) {
//     define('APP_OSM_CONTACT_EMAIL', 'admin@yourdomain.com');
// }
// if (!defined('APP_OSM_NOMINATIM_URL')) {
//     define('APP_OSM_NOMINATIM_URL', 'https://nominatim.openstreetmap.org/reverse');
// }
// if (!defined('APP_OSM_NOMINATIM_API_KEY')) {
//     define('APP_OSM_NOMINATIM_API_KEY', '');
// }
// if (!defined('APP_OSM_TILE_URL')) {
//     define('APP_OSM_TILE_URL', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png');
// }
