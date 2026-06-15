<?php
declare(strict_types=1);

require_once app_path('includes/inv_item_schema.php');
require_once app_path('includes/inv_item_display.php');
require_once app_path('includes/inv_stock.php');

/**
 * مواد المستودع: كل المواد النشطة مع كمية الرصيد في المستودع المحدد.
 * الكمية من حركات المخزون (صفر إن لم تُسجَّل واردات أو مبيعات مرحّلة بعد).
 *
 * @return list<array{
 *   item_sku:string,
 *   item_name:string,
 *   qty:float,
 *   category_name:string,
 *   unit_name:string,
 *   cost_price:float,
 *   sale_price:float
 * }>
 */
function inv_report_warehouse_items_lines(PDO $pdo, int $warehouseId, bool $positiveQtyOnly = false): array
{
    if ($warehouseId < 1) {
        return [];
    }

    inv_item_ensure_extended_schema($pdo);
    inv_stock_move_ensure_table($pdo);

    if (!inv_item_has_main_table($pdo, true)) {
        return [];
    }

    $hasCat = inv_item_has_category_table($pdo);
    $hasUnit = inv_item_has_unit_table($pdo);
    $hasStock = inv_stock_move_has_table($pdo);
    $eps = 0.000001;

    $catCol = $hasCat ? 'COALESCE(c.name_ar, \'\')' : '\'\'';
    $catJoin = $hasCat ? ' LEFT JOIN inv_item_category c ON c.id = i.category_id ' : '';
    $unitCol = $hasUnit ? 'COALESCE(u.name_ar, i.unit_name)' : 'i.unit_name';
    $unitJoin = $hasUnit ? ' LEFT JOIN inv_unit u ON u.id = i.unit_id ' : '';

    $qtyExpr = $hasStock ? 'COALESCE(st.qty_on_hand, 0)' : '0';
    $stockJoin = $hasStock
        ? ' LEFT JOIN (
             SELECT item_id, COALESCE(SUM(qty_delta), 0) AS qty_on_hand
             FROM inv_stock_move
             WHERE warehouse_id = ?
             GROUP BY item_id
         ) st ON st.item_id = i.id '
        : '';

    $itemNoSql = inv_item_sql_material_number($pdo, 'i');
    $sql =
        "SELECT {$itemNoSql} AS item_sku,
                i.name_ar AS item_name,
                {$qtyExpr} AS qty,
                {$catCol} AS category_name,
                {$unitCol} AS unit_name,
                i.default_cost AS cost_price,
                i.default_sale AS sale_price
         FROM inv_item i
         {$stockJoin}
         {$catJoin}{$unitJoin}
         WHERE i.is_active = 1";

    $params = $hasStock ? [$warehouseId] : [];

    if ($positiveQtyOnly) {
        $sql .= ' AND ' . ($hasStock ? 'COALESCE(st.qty_on_hand, 0)' : '0') . ' > ' . $eps;
    }

    // ترتيب حسب اسم المادة ثم رقم المادة
    $sql .= " ORDER BY i.name_ar ASC, CAST({$itemNoSql} AS UNSIGNED) ASC, {$itemNoSql} ASC, i.id ASC";

    $st = $pdo->prepare($sql);
    $st->execute($params);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $out[] = [
            'item_sku' => (string) ($row['item_sku'] ?? ''),
            'item_name' => (string) ($row['item_name'] ?? ''),
            'qty' => (float) ($row['qty'] ?? 0),
            'category_name' => (string) ($row['category_name'] ?? ''),
            'unit_name' => (string) ($row['unit_name'] ?? ''),
            'cost_price' => (float) ($row['cost_price'] ?? 0),
            'sale_price' => (float) ($row['sale_price'] ?? 0),
        ];
    }

    return $out;
}

/**
 * مواد المستودع ذات رصيد صفر (بدون سالب).
 *
 * @return list<array{item_sku:string, item_name:string, qty:float}>
 */
function inv_report_warehouse_zero_qty_lines(PDO $pdo, int $warehouseId): array
{
    if ($warehouseId < 1) {
        return [];
    }

    inv_item_ensure_extended_schema($pdo);
    inv_stock_move_ensure_table($pdo);

    if (!inv_item_has_main_table($pdo, true)) {
        return [];
    }

    $hasStock = inv_stock_move_has_table($pdo);
    $eps = 0.000001;

    $qtyExpr = $hasStock ? 'COALESCE(st.qty_on_hand, 0)' : '0';
    $stockJoin = $hasStock
        ? ' LEFT JOIN (
             SELECT item_id, COALESCE(SUM(qty_delta), 0) AS qty_on_hand
             FROM inv_stock_move
             WHERE warehouse_id = ?
             GROUP BY item_id
         ) st ON st.item_id = i.id '
        : '';

    $itemNoSql = inv_item_sql_material_number($pdo, 'i');
    $sql =
        "SELECT {$itemNoSql} AS item_sku,
                i.name_ar AS item_name,
                {$qtyExpr} AS qty
         FROM inv_item i
         {$stockJoin}
         WHERE i.is_active = 1
           AND {$qtyExpr} >= ?
           AND {$qtyExpr} <= ?";

    $params = $hasStock ? [$warehouseId, -$eps, $eps] : [-$eps, $eps];

    $sql .= ' ORDER BY i.name_ar ASC, i.sku ASC';

    $st = $pdo->prepare($sql);
    $st->execute($params);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $out[] = [
            'item_sku' => (string) ($row['item_sku'] ?? ''),
            'item_name' => (string) ($row['item_name'] ?? ''),
            'qty' => (float) ($row['qty'] ?? 0),
        ];
    }

    return $out;
}

/**
 * مواد المستودع ذات رصيد سالب.
 *
 * @return list<array{item_sku:string, item_name:string, qty:float}>
 */
function inv_report_warehouse_negative_qty_lines(PDO $pdo, int $warehouseId): array
{
    if ($warehouseId < 1) {
        return [];
    }

    inv_item_ensure_extended_schema($pdo);
    inv_stock_move_ensure_table($pdo);

    if (!inv_item_has_main_table($pdo, true)) {
        return [];
    }

    $hasStock = inv_stock_move_has_table($pdo);
    if (!$hasStock) {
        return [];
    }

    $eps = 0.000001;

    $qtyExpr = 'COALESCE(st.qty_on_hand, 0)';
    $stockJoin =
        ' INNER JOIN (
             SELECT item_id, COALESCE(SUM(qty_delta), 0) AS qty_on_hand
             FROM inv_stock_move
             WHERE warehouse_id = ?
             GROUP BY item_id
         ) st ON st.item_id = i.id ';

    $itemNoSql = inv_item_sql_material_number($pdo, 'i');
    $sql =
        "SELECT {$itemNoSql} AS item_sku,
                i.name_ar AS item_name,
                {$qtyExpr} AS qty
         FROM inv_item i
         {$stockJoin}
         WHERE i.is_active = 1
           AND {$qtyExpr} < ?
         ORDER BY qty ASC, i.name_ar ASC, i.sku ASC";

    $st = $pdo->prepare($sql);
    $st->execute([$warehouseId, -$eps]);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $out[] = [
            'item_sku' => (string) ($row['item_sku'] ?? ''),
            'item_name' => (string) ($row['item_name'] ?? ''),
            'qty' => (float) ($row['qty'] ?? 0),
        ];
    }

    return $out;
}
