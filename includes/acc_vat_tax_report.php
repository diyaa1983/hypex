<?php
declare(strict_types=1);

require_once app_path('includes/sal_invoice_schema.php');
require_once app_path('includes/pur_invoice_schema.php');

/** @return 'sale'|'purchase' */
function acc_vat_tax_normalize_kind(string $kind): string
{
    $k = strtolower(trim($kind));
    if (in_array($k, ['purchase', 'purchases', 'شراء', 'مشتريات'], true)) {
        return 'purchase';
    }

    return 'sale';
}

function acc_vat_tax_is_combined_kind(string $kind): bool
{
    $k = strtolower(trim($kind));

    return in_array($k, ['both', 'all', 'الكل', 'مبيعات ومشتريات'], true);
}

/** نسبة الضريبة الفعلية على الفاتورة (من الأسطر أو من المبلغ الخاضع). */
function acc_vat_invoice_effective_tax_rate(float $subtotal, float $taxAmount, float $lineRateMax = 0.0, float $lineRateMin = 0.0): float
{
    if ($lineRateMax > 0 && abs($lineRateMax - $lineRateMin) < 0.001) {
        return round($lineRateMax, 3);
    }
    if ($subtotal > 0.000001 && abs($taxAmount) > 0.000001) {
        return round($taxAmount / $subtotal * 100, 3);
    }

    return $lineRateMax > 0 ? round($lineRateMax, 3) : 0.0;
}

/**
 * @param array<string, mixed> $row
 * @return array{doc_type:string, doc_type_label:string, doc_no:string, doc_date:string, party_name:string, subtotal:float, total:float, tax_amount:float, tax_rate_percent:float, source_no:string}
 */
function acc_vat_report_map_invoice_tax_row(array $row, string $docType, string $docTypeLabel): array
{
    $subtotal = (float) ($row['subtotal'] ?? 0);
    $taxAmount = (float) ($row['tax_amount'] ?? 0);
    $lineMax = (float) ($row['line_tax_rate_max'] ?? 0);
    $lineMin = (float) ($row['line_tax_rate_min'] ?? 0);

    return [
        'doc_type' => $docType,
        'doc_type_label' => $docTypeLabel,
        'doc_no' => (string) ($row['invoice_no'] ?? $row['doc_no'] ?? ''),
        'doc_date' => (string) ($row['invoice_date'] ?? $row['doc_date'] ?? ''),
        'party_name' => (string) ($row['party_name'] ?? ''),
        'subtotal' => $subtotal,
        'total' => (float) ($row['total'] ?? 0),
        'tax_amount' => $taxAmount,
        'tax_rate_percent' => acc_vat_invoice_effective_tax_rate($subtotal, $taxAmount, $lineMax, $lineMin),
        'source_no' => (string) ($row['source_no'] ?? ''),
    ];
}

/**
 * فواتير بيع مرحّلة محاسبياً — ضريبة فقط (بدون مردودات).
 *
 * @return list<array{doc_type:string, doc_type_label:string, doc_no:string, doc_date:string, party_name:string, subtotal:float, total:float, tax_amount:float, tax_rate_percent:float, source_no:string}>
 */
function acc_vat_report_sale_invoice_tax_lines(PDO $pdo, string $from, string $to): array
{
    if ($from === '' || $to === '') {
        return [];
    }

    sal_invoice_ensure_schema($pdo);

    $st = $pdo->prepare(
        "SELECT i.invoice_no, i.invoice_date, i.subtotal, i.total, i.tax_amount,
                COALESCE(c.name_ar, '') AS party_name,
                (SELECT MAX(l.tax_rate_percent) FROM sal_invoice_line l WHERE l.invoice_id = i.id) AS line_tax_rate_max,
                (SELECT MIN(l.tax_rate_percent) FROM sal_invoice_line l WHERE l.invoice_id = i.id) AS line_tax_rate_min
         FROM sal_invoice i
         LEFT JOIN crm_customer c ON c.id = i.customer_id
         WHERE i.status = 'confirmed'
           AND i.invoice_date >= ?
           AND i.invoice_date <= ?
           AND EXISTS (
               SELECT 1 FROM acc_journal_entry e
               WHERE e.ref_type = 'sale_invoice' AND e.ref_id = i.id AND e.source = 'auto'
           )
         ORDER BY i.invoice_date ASC, i.id ASC"
    );
    $st->execute([$from, $to]);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $out[] = acc_vat_report_map_invoice_tax_row($row, 'sale', 'مبيعات');
    }

    return $out;
}

/**
 * فواتير شراء مرحّلة ماليًا — ضريبة فقط (بدون مردودات).
 *
 * @return list<array{doc_type:string, doc_type_label:string, doc_no:string, doc_date:string, party_name:string, subtotal:float, total:float, tax_amount:float, tax_rate_percent:float, source_no:string}>
 */
function acc_vat_report_purchase_invoice_tax_lines(PDO $pdo, string $from, string $to): array
{
    if ($from === '' || $to === '') {
        return [];
    }

    pur_invoice_ensure_schema($pdo);

    $lineRateSql = pur_invoice_line_has_tax_columns($pdo)
        ? ', (SELECT MAX(l.tax_rate_percent) FROM pur_invoice_line l WHERE l.invoice_id = i.id) AS line_tax_rate_max,
             (SELECT MIN(l.tax_rate_percent) FROM pur_invoice_line l WHERE l.invoice_id = i.id) AS line_tax_rate_min'
        : ', 0 AS line_tax_rate_max, 0 AS line_tax_rate_min';

    $st = $pdo->prepare(
        "SELECT i.invoice_no, i.invoice_date, i.subtotal, i.total, i.tax_amount,
                COALESCE(s.name_ar, '') AS party_name
                {$lineRateSql}
         FROM pur_invoice i
         LEFT JOIN crm_supplier s ON s.id = i.supplier_id
         WHERE i.status = 'confirmed'
           AND i.invoice_date >= ?
           AND i.invoice_date <= ?
           AND EXISTS (
               SELECT 1 FROM crm_supplier_ledger l
               WHERE l.txn_type = 'purchase_invoice' AND l.ref_id = i.id
           )
         ORDER BY i.invoice_date ASC, i.id ASC"
    );
    $st->execute([$from, $to]);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $out[] = acc_vat_report_map_invoice_tax_row($row, 'purchase', 'مشتريات');
    }

    return $out;
}

/**
 * @return list<array<string, mixed>>
 */
function acc_vat_report_invoice_tax_lines(PDO $pdo, string $from, string $to, string $kind): array
{
    if (acc_vat_tax_is_combined_kind($kind)) {
        return array_merge(
            acc_vat_report_purchase_invoice_tax_lines($pdo, $from, $to),
            acc_vat_report_sale_invoice_tax_lines($pdo, $from, $to)
        );
    }

    $kind = acc_vat_tax_normalize_kind($kind);
    if ($kind === 'sale') {
        return acc_vat_report_sale_invoice_tax_lines($pdo, $from, $to);
    }

    return acc_vat_report_purchase_invoice_tax_lines($pdo, $from, $to);
}

/**
 * جميع فواتير المبيعات والمشتريات المرحّلة في الفترة (بدون مردودات).
 *
 * @return list<array<string, mixed>>
 */
function acc_vat_report_combined_invoice_tax_lines(PDO $pdo, string $from, string $to): array
{
    return acc_vat_report_invoice_tax_lines($pdo, $from, $to, 'both');
}

/**
 * @param list<array<string, mixed>> $rows
 * @return array{sale_tax:float, purchase_tax:float, sale_total:float, purchase_total:float, total_docs:int, sale_docs:int, purchase_docs:int}
 */
function acc_vat_report_combined_invoice_tax_totals(array $rows): array
{
    $saleTax = 0.0;
    $purTax = 0.0;
    $saleTotal = 0.0;
    $purTotal = 0.0;
    $saleDocs = 0;
    $purDocs = 0;
    foreach ($rows as $r) {
        $tax = (float) ($r['tax_amount'] ?? 0);
        $total = (float) ($r['total'] ?? 0);
        if (($r['doc_type'] ?? '') === 'purchase') {
            $purTax += $tax;
            $purTotal += $total;
            $purDocs++;
        } else {
            $saleTax += $tax;
            $saleTotal += $total;
            $saleDocs++;
        }
    }

    return [
        'sale_tax' => round($saleTax, 6),
        'purchase_tax' => round($purTax, 6),
        'sale_total' => round($saleTotal, 6),
        'purchase_total' => round($purTotal, 6),
        'total_docs' => count($rows),
        'sale_docs' => $saleDocs,
        'purchase_docs' => $purDocs,
    ];
}

/**
 * @return array{sale_tax:float, purchase_tax:float, net:float, total_docs:int}
 */
function acc_vat_report_invoice_tax_totals(array $rows, string $kind): array
{
    $saleTax = 0.0;
    $purTax = 0.0;
    foreach ($rows as $r) {
        $tax = (float) ($r['tax_amount'] ?? 0);
        if (($r['doc_type'] ?? '') === 'purchase') {
            $purTax += $tax;
        } else {
            $saleTax += $tax;
        }
    }

    $isBoth = acc_vat_tax_is_combined_kind($kind);
    $normalized = acc_vat_tax_normalize_kind($kind);
    $net = $isBoth ? round($saleTax - $purTax, 6) : ($normalized === 'sale' ? $saleTax : $purTax);

    return [
        'sale_tax' => round($saleTax, 6),
        'purchase_tax' => round($purTax, 6),
        'net' => round($net, 6),
        'total_docs' => count($rows),
    ];
}

/**
 * مردودات بيع مرحّلة محاسبياً.
 *
 * @return list<array<string, mixed>>
 */
function acc_vat_report_sale_return_tax_lines(PDO $pdo, string $from, string $to): array
{
    if ($from === '' || $to === '') {
        return [];
    }

    require_once app_path('includes/sal_return_schema.php');
    if (!sal_return_has_tables($pdo)) {
        return [];
    }

    $st = $pdo->prepare(
        "SELECT r.return_no, r.return_date, r.total, r.tax_amount,
                COALESCE(c.name_ar, '') AS party_name,
                COALESCE(i.invoice_no, '') AS source_no
         FROM sal_return r
         INNER JOIN sal_invoice i ON i.id = r.invoice_id
         LEFT JOIN crm_customer c ON c.id = r.customer_id
         WHERE r.status = 'confirmed'
           AND r.return_date >= ?
           AND r.return_date <= ?
           AND EXISTS (
               SELECT 1 FROM acc_journal_entry e
               WHERE e.ref_type = 'sale_return' AND e.ref_id = r.id AND e.source = 'auto'
           )
         ORDER BY r.return_date ASC, r.id ASC"
    );
    $st->execute([$from, $to]);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $out[] = [
            'doc_type' => 'sale_return',
            'doc_type_label' => 'مردود بيع',
            'doc_no' => (string) ($row['return_no'] ?? ''),
            'doc_date' => (string) ($row['return_date'] ?? ''),
            'party_name' => (string) ($row['party_name'] ?? ''),
            'total' => (float) ($row['total'] ?? 0),
            'tax_amount' => (float) ($row['tax_amount'] ?? 0),
            'source_no' => (string) ($row['source_no'] ?? ''),
        ];
    }

    return $out;
}

/**
 * مردودات شراء مرحّلة محاسبياً.
 *
 * @return list<array<string, mixed>>
 */
function acc_vat_report_purchase_return_tax_lines(PDO $pdo, string $from, string $to): array
{
    if ($from === '' || $to === '') {
        return [];
    }

    require_once app_path('includes/pur_return_schema.php');
    pur_invoice_ensure_schema($pdo);
    if (!pur_return_ensure_schema($pdo)) {
        return [];
    }

    $st = $pdo->prepare(
        "SELECT r.return_no, r.return_date, r.total, r.tax_amount,
                COALESCE(s.name_ar, '') AS party_name,
                COALESCE(i.invoice_no, '') AS source_no
         FROM pur_return r
         INNER JOIN pur_invoice i ON i.id = r.invoice_id
         LEFT JOIN crm_supplier s ON s.id = r.supplier_id
         WHERE r.status = 'confirmed'
           AND r.return_date >= ?
           AND r.return_date <= ?
           AND EXISTS (
               SELECT 1 FROM acc_journal_entry e
               WHERE e.ref_type = 'purchase_return' AND e.ref_id = r.id AND e.source = 'auto'
           )
         ORDER BY r.return_date ASC, r.id ASC"
    );
    $st->execute([$from, $to]);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $out[] = [
            'doc_type' => 'purchase_return',
            'doc_type_label' => 'مردود شراء',
            'doc_no' => (string) ($row['return_no'] ?? ''),
            'doc_date' => (string) ($row['return_date'] ?? ''),
            'party_name' => (string) ($row['party_name'] ?? ''),
            'total' => (float) ($row['total'] ?? 0),
            'tax_amount' => (float) ($row['tax_amount'] ?? 0),
            'source_no' => (string) ($row['source_no'] ?? ''),
        ];
    }

    return $out;
}

/**
 * @return list<array<string, mixed>>
 */
function acc_vat_report_return_tax_lines(PDO $pdo, string $from, string $to, string $kind): array
{
    $kind = acc_vat_tax_normalize_kind($kind);
    if ($kind === 'sale') {
        return acc_vat_report_sale_return_tax_lines($pdo, $from, $to);
    }
    if ($kind === 'purchase') {
        return acc_vat_report_purchase_return_tax_lines($pdo, $from, $to);
    }

    $rows = array_merge(
        acc_vat_report_sale_return_tax_lines($pdo, $from, $to),
        acc_vat_report_purchase_return_tax_lines($pdo, $from, $to)
    );
    usort($rows, static function (array $a, array $b): int {
        $da = (string) ($a['doc_date'] ?? '');
        $db = (string) ($b['doc_date'] ?? '');

        return $da <=> $db;
    });

    return $rows;
}

/**
 * @return array{sale_return_tax:float, purchase_return_tax:float, net:float, total_docs:int}
 */
function acc_vat_report_return_tax_totals(array $rows, string $kind): array
{
    $kind = acc_vat_tax_normalize_kind($kind);
    $saleRet = 0.0;
    $purRet = 0.0;
    foreach ($rows as $r) {
        $tax = (float) ($r['tax_amount'] ?? 0);
        if (($r['doc_type'] ?? '') === 'purchase_return') {
            $purRet += $tax;
        } else {
            $saleRet += $tax;
        }
    }

    $net = $kind === 'both' ? round($saleRet - $purRet, 6) : ($kind === 'sale' ? $saleRet : $purRet);

    return [
        'sale_return_tax' => round($saleRet, 6),
        'purchase_return_tax' => round($purRet, 6),
        'net' => round($net, 6),
        'total_docs' => count($rows),
    ];
}
