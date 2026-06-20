<?php
declare(strict_types=1);

/**
 * ضبط شجرة حسابات شركة صغيرة + ربط الترحيل التلقائي (قابل للتعديل لاحقاً من الشاشة).
 * يُشغَّل تلقائياً عند ترقية الإصدار، أو يدوياً من «ربط الحسابات».
 */
const ACC_COA_BOOTSTRAP_VERSION = 8;

require_once app_path('includes/acc_account_tree.php');
require_once app_path('includes/acc_gl.php');

/** @return array<string, array<string, mixed>> code digits => row */
function acc_coa_index_accounts(PDO $pdo): array
{
    $map = [];
    foreach ($pdo->query('SELECT * FROM acc_account')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $d = acc_account_code_digits((string) $row['code']);
        if ($d !== '') {
            $map[$d] = $row;
        }
    }

    return $map;
}

function acc_coa_find_digits(array $index, string $codeDigits): ?array
{
    return $index[$codeDigits] ?? null;
}

function acc_coa_journal_line_count(PDO $pdo, int $accountId): int
{
    if ($accountId < 1) {
        return 0;
    }
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM acc_journal_line WHERE account_id = ?');
        $st->execute([$accountId]);

        return (int) $st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** @param list<string> $needles */
function acc_coa_code_exists(PDO $pdo, string $code): bool
{
    $st = $pdo->prepare('SELECT id FROM acc_account WHERE code = ? LIMIT 1');
    $st->execute([$code]);

    return (bool) $st->fetch();
}

/** رقم حساب غير مستخدم عالمياً (تجنّب تكرار UNIQUE على code). */
function acc_coa_unique_code(PDO $pdo, ?int $parentId, ?string $preferred = null): string
{
    if ($preferred !== null && $preferred !== '' && !acc_coa_code_exists($pdo, $preferred)) {
        return $preferred;
    }

    $tried = [];
    for ($i = 0; $i < 120; $i++) {
        $candidate = acc_account_next_code($pdo, $parentId);
        if (isset($tried[$candidate])) {
            $digits = acc_account_code_digits($candidate);
            $candidate = $digits !== '' ? (string) ((int) $digits + 1) : $candidate . '1';
        }
        $tried[$candidate] = true;
        if (!acc_coa_code_exists($pdo, $candidate)) {
            return $candidate;
        }
    }

    throw new RuntimeException('تعذر تخصيص رقم حساب فريد.');
}

function acc_coa_name_matches(string $nameAr, array $needles): bool
{
    $n = acc_coa_normalize_name($nameAr);
    foreach ($needles as $w) {
        if ($w !== '' && mb_strpos($n, acc_coa_normalize_name($w)) !== false) {
            return true;
        }
    }

    return false;
}

function acc_coa_normalize_name(string $nameAr): string
{
    $n = trim(preg_replace('/\s+/u', ' ', $nameAr) ?? $nameAr);

    return mb_strtolower($n, 'UTF-8');
}

/** اسم العرض للتجميع — بدون لاحقة « - 5165 » أو « — 5165 ». */
function acc_coa_picker_base_name(string $nameAr): string
{
    $n = acc_coa_normalize_name($nameAr);
    $n = trim((string) (preg_replace('/\s*[-–—]\s*[\d.]+$/u', '', $n) ?? $n));

    return $n !== '' ? $n : acc_coa_normalize_name($nameAr);
}

/**
 * مفتاح تجميع حسابات الاختيار — يمنع تكرار «تكلفة البضاعة المباعة» وغيرها.
 *
 * @param array<string, mixed> $acc
 */
function acc_coa_picker_group_key(PDO $pdo, array $acc, bool $byNameOnly = true): string
{
    $nameAr = (string) ($acc['name_ar'] ?? '');
    $base = acc_coa_picker_base_name($nameAr);

    foreach (acc_coa_bootstrap_account_specs() as $spec) {
        if (empty($spec['is_leaf'])) {
            continue;
        }
        $canonicalDigits = acc_account_code_digits((string) ($spec['code'] ?? ''));
        $canonicalName = acc_coa_normalize_name((string) ($spec['name_ar'] ?? ''));
        if ($canonicalName === '' || $canonicalDigits === '') {
            continue;
        }
        $keywords = $spec['role_keywords'] ?? [];
        $nameMatches = $base === $canonicalName
            || ($canonicalName !== '' && str_starts_with($base, $canonicalName))
            || ($keywords !== [] && acc_coa_name_matches($nameAr, $keywords));
        if ($nameMatches) {
            return 'canonical:' . $canonicalDigits;
        }
    }

    if ($byNameOnly) {
        return 'name:' . $base;
    }

    $parentKey = $acc['parent_id'] !== null ? (int) $acc['parent_id'] : 0;

    return $parentKey . '|' . $base;
}

/** @param array<string, mixed> $row */
function acc_coa_account_is_cogs_like(array $row): bool
{
    if ((string) ($row['account_type'] ?? '') !== 'expense') {
        return false;
    }
    if ((int) ($row['is_leaf'] ?? 0) !== 1) {
        return false;
    }

    $nameAr = (string) ($row['name_ar'] ?? '');
    $nameNorm = acc_coa_picker_base_name($nameAr);
    $cogsName = acc_coa_picker_base_name('تكلفة البضاعة المباعة');
    if ($nameNorm === $cogsName || str_starts_with($nameNorm, $cogsName)) {
        return true;
    }
    $n = acc_coa_normalize_name($nameAr);
    $isCogsName = (mb_strpos($n, acc_coa_normalize_name('تكلف')) !== false || mb_strpos($n, 'cogs') !== false)
        && mb_strpos($n, acc_coa_normalize_name('مباع')) !== false;
    if ($isCogsName) {
        return true;
    }

    $digits = acc_account_code_digits((string) ($row['code'] ?? ''));
    if ($digits === '54' || ($digits !== '' && acc_coa_code_is_extended_clone('54', $digits))) {
        return $nameNorm === $cogsName
            || str_starts_with($nameNorm, $cogsName)
            || $isCogsName;
    }

    return false;
}

/** @return list<int> */
function acc_coa_find_cogs_candidate_ids(PDO $pdo): array
{
    if (!acc_journal_has_tables($pdo)) {
        return [];
    }

    try {
        $rows = $pdo->query(
            'SELECT id, code, name_ar, account_type, is_leaf, is_active
             FROM acc_account
             ORDER BY id ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $ids = [];
    foreach ($rows as $row) {
        if (!acc_coa_account_is_cogs_like($row)) {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    return array_values($ids);
}

/** @param list<int> $ids */
function acc_coa_pick_cogs_keep_account_id(PDO $pdo, array $ids): int
{
    if ($ids === []) {
        return 0;
    }

    $mapped = 0;
    if (acc_gl_has_posting_table($pdo)) {
        try {
            $st = $pdo->prepare(
                "SELECT account_id FROM acc_posting_setting WHERE rule_code = 'cogs' AND account_id IS NOT NULL LIMIT 1"
            );
            $st->execute();
            $mapped = (int) ($st->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            $mapped = 0;
        }
    }

    $bestId = 0;
    $bestLines = -1;
    $tied = [];
    foreach ($ids as $rawId) {
        $id = (int) $rawId;
        if ($id < 1) {
            continue;
        }
        $lines = acc_coa_journal_line_count($pdo, $id);
        if ($lines > $bestLines) {
            $bestLines = $lines;
            $bestId = $id;
            $tied = [$id];
        } elseif ($lines === $bestLines && $lines >= 0) {
            $tied[] = $id;
        }
    }

    if ($bestLines > 0) {
        if (count($tied) === 1) {
            return $bestId;
        }
        if ($mapped > 0 && in_array($mapped, $tied, true)) {
            return $mapped;
        }

        return acc_coa_pick_keep_account_id($pdo, $tied, 'تكلفة البضاعة المباعة');
    }

    if ($mapped > 0 && in_array($mapped, $ids, true)) {
        return $mapped;
    }

    return acc_coa_pick_keep_account_id($pdo, $ids, 'تكلفة البضاعة المباعة');
}

function acc_coa_finalize_cogs_account(PDO $pdo, int $keepId): void
{
    if ($keepId < 1) {
        return;
    }

    $pdo->prepare(
        "UPDATE acc_account SET name_ar = ?, is_active = 1, is_leaf = 1 WHERE id = ?"
    )->execute(['تكلفة البضاعة المباعة', $keepId]);

    if (acc_gl_has_posting_table($pdo)) {
        try {
            $pdo->prepare(
                "UPDATE acc_posting_setting SET account_id = ? WHERE rule_code = 'cogs'"
            )->execute([$keepId]);
        } catch (Throwable $e) {
            // ignore
        }
    }
}

/** @param array<string, mixed> $row */
function acc_coa_account_is_salaries_expense_like(array $row): bool
{
    if ((string) ($row['account_type'] ?? '') !== 'expense') {
        return false;
    }
    if ((int) ($row['is_leaf'] ?? 0) !== 1) {
        return false;
    }

    $nameAr = (string) ($row['name_ar'] ?? '');
    $n = acc_coa_normalize_name($nameAr);
    if (mb_strpos($n, acc_coa_normalize_name('مستحق')) !== false) {
        return false;
    }

    $nameNorm = acc_coa_picker_base_name($nameAr);
    $canonical = acc_coa_picker_base_name('رواتب وأجور');
    $isSalaryName = $nameNorm === $canonical
        || str_starts_with($nameNorm, $canonical)
        || (mb_strpos($n, acc_coa_normalize_name('رواتب')) !== false
            && mb_strpos($n, acc_coa_normalize_name('أجور')) !== false);

    $digits = acc_account_code_digits((string) ($row['code'] ?? ''));
    if ($digits === '52' || ($digits !== '' && acc_coa_code_is_extended_clone('52', $digits))) {
        return $isSalaryName;
    }

    return $isSalaryName;
}

/** @return list<int> */
function acc_coa_find_salaries_expense_candidate_ids(PDO $pdo): array
{
    if (!acc_journal_has_tables($pdo)) {
        return [];
    }

    try {
        $rows = $pdo->query(
            'SELECT id, code, name_ar, account_type, is_leaf, is_active
             FROM acc_account
             ORDER BY id ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $ids = [];
    foreach ($rows as $row) {
        if (!acc_coa_account_is_salaries_expense_like($row)) {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    return array_values($ids);
}

/** @param list<int> $ids */
function acc_coa_pick_salaries_expense_keep_account_id(PDO $pdo, array $ids): int
{
    if ($ids === []) {
        return 0;
    }

    $mapped = 0;
    if (acc_gl_has_posting_table($pdo)) {
        try {
            $st = $pdo->prepare(
                "SELECT account_id FROM acc_posting_setting
                 WHERE rule_code IN ('salaries_expense', 'hr_payroll_accrual')
                   AND account_id IS NOT NULL
                 ORDER BY FIELD(rule_code, 'hr_payroll_accrual', 'salaries_expense')
                 LIMIT 1"
            );
            $st->execute();
            $mapped = (int) ($st->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            $mapped = 0;
        }
    }

    $bestId = 0;
    $bestLines = -1;
    $tied = [];
    foreach ($ids as $rawId) {
        $id = (int) $rawId;
        if ($id < 1) {
            continue;
        }
        $lines = acc_coa_journal_line_count($pdo, $id);
        if ($lines > $bestLines) {
            $bestLines = $lines;
            $bestId = $id;
            $tied = [$id];
        } elseif ($lines === $bestLines && $lines >= 0) {
            $tied[] = $id;
        }
    }

    if ($bestLines > 0) {
        if (count($tied) === 1) {
            return $bestId;
        }
        if ($mapped > 0 && in_array($mapped, $tied, true)) {
            return $mapped;
        }

        return acc_coa_pick_keep_account_id($pdo, $tied, 'رواتب وأجور');
    }

    if ($mapped > 0 && in_array($mapped, $ids, true)) {
        return $mapped;
    }

    return acc_coa_pick_keep_account_id($pdo, $ids, 'رواتب وأجور');
}

function acc_coa_finalize_salaries_expense_account(PDO $pdo, int $keepId): void
{
    if ($keepId < 1) {
        return;
    }

    $pdo->prepare(
        "UPDATE acc_account SET name_ar = ?, is_active = 1, is_leaf = 1 WHERE id = ?"
    )->execute(['رواتب وأجور', $keepId]);

    if (acc_gl_has_posting_table($pdo)) {
        try {
            foreach (['salaries_expense', 'hr_payroll_accrual'] as $ruleCode) {
                $pdo->prepare(
                    'UPDATE acc_posting_setting SET account_id = ? WHERE rule_code = ?'
                )->execute([$keepId, $ruleCode]);
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
}

/** حساب مصروف «رواتب وأجور» المعتمد للترحيل. */
function acc_coa_find_global_salaries_expense_id(PDO $pdo): int
{
    if (!acc_journal_has_tables($pdo)) {
        return 0;
    }

    $candidates = acc_coa_find_salaries_expense_candidate_ids($pdo);
    if ($candidates !== []) {
        return acc_coa_pick_salaries_expense_keep_account_id($pdo, $candidates);
    }

    try {
        $index = acc_coa_index_accounts($pdo);
        $canonical = acc_coa_find_digits($index, '52');
        if ($canonical && (int) ($canonical['is_leaf'] ?? 0) === 1) {
            return (int) ($canonical['id'] ?? 0);
        }
    } catch (Throwable $e) {
        return 0;
    }

    return 0;
}

function acc_coa_account_has_children(PDO $pdo, int $accountId): bool
{
    if ($accountId < 1) {
        return false;
    }
    $st = $pdo->prepare('SELECT id FROM acc_account WHERE parent_id = ? LIMIT 1');
    $st->execute([$accountId]);

    return (bool) $st->fetchColumn();
}

function acc_coa_is_posting_target(PDO $pdo, int $accountId): bool
{
    if ($accountId < 1 || !acc_gl_has_posting_table($pdo)) {
        return false;
    }
    $st = $pdo->prepare('SELECT 1 FROM acc_posting_setting WHERE account_id = ? LIMIT 1');
    $st->execute([$accountId]);

    return (bool) $st->fetchColumn();
}

/** رقم مُوسَّع من الضبط التلقائي (مثل 1200 عندما الأصل 12). */
function acc_coa_code_is_extended_clone(string|int $preferredDigits, string|int $codeDigits): bool
{
    $preferredDigits = acc_account_code_digits((string) $preferredDigits);
    $codeDigits = acc_account_code_digits((string) $codeDigits);
    if ($preferredDigits === '' || $codeDigits === $preferredDigits) {
        return false;
    }
    if (!str_starts_with($codeDigits, $preferredDigits)) {
        return false;
    }
    $suffix = substr($codeDigits, strlen($preferredDigits));

    return $suffix !== '' && ctype_digit($suffix);
}

/**
 * @param array{code: string, name_ar: string, account_type: string, is_leaf?: bool, sort_order?: int} $spec
 */
function acc_coa_refresh_index_row(array &$index, PDO $pdo, int $id): void
{
    $st = $pdo->prepare('SELECT * FROM acc_account WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $index[acc_account_code_digits((string) $row['code'])] = $row;
    }
}

/**
 * استخدام الحساب بالرقم المحدد (12، 21، …) دون إنشاء 1200، 1201، …
 *
 * @param array{code: string, name_ar: string, account_type: string, is_leaf?: bool, sort_order?: int} $spec
 */
function acc_coa_reclaim_preferred_account(PDO $pdo, array &$index, array $spec): int
{
    $codeDigits = acc_account_code_digits($spec['code']);
    if ($codeDigits === '') {
        return 0;
    }
    $existing = acc_coa_find_digits($index, $codeDigits);
    if (!$existing) {
        return 0;
    }
    $id = (int) $existing['id'];
    if (empty($spec['is_leaf'])) {
        return $id;
    }
    if ((int) ($existing['is_leaf'] ?? 0) === 1) {
        return $id;
    }
    if (acc_coa_account_has_children($pdo, $id)) {
        return 0;
    }
    $pdo->prepare(
        'UPDATE acc_account SET name_ar = ?, account_type = ?, is_leaf = 1, is_active = 1 WHERE id = ?'
    )->execute([$spec['name_ar'], $spec['account_type'], $id]);
    acc_coa_refresh_index_row($index, $pdo, $id);

    return $id;
}

/** @return list<array{digits: string, name_ar: string}> */
function acc_coa_canonical_leaf_names(): array
{
    $list = [];
    foreach (acc_coa_bootstrap_account_specs() as $spec) {
        if (empty($spec['is_leaf'])) {
            continue;
        }
        $digits = acc_account_code_digits($spec['code']);
        if ($digits !== '') {
            $list[] = ['digits' => $digits, 'name_ar' => (string) $spec['name_ar']];
        }
    }

    return $list;
}

/** @return array<string, string> */
function acc_coa_posting_rule_canonical_codes(): array
{
    return [
        // بعد الترقيم الهرمي v6 نعتمد الأكواد الهرمية بدلاً من الأكواد القصيرة (12/13/15/22...).
        'ar_customers' => '1001005',
        'ap_suppliers' => '2002',
        'cash' => '1001002001',
        'bank' => '1001003004',
        'sales_revenue' => '41',
        'sales_returns' => '42',
        'purchases' => '51',
        'purchase_returns' => '55',
        'inventory' => '1001007',
        'vat_input' => '3001002',
        'vat_output' => '3001002',
        'misc_expense' => '53',
        'cogs' => '54',
        'salaries_expense' => '52',
        'salaries_payable' => '23',
        'hr_income_tax' => '2007',
    ];
}

/**
 * إيقاف نسخ الضبط التلقائي (1200، 1201، …) وإعادة الربط إلى الحساب الأصلي (12، …).
 */
function acc_coa_cleanup_extended_code_clones(PDO $pdo): int
{
    $canonicalNames = acc_coa_canonical_leaf_names();
    $deactivated = 0;
    $deact = $pdo->prepare('UPDATE acc_account SET is_active = 0 WHERE id = ?');

    foreach ($pdo->query('SELECT id, code, name_ar FROM acc_account WHERE is_active = 1')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        $codeDigits = acc_account_code_digits((string) ($row['code'] ?? ''));
        $nameNorm = acc_coa_normalize_name((string) ($row['name_ar'] ?? ''));
        foreach ($canonicalNames as $canonical) {
            $canonicalDigits = $canonical['digits'];
            $canonicalName = $canonical['name_ar'];
            if (!acc_coa_code_is_extended_clone($canonicalDigits, $codeDigits)) {
                continue;
            }
            if ($nameNorm !== acc_coa_normalize_name($canonicalName)) {
                continue;
            }
            if (acc_coa_journal_line_count($pdo, $id) > 0 || acc_coa_is_posting_target($pdo, $id)) {
                continue;
            }
            $deact->execute([$id]);
            $deactivated++;
            break;
        }
    }

    $index = acc_coa_index_accounts($pdo);
    foreach ($canonicalNames as $canonical) {
        $existing = acc_coa_find_digits($index, $canonical['digits']);
        if (!$existing || (int) ($existing['is_leaf'] ?? 0) === 1) {
            continue;
        }
        $id = (int) $existing['id'];
        $st = $pdo->prepare('SELECT 1 FROM acc_account WHERE parent_id = ? AND is_active = 1 LIMIT 1');
        $st->execute([$id]);
        if ($st->fetchColumn()) {
            continue;
        }
        $pdo->prepare('UPDATE acc_account SET is_leaf = 1, is_active = 1 WHERE id = ?')->execute([$id]);
    }

    if (acc_gl_has_posting_table($pdo)) {
        $stUpd = $pdo->prepare('UPDATE acc_posting_setting SET account_id = ? WHERE rule_code = ?');
        $ruleResolvers = [
            'ar_customers' => static fn (PDO $p): int => acc_coa_find_global_ar_customers_id($p),
            'inventory' => static fn (PDO $p): int => acc_coa_find_global_inventory_id($p),
            'cash' => static fn (PDO $p): int => acc_coa_find_global_cash_box_id($p),
            'bank' => static fn (PDO $p): int => acc_coa_find_global_bank_id($p),
            'checks_fund' => static fn (PDO $p): int => acc_coa_find_global_checks_fund_id($p),
        ];
        foreach ($ruleResolvers as $rule => $resolver) {
            $accId = $resolver($pdo);
            if ($accId > 0) {
                $stUpd->execute([$accId, $rule]);
            }
        }
        $index = acc_coa_index_accounts($pdo);
        foreach (acc_coa_posting_rule_canonical_codes() as $rule => $canonical) {
            if (isset($ruleResolvers[$rule])) {
                continue;
            }
            $acc = acc_coa_find_digits($index, $canonical);
            if (!$acc || (int) ($acc['is_leaf'] ?? 0) !== 1) {
                continue;
            }
            if (strlen(acc_account_code_digits((string) ($acc['code'] ?? ''))) <= 2) {
                continue;
            }
            $stUpd->execute([(int) $acc['id'], $rule]);
        }
    }

    return $deactivated;
}

/**
 * @param list<string> $keywords
 * @return array<string, mixed>|null
 */
function acc_coa_find_leaf_under_parent(PDO $pdo, int $parentId, string $accountType, array $keywords, ?string $exactName = null): ?array
{
    if ($parentId < 1) {
        return null;
    }
    $st = $pdo->prepare(
        'SELECT * FROM acc_account
         WHERE parent_id = ? AND account_type = ? AND is_leaf = 1 AND is_active = 1
         ORDER BY sort_order ASC, code ASC, id ASC'
    );
    $st->execute([$parentId, $accountType]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($exactName !== null && $exactName !== '') {
        $target = acc_coa_normalize_name($exactName);
        foreach ($rows as $row) {
            if (acc_coa_normalize_name((string) ($row['name_ar'] ?? '')) === $target) {
                return $row;
            }
        }
    }

    if ($keywords !== []) {
        foreach ($rows as $row) {
            if (acc_coa_name_matches((string) ($row['name_ar'] ?? ''), $keywords)) {
                return $row;
            }
        }
    }

    return null;
}

/** @return array<string, mixed>|null */
function acc_coa_find_account_by_name_under_parent(
    PDO $pdo,
    ?int $parentId,
    string $accountType,
    string $nameAr,
    ?bool $isLeaf = null
): ?array {
    $sql = 'SELECT * FROM acc_account WHERE account_type = ? AND parent_id ' . ($parentId === null ? 'IS NULL' : '= ?');
    if ($isLeaf !== null) {
        $sql .= ' AND is_leaf = ?';
    }
    $sql .= ' ORDER BY sort_order ASC, code ASC, id ASC';

    $bind = [$accountType];
    if ($parentId !== null) {
        $bind[] = $parentId;
    }
    if ($isLeaf !== null) {
        $bind[] = $isLeaf ? 1 : 0;
    }
    $st = $pdo->prepare($sql);
    $st->execute($bind);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $target = acc_coa_normalize_name($nameAr);
    foreach ($rows as $row) {
        if (acc_coa_normalize_name((string) ($row['name_ar'] ?? '')) === $target) {
            return $row;
        }
    }

    return null;
}

/**
 * @return list<array{parent_id: int|null, name_ar: string, count: int}>
 */
function acc_coa_duplicate_name_groups(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT parent_id, name_ar, COUNT(*) AS cnt
         FROM acc_account
         WHERE is_active = 1 AND is_leaf = 1
         GROUP BY parent_id, name_ar
         HAVING cnt > 1
         ORDER BY cnt DESC, name_ar ASC"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'parent_id' => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            'name_ar' => (string) ($row['name_ar'] ?? ''),
            'count' => (int) ($row['cnt'] ?? 0),
        ];
    }

    return $out;
}

/** @param list<int> $ids */
function acc_coa_pick_keep_account_id(PDO $pdo, array $ids, string $nameAr): int
{
    if ($ids === []) {
        return 0;
    }
    $nameNorm = acc_coa_normalize_name($nameAr);
    foreach ($ids as $aid) {
        $row = acc_account_get($pdo, $aid);
        if (!$row) {
            continue;
        }
        $digits = acc_account_code_digits((string) ($row['code'] ?? ''));
        foreach (acc_coa_canonical_leaf_names() as $canonical) {
            if ($canonical['digits'] === $digits
                && acc_coa_normalize_name($canonical['name_ar']) === $nameNorm) {
                return $aid;
            }
        }
    }
    foreach ($ids as $aid) {
        if (acc_coa_is_posting_target($pdo, $aid)) {
            return $aid;
        }
    }
    foreach ($ids as $aid) {
        if (acc_coa_journal_line_count($pdo, $aid) > 0) {
            return $aid;
        }
    }
    $bestId = $ids[0];
    $bestLen = PHP_INT_MAX;
    foreach ($ids as $aid) {
        $row = acc_account_get($pdo, $aid);
        if (!$row) {
            continue;
        }
        $len = strlen(acc_account_code_digits((string) ($row['code'] ?? '')));
        if ($len < $bestLen) {
            $bestLen = $len;
            $bestId = $aid;
        }
    }

    return $bestId;
}

/** @return array<int, true> */
function acc_coa_build_keep_account_ids(PDO $pdo): array
{
    $keep = [];
    $index = acc_coa_index_accounts($pdo);
    foreach (acc_coa_canonical_leaf_names() as $canonical) {
        $row = acc_coa_find_digits($index, $canonical['digits']);
        if ($row) {
            $keep[(int) $row['id']] = true;
        }
    }
    foreach (acc_coa_duplicate_name_groups($pdo) as $group) {
        $parentId = $group['parent_id'];
        $nameAr = $group['name_ar'];
        if ($parentId === null) {
            $st = $pdo->prepare(
                'SELECT id FROM acc_account
                 WHERE parent_id IS NULL AND name_ar = ? AND is_leaf = 1 AND is_active = 1
                 ORDER BY id ASC'
            );
            $st->execute([$nameAr]);
        } else {
            $st = $pdo->prepare(
                'SELECT id FROM acc_account
                 WHERE parent_id = ? AND name_ar = ? AND is_leaf = 1 AND is_active = 1
                 ORDER BY id ASC'
            );
            $st->execute([$parentId, $nameAr]);
        }
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if (count($ids) < 2) {
            continue;
        }
        $keepId = acc_coa_pick_keep_account_id($pdo, $ids, $nameAr);
        if ($keepId > 0) {
            $keep[$keepId] = true;
        }
    }

    return $keep;
}

/** @param array<string, mixed> $row */
function acc_coa_row_is_bootstrap_duplicate(PDO $pdo, array $row, array $keepIds): bool
{
    $id = (int) ($row['id'] ?? 0);
    if ($id < 1 || isset($keepIds[$id])) {
        return false;
    }
    if ((int) ($row['is_leaf'] ?? 0) !== 1) {
        return false;
    }
    $codeDigits = acc_account_code_digits((string) ($row['code'] ?? ''));
    $nameNorm = acc_coa_normalize_name((string) ($row['name_ar'] ?? ''));
    foreach (acc_coa_canonical_leaf_names() as $canonical) {
        if (acc_coa_code_is_extended_clone($canonical['digits'], $codeDigits)
            && $nameNorm === acc_coa_normalize_name($canonical['name_ar'])) {
            return true;
        }
    }
    $parentId = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
    if ($parentId === null) {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM acc_account
             WHERE parent_id IS NULL AND name_ar = ? AND is_leaf = 1 AND is_active = 1'
        );
        $st->execute([(string) ($row['name_ar'] ?? '')]);
    } else {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM acc_account
             WHERE parent_id = ? AND name_ar = ? AND is_leaf = 1 AND is_active = 1'
        );
        $st->execute([$parentId, (string) ($row['name_ar'] ?? '')]);
    }

    return (int) $st->fetchColumn() > 1;
}

/** إيقاف حسابات فرعية مكررة (بدون حركة وغير مربوطة بقواعد الترحيل). */
function acc_coa_deduplicate_redundant_accounts(PDO $pdo): int
{
    $deactivated = 0;
    foreach (acc_coa_duplicate_name_groups($pdo) as $group) {
        $parentId = $group['parent_id'];
        $nameAr = $group['name_ar'];
        if ($parentId === null) {
            $st = $pdo->prepare(
                'SELECT id FROM acc_account
                 WHERE parent_id IS NULL AND name_ar = ? AND is_leaf = 1 AND is_active = 1
                 ORDER BY id ASC'
            );
            $st->execute([$nameAr]);
        } else {
            $st = $pdo->prepare(
                'SELECT id FROM acc_account
                 WHERE parent_id = ? AND name_ar = ? AND is_leaf = 1 AND is_active = 1
                 ORDER BY id ASC'
            );
            $st->execute([$parentId, $nameAr]);
        }
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if (count($ids) < 2) {
            continue;
        }

        $keepId = acc_coa_pick_keep_account_id($pdo, $ids, $nameAr);

        $deact = $pdo->prepare('UPDATE acc_account SET is_active = 0 WHERE id = ?');
        foreach ($ids as $aid) {
            if ($aid === $keepId) {
                continue;
            }
            if (acc_coa_journal_line_count($pdo, $aid) > 0 || acc_coa_is_posting_target($pdo, $aid)) {
                continue;
            }
            $deact->execute([$aid]);
            $deactivated++;
        }
    }

    return $deactivated;
}

/**
 * حذف الحسابات المكررة الآمنة (بدون قيود) حتى لا تظهر في الشجرة.
 * لا يحذف الحسابات الأصلية (12، 31، …) ولا ما عليه حركة محاسبية.
 */
function acc_coa_purge_redundant_accounts(PDO $pdo): int
{
    require_once app_path('includes/acc_account_tree.php');

    $deleted = 0;
    $keepIds = acc_coa_build_keep_account_ids($pdo);
    $rows = $pdo->query(
        'SELECT id, code, name_ar, parent_id, is_leaf, is_active FROM acc_account WHERE is_leaf = 1'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as $row) {
        if (!acc_coa_row_is_bootstrap_duplicate($pdo, $row, $keepIds)) {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        $chk = acc_account_delete_check($pdo, $id);
        if (!$chk['can_delete']) {
            continue;
        }
        try {
            acc_account_delete($pdo, $id);
            $deleted++;
        } catch (Throwable $e) {
            // حساب مرتبط ببيانات أخرى — يبقى معطّلاً فقط
        }
    }

    return $deleted;
}

/** حذف نسخ «البنك» 112 الفارغة عند وجود 1001100 فعلي. */
function acc_coa_purge_duplicate_bank_accounts(PDO $pdo): int
{
    require_once app_path('includes/acc_account_tree.php');

    $keepId = acc_coa_find_global_bank_id($pdo);
    if ($keepId < 1) {
        return 0;
    }

    try {
        $st = $pdo->prepare(
            "SELECT id FROM acc_account
             WHERE is_leaf = 1 AND id <> ? AND name_ar = 'البنك'"
        );
        $st->execute([$keepId]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (Throwable $e) {
        return 0;
    }

    $deleted = 0;
    foreach ($ids as $aid) {
        if (acc_coa_journal_line_count($pdo, $aid) > 0) {
            continue;
        }
        try {
            $fv = $pdo->prepare('SELECT COUNT(*) FROM fin_voucher WHERE cash_account_id = ?');
            $fv->execute([$aid]);
            if ((int) $fv->fetchColumn() > 0) {
                continue;
            }
        } catch (Throwable $e) {
            // ignore
        }
        try {
            $pdo->prepare('UPDATE acc_posting_setting SET account_id = ? WHERE account_id = ?')
                ->execute([$keepId, $aid]);
            $pdo->prepare("UPDATE acc_posting_setting SET account_id = ? WHERE rule_code = 'bank' AND (account_id IS NULL OR account_id = ?)")
                ->execute([$keepId, $aid]);
        } catch (Throwable $e) {
            // ignore
        }
        $chk = acc_account_delete_check($pdo, $aid);
        if (!$chk['can_delete']) {
            continue;
        }
        try {
            acc_account_delete($pdo, $aid);
            $deleted++;
        } catch (Throwable $e) {
            // ignore
        }
    }

    return $deleted;
}

/** حذف نسخ «الصندوق» 111 الفارغة عند وجود صندوق رئيسي فعلي. */
function acc_coa_purge_duplicate_cash_box_accounts(PDO $pdo): int
{
    require_once app_path('includes/acc_account_tree.php');

    $keepId = acc_coa_find_global_cash_box_id($pdo);
    if ($keepId < 1) {
        return 0;
    }

    try {
        $st = $pdo->prepare(
            "SELECT id FROM acc_account
             WHERE is_active = 1 AND is_leaf = 1
               AND id <> ?
               AND (
                 code = '111'
                 OR name_ar = 'الصندوق'
                 OR (name_ar LIKE '%صندوق%' AND name_ar NOT LIKE '%شيك%' AND name_ar NOT LIKE '%رئيسي%')
               )"
        );
        $st->execute([$keepId]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (Throwable $e) {
        return 0;
    }

    $deleted = 0;
    foreach ($ids as $aid) {
        if (acc_coa_journal_line_count($pdo, $aid) > 0) {
            continue;
        }
        try {
            $fv = $pdo->prepare('SELECT COUNT(*) FROM fin_voucher WHERE cash_account_id = ?');
            $fv->execute([$aid]);
            if ((int) $fv->fetchColumn() > 0) {
                continue;
            }
        } catch (Throwable $e) {
            // ignore
        }
        try {
            $pdo->prepare('UPDATE acc_posting_setting SET account_id = ? WHERE account_id = ?')
                ->execute([$keepId, $aid]);
            $pdo->prepare("UPDATE acc_posting_setting SET account_id = ? WHERE rule_code = 'cash' AND (account_id IS NULL OR account_id = ?)")
                ->execute([$keepId, $aid]);
        } catch (Throwable $e) {
            // ignore
        }

        $chk = acc_account_delete_check($pdo, $aid);
        if (!$chk['can_delete']) {
            continue;
        }
        try {
            acc_account_delete($pdo, $aid);
            $deleted++;
        } catch (Throwable $e) {
            // ignore
        }
    }

    return $deleted;
}

/** حذف نسخ «صندوق الشيكات» المكررة عالمياً (مثلاً 113 تحت 11 والحساب الفعلي تحت الصناديق). */
function acc_coa_purge_duplicate_checks_fund_accounts(PDO $pdo): int
{
    require_once app_path('includes/acc_account_tree.php');

    try {
        $st = $pdo->query(
            "SELECT id FROM acc_account
             WHERE is_active = 1 AND is_leaf = 1
               AND (name_ar LIKE '%صندوق الشيكات%' OR name_ar LIKE '%شيكات تحت%'
                    OR (name_ar LIKE '%شيكات%' AND name_ar LIKE '%صندوق%'))
             ORDER BY id ASC"
        );
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (Throwable $e) {
        return 0;
    }
    if (count($ids) < 2) {
        return 0;
    }

    $keepId = acc_coa_pick_keep_account_id($pdo, $ids, 'صندوق الشيكات');
    if ($keepId < 1) {
        $keepId = acc_coa_find_global_checks_fund_id($pdo);
    }
    if ($keepId < 1) {
        return 0;
    }

    $deleted = 0;
    foreach ($ids as $aid) {
        if ($aid === $keepId) {
            continue;
        }
        $chk = acc_account_delete_check($pdo, $aid);
        if (!$chk['can_delete']) {
            continue;
        }
        try {
            acc_account_delete($pdo, $aid);
            $deleted++;
        } catch (Throwable $e) {
            // يبقى معطّلاً أو مربوطاً
        }
    }

    return $deleted;
}

/**
 * تنظيف الشجرة: إيقاف ثم حذف المكررات الفارغة، وإبقاء الحسابات الأصلية.
 *
 * @return array{clones:int, deactivated:int, deleted:int, checks_dupes:int}
 */
/** معرّف جذر «الأصول / الموجودات» (كود 1). */
function acc_coa_find_assets_root_id(PDO $pdo): int
{
    try {
        $st = $pdo->query(
            "SELECT id FROM acc_account
             WHERE parent_id IS NULL AND is_active = 1
               AND (code = '1' OR account_type = 'asset')
             ORDER BY (code = '1') DESC, sort_order ASC, id ASC
             LIMIT 1"
        );

        return $st ? (int) $st->fetchColumn() : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

/** معرّف «الموجودات المتداولة» (كود 1001 أو الاسم المعتاد). */
function acc_coa_find_current_assets_id(PDO $pdo): int
{
    try {
        $st = $pdo->query(
            "SELECT id FROM acc_account
             WHERE is_active = 1 AND is_leaf = 0
               AND (code = '1001' OR name_ar LIKE '%موجودات متداولة%' OR name_ar LIKE '%أصول متداولة%')
             ORDER BY (code = '1001') DESC, sort_order ASC, id ASC
             LIMIT 1"
        );
        $id = $st ? (int) $st->fetchColumn() : 0;
        if ($id > 0) {
            return $id;
        }
    } catch (Throwable $e) {
        return 0;
    }

    return 0;
}

/** حساب ذمم العملاء المعتمد للترحيل (الأكثر حركة ثم الربط). */
function acc_coa_find_global_ar_customers_id(PDO $pdo): int
{
    if (!acc_journal_has_tables($pdo)) {
        return 0;
    }
    try {
        $st = $pdo->query(
            "SELECT a.id FROM acc_account a
             WHERE a.is_active = 1 AND a.is_leaf = 1 AND a.account_type = 'asset'
               AND LENGTH(a.code) > 4
               AND (a.name_ar LIKE '%عميل%' OR a.name_ar LIKE '%ذمم%مدين%')
             ORDER BY
               (SELECT COUNT(*) FROM acc_journal_line l WHERE l.account_id = a.id) DESC,
               LENGTH(a.code) DESC,
               a.id ASC
             LIMIT 1"
        );
        $id = $st ? (int) $st->fetchColumn() : 0;
        if ($id > 0 && acc_coa_journal_line_count($pdo, $id) > 0) {
            return $id;
        }

        $st = $pdo->query(
            "SELECT ps.account_id FROM acc_posting_setting ps
             INNER JOIN acc_account a ON a.id = ps.account_id AND a.is_active = 1 AND a.is_leaf = 1
             WHERE ps.rule_code = 'ar_customers' AND ps.account_id IS NOT NULL
               AND LENGTH(a.code) > 4
             LIMIT 1"
        );
        $posted = $st ? (int) $st->fetchColumn() : 0;
        if ($posted > 0) {
            return $posted;
        }

        return $id;
    } catch (Throwable $e) {
        return 0;
    }
}

/** حساب المخزون المعتمد للترحيل. */
function acc_coa_find_global_inventory_id(PDO $pdo): int
{
    if (!acc_journal_has_tables($pdo)) {
        return 0;
    }
    try {
        $st = $pdo->query(
            "SELECT a.id FROM acc_account a
             WHERE a.is_active = 1 AND a.is_leaf = 1 AND a.account_type = 'asset'
               AND a.name_ar LIKE '%مخزون%'
             ORDER BY
               (SELECT COUNT(*) FROM acc_journal_line l WHERE l.account_id = a.id) DESC,
               LENGTH(a.code) DESC,
               a.id ASC
             LIMIT 1"
        );
        $id = $st ? (int) $st->fetchColumn() : 0;
        if ($id > 0 && acc_coa_journal_line_count($pdo, $id) > 0) {
            return $id;
        }

        $st = $pdo->query(
            "SELECT ps.account_id FROM acc_posting_setting ps
             INNER JOIN acc_account a ON a.id = ps.account_id AND a.is_active = 1 AND a.is_leaf = 1
             WHERE ps.rule_code = 'inventory' AND ps.account_id IS NOT NULL
               AND LENGTH(a.code) > 4
             LIMIT 1"
        );
        $posted = $st ? (int) $st->fetchColumn() : 0;

        return $posted > 0 ? $posted : $id;
    } catch (Throwable $e) {
        return 0;
    }
}

/** حساب تكلفة البضاعة المباعة المعتمد للترحيل. */
function acc_coa_find_global_cogs_id(PDO $pdo): int
{
    if (!acc_journal_has_tables($pdo)) {
        return 0;
    }

    $candidates = acc_coa_find_cogs_candidate_ids($pdo);
    if ($candidates !== []) {
        return acc_coa_pick_cogs_keep_account_id($pdo, $candidates);
    }

    try {
        $index = acc_coa_index_accounts($pdo);
        $canonical = acc_coa_find_digits($index, '54');
        if ($canonical && (int) ($canonical['is_leaf'] ?? 0) === 1) {
            return (int) ($canonical['id'] ?? 0);
        }
    } catch (Throwable $e) {
        return 0;
    }

    return 0;
}

/**
 * دمج الفروع القصيرة (11، 12، 13، 15) تحت الموجودات المتداولة وإزالة التكرار قبل إعادة الترقيم.
 *
 * @return array{reparented:int, merged:int, deactivated:int, messages:list<string>}
 */
function acc_coa_normalize_asset_tree(PDO $pdo): array
{
    require_once app_path('includes/acc_account_reassign.php');

    $out = ['reparented' => 0, 'merged' => 0, 'deactivated' => 0, 'messages' => []];
    if (!acc_journal_has_tables($pdo)) {
        return $out;
    }

    $assetsId = acc_coa_find_assets_root_id($pdo);
    if ($assetsId < 1) {
        return $out;
    }

    $caId = acc_coa_find_current_assets_id($pdo);
    if ($caId < 1) {
        try {
            $pdo->prepare(
                'INSERT INTO acc_account (code, name_ar, parent_id, account_type, is_leaf, is_active, sort_order)
                 VALUES (?,?,?,?,0,1,10)'
            )->execute(['1001', 'الموجودات المتداولة', $assetsId, 'asset']);
            $caId = (int) $pdo->lastInsertId();
        } catch (Throwable $e) {
            return $out;
        }
    }

    $legacyCodes = ['11', '12', '13', '15', '1003', '1004', '1005'];
    $placeholders = implode(',', array_fill(0, count($legacyCodes), '?'));
    $params = array_merge([$caId, $assetsId, $caId], $legacyCodes);
    $st = $pdo->prepare(
        "UPDATE acc_account SET parent_id = ?
         WHERE parent_id = ? AND id <> ? AND code IN ($placeholders)"
    );
    $st->execute($params);
    $out['reparented'] = $st->rowCount();

    $mergePair = static function (int $fromId, int $toId) use ($pdo, &$out): void {
        if ($fromId < 1 || $toId < 1 || $fromId === $toId) {
            return;
        }
        $res = acc_account_reassign_all($pdo, $fromId, $toId, [
            'deactivate_source' => true,
            'delete_source' => true,
        ]);
        if ($res['ok']) {
            $out['merged']++;
            if ($res['journal_lines'] + $res['posting_rules'] + $res['vouchers'] > 0) {
                $out['messages'][] = $res['message'];
            }
        }
    };

    $stInv = $pdo->prepare('SELECT id FROM acc_account WHERE code = ? LIMIT 1');
    $invIds = [];
    foreach (['1005', '13'] as $invCode) {
        $stInv->execute([$invCode]);
        $iid = (int) $stInv->fetchColumn();
        if ($iid > 0) {
            $invIds[$iid] = $invCode;
        }
    }
    if (count($invIds) === 2) {
        $invIdsList = array_keys($invIds);
        $keepInv = acc_coa_pick_keep_account_id($pdo, $invIdsList, 'المخزون');
        $dropInv = $invIdsList[0] === $keepInv ? $invIdsList[1] : $invIdsList[0];
        $mergePair($dropInv, $keepInv);
    }

    $arKeep = acc_coa_find_global_ar_customers_id($pdo);
    $stAr = $pdo->prepare('SELECT id FROM acc_account WHERE code = ? LIMIT 1');
    $stAr->execute(['1004']);
    $arDup = (int) $stAr->fetchColumn();
    if ($arKeep > 0 && $arDup > 0 && $arKeep !== $arDup) {
        $mergePair($arDup, $arKeep);
    }

    $cashMain = acc_coa_find_global_cash_box_id($pdo);
    $bankMain = acc_coa_find_global_bank_id($pdo);
    $checksMain = acc_coa_find_global_checks_fund_id($pdo);
    foreach ([
        '111' => $cashMain,
        '112' => $bankMain,
        '113' => $checksMain,
    ] as $legacyCode => $targetId) {
        if ($targetId < 1) {
            continue;
        }
        $stLeg = $pdo->prepare('SELECT id FROM acc_account WHERE code = ? LIMIT 1');
        $stLeg->execute([$legacyCode]);
        $legacyId = (int) $stLeg->fetchColumn();
        if ($legacyId > 0) {
            $mergePair($legacyId, $targetId);
        }
    }

    $st11 = $pdo->prepare('SELECT id FROM acc_account WHERE code = ? LIMIT 1');
    $st11->execute(['11']);
    $legacy11 = (int) $st11->fetchColumn();
    if ($legacy11 > 0 && acc_coa_journal_line_count($pdo, $legacy11) === 0) {
        $childSt = $pdo->prepare('SELECT COUNT(*) FROM acc_account WHERE parent_id = ? AND is_active = 1');
        $childSt->execute([$legacy11]);
        if ((int) $childSt->fetchColumn() === 0) {
            $pdo->prepare('UPDATE acc_account SET is_active = 0 WHERE id = ?')->execute([$legacy11]);
            $out['deactivated']++;
        }
    }

    $stEmpty = $pdo->prepare('SELECT id FROM acc_account WHERE code = ? LIMIT 1');
    foreach (['1003'] as $emptyCode) {
        $stEmpty->execute([$emptyCode]);
        $eid = (int) $stEmpty->fetchColumn();
        if ($eid > 0 && acc_coa_journal_line_count($pdo, $eid) === 0 && !acc_coa_is_posting_target($pdo, $eid)) {
            $chk = acc_account_delete_check($pdo, $eid);
            if ($chk['can_delete']) {
                try {
                    acc_account_delete($pdo, $eid);
                } catch (Throwable $e) {
                    $pdo->prepare('UPDATE acc_account SET is_active = 0 WHERE id = ?')->execute([$eid]);
                    $out['deactivated']++;
                }
            }
        }
    }

    if ($out['reparented'] > 0) {
        $out['messages'][] = 'تم نقل ' . $out['reparented'] . ' حساباً قديماً تحت «الموجودات المتداولة».';
    }

    return $out;
}

/** حذف/إيقاف فروع بأكواد قصيرة (11، 12، …) أُنشئت بالخطأ بعد إعادة الترقيم الهرمي. */
function acc_coa_purge_stale_short_code_clones(PDO $pdo): int
{
    require_once app_path('includes/acc_account_tree.php');

    $removed = 0;
    $rows = $pdo->query(
        "SELECT id, code, name_ar FROM acc_account
         WHERE is_active = 1 AND parent_id IS NOT NULL
           AND code REGEXP '^[0-9]{1,2}$'"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $tryRemove = static function (int $id) use ($pdo, &$removed, &$tryRemove): void {
        if ($id < 1) {
            return;
        }
        if (acc_coa_journal_line_count($pdo, $id) > 0 || acc_coa_is_posting_target($pdo, $id)) {
            return;
        }
        $st = $pdo->prepare('SELECT id FROM acc_account WHERE parent_id = ? AND is_active = 1');
        $st->execute([$id]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $childId) {
            $tryRemove((int) $childId);
        }
        $chk = acc_account_delete_check($pdo, $id);
        if ($chk['can_delete']) {
            try {
                acc_account_delete($pdo, $id);
                $removed++;
            } catch (Throwable $e) {
                $pdo->prepare('UPDATE acc_account SET is_active = 0 WHERE id = ?')->execute([$id]);
                $removed++;
            }
        } else {
            $pdo->prepare('UPDATE acc_account SET is_active = 0 WHERE id = ?')->execute([$id]);
            $removed++;
        }
    };

    foreach ($rows as $row) {
        $tryRemove((int) ($row['id'] ?? 0));
    }

    $legacyParents = $pdo->query(
        "SELECT id FROM acc_account
         WHERE is_active = 1 AND code IN ('11','12','13','15')
           AND parent_id IS NOT NULL"
    )->fetchAll(PDO::FETCH_COLUMN) ?: [];
    foreach ($legacyParents as $pid) {
        $tryRemove((int) $pid);
    }

    return $removed;
}

function acc_coa_maintain_tree(PDO $pdo): array
{
    $clones = acc_coa_cleanup_extended_code_clones($pdo);
    $deactivated = acc_coa_deduplicate_redundant_accounts($pdo);
    $bankDupes = acc_coa_purge_duplicate_bank_accounts($pdo);
    $cashDupes = acc_coa_purge_duplicate_cash_box_accounts($pdo);
    $checksDupes = acc_coa_purge_duplicate_checks_fund_accounts($pdo);
    $shortDupes = acc_coa_purge_stale_short_code_clones($pdo);
    $deleted = acc_coa_purge_redundant_accounts($pdo) + $checksDupes + $cashDupes + $bankDupes + $shortDupes;

    return [
        'clones' => $clones,
        'deactivated' => $deactivated,
        'deleted' => $deleted,
        'checks_dupes' => $checksDupes,
        'cash_dupes' => $cashDupes,
        'bank_dupes' => $bankDupes,
        'short_dupes' => $shortDupes,
    ];
}

/**
 * @param array{
 *   code: string,
 *   name_ar: string,
 *   parent_code: ?string,
 *   account_type: string,
 *   is_leaf: bool,
 *   sort_order: int,
 *   role_keywords?: list<string>,
 * } $spec
 */
/** @param array<string, mixed> $spec */
function acc_coa_spec_is_cash_box(array $spec): bool
{
    $digits = acc_account_code_digits((string) ($spec['code'] ?? ''));
    if ($digits === '111') {
        return true;
    }
    $n = acc_coa_normalize_name((string) ($spec['name_ar'] ?? ''));

    return $n === acc_coa_normalize_name('الصندوق')
        || $n === acc_coa_normalize_name('صندوق');
}

/** حساب الصندوق النقدي المعتمد (ربط cash ثم صندوق رئيسي ثم الأكثر حركة). */
/** معرّف حساب «الصندوق / النقدية» للربط — بدون إنشاء 111 تحت النقدية والبنوك. */
function acc_coa_resolve_posting_cash_id(PDO $pdo, array &$index): int
{
    $id = acc_coa_find_global_cash_box_id($pdo);
    if ($id > 0) {
        return $id;
    }

    return acc_coa_resolve_leaf($pdo, $index, [
        'code' => '1001001001',
        'name_ar' => 'صندوق رئيسي',
        'parent_code' => '1',
        'account_type' => 'asset',
        'sort_order' => 10,
        'role_keywords' => ['صندوق', 'رئيسي', 'نقد'],
    ]);
}

function acc_coa_find_global_cash_box_id(PDO $pdo): int
{
    if (!acc_journal_has_tables($pdo)) {
        return 0;
    }
    try {
        // الصندوق التشغيلي (صندوق رئيسي) يُفضَّل على 111 القديم حتى لو كان الربط يشير إليه
        $st = $pdo->query(
            "SELECT id FROM acc_account
             WHERE is_active = 1 AND is_leaf = 1
               AND (code IN ('1001002001', '1001001001') OR name_ar LIKE '%صندوق رئيسي%')
             ORDER BY (code = '1001002001') DESC, (name_ar LIKE '%صندوق رئيسي%') DESC, id ASC
             LIMIT 1"
        );
        $id = $st ? (int) $st->fetchColumn() : 0;
        if ($id > 0) {
            return $id;
        }

        $st = $pdo->query(
            "SELECT ps.account_id FROM acc_posting_setting ps
             INNER JOIN acc_account a ON a.id = ps.account_id AND a.is_active = 1 AND a.is_leaf = 1
             WHERE ps.rule_code = 'cash' AND ps.account_id IS NOT NULL
               AND a.code <> '111'
             LIMIT 1"
        );
        $id = $st ? (int) $st->fetchColumn() : 0;
        if ($id > 0) {
            return $id;
        }

        $st = $pdo->query(
            "SELECT a.id FROM acc_account a
             WHERE a.is_active = 1 AND a.is_leaf = 1 AND a.account_type = 'asset'
               AND (a.name_ar LIKE '%صندوق%' OR a.name_ar LIKE '%نقد%')
               AND a.name_ar NOT LIKE '%شيك%'
             ORDER BY
               (SELECT COUNT(*) FROM acc_journal_line l WHERE l.account_id = a.id) DESC,
               LENGTH(a.code) DESC,
               a.id ASC
             LIMIT 1"
        );

        return $st ? (int) $st->fetchColumn() : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

/** @param array<string, mixed> $spec */
function acc_coa_spec_is_bank(array $spec): bool
{
    $digits = acc_account_code_digits((string) ($spec['code'] ?? ''));
    if ($digits === '112') {
        return true;
    }

    return acc_coa_normalize_name((string) ($spec['name_ar'] ?? ''))
        === acc_coa_normalize_name('البنك');
}

/** حساب البنك المعتمد (ربط bank ثم 1001100 ثم الأكثر حركة). */
function acc_coa_find_global_bank_id(PDO $pdo): int
{
    if (!acc_journal_has_tables($pdo)) {
        return 0;
    }
    try {
        $st = $pdo->query(
            "SELECT ps.account_id FROM acc_posting_setting ps
             INNER JOIN acc_account a ON a.id = ps.account_id AND a.is_active = 1 AND a.is_leaf = 1
             WHERE ps.rule_code = 'bank' AND ps.account_id IS NOT NULL
             LIMIT 1"
        );
        $id = $st ? (int) $st->fetchColumn() : 0;
        if ($id > 0) {
            return $id;
        }

        $st = $pdo->query(
            "SELECT id FROM acc_account
             WHERE is_active = 1 AND is_leaf = 1 AND code = '1001100'
             LIMIT 1"
        );
        $id = $st ? (int) $st->fetchColumn() : 0;
        if ($id > 0) {
            return $id;
        }

        $st = $pdo->query(
            "SELECT a.id FROM acc_account a
             WHERE a.is_active = 1 AND a.is_leaf = 1 AND a.account_type = 'asset'
               AND a.name_ar = 'البنك'
             ORDER BY
               (SELECT COUNT(*) FROM acc_journal_line l WHERE l.account_id = a.id) DESC,
               LENGTH(a.code) DESC,
               a.id ASC
             LIMIT 1"
        );

        return $st ? (int) $st->fetchColumn() : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

/** @param array<string, mixed> $spec */
function acc_coa_spec_is_checks_fund(array $spec): bool
{
    $digits = acc_account_code_digits((string) ($spec['code'] ?? ''));
    if ($digits === '113') {
        return true;
    }

    return acc_coa_normalize_name((string) ($spec['name_ar'] ?? ''))
        === acc_coa_normalize_name('صندوق الشيكات');
}

/** حساب صندوق الشيكات النشط (أولاً المستخدم فعلياً في القيود/الربط). */
function acc_coa_find_global_checks_fund_id(PDO $pdo): int
{
    if (!acc_journal_has_tables($pdo)) {
        return 0;
    }
    try {
        $st = $pdo->query(
            "SELECT a.id FROM acc_account a
             WHERE a.is_active = 1 AND a.is_leaf = 1
               AND (a.name_ar LIKE '%صندوق الشيكات%' OR a.name_ar LIKE '%شيكات تحت%'
                    OR (a.name_ar LIKE '%شيكات%' AND a.name_ar LIKE '%صندوق%'))
             ORDER BY
               (EXISTS (
                  SELECT 1 FROM acc_posting_setting ps
                  WHERE ps.rule_code = 'checks_fund' AND ps.account_id = a.id
               )) DESC,
               (SELECT COUNT(*) FROM acc_journal_line l WHERE l.account_id = a.id) DESC,
               LENGTH(a.code) DESC,
               a.id ASC
             LIMIT 1"
        );
        $id = $st ? (int) $st->fetchColumn() : 0;

        return $id > 0 ? $id : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

function acc_coa_ensure_account(PDO $pdo, array &$index, array $spec): int
{
    $reclaimed = acc_coa_reclaim_preferred_account($pdo, $index, $spec);
    if ($reclaimed > 0) {
        return $reclaimed;
    }

    if (acc_coa_spec_is_checks_fund($spec)) {
        $globalChecks = acc_coa_find_global_checks_fund_id($pdo);
        if ($globalChecks > 0) {
            return $globalChecks;
        }
    }

    if (acc_coa_spec_is_cash_box($spec)) {
        $globalCash = acc_coa_find_global_cash_box_id($pdo);
        if ($globalCash > 0) {
            return $globalCash;
        }
    }

    if (acc_coa_spec_is_bank($spec)) {
        $globalBank = acc_coa_find_global_bank_id($pdo);
        if ($globalBank > 0) {
            return $globalBank;
        }
    }

    $codeDigits = acc_account_code_digits($spec['code']);
    $existing = acc_coa_find_digits($index, $codeDigits);
    if ($existing) {
        $wantsLeaf = !empty($spec['is_leaf']);
        $isLeaf = (int) ($existing['is_leaf'] ?? 0) === 1;
        if (!$wantsLeaf || $isLeaf) {
            return (int) $existing['id'];
        }
        if ($wantsLeaf && !empty($spec['parent_code'])) {
            $parentDigits = acc_account_code_digits((string) $spec['parent_code']);
            $parent = acc_coa_find_digits($index, $parentDigits);
            if ($parent) {
                $sibling = acc_coa_find_leaf_under_parent(
                    $pdo,
                    (int) $parent['id'],
                    $spec['account_type'],
                    [],
                    (string) ($spec['name_ar'] ?? '')
                );
                if ($sibling) {
                    return (int) $sibling['id'];
                }
            }
        }

        return (int) $existing['id'];
    }

    $parentId = null;
    if (!empty($spec['parent_code'])) {
        $parentDigits = acc_account_code_digits((string) $spec['parent_code']);
        $parent = acc_coa_find_digits($index, $parentDigits);
        if (!$parent && $parentDigits === '11') {
            $liquidityParentId = acc_coa_find_liquidity_parent_id($pdo);
            if ($liquidityParentId !== null) {
                $st = $pdo->prepare('SELECT * FROM acc_account WHERE id = ? LIMIT 1');
                $st->execute([$liquidityParentId]);
                $parent = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            }
        }
        if (!$parent) {
            throw new RuntimeException('الحساب الأب غير موجود: ' . $spec['parent_code']);
        }
        $parentId = (int) $parent['id'];
        if ((int) ($parent['is_leaf'] ?? 0) === 1) {
            $pdo->prepare('UPDATE acc_account SET is_leaf = 0 WHERE id = ?')->execute([$parentId]);
            $index[$parentDigits]['is_leaf'] = 0;
        }
    }

    $sameName = acc_coa_find_account_by_name_under_parent(
        $pdo,
        $parentId,
        (string) $spec['account_type'],
        (string) $spec['name_ar'],
        !empty($spec['is_leaf']) ? true : null
    );
    if ($sameName) {
        return (int) $sameName['id'];
    }

    $useCode = acc_coa_unique_code($pdo, $parentId, $codeDigits);

    $pdo->prepare(
        'INSERT INTO acc_account (code, name_ar, parent_id, account_type, is_leaf, is_active, sort_order)
         VALUES (?,?,?,?,?,1,?)'
    )->execute([
        $useCode,
        $spec['name_ar'],
        $parentId,
        $spec['account_type'],
        $spec['is_leaf'] ? 1 : 0,
        $spec['sort_order'],
    ]);
    $id = (int) $pdo->lastInsertId();
    $st = $pdo->prepare('SELECT * FROM acc_account WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $index[acc_account_code_digits((string) $row['code'])] = $row;
    }

    return $id;
}

/**
 * يختار حساباً نهائياً للربط؛ إن وُجد كود مستخدم لغرض آخر يُنشئ حساباً شقيقاً.
 *
 * @param array{
 *   code: string,
 *   name_ar: string,
 *   parent_code: string,
 *   account_type: string,
 *   sort_order: int,
 *   role_keywords: list<string>,
 * } $spec
 */
function acc_coa_resolve_leaf(PDO $pdo, array &$index, array $spec): int
{
    $reclaimed = acc_coa_reclaim_preferred_account($pdo, $index, $spec);
    if ($reclaimed > 0) {
        return $reclaimed;
    }

    $keywords = $spec['role_keywords'] ?? [];
    if (acc_coa_name_matches((string) ($spec['name_ar'] ?? ''), ['تكلفة', 'مباع', 'cogs'])
        || acc_account_code_digits((string) ($spec['code'] ?? '')) === '54') {
        $candidates = acc_coa_find_cogs_candidate_ids($pdo);
        if ($candidates !== []) {
            return acc_coa_pick_cogs_keep_account_id($pdo, $candidates);
        }
        $globalCogs = acc_coa_find_global_cogs_id($pdo);
        if ($globalCogs > 0) {
            return $globalCogs;
        }
    }

    if (((string) ($spec['account_type'] ?? '') === 'expense')
        && (acc_account_code_digits((string) ($spec['code'] ?? '')) === '52'
            || (acc_coa_name_matches((string) ($spec['name_ar'] ?? ''), ['راتب', 'أجور', 'رواتب'])
                && !acc_coa_name_matches((string) ($spec['name_ar'] ?? ''), ['مستحق'])))) {
        $salaryCandidates = acc_coa_find_salaries_expense_candidate_ids($pdo);
        if ($salaryCandidates !== []) {
            return acc_coa_pick_salaries_expense_keep_account_id($pdo, $salaryCandidates);
        }
        $globalSalary = acc_coa_find_global_salaries_expense_id($pdo);
        if ($globalSalary > 0) {
            return $globalSalary;
        }
    }

    $codeDigits = acc_account_code_digits($spec['code']);
    $existing = acc_coa_find_digits($index, $codeDigits);
    if ($existing && (int) ($existing['is_leaf'] ?? 0) === 1) {
        $id = (int) $existing['id'];
        $typeOk = (string) ($existing['account_type'] ?? '') === $spec['account_type'];
        if ($typeOk) {
            if (acc_coa_journal_line_count($pdo, $id) === 0
                && !acc_coa_name_matches((string) ($existing['name_ar'] ?? ''), $keywords)) {
                $pdo->prepare('UPDATE acc_account SET name_ar = ? WHERE id = ?')->execute([$spec['name_ar'], $id]);
                $index[$codeDigits]['name_ar'] = $spec['name_ar'];
            }

            return $id;
        }
    }

    $parentDigits = acc_account_code_digits($spec['parent_code']);
    $parent = acc_coa_find_digits($index, $parentDigits);
    if (!$parent && $parentDigits === '11') {
        $liquidityParentId = acc_coa_find_liquidity_parent_id($pdo);
        if ($liquidityParentId !== null) {
            $st = $pdo->prepare('SELECT * FROM acc_account WHERE id = ? LIMIT 1');
            $st->execute([$liquidityParentId]);
            $parent = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }
    if (!$parent) {
        throw new RuntimeException('الحساب الأب غير موجود.');
    }
    $parentId = (int) $parent['id'];

    $sibling = acc_coa_find_leaf_under_parent(
        $pdo,
        $parentId,
        $spec['account_type'],
        $keywords,
        (string) ($spec['name_ar'] ?? '')
    );
    if ($sibling) {
        return (int) $sibling['id'];
    }

    if ($existing && (int) ($existing['is_active'] ?? 1) === 0) {
        $id = (int) $existing['id'];
        if (acc_coa_journal_line_count($pdo, $id) === 0) {
            $pdo->prepare(
                'UPDATE acc_account SET name_ar = ?, account_type = ?, is_leaf = 1, is_active = 1, parent_id = ?, sort_order = ? WHERE id = ?'
            )->execute([
                $spec['name_ar'],
                $spec['account_type'],
                $parentId,
                $spec['sort_order'],
                $id,
            ]);
            acc_coa_refresh_index_row($index, $pdo, $id);

            return $id;
        }
    }

    if ($existing && (int) ($existing['is_leaf'] ?? 0) !== 1 && !acc_coa_account_has_children($pdo, (int) $existing['id'])) {
        $id = (int) $existing['id'];
        if (acc_coa_journal_line_count($pdo, $id) === 0) {
            $pdo->prepare(
                'UPDATE acc_account SET name_ar = ?, account_type = ?, is_leaf = 1, parent_id = ?, sort_order = ? WHERE id = ?'
            )->execute([
                $spec['name_ar'],
                $spec['account_type'],
                $parentId,
                $spec['sort_order'],
                $id,
            ]);
            $st = $pdo->prepare('SELECT * FROM acc_account WHERE id = ?');
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $index[acc_account_code_digits((string) $row['code'])] = $row;
            }

            return $id;
        }
    }

    $newCode = acc_coa_unique_code($pdo, $parentId, null);
    $pdo->prepare(
        'INSERT INTO acc_account (code, name_ar, parent_id, account_type, is_leaf, is_active, sort_order)
         VALUES (?,?,?,?,?,1,?)'
    )->execute([
        $newCode,
        $spec['name_ar'],
        $parentId,
        $spec['account_type'],
        1,
        $spec['sort_order'],
    ]);
    $id = (int) $pdo->lastInsertId();
    $st = $pdo->prepare('SELECT * FROM acc_account WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $index[acc_account_code_digits((string) $row['code'])] = $row;
    }

    return $id;
}

function acc_coa_meta_table_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    if (!acc_gl_has_posting_table($pdo)) {
        $ready = false;

        return false;
    }
    try {
        $pdo->query('SELECT meta_key FROM acc_system_meta LIMIT 1');
        $ready = true;
    } catch (Throwable $e) {
        $ready = false;
    }

    return $ready;
}

function acc_coa_meta_ensure_table(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS acc_system_meta (
              meta_key VARCHAR(40) NOT NULL PRIMARY KEY,
              meta_value VARCHAR(200) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $ensured = true;
    } catch (Throwable $e) {
    }
}

function acc_coa_meta_get(PDO $pdo, string $key): ?string
{
    if (!acc_coa_meta_table_ready($pdo)) {
        return null;
    }
    if (!isset($GLOBALS['_acc_coa_meta_cache']) || !is_array($GLOBALS['_acc_coa_meta_cache'])) {
        $GLOBALS['_acc_coa_meta_cache'] = [];
    }
    if (array_key_exists($key, $GLOBALS['_acc_coa_meta_cache'])) {
        return $GLOBALS['_acc_coa_meta_cache'][$key];
    }
    $st = $pdo->prepare('SELECT meta_value FROM acc_system_meta WHERE meta_key = ? LIMIT 1');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    $GLOBALS['_acc_coa_meta_cache'][$key] = $v !== false ? (string) $v : null;

    return $GLOBALS['_acc_coa_meta_cache'][$key];
}

function acc_coa_meta_set(PDO $pdo, string $key, string $value): void
{
    acc_coa_meta_ensure_table($pdo);
    $pdo->prepare(
        'INSERT INTO acc_system_meta (meta_key, meta_value) VALUES (?,?)
         ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)'
    )->execute([$key, $value]);
    if (!isset($GLOBALS['_acc_coa_meta_cache']) || !is_array($GLOBALS['_acc_coa_meta_cache'])) {
        $GLOBALS['_acc_coa_meta_cache'] = [];
    }
    $GLOBALS['_acc_coa_meta_cache'][$key] = $value;
}

/** @return list<array<string, mixed>> */
function acc_coa_bootstrap_account_specs(): array
{
    return [
        ['code' => '1', 'name_ar' => 'الأصول', 'parent_code' => null, 'account_type' => 'asset', 'is_leaf' => false, 'sort_order' => 1],
        ['code' => '2', 'name_ar' => 'الخصوم', 'parent_code' => null, 'account_type' => 'liability', 'is_leaf' => false, 'sort_order' => 2],
        ['code' => '3', 'name_ar' => 'حقوق الملكية', 'parent_code' => null, 'account_type' => 'equity', 'is_leaf' => false, 'sort_order' => 3],
        ['code' => '4', 'name_ar' => 'الإيرادات', 'parent_code' => null, 'account_type' => 'revenue', 'is_leaf' => false, 'sort_order' => 4],
        ['code' => '5', 'name_ar' => 'المصروفات', 'parent_code' => null, 'account_type' => 'expense', 'is_leaf' => false, 'sort_order' => 5],
        // الموجودات المتداولة: تحتها النقدية والذمم والمخزون (أكواد هرمية 1001xxx بعد إعادة الترقيم)
        ['code' => '1001', 'name_ar' => 'الموجودات المتداولة', 'parent_code' => '1', 'account_type' => 'asset', 'is_leaf' => false, 'sort_order' => 10],
        // الصندوق / البنك / الشيكات: acc_coa_find_global_* + 1001001001 تحت 1001
        ['code' => '2002', 'name_ar' => 'الموردون (ذمم دائنة)', 'parent_code' => '2', 'account_type' => 'liability', 'is_leaf' => true, 'sort_order' => 10],
        ['code' => '22', 'name_ar' => 'ضريبة القيمة المضافة — مستحقة', 'parent_code' => '2', 'account_type' => 'liability', 'is_leaf' => true, 'sort_order' => 20],
        ['code' => '23', 'name_ar' => 'رواتب مستحقة', 'parent_code' => '2', 'account_type' => 'liability', 'is_leaf' => true, 'sort_order' => 30],
        ['code' => '2007', 'name_ar' => 'ضريبة دخل مستحقة', 'parent_code' => '2', 'account_type' => 'liability', 'is_leaf' => true, 'sort_order' => 27],
        ['code' => '31', 'name_ar' => 'رأس المال', 'parent_code' => '3', 'account_type' => 'equity', 'is_leaf' => true, 'sort_order' => 10],
        ['code' => '41', 'name_ar' => 'إيرادات المبيعات', 'parent_code' => '4', 'account_type' => 'revenue', 'is_leaf' => true, 'sort_order' => 10],
        ['code' => '42', 'name_ar' => 'مردودات المبيعات', 'parent_code' => '4', 'account_type' => 'revenue', 'is_leaf' => true, 'sort_order' => 20],
        ['code' => '51', 'name_ar' => 'مشتريات وتوريدات', 'parent_code' => '5', 'account_type' => 'expense', 'is_leaf' => true, 'sort_order' => 10],
        ['code' => '52', 'name_ar' => 'رواتب وأجور', 'parent_code' => '5', 'account_type' => 'expense', 'is_leaf' => true, 'sort_order' => 20],
        ['code' => '53', 'name_ar' => 'مصروفات عمومية وإدارية', 'parent_code' => '5', 'account_type' => 'expense', 'is_leaf' => true, 'sort_order' => 30],
        ['code' => '54', 'name_ar' => 'تكلفة البضاعة المباعة', 'parent_code' => '5', 'account_type' => 'expense', 'is_leaf' => true, 'sort_order' => 40],
        ['code' => '55', 'name_ar' => 'مردودات المشتريات', 'parent_code' => '5', 'account_type' => 'expense', 'is_leaf' => true, 'sort_order' => 15],
    ];
}

/**
 * @return array{changed:bool, messages:list<string>, mapped:int}
 */
function acc_coa_bootstrap_run(PDO $pdo, bool $forceRemap = false): array
{
    require_once app_path('includes/acc_account_reassign.php');

    $messages = [];
    $mapped = 0;
    if (!acc_gl_ensure_schema($pdo)) {
        return ['changed' => false, 'messages' => ['تعذر تحميل جداول الربط المحاسبي.'], 'mapped' => 0];
    }

    try {
        $storedVer = (int) (acc_coa_meta_get($pdo, 'coa_bootstrap_version') ?? '0');
    } catch (Throwable $e) {
        $storedVer = 0;
    }
    $needsRemap = $forceRemap || $storedVer < ACC_COA_BOOTSTRAP_VERSION;

    if (!$forceRemap && $storedVer >= ACC_COA_BOOTSTRAP_VERSION) {
        return ['changed' => false, 'messages' => [], 'mapped' => 0];
    }

    if ($storedVer < ACC_COA_BOOTSTRAP_VERSION) {
        $maint = acc_coa_maintain_tree($pdo);
        if (($maint['deleted'] ?? 0) > 0) {
            $messages[] = 'تم حذف ' . (int) $maint['deleted'] . ' حساباً مكرراً فارغاً من الشجرة.';
        }
        if (($maint['deactivated'] ?? 0) + ($maint['clones'] ?? 0) > 0) {
            $messages[] = 'تم إيقاف ' . ((int) $maint['deactivated'] + (int) $maint['clones']) . ' حساباً مكرراً (يُبقى المعتمد إن وُجدت عليه قيود).';
        }
        if ($storedVer < 6) {
            $norm = acc_coa_normalize_asset_tree($pdo);
            foreach ($norm['messages'] as $msg) {
                $messages[] = $msg;
            }
            if (($norm['merged'] ?? 0) > 0) {
                $messages[] = 'تم دمج ' . (int) $norm['merged'] . ' حساباً مكرراً (عملاء/مخزون/نقدية).';
            }
        }
    }

    acc_coa_purge_stale_short_code_clones($pdo);

    $index = acc_coa_index_accounts($pdo);
    foreach (acc_coa_bootstrap_account_specs() as $spec) {
        try {
            acc_coa_ensure_account($pdo, $index, $spec);
        } catch (Throwable $e) {
            $messages[] = 'تعذر إنشاء حساب «' . $spec['name_ar'] . '»: ' . $e->getMessage();
        }
    }
    $index = acc_coa_index_accounts($pdo);

    $resolve = static function (array $spec) use ($pdo, &$index): int {
        return acc_coa_resolve_leaf($pdo, $index, $spec);
    };

    $miscId = $resolve([
        'code' => '53',
        'name_ar' => 'مصروفات عمومية وإدارية',
        'parent_code' => '5',
        'account_type' => 'expense',
        'sort_order' => 30,
        'role_keywords' => ['عموم', 'إدار', 'مصروف'],
    ]);
    $oldMisc = acc_coa_find_digits($index, '52');
    if ($oldMisc && (int) ($oldMisc['is_leaf'] ?? 0) === 1) {
        $n = (string) ($oldMisc['name_ar'] ?? '');
        if (acc_coa_name_matches($n, ['عموم', 'إدار']) && !acc_coa_name_matches($n, ['راتب', 'أجور'])) {
            $miscId = (int) $oldMisc['id'];
        }
    }

    $ruleAccounts = [
        'ar_customers' => (static function () use ($pdo, $resolve): int {
            $id = acc_coa_find_global_ar_customers_id($pdo);
            if ($id > 0) {
                return $id;
            }

            return $resolve([
                'code' => '1001005',
                'name_ar' => 'العملاء (ذمم مدينة)',
                'parent_code' => '1001',
                'account_type' => 'asset',
                'sort_order' => 20,
                'role_keywords' => ['عميل', 'ذمم', 'مدين'],
            ]);
        })(),
        'ap_suppliers' => $resolve([
            'code' => '2002',
            'name_ar' => 'الموردون (ذمم دائنة)',
            'parent_code' => '2',
            'account_type' => 'liability',
            'sort_order' => 10,
            'role_keywords' => ['مورد', 'ذمم', 'دائن'],
        ]),
        'cash' => acc_coa_resolve_posting_cash_id($pdo, $index),
        'bank' => $resolve([
            'code' => '1001003001',
            'name_ar' => 'البنك',
            'parent_code' => '1001',
            'account_type' => 'asset',
            'sort_order' => 12,
            'role_keywords' => ['بنك'],
        ]),
        'checks_fund' => $resolve([
            'code' => '1001002002',
            'name_ar' => 'صندوق الشيكات',
            'parent_code' => '1001',
            'account_type' => 'asset',
            'sort_order' => 13,
            'role_keywords' => ['شيكات', 'شيك'],
        ]),
        'sales_revenue' => $resolve([
            'code' => '41',
            'name_ar' => 'إيرادات المبيعات',
            'parent_code' => '4',
            'account_type' => 'revenue',
            'sort_order' => 10,
            'role_keywords' => ['مبيع', 'إيراد'],
        ]),
        'sales_returns' => $resolve([
            'code' => '42',
            'name_ar' => 'مردودات المبيعات',
            'parent_code' => '4',
            'account_type' => 'revenue',
            'sort_order' => 20,
            'role_keywords' => ['مردود', 'مرتجع'],
        ]),
        'purchases' => (static function () use ($pdo, $resolve): int {
            $acc6001 = acc_account_get_by_code($pdo, '6001');
            if ($acc6001 && (int) ($acc6001['is_leaf'] ?? 0) === 1 && (int) ($acc6001['is_active'] ?? 0) === 1) {
                return (int) $acc6001['id'];
            }

            return $resolve([
                'code' => '51',
                'name_ar' => 'مشتريات وتوريدات',
                'parent_code' => '5',
                'account_type' => 'expense',
                'sort_order' => 10,
                'role_keywords' => ['مشتري', 'توريد', 'شراء'],
            ]);
        })(),
        'purchase_returns' => $resolve([
            'code' => '55',
            'name_ar' => 'مردودات المشتريات',
            'parent_code' => '5',
            'account_type' => 'expense',
            'sort_order' => 15,
            'role_keywords' => ['مردود', 'مشتري'],
        ]),
        'inventory' => (static function () use ($pdo, $resolve): int {
            $id = acc_coa_find_global_inventory_id($pdo);
            if ($id > 0) {
                return $id;
            }

            return $resolve([
                'code' => '1001007',
                'name_ar' => 'المخزون',
                'parent_code' => '1001',
                'account_type' => 'asset',
                'sort_order' => 30,
                'role_keywords' => ['مخزون', 'بضاعة'],
            ]);
        })(),
        'vat_output' => $resolve([
            'code' => '3001002',
            'name_ar' => 'أمانات ضريبة مبيعات',
            'parent_code' => '3001',
            'account_type' => 'liability',
            'sort_order' => 20,
            'role_keywords' => ['أمانات', 'ضريبة', 'مبيع', 'vat'],
        ]),
        'vat_input' => (static function () use ($pdo): int {
            require_once app_path('includes/acc_vat_trust_account.php');
            $id = acc_vat_trust_find_account_id($pdo);
            if ($id > 0) {
                return $id;
            }

            return acc_vat_trust_ensure_account($pdo);
        })(),
        'misc_expense' => $miscId,
        'cogs' => (static function () use ($pdo, $resolve): int {
            $id = acc_coa_find_global_cogs_id($pdo);
            if ($id > 0) {
                return $id;
            }

            return $resolve([
                'code' => '54',
                'name_ar' => 'تكلفة البضاعة المباعة',
                'parent_code' => '5',
                'account_type' => 'expense',
                'sort_order' => 40,
                'role_keywords' => ['تكلفة', 'مباع', 'cogs'],
            ]);
        })(),
        'salaries_expense' => $resolve([
            'code' => '52',
            'name_ar' => 'رواتب وأجور',
            'parent_code' => '5',
            'account_type' => 'expense',
            'sort_order' => 20,
            'role_keywords' => ['راتب', 'أجور', 'رواتب'],
        ]),
        'salaries_payable' => $resolve([
            'code' => '23',
            'name_ar' => 'رواتب مستحقة',
            'parent_code' => '2',
            'account_type' => 'liability',
            'sort_order' => 30,
            'role_keywords' => ['راتب', 'مستحق', 'رواتب'],
        ]),
        'hr_income_tax' => $resolve([
            'code' => '2007',
            'name_ar' => 'ضريبة دخل مستحقة',
            'parent_code' => '2',
            'account_type' => 'liability',
            'sort_order' => 27,
            'role_keywords' => ['ضريبة', 'دخل', 'مستحق'],
        ]),
    ];

    acc_coa_bootstrap_ensure_posting_rules($pdo);

    $stRead = $pdo->prepare('SELECT account_id FROM acc_posting_setting WHERE rule_code = ? LIMIT 1');
    $stUpd = $pdo->prepare('UPDATE acc_posting_setting SET account_id = ? WHERE rule_code = ?');
    foreach ($ruleAccounts as $rule => $accId) {
        if ($accId < 1) {
            continue;
        }
        $stRead->execute([$rule]);
        $cur = $stRead->fetchColumn();
        $curId = $cur !== false && $cur !== null ? (int) $cur : 0;
        if ($needsRemap || $curId < 1) {
            $stUpd->execute([$accId, $rule]);
            $mapped++;
        }
    }

    if ($storedVer < 6) {
        try {
            $renumbered = acc_account_recode_hierarchy($pdo);
            if ($renumbered > 0) {
                $messages[] = 'تمت إعادة ترقيم ' . $renumbered . ' حساباً بنمط هرمي متسلسل (1001، 1001001، …) دون تغيير القيود.';
            }
        } catch (Throwable $e) {
            $messages[] = 'تعذر إعادة ترقيم الحسابات: ' . $e->getMessage();
        }
    }

    acc_coa_purge_stale_short_code_clones($pdo);

    try {
        acc_coa_meta_set($pdo, 'coa_bootstrap_version', (string) ACC_COA_BOOTSTRAP_VERSION);
    } catch (Throwable $e) {
        $messages[] = 'تعذر حفظ إصدار الضبط.';
    }
    $changed = $mapped > 0 || $needsRemap;
    if ($mapped > 0) {
        $messages[] = 'تم ربط ' . $mapped . ' عملية بحسابات الشجرة المقترحة.';
    }
    if ($needsRemap && $mapped === 0 && empty($messages)) {
        $messages[] = 'الشجرة والربط محدَّثان مسبقاً — يمكنك تعديل أي ربط من الجدول أدناه.';
    }

    return ['changed' => $changed, 'messages' => $messages, 'mapped' => $mapped];
}

/** هل الاسم يطابق مجموعة الصندوق/الصناديق (وليس حساباً تشغيلياً باسم «صندوق رئيسي» فقط). */
function acc_coa_name_is_cash_group_header(string $nameAr, bool $isLeaf): bool
{
    $n = acc_coa_normalize_name($nameAr);
    if ($n === acc_coa_normalize_name('الصناديق')) {
        return true;
    }
    if (!$isLeaf && ($n === acc_coa_normalize_name('الصندوق') || $n === acc_coa_normalize_name('صندوق'))) {
        return true;
    }
    if (!$isLeaf && preg_match('/^صناديق/u', trim($nameAr))) {
        return true;
    }

    return false;
}

/** هل الاسم يطابق مجموعة البنوك (حساب تجميعي). */
function acc_coa_name_is_banks_group_header(string $nameAr, bool $isLeaf): bool
{
    if ($isLeaf) {
        return false;
    }
    $n = acc_coa_normalize_name($nameAr);

    return $n === acc_coa_normalize_name('البنوك')
        || $n === acc_coa_normalize_name('البنك')
        || $n === acc_coa_normalize_name('مصارف')
        || $n === acc_coa_normalize_name('المصارف')
        || preg_match('/^بنوك/u', trim($nameAr))
        || preg_match('/^مصارف/u', trim($nameAr));
}

/**
 * جذور مجموعة الصندوق/الصناديق في الشجرة (حسابات أب غير نهائية).
 *
 * @return list<int>
 */
function acc_coa_find_cash_group_parent_ids(PDO $pdo): array
{
    if (!acc_journal_has_tables($pdo)) {
        return [];
    }

    $ids = [];
    $index = acc_coa_index_accounts($pdo);
    foreach (['1001002', '1001001000'] as $digits) {
        $row = acc_coa_find_digits($index, $digits);
        if ($row && (int) ($row['is_leaf'] ?? 0) === 0) {
            $ids[(int) $row['id']] = true;
        }
    }

    foreach (
        $pdo->query(
            'SELECT id, name_ar, is_leaf FROM acc_account
             WHERE is_active = 1 AND is_leaf = 0
             ORDER BY sort_order ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row
    ) {
        $id = (int) ($row['id'] ?? 0);
        if ($id < 1 || isset($ids[$id])) {
            continue;
        }
        if (acc_coa_name_is_cash_group_header((string) ($row['name_ar'] ?? ''), false)) {
            $ids[$id] = true;
        }
    }

    $liquidityId = acc_coa_find_liquidity_parent_id($pdo);
    if ($liquidityId !== null && $liquidityId > 0) {
        $st = $pdo->prepare(
            'SELECT id, name_ar, is_leaf FROM acc_account
             WHERE is_active = 1 AND parent_id = ? AND is_leaf = 0
             ORDER BY sort_order ASC, id ASC'
        );
        $st->execute([$liquidityId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1 || isset($ids[$id])) {
                continue;
            }
            if (acc_coa_name_is_cash_group_header((string) ($row['name_ar'] ?? ''), false)) {
                $ids[$id] = true;
            }
        }
    }

    return array_map('intval', array_keys($ids));
}

/**
 * جذور مجموعة البنوك في الشجرة (حسابات أب غير نهائية).
 *
 * @return list<int>
 */
function acc_coa_find_banks_group_parent_ids(PDO $pdo): array
{
    if (!acc_journal_has_tables($pdo)) {
        return [];
    }

    $ids = [];
    $index = acc_coa_index_accounts($pdo);
    foreach (['1001003', '1001003000'] as $digits) {
        $row = acc_coa_find_digits($index, $digits);
        if ($row && (int) ($row['is_leaf'] ?? 0) === 0) {
            $ids[(int) $row['id']] = true;
        }
    }

    foreach (
        $pdo->query(
            'SELECT id, name_ar, is_leaf FROM acc_account
             WHERE is_active = 1 AND is_leaf = 0
             ORDER BY sort_order ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row
    ) {
        $id = (int) ($row['id'] ?? 0);
        if ($id < 1 || isset($ids[$id])) {
            continue;
        }
        if (acc_coa_name_is_banks_group_header((string) ($row['name_ar'] ?? ''), false)) {
            $ids[$id] = true;
        }
    }

    $liquidityId = acc_coa_find_liquidity_parent_id($pdo);
    if ($liquidityId !== null && $liquidityId > 0) {
        $st = $pdo->prepare(
            'SELECT id, name_ar, is_leaf FROM acc_account
             WHERE is_active = 1 AND parent_id = ? AND is_leaf = 0
             ORDER BY sort_order ASC, id ASC'
        );
        $st->execute([$liquidityId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1 || isset($ids[$id])) {
                continue;
            }
            if (acc_coa_name_is_banks_group_header((string) ($row['name_ar'] ?? ''), false)) {
                $ids[$id] = true;
            }
        }
    }

    return array_map('intval', array_keys($ids));
}

/** أب «النقدية والبنوك» حتى مع أكواد مخصّصة (11، 00000011، أو أب الصندوق). */
function acc_coa_find_liquidity_parent_id(PDO $pdo): ?int
{
    if (!acc_journal_has_tables($pdo)) {
        return null;
    }

    $index = acc_coa_index_accounts($pdo);
    foreach (['1001001', '1001', '11', '00000011', '000011'] as $digits) {
        $row = acc_coa_find_digits($index, $digits);
        if ($row && (int) ($row['is_leaf'] ?? 0) === 0) {
            return (int) $row['id'];
        }
    }

    $st = $pdo->query(
        "SELECT id FROM acc_account
         WHERE is_active = 1 AND is_leaf = 0
           AND (name_ar LIKE '%نقدية%' OR name_ar LIKE '%بنوك%' OR name_ar LIKE '%نقد%')
         ORDER BY sort_order ASC, id ASC
         LIMIT 1"
    );
    $id = $st ? $st->fetchColumn() : false;
    if ($id !== false && (int) $id > 0) {
        return (int) $id;
    }

    $st = $pdo->query(
        "SELECT p.id FROM acc_account c
         INNER JOIN acc_account p ON p.id = c.parent_id AND p.is_active = 1
         WHERE c.is_active = 1 AND c.is_leaf = 1
           AND (c.name_ar LIKE '%صندوق%' OR c.name_ar LIKE '%نقد%' OR c.code LIKE '%111%')
         ORDER BY c.id ASC
         LIMIT 1"
    );
    $pid = $st ? $st->fetchColumn() : false;

    return $pid !== false && (int) $pid > 0 ? (int) $pid : null;
}

/**
 * يضمن وجود حساب «صندوق الشيكات» لقيود صرف الشيك.
 *
 * @return array{created:bool, account_id:int, message:string}
 */
function acc_coa_ensure_checks_fund_account(PDO $pdo): array
{
    $out = ['created' => false, 'account_id' => 0, 'message' => ''];
    if (!acc_journal_has_tables($pdo)) {
        $out['message'] = 'دليل الحسابات غير مهيأ.';

        return $out;
    }

    try {
        $existingId = acc_coa_find_global_checks_fund_id($pdo);
        if ($existingId > 0) {
            $out['account_id'] = $existingId;

            return $out;
        }

        $parentId = acc_coa_find_liquidity_parent_id($pdo);
        if ($parentId === null) {
            $out['message'] = 'لم يُعثر على مجموعة «النقدية والبنوك». أضف الحساب يدوياً تحتها من شجرة الحسابات.';

            return $out;
        }

        if ((int) (acc_account_get($pdo, $parentId)['is_leaf'] ?? 0) === 1) {
            $pdo->prepare('UPDATE acc_account SET is_leaf = 0 WHERE id = ?')->execute([$parentId]);
        }

        $code = acc_coa_unique_code($pdo, $parentId, '113');
        $pdo->prepare(
            'INSERT INTO acc_account (code, name_ar, parent_id, account_type, is_leaf, is_active, sort_order)
             VALUES (?,?,?,?,1,1,?)'
        )->execute([$code, 'صندوق الشيكات', $parentId, 'asset', 13]);
        $out['account_id'] = (int) $pdo->lastInsertId();
        $out['created'] = $out['account_id'] > 0;
        if ($out['created']) {
            $out['message'] = 'تم إنشاء حساب «صندوق الشيكات» (' . $code . ') تحت النقدية والبنوك.';
        }

        return $out;
    } catch (Throwable $e) {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/055_acc_checks_fund_account.sql');
        $st = $pdo->query(
            "SELECT id FROM acc_account WHERE is_active = 1 AND name_ar LIKE '%صندوق الشيكات%' LIMIT 1"
        );
        $out['account_id'] = $st ? (int) $st->fetchColumn() : 0;
        $out['created'] = $out['account_id'] > 0;
        if (!$out['created']) {
            $out['message'] = 'تعذر إنشاء الحساب تلقائياً. أضفه يدوياً من شجرة الحسابات.';
        }

        return $out;
    }
}

function acc_coa_bootstrap_ensure_posting_rules(PDO $pdo): void
{
    $extra = [
        ['checks_fund', 'صندوق الشيكات', 'يُستخدم لاستلام الشيكات قبل إيداعها بالبنك (سندات قبض الشيكات)', 13],
        ['salaries_expense', 'رواتب وأجور (مصروف — داخلي)', 'لا يُستخدم في ترحيل الرواتب', 82],
        ['salaries_payable', 'رواتب مستحقة', 'دائن عند ترحيل الرواتب — صافي للموظفين (بعد اقتطاع حصة الموظف من الضمان)', 83],
        ['hr_social_insurance_payable', 'ضمان اجتماعي مستحق', 'دائن — حصة الموظف + حصة الشركة (يُسدّد للضمان من الصندوق)', 84],
        ['hr_payroll_deductions', 'خصومات وسلف رواتب', 'دائن عند ترحيل الرواتب — سلف وخصومات أخرى غير الضمان', 87],
        ['hr_employee_advance_receivable', 'ذمة سلف الموظفين', 'مدين عند ترحيل السلفة — مستحق على الموظف. دائن عند اقتطاعها من الراتب', 88],
        ['hr_employee_advance_payable', 'سلف موظفين مستحقة الصرف', 'دائن عند اعتماد السلفة — مدين عند صرف النقد من المحاسبة', 89],
        ['hr_income_tax', 'ضريبة دخل مستحقة', 'دائن عند ترحيل الرواتب — اقتطاع ضريبة الدخل من الموظف', 88],
        ['purchase_returns', 'مردودات المشتريات', 'دائن عند مردود شراء (إن وُجد حساب مخصص)', 56],
    ];
    $st = $pdo->prepare(
        'INSERT IGNORE INTO acc_posting_setting (rule_code, label_ar, hint_ar, sort_order) VALUES (?,?,?,?)'
    );
    foreach ($extra as $row) {
        $st->execute($row);
    }

    $labels = [
        'purchases' => 'المشتريات (بدون مخزون)',
    ];
    $stL = $pdo->prepare('UPDATE acc_posting_setting SET label_ar = ? WHERE rule_code = ?');
    foreach ($labels as $code => $label) {
        $stL->execute([$label, $code]);
    }

    $hints = [
        'ar_customers' => 'فواتير بيع آجلة، سندات قبض، مردودات وإشعارات للعملاء',
        'ap_suppliers' => 'فواتير شراء آجلة، سندات صرف للموردين',
        'cash' => 'مبيعات/مشتريات نقدية وسندات نقدية',
        'bank' => 'سندات بالشيك أو على البنك',
        'checks_fund' => 'سند قبض شيك — يُسجَّل مدين في صندوق الشيكات حتى الإيداع في البنك',
        'sales_revenue' => 'دائن عند ترحيل فاتورة البيع',
        'purchases' => 'مدين عند شراء مباشر بدون مخزون؛ عند ربط المخزون تُسجّل المشتريات في حساب المخزون وليس مصروفاً',
        'inventory' => 'مدين عند شراء بضاعة للمخزون (مُفضّل للتجارة)',
        'misc_expense' => 'سند صرف لطرف «أخرى» أو مصروفات غير مرتبطة بمورد',
    ];
    $stH = $pdo->prepare('UPDATE acc_posting_setting SET hint_ar = ? WHERE rule_code = ?');
    foreach ($hints as $code => $hint) {
        $stH->execute([$hint, $code]);
    }
}
