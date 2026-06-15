<?php
declare(strict_types=1);

function pur_return_browse_sql_condition(string $alias = 'r'): string
{
    return "{$alias}.status = 'confirmed'";
}

function pur_return_nav_neighbor_id(PDO $pdo, int $id, string $dir): ?int
{
    if ($id < 1) {
        return null;
    }

    $cond = pur_return_browse_sql_condition('r');
    if ($dir === 'prev') {
        $sql = "SELECT r.id FROM pur_return r WHERE ({$cond}) AND r.id < ? ORDER BY r.id DESC LIMIT 1";
    } else {
        $sql = "SELECT r.id FROM pur_return r WHERE ({$cond}) AND r.id > ? ORDER BY r.id ASC LIMIT 1";
    }

    $st = $pdo->prepare($sql);
    $st->execute([$id]);
    $nid = $st->fetchColumn();

    return $nid !== false ? (int) $nid : null;
}

function pur_return_first_id(PDO $pdo): ?int
{
    $cond = pur_return_browse_sql_condition('r');
    $st = $pdo->query("SELECT r.id FROM pur_return r WHERE {$cond} ORDER BY r.id DESC LIMIT 1");
    $id = $st->fetchColumn();

    return $id !== false ? (int) $id : null;
}

function pur_return_count_all(PDO $pdo): int
{
    $cond = pur_return_browse_sql_condition('r');

    return (int) $pdo->query("SELECT COUNT(*) FROM pur_return r WHERE {$cond}")->fetchColumn();
}
