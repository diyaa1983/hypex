<?php
declare(strict_types=1);

require_once app_path('includes/pur_order_schema.php');

/**
 * @return array{rows:list<array<string,mixed>>, pager:array<string,mixed>}
 */
function pur_orders_documents_list_fetch(PDO $pdo, string $search, array $pager): array
{
    pur_order_ensure_schema($pdo);

    $sql = "SELECT o.id, o.order_no, o.order_date, o.expected_date, o.total, o.status,
                   s.name_ar AS supplier_name
            FROM pur_order o
            INNER JOIN crm_supplier s ON s.id = o.supplier_id
            WHERE o.status <> 'cancelled'";
    $params = [];

    if ($search !== '') {
        $like = '%' . $search . '%';
        $sql .= ' AND (o.order_no LIKE ? OR o.reference_no LIKE ? OR s.name_ar LIKE ? OR s.code LIKE ?)';
        $params = [$like, $like, $like, $like];
    }

    $countSql = 'SELECT COUNT(*) FROM pur_order o INNER JOIN crm_supplier s ON s.id = o.supplier_id WHERE o.status <> \'cancelled\'';
    $countParams = $params;
    if ($search !== '') {
        $countSql .= ' AND (o.order_no LIKE ? OR o.reference_no LIKE ? OR s.name_ar LIKE ? OR s.code LIKE ?)';
    }

    require_once app_path('includes/list_pagination.php');
    $stCount = $pdo->prepare($countSql);
    $stCount->execute($countParams);
    $total = (int) $stCount->fetchColumn();
    $pager = list_pager_with_total($pager, $total);

    $sql .= ' ORDER BY o.order_date DESC, o.id DESC' . list_pager_sql_limit($pager);
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as &$row) {
        $row['status_label'] = pur_order_status_label((string) ($row['status'] ?? ''));
    }
    unset($row);

    return ['rows' => $rows, 'pager' => $pager];
}
