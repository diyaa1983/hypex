<?php
declare(strict_types=1);

function inv_wh_move_nav_neighbor_id(PDO $pdo, int $id, string $dir): ?int
{
    if ($id < 1) {
        return null;
    }

    if ($dir === 'prev') {
        $sql = 'SELECT id FROM inv_wh_move WHERE id < ? ORDER BY id DESC LIMIT 1';
    } else {
        $sql = 'SELECT id FROM inv_wh_move WHERE id > ? ORDER BY id ASC LIMIT 1';
    }

    $st = $pdo->prepare($sql);
    $st->execute([$id]);
    $nid = $st->fetchColumn();

    return $nid !== false ? (int) $nid : null;
}

function inv_wh_move_first_id(PDO $pdo): ?int
{
    $id = $pdo->query('SELECT id FROM inv_wh_move ORDER BY id DESC LIMIT 1')->fetchColumn();

    return $id !== false ? (int) $id : null;
}

function inv_wh_move_count_all(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM inv_wh_move')->fetchColumn();
}

/** @return list<string> */
function inv_wh_move_no_lookup_candidates(string $moveNo): array
{
    $moveNo = trim($moveNo);
    if ($moveNo === '') {
        return [];
    }

    $out = [$moveNo];
    if (preg_match('/^(\d+)$/', $moveNo, $m)) {
        $digits = $m[1];
        $out[] = str_pad($digits, 6, '0', STR_PAD_LEFT);
        $trimmed = ltrim($digits, '0');
        if ($trimmed === '') {
            $trimmed = '0';
        }
        $out[] = $trimmed;
    }

    return array_values(array_unique($out));
}
