<?php
declare(strict_types=1);

function sal_delivery_nav_neighbor_id(PDO $pdo, int $currentId, string $dir): ?int
{
    if ($currentId < 1) {
        return null;
    }
    if ($dir === 'prev') {
        $st = $pdo->prepare('SELECT id FROM sal_delivery WHERE id < ? ORDER BY id DESC LIMIT 1');
    } else {
        $st = $pdo->prepare('SELECT id FROM sal_delivery WHERE id > ? ORDER BY id ASC LIMIT 1');
    }
    $st->execute([$currentId]);
    $id = $st->fetchColumn();

    return $id !== false ? (int) $id : null;
}

function sal_delivery_count_all(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM sal_delivery')->fetchColumn();
}

function sal_delivery_first_id(PDO $pdo): ?int
{
    $st = $pdo->query('SELECT id FROM sal_delivery ORDER BY id ASC LIMIT 1');
    $id = $st->fetchColumn();

    return $id !== false ? (int) $id : null;
}

/** @return list<int> */
function sal_delivery_search_ids_by_no_fragment(PDO $pdo, string $fragment, int $limit = 200): array
{
    require_once app_path('includes/doc_no_fragment_search.php');
    $fragment = trim($fragment);
    if ($fragment === '') {
        return [];
    }

    $limit = max(1, min(500, $limit));

    return doc_no_search_ids_like(
        $pdo,
        "SELECT id FROM sal_delivery
         WHERE delivery_no LIKE ?
         ORDER BY delivery_no ASC, id ASC
         LIMIT {$limit}",
        [doc_no_sql_like_pattern($fragment)]
    );
}
