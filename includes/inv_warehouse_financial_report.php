<?php
declare(strict_types=1);

require_once app_path('includes/inv_item_schema.php');
require_once app_path('includes/inv_item_display.php');
require_once app_path('includes/inv_stock.php');
require_once app_path('includes/inv_item_inventory_unit_cost.php');

/**
 * أرصدة المستودع المالية: لكل مادة (الكمية × التكلفة).
 *
 * @return list<array{
 *   item_sku:string,
 *   item_name:string,
 *   qty:float,
 *   unit_name:string,
 *   cost_price:float,
 *   total_value:float
 * }>
 */
function inv_report_warehouse_financial_lines(
    PDO $pdo,
    int $warehouseId,
    bool $positiveQtyOnly = true,
    ?string $dateTo = null,
    ?string $dateFrom = null
): array {
    if ($warehouseId < 1) {
        return [];
    }

    $dateTo = $dateTo !== null && $dateTo !== '' ? $dateTo : null;
    $dateFrom = $dateFrom !== null && $dateFrom !== '' ? $dateFrom : null;
    if ($dateTo !== null && $dateFrom !== null && $dateFrom > $dateTo) {
        [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
    }

    inv_item_ensure_extended_schema($pdo);
    inv_stock_move_ensure_table($pdo);

    if (!inv_item_has_main_table($pdo, true)) {
        return [];
    }

    $hasUnit = inv_item_has_unit_table($pdo);
    $hasStock = inv_stock_move_has_table($pdo);

    $unitCol = $hasUnit ? 'COALESCE(u.name_ar, i.unit_name)' : 'i.unit_name';
    $unitJoin = $hasUnit ? ' LEFT JOIN inv_unit u ON u.id = i.unit_id ' : '';

    $qtyExpr = $hasStock ? 'COALESCE(st.qty_on_hand, 0)' : '0';
    // الرصيد المالي = مجموع حركات المخزون حتى تاريخ النهاية (وليس صافي الفترة فقط).
    $stockParams = [];
    $stockWhere = 'warehouse_id = ?';
    $stockParams[] = $warehouseId;
    if ($dateTo !== null) {
        $stockWhere .= ' AND move_date <= ?';
        $stockParams[] = $dateTo;
    }
    $stockJoin = $hasStock
        ? ' LEFT JOIN (
             SELECT item_id, COALESCE(SUM(qty_delta), 0) AS qty_on_hand
             FROM inv_stock_move
             WHERE ' . $stockWhere . '
             GROUP BY item_id
         ) st ON st.item_id = i.id '
        : '';

    $itemNoSql = inv_item_sql_material_number($pdo, 'i');
    $sql =
        "SELECT i.id AS item_id,
                {$itemNoSql} AS item_sku,
                i.name_ar AS item_name,
                {$qtyExpr} AS qty,
                {$unitCol} AS unit_name,
                i.default_cost AS cost_price,
                ({$qtyExpr} * i.default_cost) AS total_value
         FROM inv_item i
         {$stockJoin}
         {$unitJoin}
         WHERE i.is_active = 1";

    $params = $hasStock ? $stockParams : [];

    if ($positiveQtyOnly) {
        // عرض المواد ذات الكمية الموجبة فقط (لمنطق الأرصدة المالية)
        $eps = 0.000001;
        $sql .= ' AND ' . ($hasStock ? 'COALESCE(st.qty_on_hand, 0)' : '0') . ' > ' . $eps;
    }

    $sql .= ' ORDER BY i.name_ar ASC, i.sku ASC';

    $st = $pdo->prepare($sql);
    $st->execute($params);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $qty = (float) ($row['qty'] ?? 0);
        $itemId = (int) ($row['item_id'] ?? 0);
        if ($itemId < 1 && isset($row['id'])) {
            $itemId = (int) $row['id'];
        }
        $cost = $itemId > 0
            ? inv_item_inventory_unit_cost($pdo, $itemId, $dateTo)
            : (float) ($row['cost_price'] ?? 0);
        $out[] = [
            'item_sku' => (string) ($row['item_sku'] ?? ''),
            'item_name' => (string) ($row['item_name'] ?? ''),
            'qty' => $qty,
            'unit_name' => (string) ($row['unit_name'] ?? ''),
            'cost_price' => $cost,
            'total_value' => $qty * $cost,
        ];
    }

    return $out;
}

/** مجموع قيمة كل المستودعات النشطة (أرصدة مالية حتى تاريخ النهاية). */
function inv_warehouse_financial_grand_total(PDO $pdo, ?string $dateTo = null): float
{
    try {
        $st = $pdo->query('SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY id');
        $ids = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        return 0.0;
    }

    $sum = 0.0;
    foreach ($ids as $rawId) {
        $wid = (int) $rawId;
        if ($wid < 1) {
            continue;
        }
        foreach (inv_report_warehouse_financial_lines($pdo, $wid, false, $dateTo) as $ln) {
            $sum += (float) ($ln['total_value'] ?? 0);
        }
    }

    return round(max(0, $sum), 6);
}
