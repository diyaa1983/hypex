<?php
declare(strict_types=1);

require_once app_path('includes/crm_supplier_ledger.php');
require_once app_path('includes/pur_invoice_post.php');

function pur_invoice_normalize_browse_filter(string $filter): string
{
    return in_array($filter, ['unposted', 'posted'], true) ? $filter : 'all';
}

function pur_invoice_browse_sql_condition(PDO $pdo, string $filter): string
{
    $filter = pur_invoice_normalize_browse_filter($filter);
    $base = "i.status = 'confirmed'";

    if (!crm_supplier_ledger_has_table($pdo)) {
        return $base;
    }

    if ($filter === 'unposted') {
        return $base . ' AND NOT ' . pur_invoice_sql_is_posted_expr('i');
    }

    if ($filter === 'posted') {
        return $base . ' AND ' . pur_invoice_sql_is_posted_expr('i');
    }

    return $base;
}

function pur_invoice_matches_browse_filter(PDO $pdo, int $id, string $filter): bool
{
    if ($id < 1) {
        return false;
    }

    $cond = pur_invoice_browse_sql_condition($pdo, $filter);
    $st = $pdo->prepare("SELECT i.id FROM pur_invoice i WHERE i.id = ? AND ({$cond}) LIMIT 1");
    $st->execute([$id]);

    return (bool) $st->fetch();
}

function pur_invoice_nav_neighbor_id(PDO $pdo, int $id, string $dir, string $filter): ?int
{
    if ($id < 1) {
        return null;
    }

    $cond = pur_invoice_browse_sql_condition($pdo, $filter);
    if ($dir === 'prev') {
        $sql = "SELECT i.id FROM pur_invoice i WHERE ({$cond}) AND i.id < ? ORDER BY i.id DESC LIMIT 1";
    } else {
        $sql = "SELECT i.id FROM pur_invoice i WHERE ({$cond}) AND i.id > ? ORDER BY i.id ASC LIMIT 1";
    }

    $st = $pdo->prepare($sql);
    $st->execute([$id]);
    $nid = $st->fetchColumn();

    return $nid !== false ? (int) $nid : null;
}

function pur_invoice_first_in_filter(PDO $pdo, string $filter): ?int
{
    $cond = pur_invoice_browse_sql_condition($pdo, $filter);
    $st = $pdo->query("SELECT i.id FROM pur_invoice i WHERE {$cond} ORDER BY i.id DESC LIMIT 1");
    $id = $st->fetchColumn();

    return $id !== false ? (int) $id : null;
}

function pur_invoice_count_in_filter(PDO $pdo, string $filter): int
{
    $cond = pur_invoice_browse_sql_condition($pdo, $filter);

    return (int) $pdo->query("SELECT COUNT(*) FROM pur_invoice i WHERE {$cond}")->fetchColumn();
}
