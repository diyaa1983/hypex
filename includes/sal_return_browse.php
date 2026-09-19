<?php
declare(strict_types=1);

function sal_return_browse_sql_condition(string $alias = 'r'): string
{
    return "{$alias}.status <> 'cancelled'";
}

function sal_return_nav_neighbor_id(PDO $pdo, int $id, string $dir): ?int
{
    if ($id < 1) {
        return null;
    }

    $cond = sal_return_browse_sql_condition('r');
    if ($dir === 'prev') {
        $sql = "SELECT r.id FROM sal_return r WHERE ({$cond}) AND r.id < ? ORDER BY r.id DESC LIMIT 1";
    } else {
        $sql = "SELECT r.id FROM sal_return r WHERE ({$cond}) AND r.id > ? ORDER BY r.id ASC LIMIT 1";
    }

    $st = $pdo->prepare($sql);
    $st->execute([$id]);
    $nid = $st->fetchColumn();

    return $nid !== false ? (int) $nid : null;
}

function sal_return_first_id(PDO $pdo): ?int
{
    $cond = sal_return_browse_sql_condition('r');
    $st = $pdo->query("SELECT r.id FROM sal_return r WHERE {$cond} ORDER BY r.id DESC LIMIT 1");
    $id = $st->fetchColumn();

    return $id !== false ? (int) $id : null;
}

function sal_return_count_all(PDO $pdo): int
{
    $cond = sal_return_browse_sql_condition('r');

    return (int) $pdo->query("SELECT COUNT(*) FROM sal_return r WHERE {$cond}")->fetchColumn();
}

/** @return list<int> */
function sal_return_search_ids_by_no_fragment(PDO $pdo, string $fragment, int $limit = 200): array
{
    require_once app_path('includes/doc_no_fragment_search.php');
    $fragment = trim($fragment);
    if ($fragment === '') {
        return [];
    }

    $cond = sal_return_browse_sql_condition('r');
    $limit = max(1, min(500, $limit));

    return doc_no_search_ids_like(
        $pdo,
        "SELECT r.id FROM sal_return r
         WHERE ({$cond}) AND r.return_no LIKE ?
         ORDER BY r.return_no ASC, r.id ASC
         LIMIT {$limit}",
        [doc_no_sql_like_pattern($fragment)]
    );
}
