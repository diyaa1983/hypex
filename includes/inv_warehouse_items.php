<?php
declare(strict_types=1);

require_once app_path('includes/inv_item_barcode.php');

/** المستودع الافتراضي للفواتير: الرمز MAIN ثم الاسم «رئيسي» ثم أول مستودع نشط. */
function inv_default_warehouse_id(PDO $pdo): ?int
{
    static $cached = -1;
    if ($cached >= 0) {
        return $cached > 0 ? $cached : null;
    }

    try {
        $st = $pdo->query(
            "SELECT id FROM inv_warehouse WHERE is_active = 1 AND UPPER(TRIM(code)) = 'MAIN' ORDER BY id LIMIT 1"
        );
        $id = (int) $st->fetchColumn();
        if ($id > 0) {
            $cached = $id;

            return $id;
        }

        $st = $pdo->query(
            "SELECT id FROM inv_warehouse WHERE is_active = 1 AND name_ar LIKE '%رئيس%' ORDER BY id LIMIT 1"
        );
        $id = (int) $st->fetchColumn();
        if ($id > 0) {
            $cached = $id;

            return $id;
        }

        $st = $pdo->query('SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY id LIMIT 1');
        $id = (int) $st->fetchColumn();
        $cached = $id > 0 ? $id : 0;

        return $id > 0 ? $id : null;
    } catch (Throwable $e) {
        $cached = 0;

        return null;
    }
}

/** هل وُجدت حركات مخزون لهذا المستودع؟ */
function inv_warehouse_has_stock_moves(PDO $pdo, int $warehouseId): bool
{
    if ($warehouseId < 1) {
        return false;
    }
    try {
        $st = $pdo->prepare('SELECT 1 FROM inv_stock_move WHERE warehouse_id = ? LIMIT 1');
        $st->execute([$warehouseId]);

        return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * بحث مواد المستودع مع عرض الرصيد (موجب أو سالب) — يُسمح بالبيع بالسالب.
 *
 * @return array{sql:string, params:array<int|string>, has_stock:bool}
 */
function inv_item_price_select_sql(bool $hasBarcode): string
{
    return $hasBarcode
        ? 'i.id, i.sku, i.barcode, i.name_ar, i.unit_name, i.default_cost, i.default_sale'
        : 'i.id, i.sku, i.name_ar, i.unit_name, i.default_cost, i.default_sale';
}

function inv_warehouse_items_search_query(PDO $pdo, int $warehouseId, string $q, bool $listAll): array
{
    $hasBarcode = inv_item_has_barcode_column($pdo);
    $select = inv_item_price_select_sql($hasBarcode);
    $params = [];
    $where = ['i.is_active = 1'];

    if ($warehouseId > 0) {
        $select .= ', COALESCE(stk.qty, 0) AS stock_qty';
        $from = 'inv_item i LEFT JOIN (
            SELECT item_id, SUM(qty_delta) AS qty
            FROM inv_stock_move
            WHERE warehouse_id = ?
            GROUP BY item_id
        ) stk ON stk.item_id = i.id';
        $params[] = $warehouseId;
        $hasStock = true;
    } else {
        $select .= ', NULL AS stock_qty';
        $from = 'inv_item i';
        $hasStock = false;
    }

    if ($q !== '') {
        $like = '%' . $q . '%';
        if ($hasBarcode) {
            $where[] = '(i.name_ar LIKE ? OR i.sku LIKE ? OR i.barcode LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        } else {
            $where[] = '(i.name_ar LIKE ? OR i.sku LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }
    } elseif (!$listAll) {
        return ['sql' => '', 'params' => [], 'has_stock' => $hasStock];
    }

    $sql = 'SELECT ' . $select . ' FROM ' . $from . ' WHERE ' . implode(' AND ', $where)
        . ' ORDER BY i.name_ar ASC LIMIT 80';

    return ['sql' => $sql, 'params' => $params, 'has_stock' => $hasStock];
}

/**
 * بحث مادة بالباركود أو SKU (تطابق تام) — بلا قيد default_warehouse.
 *
 * @return list<array<string, mixed>>
 */
function inv_items_find_by_code(PDO $pdo, string $code, int $warehouseId = 0): array
{
    $code = trim($code);
    if ($code === '') {
        return [];
    }

    $hasBarcode = inv_item_has_barcode_column($pdo);
    $select = inv_item_price_select_sql($hasBarcode);
    if ($hasBarcode) {
        $params = [$code, $code];
        $match = '(TRIM(i.sku) = ? OR TRIM(COALESCE(i.barcode, \'\')) = ?)';
    } else {
        $params = [$code];
        $match = 'TRIM(i.sku) = ?';
    }

    if ($warehouseId > 0) {
        $select .= ', COALESCE(stk.qty, 0) AS stock_qty';
        $sql = 'SELECT ' . $select . ' FROM inv_item i LEFT JOIN (
            SELECT item_id, SUM(qty_delta) AS qty
            FROM inv_stock_move
            WHERE warehouse_id = ?
            GROUP BY item_id
        ) stk ON stk.item_id = i.id
        WHERE i.is_active = 1 AND ' . $match . '
        ORDER BY i.name_ar ASC LIMIT 5';
        array_unshift($params, $warehouseId);
    } else {
        $select .= ', NULL AS stock_qty';
        $sql = 'SELECT ' . $select . ' FROM inv_item i
        WHERE i.is_active = 1 AND ' . $match . '
        ORDER BY i.name_ar ASC LIMIT 5';
    }

    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** مواد نشطة بدون فلتر مستودع (لا يوجد مستودعات في النظام). */
function inv_items_search_all_query(PDO $pdo, string $q, bool $listAll): array
{
    $hasBarcode = inv_item_has_barcode_column($pdo);
    $params = [];
    $where = ['is_active = 1'];

    if ($q !== '') {
        $like = '%' . $q . '%';
        if ($hasBarcode) {
            $where[] = '(name_ar LIKE ? OR sku LIKE ? OR barcode LIKE ?)';
            $params = [$like, $like, $like];
        } else {
            $where[] = '(name_ar LIKE ? OR sku LIKE ?)';
            $params = [$like, $like];
        }
    } elseif (!$listAll) {
        return ['sql' => '', 'params' => []];
    }

    $cols = $hasBarcode
        ? 'id, sku, barcode, name_ar, unit_name, default_cost, default_sale'
        : 'id, sku, name_ar, unit_name, default_cost, default_sale';

    $sql = 'SELECT ' . $cols . ' FROM inv_item WHERE ' . implode(' AND ', $where)
        . ' ORDER BY name_ar ASC LIMIT 80';

    return ['sql' => $sql, 'params' => $params];
}
