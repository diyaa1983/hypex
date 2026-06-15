<?php
declare(strict_types=1);

require_once app_path('includes/inv_item_barcode.php');

/**
 * تعبير SQL لرقم المادة (باركود إن وُجد وإلا SKU) — للاستخدام في SELECT.
 *
 * @param bool $digitsOnly إن true: أرقام فقط بدون أحرف (يتطلب REGEXP_REPLACE).
 */
function inv_item_sql_material_number(PDO $pdo, string $tableAlias, bool $digitsOnly = false): string
{
    $t = preg_replace('/[^a-zA-Z0-9_]/', '', $tableAlias);
    if ($t === '') {
        $t = 'it';
    }

    $skuExpr = "TRIM({$t}.sku)";
    if (!inv_item_has_barcode_column($pdo)) {
        return $digitsOnly ? inv_item_sql_digits_only_expr($skuExpr) : $skuExpr;
    }

    $barcodeExpr = "NULLIF(TRIM({$t}.barcode), '')";
    $fallback = "COALESCE({$barcodeExpr}, {$skuExpr})";

    if (!$digitsOnly) {
        return $fallback;
    }

    $bcDigits = inv_item_sql_digits_only_expr($barcodeExpr);
    $skuDigits = inv_item_sql_digits_only_expr($skuExpr);

    return "COALESCE({$bcDigits}, {$skuDigits}, {$fallback})";
}

/** إزالة غير الأرقام من تعبير SQL. */
function inv_item_sql_digits_only_expr(string $sqlExpr): string
{
    return "NULLIF(REGEXP_REPLACE(CAST({$sqlExpr} AS CHAR CHARACTER SET utf8mb4), '[^0-9]', ''), '')";
}

/** رقم المادة للعرض: الباركود إن وُجد وإلا رمز SKU الداخلي. */
function inv_item_material_number(?string $barcode, ?string $sku): string
{
    $bc = trim((string) $barcode);
    if ($bc !== '') {
        return $bc;
    }

    return trim((string) $sku);
}

/** تسمية المادة في القوائم (اسم — رقم) كما في فاتورة البيع. */
function inv_item_picker_label(?string $nameAr, ?string $barcode, ?string $sku): string
{
    $name = trim((string) $nameAr);
    $code = inv_item_material_number($barcode, $sku);
    if ($name === '') {
        return $code;
    }

    return $code !== '' ? $name . ' — ' . $code : $name;
}

/** رقم المادة للعرض والطباعة — أرقام فقط (بدون أحرف). */
function inv_item_material_number_digits(?string $barcode, ?string $sku): string
{
    $bc = trim((string) $barcode);
    if ($bc !== '') {
        $fromBc = preg_replace('/\D+/', '', $bc);
        if (is_string($fromBc) && $fromBc !== '') {
            return $fromBc;
        }
    }

    $fromSku = preg_replace('/\D+/', '', trim((string) $sku));

    return is_string($fromSku) ? $fromSku : '';
}
