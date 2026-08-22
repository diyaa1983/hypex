<?php
declare(strict_types=1);

require_once app_path('includes/sal_invoice_schema.php');

/**
 * كشف حركات المبيعات للموبايل — بنود فواتير مؤكدة + مواد نشطة فقط.
 *
 * @param array{from:string,to:string,customer_id?:int,item_id?:int,limit?:int} $filters
 * @return array{rows:list<array<string,mixed>>,totals:array{qty:float,amount:float,line_count:int}}
 */
function sal_mobile_sales_movement_report(PDO $pdo, array $filters): array
{
    $from = trim((string) ($filters['from'] ?? ''));
    $to = trim((string) ($filters['to'] ?? ''));
    if ($from === '' || $to === '') {
        return ['rows' => [], 'totals' => ['qty' => 0.0, 'amount' => 0.0, 'line_count' => 0]];
    }

    sal_invoice_ensure_schema($pdo);

    $customerId = max(0, (int) ($filters['customer_id'] ?? 0));
    $itemId = max(0, (int) ($filters['item_id'] ?? 0));
    $limit = max(50, min(3000, (int) ($filters['limit'] ?? 1500)));

    $where = [
        "i.status = 'confirmed'",
        'i.invoice_date >= ?',
        'i.invoice_date <= ?',
        'it.is_active = 1',
    ];
    $params = [$from, $to];
    if ($customerId > 0) {
        $where[] = 'i.customer_id = ?';
        $params[] = $customerId;
    }
    if ($itemId > 0) {
        $where[] = 'l.item_id = ?';
        $params[] = $itemId;
    }

    $hasSku = sal_invoice_column_exists($pdo, 'inv_item', 'sku');
    $skuExpr = $hasSku ? 'COALESCE(it.sku, it.code)' : 'it.code';

    $sql = 'SELECT i.id AS invoice_id, i.invoice_no, i.invoice_date,
                   c.id AS customer_id, c.code AS customer_code, c.name_ar AS customer_name,
                   it.id AS item_id, ' . $skuExpr . ' AS item_code, it.name_ar AS item_name,
                   l.qty, l.unit_price, l.line_total,
                   COALESCE(w.name_ar, \'\') AS warehouse_name
            FROM sal_invoice_line l
            INNER JOIN sal_invoice i ON i.id = l.invoice_id
            INNER JOIN inv_item it ON it.id = l.item_id AND it.is_active = 1
            INNER JOIN crm_customer c ON c.id = i.customer_id
            LEFT JOIN inv_warehouse w ON w.id = l.warehouse_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY i.invoice_date DESC, i.id DESC, l.id ASC
            LIMIT ' . $limit;

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $raw = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $rows = [];
    $totalQty = 0.0;
    $totalAmt = 0.0;
    foreach ($raw as $r) {
        $qty = (float) ($r['qty'] ?? 0);
        $amt = (float) ($r['line_total'] ?? 0);
        $totalQty += $qty;
        $totalAmt += $amt;
        $rows[] = [
            'invoice_id' => (int) ($r['invoice_id'] ?? 0),
            'invoice_no' => (string) ($r['invoice_no'] ?? ''),
            'invoice_date' => (string) ($r['invoice_date'] ?? ''),
            'customer_id' => (int) ($r['customer_id'] ?? 0),
            'customer_code' => (string) ($r['customer_code'] ?? ''),
            'customer_name' => (string) ($r['customer_name'] ?? ''),
            'item_id' => (int) ($r['item_id'] ?? 0),
            'item_code' => (string) ($r['item_code'] ?? ''),
            'item_name' => (string) ($r['item_name'] ?? ''),
            'qty' => $qty,
            'unit_price' => (float) ($r['unit_price'] ?? 0),
            'line_total' => $amt,
            'warehouse_name' => (string) ($r['warehouse_name'] ?? ''),
        ];
    }

    return [
        'rows' => $rows,
        'totals' => [
            'qty' => $totalQty,
            'amount' => $totalAmt,
            'line_count' => count($rows),
        ],
    ];
}
