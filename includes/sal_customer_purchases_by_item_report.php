<?php
declare(strict_types=1);

require_once app_path('includes/sal_invoice_schema.php');

/**
 * ملخص مشتريات العميل (مبيعات مؤكدة) مجمّعة حسب المادة.
 *
 * @return array{
 *   summary: list<array{
 *     item_id:int,
 *     item_sku:string,
 *     item_name:string,
 *     qty:float,
 *     line_total:float,
 *     line_gross:float,
 *     invoice_count:int
 *   }>,
 *   details: list<array{
 *     invoice_id:int,
 *     invoice_no:string,
 *     invoice_date:string,
 *     warehouse_name:string,
 *     item_sku:string,
 *     item_name:string,
 *     qty:float,
 *     unit_price:float,
 *     line_total:float,
 *     line_gross:float
 *   }>,
 *   totals: array{qty:float, line_total:float, line_gross:float, line_count:int, item_count:int}
 * }
 */
function sal_report_customer_purchases_by_item(
    PDO $pdo,
    int $customerId,
    string $from,
    string $to,
    int $warehouseId = 0
): array {
    $empty = [
        'summary' => [],
        'details' => [],
        'totals' => [
            'qty' => 0.0,
            'line_total' => 0.0,
            'line_gross' => 0.0,
            'line_count' => 0,
            'item_count' => 0,
        ],
    ];

    if ($customerId < 1 || $from === '' || $to === '') {
        return $empty;
    }

    sal_invoice_ensure_schema($pdo);

    $hasLineTax = sal_invoice_column_exists($pdo, 'sal_invoice_line', 'tax_rate_percent');
    $hasSku = sal_invoice_column_exists($pdo, 'inv_item', 'sku');
    $skuExpr = $hasSku ? 'COALESCE(it.sku, \'\')' : "''";

    $taxCols = $hasLineTax
        ? 'l.line_total, l.line_gross'
        : 'l.line_total, l.line_total AS line_gross';

    $whFilter = $warehouseId > 0 ? ' AND i.warehouse_id = ? ' : '';
    $params = [$customerId, $from, $to];
    if ($warehouseId > 0) {
        $params[] = $warehouseId;
    }

    $detailSql =
        "SELECT i.id AS invoice_id, i.invoice_no, i.invoice_date,
                COALESCE(w.name_ar, '') AS warehouse_name,
                {$skuExpr} AS item_sku,
                COALESCE(NULLIF(TRIM(l.line_desc), ''), it.name_ar, '') AS item_name,
                it.id AS item_id,
                l.qty, l.unit_price, {$taxCols}
         FROM sal_invoice_line l
         INNER JOIN sal_invoice i ON i.id = l.invoice_id
         INNER JOIN inv_item it ON it.id = l.item_id
         LEFT JOIN inv_warehouse w ON w.id = i.warehouse_id
         WHERE i.status = 'confirmed'
           AND i.customer_id = ?
           AND i.invoice_date >= ?
           AND i.invoice_date <= ?
           {$whFilter}
         ORDER BY it.name_ar ASC, i.invoice_date ASC, i.id ASC, l.id ASC";

    try {
        $st = $pdo->prepare($detailSql);
        $st->execute($params);
        $raw = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return $empty;
    }

    if ($raw === []) {
        return $empty;
    }

    /** @var array<int, array{item_id:int,item_sku:string,item_name:string,qty:float,line_total:float,line_gross:float,invoice_count:int,_invoices:array<int,bool>}> $summaryMap */
    $summaryMap = [];
    $details = [];
    $totQty = 0.0;
    $totLine = 0.0;
    $totGross = 0.0;

    foreach ($raw as $row) {
        $itemId = (int) ($row['item_id'] ?? 0);
        $qty = (float) ($row['qty'] ?? 0);
        $lineTotal = (float) ($row['line_total'] ?? 0);
        $lineGross = (float) ($row['line_gross'] ?? 0);
        $invoiceId = (int) ($row['invoice_id'] ?? 0);

        $details[] = [
            'invoice_id' => $invoiceId,
            'invoice_no' => (string) ($row['invoice_no'] ?? ''),
            'invoice_date' => (string) ($row['invoice_date'] ?? ''),
            'warehouse_name' => (string) ($row['warehouse_name'] ?? ''),
            'item_sku' => (string) ($row['item_sku'] ?? ''),
            'item_name' => (string) ($row['item_name'] ?? ''),
            'qty' => $qty,
            'unit_price' => (float) ($row['unit_price'] ?? 0),
            'line_total' => $lineTotal,
            'line_gross' => $lineGross,
        ];

        if (!isset($summaryMap[$itemId])) {
            $summaryMap[$itemId] = [
                'item_id' => $itemId,
                'item_sku' => (string) ($row['item_sku'] ?? ''),
                'item_name' => (string) ($row['item_name'] ?? ''),
                'qty' => 0.0,
                'line_total' => 0.0,
                'line_gross' => 0.0,
                'invoice_count' => 0,
                '_invoices' => [],
            ];
        }
        $summaryMap[$itemId]['qty'] += $qty;
        $summaryMap[$itemId]['line_total'] += $lineTotal;
        $summaryMap[$itemId]['line_gross'] += $lineGross;
        if ($invoiceId > 0 && !isset($summaryMap[$itemId]['_invoices'][$invoiceId])) {
            $summaryMap[$itemId]['_invoices'][$invoiceId] = true;
            $summaryMap[$itemId]['invoice_count']++;
        }

        $totQty += $qty;
        $totLine += $lineTotal;
        $totGross += $lineGross;
    }

    $summary = [];
    foreach ($summaryMap as $block) {
        unset($block['_invoices']);
        $summary[] = $block;
    }

    usort($summary, static function (array $a, array $b): int {
        return strcmp((string) $a['item_name'], (string) $b['item_name']);
    });

    return [
        'summary' => $summary,
        'details' => $details,
        'totals' => [
            'qty' => $totQty,
            'line_total' => $totLine,
            'line_gross' => $totGross,
            'line_count' => count($details),
            'item_count' => count($summary),
        ],
    ];
}
