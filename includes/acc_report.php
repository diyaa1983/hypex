<?php
declare(strict_types=1);

require_once app_path('includes/acc_gl.php');
require_once app_path('includes/acc_report_ref.php');

/**
 * @return array{debit: float, credit: float}
 */
function acc_report_split_balance(float $balance): array
{
    return [
        'debit' => $balance > 0.0000005 ? round($balance, 6) : 0.0,
        'credit' => $balance < -0.0000005 ? round(abs($balance), 6) : 0.0,
    ];
}

/**
 * @return array{sum_debit: float, sum_credit: float, balance: float}
 */
function acc_report_account_sums(
    PDO $pdo,
    int $accountId,
    ?string $dateFrom = null,
    ?string $dateTo = null,
    bool $beforeDateFrom = false
): array {
    if ($accountId < 1 || !acc_journal_has_tables($pdo)) {
        return ['sum_debit' => 0.0, 'sum_credit' => 0.0, 'balance' => 0.0];
    }

    $sql = 'SELECT COALESCE(SUM(l.debit), 0) AS sum_debit, COALESCE(SUM(l.credit), 0) AS sum_credit
            FROM acc_journal_line l
            INNER JOIN acc_journal_entry e ON e.id = l.journal_id
            WHERE l.account_id = ? AND e.status = \'posted\'';
    $params = [$accountId];

    if ($beforeDateFrom && $dateFrom !== null && $dateFrom !== '') {
        $sql .= ' AND e.entry_date < ?';
        $params[] = $dateFrom;
    } else {
        if ($dateFrom !== null && $dateFrom !== '') {
            $sql .= ' AND e.entry_date >= ?';
            $params[] = $dateFrom;
        }
        if ($dateTo !== null && $dateTo !== '') {
            $sql .= ' AND e.entry_date <= ?';
            $params[] = $dateTo;
        }
    }

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $d = (float) ($row['sum_debit'] ?? 0);
    $c = (float) ($row['sum_credit'] ?? 0);

    return [
        'sum_debit' => $d,
        'sum_credit' => $c,
        'balance' => round($d - $c, 6),
    ];
}

/**
 * ميزان مراجعة للفترة فقط (للتوافق مع الإصدار السابق).
 *
 * @return list<array<string, mixed>>
 */
function acc_report_trial_balance(PDO $pdo, string $dateFrom, string $dateTo): array
{
    $full = acc_report_trial_balance_full($pdo, $dateFrom, $dateTo);

    return array_values(array_filter($full, static function (array $row): bool {
        return (float) ($row['period_debit'] ?? 0) > 0
            || (float) ($row['period_credit'] ?? 0) > 0;
    }));
}

/**
 * ميزان مراجعة كامل: افتتاحي + حركة الفترة + ختامي.
 *
 * @return list<array<string, mixed>>
 */
function acc_report_trial_balance_full(PDO $pdo, string $dateFrom, string $dateTo): array
{
    if (!acc_journal_has_tables($pdo)) {
        return [];
    }

    $accounts = $pdo->query(
        'SELECT id, code, name_ar, account_type
         FROM acc_account
         WHERE is_active = 1
         ORDER BY code ASC'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $rows = [];
    foreach ($accounts as $acc) {
        $accountId = (int) $acc['id'];
        $opening = acc_report_account_sums($pdo, $accountId, $dateFrom, null, true);
        $period = acc_report_account_sums($pdo, $accountId, $dateFrom, $dateTo, false);
        $closingBal = round($opening['balance'] + $period['balance'], 6);

        if (
            abs($opening['balance']) < 0.000001
            && abs($period['sum_debit']) < 0.000001
            && abs($period['sum_credit']) < 0.000001
        ) {
            continue;
        }

        $openSplit = acc_report_split_balance($opening['balance']);
        $closeSplit = acc_report_split_balance($closingBal);

        $rows[] = [
            'id' => $accountId,
            'code' => (string) $acc['code'],
            'name_ar' => (string) $acc['name_ar'],
            'account_type' => (string) $acc['account_type'],
            'opening_debit' => $openSplit['debit'],
            'opening_credit' => $openSplit['credit'],
            'period_debit' => $period['sum_debit'],
            'period_credit' => $period['sum_credit'],
            'closing_debit' => $closeSplit['debit'],
            'closing_credit' => $closeSplit['credit'],
            'debit_balance' => $closeSplit['debit'],
            'credit_balance' => $closeSplit['credit'],
        ];
    }

    return $rows;
}

/**
 * مجاميع فرعية لحساب (من أوراق الشجرة فقط).
 *
 * @param array<int, array<string, mixed>> $leafById
 * @param array<int, list<int>> $childrenOf
 * @return array{opening_debit:float, opening_credit:float, period_debit:float, period_credit:float, closing_debit:float, closing_credit:float}
 */
function acc_report_trial_balance_sum_subtree(int $accountId, array $leafById, array $childrenOf, array $metaById): array
{
    $zero = [
        'opening_debit' => 0.0,
        'opening_credit' => 0.0,
        'period_debit' => 0.0,
        'period_credit' => 0.0,
        'closing_debit' => 0.0,
        'closing_credit' => 0.0,
    ];
    $meta = $metaById[$accountId] ?? null;
    if (!$meta) {
        return $zero;
    }
    if ((int) ($meta['is_leaf'] ?? 0) === 1) {
        return $leafById[$accountId] ?? $zero;
    }
    $sum = $zero;
    foreach ($childrenOf[$accountId] ?? [] as $childId) {
        $part = acc_report_trial_balance_sum_subtree($childId, $leafById, $childrenOf, $metaById);
        foreach ($sum as $k => $_) {
            $sum[$k] += (float) ($part[$k] ?? 0);
        }
    }

    return $sum;
}

/**
 * ميزان مراجعة إجمالي: حسابات المجموعات (غير نهائية) بمجاميع فروعها.
 *
 * @return list<array<string, mixed>>
 */
function acc_report_trial_balance_summary(PDO $pdo, string $dateFrom, string $dateTo): array
{
    if (!acc_journal_has_tables($pdo)) {
        return [];
    }

    $detail = acc_report_trial_balance_full($pdo, $dateFrom, $dateTo);
    $leafById = [];
    foreach ($detail as $row) {
        $leafById[(int) ($row['id'] ?? 0)] = $row;
    }

    $accounts = $pdo->query(
        'SELECT id, code, name_ar, parent_id, is_leaf, account_type
         FROM acc_account
         WHERE is_active = 1
         ORDER BY code ASC'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $metaById = [];
    $childrenOf = [0 => []];
    foreach ($accounts as $acc) {
        $id = (int) $acc['id'];
        $pid = $acc['parent_id'] === null || $acc['parent_id'] === '' ? 0 : (int) $acc['parent_id'];
        $metaById[$id] = [
            'id' => $id,
            'code' => (string) $acc['code'],
            'name_ar' => (string) $acc['name_ar'],
            'parent_id' => $pid > 0 ? $pid : null,
            'is_leaf' => (int) $acc['is_leaf'],
            'account_type' => (string) $acc['account_type'],
        ];
        if (!isset($childrenOf[$pid])) {
            $childrenOf[$pid] = [];
        }
        $childrenOf[$pid][] = $id;
        if (!isset($childrenOf[$id])) {
            $childrenOf[$id] = [];
        }
    }

    $depthOf = [];
    $walkDepth = static function (int $id, int $depth) use (&$walkDepth, &$depthOf, $childrenOf): void {
        $depthOf[$id] = $depth;
        foreach ($childrenOf[$id] ?? [] as $cid) {
            $walkDepth($cid, $depth + 1);
        }
    };
    foreach ($childrenOf[0] ?? [] as $rootId) {
        $walkDepth($rootId, 0);
    }

    $rows = [];
    foreach ($metaById as $id => $meta) {
        if ((int) ($meta['is_leaf'] ?? 0) === 1) {
            continue;
        }
        $sums = acc_report_trial_balance_sum_subtree($id, $leafById, $childrenOf, $metaById);
        if (
            abs($sums['opening_debit']) < 0.000001
            && abs($sums['opening_credit']) < 0.000001
            && abs($sums['period_debit']) < 0.000001
            && abs($sums['period_credit']) < 0.000001
            && abs($sums['closing_debit']) < 0.000001
            && abs($sums['closing_credit']) < 0.000001
        ) {
            continue;
        }
        $rows[] = [
            'id' => $id,
            'code' => (string) $meta['code'],
            'name_ar' => (string) $meta['name_ar'],
            'account_type' => (string) $meta['account_type'],
            'is_leaf' => 0,
            'depth' => (int) ($depthOf[$id] ?? 0),
            'opening_debit' => $sums['opening_debit'],
            'opening_credit' => $sums['opening_credit'],
            'period_debit' => $sums['period_debit'],
            'period_credit' => $sums['period_credit'],
            'closing_debit' => $sums['closing_debit'],
            'closing_credit' => $sums['closing_credit'],
        ];
    }

    usort($rows, static function (array $a, array $b): int {
        return strcmp((string) $a['code'], (string) $b['code']);
    });

    return $rows;
}

/**
 * ميزان مراجعة تفصيلي: عرض هرمي كامل (حسابات المجموعات + الحسابات النهائية)
 * بترتيب الشجرة، مع مجاميع المجموعات من فروعها.
 *
 * @return list<array<string, mixed>>
 */
function acc_report_trial_balance_detailed(PDO $pdo, string $dateFrom, string $dateTo): array
{
    if (!acc_journal_has_tables($pdo)) {
        return [];
    }

    $detail = acc_report_trial_balance_full($pdo, $dateFrom, $dateTo);
    $leafById = [];
    foreach ($detail as $row) {
        $leafById[(int) ($row['id'] ?? 0)] = $row;
    }

    $accounts = $pdo->query(
        'SELECT id, code, name_ar, parent_id, is_leaf, account_type
         FROM acc_account
         WHERE is_active = 1
         ORDER BY code ASC'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $metaById = [];
    $childrenOf = [0 => []];
    foreach ($accounts as $acc) {
        $id = (int) $acc['id'];
        $pid = $acc['parent_id'] === null || $acc['parent_id'] === '' ? 0 : (int) $acc['parent_id'];
        $metaById[$id] = [
            'id' => $id,
            'code' => (string) $acc['code'],
            'name_ar' => (string) $acc['name_ar'],
            'parent_id' => $pid > 0 ? $pid : null,
            'is_leaf' => (int) $acc['is_leaf'],
            'account_type' => (string) $acc['account_type'],
        ];
        if (!isset($childrenOf[$pid])) {
            $childrenOf[$pid] = [];
        }
        $childrenOf[$pid][] = $id;
        if (!isset($childrenOf[$id])) {
            $childrenOf[$id] = [];
        }
    }

    foreach ($childrenOf as $pid => $kids) {
        usort($childrenOf[$pid], static function (int $a, int $b) use ($metaById): int {
            return strcmp((string) ($metaById[$a]['code'] ?? ''), (string) ($metaById[$b]['code'] ?? ''));
        });
    }

    $depthOf = [];
    $walkDepth = static function (int $id, int $depth) use (&$walkDepth, &$depthOf, $childrenOf): void {
        $depthOf[$id] = $depth;
        foreach ($childrenOf[$id] ?? [] as $cid) {
            $walkDepth($cid, $depth + 1);
        }
    };
    foreach ($childrenOf[0] ?? [] as $rootId) {
        $walkDepth($rootId, 0);
    }

    $hasMovement = static function (array $sums): bool {
        foreach (['opening_debit', 'opening_credit', 'period_debit', 'period_credit', 'closing_debit', 'closing_credit'] as $k) {
            if (abs((float) ($sums[$k] ?? 0)) > 0.000001) {
                return true;
            }
        }

        return false;
    };

    $rows = [];
    $walk = null;
    $walk = static function (int $id) use (&$walk, &$rows, $leafById, $childrenOf, $metaById, $depthOf, $hasMovement): void {
        $meta = $metaById[$id] ?? null;
        if (!$meta) {
            return;
        }
        $isLeaf = (int) ($meta['is_leaf'] ?? 0) === 1;
        if ($isLeaf) {
            $leaf = $leafById[$id] ?? null;
            if (!$leaf) {
                return;
            }
            $rows[] = array_merge($leaf, [
                'depth' => (int) ($depthOf[$id] ?? 0),
                'is_leaf' => 1,
                'is_group' => 0,
            ]);

            return;
        }

        $sums = acc_report_trial_balance_sum_subtree($id, $leafById, $childrenOf, $metaById);
        if (!$hasMovement($sums)) {
            return;
        }
        $rows[] = array_merge([
            'id' => $id,
            'code' => (string) $meta['code'],
            'name_ar' => (string) $meta['name_ar'],
            'account_type' => (string) $meta['account_type'],
            'is_leaf' => 0,
            'is_group' => 1,
            'depth' => (int) ($depthOf[$id] ?? 0),
        ], $sums);

        foreach ($childrenOf[$id] ?? [] as $childId) {
            $walk($childId);
        }
    };

    $rootIds = $childrenOf[0] ?? [];
    usort($rootIds, static function (int $a, int $b) use ($metaById): int {
        return strcmp((string) ($metaById[$a]['code'] ?? ''), (string) ($metaById[$b]['code'] ?? ''));
    });
    foreach ($rootIds as $rootId) {
        $walk($rootId);
    }

    return $rows;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return array{opening_debit:float, opening_credit:float, period_debit:float, period_credit:float, closing_debit:float, closing_credit:float}
 */
function acc_report_trial_balance_totals(array $rows): array
{
    $t = [
        'opening_debit' => 0.0,
        'opening_credit' => 0.0,
        'period_debit' => 0.0,
        'period_credit' => 0.0,
        'closing_debit' => 0.0,
        'closing_credit' => 0.0,
    ];
    foreach ($rows as $r) {
        $t['opening_debit'] += (float) ($r['opening_debit'] ?? 0);
        $t['opening_credit'] += (float) ($r['opening_credit'] ?? 0);
        $t['period_debit'] += (float) ($r['period_debit'] ?? 0);
        $t['period_credit'] += (float) ($r['period_credit'] ?? 0);
        $t['closing_debit'] += (float) ($r['closing_debit'] ?? 0);
        $t['closing_credit'] += (float) ($r['closing_credit'] ?? 0);
    }

    return $t;
}

/**
 * @return list<array<string, mixed>>
 */
function acc_report_general_ledger(PDO $pdo, int $accountId, string $dateFrom, string $dateTo): array
{
    if ($accountId < 1 || !acc_journal_has_tables($pdo)) {
        return [];
    }

    $opening = acc_report_account_sums($pdo, $accountId, $dateFrom, null, true);
    $running = $opening['balance'];

    $st = $pdo->prepare(
        'SELECT e.id AS journal_id, e.entry_no, e.entry_date, e.description_ar, e.source, e.ref_type, e.ref_id,
                l.debit, l.credit, l.memo
         FROM acc_journal_line l
         INNER JOIN acc_journal_entry e ON e.id = l.journal_id
         WHERE l.account_id = ? AND e.status = \'posted\'
           AND e.entry_date >= ? AND e.entry_date <= ?
         ORDER BY e.entry_date ASC, e.id ASC, l.id ASC'
    );
    $st->execute([$accountId, $dateFrom, $dateTo]);
    $lines = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $rows = [];
    foreach ($lines as $ln) {
        $debit = (float) ($ln['debit'] ?? 0);
        $credit = (float) ($ln['credit'] ?? 0);
        $running = round($running + $debit - $credit, 6);
        $rows[] = [
            'journal_id' => (int) ($ln['journal_id'] ?? 0),
            'entry_no' => (string) ($ln['entry_no'] ?? ''),
            'entry_date' => (string) ($ln['entry_date'] ?? ''),
            'description_ar' => (string) ($ln['description_ar'] ?? ''),
            'source' => (string) ($ln['source'] ?? 'manual'),
            'ref_type' => (string) ($ln['ref_type'] ?? ''),
            'ref_id' => (int) ($ln['ref_id'] ?? 0),
            'debit' => $debit,
            'credit' => $credit,
            'memo' => (string) ($ln['memo'] ?? ''),
            'running_balance' => $running,
        ];
    }

    return $rows;
}

/**
 * @return array{opening: array{sum_debit: float, sum_credit: float, balance: float}, lines: list<array<string, mixed>>, closing_balance: float}
 */
function acc_report_general_ledger_pack(PDO $pdo, int $accountId, string $dateFrom, string $dateTo): array
{
    $opening = acc_report_account_sums($pdo, $accountId, $dateFrom, null, true);
    $lines = acc_report_general_ledger($pdo, $accountId, $dateFrom, $dateTo);
    $closing = $opening['balance'];
    if ($lines) {
        $closing = (float) $lines[count($lines) - 1]['running_balance'];
    }

    return [
        'opening' => $opening,
        'lines' => $lines,
        'closing_balance' => $closing,
    ];
}

/**
 * تصفية أسطر دفتر الأستاذ (بحث نصي: رقم القيد، البيان، الملاحظة، التاريخ، المبلغ، نوع المرجع).
 *
 * @param list<array<string, mixed>> $lines
 * @return list<array<string, mixed>>
 */
function acc_report_general_ledger_filter_lines(array $lines, string $q): array
{
    $q = trim($q);
    if ($q === '' || $lines === []) {
        return $lines;
    }

    $filtered = [];
    foreach ($lines as $ln) {
        if (acc_report_general_ledger_line_matches($ln, $q)) {
            $filtered[] = $ln;
        }
    }

    return $filtered;
}

/**
 * @param array<string, mixed> $ln
 */
function acc_report_general_ledger_line_matches(array $ln, string $q): bool
{
    $q = trim($q);
    if ($q === '') {
        return true;
    }

    $textFields = [
        (string) ($ln['entry_no'] ?? ''),
        (string) ($ln['description_ar'] ?? ''),
        (string) ($ln['memo'] ?? ''),
        format_date_dmY((string) ($ln['entry_date'] ?? '')),
        (string) ($ln['entry_date'] ?? ''),
    ];
    $refType = (string) ($ln['ref_type'] ?? '');
    if ($refType !== '') {
        $textFields[] = acc_report_ref_type_label($refType);
        $textFields[] = $refType;
    }

    foreach ($textFields as $field) {
        if ($field !== '' && mb_stripos($field, $q, 0, 'UTF-8') !== false) {
            return true;
        }
    }

    $dateIso = parse_date_to_iso($q);
    if ($dateIso !== null && $dateIso !== '' && (string) ($ln['entry_date'] ?? '') === $dateIso) {
        return true;
    }

    $numNorm = str_replace([',', '٬', ' '], ['', '', ''], $q);
    $amount = null;
    if (preg_match('/^\d+(\.\d+)?$/u', $numNorm)) {
        $amount = round((float) $numNorm, 6);
    }

    $debit = round((float) ($ln['debit'] ?? 0), 6);
    $credit = round((float) ($ln['credit'] ?? 0), 6);
    $running = round((float) ($ln['running_balance'] ?? 0), 6);

    if ($amount !== null && $amount > 0) {
        if (abs($debit - $amount) < 0.000001
            || abs($credit - $amount) < 0.000001
            || abs($running - $amount) < 0.000001
            || abs(abs($running) - $amount) < 0.000001) {
            return true;
        }
    }

    if ($numNorm !== '' && preg_match('/\d/u', $numNorm)) {
        foreach ([$debit, $credit, abs($running)] as $amt) {
            if ($amt <= 0) {
                continue;
            }
            $formatted = str_replace([',', '٬', ' '], ['', '', ''], format_money($amt));
            if ($formatted !== '' && str_contains($formatted, $numNorm)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * @return list<array<string, mixed>>
 */
function acc_report_journal_entries(PDO $pdo, string $dateFrom, string $dateTo, ?string $status = null): array
{
    if (!acc_journal_has_tables($pdo)) {
        return [];
    }
    $sql = 'SELECT e.id, e.entry_no, e.entry_date, e.description_ar, e.status, e.source,
                   e.ref_type, e.ref_id,
                   COALESCE(SUM(l.debit), 0) AS total_debit,
                   COALESCE(SUM(l.credit), 0) AS total_credit
            FROM acc_journal_entry e
            LEFT JOIN acc_journal_line l ON l.journal_id = e.id
            WHERE e.entry_date >= ? AND e.entry_date <= ?';
    $params = [$dateFrom, $dateTo];
    if ($status !== null && $status !== '') {
        $sql .= ' AND e.status = ?';
        $params[] = $status;
    }
    $sql .= ' GROUP BY e.id ORDER BY e.entry_date ASC, e.id ASC LIMIT 2000';

    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return list<array<string, mixed>>
 */
function acc_report_journal_lines(PDO $pdo, int $journalId): array
{
    if ($journalId < 1) {
        return [];
    }
    $st = $pdo->prepare(
        'SELECT l.account_id, l.debit, l.credit, l.memo, a.code, a.name_ar
         FROM acc_journal_line l
         INNER JOIN acc_account a ON a.id = l.account_id
         WHERE l.journal_id = ?
         ORDER BY l.id ASC'
    );
    $st->execute([$journalId]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return list<array<string, mixed>>
 */
function acc_report_accounts_by_types(PDO $pdo, array $types, string $dateFrom, string $dateTo): array
{
    if (!acc_journal_has_tables($pdo) || $types === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($types), '?'));
    $st = $pdo->prepare(
        "SELECT id, code, name_ar, account_type
         FROM acc_account
         WHERE is_active = 1 AND is_leaf = 1 AND account_type IN ($placeholders)
         ORDER BY code ASC"
    );
    $st->execute($types);
    $accounts = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $rows = [];
    foreach ($accounts as $acc) {
        $accountId = (int) $acc['id'];
        $period = acc_report_account_sums($pdo, $accountId, $dateFrom, $dateTo, false);
        if (abs($period['sum_debit']) < 0.000001 && abs($period['sum_credit']) < 0.000001) {
            continue;
        }

        $type = (string) $acc['account_type'];
        $amount = 0.0;
        if ($type === 'revenue') {
            $amount = round($period['sum_credit'] - $period['sum_debit'], 6);
        } elseif ($type === 'expense') {
            $amount = round($period['sum_debit'] - $period['sum_credit'], 6);
        } else {
            $amount = $period['balance'];
        }

        if (abs($amount) < 0.000001) {
            continue;
        }

        $rows[] = [
            'id' => $accountId,
            'code' => (string) $acc['code'],
            'name_ar' => (string) $acc['name_ar'],
            'account_type' => $type,
            'period_debit' => $period['sum_debit'],
            'period_credit' => $period['sum_credit'],
            'amount' => $amount,
        ];
    }

    return $rows;
}

/**
 * @return array{revenue: list<array<string, mixed>>, expenses: list<array<string, mixed>>, total_revenue: float, total_expenses: float, net_income: float}
 */
function acc_report_income_statement(PDO $pdo, string $dateFrom, string $dateTo): array
{
    $detailed = acc_report_income_statement_detailed($pdo, $dateFrom, $dateTo, false);

    return [
        'revenue' => $detailed['revenue']['accounts_flat'] ?? [],
        'expenses' => $detailed['expense']['accounts_flat'] ?? [],
        'total_revenue' => $detailed['total_revenue'],
        'total_expenses' => $detailed['total_expenses'],
        'net_income' => $detailed['net_income'],
    ];
}

function acc_report_pl_net_amount(string $accountType, float $debit, float $credit): float
{
    if ($accountType === 'revenue') {
        return round($credit - $debit, 6);
    }

    return round($debit - $credit, 6);
}

/**
 * @return list<array<string, mixed>>
 */
function acc_report_pl_account_movements(PDO $pdo, int $accountId, string $dateFrom, string $dateTo): array
{
    if ($accountId < 1 || !acc_journal_has_tables($pdo)) {
        return [];
    }

    $st = $pdo->prepare(
        'SELECT e.id AS journal_id, e.entry_no, e.entry_date, e.description_ar, e.source, e.ref_type, e.ref_id,
                l.debit, l.credit, l.memo
         FROM acc_journal_line l
         INNER JOIN acc_journal_entry e ON e.id = l.journal_id
         WHERE l.account_id = ? AND e.status = \'posted\'
           AND e.entry_date >= ? AND e.entry_date <= ?
         ORDER BY e.entry_date ASC, e.id ASC, l.id ASC'
    );
    $st->execute([$accountId, $dateFrom, $dateTo]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return list<array<string, mixed>>
 */
function acc_report_pl_load_accounts(PDO $pdo, string $accountType): array
{
    $st = $pdo->prepare(
        'SELECT id, code, name_ar, parent_id, is_leaf, sort_order
         FROM acc_account
         WHERE is_active = 1 AND account_type = ?
         ORDER BY code ASC, sort_order ASC, id ASC'
    );
    $st->execute([$accountType]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @param list<array<string, mixed>> $accounts
 * @return array{
 *   lines: list<array<string, mixed>>,
 *   accounts_flat: list<array<string, mixed>>,
 *   total_debit: float,
 *   total_credit: float,
 *   total_net: float
 * }
 */
function acc_report_pl_build_section(
    PDO $pdo,
    string $accountType,
    string $dateFrom,
    string $dateTo,
    bool $includeMovements,
    float $pctBase
): array {
    $accounts = acc_report_pl_load_accounts($pdo, $accountType);
    if ($accounts === []) {
        return [
            'lines' => [],
            'accounts_flat' => [],
            'total_debit' => 0.0,
            'total_credit' => 0.0,
            'total_net' => 0.0,
        ];
    }

    $byId = [];
    $childrenOf = [];
    foreach ($accounts as $acc) {
        $id = (int) $acc['id'];
        $byId[$id] = $acc;
        $pid = $acc['parent_id'] === null ? 0 : (int) $acc['parent_id'];
        if (!isset($childrenOf[$pid])) {
            $childrenOf[$pid] = [];
        }
        $childrenOf[$pid][] = $id;
    }

    /** @var array<int, array{debit: float, credit: float, net: float, movements: list<array<string, mixed>>}> */
    $metrics = [];

    foreach ($accounts as $acc) {
        if ((int) ($acc['is_leaf'] ?? 1) !== 1) {
            continue;
        }
        $id = (int) $acc['id'];
        $period = acc_report_account_sums($pdo, $id, $dateFrom, $dateTo, false);
        if (abs($period['sum_debit']) < 0.000001 && abs($period['sum_credit']) < 0.000001) {
            continue;
        }
        $metrics[$id] = [
            'debit' => (float) $period['sum_debit'],
            'credit' => (float) $period['sum_credit'],
            'net' => acc_report_pl_net_amount($accountType, (float) $period['sum_debit'], (float) $period['sum_credit']),
            'movements' => $includeMovements ? acc_report_pl_account_movements($pdo, $id, $dateFrom, $dateTo) : [],
        ];
    }

    $rollup = static function (int $id) use (&$rollup, &$metrics, $childrenOf, $accountType): bool {
        $changed = false;
        $kids = $childrenOf[$id] ?? [];
        if ($kids === []) {
            return false;
        }
        $sumD = 0.0;
        $sumC = 0.0;
        $sumN = 0.0;
        $hasChild = false;
        foreach ($kids as $cid) {
            if (!isset($metrics[$cid])) {
                $rollup($cid);
            }
            if (!isset($metrics[$cid])) {
                continue;
            }
            $hasChild = true;
            $sumD += $metrics[$cid]['debit'];
            $sumC += $metrics[$cid]['credit'];
            $sumN += $metrics[$cid]['net'];
        }
        if (!$hasChild) {
            return false;
        }
        if (!isset($metrics[$id])) {
            $metrics[$id] = ['debit' => 0.0, 'credit' => 0.0, 'net' => 0.0, 'movements' => []];
            $changed = true;
        }
        if (
            abs($metrics[$id]['debit'] - $sumD) > 0.000001
            || abs($metrics[$id]['credit'] - $sumC) > 0.000001
            || abs($metrics[$id]['net'] - $sumN) > 0.000001
        ) {
            $metrics[$id]['debit'] = round($sumD, 6);
            $metrics[$id]['credit'] = round($sumC, 6);
            $metrics[$id]['net'] = round($sumN, 6);
            $changed = true;
        }

        return $changed;
    };

    foreach (array_keys($byId) as $id) {
        if ((int) ($byId[$id]['is_leaf'] ?? 1) !== 1) {
            $rollup((int) $id);
        }
    }

    $hasDescendant = static function (int $id) use (&$hasDescendant, &$metrics, $childrenOf): bool {
        if (isset($metrics[$id])) {
            return true;
        }
        foreach ($childrenOf[$id] ?? [] as $cid) {
            if ($hasDescendant((int) $cid)) {
                return true;
            }
        }

        return false;
    };

    $lines = [];
    $accountsFlat = [];
    $lineNo = 0;

    $emitTree = static function (int $parentId, int $depth) use (
        &$emitTree,
        &$lines,
        &$lineNo,
        &$accountsFlat,
        $childrenOf,
        $byId,
        $metrics,
        $hasDescendant,
        $accountType,
        $includeMovements,
        $pctBase,
        $dateFrom,
        $dateTo
    ): void {
        $kids = $childrenOf[$parentId] ?? [];
        usort($kids, static function (int $a, int $b) use ($byId): int {
            return strcmp((string) ($byId[$a]['code'] ?? ''), (string) ($byId[$b]['code'] ?? ''));
        });

        foreach ($kids as $id) {
            if (!$hasDescendant($id)) {
                continue;
            }
            $acc = $byId[$id];
            $m = $metrics[$id] ?? ['debit' => 0.0, 'credit' => 0.0, 'net' => 0.0, 'movements' => []];
            $isLeaf = (int) ($acc['is_leaf'] ?? 1) === 1;
            $lineNo++;
            $pct = $pctBase > 0.000001 ? round(((float) $m['net'] / $pctBase) * 100, 2) : null;

            $line = [
                'kind' => $isLeaf ? 'account' : 'group',
                'line_no' => $lineNo,
                'depth' => $depth,
                'id' => $id,
                'code' => (string) ($acc['code'] ?? ''),
                'name_ar' => (string) ($acc['name_ar'] ?? ''),
                'debit' => (float) $m['debit'],
                'credit' => (float) $m['credit'],
                'net' => (float) $m['net'],
                'pct' => $pct,
                'is_leaf' => $isLeaf,
            ];
            $lines[] = $line;
            if ($isLeaf) {
                $accountsFlat[] = array_merge($line, ['amount' => (float) $m['net']]);
            }

            if ($isLeaf && $includeMovements && !empty($m['movements'])) {
                foreach ($m['movements'] as $mv) {
                    $lineNo++;
                    $lines[] = [
                        'kind' => 'movement',
                        'line_no' => $lineNo,
                        'depth' => $depth + 1,
                        'parent_id' => $id,
                        'journal_id' => (int) ($mv['journal_id'] ?? 0),
                        'entry_no' => (string) ($mv['entry_no'] ?? ''),
                        'entry_date' => (string) ($mv['entry_date'] ?? ''),
                        'description_ar' => (string) ($mv['description_ar'] ?? ''),
                        'memo' => (string) ($mv['memo'] ?? ''),
                        'source' => (string) ($mv['source'] ?? ''),
                        'ref_type' => (string) ($mv['ref_type'] ?? ''),
                        'ref_id' => (int) ($mv['ref_id'] ?? 0),
                        'debit' => (float) ($mv['debit'] ?? 0),
                        'credit' => (float) ($mv['credit'] ?? 0),
                        'date_from' => $dateFrom,
                        'date_to' => $dateTo,
                    ];
                }
            }

            if (!$isLeaf) {
                $emitTree($id, $depth + 1);
            }
        }
    };

    $roots = [];
    foreach ($byId as $id => $acc) {
        $pid = $acc['parent_id'] === null ? 0 : (int) $acc['parent_id'];
        if ($pid === 0 || !isset($byId[$pid])) {
            $roots[] = (int) $id;
        }
    }
    if ($roots === []) {
        $roots = $childrenOf[0] ?? array_keys($byId);
    }

    usort($roots, static function (int $a, int $b) use ($byId): int {
        return strcmp((string) ($byId[$a]['code'] ?? ''), (string) ($byId[$b]['code'] ?? ''));
    });

    foreach ($roots as $rootId) {
        $emitTree((int) $rootId, 0);
    }

    $totalDebit = 0.0;
    $totalCredit = 0.0;
    $totalNet = 0.0;
    foreach ($accounts as $acc) {
        if ((int) ($acc['is_leaf'] ?? 1) !== 1) {
            continue;
        }
        $id = (int) $acc['id'];
        if (!isset($metrics[$id])) {
            continue;
        }
        $totalDebit += $metrics[$id]['debit'];
        $totalCredit += $metrics[$id]['credit'];
        $totalNet += $metrics[$id]['net'];
    }

    return [
        'lines' => $lines,
        'accounts_flat' => $accountsFlat,
        'total_debit' => round($totalDebit, 6),
        'total_credit' => round($totalCredit, 6),
        'total_net' => round($totalNet, 6),
    ];
}

/**
 * قائمة دخل ملخصة: حسابات نهائية فقط بدون مجموعات أو حركات قيود.
 *
 * @return array{
 *   view: string,
 *   date_from: string,
 *   date_to: string,
 *   revenue_rows: list<array<string, mixed>>,
 *   expense_rows: list<array<string, mixed>>,
 *   total_revenue: float,
 *   total_expenses: float,
 *   net_income: float,
 *   net_is_profit: bool
 * }
 */
function acc_report_income_statement_summary(PDO $pdo, string $dateFrom, string $dateTo): array
{
    require_once app_path('includes/acc_income_statement_comprehensive.php');
    require_once app_path('includes/acc_report_inventory.php');

    $usesInventory = acc_report_inventory_account_id($pdo) > 0;
    $revenueRows = acc_report_accounts_by_types($pdo, ['revenue'], $dateFrom, $dateTo);
    $allExpenseRows = acc_report_accounts_by_types($pdo, ['expense'], $dateFrom, $dateTo);
    $postingByAccount = acc_pl_comp_posting_rules_by_account($pdo);

    $purchaseRows = [];
    $purchaseReturnRows = [];
    $cogsRows = [];
    $operatingRows = [];

    foreach ($allExpenseRows as $row) {
        $section = acc_pl_comp_classify_account(
            [
                'id' => (int) ($row['id'] ?? 0),
                'code' => (string) ($row['code'] ?? ''),
                'account_type' => 'expense',
            ],
            $postingByAccount,
            $usesInventory
        );
        if ($section === 'ignore') {
            continue;
        }
        switch ($section) {
            case 'purchases':
                $purchaseRows[] = $row;
                break;
            case 'purchase_returns':
                $purchaseReturnRows[] = $row;
                break;
            case 'cogs':
                $cogsRows[] = $row;
                break;
            default:
                $operatingRows[] = $row;
                break;
        }
    }

    acc_report_pl_append_inventory_purchase_rows($pdo, $dateFrom, $dateTo, $purchaseRows, $purchaseReturnRows);

    $totalPurchases = 0.0;
    foreach ($purchaseRows as $r) {
        $totalPurchases += abs((float) ($r['amount'] ?? 0));
    }
    $totalPurchases = round($totalPurchases, 6);

    $totalPurchaseReturns = 0.0;
    foreach ($purchaseReturnRows as $r) {
        $totalPurchaseReturns += abs((float) ($r['amount'] ?? 0));
    }
    $totalPurchaseReturns = round($totalPurchaseReturns, 6);

    $totalCogs = 0.0;
    foreach ($cogsRows as $r) {
        $totalCogs += (float) ($r['amount'] ?? 0);
    }
    $totalCogs = round($totalCogs, 6);

    $totalOperating = 0.0;
    foreach ($operatingRows as $r) {
        $totalOperating += (float) ($r['amount'] ?? 0);
    }
    $totalOperating = round($totalOperating, 6);

    $totalRevenue = 0.0;
    foreach ($revenueRows as $r) {
        $totalRevenue += (float) ($r['amount'] ?? 0);
    }
    $totalRevenue = round($totalRevenue, 6);

    $lineNo = 0;
    foreach ($revenueRows as &$row) {
        $lineNo++;
        $row['line_no'] = $lineNo;
        $amt = (float) ($row['amount'] ?? 0);
        $row['pct'] = $totalRevenue > 0.000001 ? round(($amt / $totalRevenue) * 100, 2) : null;
    }
    unset($row);

    $assignLineNos = static function (array &$rows): void {
        $n = 0;
        foreach ($rows as &$row) {
            $n++;
            $row['line_no'] = $n;
            $row['pct'] = null;
        }
        unset($row);
    };
    $assignLineNos($purchaseRows);
    $assignLineNos($purchaseReturnRows);
    $assignLineNos($cogsRows);
    $assignLineNos($operatingRows);

    if ($usesInventory) {
        $totalCostOfSales = round($totalCogs - $totalPurchaseReturns, 6);
    } else {
        $totalCostOfSales = round($totalPurchases + $totalCogs - $totalPurchaseReturns, 6);
    }
    $grossProfit = round($totalRevenue - $totalCostOfSales, 6);
    $netIncome = round($grossProfit - $totalOperating, 6);

    $expenseRows = array_merge($purchaseRows, $purchaseReturnRows, $cogsRows, $operatingRows);
    $totalExpenses = round($totalCostOfSales + $totalOperating, 6);

    return [
        'view' => 'summary',
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'revenue_rows' => $revenueRows,
        'purchase_rows' => $purchaseRows,
        'purchase_return_rows' => $purchaseReturnRows,
        'cogs_rows' => $cogsRows,
        'operating_rows' => $operatingRows,
        'expense_rows' => $expenseRows,
        'uses_inventory' => $usesInventory,
        'total_purchases' => $totalPurchases,
        'total_purchase_returns' => $totalPurchaseReturns,
        'total_cogs' => $totalCogs,
        'total_cost_of_sales' => $totalCostOfSales,
        'gross_profit' => $grossProfit,
        'total_operating' => $totalOperating,
        'total_revenue' => $totalRevenue,
        'total_expenses' => $totalExpenses,
        'net_income' => $netIncome,
        'net_is_profit' => $netIncome >= 0,
    ];
}

/**
 * @return array{
 *   view: string,
 *   date_from: string,
 *   date_to: string,
 *   include_movements: bool,
 *   revenue: array<string, mixed>,
 *   expense: array<string, mixed>,
 *   total_revenue: float,
 *   total_expenses: float,
 *   net_income: float,
 *   net_is_profit: bool
 * }
 */
function acc_report_income_statement_detailed(
    PDO $pdo,
    string $dateFrom,
    string $dateTo,
    bool $includeMovements = true
): array {
    $revenueSection = acc_report_pl_build_section($pdo, 'revenue', $dateFrom, $dateTo, $includeMovements, 0.0);
    $totalRevenue = (float) $revenueSection['total_net'];
    $expenseSection = acc_report_pl_build_section(
        $pdo,
        'expense',
        $dateFrom,
        $dateTo,
        $includeMovements,
        abs($totalRevenue) > 0.000001 ? $totalRevenue : 1.0
    );

    if (abs($totalRevenue) > 0.000001) {
        $revenueSection = acc_report_pl_build_section(
            $pdo,
            'revenue',
            $dateFrom,
            $dateTo,
            $includeMovements,
            $totalRevenue
        );
        $totalRevenue = (float) $revenueSection['total_net'];
    }

    $totalExpenses = (float) $expenseSection['total_net'];
    $netIncome = round($totalRevenue - $totalExpenses, 6);

    return [
        'view' => 'detailed',
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'include_movements' => $includeMovements,
        'revenue' => $revenueSection,
        'expense' => $expenseSection,
        'total_revenue' => round($totalRevenue, 6),
        'total_expenses' => round($totalExpenses, 6),
        'net_income' => $netIncome,
        'net_is_profit' => $netIncome >= 0,
    ];
}

/**
 * @return array{
 *   assets: list<array<string, mixed>>,
 *   liabilities: list<array<string, mixed>>,
 *   equity: list<array<string, mixed>>,
 *   total_assets: float,
 *   total_liabilities: float,
 *   total_equity_accounts: float,
 *   net_income: float,
 *   total_equity: float,
 *   total_liabilities_equity: float
 * }
 */
function acc_report_balance_sheet(PDO $pdo, string $asOfDate): array
{
    if (!acc_journal_has_tables($pdo)) {
        return [
            'assets' => [],
            'liabilities' => [],
            'equity' => [],
            'total_assets' => 0.0,
            'total_liabilities' => 0.0,
            'total_equity_accounts' => 0.0,
            'net_income' => 0.0,
            'total_equity' => 0.0,
            'total_liabilities_equity' => 0.0,
        ];
    }

    $pack = static function (array $types) use ($pdo, $asOfDate): array {
        $st = $pdo->prepare(
            'SELECT id, code, name_ar, account_type
             FROM acc_account
             WHERE is_active = 1 AND is_leaf = 1 AND account_type = ?
             ORDER BY code ASC'
        );
        $rows = [];
        foreach ($types as $type) {
            $st->execute([$type]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $acc) {
                $accountId = (int) $acc['id'];
                $sums = acc_report_account_sums($pdo, $accountId, null, $asOfDate, false);
                if (abs($sums['balance']) < 0.000001) {
                    continue;
                }
                $display = 0.0;
                if ($type === 'asset') {
                    $display = $sums['balance'];
                } else {
                    $display = round(-$sums['balance'], 6);
                }
                if (abs($display) < 0.000001) {
                    continue;
                }
                $split = acc_report_split_balance($type === 'asset' ? $display : -$display);
                $rows[] = [
                    'id' => $accountId,
                    'code' => (string) $acc['code'],
                    'name_ar' => (string) $acc['name_ar'],
                    'account_type' => $type,
                    'balance' => $display,
                    'debit_balance' => $split['debit'],
                    'credit_balance' => $split['credit'],
                ];
            }
        }

        return $rows;
    };

    $assets = $pack(['asset']);
    $liabilities = acc_report_balance_sheet_liability_rows($pdo, $asOfDate);
    $equity = $pack(['equity']);

    $totalAssets = 0.0;
    foreach ($assets as $r) {
        $totalAssets += (float) ($r['balance'] ?? 0);
    }
    $totalLiabilities = 0.0;
    foreach ($liabilities as $r) {
        if ((int) ($r['depth'] ?? 0) > 0) {
            continue;
        }
        $totalLiabilities += (float) ($r['balance'] ?? 0);
    }
    $totalEquityAccounts = 0.0;
    foreach ($equity as $r) {
        $totalEquityAccounts += (float) ($r['balance'] ?? 0);
    }

    $yearStart = substr($asOfDate, 0, 4) . '-01-01';
    $pl = acc_report_income_statement($pdo, $yearStart, $asOfDate);
    $netIncome = (float) $pl['net_income'];
    $totalEquity = round($totalEquityAccounts + $netIncome, 6);

    return [
        'assets' => $assets,
        'liabilities' => $liabilities,
        'equity' => $equity,
        'total_assets' => round($totalAssets, 6),
        'total_liabilities' => round($totalLiabilities, 6),
        'total_equity_accounts' => round($totalEquityAccounts, 6),
        'net_income' => $netIncome,
        'total_equity' => $totalEquity,
        'total_liabilities_equity' => round($totalLiabilities + $totalEquity, 6),
    ];
}

/**
 * خصوم الميزانية — مع تجميع «مستحقات الموظfين» (رواتb + ضمان + ضريبة).
 *
 * @return list<array{id:int, code:string, name_ar:string, account_type:string, balance:float, debit_balance:float, credit_balance:float, depth:int, is_group:bool}>
 */
function acc_report_balance_sheet_liability_rows(PDO $pdo, string $asOfDate): array
{
    if (!acc_journal_has_tables($pdo)) {
        return [];
    }

    require_once app_path('includes/hr_payroll_gl.php');
    require_once app_path('includes/acc_account_tree.php');
    hr_payroll_gl_ensure_payroll_liability_group($pdo);

    $st = $pdo->prepare(
        'SELECT id, code, name_ar, account_type
         FROM acc_account
         WHERE is_active = 1 AND is_leaf = 1 AND account_type = ?
         ORDER BY code ASC'
    );
    $st->execute(['liability']);
    $leaves = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $acc) {
        $accountId = (int) $acc['id'];
        $sums = acc_report_account_sums($pdo, $accountId, null, $asOfDate, false);
        if (abs($sums['balance']) < 0.000001) {
            continue;
        }
        $display = round(-$sums['balance'], 6);
        if (abs($display) < 0.000001) {
            continue;
        }
        $split = acc_report_split_balance(-$display);
        $leaves[] = [
            'id' => $accountId,
            'code' => (string) $acc['code'],
            'name_ar' => (string) $acc['name_ar'],
            'account_type' => 'liability',
            'balance' => $display,
            'debit_balance' => $split['debit'],
            'credit_balance' => $split['credit'],
            'depth' => 0,
            'is_group' => false,
        ];
    }

    $hrIds = hr_payroll_gl_grouped_liability_account_ids($pdo);
    if ($hrIds === []) {
        return $leaves;
    }

    $hrRows = [];
    $otherRows = [];
    foreach ($leaves as $row) {
        if (in_array((int) ($row['id'] ?? 0), $hrIds, true)) {
            $hrRows[] = $row;
        } else {
            $otherRows[] = $row;
        }
    }

    if ($hrRows === []) {
        return $leaves;
    }

    usort($hrRows, static fn(array $a, array $b): int => strcmp((string) $a['code'], (string) $b['code']));

    $groupSpec = hr_payroll_gl_liability_group_spec();
    $groupId = hr_payroll_gl_liability_group_id($pdo);
    $groupAcc = $groupId > 0 ? acc_account_get($pdo, $groupId) : null;
    $groupTotal = 0.0;
    foreach ($hrRows as $row) {
        $groupTotal += (float) ($row['balance'] ?? 0);
    }
    $groupTotal = round($groupTotal, 6);
    if (abs($groupTotal) < 0.000001) {
        foreach ($hrRows as &$row) {
            $row['depth'] = 0;
        }
        unset($row);

        return array_merge($otherRows, $hrRows);
    }

    $split = acc_report_split_balance(-$groupTotal);
    $groupRow = [
        'id' => $groupId,
        'code' => $groupAcc ? (string) ($groupAcc['code'] ?? $groupSpec['code']) : $groupSpec['code'],
        'name_ar' => $groupAcc ? (string) ($groupAcc['name_ar'] ?? $groupSpec['name_ar']) : $groupSpec['name_ar'],
        'account_type' => 'liability',
        'balance' => $groupTotal,
        'debit_balance' => $split['debit'],
        'credit_balance' => $split['credit'],
        'depth' => 0,
        'is_group' => true,
    ];

    foreach ($hrRows as &$row) {
        $row['depth'] = 1;
        $row['is_group'] = false;
    }
    unset($row);

    $groupCode = (string) $groupRow['code'];
    $result = [];
    $groupInserted = false;
    foreach ($otherRows as $row) {
        if (!$groupInserted && strcmp((string) $row['code'], $groupCode) > 0) {
            $result[] = $groupRow;
            foreach ($hrRows as $hrRow) {
                $result[] = $hrRow;
            }
            $groupInserted = true;
        }
        $result[] = $row;
    }
    if (!$groupInserted) {
        $result[] = $groupRow;
        foreach ($hrRows as $hrRow) {
            $result[] = $hrRow;
        }
    }

    return $result;
}

/** @return array<int, true> */
function acc_report_posted_account_ids(PDO $pdo): array
{
    if (!acc_journal_has_tables($pdo)) {
        return [];
    }

    $ids = $pdo->query(
        'SELECT DISTINCT l.account_id
         FROM acc_journal_line l
         INNER JOIN acc_journal_entry e ON e.id = l.journal_id
         WHERE e.status = \'posted\''
    )->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $map = [];
    foreach ($ids as $rawId) {
        $id = (int) $rawId;
        if ($id > 0) {
            $map[$id] = true;
        }
    }

    return $map;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function acc_report_deduplicate_picker_accounts(PDO $pdo, array $rows, bool $byNameOnly = false): array
{
    if ($rows === []) {
        return [];
    }

    require_once app_path('includes/acc_coa_bootstrap.php');

    /** @var array<string, list<array<string, mixed>>> $groups */
    $groups = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        $acc = acc_account_get($pdo, $id);
        if (!$acc) {
            continue;
        }
        if ($byNameOnly) {
            $groupKey = acc_coa_picker_group_key($pdo, $acc, true);
        } else {
            $parentKey = $acc['parent_id'] !== null ? (int) $acc['parent_id'] : 0;
            $groupKey = $parentKey . '|' . acc_coa_picker_base_name((string) ($acc['name_ar'] ?? ''));
        }
        if (!isset($groups[$groupKey])) {
            $groups[$groupKey] = [];
        }
        $groups[$groupKey][] = $row;
    }

    $out = [];
    foreach ($groups as $group) {
        if (count($group) === 1) {
            $out[] = $group[0];
            continue;
        }
        $ids = array_map(static fn (array $r): int => (int) ($r['id'] ?? 0), $group);
        $keepId = acc_coa_pick_keep_account_id($pdo, $ids, (string) ($group[0]['name_ar'] ?? ''));
        foreach ($group as $row) {
            if ((int) ($row['id'] ?? 0) === $keepId) {
                $out[] = $row;
                break;
            }
        }
    }

    usort($out, static function (array $a, array $b): int {
        $cmp = strcmp((string) ($a['code'] ?? ''), (string) ($b['code'] ?? ''));
        if ($cmp !== 0) {
            return $cmp;
        }

        return strcmp((string) ($a['name_ar'] ?? ''), (string) ($b['name_ar'] ?? ''));
    });

    return $out;
}

/**
 * حسابات نهائية للتقارير: عليها قيود مرحّلة فقط، بدون تكرار الاسم تحت نفس الأب.
 *
 * @param list<int> $ensureIds
 * @return list<array<string, mixed>>
 */
function acc_report_leaf_accounts_picker(PDO $pdo, array $ensureIds = []): array
{
    require_once app_path('includes/acc_journal.php');
    require_once app_path('includes/acc_account_tree.php');

    $postedIds = acc_report_posted_account_ids($pdo);
    if ($postedIds === [] && $ensureIds === []) {
        return [];
    }

    $all = acc_journal_load_leaf_accounts($pdo);
    $seen = [];
    foreach ($all as $row) {
        $seen[(int) ($row['id'] ?? 0)] = true;
    }
    foreach ($ensureIds as $rawId) {
        $id = (int) $rawId;
        if ($id < 1 || isset($seen[$id])) {
            continue;
        }
        $acc = acc_account_get($pdo, $id);
        if (!$acc || (int) ($acc['is_leaf'] ?? 0) !== 1) {
            continue;
        }
        $all[] = [
            'id' => $id,
            'code' => (string) ($acc['code'] ?? ''),
            'name_ar' => (string) ($acc['name_ar'] ?? ''),
            'account_type' => (string) ($acc['account_type'] ?? ''),
        ];
        $seen[$id] = true;
    }
    foreach ($all as &$row) {
        $row['code'] = acc_account_format_code((string) ($row['code'] ?? ''));
    }
    unset($row);

    $filtered = [];
    foreach ($all as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0 && isset($postedIds[$id])) {
            $filtered[] = $row;
        }
    }

    $seen = [];
    foreach ($filtered as $row) {
        $seen[(int) ($row['id'] ?? 0)] = true;
    }
    foreach ($ensureIds as $rawId) {
        $id = (int) $rawId;
        if ($id < 1 || isset($seen[$id])) {
            continue;
        }
        $acc = acc_account_get($pdo, $id);
        if (!$acc || (int) ($acc['is_leaf'] ?? 0) !== 1) {
            continue;
        }
        $filtered[] = [
            'id' => $id,
            'code' => acc_account_format_code((string) ($acc['code'] ?? '')),
            'name_ar' => (string) ($acc['name_ar'] ?? ''),
            'account_type' => (string) ($acc['account_type'] ?? ''),
        ];
        $seen[$id] = true;
    }

    return acc_report_deduplicate_picker_accounts($pdo, $filtered, true);
}
