<?php
declare(strict_types=1);

require_once app_path('includes/sal_delivery_schema.php');

function sal_delivery_report_status_label(string $status, int $isPosted): string
{
    if ($status === 'cancelled') {
        return 'ملغى';
    }
    if ($isPosted === 1) {
        return 'مرحّل';
    }
    if ($status === 'draft') {
        return 'مسودة';
    }

    return 'مؤكد';
}

function sal_delivery_report_status_filter_label(string $filter): string
{
    return match ($filter) {
        'posted' => 'مرحّل',
        'unposted' => 'غير مرحّل',
        'cancelled' => 'ملغى',
        'draft' => 'مسودة',
        default => 'الكل',
    };
}

/**
 * @return list<array<string,mixed>>
 */
function sal_report_deliveries(PDO $pdo, int $customerId, string $from, string $to, string $statusFilter = 'all'): array
{
    if ($from === '' || $to === '' || $customerId < 0) {
        return [];
    }
    if (!sal_delivery_ensure_schema($pdo)) {
        return [];
    }

    $custFilter = $customerId > 0 ? ' AND d.customer_id = ? ' : '';
    $params = [$from, $to];
    if ($customerId > 0) {
        $params[] = $customerId;
    }

    $statusSql = '';
    if ($statusFilter === 'posted') {
        $statusSql = " AND d.is_posted = 1 AND d.status <> 'cancelled' ";
    } elseif ($statusFilter === 'unposted') {
        $statusSql = " AND d.is_posted = 0 AND d.status <> 'cancelled' ";
    } elseif ($statusFilter === 'cancelled') {
        $statusSql = " AND d.status = 'cancelled' ";
    } elseif ($statusFilter === 'draft') {
        $statusSql = " AND d.status = 'draft' ";
    }

    $orderBy = $customerId > 0
        ? 'd.delivery_date ASC, d.delivery_no ASC, d.id ASC'
        : 'c.name_ar ASC, d.delivery_date ASC, d.delivery_no ASC, d.id ASC';

    $st = $pdo->prepare(
        "SELECT d.id, d.delivery_no, d.delivery_date, d.status, d.is_posted, d.notes,
                c.name_ar AS customer_name,
                w.name_ar AS warehouse_name,
                inv.id AS linked_invoice_id,
                inv.invoice_no AS linked_invoice_no,
                u.full_name_ar AS created_by_name,
                COALESCE(agg.line_count, 0) AS line_count,
                COALESCE(agg.total_qty, 0) AS total_qty
         FROM sal_delivery d
         INNER JOIN crm_customer c ON c.id = d.customer_id
         LEFT JOIN inv_warehouse w ON w.id = d.warehouse_id
         LEFT JOIN sal_invoice inv ON inv.delivery_id = d.id
         LEFT JOIN sys_user u ON u.id = d.created_by
         LEFT JOIN (
             SELECT delivery_id, COUNT(*) AS line_count, SUM(qty) AS total_qty
             FROM sal_delivery_line
             GROUP BY delivery_id
         ) agg ON agg.delivery_id = d.id
         WHERE d.delivery_date >= ? AND d.delivery_date <= ?
           {$custFilter}{$statusSql}
         ORDER BY {$orderBy}"
    );
    $st->execute($params);

    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $status = (string) ($row['status'] ?? 'confirmed');
        $isPosted = (int) ($row['is_posted'] ?? 0);
        $rows[] = [
            'id' => (int) ($row['id'] ?? 0),
            'delivery_no' => (string) ($row['delivery_no'] ?? ''),
            'delivery_date' => (string) ($row['delivery_date'] ?? ''),
            'customer_name' => (string) ($row['customer_name'] ?? ''),
            'warehouse_name' => (string) ($row['warehouse_name'] ?? ''),
            'line_count' => (int) ($row['line_count'] ?? 0),
            'total_qty' => (float) ($row['total_qty'] ?? 0),
            'linked_invoice_id' => (int) ($row['linked_invoice_id'] ?? 0),
            'linked_invoice_no' => (string) ($row['linked_invoice_no'] ?? ''),
            'status' => $status,
            'is_posted' => $isPosted,
            'status_label' => sal_delivery_report_status_label($status, $isPosted),
            'notes' => (string) ($row['notes'] ?? ''),
            'created_by_name' => (string) ($row['created_by_name'] ?? ''),
        ];
    }

    return $rows;
}
