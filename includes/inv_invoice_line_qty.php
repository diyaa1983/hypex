<?php
declare(strict_types=1);

require_once app_path('includes/sal_invoice_schema.php');

/** ضمان عمود qty_extra على بنود فواتير البيع والشراء. */
function inv_invoice_line_ensure_qty_extra(PDO $pdo): void
{
    require_once app_path('includes/sql_migration.php');
    sql_migration_run_file($pdo, 'database/migrations/043_invoice_line_qty_extra.sql');

    foreach (['sal_invoice_line', 'pur_invoice_line'] as $table) {
        if (!sal_invoice_column_exists($pdo, $table, 'qty_extra')) {
            try {
                $pdo->exec(
                    "ALTER TABLE {$table} ADD COLUMN qty_extra DECIMAL(18,6) NOT NULL DEFAULT 0 AFTER qty"
                );
            } catch (Throwable $e) {
                // عمود موجود أو صلاحيات
            }
        }
    }
}

function inv_invoice_line_has_qty_extra(PDO $pdo, string $table): bool
{
    return sal_invoice_column_exists($pdo, $table, 'qty_extra');
}

/** مجموع الكمية المستودعية (الكمية + الكمية الإضافية). */
function inv_invoice_line_stock_qty_sum(float $qty, float $qtyExtra): float
{
    return max(0.0, (float) $qty) + max(0.0, (float) $qtyExtra);
}

/** شرط SQL: يوجد كمية مستودعية على السطر. */
function inv_invoice_line_sql_stock_positive(string $lineAlias = 'il'): string
{
    $lineAlias = preg_replace('/[^a-zA-Z0-9_]/', '', $lineAlias) ?: 'il';

    return "({$lineAlias}.qty + COALESCE({$lineAlias}.qty_extra, 0)) > 0.000001";
}

/** شرط SQL: يوجد كمية إضافية على السطر. */
function inv_invoice_line_sql_qty_extra_positive(string $lineAlias = 'il'): string
{
    $lineAlias = preg_replace('/[^a-zA-Z0-9_]/', '', $lineAlias) ?: 'il';

    return "COALESCE({$lineAlias}.qty_extra, 0) > 0.000001";
}

/** سعر صفر — تُعامل كمية السطر ككمية إضافية في التقارير. */
function inv_invoice_line_unit_price_is_zero(float $unitPrice): bool
{
    return $unitPrice <= 0.000001;
}

/** شرط SQL: سعر الوحدة صفر. */
function inv_invoice_line_sql_unit_price_zero(string $lineAlias = 'il'): string
{
    $lineAlias = preg_replace('/[^a-zA-Z0-9_]/', '', $lineAlias) ?: 'il';

    return "COALESCE({$lineAlias}.unit_price, 0) <= 0.000001";
}

/**
 * الكمية الإضافية المعروضة: qty_extra + الكمية عند سعر صفر.
 */
function inv_invoice_line_effective_qty_extra(float $qty, float $qtyExtra, float $unitPrice): float
{
    $extra = max(0.0, (float) $qtyExtra);
    if (inv_invoice_line_unit_price_is_zero($unitPrice)) {
        $extra += max(0.0, (float) $qty);
    }

    return $extra;
}

/** تعبير SQL للكمية الإضافية الفعلية (للتقارير). */
function inv_invoice_line_sql_effective_qty_extra(string $lineAlias = 'il'): string
{
    $lineAlias = preg_replace('/[^a-zA-Z0-9_]/', '', $lineAlias) ?: 'il';
    $zero = inv_invoice_line_sql_unit_price_zero($lineAlias);

    return "(COALESCE({$lineAlias}.qty_extra, 0)"
        . " + CASE WHEN {$zero} THEN COALESCE({$lineAlias}.qty, 0) ELSE 0 END)";
}

/** شرط SQL: كمية إضافية فعلية (حقل qty_extra أو سطر بسعر صفر). */
function inv_invoice_line_sql_effective_qty_extra_positive(string $lineAlias = 'il'): string
{
    return inv_invoice_line_sql_effective_qty_extra($lineAlias) . ' > 0.000001';
}

/** تطبيع qty_extra في مصفوفة البند قبل الحفظ. */
function inv_invoice_line_normalize_qty_extra(array $ln): array
{
    $ln['qty_extra'] = max(0.0, (float) ($ln['qty_extra'] ?? 0));

    return $ln;
}

/**
 * التحقق من بنود الفاتورة قبل الحفظ.
 *
 * @return string|null رسالة خطأ أو null عند النجاح
 */
function inv_invoice_validate_save_lines(array $lines, bool $allowEmpty): ?string
{
    if (count($lines) < 1) {
        return $allowEmpty ? null : 'أضف مادة واحدة على الأقل.';
    }

    foreach ($lines as $ln) {
        if (!is_array($ln)) {
            return 'بيانات الأسطر غير صالحة.';
        }
        $iid = (int) ($ln['item_id'] ?? 0);
        $qty = (float) ($ln['qty'] ?? 0);
        $qtyExtra = (float) ($ln['qty_extra'] ?? 0);
        $up = (float) ($ln['unit_price'] ?? 0);
        if ($iid < 1) {
            return 'تأكد من اختيار مادة لكل سطر.';
        }
        if (inv_invoice_line_stock_qty_sum($qty, $qtyExtra) <= 0) {
            return 'أدخل كمية لكل مادة في الفاتورة.';
        }
        if ($up < 0) {
            return 'تأكد من أسعار كل سطر.';
        }
    }

    return null;
}
