<?php
declare(strict_types=1);

require_once app_path('includes/crm_customer_ledger.php');
require_once app_path('includes/sal_invoice_post.php');

function sal_invoice_normalize_browse_filter(string $filter): string
{
    return in_array($filter, ['unposted', 'posted'], true) ? $filter : 'all';
}

function sal_invoice_browse_sql_condition(PDO $pdo, string $filter): string
{
    $filter = sal_invoice_normalize_browse_filter($filter);
    $base = "i.status = 'confirmed'";

    if (!crm_ledger_has_table($pdo)) {
        return $base;
    }

    if ($filter === 'unposted') {
        return $base . ' AND NOT ' . sal_invoice_sql_is_posted_expr('i');
    }

    if ($filter === 'posted') {
        return $base . ' AND ' . sal_invoice_sql_is_posted_expr('i');
    }

    return $base;
}

function sal_invoice_matches_browse_filter(PDO $pdo, int $id, string $filter): bool
{
    if ($id < 1) {
        return false;
    }

    $cond = sal_invoice_browse_sql_condition($pdo, $filter);
    $st = $pdo->prepare("SELECT i.id FROM sal_invoice i WHERE i.id = ? AND ({$cond}) LIMIT 1");
    $st->execute([$id]);

    return (bool) $st->fetch();
}

function sal_invoice_nav_neighbor_id(PDO $pdo, int $id, string $dir, string $filter): ?int
{
    if ($id < 1) {
        return null;
    }

    $cond = sal_invoice_browse_sql_condition($pdo, $filter);
    if ($dir === 'prev') {
        $sql = "SELECT i.id FROM sal_invoice i WHERE ({$cond}) AND i.id < ? ORDER BY i.id DESC LIMIT 1";
    } else {
        $sql = "SELECT i.id FROM sal_invoice i WHERE ({$cond}) AND i.id > ? ORDER BY i.id ASC LIMIT 1";
    }

    $st = $pdo->prepare($sql);
    $st->execute([$id]);
    $nid = $st->fetchColumn();

    return $nid !== false ? (int) $nid : null;
}

function sal_invoice_first_in_filter(PDO $pdo, string $filter): ?int
{
    $cond = sal_invoice_browse_sql_condition($pdo, $filter);
    $st = $pdo->query("SELECT i.id FROM sal_invoice i WHERE {$cond} ORDER BY i.id DESC LIMIT 1");
    $id = $st->fetchColumn();

    return $id !== false ? (int) $id : null;
}

function sal_invoice_count_in_filter(PDO $pdo, string $filter): int
{
    $cond = sal_invoice_browse_sql_condition($pdo, $filter);

    return (int) $pdo->query("SELECT COUNT(*) FROM sal_invoice i WHERE {$cond}")->fetchColumn();
}

/** @return list<int> */
function sal_invoice_search_ids_by_no_fragment(PDO $pdo, string $fragment, string $filter, int $limit = 200): array
{
    require_once app_path('includes/doc_no_fragment_search.php');
    $fragment = trim($fragment);
    if ($fragment === '') {
        return [];
    }

    $cond = sal_invoice_browse_sql_condition($pdo, $filter);
    $limit = max(1, min(500, $limit));

    return doc_no_search_ids_like(
        $pdo,
        "SELECT i.id FROM sal_invoice i
         WHERE ({$cond}) AND i.invoice_no LIKE ?
         ORDER BY i.invoice_no ASC, i.id ASC
         LIMIT {$limit}",
        [doc_no_sql_like_pattern($fragment)]
    );
}
