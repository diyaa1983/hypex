<?php
declare(strict_types=1);

/**
 * تواريخ افتراضية للفلاتر في الشاشات والتقارير.
 *
 * - تاريخ البداية: ثابت 2026-01-01 (بداية السنة المالية).
 * - تاريخ النهاية: تاريخ اليوم.
 *
 * أي تقرير/شاشة فيها فلتر "من تاريخ / إلى تاريخ" يجب أن يستخدم هاتين الدالتين
 * كافتراضي بدلاً من date('Y-m-01') أو date('Y-m-d') مباشرة.
 */

if (!defined('APP_DEFAULT_DATE_FROM')) {
    define('APP_DEFAULT_DATE_FROM', '2026-01-01');
}

/** تفعيل المنطقة الزمنية للتطبيق (مرة واحدة). */
function app_apply_timezone(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $tz = trim((string) (defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Asia/Amman'));
    if ($tz === '') {
        return;
    }

    try {
        new DateTimeZone($tz);
        date_default_timezone_set($tz);
    } catch (Throwable $e) {
        // ignored — يبقى إعداد PHP الافتراضي
    }
}

/**
 * مزامنة جلسة MySQL مع APP_TIMEZONE حتى تتطابق NOW() وعرض الأوقات.
 */
function app_mysql_apply_timezone(PDO $pdo): void
{
    static $applied = false;
    if ($applied) {
        return;
    }

    app_apply_timezone();
    $tzName = trim((string) (defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Asia/Amman'));
    if ($tzName === '') {
        return;
    }

    try {
        $pdo->exec('SET time_zone = ' . $pdo->quote($tzName));
        $applied = true;

        return;
    } catch (Throwable $e) {
        // قد لا تكون جداول المناطق الزمنية محمّلة في MySQL — نستخدم الإزاحة.
    }

    try {
        $dt = new DateTime('now', new DateTimeZone($tzName));
        $offsetSec = $dt->getOffset();
        $sign = $offsetSec >= 0 ? '+' : '-';
        $offsetSec = abs($offsetSec);
        $offset = sprintf(
            '%s%02d:%02d',
            $sign,
            intdiv($offsetSec, 3600),
            intdiv($offsetSec % 3600, 60)
        );
        $pdo->exec('SET time_zone = ' . $pdo->quote($offset));
        $applied = true;
    } catch (Throwable $e) {
        error_log('app_mysql_apply_timezone: ' . $e->getMessage());
    }
}

/** وقت محلي H:i من قيمة DATETIME مخزّنة. */
function app_format_time_hi(?string $datetime): string
{
    $datetime = trim((string) ($datetime ?? ''));
    if ($datetime === '') {
        return '';
    }
    app_apply_timezone();
    $ts = strtotime($datetime);

    return $ts !== false ? date('H:i', $ts) : '';
}

/** وقت محلي H:i:s من قيمة DATETIME مخزّنة. */
function app_format_time_his(?string $datetime): string
{
    $datetime = trim((string) ($datetime ?? ''));
    if ($datetime === '') {
        return '';
    }
    app_apply_timezone();
    $ts = strtotime($datetime);

    return $ts !== false ? date('H:i:s', $ts) : '';
}

/** تاريخ اليوم المحلي بصيغة Y-m-d (للمجلدات والفلاتر). */
function app_today_ymd(): string
{
    app_apply_timezone();

    return date('Y-m-d');
}

function app_default_date_from(): string
{
    return APP_DEFAULT_DATE_FROM;
}

function app_default_date_to(): string
{
    return app_today_ymd();
}

/** أول يوم في الشهر الحالي (Y-m-d). */
function app_default_month_from(): string
{
    app_apply_timezone();

    return date('Y-m-01');
}

/** آخر يوم في الشهر الحالي (Y-m-d). */
function app_default_month_to(): string
{
    app_apply_timezone();

    return date('Y-m-t');
}
