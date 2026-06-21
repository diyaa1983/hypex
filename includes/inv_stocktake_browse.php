<?php
declare(strict_types=1);

require_once app_path('includes/inv_stocktake_schema.php');

function inv_stocktake_nav_neighbor_id(PDO $pdo, int $id, string $dir): ?int
{
    if ($id < 1) {
        return null;
    }
    $sql = $dir === 'prev'
        ? 'SELECT id FROM inv_stocktake_doc WHERE id < ? ORDER BY id DESC LIMIT 1'
        : 'SELECT id FROM inv_stocktake_doc WHERE id > ? ORDER BY id ASC LIMIT 1';
    $st = $pdo->prepare($sql);
    $st->execute([$id]);
    $out = $st->fetchColumn();

    return $out !== false ? (int) $out : null;
}

function inv_stocktake_first_id(PDO $pdo): ?int
{
    $id = $pdo->query('SELECT id FROM inv_stocktake_doc ORDER BY id DESC LIMIT 1')->fetchColumn();

    return $id !== false ? (int) $id : null;
}

function inv_stocktake_count_all(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM inv_stocktake_doc')->fetchColumn();
}

/** @return list<string> */
function inv_stocktake_no_lookup_candidates(string $takeNo): array
{
    $takeNo = trim($takeNo);
    if ($takeNo === '') {
        return [];
    }
    $out = [$takeNo];
    if (preg_match('/^0+(\d+)$/', $takeNo, $m)) {
        $out[] = $m[1];
    }
    if (ctype_digit($takeNo)) {
        $out[] = str_pad($takeNo, 6, '0', STR_PAD_LEFT);
    }

    return array_values(array_unique($out));
}

/** @return list<int> */
function inv_stocktake_search_ids_by_no_fragment(PDO $pdo, string $fragment, int $limit = 200): array
{
    require_once app_path('includes/doc_no_fragment_search.php');
    $fragment = trim($fragment);
    if ($fragment === '') {
        return [];
    }

    $limit = max(1, min(500, $limit));

    return doc_no_search_ids_like(
        $pdo,
        "SELECT id FROM inv_stocktake_doc
         WHERE take_no LIKE ?
         ORDER BY take_no ASC, id ASC
         LIMIT {$limit}",
        [doc_no_sql_like_pattern($fragment)]
    );
}
