<?php
declare(strict_types=1);

require_once app_path('includes/inv_wh_move_schema.php');
require_once app_path('includes/inv_item_barcode.php');

/**
 * @return list<array<string, mixed>>
 */
function inv_report_warehouse_moves_lines(
    PDO $pdo,
    string $from,
    string $to,
    int $warehouseId = 0,
    string $movementTypeCode = ''
): array {
    if ($from === '' || $to === '') {
        return [];
    }
    if (!inv_wh_move_ensure_schema($pdo)) {
        return [];
    }

    $hasBarcode = inv_item_has_barcode_column($pdo);
    $barcodeSel = $hasBarcode ? 'it.barcode' : 'NULL AS barcode';

    $where = [
        'm.move_date >= ?',
        'm.move_date <= ?',
    ];
    $params = [$from, $to];

    if ($warehouseId > 0) {
        $where[] = '(m.warehouse_id = ? OR m.warehouse_to_id = ?)';
        $params[] = $warehouseId;
        $params[] = $warehouseId;
    }

    $movementTypeCode = trim($movementTypeCode);
    if ($movementTypeCode !== '' && $movementTypeCode !== '0') {
        $where[] = 'm.movement_type_code = ?';
        $params[] = $movementTypeCode;
    }

    $sql = "SELECT
                m.id,
                m.move_no,
                m.move_date,
                m.status,
                m.notes,
                m.movement_type_code,
                COALESCE(NULLIF(TRIM(mt.name_ar), ''), m.movement_type_code) AS movement_type_name,
                m.warehouse_id,
                m.warehouse_to_id,
                COALESCE(NULLIF(TRIM(wh_from.name_ar), ''), wh_from.code, '') AS warehouse_name,
                COALESCE(NULLIF(TRIM(wh_to.name_ar), ''), wh_to.code, '') AS warehouse_to_name,
                l.line_no,
                l.item_id,
                l.qty,
                it.sku,
                {$barcodeSel},
                COALESCE(NULLIF(TRIM(it.name_ar), ''), it.sku, '') AS item_name
            FROM inv_wh_move m
            INNER JOIN inv_wh_move_line l ON l.move_id = m.id
            LEFT JOIN inv_movement_type mt ON mt.code = m.movement_type_code
            INNER JOIN inv_warehouse wh_from ON wh_from.id = m.warehouse_id
            LEFT JOIN inv_warehouse wh_to ON wh_to.id = m.warehouse_to_id
            INNER JOIN inv_item it ON it.id = l.item_id
            WHERE " . implode(' AND ', $where) . '
            ORDER BY m.move_date ASC, m.id ASC, l.line_no ASC, l.id ASC';

    $st = $pdo->prepare($sql);
    $st->execute($params);

    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $qty = (float) ($row['qty'] ?? 0);
        $typeCode = (string) ($row['movement_type_code'] ?? '');
        $fromWhId = (int) ($row['warehouse_id'] ?? 0);
        $toWhId = (int) ($row['warehouse_to_id'] ?? 0);

        $directionLabel = '—';
        $qtyEffect = $qty;

        if ($typeCode === 'transfer') {
            if ($warehouseId > 0) {
                if ($warehouseId === $fromWhId && $warehouseId === $toWhId) {
                    $directionLabel = 'داخلي';
                    $qtyEffect = 0.0;
                } elseif ($warehouseId === $fromWhId) {
                    $directionLabel = 'صادر';
                    $qtyEffect = -$qty;
                } elseif ($warehouseId === $toWhId) {
                    $directionLabel = 'وارد';
                    $qtyEffect = $qty;
                } else {
                    $directionLabel = 'نقل';
                    $qtyEffect = 0.0;
                }
            } else {
                $directionLabel = 'نقل بين مستودعين';
                $qtyEffect = 0.0;
            }
        } elseif ($typeCode === 'adjust_in') {
            $directionLabel = 'وارد';
            $qtyEffect = $qty;
        } elseif ($typeCode === 'adjust_out' || $typeCode === 'disposal') {
            $directionLabel = 'صادر';
            $qtyEffect = -$qty;
        }

        $rows[] = [
            'id' => (int) ($row['id'] ?? 0),
            'move_no' => (string) ($row['move_no'] ?? ''),
            'move_date' => (string) ($row['move_date'] ?? ''),
            'status' => (string) ($row['status'] ?? 'draft'),
            'status_label' => (string) ($row['status'] ?? '') === 'posted' ? 'مرحّل' : 'مسودة',
            'movement_type_code' => $typeCode,
            'movement_type_name' => (string) ($row['movement_type_name'] ?? ''),
            'warehouse_id' => $fromWhId,
            'warehouse_to_id' => $toWhId,
            'warehouse_name' => (string) ($row['warehouse_name'] ?? ''),
            'warehouse_to_name' => (string) ($row['warehouse_to_name'] ?? ''),
            'line_no' => (int) ($row['line_no'] ?? 0),
            'item_id' => (int) ($row['item_id'] ?? 0),
            'item_name' => (string) ($row['item_name'] ?? ''),
            'sku' => (string) ($row['sku'] ?? ''),
            'barcode' => (string) ($row['barcode'] ?? ''),
            'qty' => $qty,
            'direction_label' => $directionLabel,
            'qty_effect' => $qtyEffect,
            'notes' => (string) ($row['notes'] ?? ''),
        ];
    }

    return $rows;
}
