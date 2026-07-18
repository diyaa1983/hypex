<?php
declare(strict_types=1);

function doc_number_pool_ensure_table(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $pdo->query('SELECT id FROM doc_number_pool LIMIT 1');
        $ok = true;
    } catch (Throwable $e) {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/171_voucher_cancel_and_number_pool.sql');
        try {
            $pdo->query('SELECT id FROM doc_number_pool LIMIT 1');
            $ok = true;
        } catch (Throwable $e2) {
            $ok = false;
        }
    }

    return $ok;
}

function doc_number_pool_key_fin_voucher(string $type): string
{
    return 'fin_voucher:' . ($type === 'payment' ? 'payment' : 'receipt');
}

function doc_number_pool_key_journal(): string
{
    return 'acc_journal_entry';
}

function doc_number_pool_key_sal_invoice(): string
{
    return 'sal_invoice';
}

function doc_number_pool_key_sal_return(): string
{
    return 'sal_return';
}

function doc_number_pool_key_pur_invoice(): string
{
    return 'pur_invoice';
}

/** @return list<string> */
function doc_number_pool_take(PDO $pdo, string $poolKey, int $year, int $limit = 1): array
{
    if (!doc_number_pool_ensure_table($pdo) || $poolKey === '') {
        return [];
    }
    $st = $pdo->prepare(
        'SELECT id, doc_no FROM doc_number_pool
         WHERE pool_key = ? AND doc_year = ?
         ORDER BY doc_no ASC, id ASC
         LIMIT ' . max(1, min(50, $limit))
    );
    $st->execute([$poolKey, $year]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($rows === []) {
        return [];
    }
    $nos = [];
    $ids = [];
    foreach ($rows as $row) {
        $nos[] = (string) ($row['doc_no'] ?? '');
        $ids[] = (int) ($row['id'] ?? 0);
    }
    if ($ids !== []) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("DELETE FROM doc_number_pool WHERE id IN ({$placeholders})")->execute($ids);
    }

    return array_values(array_filter($nos, static fn (string $n): bool => $n !== ''));
}

function doc_number_pool_release(PDO $pdo, string $poolKey, string $docNo, string $dateIso): void
{
    $docNo = trim($docNo);
    if ($docNo === '' || $poolKey === '' || !doc_number_pool_ensure_table($pdo)) {
        return;
    }
    $ts = strtotime($dateIso);
    $year = $ts !== false ? (int) date('Y', $ts) : (int) date('Y');
    try {
        $pdo->prepare(
            'INSERT IGNORE INTO doc_number_pool (pool_key, doc_no, doc_year) VALUES (?,?,?)'
        )->execute([$poolKey, $docNo, $year]);
    } catch (Throwable $e) {
        //
    }
}

/** @return list<array{pool_key:string, doc_no:string, doc_year:int, created_at:string}> */
function doc_number_pool_list(PDO $pdo, ?string $poolKey = null, ?int $year = null): array
{
    if (!doc_number_pool_ensure_table($pdo)) {
        return [];
    }
    $where = [];
    $params = [];
    if ($poolKey !== null && $poolKey !== '') {
        $where[] = 'pool_key = ?';
        $params[] = $poolKey;
    }
    if ($year !== null && $year > 0) {
        $where[] = 'doc_year = ?';
        $params[] = $year;
    }
    $sql = 'SELECT pool_key, doc_no, doc_year, created_at FROM doc_number_pool';
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY pool_key ASC, doc_year ASC, doc_no ASC';
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
