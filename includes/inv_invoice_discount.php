<?php
declare(strict_types=1);

require_once app_path('includes/invoice_amount_decimals.php');

/**
 * تحليل حقل الخصم: نسبة إذا انتهى بـ % أو ٪، وإلا مبلغ.
 *
 * @return array{type:'percent'|'amount',value:float}|null
 */
function inv_discount_parse_input(?string $raw): ?array
{
    $s = trim((string) $raw);
    if ($s === '') {
        return null;
    }
    $s = preg_replace('/\s+/u', '', $s) ?? $s;
    $isPct = false;
    if (preg_match('/[%٪]$/u', $s)) {
        $isPct = true;
        $s = preg_replace('/[%٪]+$/u', '', $s) ?? $s;
    }
    $s = str_replace([',', '،'], ['', ''], $s);
    if ($s === '' || !is_numeric($s)) {
        return null;
    }
    $v = (float) $s;
    if ($v < 0) {
        return null;
    }
    if ($isPct) {
        return ['type' => 'percent', 'value' => min(100.0, $v)];
    }

    return ['type' => 'amount', 'value' => $v];
}

/**
 * خصم الفاتورة: رقم صحيح 1–100 بدون % يُفسَّر كنسبة؛ مبلغ بفاصلة عشرية (مثل 1.000).
 *
 * @return array{type:'percent'|'amount',value:float}|null
 */
function inv_discount_parse_header_input(?string $raw): ?array
{
    $s = trim((string) $raw);
    if ($s === '') {
        return null;
    }
    $compact = preg_replace('/\s+/u', '', $s) ?? $s;
    if (preg_match('/[%٪]$/u', $compact)) {
        return inv_discount_parse_input($raw);
    }
    if (preg_match('/[.,،]/u', $s)) {
        return inv_discount_parse_input($raw);
    }
    $parsed = inv_discount_parse_input($raw);
    if ($parsed === null || $parsed['type'] !== 'amount') {
        return $parsed;
    }
    $v = $parsed['value'];
    if ($v >= 1 && $v <= 100 && abs($v - round($v)) < 0.0001) {
        return ['type' => 'percent', 'value' => min(100.0, $v)];
    }

    return $parsed;
}

/** مبلغ خصم الفاتورة على أساس المجموع قبل الضريبة. */
function inv_discount_header_amount_for_base(float $lineBase, ?string $input, int $decimals = 2): float
{
    if ($lineBase <= 0) {
        return 0.0;
    }
    $dp = invoice_amount_decimals_clamp($decimals);
    $parsed = inv_discount_parse_header_input($input);
    if ($parsed === null) {
        return 0.0;
    }
    if ($parsed['type'] === 'percent') {
        return min($lineBase, round($lineBase * $parsed['value'] / 100, $dp));
    }

    return min($lineBase, round($parsed['value'], $dp));
}

/** إجمالي المادة قبل الضريبة (كمية × سعر الوحدة). */
function inv_invoice_line_merchandise_before_tax(array $ln, int $decimals): float
{
    $dp = invoice_amount_decimals_clamp($decimals);
    $upDp = invoice_line_unit_price_decimals(null);
    $qty = (float) ($ln['qty'] ?? 0);
    $up = round((float) ($ln['unit_price'] ?? 0), $upDp);

    return $qty > 0 ? round($qty * $up, $dp) : 0.0;
}

/** مبلغ الخصم على أساس السطر قبل الضريبة. */
function inv_discount_amount_for_base(
    float $lineBase,
    ?string $input,
    float $storedPct = 0.0,
    float $storedAmount = 0.0,
    int $decimals = 2
): float {
    if ($lineBase <= 0) {
        return 0.0;
    }
    $dp = invoice_amount_decimals_clamp($decimals);
    $parsed = inv_discount_parse_input($input);
    if ($parsed !== null) {
        if ($parsed['type'] === 'percent') {
            return min($lineBase, round($lineBase * $parsed['value'] / 100, $dp));
        }

        return min($lineBase, round($parsed['value'], $dp));
    }
    if ($storedAmount > 0.0000001) {
        return min($lineBase, round($storedAmount, $dp));
    }
    if ($storedPct > 0.0000001) {
        return min($lineBase, round($lineBase * $storedPct / 100, $dp));
    }

    return 0.0;
}

/**
 * توزيع مبلغ خصم على البنود تناسبياً مع تصحيح الفرق على آخر بند.
 *
 * @param list<float> $lineBases
 * @return list<float>
 */
function inv_discount_distribute_proportional(float $totalDiscount, array $lineBases, int $decimals): array
{
    $dp = invoice_amount_decimals_clamp($decimals);
    $n = count($lineBases);
    $out = array_fill(0, $n, 0.0);
    if ($n === 0 || $totalDiscount <= 0) {
        return $out;
    }
    $sumBase = 0.0;
    foreach ($lineBases as $b) {
        $sumBase += max(0.0, (float) $b);
    }
    if ($sumBase <= 0) {
        return $out;
    }
    $totalDiscount = min($totalDiscount, round($sumBase, $dp));
    $allocated = 0.0;
    $lastIdx = -1;
    for ($i = 0; $i < $n; $i++) {
        $base = max(0.0, (float) $lineBases[$i]);
        if ($base <= 0) {
            continue;
        }
        $lastIdx = $i;
        if ($i === $n - 1) {
            break;
        }
        $share = round($totalDiscount * ($base / $sumBase), $dp);
        $share = min($share, $base);
        $out[$i] = $share;
        $allocated += $share;
    }
    if ($lastIdx >= 0) {
        $out[$lastIdx] = round(max(0.0, $totalDiscount - $allocated), $dp);
        $cap = max(0.0, (float) $lineBases[$lastIdx]);
        if ($out[$lastIdx] > $cap) {
            $out[$lastIdx] = round($cap, $dp);
        }
    }

    return $out;
}

/**
 * خصم الفاتورة على مستوى الرأس: يوزّع على البنود ويُصفّر مدخلات خصم السطر.
 *
 * @param list<array<string, mixed>> $lines
 * @return list<array<string, mixed>>
 */
function inv_invoice_apply_header_discount(array $lines, ?string $headerInput, int $decimals): array
{
    $parsed = inv_discount_parse_input($headerInput);
    if ($parsed === null || $lines === []) {
        return $lines;
    }
    $dp = invoice_amount_decimals_clamp($decimals);
    $bases = [];
    $sumPreTax = 0.0;
    foreach ($lines as $ln) {
        $base = inv_invoice_line_merchandise_before_tax($ln, $dp);
        $bases[] = $base;
        $lineDisc = inv_discount_amount_for_base(
            $base,
            (string) ($ln['line_discount_input'] ?? ''),
            (float) ($ln['discount_pct'] ?? 0),
            (float) ($ln['discount_amount'] ?? 0),
            $dp
        );
        $sumPreTax += max(0.0, round($base - $lineDisc, $dp));
    }
    if ($sumPreTax <= 0) {
        $sumPreTax = array_sum($bases);
    }
    $totalDisc = inv_discount_header_amount_for_base($sumPreTax, $headerInput, $dp);
    $parts = inv_discount_distribute_proportional($totalDisc, $bases, $dp);
    foreach ($lines as $i => &$ln) {
        $ln['line_discount_input'] = '';
        $ln['discount_pct'] = 0;
        $ln['discount_amount'] = $parts[$i] ?? 0.0;
    }
    unset($ln);

    return $lines;
}

/** استنتاج نسبة/مبلغ للتخزين من المدخل النصي. */
function inv_discount_storage_from_input(?string $input, float $lineBase, float $discountAmount, int $decimals): array
{
    $dp = invoice_amount_decimals_clamp($decimals);
    $parsed = inv_discount_parse_input($input);
    if ($parsed !== null && $parsed['type'] === 'percent') {
        return ['discount_pct' => round($parsed['value'], 3), 'discount_amount' => round($discountAmount, $dp)];
    }

    return ['discount_pct' => 0.0, 'discount_amount' => round($discountAmount, $dp)];
}

/** نص الحقل في الواجهة: نسبة مع % أو مبلغ. */
function inv_discount_format_input_for_ui(float $discountPct, float $discountAmount, int $decimals): string
{
    if ($discountPct > 0.0000001) {
        $s = rtrim(rtrim(number_format($discountPct, 3, '.', ''), '0'), '.');

        return $s . '%';
    }
    if ($discountAmount > 0.0000001) {
        require_once app_path('includes/company_settings.php');
        $dp = invoice_amount_decimals_clamp($decimals);

        return number_format(round($discountAmount, $dp), $dp, '.', '');
    }

    return '';
}
