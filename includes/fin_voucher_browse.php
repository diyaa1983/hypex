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

/** @return list<int> */
function fin_voucher_search_ids_by_no_fragment(PDO $pdo, string $fragment, string $type, int $limit = 200): array
{
    require_once app_path('includes/doc_no_fragment_search.php');
    $fragment = trim($fragment);
    if ($fragment === '' || !fin_voucher_type_valid($type)) {
        return [];
    }

    $limit = max(1, min(500, $limit));

    return doc_no_search_ids_like(
        $pdo,
        "SELECT id FROM fin_voucher
         WHERE voucher_type = ? AND voucher_no LIKE ?
         ORDER BY voucher_no ASC, id ASC
         LIMIT {$limit}",
        [$type, doc_no_sql_like_pattern($fragment)]
    );
}
