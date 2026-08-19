<?php
declare(strict_types=1);

require_once app_path('includes/sal_invoice_schema.php');
require_once app_path('includes/sal_invoice_post.php');
require_once app_path('includes/inv_invoice_line_qty.php');

/**
 * @param array{
 *   from:string,
 *   to:string,
 *   customer_id?:int,
 *   sales_rep_id?:int,
 *   region_id?:int,
 *   category_id?:int,
 *   item_id?:int,
 *   warehouse_id?:int,
 *   payment_type?:string,
 *   posted_only?:bool,
 *   group_by?:string,
 *   limit?:int
 * } $filters
 *
 * @return array{
 *   summary: list<array<string, mixed>>,
 *   details: list<array<string, mixed>>,
 *   totals: array{
 *     qty:float,
 *     line_total:float,
 *     line_gross:float,
 *     tax_amount:float,
 *     line_count:int,
 *     invoice_count:int
 *   },
 *   group_by: string
 * }
 */
function sal_report_sales_detailed(PDO $pdo, array $filters): array
{
    $emptyTotals = [
        'qty' => 0.0,
        'line_total' => 0.0,
        'line_gross' => 0.0,
        'tax_amount' => 0.0,
        'line_count' => 0,
        'invoice_count' => 0,
    ];
    $empty = [
        'summary' => [],
        'details' => [],
        'totals' => $emptyTotals,
        'group_by' => 'customer',
    ];

    $from = trim((string) ($filters['from'] ?? ''));
    $to = trim((string) ($filters['to'] ?? ''));
    if ($from === '' || $to === '') {
        return $empty;
    }

    sal_invoice_ensure_schema($pdo);
    inv_invoice_line_ensure_qty_extra($pdo);

    $customerId = max(0, (int) ($filters['customer_id'] ?? 0));
    $salesRepId = max(0, (int) ($filters['sales_rep_id'] ?? 0));
    $regionId = max(0, (int) ($filters['region_id'] ?? 0));
    $categoryId = max(0, (int) ($filters['category_id'] ?? 0));
    $itemId = max(0, (int) ($filters['item_id'] ?? 0));
    $warehouseId = max(0, (int) ($filters['warehouse_id'] ?? 0));
    $paymentType = strtolower(trim((string) ($filters['payment_type'] ?? '')));
    $postedOnly = !empty($filters['posted_only']);
    $groupBy = sal_report_sales_detailed_normalize_group_by((string) ($filters['group_by'] ?? 'customer'));
    $limit = max(100, min(5000, (int) ($filters['limit'] ?? 3000)));

    $hasSku = sal_invoice_column_exists($pdo, 'inv_item', 'sku');
    $skuExpr = $hasSku ? 'COALESCE(it.sku, \'\')' : "''";
    $hasLineTax = sal_invoice_column_exists($pdo, 'sal_invoice_line', 'tax_rate_percent');
    $taxCols = $hasLineTax
        ? 'l.line_total, l.tax_amount, l.line_gross'
        : 'l.line_total, 0 AS tax_amount, l.line_total AS line_gross';

    $where = ["i.status = 'confirmed'", 'i.invoice_date >= ?', 'i.invoice_date <= ?'];
    $params = [$from, $to];

    if ($customerId > 0) {
        $where[] = 'i.customer_id = ?';
        $params[] = $customerId;
    }
    if ($salesRepId > 0) {
        $where[] = 'COALESCE(i.sales_rep_id, c.sales_rep_id) = ?';
        $params[] = $salesRepId;
    }
    if ($regionId > 0) {
        $where[] = 'c.region_id = ?';
        $params[] = $regionId;
    }
    if ($categoryId > 0) {
        $where[] = 'it.category_id = ?';
        $params[] = $categoryId;
    }
    if ($itemId > 0) {
        $where[] = 'l.item_id = ?';
        $params[] = $itemId;
    }
    if ($warehouseId > 0) {
        $where[] = 'i.warehouse_id = ?';
        $params[] = $warehouseId;
    }
    if ($paymentType === 'cash' || $paymentType === 'credit') {
        $where[] = 'i.payment_type = ?';
        $params[] = $paymentType;
    }
    if ($postedOnly) {
        $where[] = sal_invoice_sql_is_posted_expr('i');
    }

    $sql =
        "SELECT i.id AS invoice_id, i.invoice_no, i.invoice_date, i.payment_type,
                c.id AS customer_id, c.code AS customer_code, c.name_ar AS customer_name,
                COALESCE(sr.id, 0) AS sales_rep_id,
                COALESCE(sr.name_ar, '') AS sales_rep_name,
                COALESCE(sr.code, '') AS sales_rep_code,
                COALESCE(rg.id, 0) AS region_id,
                COALESCE(rg.name_ar, '') AS region_name,
                COALESCE(w.id, 0) AS warehouse_id,
                COALESCE(w.name_ar, '') AS warehouse_name,
                it.id AS item_id,
                {$skuExpr} AS item_sku,
                COALESCE(NULLIF(TRIM(l.line_desc), ''), it.name_ar, '') AS item_name,
                COALESCE(cat.id, 0) AS category_id,
                COALESCE(cat.name_ar, '') AS category_name,
                l.qty, l.unit_price, l.discount_pct, {$taxCols}
         FROM sal_invoice_line l
         INNER JOIN sal_invoice i ON i.id = l.invoice_id
         INNER JOIN crm_customer c ON c.id = i.customer_id
         LEFT JOIN crm_sales_rep sr ON sr.id = COALESCE(i.sales_rep_id, c.sales_rep_id)
         LEFT JOIN crm_region rg ON rg.id = c.region_id
         LEFT JOIN inv_warehouse w ON w.id = i.warehouse_id
         INNER JOIN inv_item it ON it.id = l.item_id
         LEFT JOIN inv_item_category cat ON cat.id = it.category_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY i.invoice_date ASC, i.id ASC, l.id ASC
         LIMIT {$limit}";

    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $raw = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return $empty;
    }

    if ($raw === []) {
        return [
            'summary' => [],
            'details' => [],
            'totals' => $emptyTotals,
            'group_by' => $groupBy,
        ];
    }

    $details = [];
    $totQty = 0.0;
    $totLine = 0.0;
    $totGross = 0.0;
    $totTax = 0.0;
    $invoiceIds = [];

    foreach ($raw as $row) {
        $qty = (float) ($row['qty'] ?? 0);
        $lineTotal = (float) ($row['line_total'] ?? 0);
        $lineGross = (float) ($row['line_gross'] ?? 0);
        $taxAmount = (float) ($row['tax_amount'] ?? 0);
        $invoiceId = (int) ($row['invoice_id'] ?? 0);
        if ($invoiceId > 0) {
            $invoiceIds[$invoiceId] = true;
        }

        $details[] = [
            'invoice_id' => $invoiceId,
            'invoice_no' => (string) ($row['invoice_no'] ?? ''),
            'invoice_date' => (string) ($row['invoice_date'] ?? ''),
            'payment_type' => (string) ($row['payment_type'] ?? ''),
            'customer_id' => (int) ($row['customer_id'] ?? 0),
            'customer_code' => (string) ($row['customer_code'] ?? ''),
            'customer_name' => (string) ($row['customer_name'] ?? ''),
            'sales_rep_id' => (int) ($row['sales_rep_id'] ?? 0),
            'sales_rep_name' => (string) ($row['sales_rep_name'] ?? ''),
            'sales_rep_code' => (string) ($row['sales_rep_code'] ?? ''),
            'region_id' => (int) ($row['region_id'] ?? 0),
            'region_name' => (string) ($row['region_name'] ?? ''),
            'warehouse_id' => (int) ($row['warehouse_id'] ?? 0),
            'warehouse_name' => (string) ($row['warehouse_name'] ?? ''),
            'item_id' => (int) ($row['item_id'] ?? 0),
            'item_sku' => (string) ($row['item_sku'] ?? ''),
            'item_name' => (string) ($row['item_name'] ?? ''),
            'category_id' => (int) ($row['category_id'] ?? 0),
            'category_name' => (string) ($row['category_name'] ?? ''),
            'qty' => $qty,
            'unit_price' => (float) ($row['unit_price'] ?? 0),
            'discount_pct' => (float) ($row['discount_pct'] ?? 0),
            'line_total' => $lineTotal,
            'line_gross' => $lineGross,
            'tax_amount' => $taxAmount,
        ];

        $totQty += $qty;
        $totLine += $lineTotal;
        $totGross += $lineGross;
        $totTax += $taxAmount;
    }

    return [
        'summary' => sal_report_sales_detailed_build_summary($details, $groupBy),
        'details' => $details,
        'totals' => [
            'qty' => $totQty,
            'line_total' => $totLine,
            'line_gross' => $totGross,
            'tax_amount' => $totTax,
            'line_count' => count($details),
            'invoice_count' => count($invoiceIds),
        ],
        'group_by' => $groupBy,
    ];
}

function sal_report_sales_detailed_normalize_group_by(string $groupBy): string
{
    $allowed = [
        'customer',
        'sales_rep',
        'region',
        'category',
        'item',
        'invoice_date',
        'warehouse',
        'payment_type',
    ];

    return in_array($groupBy, $allowed, true) ? $groupBy : 'customer';
}

/**
 * @param list<array<string, mixed>> $details
 * @return list<array<string, mixed>>
 */
function sal_report_sales_detailed_build_summary(array $details, string $groupBy): array
{
    /** @var array<string, array<string, mixed>> $map */
    $map = [];

    foreach ($details as $row) {
        [$key, $label, $code] = sal_report_sales_detailed_group_key($row, $groupBy);
        if (!isset($map[$key])) {
            $map[$key] = [
                'group_key' => $key,
                'label' => $label,
                'code' => $code,
                'qty' => 0.0,
                'line_total' => 0.0,
                'line_gross' => 0.0,
                'tax_amount' => 0.0,
                'line_count' => 0,
                '_invoices' => [],
            ];
        }
        $map[$key]['qty'] += (float) ($row['qty'] ?? 0);
        $map[$key]['line_total'] += (float) ($row['line_total'] ?? 0);
        $map[$key]['line_gross'] += (float) ($row['line_gross'] ?? 0);
        $map[$key]['tax_amount'] += (float) ($row['tax_amount'] ?? 0);
        $map[$key]['line_count']++;
        $invId = (int) ($row['invoice_id'] ?? 0);
        if ($invId > 0) {
            $map[$key]['_invoices'][$invId] = true;
        }
    }

    $summary = [];
    foreach ($map as $block) {
        $block['invoice_count'] = count($block['_invoices']);
        unset($block['_invoices']);
        $summary[] = $block;
    }

    usort($summary, static function (array $a, array $b): int {
        return strcmp((string) $b['line_gross'], (string) $a['line_gross']);
    });

    return $summary;
}

/**
 * @param array<string, mixed> $row
 * @return array{0:string,1:string,2:string}
 */
function sal_report_sales_detailed_group_key(array $row, string $groupBy): array
{
    switch ($groupBy) {
        case 'sales_rep':
            $id = (int) ($row['sales_rep_id'] ?? 0);
            return [
                'rep:' . $id,
                (string) (($row['sales_rep_name'] ?? '') !== '' ? $row['sales_rep_name'] : '— بدون مندوب —'),
                (string) ($row['sales_rep_code'] ?? ''),
            ];
        case 'region':
            $id = (int) ($row['region_id'] ?? 0);
            return [
                'region:' . $id,
                (string) (($row['region_name'] ?? '') !== '' ? $row['region_name'] : '— بدون منطقة —'),
                '',
            ];
        case 'category':
            $id = (int) ($row['category_id'] ?? 0);
            return [
                'cat:' . $id,
                (string) (($row['category_name'] ?? '') !== '' ? $row['category_name'] : '— بدون فئة —'),
                '',
            ];
        case 'item':
            $id = (int) ($row['item_id'] ?? 0);
            return [
                'item:' . $id,
                (string) ($row['item_name'] ?? ''),
                (string) ($row['item_sku'] ?? ''),
            ];
        case 'invoice_date':
            $d = (string) ($row['invoice_date'] ?? '');
            return ['date:' . $d, $d, ''];
        case 'warehouse':
            $id = (int) ($row['warehouse_id'] ?? 0);
            return [
                'wh:' . $id,
                (string) (($row['warehouse_name'] ?? '') !== '' ? $row['warehouse_name'] : '— بدون مستودع —'),
                '',
            ];
        case 'payment_type':
            $pt = (string) ($row['payment_type'] ?? '');
            $label = $pt === 'credit' ? 'آجل' : ($pt === 'cash' ? 'نقد' : ($pt !== '' ? $pt : '—'));
            return ['pay:' . $pt, $label, ''];
        case 'customer':
        default:
            $id = (int) ($row['customer_id'] ?? 0);
            return [
                'cust:' . $id,
                (string) ($row['customer_name'] ?? ''),
                (string) ($row['customer_code'] ?? ''),
            ];
    }
}

function sal_report_sales_detailed_group_label(string $groupBy): string
{
    $labels = [
        'customer' => 'العميل',
        'sales_rep' => 'المندوب',
        'region' => 'المنطقة',
        'category' => 'فئة المادة',
        'item' => 'المادة',
        'invoice_date' => 'التاريخ',
        'warehouse' => 'المستودع',
        'payment_type' => 'نوع الدفع',
    ];

    return $labels[$groupBy] ?? 'العميل';
}

function sal_report_sales_detailed_payment_label(?string $paymentType): string
{
    $pt = strtolower(trim((string) $paymentType));
    if ($pt === 'cash') {
        return 'نقد';
    }
    if ($pt === 'credit') {
        return 'آجل';
    }

    return $pt !== '' ? $pt : '—';
}

function sal_report_detailed_normalize_source(string $source): string
{
    $s = strtolower(trim($source));
    if ($s === 'orders' || $s === 'order') {
        return 'orders';
    }
    if ($s === 'both' || $s === 'all') {
        return 'both';
    }

    return 'sales';
}

function sal_report_order_status_label(?string $status): string
{
    $s = strtolower(trim((string) $status));
    if ($s === 'approved' || $s === 'posted') {
        return 'معتمد';
    }
    if ($s === 'draft') {
        return 'مسودة';
    }
    if ($s === 'pending') {
        return 'معلّق';
    }

    return $status !== '' ? (string) $status : '—';
}

/**
 * @param array<string, mixed> $filters
 * @return array<string, mixed>
 */
function sal_report_customer_orders_detailed(PDO $pdo, array $filters): array
{
    require_once app_path('includes/sal_customer_order.php');
    sal_customer_order_ensure_schema($pdo);
    sal_customer_order_ensure_pricing_schema($pdo);

    $empty = [
        'summary' => [],
        'details' => [],
        'totals' => [
            'qty' => 0.0,
            'line_total' => 0.0,
            'line_gross' => 0.0,
            'tax_amount' => 0.0,
            'line_count' => 0,
            'invoice_count' => 0,
            'order_count' => 0,
            'doc_count' => 0,
        ],
        'group_by' => 'customer',
        'source' => 'orders',
    ];

    $from = trim((string) ($filters['from'] ?? ''));
    $to = trim((string) ($filters['to'] ?? ''));
    if ($from === '' || $to === '') {
        return $empty;
    }

    $groupBy = sal_report_sales_detailed_normalize_group_by((string) ($filters['group_by'] ?? 'customer'));
    $customerId = max(0, (int) ($filters['customer_id'] ?? 0));
    $salesRepId = max(0, (int) ($filters['sales_rep_id'] ?? 0));
    $regionId = max(0, (int) ($filters['region_id'] ?? 0));
    $categoryId = max(0, (int) ($filters['category_id'] ?? 0));
    $itemId = max(0, (int) ($filters['item_id'] ?? 0));
    $warehouseId = max(0, (int) ($filters['warehouse_id'] ?? 0));
    $approvedOnly = !empty($filters['posted_only']);
    $limit = max(100, min(5000, (int) ($filters['limit'] ?? 3000)));

    $where = ['o.order_date >= ?', 'o.order_date <= ?', 'IFNULL(o.is_sent, 1) = 1'];
    $params = [$from, $to];
    if ($customerId > 0) {
        $where[] = 'o.customer_id = ?';
        $params[] = $customerId;
    }
    if ($salesRepId > 0) {
        $where[] = 'COALESCE(o.sales_rep_id, c.sales_rep_id) = ?';
        $params[] = $salesRepId;
    }
    if ($regionId > 0) {
        $where[] = 'c.region_id = ?';
        $params[] = $regionId;
    }
    if ($categoryId > 0) {
        $where[] = 'it.category_id = ?';
        $params[] = $categoryId;
    }
    if ($itemId > 0) {
        $where[] = 'l.item_id = ?';
        $params[] = $itemId;
    }
    if ($warehouseId > 0) {
        $where[] = 'o.warehouse_id = ?';
        $params[] = $warehouseId;
    }
    if ($approvedOnly) {
        $where[] = "o.status IN ('approved','posted')";
    }

    $sql =
        "SELECT o.id AS invoice_id, o.order_no AS invoice_no, o.order_date AS invoice_date, o.status AS payment_type,
                c.id AS customer_id, c.code AS customer_code, c.name_ar AS customer_name,
                COALESCE(sr.id, 0) AS sales_rep_id, COALESCE(sr.name_ar, '') AS sales_rep_name,
                COALESCE(sr.code, '') AS sales_rep_code,
                COALESCE(rg.id, 0) AS region_id, COALESCE(rg.name_ar, '') AS region_name,
                COALESCE(w.id, 0) AS warehouse_id, COALESCE(w.name_ar, '') AS warehouse_name,
                it.id AS item_id, COALESCE(it.sku, it.barcode, '') AS item_sku,
                COALESCE(NULLIF(TRIM(it.name_ar), ''), NULLIF(TRIM(l.item_name), ''), '') AS item_name,
                COALESCE(cat.id, 0) AS category_id, COALESCE(cat.name_ar, '') AS category_name,
                l.qty, COALESCE(l.unit_price, 0) AS unit_price, COALESCE(l.discount_pct, 0) AS discount_pct,
                COALESCE(l.line_total, 0) AS line_total, COALESCE(l.tax_amount, 0) AS tax_amount,
                COALESCE(l.line_gross, l.line_total, 0) AS line_gross
         FROM sal_customer_order_line l
         INNER JOIN sal_customer_order o ON o.id = l.order_id
         INNER JOIN crm_customer c ON c.id = o.customer_id
         LEFT JOIN crm_sales_rep sr ON sr.id = COALESCE(o.sales_rep_id, c.sales_rep_id)
         LEFT JOIN crm_region rg ON rg.id = c.region_id
         LEFT JOIN inv_warehouse w ON w.id = o.warehouse_id
         INNER JOIN inv_item it ON it.id = l.item_id
         LEFT JOIN inv_item_category cat ON cat.id = it.category_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY o.order_date ASC, o.id ASC, l.line_no ASC
         LIMIT {$limit}";

    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $raw = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return $empty;
    }

    $details = [];
    $orderIds = [];
    foreach ($raw as $row) {
        $oid = (int) ($row['invoice_id'] ?? 0);
        if ($oid > 0) {
            $orderIds[$oid] = true;
        }
        $details[] = [
            'doc_type' => 'order',
            'doc_label' => 'طلب',
            'invoice_id' => $oid,
            'invoice_no' => (string) ($row['invoice_no'] ?? ''),
            'invoice_date' => (string) ($row['invoice_date'] ?? ''),
            'payment_type' => sal_report_order_status_label((string) ($row['payment_type'] ?? '')),
            'customer_id' => (int) ($row['customer_id'] ?? 0),
            'customer_code' => (string) ($row['customer_code'] ?? ''),
            'customer_name' => (string) ($row['customer_name'] ?? ''),
            'sales_rep_id' => (int) ($row['sales_rep_id'] ?? 0),
            'sales_rep_name' => (string) ($row['sales_rep_name'] ?? ''),
            'sales_rep_code' => (string) ($row['sales_rep_code'] ?? ''),
            'region_id' => (int) ($row['region_id'] ?? 0),
            'region_name' => (string) ($row['region_name'] ?? ''),
            'warehouse_id' => (int) ($row['warehouse_id'] ?? 0),
            'warehouse_name' => (string) ($row['warehouse_name'] ?? ''),
            'item_id' => (int) ($row['item_id'] ?? 0),
            'item_sku' => (string) ($row['item_sku'] ?? ''),
            'item_name' => (string) ($row['item_name'] ?? ''),
            'category_id' => (int) ($row['category_id'] ?? 0),
            'category_name' => (string) ($row['category_name'] ?? ''),
            'qty' => (float) ($row['qty'] ?? 0),
            'unit_price' => (float) ($row['unit_price'] ?? 0),
            'discount_pct' => (float) ($row['discount_pct'] ?? 0),
            'line_total' => (float) ($row['line_total'] ?? 0),
            'line_gross' => (float) ($row['line_gross'] ?? 0),
            'tax_amount' => (float) ($row['tax_amount'] ?? 0),
        ];
    }

    $totQty = 0.0;
    $totLine = 0.0;
    $totGross = 0.0;
    $totTax = 0.0;
    foreach ($details as $d) {
        $totQty += (float) ($d['qty'] ?? 0);
        $totLine += (float) ($d['line_total'] ?? 0);
        $totGross += (float) ($d['line_gross'] ?? 0);
        $totTax += (float) ($d['tax_amount'] ?? 0);
    }

    return [
        'summary' => sal_report_sales_detailed_build_summary($details, $groupBy),
        'details' => $details,
        'totals' => [
            'qty' => $totQty,
            'line_total' => $totLine,
            'line_gross' => $totGross,
            'tax_amount' => $totTax,
            'line_count' => count($details),
            'invoice_count' => 0,
            'order_count' => count($orderIds),
            'doc_count' => count($orderIds),
        ],
        'group_by' => $groupBy,
        'source' => 'orders',
    ];
}

/**
 * @param array<string, mixed> $filters
 * @return array<string, mixed>
 */
function sal_report_combined_detailed(PDO $pdo, array $filters): array
{
    $source = sal_report_detailed_normalize_source((string) ($filters['source'] ?? 'sales'));
    if ($source === 'orders') {
        return sal_report_customer_orders_detailed($pdo, $filters);
    }
    if ($source === 'sales') {
        $report = sal_report_sales_detailed($pdo, $filters);
        foreach ($report['details'] as &$row) {
            $row['doc_type'] = 'sales';
            $row['doc_label'] = 'فاتورة';
        }
        unset($row);
        $report['source'] = 'sales';
        $report['totals']['order_count'] = 0;
        $report['totals']['doc_count'] = (int) ($report['totals']['invoice_count'] ?? 0);

        return $report;
    }

    $sales = sal_report_combined_detailed($pdo, array_merge($filters, ['source' => 'sales']));
    $orders = sal_report_customer_orders_detailed($pdo, $filters);
    $details = array_merge($sales['details'], $orders['details']);
    $groupBy = sal_report_sales_detailed_normalize_group_by((string) ($filters['group_by'] ?? 'customer'));

    $totQty = (float) ($sales['totals']['qty'] ?? 0) + (float) ($orders['totals']['qty'] ?? 0);
    $totLine = (float) ($sales['totals']['line_total'] ?? 0) + (float) ($orders['totals']['line_total'] ?? 0);
    $totGross = (float) ($sales['totals']['line_gross'] ?? 0) + (float) ($orders['totals']['line_gross'] ?? 0);
    $totTax = (float) ($sales['totals']['tax_amount'] ?? 0) + (float) ($orders['totals']['tax_amount'] ?? 0);

    return [
        'summary' => sal_report_sales_detailed_build_summary($details, $groupBy),
        'details' => $details,
        'totals' => [
            'qty' => $totQty,
            'line_total' => $totLine,
            'line_gross' => $totGross,
            'tax_amount' => $totTax,
            'line_count' => count($details),
            'invoice_count' => (int) ($sales['totals']['invoice_count'] ?? 0),
            'order_count' => (int) ($orders['totals']['order_count'] ?? 0),
            'doc_count' => (int) ($sales['totals']['invoice_count'] ?? 0) + (int) ($orders['totals']['order_count'] ?? 0),
        ],
        'group_by' => $groupBy,
        'source' => 'both',
    ];
}
