<?php
declare(strict_types=1);

require_once app_path('includes/inv_wh_move_schema.php');
require_once app_path('includes/inv_wh_move_browse.php');
require_once app_path('includes/inv_stock.php');

/** @return array<string, mixed>|null */
function inv_wh_move_fetch_by_id(PDO $pdo, int $id): ?array
{
    if ($id < 1 || !inv_wh_move_has_tables($pdo)) {
        return null;
    }

    $move = inv_wh_move_by_id($pdo, $id);
    if ($move === null) {
        return null;
    }

    return inv_wh_move_enrich_for_api($pdo, $move);
}

/** @return array<string, mixed>|null */
function inv_wh_move_fetch_by_no(PDO $pdo, string $moveNo): ?array
{
    if (!inv_wh_move_has_tables($pdo)) {
        return null;
    }

    foreach (inv_wh_move_no_lookup_candidates($moveNo) as $candidate) {
        $st = $pdo->prepare('SELECT id FROM inv_wh_move WHERE move_no = ? LIMIT 1');
        $st->execute([$candidate]);
        $id = $st->fetchColumn();
        if ($id !== false) {
            return inv_wh_move_fetch_by_id($pdo, (int) $id);
        }
    }

    return null;
}

/** @param array<string, mixed> $move */
function inv_wh_move_enrich_for_api(PDO $pdo, array $move): array
{
    $id = (int) ($move['id'] ?? 0);
    $warehouseId = (int) ($move['warehouse_id'] ?? 0);
    $status = (string) ($move['status'] ?? 'draft');

    $move['move_date_display'] = format_date_dmY((string) ($move['move_date'] ?? ''));
    $move['is_posted'] = $status === 'posted';
    $move['prev_id'] = inv_wh_move_nav_neighbor_id($pdo, $id, 'prev') ?? 0;
    $move['next_id'] = inv_wh_move_nav_neighbor_id($pdo, $id, 'next') ?? 0;
    $move['browse_count'] = inv_wh_move_count_all($pdo);

    require_once app_path('includes/inv_item_display.php');
    $lines = inv_wh_move_lines($pdo, $id);
    $apiLines = [];
    foreach ($lines as $ln) {
        $itemId = (int) ($ln['item_id'] ?? 0);
        $onHand = $warehouseId > 0 && $itemId > 0
            ? inv_stock_qty_on_hand($pdo, $warehouseId, $itemId)
            : 0.0;
        $apiLines[] = [
            'item_id' => $itemId,
            'sku' => inv_item_material_number_digits(
                (string) ($ln['barcode'] ?? ''),
                (string) ($ln['sku'] ?? '')
            ),
            'name_ar' => (string) ($ln['item_name'] ?? ''),
            'qty' => (float) ($ln['qty'] ?? 0),
            'on_hand' => company_round_amount($onHand),
        ];
    }
    $move['lines'] = $apiLines;

    return $move;
}
