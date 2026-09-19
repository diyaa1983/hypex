<?php
declare(strict_types=1);

require_once app_path('includes/sal_invoice_schema.php');

/**
 * بنود فواتير مبيعات مؤكدة للعميل بين تاريخين، لمادة محددة أو جميع المواد.
 *
 * @return list<array<string, mixed>>
 */
function sal_report_sales_by_item(
    PDO $pdo,
    int $customerId,
    int $itemId,
    string $from,
    string $to
): array {
    if ($customerId < 0 || $from === '' || $to === '' || $itemId < 0) {
        return [];
    }

    sal_invoice_ensure_schema($pdo);

    $hasLineTax = sal_invoice_column_exists($pdo, 'sal_invoice_line', 'tax_rate_percent');

    $customerFilter = $customerId > 0 ? ' AND i.customer_id = ? ' : '';
    $itemFilter = $itemId > 0 ? ' AND l.item_id = ? ' : '';
    $params = [];
    if ($customerId > 0) {
        $params[] = $customerId;
    }
    $params[] = $from;
    $params[] = $to;
    if ($itemId > 0) {
        $params[] = $itemId;
    }

    $taxCols = $hasLineTax
        ? 'l.tax_rate_percent, l.tax_amount, l.line_gross'
        : '0 AS tax_rate_percent, 0 AS tax_amount, l.line_total AS line_gross';

    $st = $pdo->prepare(
        "SELECT i.id AS invoice_id, i.invoice_no, i.invoice_date,
                l.qty, l.unit_price, l.line_total, {$taxCols},
                COALESCE(NULLIF(TRIM(l.line_desc), ''), it.name_ar) AS item_name
         FROM sal_invoice_line l
         INNER JOIN sal_invoice i ON i.id = l.invoice_id
         INNER JOIN inv_item it ON it.id = l.item_id
         WHERE i.status = 'confirmed'
           AND i.invoice_date >= ?
           AND i.invoice_date <= ?
           {$customerFilter}
           {$itemFilter}
         ORDER BY i.invoice_date ASC, i.id ASC, l.id ASC"
    );
    $st->execute($params);

    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $qty = (float) ($row['qty'] ?? 0);
        $unitExcl = (float) ($row['unit_price'] ?? 0);
        $taxRate = (float) ($row['tax_rate_percent'] ?? 0);
        $lineGross = (float) ($row['line_gross'] ?? 0);

        if ($qty > 0) {
            $unitIncl = round($lineGross / $qty, 6);
        } else {
            $unitIncl = round($unitExcl * (1 + $taxRate / 100), 6);
        }

        $rows[] = [
            'invoice_id' => (int) ($row['invoice_id'] ?? 0),
            'invoice_no' => (string) ($row['invoice_no'] ?? ''),
            'invoice_date' => (string) ($row['invoice_date'] ?? ''),
            'item_name' => (string) ($row['item_name'] ?? ''),
            'unit_price_excl' => $unitExcl,
            'unit_price_incl' => $unitIncl,
        ];
    }

    return $rows;
}
