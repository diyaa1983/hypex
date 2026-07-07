<?php
declare(strict_types=1);

require_once app_path('includes/sal_invoice_schema.php');
require_once app_path('includes/inv_invoice_discount.php');
require_once app_path('includes/company_settings.php');

/**
 * بنود فواتير مبيعات مؤكّدة فيها خصم (على مستوى السطر) بين تاريخين.
 *
 * @return list<array{
 *   invoice_id:int,
 *   invoice_no:string,
 *   invoice_date:string,
 *   item_name:string,
 *   discount_pct:float,
 *   discount_amount:float
 * }>
 */
function sal_report_invoice_discount_lines(PDO $pdo, string $from, string $to): array
{
    if ($from === '' || $to === '') {
        return [];
    }

    sal_invoice_ensure_schema($pdo);

    $decimals = company_decimal_places($pdo);
    $hasDiscAmt = sal_invoice_column_exists($pdo, 'sal_invoice_line', 'discount_amount');
    $discFilter = $hasDiscAmt
        ? '(l.discount_pct > 0 OR l.discount_amount > 0.0000001)'
        : 'l.discount_pct > 0';

    $st = $pdo->prepare(
        "SELECT i.id AS invoice_id, i.invoice_no, i.invoice_date,
                l.qty, l.unit_price, l.discount_pct,
                " . ($hasDiscAmt ? 'l.discount_amount' : '0 AS discount_amount') . ",
                COALESCE(NULLIF(TRIM(l.line_desc), ''), it.name_ar) AS item_name
         FROM sal_invoice_line l
         INNER JOIN sal_invoice i ON i.id = l.invoice_id
         INNER JOIN inv_item it ON it.id = l.item_id
         WHERE i.status = 'confirmed'
           AND i.invoice_date >= ?
           AND i.invoice_date <= ?
           AND {$discFilter}
         ORDER BY i.invoice_date ASC, i.id ASC, l.id ASC"
    );
    $st->execute([$from, $to]);

    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $line = [
            'qty' => (float) ($row['qty'] ?? 0),
            'unit_price' => (float) ($row['unit_price'] ?? 0),
            'discount_pct' => (float) ($row['discount_pct'] ?? 0),
            'discount_amount' => (float) ($row['discount_amount'] ?? 0),
        ];
        $base = inv_invoice_line_merchandise_before_tax($line, $decimals);
        $discAmt = inv_discount_amount_for_base(
            $base,
            '',
            (float) ($row['discount_pct'] ?? 0),
            (float) ($row['discount_amount'] ?? 0),
            $decimals
        );
        if ($discAmt <= 0.0000001) {
            continue;
        }

        $discPct = (float) ($row['discount_pct'] ?? 0);
        if ($discPct <= 0.0000001 && $base > 0) {
            $discPct = round($discAmt / $base * 100, 3);
        }

        $rows[] = [
            'invoice_id' => (int) ($row['invoice_id'] ?? 0),
            'invoice_no' => (string) ($row['invoice_no'] ?? ''),
            'invoice_date' => (string) ($row['invoice_date'] ?? ''),
            'item_name' => (string) ($row['item_name'] ?? ''),
            'discount_pct' => $discPct,
            'discount_amount' => $discAmt,
        ];
    }

    return $rows;
}

/**
 * @param list<array{invoice_id:int,discount_amount:float}> $rows
 * @return array{grand_total:float, invoice_count:int, by_invoice:array<int,float>}
 */
function sal_report_invoice_discount_totals(array $rows): array
{
    $grand = 0.0;
    $byInvoice = [];
    foreach ($rows as $row) {
        $invId = (int) ($row['invoice_id'] ?? 0);
        $amt = (float) ($row['discount_amount'] ?? 0);
        $grand += $amt;
        if ($invId > 0) {
            $byInvoice[$invId] = ($byInvoice[$invId] ?? 0.0) + $amt;
        }
    }

    return [
        'grand_total' => round($grand, company_decimal_places()),
        'invoice_count' => count($byInvoice),
        'by_invoice' => $byInvoice,
    ];
}

/** @return string */
function sal_report_format_discount_pct(float $pct): string
{
    if ($pct <= 0.0000001) {
        return '—';
    }
    $s = rtrim(rtrim(number_format($pct, 3, '.', ''), '0'), '.');

    return $s . '%';
}
