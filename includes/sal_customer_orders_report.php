<?php
declare(strict_types=1);

require_once app_path('includes/sal_customer_order.php');

/**
 * تقرير طلبات شراء العملاء (بنود) مع فلتر تاريخ / عميل / مندوب / مادة.
 *
 * @return list<array<string,mixed>>
 */
function sal_report_customer_orders(
    PDO $pdo,
    int $customerId,
    int $salesRepId,
    int $itemId,
    string $from,
    string $to,
    string $statusFilter = 'all'
): array {
    if ($from === '' || $to === '' || $customerId < 0 || $salesRepId < 0 || $itemId < 0) {
        return [];
    }

    if (!sal_customer_order_ensure_schema($pdo)) {
        return [];
    }

    $params = [$from, $to];
    $sql = "SELECT o.id, o.order_no, o.order_date, o.status,
                   c.name_ar AS customer_name, c.code AS customer_code,
                   COALESCE(r.name_ar, '') AS sales_rep_name,
                   w.name_ar AS warehouse_name,
                   l.line_no, l.item_id, l.item_name, l.unit_name,
                   l.qty, COALESCE(l.unit_factor, 1) AS unit_factor,
                   COALESCE(l.qty_base, l.qty * COALESCE(l.unit_factor, 1)) AS qty_base,
                   i.barcode
            FROM sal_customer_order_line l
            INNER JOIN sal_customer_order o ON o.id = l.order_id
            INNER JOIN crm_customer c ON c.id = o.customer_id
            INNER JOIN inv_warehouse w ON w.id = o.warehouse_id
            LEFT JOIN crm_sales_rep r ON r.id = o.sales_rep_id
            LEFT JOIN inv_item i ON i.id = l.item_id
            WHERE o.order_date >= ? AND o.order_date <= ?";

    if ($customerId > 0) {
        $sql .= ' AND o.customer_id = ?';
        $params[] = $customerId;
    }
    if ($salesRepId > 0) {
        $sql .= ' AND o.sales_rep_id = ?';
        $params[] = $salesRepId;
    }
    if ($itemId > 0) {
        $sql .= ' AND l.item_id = ?';
        $params[] = $itemId;
    }
    if ($statusFilter === 'draft' || $statusFilter === 'approved') {
        $sql .= ' AND o.status = ?';
        $params[] = $statusFilter;
    }

    $orderBy = $customerId > 0
        ? 'o.order_date ASC, o.id ASC, l.line_no ASC, l.id ASC'
        : 'c.name_ar ASC, o.order_date ASC, o.id ASC, l.line_no ASC, l.id ASC';
    $sql .= ' ORDER BY ' . $orderBy;

    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $raw = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $rows = [];
    foreach ($raw as $row) {
        $status = (string) ($row['status'] ?? 'draft');
        $rows[] = [
            'id' => (int) ($row['id'] ?? 0),
            'order_no' => (string) ($row['order_no'] ?? ''),
            'order_date' => (string) ($row['order_date'] ?? ''),
            'status' => $status,
            'status_label' => sal_customer_order_status_label($status),
            'customer_name' => (string) ($row['customer_name'] ?? ''),
            'customer_code' => (string) ($row['customer_code'] ?? ''),
            'sales_rep_name' => (string) ($row['sales_rep_name'] ?? ''),
            'warehouse_name' => (string) ($row['warehouse_name'] ?? ''),
            'line_no' => (int) ($row['line_no'] ?? 0),
            'item_id' => (int) ($row['item_id'] ?? 0),
            'item_name' => (string) ($row['item_name'] ?? ''),
            'unit_name' => (string) ($row['unit_name'] ?? ''),
            'qty' => (float) ($row['qty'] ?? 0),
            'unit_factor' => (float) ($row['unit_factor'] ?? 1),
            'qty_base' => (float) ($row['qty_base'] ?? 0),
            'barcode' => (string) ($row['barcode'] ?? ''),
        ];
    }

    return $rows;
}

/**
 * تقرير طلبات شراء العملاء حسب مادة معينة (بنود).
 *
 * @return list<array<string,mixed>>
 */
function sal_report_customer_orders_by_item(PDO $pdo, int $itemId, string $from, string $to): array
{
    if ($itemId < 1 || $from === '' || $to === '') {
        return [];
    }

    $hasPrice = sal_customer_order_has_column($pdo, 'sal_customer_order_line', 'unit_price');
    $hasGross = sal_customer_order_has_column($pdo, 'sal_customer_order_line', 'line_gross');
    $hasExtra = sal_customer_order_has_column($pdo, 'sal_customer_order_line', 'qty_extra');
    $priceSel = $hasPrice ? 'COALESCE(l.unit_price, 0) AS unit_price' : '0 AS unit_price';
    $grossSel = $hasGross ? 'COALESCE(l.line_gross, 0) AS line_gross' : '0 AS line_gross';
    $extraSel = $hasExtra ? 'COALESCE(l.qty_extra, 0) AS qty_extra' : '0 AS qty_extra';

    if (!sal_customer_order_ensure_schema($pdo)) {
        return [];
    }

    $sql = "SELECT o.id, o.order_no, o.order_date, o.status,
                   c.name_ar AS customer_name, c.code AS customer_code,
                   COALESCE(r.name_ar, '') AS sales_rep_name,
                   w.name_ar AS warehouse_name,
                   l.line_no, l.item_id, l.item_name, l.unit_name,
                   l.qty, {$extraSel},
                   COALESCE(l.unit_factor, 1) AS unit_factor,
                   COALESCE(l.qty_base, l.qty * COALESCE(l.unit_factor, 1)) AS qty_base,
                   {$priceSel}, {$grossSel},
                   i.barcode, i.name_ar AS item_name_master
            FROM sal_customer_order_line l
            INNER JOIN sal_customer_order o ON o.id = l.order_id
            INNER JOIN crm_customer c ON c.id = o.customer_id
            INNER JOIN inv_warehouse w ON w.id = o.warehouse_id
            LEFT JOIN crm_sales_rep r ON r.id = o.sales_rep_id
            LEFT JOIN inv_item i ON i.id = l.item_id
            WHERE l.item_id = ?
              AND o.order_date >= ? AND o.order_date <= ?
              AND IFNULL(o.is_sent, 1) = 1
            ORDER BY o.order_date ASC, o.id ASC, l.line_no ASC, l.id ASC";

    try {
        $st = $pdo->prepare($sql);
        $st->execute([$itemId, $from, $to]);
        $raw = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        // بدون عمود is_sent على قواعد قديمة
        try {
            $sql2 = str_replace('AND IFNULL(o.is_sent, 1) = 1', '', $sql);
            $st = $pdo->prepare($sql2);
            $st->execute([$itemId, $from, $to]);
            $raw = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e2) {
            return [];
        }
    }

    $rows = [];
    foreach ($raw as $row) {
        $status = (string) ($row['status'] ?? 'draft');
        $qty = (float) ($row['qty'] ?? 0);
        $qtyExtra = (float) ($row['qty_extra'] ?? 0);
        $rows[] = [
            'id' => (int) ($row['id'] ?? 0),
            'order_no' => (string) ($row['order_no'] ?? ''),
            'order_date' => (string) ($row['order_date'] ?? ''),
            'status' => $status,
            'status_label' => sal_customer_order_status_label($status),
            'customer_name' => (string) ($row['customer_name'] ?? ''),
            'customer_code' => (string) ($row['customer_code'] ?? ''),
            'sales_rep_name' => (string) ($row['sales_rep_name'] ?? ''),
            'warehouse_name' => (string) ($row['warehouse_name'] ?? ''),
            'line_no' => (int) ($row['line_no'] ?? 0),
            'item_id' => (int) ($row['item_id'] ?? 0),
            'item_name' => (string) (($row['item_name'] ?? '') !== '' ? $row['item_name'] : ($row['item_name_master'] ?? '')),
            'unit_name' => (string) ($row['unit_name'] ?? ''),
            'qty' => $qty,
            'qty_extra' => $qtyExtra,
            'qty_ordered' => $qty + $qtyExtra,
            'unit_factor' => (float) ($row['unit_factor'] ?? 1),
            'qty_base' => (float) ($row['qty_base'] ?? 0),
            'unit_price' => (float) ($row['unit_price'] ?? 0),
            'line_gross' => (float) ($row['line_gross'] ?? 0),
            'barcode' => (string) ($row['barcode'] ?? ''),
        ];
    }

    return $rows;
}
