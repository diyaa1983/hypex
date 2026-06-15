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
