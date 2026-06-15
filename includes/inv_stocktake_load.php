<?php
declare(strict_types=1);

require_once app_path('includes/inv_stocktake_schema.php');
require_once app_path('includes/inv_stocktake_browse.php');
require_once app_path('includes/inv_item_inventory_unit_cost.php');

/** @return array<string,mixed>|null */
function inv_stocktake_fetch_by_id(PDO $pdo, int $id): ?array
{
    if ($id < 1 || !inv_stocktake_has_tables($pdo)) {
        return null;
    }
    $doc = inv_stocktake_doc_by_id($pdo, $id);
    if ($doc === null) {
        return null;
    }

    return inv_stocktake_enrich_for_api($pdo, $doc);
}

/** @return array<string,mixed>|null */
function inv_stocktake_fetch_by_no(PDO $pdo, string $no): ?array
{
    if (!inv_stocktake_has_tables($pdo)) {
        return null;
    }
    foreach (inv_stocktake_no_lookup_candidates($no) as $c) {
        $st = $pdo->prepare('SELECT id FROM inv_stocktake_doc WHERE take_no = ? LIMIT 1');
        $st->execute([$c]);
        $id = $st->fetchColumn();
        if ($id !== false) {
            return inv_stocktake_fetch_by_id($pdo, (int) $id);
        }
    }

    return null;
}

/** @param array<string,mixed> $doc @return array<string,mixed> */
function inv_stocktake_enrich_for_api(PDO $pdo, array $doc): array
{
    $id = (int) ($doc['id'] ?? 0);
    $doc['is_posted'] = (string) ($doc['status'] ?? '') === 'posted';
    $doc['take_date_display'] = format_date_dmY((string) ($doc['take_date'] ?? ''));
    $doc['prev_id'] = inv_stocktake_nav_neighbor_id($pdo, $id, 'prev') ?? 0;
    $doc['next_id'] = inv_stocktake_nav_neighbor_id($pdo, $id, 'next') ?? 0;
    $doc['browse_count'] = inv_stocktake_count_all($pdo);
    $takeDate = (string) ($doc['take_date'] ?? '');
    $doc['lines'] = array_map(static function (array $ln) use ($pdo, $takeDate): array {
        $itemId = (int) ($ln['item_id'] ?? 0);
        return [
            'item_id' => $itemId,
            'item_sku' => (string) ($ln['item_sku'] ?? ''),
            'item_name' => (string) ($ln['item_name'] ?? ''),
            'book_qty' => (float) ($ln['book_qty'] ?? 0),
            'counted_qty' => (float) ($ln['counted_qty'] ?? 0),
            'unit_cost' => $itemId > 0 ? inv_item_inventory_unit_cost($pdo, $itemId, $takeDate) : 0.0,
        ];
    }, inv_stocktake_doc_lines($pdo, $id));

    return $doc;
}
