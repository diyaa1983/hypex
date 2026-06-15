<?php
declare(strict_types=1);

/**
 * عملات النظام: المفتاح = الكود، القيم = اسم العملة بالعربية، اسم الفئة (الكسر)،
 * الرمز المختصر، عدد كسور الفئة (100 = هللة/قرش/سنت، 1000 = فلس).
 *
 * المفردات بصيغة التمييز المنصوب (مناسبة للتفقيط: «… ريالاً و… هللة»).
 *
 * @return array<string, array{code:string, name_ar:string, main_ar:string, fraction_ar:string, symbol:string, fraction_units:int}>
 */
function company_currency_catalog(): array
{
    return [
        'SAR' => ['code' => 'SAR', 'name_ar' => 'ريال سعودي', 'main_ar' => 'ريالاً سعودياً', 'fraction_ar' => 'هللة', 'symbol' => 'ر.س', 'fraction_units' => 100],
        'YER' => ['code' => 'YER', 'name_ar' => 'ريال يمني', 'main_ar' => 'ريالاً يمنياً', 'fraction_ar' => 'فلساً', 'symbol' => 'ر.ي', 'fraction_units' => 100],
        'AED' => ['code' => 'AED', 'name_ar' => 'درهم إماراتي', 'main_ar' => 'درهماً إماراتياً', 'fraction_ar' => 'فلساً', 'symbol' => 'د.إ', 'fraction_units' => 100],
        'KWD' => ['code' => 'KWD', 'name_ar' => 'دينار كويتي', 'main_ar' => 'ديناراً كويتياً', 'fraction_ar' => 'فلساً', 'symbol' => 'د.ك', 'fraction_units' => 1000],
        'JOD' => ['code' => 'JOD', 'name_ar' => 'دينار أردني', 'main_ar' => 'ديناراً', 'fraction_ar' => 'قرشاً', 'symbol' => 'د.أ', 'fraction_units' => 100],
        'IQD' => ['code' => 'IQD', 'name_ar' => 'دينار عراقي', 'main_ar' => 'ديناراً عراقياً', 'fraction_ar' => 'فلساً', 'symbol' => 'د.ع', 'fraction_units' => 1000],
        'BHD' => ['code' => 'BHD', 'name_ar' => 'دينار بحريني', 'main_ar' => 'ديناراً بحرينياً', 'fraction_ar' => 'فلساً', 'symbol' => 'د.ب', 'fraction_units' => 1000],
        'OMR' => ['code' => 'OMR', 'name_ar' => 'ريال عماني', 'main_ar' => 'ريالاً عمانياً', 'fraction_ar' => 'بيسة', 'symbol' => 'ر.ع', 'fraction_units' => 1000],
        'QAR' => ['code' => 'QAR', 'name_ar' => 'ريال قطري', 'main_ar' => 'ريالاً قطرياً', 'fraction_ar' => 'درهماً', 'symbol' => 'ر.ق', 'fraction_units' => 100],
        'EGP' => ['code' => 'EGP', 'name_ar' => 'جنيه مصري', 'main_ar' => 'جنيهاً مصرياً', 'fraction_ar' => 'قرشاً', 'symbol' => 'ج.م', 'fraction_units' => 100],
        'SDG' => ['code' => 'SDG', 'name_ar' => 'جنيه سوداني', 'main_ar' => 'جنيهاً سودانياً', 'fraction_ar' => 'قرشاً', 'symbol' => 'ج.س', 'fraction_units' => 100],
        'LYD' => ['code' => 'LYD', 'name_ar' => 'دينار ليبي', 'main_ar' => 'ديناراً ليبياً', 'fraction_ar' => 'درهماً', 'symbol' => 'د.ل', 'fraction_units' => 1000],
        'DZD' => ['code' => 'DZD', 'name_ar' => 'دينار جزائري', 'main_ar' => 'ديناراً جزائرياً', 'fraction_ar' => 'سنتيماً', 'symbol' => 'د.ج', 'fraction_units' => 100],
        'MAD' => ['code' => 'MAD', 'name_ar' => 'درهم مغربي', 'main_ar' => 'درهماً مغربياً', 'fraction_ar' => 'سنتيماً', 'symbol' => 'د.م', 'fraction_units' => 100],
        'TND' => ['code' => 'TND', 'name_ar' => 'دينار تونسي', 'main_ar' => 'ديناراً تونسياً', 'fraction_ar' => 'مليماً', 'symbol' => 'د.ت', 'fraction_units' => 1000],
        'SYP' => ['code' => 'SYP', 'name_ar' => 'ليرة سورية', 'main_ar' => 'ليرة سورية', 'fraction_ar' => 'قرشاً', 'symbol' => 'ل.س', 'fraction_units' => 100],
        'LBP' => ['code' => 'LBP', 'name_ar' => 'ليرة لبنانية', 'main_ar' => 'ليرة لبنانية', 'fraction_ar' => 'قرشاً', 'symbol' => 'ل.ل', 'fraction_units' => 100],
        'USD' => ['code' => 'USD', 'name_ar' => 'دولار أمريكي', 'main_ar' => 'دولاراً أمريكياً', 'fraction_ar' => 'سنتاً', 'symbol' => '$', 'fraction_units' => 100],
        'EUR' => ['code' => 'EUR', 'name_ar' => 'يورو', 'main_ar' => 'يورو', 'fraction_ar' => 'سنتاً', 'symbol' => '€', 'fraction_units' => 100],
        'GBP' => ['code' => 'GBP', 'name_ar' => 'جنيه إسترليني', 'main_ar' => 'جنيهاً إسترلينياً', 'fraction_ar' => 'بنساً', 'symbol' => '£', 'fraction_units' => 100],
        'TRY' => ['code' => 'TRY', 'name_ar' => 'ليرة تركية', 'main_ar' => 'ليرة تركية', 'fraction_ar' => 'قرشاً', 'symbol' => '₺', 'fraction_units' => 100],
    ];
}

function company_currency_is_valid(string $code): bool
{
    return isset(company_currency_catalog()[strtoupper($code)]);
}

/**
 * يضمن وجود عمود currency_code في sys_company_settings.
 */
function company_settings_ensure_currency_column(PDO $pdo): void
{
    try {
        $pdo->query('SELECT currency_code FROM sys_company_settings LIMIT 1');
    } catch (Throwable $e) {
        if (strpos($e->getMessage(), 'Unknown column') === false) {
            return;
        }
        try {
            $pdo->exec(
                "ALTER TABLE sys_company_settings
                 ADD COLUMN currency_code VARCHAR(8) NOT NULL DEFAULT 'SAR'"
            );
        } catch (Throwable $e2) {
            require_once app_path('includes/sql_migration.php');
            sql_migration_run_file($pdo, 'database/migrations/057_company_currency.sql');
        }
    }
}

/**
 * يقرأ كود العملة الحالي من إعدادات الشركة.
 */
function company_currency_code(?PDO $pdo = null): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $pdo = $pdo ?? db();
        company_settings_ensure_currency_column($pdo);
        $val = $pdo->query('SELECT currency_code FROM sys_company_settings WHERE id = 1 LIMIT 1')->fetchColumn();
        $code = is_string($val) ? strtoupper(trim($val)) : '';
        if ($code !== '' && company_currency_is_valid($code)) {
            $cached = $code;

            return $cached;
        }
    } catch (Throwable $e) {
        // قاعدة غير مهيأة
    }
    $cached = 'SAR';

    return $cached;
}

/**
 * @return array{code:string, name_ar:string, main_ar:string, fraction_ar:string, symbol:string, fraction_units:int}
 */
function company_currency(?PDO $pdo = null): array
{
    $code = company_currency_code($pdo);
    $cat = company_currency_catalog();

    return $cat[$code] ?? $cat['SAR'];
}

function company_currency_reset_cache(): void
{
    // إعادة قراءة العملة عند تغيير الإعدادات (يُستدعى ضمنياً بعد UPDATE)
    // ملاحظة: static $cached محصور بداخل دالة company_currency_code، لذا نُجبر إعادة الحمل في الطلب التالي.
}
