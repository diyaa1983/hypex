<?php
declare(strict_types=1);

function inv_price_adj_nav_neighbor_id(PDO $pdo, int $id, string $dir): ?int
{
    if ($id < 1 || !inv_price_adj_has_doc_table($pdo)) {
        return null;
    }
    if ($dir === 'prev') {
        $sql = 'SELECT id FROM inv_price_adj_doc WHERE id < ? ORDER BY id DESC LIMIT 1';
    } else {
        $sql = 'SELECT id FROM inv_price_adj_doc WHERE id > ? ORDER BY id ASC LIMIT 1';
    }
    $st = $pdo->prepare($sql);
    $st->execute([$id]);
    $nid = $st->fetchColumn();

    return $nid !== false ? (int) $nid : null;
}

function inv_price_adj_first_id(PDO $pdo): ?int
{
    if (!inv_price_adj_has_doc_table($pdo)) {
        return null;
    }
    $id = $pdo->query('SELECT id FROM inv_price_adj_doc ORDER BY id DESC LIMIT 1')->fetchColumn();

    return $id !== false ? (int) $id : null;
}

function inv_price_adj_count_all(PDO $pdo): int
{
    if (!inv_price_adj_has_doc_table($pdo)) {
        return 0;
    }

    return (int) $pdo->query('SELECT COUNT(*) FROM inv_price_adj_doc')->fetchColumn();
}

/** @return list<string> */
function inv_price_adj_no_lookup_candidates(string $adjNo): array
{
    $adjNo = trim($adjNo);
    if ($adjNo === '') {
        return [];
    }
    $candidates = [$adjNo];
    if (preg_match('/^0+(\d+)$/', $adjNo, $m)) {
        $candidates[] = $m[1];
    }
    if (ctype_digit($adjNo)) {
        $candidates[] = str_pad($adjNo, 6, '0', STR_PAD_LEFT);
    }

    return array_values(array_unique($candidates));
}

/** @return list<int> */
function inv_price_adj_search_ids_by_no_fragment(PDO $pdo, string $fragment, int $limit = 200): array
{
    require_once app_path('includes/doc_no_fragment_search.php');
    $fragment = trim($fragment);
    if ($fragment === '') {
        return [];
    }

    $limit = max(1, min(500, $limit));

    return doc_no_search_ids_like(
        $pdo,
        "SELECT id FROM inv_price_adj_doc
         WHERE adj_no LIKE ?
         ORDER BY adj_no ASC, id ASC
         LIMIT {$limit}",
        [doc_no_sql_like_pattern($fragment)]
    );
}
