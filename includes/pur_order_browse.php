<?php
declare(strict_types=1);

require_once app_path('includes/pur_order_schema.php');

function pur_order_normalize_browse_filter(string $filter): string
{
    return in_array($filter, ['draft', 'pending', 'approved', 'open', 'closed', 'cancelled'], true)
        ? $filter
        : 'all';
}

function pur_order_browse_sql_condition(string $filter): string
{
    $filter = pur_order_normalize_browse_filter($filter);

    return match ($filter) {
        'draft' => "o.status IN ('draft','submitted')",
        'pending' => "o.status = 'submitted'",
        'approved' => "o.status IN ('approved','partial','closed')",
        'open' => "o.status IN ('approved','partial')",
        'closed' => "o.status = 'closed'",
        'cancelled' => "o.status = 'cancelled'",
        default => "o.status <> 'cancelled'",
    };
}

function pur_order_matches_browse_filter(PDO $pdo, int $id, string $filter): bool
{
    if ($id < 1) {
        return false;
    }
    pur_order_ensure_schema($pdo);
    $cond = pur_order_browse_sql_condition($filter);
    $st = $pdo->prepare("SELECT o.id FROM pur_order o WHERE o.id = ? AND ({$cond}) LIMIT 1");
    $st->execute([$id]);

    return (bool) $st->fetch();
}

function pur_order_nav_neighbor_id(PDO $pdo, int $id, string $dir, string $filter): ?int
{
    if ($id < 1) {
        return null;
    }
    $cond = pur_order_browse_sql_condition($filter);
    if ($dir === 'prev') {
        $sql = "SELECT o.id FROM pur_order o WHERE ({$cond}) AND o.id < ? ORDER BY o.id DESC LIMIT 1";
    } else {
        $sql = "SELECT o.id FROM pur_order o WHERE ({$cond}) AND o.id > ? ORDER BY o.id ASC LIMIT 1";
    }
    $st = $pdo->prepare($sql);
    $st->execute([$id]);
    $nid = $st->fetchColumn();

    return $nid !== false ? (int) $nid : null;
}

function pur_order_first_in_filter(PDO $pdo, string $filter): ?int
{
    $cond = pur_order_browse_sql_condition($filter);
    $st = $pdo->query("SELECT o.id FROM pur_order o WHERE {$cond} ORDER BY o.id DESC LIMIT 1");
    $id = $st->fetchColumn();

    return $id !== false ? (int) $id : null;
}

function pur_order_count_in_filter(PDO $pdo, string $filter): int
{
    $cond = pur_order_browse_sql_condition($filter);

    return (int) $pdo->query("SELECT COUNT(*) FROM pur_order o WHERE {$cond}")->fetchColumn();
}
