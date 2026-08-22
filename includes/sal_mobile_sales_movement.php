<?php
declare(strict_types=1);

require_once app_path('includes/sal_invoice_schema.php');
require_once app_path('includes/sal_customer_order.php');
require_once app_path('includes/crm_sales_rep_schema.php');

/**
 * كشف حركات المبيعات للموبايل:
 * - بنود فواتير مبيعات مؤكدة
 * - بنود طلبات شراء معتمدة (حتى قبل ترحيل Oracle)
 * مواد نشطة فقط.
 *
 * @param array{
 *   from:string,
 *   to:string,
 *   customer_id?:int,
 *   item_id?:int,
 *   sales_rep_id?:int|null,
 *   limit?:int
 * } $filters
 * @return array{
 *   rows:list<array<string,mixed>>,
 *   totals:array{qty:float,amount:float,line_count:int,invoice_lines:int,order_lines:int}
 * }
 */
function sal_mobile_sales_movement_report(PDO $pdo, array $filters): array
{
    $from = trim((string) ($filters['from'] ?? ''));
    $to = trim((string) ($filters['to'] ?? ''));
    $emptyTotals = [
        'qty' => 0.0,
        'amount' => 0.0,
        'line_count' => 0,
        'invoice_lines' => 0,
        'order_lines' => 0,
    ];
    if ($from === '' || $to === '') {
        return ['rows' => [], 'totals' => $emptyTotals];
    }

    sal_invoice_ensure_schema($pdo);
    sal_customer_order_ensure_schema($pdo);
    crm_sales_rep_ensure_schema($pdo);

    $customerId = max(0, (int) ($filters['customer_id'] ?? 0));
    $itemId = max(0, (int) ($filters['item_id'] ?? 0));
    $limit = max(50, min(3000, (int) ($filters['limit'] ?? 1500)));
    $salesRepId = array_key_exists('sales_rep_id', $filters)
        ? ($filters['sales_rep_id'] === null ? null : max(0, (int) $filters['sales_rep_id']))
        : null;

    $hasSku = sal_invoice_column_exists($pdo, 'inv_item', 'sku');
    $skuExpr = $hasSku ? 'COALESCE(it.sku, \'\')' : "''";
    $hasOrderPricing = sal_customer_order_has_pricing($pdo);
    $orderAmountExpr = $hasOrderPricing
        ? 'COALESCE(l.line_gross, l.line_total, 0)'
        : '0';
    $orderPriceExpr = $hasOrderPricing ? 'COALESCE(l.unit_price, 0)' : '0';

    $invoiceRows = sal_mobile_sales_movement_invoice_rows(
        $pdo,
        $from,
        $to,
        $customerId,
        $itemId,
        $salesRepId,
        $skuExpr,
        $limit
    );
    $orderRows = sal_mobile_sales_movement_order_rows(
        $pdo,
        $from,
        $to,
        $customerId,
        $itemId,
        $salesRepId,
        $skuExpr,
        $orderAmountExpr,
        $orderPriceExpr,
        $limit
    );

    $merged = array_merge($invoiceRows, $orderRows);
    usort($merged, static function (array $a, array $b): int {
        $da = (string) ($a['invoice_date'] ?? '');
        $db = (string) ($b['invoice_date'] ?? '');
        if ($da !== $db) {
            return strcmp($db, $da);
        }
        $na = (string) ($a['invoice_no'] ?? '');
        $nb = (string) ($b['invoice_no'] ?? '');
        if ($na !== $nb) {
            return strcmp($nb, $na);
        }

        return strcmp((string) ($a['item_name'] ?? ''), (string) ($b['item_name'] ?? ''));
    });
    if (count($merged) > $limit) {
        $merged = array_slice($merged, 0, $limit);
    }

    $totalQty = 0.0;
    $totalAmt = 0.0;
    $invoiceLines = 0;
    $orderLines = 0;
    foreach ($merged as $row) {
        $totalQty += (float) ($row['qty'] ?? 0);
        $totalAmt += (float) ($row['line_total'] ?? 0);
        if (($row['source'] ?? '') === 'order') {
            $orderLines++;
        } else {
            $invoiceLines++;
        }
    }

    return [
        'rows' => $merged,
        'totals' => [
            'qty' => $totalQty,
            'amount' => $totalAmt,
            'line_count' => count($merged),
            'invoice_lines' => $invoiceLines,
            'order_lines' => $orderLines,
        ],
    ];
}

/**
 * @return list<array<string,mixed>>
 */
function sal_mobile_sales_movement_invoice_rows(
    PDO $pdo,
    string $from,
    string $to,
    int $customerId,
    int $itemId,
    ?int $salesRepId,
    string $skuExpr,
    int $limit
): array {
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
    if ($salesRepId !== null && $salesRepId > 0) {
        [$linkSql, $linkParams] = crm_customer_sql_linked_to_rep($pdo, 'c', $salesRepId);
        $where[] = '(COALESCE(i.sales_rep_id, c.sales_rep_id) = ? OR ' . $linkSql . ')';
        $params[] = $salesRepId;
        $params = array_merge($params, $linkParams);
    }

    $sql = 'SELECT i.id AS invoice_id, i.invoice_no, i.invoice_date,
                   c.id AS customer_id, c.code AS customer_code, c.name_ar AS customer_name,
                   it.id AS item_id, ' . $skuExpr . ' AS item_code, it.name_ar AS item_name,
                   l.qty, l.unit_price, l.line_total,
                   COALESCE(w.name_ar, \'\') AS warehouse_name
            FROM sal_invoice_line l
            INNER JOIN sal_invoice i ON i.id = l.invoice_id
            INNER JOIN inv_item it ON it.id = l.item_id AND it.is_active = 1
            INNER JOIN crm_customer c ON c.id = i.customer_id
            LEFT JOIN inv_warehouse w ON w.id = i.warehouse_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY i.invoice_date DESC, i.id DESC, l.id ASC
            LIMIT ' . $limit;

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $raw = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return sal_mobile_sales_movement_map_rows($raw, 'invoice');
}

/**
 * @return list<array<string,mixed>>
 */
function sal_mobile_sales_movement_order_rows(
    PDO $pdo,
    string $from,
    string $to,
    int $customerId,
    int $itemId,
    ?int $salesRepId,
    string $skuExpr,
    string $orderAmountExpr,
    string $orderPriceExpr,
    int $limit
): array {
    $where = [
        "o.status = 'approved'",
        'o.order_date >= ?',
        'o.order_date <= ?',
        'it.is_active = 1',
    ];
    $params = [$from, $to];
    if ($customerId > 0) {
        $where[] = 'o.customer_id = ?';
        $params[] = $customerId;
    }
    if ($itemId > 0) {
        $where[] = 'l.item_id = ?';
        $params[] = $itemId;
    }
    if ($salesRepId !== null && $salesRepId > 0) {
        [$linkSql, $linkParams] = crm_customer_sql_linked_to_rep($pdo, 'c', $salesRepId);
        $where[] = '(COALESCE(o.sales_rep_id, c.sales_rep_id) = ? OR ' . $linkSql . ')';
        $params[] = $salesRepId;
        $params = array_merge($params, $linkParams);
    }

    $sql = 'SELECT o.id AS invoice_id, o.order_no AS invoice_no, o.order_date AS invoice_date,
                   c.id AS customer_id, c.code AS customer_code, c.name_ar AS customer_name,
                   it.id AS item_id, ' . $skuExpr . ' AS item_code, it.name_ar AS item_name,
                   l.qty, ' . $orderPriceExpr . ' AS unit_price, ' . $orderAmountExpr . ' AS line_total,
                   COALESCE(w.name_ar, \'\') AS warehouse_name
            FROM sal_customer_order_line l
            INNER JOIN sal_customer_order o ON o.id = l.order_id
            INNER JOIN inv_item it ON it.id = l.item_id AND it.is_active = 1
            INNER JOIN crm_customer c ON c.id = o.customer_id
            LEFT JOIN inv_warehouse w ON w.id = o.warehouse_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY o.order_date DESC, o.id DESC, l.id ASC
            LIMIT ' . $limit;

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $raw = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return sal_mobile_sales_movement_map_rows($raw, 'order');
}

/**
 * @param list<array<string,mixed>> $raw
 * @return list<array<string,mixed>>
 */
function sal_mobile_sales_movement_map_rows(array $raw, string $source): array
{
    $rows = [];
    foreach ($raw as $r) {
        $qty = (float) ($r['qty'] ?? 0);
        if ($qty <= 0) {
            continue;
        }
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
            'line_total' => (float) ($r['line_total'] ?? 0),
            'warehouse_name' => (string) ($r['warehouse_name'] ?? ''),
            'source' => $source,
            'source_label' => $source === 'order' ? 'طلب شراء' : 'فاتورة',
        ];
    }

    return $rows;
}
