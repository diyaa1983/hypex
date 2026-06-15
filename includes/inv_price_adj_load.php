<?php
declare(strict_types=1);

require_once app_path('includes/inv_price_adj_schema.php');
require_once app_path('includes/inv_price_adj_browse.php');
require_once app_path('includes/inv_item_sale_price_adj.php');

function inv_price_adj_fetch_by_id(PDO $pdo, int $id): ?array
{
    if ($id < 1) {
        return null;
    }
    if (!inv_price_adj_schema_is_ready($pdo) && !inv_price_adj_ensure_schema($pdo)) {
        return null;
    }
    $doc = inv_price_adj_doc_by_id($pdo, $id);
    if ($doc === null) {
        return null;
    }

    return inv_price_adj_enrich_for_api($pdo, $doc);
}

function inv_price_adj_fetch_by_no(PDO $pdo, string $adjNo): ?array
{
    if (!inv_price_adj_schema_is_ready($pdo) && !inv_price_adj_ensure_schema($pdo)) {
        return null;
    }
    foreach (inv_price_adj_no_lookup_candidates($adjNo) as $candidate) {
        $st = $pdo->prepare('SELECT id FROM inv_price_adj_doc WHERE adj_no = ? LIMIT 1');
        $st->execute([$candidate]);
        $id = $st->fetchColumn();
        if ($id !== false) {
            return inv_price_adj_fetch_by_id($pdo, (int) $id);
        }
    }

    return null;
}

/** @param array<string, mixed> $doc */
function inv_price_adj_enrich_for_api(PDO $pdo, array $doc): array
{
    $id = (int) ($doc['id'] ?? 0);
    $status = (string) ($doc['status'] ?? 'draft');
    $doc['is_posted'] = $status === 'posted';
    $doc['adj_date_display'] = format_date_dmY((string) ($doc['adj_date'] ?? ''));
    $doc['prev_id'] = inv_price_adj_nav_neighbor_id($pdo, $id, 'prev') ?? 0;
    $doc['next_id'] = inv_price_adj_nav_neighbor_id($pdo, $id, 'next') ?? 0;
    $doc['browse_count'] = inv_price_adj_count_all($pdo);

    $linesOut = [];
    foreach (inv_price_adj_lines($pdo, $id) as $ln) {
        $linesOut[] = [
            'id' => (int) ($ln['id'] ?? 0),
            'line_no' => (int) ($ln['line_no'] ?? 0),
            'item_id' => (int) ($ln['item_id'] ?? 0),
            'item_sku' => (string) ($ln['item_sku'] ?? ''),
            'item_name' => (string) ($ln['item_name'] ?? ''),
            'old_sale_price' => (float) ($ln['old_sale_price'] ?? 0),
            'new_sale_price' => (float) ($ln['new_sale_price'] ?? 0),
            'old_sale_price_display' => inv_item_sale_price_adj_format_price((float) ($ln['old_sale_price'] ?? 0), $pdo),
            'new_sale_price_display' => inv_item_sale_price_adj_format_price((float) ($ln['new_sale_price'] ?? 0), $pdo),
            'tax_rate_percent' => (float) ($ln['tax_rate_percent'] ?? 0),
            'tax_display' => inv_item_sale_price_adj_format_tax((float) ($ln['tax_rate_percent'] ?? 0)),
        ];
    }
    $doc['lines'] = $linesOut;

    return $doc;
}
