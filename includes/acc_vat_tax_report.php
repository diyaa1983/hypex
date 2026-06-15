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

/**
 * فواتير بيع مرحّلة محاسبياً — ضريبة فقط (بدون مردودات).
 *
 * @return list<array{doc_type:string, doc_type_label:string, doc_no:string, doc_date:string, party_name:string, total:float, tax_amount:float, source_no:string}>
 */
function acc_vat_report_sale_invoice_tax_lines(PDO $pdo, string $from, string $to): array
{
    if ($from === '' || $to === '') {
        return [];
    }

    sal_invoice_ensure_schema($pdo);

    $st = $pdo->prepare(
        "SELECT i.invoice_no, i.invoice_date, i.total, i.tax_amount, COALESCE(c.name_ar, '') AS party_name
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
        $out[] = [
            'doc_type' => 'sale',
            'doc_type_label' => 'بيع',
            'doc_no' => (string) ($row['invoice_no'] ?? ''),
            'doc_date' => (string) ($row['invoice_date'] ?? ''),
            'party_name' => (string) ($row['party_name'] ?? ''),
            'total' => (float) ($row['total'] ?? 0),
            'tax_amount' => (float) ($row['tax_amount'] ?? 0),
            'source_no' => '',
        ];
    }

    return $out;
}

/**
 * فواتير شراء مرحّلة ماليًا — ضريبة فقط (بدون مردودات).
 *
 * @return list<array{doc_type:string, doc_type_label:string, doc_no:string, doc_date:string, party_name:string, total:float, tax_amount:float, source_no:string}>
 */
function acc_vat_report_purchase_invoice_tax_lines(PDO $pdo, string $from, string $to): array
{
    if ($from === '' || $to === '') {
        return [];
    }

    pur_invoice_ensure_schema($pdo);

    $st = $pdo->prepare(
        "SELECT i.invoice_no, i.invoice_date, i.total, i.tax_amount, COALESCE(s.name_ar, '') AS party_name
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
        $out[] = [
            'doc_type' => 'purchase',
            'doc_type_label' => 'شراء',
            'doc_no' => (string) ($row['invoice_no'] ?? ''),
            'doc_date' => (string) ($row['invoice_date'] ?? ''),
            'party_name' => (string) ($row['party_name'] ?? ''),
            'total' => (float) ($row['total'] ?? 0),
            'tax_amount' => (float) ($row['tax_amount'] ?? 0),
            'source_no' => '',
        ];
    }

    return $out;
}

/**
 * @return list<array<string, mixed>>
 */
function acc_vat_report_invoice_tax_lines(PDO $pdo, string $from, string $to, string $kind): array
{
    $kind = acc_vat_tax_normalize_kind($kind);
    if ($kind === 'sale') {
        return acc_vat_report_sale_invoice_tax_lines($pdo, $from, $to);
    }
    if ($kind === 'purchase') {
        return acc_vat_report_purchase_invoice_tax_lines($pdo, $from, $to);
    }

    $rows = array_merge(
        acc_vat_report_sale_invoice_tax_lines($pdo, $from, $to),
        acc_vat_report_purchase_invoice_tax_lines($pdo, $from, $to)
    );
    usort($rows, static function (array $a, array $b): int {
        $da = (string) ($a['doc_date'] ?? '');
        $db = (string) ($b['doc_date'] ?? '');
        if ($da === $db) {
            return strcmp((string) ($a['doc_no'] ?? ''), (string) ($b['doc_no'] ?? ''));
        }

        return $da <=> $db;
    });

    return $rows;
}

/**
 * @return array{sale_tax:float, purchase_tax:float, net:float, total_docs:int}
 */
function acc_vat_report_invoice_tax_totals(array $rows, string $kind): array
{
    $kind = acc_vat_tax_normalize_kind($kind);
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

    $net = $kind === 'both' ? round($saleTax - $purTax, 6) : ($kind === 'sale' ? $saleTax : $purTax);

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
