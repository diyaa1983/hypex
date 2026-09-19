<?php
declare(strict_types=1);

require_once app_path('includes/inv_item_display.php');
require_once app_path('includes/sal_invoice_schema.php');
require_once app_path('includes/pur_invoice_schema.php');
require_once app_path('includes/inv_invoice_line_qty.php');
require_once app_path('includes/inv_stock.php');

/**
 * أرصدة المستودعات للمادة قبل تاريخ (من حركات المخزون).
 *
 * @return array<int, float> warehouse_id => qty on hand
 */
function inv_report_item_warehouse_balances_before(PDO $pdo, int $itemId, string $beforeDate): array
{
    if ($itemId < 1 || $beforeDate === '' || !inv_stock_move_has_table($pdo)) {
        return [];
    }

    inv_stock_move_ensure_table($pdo);
    $st = $pdo->prepare(
        'SELECT warehouse_id, COALESCE(SUM(qty_delta), 0) AS bal
         FROM inv_stock_move
         WHERE item_id = ? AND move_date < ?
         GROUP BY warehouse_id'
    );
    $st->execute([$itemId, $beforeDate]);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $whId = (int) ($row['warehouse_id'] ?? 0);
        if ($whId > 0) {
            $out[$whId] = (float) ($row['bal'] ?? 0);
        }
    }

    return $out;
}

/**
 * بنود فواتير بيع وشراء مؤكدة لمادة معينة بين تاريخين.
 *
 * @return list<array{
 *   invoice_no:string,
 *   invoice_date:string,
 *   item_sku:string,
 *   item_name:string,
 *   mov_type:string,
 *   mov_type_label:string,
 *   party_name:string,
 *   warehouse_id:int,
 *   warehouse_name:string,
 *   qty:float,
 *   warehouse_balance:?float,
 *   unit_price_excl:float
 * }>
 */
function inv_report_item_invoice_movements(PDO $pdo, int $itemId, string $from, string $to): array
{
    if ($itemId < 1 || $from === '' || $to === '') {
        return [];
    }

    sal_invoice_ensure_schema($pdo);
    pur_invoice_ensure_schema($pdo);
    inv_invoice_line_ensure_qty_extra($pdo);

    $qtyExtraSale = inv_invoice_line_has_qty_extra($pdo, 'sal_invoice_line')
        ? 'COALESCE(l.qty_extra, 0)'
        : '0';
    $qtyExtraPur = inv_invoice_line_has_qty_extra($pdo, 'pur_invoice_line')
        ? 'COALESCE(l.qty_extra, 0)'
        : '0';

    $itemNoSql = inv_item_sql_material_number($pdo, 'it');
    $stSale = $pdo->prepare(
        "SELECT i.id AS _inv_id, l.id AS _line_id, 1 AS _mov_sort,
                'sale' AS mov_type,
                i.invoice_no, i.invoice_date,
                COALESCE(i.warehouse_id, 0) AS warehouse_id,
                COALESCE(NULLIF(TRIM(w.name_ar), ''), '') AS warehouse_name,
                {$itemNoSql} AS item_sku,
                COALESCE(NULLIF(TRIM(l.line_desc), ''), it.name_ar) AS item_name,
                COALESCE(NULLIF(TRIM(c.name_ar), ''), '') AS party_name,
                l.qty, {$qtyExtraSale} AS qty_extra, l.unit_price
         FROM sal_invoice_line l
         INNER JOIN sal_invoice i ON i.id = l.invoice_id
         INNER JOIN inv_item it ON it.id = l.item_id
         LEFT JOIN crm_customer c ON c.id = i.customer_id
         LEFT JOIN inv_warehouse w ON w.id = i.warehouse_id
         WHERE l.item_id = ?
           AND i.status = 'confirmed'
           AND i.invoice_date >= ?
           AND i.invoice_date <= ?"
    );
    $stSale->execute([$itemId, $from, $to]);

    $stPur = $pdo->prepare(
        "SELECT i.id AS _inv_id, l.id AS _line_id, 2 AS _mov_sort,
                'purchase' AS mov_type,
                i.invoice_no, i.invoice_date,
                COALESCE(i.warehouse_id, 0) AS warehouse_id,
                COALESCE(NULLIF(TRIM(w.name_ar), ''), '') AS warehouse_name,
                {$itemNoSql} AS item_sku,
                COALESCE(NULLIF(TRIM(l.line_desc), ''), it.name_ar) AS item_name,
                COALESCE(NULLIF(TRIM(s.name_ar), ''), '') AS party_name,
                l.qty, {$qtyExtraPur} AS qty_extra, l.unit_price
         FROM pur_invoice_line l
         INNER JOIN pur_invoice i ON i.id = l.invoice_id
         INNER JOIN inv_item it ON it.id = l.item_id
         LEFT JOIN crm_supplier s ON s.id = i.supplier_id
         LEFT JOIN inv_warehouse w ON w.id = i.warehouse_id
         WHERE l.item_id = ?
           AND i.status = 'confirmed'
           AND i.invoice_date >= ?
           AND i.invoice_date <= ?"
    );
    $stPur->execute([$itemId, $from, $to]);

    $buf = [];
    foreach (array_merge($stSale->fetchAll(PDO::FETCH_ASSOC) ?: [], $stPur->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $movType = (string) ($row['mov_type'] ?? '');
        $stockQty = inv_invoice_line_stock_qty_sum(
            (float) ($row['qty'] ?? 0),
            (float) ($row['qty_extra'] ?? 0)
        );
        $buf[] = [
            'invoice_no' => (string) ($row['invoice_no'] ?? ''),
            'invoice_date' => (string) ($row['invoice_date'] ?? ''),
            'item_sku' => (string) ($row['item_sku'] ?? ''),
            'item_name' => (string) ($row['item_name'] ?? ''),
            'mov_type' => $movType,
            'mov_type_label' => $movType === 'purchase' ? 'مشتريات' : 'مبيعات',
            'party_name' => (string) ($row['party_name'] ?? ''),
            'warehouse_id' => (int) ($row['warehouse_id'] ?? 0),
            'warehouse_name' => (string) ($row['warehouse_name'] ?? ''),
            'qty' => $stockQty,
            'unit_price_excl' => (float) ($row['unit_price'] ?? 0),
            '_stock_delta' => $movType === 'sale' ? -$stockQty : $stockQty,
            '_inv_id' => (int) ($row['_inv_id'] ?? 0),
            '_line_id' => (int) ($row['_line_id'] ?? 0),
            '_mov_sort' => (int) ($row['_mov_sort'] ?? 0),
        ];
    }

    usort(
        $buf,
        static function (array $a, array $b): int {
            $c = strcmp($a['invoice_date'], $b['invoice_date']);
            if ($c !== 0) {
                return $c;
            }
            $c = ($a['_inv_id'] <=> $b['_inv_id']);
            if ($c !== 0) {
                return $c;
            }
            $c = ($a['_mov_sort'] <=> $b['_mov_sort']);
            if ($c !== 0) {
                return $c;
            }

            return ($a['_line_id'] <=> $b['_line_id']);
        }
    );

    $runningByWh = inv_report_item_warehouse_balances_before($pdo, $itemId, $from);

    $out = [];
    foreach ($buf as $r) {
        $whId = (int) ($r['warehouse_id'] ?? 0);
        $whBalance = null;
        if ($whId > 0) {
            $prev = (float) ($runningByWh[$whId] ?? 0.0);
            $whBalance = $prev + (float) ($r['_stock_delta'] ?? 0);
            $runningByWh[$whId] = $whBalance;
        }

        $out[] = [
            'invoice_no' => $r['invoice_no'],
            'invoice_date' => $r['invoice_date'],
            'item_sku' => $r['item_sku'],
            'item_name' => $r['item_name'],
            'mov_type' => $r['mov_type'],
            'mov_type_label' => $r['mov_type_label'],
            'party_name' => $r['party_name'],
            'warehouse_id' => $whId,
            'warehouse_name' => $r['warehouse_name'],
            'qty' => $r['qty'],
            'warehouse_balance' => $whBalance,
            'unit_price_excl' => $r['unit_price_excl'],
        ];
    }

    return $out;
}
