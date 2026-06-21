<?php
declare(strict_types=1);

require_once app_path('includes/pur_order_schema.php');

/**
 * @return list<array<string,mixed>>
 */
function pur_report_purchase_orders(PDO $pdo, int $supplierId, string $from, string $to, string $statusFilter = 'all'): array
{
    if ($from === '' || $to === '' || $supplierId < 0) {
        return [];
    }

    pur_order_ensure_schema($pdo);

    $supFilter = $supplierId > 0 ? ' AND o.supplier_id = ? ' : '';
    $params = [$from, $to];
    if ($supplierId > 0) {
        $params[] = $supplierId;
    }

    $statusSql = '';
    if ($statusFilter === 'open') {
        $statusSql = " AND o.status IN ('approved','partial','submitted')";
    } elseif ($statusFilter === 'approved') {
        $statusSql = " AND o.status IN ('approved','partial','closed')";
    } elseif ($statusFilter !== 'all' && in_array($statusFilter, pur_order_valid_statuses(), true)) {
        $statusSql = ' AND o.status = ?';
        $params[] = $statusFilter;
    }

    $orderBy = $supplierId > 0
        ? 'o.order_date ASC, o.id ASC'
        : 's.name_ar ASC, o.order_date ASC, o.id ASC';

    $st = $pdo->prepare(
        "SELECT o.id, o.order_no, o.order_date, o.expected_date, o.reference_no, o.subtotal, o.total,
                o.status, o.payment_type, s.name_ar AS supplier_name
         FROM pur_order o
         INNER JOIN crm_supplier s ON s.id = o.supplier_id
         WHERE o.order_date >= ? AND o.order_date <= ?
           AND o.status <> 'cancelled'
           {$supFilter}{$statusSql}
         ORDER BY {$orderBy}"
    );
    $st->execute($params);

    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $pay = (string) ($row['payment_type'] ?? 'credit');
        $rows[] = [
            'id' => (int) ($row['id'] ?? 0),
            'order_no' => (string) ($row['order_no'] ?? ''),
            'supplier_name' => (string) ($row['supplier_name'] ?? ''),
            'order_date' => (string) ($row['order_date'] ?? ''),
            'expected_date' => (string) ($row['expected_date'] ?? ''),
            'reference_no' => (string) ($row['reference_no'] ?? ''),
            'subtotal' => (float) ($row['subtotal'] ?? 0),
            'total' => (float) ($row['total'] ?? 0),
            'status' => (string) ($row['status'] ?? ''),
            'status_label' => pur_order_status_label((string) ($row['status'] ?? '')),
            'payment_type' => $pay,
            'payment_label' => $pay === 'credit' ? 'ذمم' : 'نقدي',
        ];
    }

    return $rows;
}

/**
 * @return list<array<string,mixed>>
 */
function pur_report_purchase_orders_by_item(PDO $pdo, int $itemId, string $from, string $to): array
{
    if ($from === '' || $to === '' || $itemId < 1) {
        return [];
    }

    pur_order_ensure_schema($pdo);

    $st = $pdo->prepare(
        "SELECT o.id, o.order_no, o.order_date, o.status, s.name_ar AS supplier_name,
                ol.qty, ol.qty_extra, ol.qty_invoiced, ol.unit_price, ol.line_gross,
                i.name_ar AS item_name, i.barcode
         FROM pur_order_line ol
         INNER JOIN pur_order o ON o.id = ol.order_id
         INNER JOIN crm_supplier s ON s.id = o.supplier_id
         INNER JOIN inv_item i ON i.id = ol.item_id
         WHERE ol.item_id = ?
           AND o.order_date >= ? AND o.order_date <= ?
           AND o.status <> 'cancelled'
         ORDER BY o.order_date ASC, o.id ASC"
    );
    $st->execute([$itemId, $from, $to]);

    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        require_once app_path('includes/inv_invoice_line_qty.php');
        $ordered = inv_invoice_line_stock_qty_sum((float) ($row['qty'] ?? 0), (float) ($row['qty_extra'] ?? 0));
        $rows[] = [
            'order_no' => (string) ($row['order_no'] ?? ''),
            'order_date' => (string) ($row['order_date'] ?? ''),
            'supplier_name' => (string) ($row['supplier_name'] ?? ''),
            'item_name' => (string) ($row['item_name'] ?? ''),
            'barcode' => (string) ($row['barcode'] ?? ''),
            'qty_ordered' => $ordered,
            'qty_invoiced' => (float) ($row['qty_invoiced'] ?? 0),
            'unit_price' => (float) ($row['unit_price'] ?? 0),
            'line_gross' => (float) ($row['line_gross'] ?? 0),
            'status_label' => pur_order_status_label((string) ($row['status'] ?? '')),
        ];
    }

    return $rows;
}

/**
 * @return list<array<string,mixed>>
 */
function pur_report_purchase_orders_open(PDO $pdo, int $supplierId): array
{
    pur_order_ensure_schema($pdo);

    $supFilter = $supplierId > 0 ? ' AND o.supplier_id = ? ' : '';
    $params = $supplierId > 0 ? [$supplierId] : [];

    $st = $pdo->prepare(
        "SELECT o.id, o.order_no, o.order_date, o.expected_date, o.total, o.status,
                s.name_ar AS supplier_name,
                (SELECT COALESCE(SUM(qty + qty_extra - qty_invoiced), 0) FROM pur_order_line ol WHERE ol.order_id = o.id) AS qty_remaining
         FROM pur_order o
         INNER JOIN crm_supplier s ON s.id = o.supplier_id
         WHERE o.status IN ('approved','partial','submitted')
           {$supFilter}
         ORDER BY o.expected_date ASC, o.order_date ASC, o.id ASC"
    );
    $st->execute($params);

    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        if ((float) ($row['qty_remaining'] ?? 0) <= 0.000001 && (string) ($row['status'] ?? '') !== 'submitted') {
            continue;
        }
        $rows[] = [
            'id' => (int) ($row['id'] ?? 0),
            'order_no' => (string) ($row['order_no'] ?? ''),
            'order_date' => (string) ($row['order_date'] ?? ''),
            'expected_date' => (string) ($row['expected_date'] ?? ''),
            'supplier_name' => (string) ($row['supplier_name'] ?? ''),
            'total' => (float) ($row['total'] ?? 0),
            'status_label' => pur_order_status_label((string) ($row['status'] ?? '')),
            'qty_remaining' => (float) ($row['qty_remaining'] ?? 0),
        ];
    }

    return $rows;
}
