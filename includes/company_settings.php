<?php
declare(strict_types=1);

require_once app_path('includes/invoice_amount_decimals.php');

/** @var array<string, mixed>|null */
$GLOBALS['_company_settings_cache'] = null;

/** @return array<string, mixed> */
function company_settings(?PDO $pdo = null): array
{
    if (is_array($GLOBALS['_company_settings_cache'])) {
        return $GLOBALS['_company_settings_cache'];
    }

    $defaults = [
        'company_name_ar' => 'الشركة',
        'tax_rate_percent' => 15.0,
        'decimal_places' => 2,
        'invoice_unit_price_decimal_places' => 2,
        'invoice_print_decimal_places' => 2,
        'invoice_print_unit_price_decimal_places' => 2,
        'rows_per_page' => 10,
        'ui_theme' => 'basic',
        'ui_lang' => 'ar',
        'logo_path' => null,
    ];

    try {
        $pdo = $pdo ?? db();
        company_settings_ensure_schema($pdo);
        company_settings_ensure_ui_theme_column($pdo);
        company_settings_ensure_ui_lang_column($pdo);
        $row = $pdo->query(
            'SELECT company_name_ar, tax_rate_percent, decimal_places, invoice_unit_price_decimal_places,
                    invoice_print_decimal_places, invoice_print_unit_price_decimal_places,
                    rows_per_page, ui_theme, ui_lang, logo_path FROM sys_company_settings WHERE id = 1 LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $defaults = array_merge($defaults, $row);
        }
    } catch (Throwable $e) {
        // DB غير مهيأ — جرّب بدون أعمدة الواجهة إن لم تُنشأ بعد
        try {
            $pdo = $pdo ?? db();
            $row = $pdo->query(
                'SELECT company_name_ar, tax_rate_percent, decimal_places, invoice_unit_price_decimal_places,
                        invoice_print_decimal_places, invoice_print_unit_price_decimal_places,
                        rows_per_page, logo_path FROM sys_company_settings WHERE id = 1 LIMIT 1'
            )->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $defaults = array_merge($defaults, $row);
            }
        } catch (Throwable $e2) {
            // ignore
        }
    }

    $defaults['decimal_places'] = invoice_amount_decimals_clamp((int) ($defaults['decimal_places'] ?? 2));
    $defaults['invoice_unit_price_decimal_places'] = invoice_amount_decimals_clamp(
        (int) ($defaults['invoice_unit_price_decimal_places'] ?? $defaults['decimal_places'])
    );
    $defaults['invoice_print_decimal_places'] = invoice_amount_decimals_clamp(
        (int) ($defaults['invoice_print_decimal_places'] ?? $defaults['decimal_places'])
    );
    $defaults['invoice_print_unit_price_decimal_places'] = invoice_amount_decimals_clamp(
        (int) ($defaults['invoice_print_unit_price_decimal_places']
            ?? $defaults['invoice_unit_price_decimal_places']
            ?? $defaults['decimal_places'])
    );
    $rpp = (int) ($defaults['rows_per_page'] ?? 10);
    $defaults['rows_per_page'] = in_array($rpp, [10, 15, 20], true) ? $rpp : 10;
    $defaults['ui_theme'] = company_ui_theme_normalize((string) ($defaults['ui_theme'] ?? 'classic'));
    $defaults['ui_lang'] = company_ui_lang_normalize((string) ($defaults['ui_lang'] ?? 'ar'));
    $GLOBALS['_company_settings_cache'] = $defaults;

    return $defaults;
}

function company_settings_clear_cache(): void
{
    $GLOBALS['_company_settings_cache'] = null;
}

/** @return 'classic'|'basic' */
function company_ui_theme_normalize(string $theme): string
{
    $theme = strtolower(trim($theme));
    // modern = اسم قديم لـ basic
    if ($theme === 'basic' || $theme === 'modern') {
        return 'basic';
    }

    return 'classic';
}

/** واجهة النظام من إعدادات الشركة: classic | basic. */
function company_ui_theme(?PDO $pdo = null): string
{
    return company_ui_theme_normalize((string) (company_settings($pdo)['ui_theme'] ?? 'classic'));
}

/** واجهة العرض الحالية — من الإعدادات فقط. @return 'classic'|'basic' */
function app_ui_theme(?PDO $pdo = null): string
{
    return company_ui_theme($pdo);
}

function user_ui_theme_ensure_column(PDO $pdo): void
{
    try {
        $pdo->query('SELECT ui_theme FROM sys_user LIMIT 1');
    } catch (Throwable $e) {
        if (strpos($e->getMessage(), 'Unknown column') === false) {
            return;
        }
        try {
            $pdo->exec(
                "ALTER TABLE sys_user
                 ADD COLUMN ui_theme VARCHAR(16) NOT NULL DEFAULT 'classic' AFTER is_active"
            );
            $def = company_ui_theme($pdo);
            $pdo->prepare('UPDATE sys_user SET ui_theme = ?')->execute([$def]);
        } catch (Throwable $e2) {
            // ignore
        }
    }
}

/** @return 'ar'|'en' */
function company_ui_lang_normalize(string $lang): string
{
    $lang = strtolower(trim($lang));

    return $lang === 'en' ? 'en' : 'ar';
}

/** @return 'ar'|'en' */
function company_ui_lang(?PDO $pdo = null): string
{
    return company_ui_lang_normalize((string) (company_settings($pdo)['ui_lang'] ?? 'ar'));
}

function company_decimal_places(?PDO $pdo = null): int
{
    return invoice_amount_decimals_clamp((int) company_settings($pdo)['decimal_places']);
}

/** خانات عشرية لسعر الوحدة في فواتير البيع/الشراء فقط. */
function company_invoice_unit_price_decimal_places(?PDO $pdo = null): int
{
    return invoice_amount_decimals_clamp((int) company_settings($pdo)['invoice_unit_price_decimal_places']);
}

/** خانات عشرية لمبالغ الفاتورة عند الطباعة فقط. */
function company_invoice_print_decimal_places(?PDO $pdo = null): int
{
    return invoice_amount_decimals_clamp((int) company_settings($pdo)['invoice_print_decimal_places']);
}

/** خانات عشرية لسعر الوحدة عند طباعة الفاتورة. */
function company_invoice_print_unit_price_decimal_places(?PDO $pdo = null): int
{
    return invoice_amount_decimals_clamp((int) company_settings($pdo)['invoice_print_unit_price_decimal_places']);
}

function company_invoice_unit_price_decimal_step(?PDO $pdo = null): string
{
    return company_decimal_step(company_invoice_unit_price_decimal_places($pdo));
}

function company_decimal_places_max(): int
{
    return invoice_amount_decimals_max();
}

function company_decimal_step(?int $decimals = null): string
{
    $dp = $decimals ?? company_decimal_places();
    if ($dp <= 0) {
        return '1';
    }

    return '0.' . str_repeat('0', $dp - 1) . '1';
}

function company_round_amount(float $n, ?PDO $pdo = null, ?int $decimals = null): float
{
    $dp = $decimals !== null
        ? invoice_amount_decimals_clamp($decimals)
        : company_decimal_places($pdo);

    return round($n, $dp);
}

/** تقريب سعر الوحدة فقط — حسب إعداد «السعر الافرادي في الفواتير». */
function company_round_unit_price(float $n, ?PDO $pdo = null): float
{
    return round($n, company_invoice_unit_price_decimal_places($pdo));
}

/** @param array<string, mixed> $row */
function company_round_invoice_header_array(array &$row, ?PDO $pdo = null, ?int $decimals = null): void
{
    if (isset($row['subtotal'])) {
        $row['subtotal'] = company_round_amount((float) $row['subtotal'], $pdo, $decimals);
    }
    if (isset($row['tax_amount'])) {
        $row['tax_amount'] = company_round_amount((float) $row['tax_amount'], $pdo, $decimals);
    }
    if (isset($row['total'])) {
        $row['total'] = company_round_amount((float) $row['total'], $pdo, $decimals);
    }
}

/** @param array<string, mixed> $ln */
function company_round_invoice_line_array(array &$ln, ?PDO $pdo = null, ?int $decimals = null): void
{
    $dp = $decimals !== null
        ? invoice_amount_decimals_clamp($decimals)
        : company_decimal_places($pdo);
    $storedUp = isset($ln['unit_price']) ? (float) $ln['unit_price'] : 0.0;
    $normalized = invoice_normalize_line_array($ln, $dp);
    $qty = (float) ($ln['qty'] ?? 0);
    if ($storedUp > 0) {
        $normalized['unit_price'] = company_round_unit_price($storedUp, $pdo);
    } elseif ($qty > 0 && isset($normalized['line_subtotal'])) {
        $normalized['unit_price'] = company_round_unit_price((float) $normalized['line_subtotal'] / $qty, $pdo);
    }
    foreach ($normalized as $k => $v) {
        $ln[$k] = $v;
    }
}

/** يضمن وجود السجل الافتراضي id=1 حتى يعمل UPDATE من شاشة الإعدادات. */
function company_settings_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        require_once app_path('includes/acc_coa_bootstrap.php');
        if (acc_coa_meta_get($pdo, 'company_settings_schema_v1') === '1') {
            return;
        }
    } catch (Throwable $e) {
        // continue
    }

    company_settings_ensure_default_row($pdo);
    company_settings_ensure_rows_per_page_column($pdo);
    company_settings_ensure_ui_theme_column($pdo);
    company_settings_ensure_invoice_unit_price_decimal_places_column($pdo);
    company_settings_ensure_invoice_print_decimal_places_columns($pdo);

    try {
        require_once app_path('includes/acc_coa_bootstrap.php');
        acc_coa_meta_set($pdo, 'company_settings_schema_v1', '1');
    } catch (Throwable $e) {
        // ignore
    }
}

function company_settings_ensure_rows_per_page_column(PDO $pdo): void
{
    try {
        $pdo->query('SELECT rows_per_page FROM sys_company_settings LIMIT 1');
    } catch (Throwable $e) {
        if (strpos($e->getMessage(), 'Unknown column') === false) {
            return;
        }
        try {
            $pdo->exec(
                'ALTER TABLE sys_company_settings
                 ADD COLUMN rows_per_page TINYINT UNSIGNED NOT NULL DEFAULT 10 AFTER decimal_places'
            );
        } catch (Throwable $e2) {
            // ignore
        }
    }
}

function company_settings_ensure_ui_theme_column(PDO $pdo): void
{
    try {
        $pdo->query('SELECT ui_theme FROM sys_company_settings LIMIT 1');
    } catch (Throwable $e) {
        if (strpos($e->getMessage(), 'Unknown column') === false) {
            return;
        }
        try {
            $pdo->exec(
                "ALTER TABLE sys_company_settings
                 ADD COLUMN ui_theme VARCHAR(16) NOT NULL DEFAULT 'classic' AFTER rows_per_page"
            );
        } catch (Throwable $e2) {
            // ignore
        }
    }
    try {
        $pdo->exec("UPDATE sys_company_settings SET ui_theme = 'basic' WHERE ui_theme = 'modern'");
    } catch (Throwable $e3) {
        // ignore
    }
}

function company_settings_ensure_ui_lang_column(PDO $pdo): void
{
    try {
        $pdo->query('SELECT ui_lang FROM sys_company_settings LIMIT 1');
    } catch (Throwable $e) {
        if (strpos($e->getMessage(), 'Unknown column') === false) {
            return;
        }
        try {
            $pdo->exec(
                "ALTER TABLE sys_company_settings
                 ADD COLUMN ui_lang VARCHAR(8) NOT NULL DEFAULT 'ar' AFTER ui_theme"
            );
        } catch (Throwable $e2) {
            try {
                $pdo->exec(
                    "ALTER TABLE sys_company_settings
                     ADD COLUMN ui_lang VARCHAR(8) NOT NULL DEFAULT 'ar' AFTER rows_per_page"
                );
            } catch (Throwable $e3) {
                // ignore
            }
        }
    }
}

function company_settings_ensure_invoice_unit_price_decimal_places_column(PDO $pdo): void
{
    try {
        $pdo->query('SELECT invoice_unit_price_decimal_places FROM sys_company_settings LIMIT 1');
    } catch (Throwable $e) {
        if (strpos($e->getMessage(), 'Unknown column') === false) {
            return;
        }
        try {
            $pdo->exec(
                'ALTER TABLE sys_company_settings
                 ADD COLUMN invoice_unit_price_decimal_places TINYINT UNSIGNED NOT NULL DEFAULT 2
                 AFTER decimal_places'
            );
            $pdo->exec(
                'UPDATE sys_company_settings
                 SET invoice_unit_price_decimal_places = decimal_places WHERE id = 1'
            );
        } catch (Throwable $e2) {
            // ignore
        }
    }
}

function company_settings_ensure_invoice_print_decimal_places_columns(PDO $pdo): void
{
    company_settings_ensure_invoice_unit_price_decimal_places_column($pdo);
    try {
        $pdo->query('SELECT invoice_print_decimal_places FROM sys_company_settings LIMIT 1');
    } catch (Throwable $e) {
        if (strpos($e->getMessage(), 'Unknown column') === false) {
            return;
        }
        try {
            $pdo->exec(
                'ALTER TABLE sys_company_settings
                 ADD COLUMN invoice_print_decimal_places TINYINT UNSIGNED NOT NULL DEFAULT 2
                 AFTER invoice_unit_price_decimal_places'
            );
            $pdo->exec(
                'ALTER TABLE sys_company_settings
                 ADD COLUMN invoice_print_unit_price_decimal_places TINYINT UNSIGNED NOT NULL DEFAULT 2
                 AFTER invoice_print_decimal_places'
            );
            $pdo->exec(
                'UPDATE sys_company_settings SET
                    invoice_print_decimal_places = decimal_places,
                    invoice_print_unit_price_decimal_places = invoice_unit_price_decimal_places
                 WHERE id = 1'
            );
        } catch (Throwable $e2) {
            // ignore
        }
    }
}

function company_settings_ensure_default_row(PDO $pdo): void
{
    try {
        $pdo->exec(
            "INSERT IGNORE INTO sys_company_settings (id, company_name_ar, tax_rate_percent, decimal_places)
             VALUES (1, 'اسم الشركة', 15.000, 2)"
        );
    } catch (Throwable $e) {
        // جدول غير موجود أو صلاحيات
    }
}

/** نوع MIME لملف مرفوع مؤقت (بديل إذا تعطّل امتداد fileinfo على الخادم). */
function company_settings_upload_mime(string $tmpPath): string
{
    if ($tmpPath === '' || !is_readable($tmpPath)) {
        return '';
    }
    if (class_exists('finfo')) {
        try {
            $f = new finfo(FILEINFO_MIME_TYPE);
            $m = $f->file($tmpPath);
            if (is_string($m) && $m !== '') {
                return $m;
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
    if (function_exists('mime_content_type')) {
        $m = @mime_content_type($tmpPath);
        if (is_string($m) && $m !== '') {
            return $m;
        }
    }
    $img = @getimagesize($tmpPath);

    return is_array($img) && !empty($img['mime']) ? (string) $img['mime'] : '';
}

/** رسالة قصيرة لرمز خطأ رفع الملفات في PHP. */
function company_settings_upload_error_message(int $code): string
{
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'حجم الملف يتجاوز الحد المسموح.';
        case UPLOAD_ERR_PARTIAL:
            return 'تم رفع الملف جزئيًا فقط.';
        case UPLOAD_ERR_NO_FILE:
            return '';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'مجلد الملفات المؤقتة غير متوفر على الخادم.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'تعذر كتابة الملف على القرص.';
        case UPLOAD_ERR_EXTENSION:
            return 'منع امتداد PHP لرفع هذا الملف.';
        default:
            return 'تعذر رفع الملف.';
    }
}
