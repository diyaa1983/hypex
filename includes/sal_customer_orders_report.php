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
                   l.line_no, l.item_id, l.item_name, l.unit_name, l.qty,
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
            'barcode' => (string) ($row['barcode'] ?? ''),
        ];
    }

    return $rows;
}
