<?php
declare(strict_types=1);

function fin_voucher_nav_neighbor_id(PDO $pdo, int $currentId, string $type, string $dir): ?int
{
    if ($currentId < 1 || !fin_voucher_type_valid($type)) {
        return null;
    }
    if ($dir === 'prev') {
        $st = $pdo->prepare(
            'SELECT id FROM fin_voucher WHERE voucher_type = ? AND id < ? ORDER BY id DESC LIMIT 1'
        );
    } else {
        $st = $pdo->prepare(
            'SELECT id FROM fin_voucher WHERE voucher_type = ? AND id > ? ORDER BY id ASC LIMIT 1'
        );
    }
    $st->execute([$type, $currentId]);
    $id = $st->fetchColumn();

    return $id !== false ? (int) $id : null;
}

function fin_voucher_latest_id(PDO $pdo, string $type): ?int
{
    if (!fin_voucher_type_valid($type)) {
        return null;
    }
    $st = $pdo->prepare('SELECT id FROM fin_voucher WHERE voucher_type = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$type]);
    $id = $st->fetchColumn();

    return $id !== false ? (int) $id : null;
}

/** أقدم سند (أصغر id) — للسهم الأيسر عند فتح سند جديد فارغ. */
function fin_voucher_oldest_id(PDO $pdo, string $type): ?int
{
    if (!fin_voucher_type_valid($type)) {
        return null;
    }
    $st = $pdo->prepare('SELECT id FROM fin_voucher WHERE voucher_type = ? ORDER BY id ASC LIMIT 1');
    $st->execute([$type]);
    $id = $st->fetchColumn();

    return $id !== false ? (int) $id : null;
}

function fin_voucher_first_id(PDO $pdo, string $type): ?int
{
    return fin_voucher_latest_id($pdo, $type);
}
