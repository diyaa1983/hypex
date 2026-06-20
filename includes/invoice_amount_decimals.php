<?php
declare(strict_types=1);

require_once app_path('includes/sal_invoice_schema.php');
require_once app_path('includes/inv_invoice_discount.php');

function invoice_amount_decimals_max(): int
{
    return 8;
}

function invoice_amount_decimals_clamp(int $dp): int
{
    if ($dp < 0) {
        return 0;
    }
    if ($dp > invoice_amount_decimals_max()) {
        return invoice_amount_decimals_max();
    }

    return $dp;
}

function invoice_amount_decimals_ensure_schema(PDO $pdo): void
{
    $tables = ['sal_invoice', 'pur_invoice', 'sal_return', 'pur_return'];
    foreach ($tables as $table) {
        if (!sal_invoice_column_exists($pdo, $table, 'amount_decimals')) {
            try {
                $pdo->exec(
                    "ALTER TABLE `{$table}` ADD COLUMN amount_decimals TINYINT UNSIGNED NOT NULL DEFAULT 2 AFTER total"
                );
            } catch (Throwable $e) {
                // ignore
            }
        }
    }

    try {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/038_invoice_amount_decimals.sql');
    } catch (Throwable $e) {
        // ignore — قد تكون الأعمدة موجودة
    }
}

/**
 * @return array{unit_price:float, sub:float, tax:float, gross:float}
 */
function invoice_line_amounts(
    float $qty,
    float $unitPrice,
    float $taxRatePercent,
    int $decimals,
    float $discountAmount = 0.0
): array {
    $dp = invoice_amount_decimals_clamp($decimals);
    $upDp = invoice_line_unit_price_decimals(null);
    $up = round($unitPrice, $upDp);
    $lineBase = round($qty * $up, $dp);
    $disc = min(max(0.0, $discountAmount), $lineBase);
    $sub = round($lineBase - $disc, $dp);
    $tax = round($sub * ($taxRatePercent / 100), $dp);
    $gross = round($sub + $tax, $dp);

    return [
        'unit_price' => $up,
        'line_base' => $lineBase,
        'discount' => $disc,
        'sub' => $sub,
        'tax' => $tax,
        'gross' => $gross,
    ];
}

/**
 * الإجمالي الشامل مُثبَّت — يُشتق قبل الضريبة والضريبة منه دون تغيير الإجمالي.
 *
 * @return array{unit_price:float, sub:float, tax:float, gross:float}
 */
function invoice_line_unit_price_decimals(?PDO $pdo = null): int
{
    require_once app_path('includes/company_settings.php');

    return invoice_amount_decimals_clamp(company_invoice_unit_price_decimal_places($pdo));
}

function invoice_line_amounts_from_gross(float $qty, float $gross, float $taxRatePercent, int $decimals): array
{
    $dp = invoice_amount_decimals_clamp($decimals);
    $upDp = invoice_line_unit_price_decimals(null);
    $grossR = round($gross, $dp);
    $factor = 1 + ($taxRatePercent / 100);
    $sub = $factor > 0 ? round($grossR / $factor, $dp) : $grossR;
    $tax = round($grossR - $sub, $dp);
    $up = $qty > 0 ? round($sub / $qty, $upDp) : 0.0;

    return [
        'unit_price' => $up,
        'sub' => $sub,
        'tax' => $tax,
        'gross' => $grossR,
    ];
}

/**
 * المجموع قبل الضريبة مُثبَّت.
 *
 * @return array{unit_price:float, sub:float, tax:float, gross:float}
 */
function invoice_line_amounts_from_sub(float $qty, float $sub, float $taxRatePercent, int $decimals): array
{
    $dp = invoice_amount_decimals_clamp($decimals);
    $upDp = invoice_line_unit_price_decimals(null);
    $subR = round($sub, $dp);
    $factor = 1 + ($taxRatePercent / 100);
    $gross = round($subR * $factor, $dp);
    $tax = round($gross - $subR, $dp);
    $up = $qty > 0 ? round($subR / $qty, $upDp) : 0.0;

    return [
        'unit_price' => $up,
        'sub' => $subR,
        'tax' => $tax,
        'gross' => $gross,
    ];
}

/**
 * اختيار طريقة الحساب مع الحفاظ على الإجمالي/قبل الضريبة المُدخل إن وُجد.
 *
 * @return array{unit_price:float, sub:float, tax:float, gross:float}
 */
function invoice_line_amounts_resolve(
    float $qty,
    float $unitPrice,
    float $taxRatePercent,
    int $decimals,
    ?float $grossStored = null,
    ?float $subStored = null,
    string $driver = ''
): array {
    $dp = invoice_amount_decimals_clamp($decimals);
    $tol = pow(10, -$dp) * 0.51;
    $driver = strtolower(trim($driver));

    if ($driver === 'gross' && $grossStored !== null && $grossStored >= 0) {
        return invoice_line_amounts_from_gross($qty, $grossStored, $taxRatePercent, $decimals);
    }
    if ($driver === 'subtotal' && $subStored !== null && $subStored >= 0) {
        return invoice_line_amounts_from_sub($qty, $subStored, $taxRatePercent, $decimals);
    }

    $calcUnit = invoice_line_amounts($qty, $unitPrice, $taxRatePercent, $decimals);
    if ($grossStored !== null && $grossStored > 0 && abs($grossStored - $calcUnit['gross']) >= $tol) {
        return invoice_line_amounts_from_gross($qty, $grossStored, $taxRatePercent, $decimals);
    }
    if ($subStored !== null && $subStored > 0 && abs($subStored - $calcUnit['sub']) >= $tol) {
        return invoice_line_amounts_from_sub($qty, $subStored, $taxRatePercent, $decimals);
    }

    return $calcUnit;
}

/** @return array{unit_price:float, sub:float, tax:float, gross:float} */
function invoice_line_amounts_gross_without_implicit_discount(
    float $qty,
    float $gross,
    float $taxRatePercent,
    int $decimals
): array {
    $fromGross = invoice_line_amounts_from_gross($qty, $gross, $taxRatePercent, $decimals);
    $upDp = invoice_line_unit_price_decimals(null);
    $up = $qty > 0 ? round($fromGross['sub'] / $qty, $upDp) : 0.0;

    return [
        'unit_price' => $up,
        'sub' => $fromGross['sub'],
        'tax' => round($fromGross['gross'] - $fromGross['sub'], invoice_amount_decimals_clamp($decimals)),
        'gross' => $fromGross['gross'],
    ];
}

/** @return array{unit_price:float, sub:float, tax:float, gross:float} */
function invoice_line_amounts_sub_without_implicit_discount(
    float $qty,
    float $sub,
    float $taxRatePercent,
    int $decimals
): array {
    $fromSub = invoice_line_amounts_from_sub($qty, $sub, $taxRatePercent, $decimals);
    $upDp = invoice_line_unit_price_decimals(null);
    $up = $qty > 0 ? round($fromSub['sub'] / $qty, $upDp) : 0.0;

    return [
        'unit_price' => $up,
        'sub' => $fromSub['sub'],
        'tax' => $fromSub['tax'],
        'gross' => $fromSub['gross'],
    ];
}

function invoice_line_has_explicit_discount(string $discInput, float $discountPct): bool
{
    require_once app_path('includes/inv_invoice_discount.php');

    return inv_discount_parse_input($discInput) !== null || $discountPct > 0.0000001;
}

/** @param array<string, mixed> $ln */
function invoice_normalize_line_array(array $ln, int $decimals): array
{
    $dp = invoice_amount_decimals_clamp($decimals);
    $upDp = invoice_line_unit_price_decimals(null);
    $qty = (float) ($ln['qty'] ?? 0);
    $rate = (float) ($ln['tax_rate_percent'] ?? 0);
    $subIn = (float) ($ln['line_subtotal'] ?? $ln['line_total'] ?? 0);
    $upIn = (float) ($ln['unit_price'] ?? 0);
    $grossIn = (float) ($ln['line_gross'] ?? 0);
    $driver = strtolower(trim((string) ($ln['amount_driver'] ?? '')));
    $discInput = (string) ($ln['line_discount_input'] ?? $ln['discount_input'] ?? '');
    $lineBase = inv_invoice_line_merchandise_before_tax($ln, $decimals);
    $hasExplicitDisc = invoice_line_has_explicit_discount(
        $discInput,
        (float) ($ln['discount_pct'] ?? 0)
    );
    $discAmt = inv_discount_amount_for_base(
        $lineBase,
        $discInput,
        (float) ($ln['discount_pct'] ?? 0),
        (float) ($ln['discount_amount'] ?? 0),
        $decimals
    );
    if (!$hasExplicitDisc) {
        $discAmt = 0.0;
    }
    $tol = pow(10, -$dp) * 0.51;

    if ($driver === 'gross' && $grossIn > 0) {
        if (!$hasExplicitDisc) {
            $calc = invoice_line_amounts_gross_without_implicit_discount($qty, $grossIn, $rate, $decimals);
        } else {
            $fromGross = invoice_line_amounts_from_gross($qty, $grossIn, $rate, $decimals);
            $discAmt = max($discAmt, max(0.0, round($lineBase - $fromGross['sub'], $dp)));
            $calc = invoice_line_amounts($qty, $upIn, $rate, $decimals, $discAmt);
        }
    } elseif ($driver === 'subtotal' && $subIn > 0) {
        if (!$hasExplicitDisc) {
            $calc = invoice_line_amounts_sub_without_implicit_discount($qty, $subIn, $rate, $decimals);
        } else {
            $discAmt = max($discAmt, max(0.0, round($lineBase - $subIn, $dp)));
            $calc = invoice_line_amounts($qty, $upIn, $rate, $decimals, $discAmt);
        }
    } else {
        $calc = invoice_line_amounts($qty, $upIn, $rate, $decimals, $discAmt);
        if (!$hasExplicitDisc) {
            if ($grossIn > 0 && abs($grossIn - $calc['gross']) >= $tol) {
                $calc = invoice_line_amounts_gross_without_implicit_discount($qty, $grossIn, $rate, $decimals);
            } elseif ($subIn > 0 && abs($subIn - $calc['sub']) >= $tol) {
                $calc = invoice_line_amounts_sub_without_implicit_discount($qty, $subIn, $rate, $decimals);
            }
        } elseif ($grossIn > 0 && abs($grossIn - $calc['gross']) >= $tol) {
            $fromGross = invoice_line_amounts_from_gross($qty, $grossIn, $rate, $decimals);
            $discAmt = max($discAmt, max(0.0, round($lineBase - $fromGross['sub'], $dp)));
            $calc = invoice_line_amounts($qty, $upIn, $rate, $decimals, $discAmt);
        } elseif ($subIn > 0 && abs($subIn - $calc['sub']) >= $tol) {
            $discAmt = max($discAmt, max(0.0, round($lineBase - $subIn, $dp)));
            $calc = invoice_line_amounts($qty, $upIn, $rate, $decimals, $discAmt);
        }
    }

    $stor = inv_discount_storage_from_input($discInput, $lineBase, $discAmt, $decimals);
    $ln['unit_price'] = $calc['unit_price'];
    $ln['line_subtotal'] = $calc['sub'];
    $ln['line_total'] = $calc['sub'];
    $ln['tax_amount'] = $calc['tax'];
    $ln['line_gross'] = $calc['gross'];
    $ln['discount_pct'] = $stor['discount_pct'];
    $ln['discount_amount'] = $stor['discount_amount'];

    require_once app_path('includes/inv_invoice_line_qty.php');

    return inv_invoice_line_normalize_qty_extra($ln);
}

/** @param list<array<string, mixed>> $lines */
function invoice_normalize_lines_array(array $lines, int $decimals): array
{
    $out = [];
    foreach ($lines as $ln) {
        if (!is_array($ln)) {
            continue;
        }
        $out[] = invoice_normalize_line_array($ln, $decimals);
    }

    return $out;
}

function sal_invoice_amount_decimals(PDO $pdo, int $invoiceId): int
{
    require_once app_path('includes/company_settings.php');
    if ($invoiceId < 1) {
        return invoice_amount_decimals_clamp(company_decimal_places($pdo));
    }
    invoice_amount_decimals_ensure_schema($pdo);
    if (!sal_invoice_column_exists($pdo, 'sal_invoice', 'amount_decimals')) {
        return invoice_amount_decimals_clamp(company_decimal_places($pdo));
    }
    $st = $pdo->prepare('SELECT amount_decimals FROM sal_invoice WHERE id = ? LIMIT 1');
    $st->execute([$invoiceId]);
    $v = $st->fetchColumn();
    if ($v === false) {
        return invoice_amount_decimals_clamp(company_decimal_places($pdo));
    }

    return invoice_amount_decimals_clamp((int) $v);
}

function pur_invoice_amount_decimals(PDO $pdo, int $invoiceId): int
{
    require_once app_path('includes/company_settings.php');
    if ($invoiceId < 1) {
        return invoice_amount_decimals_clamp(company_decimal_places($pdo));
    }
    invoice_amount_decimals_ensure_schema($pdo);
    if (!sal_invoice_column_exists($pdo, 'pur_invoice', 'amount_decimals')) {
        return invoice_amount_decimals_clamp(company_decimal_places($pdo));
    }
    $st = $pdo->prepare('SELECT amount_decimals FROM pur_invoice WHERE id = ? LIMIT 1');
    $st->execute([$invoiceId]);
    $v = $st->fetchColumn();
    if ($v === false) {
        return invoice_amount_decimals_clamp(company_decimal_places($pdo));
    }

    return invoice_amount_decimals_clamp((int) $v);
}

function sal_invoice_set_amount_decimals(PDO $pdo, int $invoiceId, int $decimals): void
{
    if ($invoiceId < 1 || !sal_invoice_column_exists($pdo, 'sal_invoice', 'amount_decimals')) {
        return;
    }
    $pdo->prepare('UPDATE sal_invoice SET amount_decimals = ? WHERE id = ?')->execute([
        invoice_amount_decimals_clamp($decimals),
        $invoiceId,
    ]);
}

function pur_invoice_set_amount_decimals(PDO $pdo, int $invoiceId, int $decimals): void
{
    if ($invoiceId < 1 || !sal_invoice_column_exists($pdo, 'pur_invoice', 'amount_decimals')) {
        return;
    }
    $pdo->prepare('UPDATE pur_invoice SET amount_decimals = ? WHERE id = ?')->execute([
        invoice_amount_decimals_clamp($decimals),
        $invoiceId,
    ]);
}

function sal_invoice_persist_normalized(PDO $pdo, int $invoiceId, int $decimals): void
{
    if ($invoiceId < 1) {
        return;
    }
    $dp = invoice_amount_decimals_clamp($decimals);
    $hasTax = sal_invoice_column_exists($pdo, 'sal_invoice_line', 'tax_rate_percent');
    $cols = 'id, qty, unit_price, line_total, discount_pct';
    if (sal_invoice_column_exists($pdo, 'sal_invoice_line', 'discount_amount')) {
        $cols .= ', discount_amount';
    }
    if ($hasTax) {
        $cols .= ', tax_rate_percent, tax_amount, line_gross';
    }
    $st = $pdo->prepare("SELECT {$cols} FROM sal_invoice_line WHERE invoice_id = ? ORDER BY id");
    $st->execute([$invoiceId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $sumSub = 0.0;
    $sumTax = 0.0;
    $sumGross = 0.0;

    $updTax = $hasTax
        ? $pdo->prepare(
            'UPDATE sal_invoice_line SET unit_price = ?, line_total = ?, tax_amount = ?, line_gross = ? WHERE id = ?'
        )
        : $pdo->prepare('UPDATE sal_invoice_line SET unit_price = ?, line_total = ? WHERE id = ?');

    foreach ($rows as $ln) {
        $rate = $hasTax ? (float) ($ln['tax_rate_percent'] ?? 0) : 0.0;
        $qty = (float) $ln['qty'];
        $up = (float) $ln['unit_price'];
        $upDp = invoice_line_unit_price_decimals(null);
        $lineBase = round($qty * round($up, $upDp), $dp);
        $discAmt = inv_discount_amount_for_base(
            $lineBase,
            null,
            (float) ($ln['discount_pct'] ?? 0),
            (float) ($ln['discount_amount'] ?? 0),
            $dp
        );
        $calc = invoice_line_amounts($qty, $up, $rate, $dp, $discAmt);
        if ($hasTax) {
            $updTax->execute([$calc['unit_price'], $calc['sub'], $calc['tax'], $calc['gross'], (int) $ln['id']]);
        } else {
            $updTax->execute([$calc['unit_price'], $calc['sub'], (int) $ln['id']]);
        }
        $sumSub += $calc['sub'];
        $sumTax += $calc['tax'];
        $sumGross += $calc['gross'];
    }

    $sumSub = round($sumSub, $dp);
    $sumTax = round($sumTax, $dp);
    $sumGross = round($sumGross, $dp);

    $pdo->prepare('UPDATE sal_invoice SET subtotal = ?, tax_amount = ?, total = ?, amount_decimals = ? WHERE id = ?')
        ->execute([$sumSub, $sumTax, $sumGross, $dp, $invoiceId]);
}

function pur_invoice_persist_normalized(PDO $pdo, int $invoiceId, int $decimals): void
{
    if ($invoiceId < 1) {
        return;
    }
    $dp = invoice_amount_decimals_clamp($decimals);
    $hasTax = pur_invoice_line_has_tax_columns($pdo);
    $cols = 'id, qty, unit_price, line_total, discount_pct';
    if (sal_invoice_column_exists($pdo, 'pur_invoice_line', 'discount_amount')) {
        $cols .= ', discount_amount';
    }
    if ($hasTax) {
        $cols .= ', tax_rate_percent, tax_amount, line_gross';
    }
    $st = $pdo->prepare("SELECT {$cols} FROM pur_invoice_line WHERE invoice_id = ? ORDER BY id");
    $st->execute([$invoiceId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $sumSub = 0.0;
    $sumTax = 0.0;
    $sumGross = 0.0;

    $updTax = $hasTax
        ? $pdo->prepare(
            'UPDATE pur_invoice_line SET unit_price = ?, line_total = ?, tax_amount = ?, line_gross = ? WHERE id = ?'
        )
        : $pdo->prepare('UPDATE pur_invoice_line SET unit_price = ?, line_total = ? WHERE id = ?');

    foreach ($rows as $ln) {
        $rate = $hasTax ? (float) ($ln['tax_rate_percent'] ?? 0) : 0.0;
        $qty = (float) $ln['qty'];
        $up = (float) $ln['unit_price'];
        $upDp = invoice_line_unit_price_decimals(null);
        $lineBase = round($qty * round($up, $upDp), $dp);
        $discAmt = inv_discount_amount_for_base(
            $lineBase,
            null,
            (float) ($ln['discount_pct'] ?? 0),
            (float) ($ln['discount_amount'] ?? 0),
            $dp
        );
        $calc = invoice_line_amounts($qty, $up, $rate, $dp, $discAmt);
        if ($hasTax) {
            $updTax->execute([$calc['unit_price'], $calc['sub'], $calc['tax'], $calc['gross'], (int) $ln['id']]);
        } else {
            $updTax->execute([$calc['unit_price'], $calc['sub'], (int) $ln['id']]);
        }
        $sumSub += $calc['sub'];
        $sumTax += $calc['tax'];
        $sumGross += $calc['gross'];
    }

    $sumSub = round($sumSub, $dp);
    $sumTax = round($sumTax, $dp);
    $sumGross = round($sumGross, $dp);

    $pdo->prepare('UPDATE pur_invoice SET subtotal = ?, tax_amount = ?, total = ?, amount_decimals = ? WHERE id = ?')
        ->execute([$sumSub, $sumTax, $sumGross, $dp, $invoiceId]);
}
