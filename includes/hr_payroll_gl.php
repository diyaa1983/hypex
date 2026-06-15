<?php
declare(strict_types=1);

require_once app_path('includes/hr_social_security_payroll.php');
require_once app_path('includes/hr_income_tax.php');

const HR_PAYROLL_DEDUCTIONS_RULE_CODE = 'hr_payroll_deductions';
const HR_PAYROLL_ACCRUAL_RULE_CODE = 'hr_payroll_accrual';

function hr_payroll_gl_ensure_posting_rules(PDO $pdo): void
{
    if (!acc_gl_has_posting_table($pdo)) {
        return;
    }
    try {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/105_hr_payroll_deductions_posting.sql');
        sql_migration_run_file($pdo, 'database/migrations/112_acc_hr_payroll_gl_accounts.sql');
        sql_migration_run_file($pdo, 'database/migrations/113_hr_ss_posting_single_account.sql');
        sql_migration_run_file($pdo, 'database/migrations/114_hr_payroll_standard_accounting.sql');
        sql_migration_run_file($pdo, 'database/migrations/115_hr_payroll_liability_posting.sql');
        sql_migration_run_file($pdo, 'database/migrations/116_hr_payroll_mapping_fix.sql');
        sql_migration_run_file($pdo, 'database/migrations/117_hr_payroll_expense_account.sql');
        sql_migration_run_file($pdo, 'database/migrations/118_hr_payroll_expense_cleanup.sql');
        sql_migration_run_file($pdo, 'database/migrations/119_hr_payroll_payable_mapping_fix.sql');
        sql_migration_run_file($pdo, 'database/migrations/120_hr_payroll_liability_group.sql');
    } catch (Throwable $e) {
        // ignored
    }
    try {
        $st = $pdo->prepare(
            'INSERT INTO acc_posting_setting (rule_code, label_ar, hint_ar, sort_order)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               label_ar = VALUES(label_ar),
               hint_ar = VALUES(hint_ar),
               sort_order = VALUES(sort_order)'
        );
        $st->execute([
            HR_PAYROLL_DEDUCTIONS_RULE_CODE,
            'خصومات وسلف رواتب',
            'دائن عند ترحيل الرواتب — سلف وخصومات أخرى غير الضمان',
            87,
        ]);
    } catch (Throwable $e) {
        // ignored
    }
    hr_payroll_gl_apply_default_mapping($pdo);
    hr_payroll_gl_ensure_accrual_account($pdo);
    hr_payroll_gl_ensure_payroll_liability_group($pdo);
    hr_payroll_gl_rebuild_stale_journals($pdo);
}

/**
 * @return array{ok:bool, messages:list<string>, mapping:array<string, array{code:string, name_ar:string, account_type:string}>}
 */
function hr_payroll_gl_apply_default_mapping(PDO $pdo, bool $force = false): array
{
    $out = ['ok' => true, 'messages' => [], 'mapping' => []];
    if (!acc_gl_has_posting_table($pdo)) {
        $out['ok'] = false;

        return $out;
    }

    require_once app_path('includes/acc_account_reassign.php');

    $payableId = hr_payroll_gl_resolve_payable_account($pdo);

    $ssId = hr_payroll_gl_resolve_leaf_account($pdo, '2006', 'liability');
    if ($ssId < 1) {
        $ssId = hr_payroll_gl_resolve_leaf_by_pattern($pdo, 'liability', '%ضمان%مستحق%');
    }

    $taxId = hr_payroll_gl_resolve_leaf_account($pdo, '2007', 'liability');
    if ($taxId < 1) {
        $taxId = hr_payroll_gl_resolve_leaf_by_pattern($pdo, 'liability', '%ضريبة%دخل%');
    }

    $expenseId = hr_payroll_gl_resolve_accrual_expense_account($pdo);

    $settings = acc_gl_load_settings($pdo);
    $curPayable = (int) ($settings['salaries_payable']['account_id'] ?? 0);
    $expenseIdForCheck = (int) ($settings[HR_PAYROLL_ACCRUAL_RULE_CODE]['account_id'] ?? 0);
    if ($expenseIdForCheck < 1) {
        $expenseIdForCheck = (int) ($settings['salaries_expense']['account_id'] ?? 0);
    }
    $needsFix = $force || hr_payroll_gl_mapping_needs_fix($pdo, $curPayable, $payableId, $expenseIdForCheck);

    if ($payableId > 0 && $needsFix) {
        hr_payroll_gl_set_posting_account($pdo, 'salaries_payable', $payableId);
        $acc = acc_account_get($pdo, $payableId);
        $out['messages'][] = 'رواتب مستحقة ← ' . hr_payroll_gl_account_label($acc);
    }

    if ($ssId > 0 && ($force || (int) ($settings[HR_SS_PAYABLE_RULE_CODE]['account_id'] ?? 0) !== $ssId)) {
        hr_payroll_gl_set_posting_account($pdo, HR_SS_PAYABLE_RULE_CODE, $ssId);
        $acc = acc_account_get($pdo, $ssId);
        $out['messages'][] = 'ضمان اجتماعي مستحق ← ' . hr_payroll_gl_account_label($acc);
    }

    if ($taxId > 0 && (int) ($settings[HR_INCOME_TAX_RULE_CODE]['account_id'] ?? 0) < 1) {
        hr_payroll_gl_set_posting_account($pdo, HR_INCOME_TAX_RULE_CODE, $taxId);
    }

    if ($expenseId > 0) {
        $curAccrual = (int) ($settings[HR_PAYROLL_ACCRUAL_RULE_CODE]['account_id'] ?? 0);
        if ($force || !hr_payroll_gl_accrual_account_valid($pdo, $curAccrual)) {
            hr_payroll_gl_set_posting_account($pdo, HR_PAYROLL_ACCRUAL_RULE_CODE, $expenseId);
            hr_payroll_gl_set_posting_account($pdo, 'salaries_expense', $expenseId);
        }
    }

    hr_payroll_gl_consolidate_duplicate_payable($pdo, $payableId, $out);

    $settings = acc_gl_load_settings($pdo);
    foreach (['salaries_payable', HR_SS_PAYABLE_RULE_CODE, HR_INCOME_TAX_RULE_CODE] as $rule) {
        $aid = (int) ($settings[$rule]['account_id'] ?? 0);
        if ($aid > 0) {
            $acc = acc_account_get($pdo, $aid);
            if ($acc) {
                $out['mapping'][$rule] = [
                    'code' => (string) ($acc['code'] ?? ''),
                    'name_ar' => (string) ($acc['name_ar'] ?? ''),
                    'account_type' => (string) ($acc['account_type'] ?? ''),
                ];
            }
        }
    }

    if ($out['messages'] === [] && $needsFix) {
        $out['messages'][] = 'تم التحقق من ربط الرواتب — الإعدادات صحيحة.';
    }

    return $out;
}

function hr_payroll_gl_account_label(?array $acc): string
{
    if (!$acc) {
        return '—';
    }

    return trim((string) ($acc['code'] ?? '') . ' — ' . (string) ($acc['name_ar'] ?? ''));
}

function hr_payroll_gl_resolve_leaf_account(PDO $pdo, string $code, string $type): int
{
    try {
        $st = $pdo->prepare(
            'SELECT id FROM acc_account
             WHERE is_active = 1 AND is_leaf = 1 AND account_type = ? AND code = ?
             LIMIT 1'
        );
        $st->execute([$type, $code]);

        return (int) ($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function hr_payroll_gl_resolve_leaf_by_pattern(PDO $pdo, string $type, string $nameLike): int
{
    try {
        $st = $pdo->prepare(
            'SELECT id FROM acc_account
             WHERE is_active = 1 AND is_leaf = 1 AND account_type = ? AND name_ar LIKE ?
             ORDER BY code ASC LIMIT 1'
        );
        $st->execute([$type, $nameLike]);

        return (int) ($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function hr_payroll_gl_resolve_expense_salary_account(PDO $pdo): int
{
    require_once app_path('includes/acc_coa_bootstrap.php');
    $global = acc_coa_find_global_salaries_expense_id($pdo);
    if ($global > 0) {
        return $global;
    }

    require_once app_path('includes/acc_account_tree.php');
    try {
        $rows = $pdo->query(
            "SELECT id, code, name_ar FROM acc_account
             WHERE is_active = 1 AND is_leaf = 1 AND account_type = 'expense'"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $best = 0;
        $bestScore = -1;
        foreach ($rows as $row) {
            $canon = acc_account_code_canonical_digits((string) ($row['code'] ?? ''));
            $name = (string) ($row['name_ar'] ?? '');
            $score = 0;
            if ($canon === '52') {
                $score = 100;
            } elseif (str_ends_with($canon, '52') && strlen($canon) <= 8) {
                $score = 85;
            } elseif (preg_match('/رواتب/u', $name) && preg_match('/أجور/u', $name)) {
                $score = 75;
            } elseif (preg_match('/رواتب/u', $name) || preg_match('/أجور/u', $name)) {
                $score = 55;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = (int) $row['id'];
            }
        }

        return $best;
    } catch (Throwable $e) {
        return 0;
    }
}

/** @return array{code:string, name_ar:string, parent_code:?string, account_type:string, is_leaf:bool, sort_order:int, role_keywords:list<string>} */
function hr_payroll_gl_salary_payable_spec(): array
{
    return [
        'code' => '23',
        'name_ar' => 'رواتب مستحقة',
        'parent_code' => '2',
        'account_type' => 'liability',
        'is_leaf' => true,
        'sort_order' => 30,
        'role_keywords' => ['راتب', 'مستحق', 'رواتب'],
    ];
}

/** @return array{code:string, name_ar:string, parent_code:?string, account_type:string, is_leaf:bool, sort_order:int} */
function hr_payroll_gl_liability_parent_spec(): array
{
    return [
        'code' => '2',
        'name_ar' => 'الخصوم',
        'parent_code' => null,
        'account_type' => 'liability',
        'is_leaf' => false,
        'sort_order' => 2,
    ];
}

function hr_payroll_gl_resolve_liability_account(PDO $pdo, string $canonicalCode, string $nameLike): int
{
    require_once app_path('includes/acc_account_tree.php');
    try {
        $rows = $pdo->query(
            "SELECT id, code, name_ar FROM acc_account
             WHERE is_active = 1 AND is_leaf = 1 AND account_type = 'liability'"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $best = 0;
        $bestScore = -1;
        foreach ($rows as $row) {
            $canon = acc_account_code_canonical_digits((string) ($row['code'] ?? ''));
            $name = (string) ($row['name_ar'] ?? '');
            $score = 0;
            if ($canon === $canonicalCode) {
                $score = 100;
            } elseif (str_ends_with($canon, $canonicalCode) && strlen($canon) <= 8) {
                $score = 85;
            }
            if (preg_match('/رواتب/u', $name) && preg_match('/مستحق/u', $name)) {
                $score = max($score, 90);
            } elseif (preg_match('/' . preg_quote(trim($nameLike, '%'), '/') . '/u', $name)) {
                $score = max($score, 50);
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = (int) $row['id'];
            }
        }

        return $best;
    } catch (Throwable $e) {
        return 0;
    }
}

/** حساب خصوم «رواتب مستحقة» — يُنشأ تلقائياً إن لم يوجد. */
function hr_payroll_gl_resolve_payable_account(PDO $pdo): int
{
    static $resolving = false;

    $found = hr_payroll_gl_resolve_liability_account($pdo, '23', 'رواتب');
    if ($found > 0) {
        return $found;
    }

    if ($resolving) {
        return 0;
    }
    $resolving = true;

    try {
        require_once app_path('includes/acc_coa_bootstrap.php');
        $index = acc_coa_index_accounts($pdo);
        try {
            acc_coa_ensure_account($pdo, $index, hr_payroll_gl_liability_parent_spec());
        } catch (Throwable $e) {
            // ignored
        }
        $index = acc_coa_index_accounts($pdo);
        $spec = hr_payroll_gl_salary_payable_spec();
        try {
            $id = acc_coa_resolve_leaf($pdo, $index, $spec);
            if ($id > 0) {
                return $id;
            }
        } catch (Throwable $e) {
            // ignored
        }

        try {
            $id = acc_coa_ensure_account($pdo, $index, $spec);
            if ($id > 0) {
                return $id;
            }
        } catch (Throwable $e) {
            // ignored
        }

        return hr_payroll_gl_resolve_liability_account($pdo, '23', 'رواتب');
    } finally {
        $resolving = false;
    }
}

function hr_payroll_gl_payable_account_valid(PDO $pdo, int $accountId): bool
{
    if ($accountId < 1) {
        return false;
    }
    $acc = acc_account_get($pdo, $accountId);
    if (!$acc || (int) ($acc['is_active'] ?? 0) !== 1 || (int) ($acc['is_leaf'] ?? 0) !== 1) {
        return false;
    }
    if ((string) ($acc['account_type'] ?? '') !== 'liability') {
        return false;
    }
    $settings = acc_gl_load_settings($pdo);
    foreach ([HR_PAYROLL_ACCRUAL_RULE_CODE, 'salaries_expense'] as $rule) {
        $expenseId = (int) ($settings[$rule]['account_id'] ?? 0);
        if ($expenseId > 0 && $expenseId === $accountId) {
            return false;
        }
    }

    return true;
}

/** @return array{code:string, name_ar:string, parent_code:?string, account_type:string, is_leaf:bool, sort_order:int, role_keywords:list<string>} */
function hr_payroll_gl_salary_expense_spec(): array
{
    return [
        'code' => '52',
        'name_ar' => 'رواتب وأجور',
        'parent_code' => '5',
        'account_type' => 'expense',
        'is_leaf' => true,
        'sort_order' => 20,
        'role_keywords' => ['راتب', 'أجور', 'رواتب'],
    ];
}

/** @return array{code:string, name_ar:string, parent_code:?string, account_type:string, is_leaf:bool, sort_order:int} */
function hr_payroll_gl_expense_parent_spec(): array
{
    return [
        'code' => '5',
        'name_ar' => 'المصروفات',
        'parent_code' => null,
        'account_type' => 'expense',
        'is_leaf' => false,
        'sort_order' => 5,
    ];
}

/** حساب مصروف رواتب للمدين الداخلي — يُنشأ تلقائياً عبر شجرة الحسابات إن لم يوجد. */
function hr_payroll_gl_resolve_accrual_expense_account(PDO $pdo): int
{
    static $resolving = false;

    $found = hr_payroll_gl_resolve_expense_salary_account($pdo);
    if ($found > 0) {
        return $found;
    }

    if ($resolving) {
        return 0;
    }
    $resolving = true;

    try {
        require_once app_path('includes/acc_coa_bootstrap.php');
        $index = acc_coa_index_accounts($pdo);

        try {
            acc_coa_ensure_account($pdo, $index, hr_payroll_gl_expense_parent_spec());
        } catch (Throwable $e) {
            // ignored
        }
        $index = acc_coa_index_accounts($pdo);
        $spec = hr_payroll_gl_salary_expense_spec();

        try {
            $id = acc_coa_resolve_leaf($pdo, $index, $spec);
            if ($id > 0) {
                return $id;
            }
        } catch (Throwable $e) {
            // ignored
        }

        try {
            $id = acc_coa_ensure_account($pdo, $index, $spec);
            if ($id > 0) {
                return $id;
            }
        } catch (Throwable $e) {
            // ignored
        }

        return hr_payroll_gl_resolve_expense_salary_account($pdo);
    } finally {
        $resolving = false;
    }
}

function hr_payroll_gl_accrual_account_valid(PDO $pdo, int $accountId): bool
{
    if ($accountId < 1) {
        return false;
    }
    $acc = acc_account_get($pdo, $accountId);

    return $acc
        && (int) ($acc['is_active'] ?? 0) === 1
        && (int) ($acc['is_leaf'] ?? 0) === 1
        && (string) ($acc['account_type'] ?? '') === 'expense';
}

function hr_payroll_gl_set_posting_account(PDO $pdo, string $ruleCode, int $accountId): void
{
    if ($accountId < 1) {
        return;
    }
    $chk = $pdo->prepare('SELECT 1 FROM acc_posting_setting WHERE rule_code = ? LIMIT 1');
    $chk->execute([$ruleCode]);
    if ($chk->fetchColumn()) {
        $pdo->prepare('UPDATE acc_posting_setting SET account_id = ? WHERE rule_code = ?')
            ->execute([$accountId, $ruleCode]);

        return;
    }
    $defaults = [
        HR_PAYROLL_ACCRUAL_RULE_CODE => ['استحقاق رواتب (مدين — داخلي)', 'مدين داخلي لموازنة قيد الرواتب', 81],
        'salaries_expense' => ['رواتب وأجور (مصروف — داخلي)', 'مصروف رواتب — داخلي', 82],
        'salaries_payable' => ['رواتب مستحقة', 'دائن — صافي مستحق للموظفين', 83],
        HR_SS_PAYABLE_RULE_CODE => ['ضمان اجتماعي مستحق', 'دائن — حصة الموظف + الشركة', 85],
        HR_INCOME_TAX_RULE_CODE => ['ضريبة دخل مستحقة', 'دائن — اقتطاع ضريبة الدخل', 88],
        HR_PAYROLL_DEDUCTIONS_RULE_CODE => ['خصومات وسلف رواتب', 'دائن — خصومات أخرى', 87],
    ];
    $meta = $defaults[$ruleCode] ?? [$ruleCode, '', 90];
    $pdo->prepare(
        'INSERT INTO acc_posting_setting (rule_code, label_ar, hint_ar, sort_order, account_id)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$ruleCode, $meta[0], $meta[1], $meta[2], $accountId]);
}

function hr_payroll_gl_mapping_needs_fix(PDO $pdo, int $curPayableId, int $targetPayableId, int $expenseAccountId = 0): bool
{
    if ($curPayableId < 1) {
        return $targetPayableId > 0;
    }
    if ($expenseAccountId > 0 && $curPayableId === $expenseAccountId) {
        return true;
    }
    $acc = acc_account_get($pdo, $curPayableId);
    if (!$acc) {
        return true;
    }
    if ((string) ($acc['account_type'] ?? '') !== 'liability') {
        return true;
    }
    if ($targetPayableId < 1) {
        return false;
    }
    if ($curPayableId === $targetPayableId) {
        return false;
    }
    $code = trim((string) ($acc['code'] ?? ''));

    return in_array($code, ['2005', '5038'], true);
}

/** @param array{ok:bool, messages:list<string>, mapping:array<string, mixed>} $out */
function hr_payroll_gl_consolidate_duplicate_payable(PDO $pdo, int $payableId, array &$out): void
{
    if ($payableId < 1) {
        return;
    }
    $dup = acc_account_get_by_code($pdo, '2005');
    if (!$dup || (int) ($dup['is_active'] ?? 0) !== 1) {
        return;
    }
    if ((string) ($dup['account_type'] ?? '') !== 'liability') {
        return;
    }
    $dupId = (int) ($dup['id'] ?? 0);
    if ($dupId < 1 || $dupId === $payableId) {
        return;
    }

    $usage = acc_account_usage_summary($pdo, $dupId);
    if ($usage['journal_lines'] < 1 && ($usage['vouchers'] ?? 0) < 1) {
        $pdo->prepare('UPDATE acc_account SET is_active = 0 WHERE id = ?')->execute([$dupId]);
        $out['messages'][] = 'إيقاف حساب 2005 المكرر — استخدم 23 «رواتب مستحقة» فقط في الميزانية.';

        return;
    }

    $merge = acc_account_reassign_all($pdo, $dupId, $payableId, [
        'deactivate_source' => true,
        'delete_source' => false,
        'force_cash_rule' => false,
    ]);
    if (!empty($merge['ok'])) {
        $out['messages'][] = 'دمج حركات حساب 2005 في «رواتب مستحقة».';
    }
}

/** @return array<string, string> */
function hr_payroll_gl_mapping_summary(PDO $pdo): array
{
    hr_payroll_gl_ensure_posting_rules($pdo);
    $settings = acc_gl_load_settings($pdo);
    $labels = [
        'salaries_payable' => 'رواتب مستحقة (صافي للموظفين)',
        HR_SS_PAYABLE_RULE_CODE => 'ضمان اجتماعي مستحق',
        HR_INCOME_TAX_RULE_CODE => 'ضريبة دخل مستحقة',
    ];
    $summary = [];
    foreach ($labels as $rule => $label) {
        $aid = (int) ($settings[$rule]['account_id'] ?? 0);
        if ($aid < 1) {
            $summary[$label] = 'غير مربوط';
            continue;
        }
        $acc = acc_account_get($pdo, $aid);
        $summary[$label] = $acc
            ? hr_payroll_gl_account_label($acc) . ' (' . ($acc['account_type'] ?? '') . ')'
            : 'غير مربوط';
    }

    return $summary;
}

/** ربط حساب المدين الداخلي لقيد الرواتب. */
function hr_payroll_gl_ensure_accrual_account(PDO $pdo): void
{
    if (!acc_gl_has_posting_table($pdo)) {
        return;
    }
    try {
        $settings = acc_gl_load_settings($pdo);
        $curId = (int) ($settings[HR_PAYROLL_ACCRUAL_RULE_CODE]['account_id'] ?? 0);
        if (hr_payroll_gl_accrual_account_valid($pdo, $curId)) {
            return;
        }
        $expenseId = hr_payroll_gl_resolve_accrual_expense_account($pdo);
        if ($expenseId > 0) {
            hr_payroll_gl_set_posting_account($pdo, HR_PAYROLL_ACCRUAL_RULE_CODE, $expenseId);
            hr_payroll_gl_set_posting_account($pdo, 'salaries_expense', $expenseId);
        }
    } catch (Throwable $e) {
        // ignored
    }
}

/**
 * @param array{gross?:float, net?:float, employee_ss?:float, employer_ss?:float, other_deductions?:float, income_tax?:float} $totals
 */
function hr_payroll_posting_credit_total(array $totals): float
{
    $net = round(max(0, (float) ($totals['net'] ?? 0)), 3);
    $ssEmp = round(max(0, (float) ($totals['employee_ss'] ?? 0)), 3);
    $ssEr = round(max(0, (float) ($totals['employer_ss'] ?? 0)), 3);
    $incomeTax = round(max(0, (float) ($totals['income_tax'] ?? 0)), 3);
    $otherDed = hr_payroll_gl_other_deductions($totals);

    return round($net + $ssEmp + $ssEr + $incomeTax + $otherDed, 3);
}

/**
 * @return array{gross:float, net:float, employee_ss:float, income_tax:float, other_deductions:float}
 */
function hr_payroll_month_salary_totals(PDO $pdo, int $year, int $month, bool $unpostedOnly = true): array
{
    $gross = 0.0;
    $net = 0.0;
    $ssEmp = 0.0;
    $incomeTax = 0.0;
    try {
        $sql = 'SELECT COALESCE(SUM(base_salary + allowances + overtime + bonus), 0),
                       COALESCE(SUM(net_salary), 0),
                       COALESCE(SUM(social_security_emp), 0),
                       COALESCE(SUM(income_tax), 0)
                FROM hr_salary
                WHERE pay_year = ? AND pay_month = ?';
        if ($unpostedOnly) {
            $sql .= ' AND is_posted = 0';
        }
        $st = $pdo->prepare($sql);
        $st->execute([$year, $month]);
        $row = $st->fetch(PDO::FETCH_NUM);
        if ($row) {
            $gross = round((float) ($row[0] ?? 0), 3);
            $net = round((float) ($row[1] ?? 0), 3);
            $ssEmp = round((float) ($row[2] ?? 0), 3);
            $incomeTax = round((float) ($row[3] ?? 0), 3);
        }
    } catch (Throwable $e) {
        // ignored
    }

    $other = round(max(0, $gross - $net - $ssEmp - $incomeTax), 3);

    return [
        'gross' => $gross,
        'net' => $net,
        'employee_ss' => $ssEmp,
        'income_tax' => $incomeTax,
        'other_deductions' => $other,
    ];
}

/**
 * @param array{gross?:float, net?:float, employee_ss?:float, employer_ss?:float, other_deductions?:float} $totals
 */
function hr_payroll_gl_other_deductions(array $totals): float
{
    if (isset($totals['other_deductions'])) {
        return round(max(0, (float) $totals['other_deductions']), 3);
    }
    $gross = round((float) ($totals['gross'] ?? 0), 3);
    $net = round((float) ($totals['net'] ?? 0), 3);
    $ssEmp = round((float) ($totals['employee_ss'] ?? 0), 3);
    $incomeTax = round((float) ($totals['income_tax'] ?? 0), 3);

    return round(max(0, $gross - $net - $ssEmp - $incomeTax), 3);
}

/**
 * @param array{gross?:float, net?:float, employee_ss?:float, employer_ss?:float, other_deductions?:float} $totals
 * @return array{ready:bool, message:string}
 */
function hr_payroll_posting_ready(PDO $pdo, array $totals): array
{
    hr_payroll_gl_ensure_posting_rules($pdo);
    hr_income_tax_ensure_posting_rule($pdo);
    hr_ss_ensure_posting_rule($pdo);

    if (!acc_gl_has_posting_table($pdo)) {
        return ['ready' => true, 'message' => ''];
    }

    $gross = round((float) ($totals['gross'] ?? 0), 3);
    $net = round((float) ($totals['net'] ?? 0), 3);
    $otherDed = hr_payroll_gl_other_deductions($totals);
    $ssEr = round((float) ($totals['employer_ss'] ?? 0), 3);
    $ssEmp = round((float) ($totals['employee_ss'] ?? 0), 3);
    $incomeTax = round((float) ($totals['income_tax'] ?? 0), 3);

    if ($gross <= 0.0005 && $ssEr <= 0.0005 && $ssEmp <= 0.0005) {
        return ['ready' => true, 'message' => ''];
    }

    $settings = acc_gl_load_settings($pdo);
    $creditTotal = hr_payroll_posting_credit_total($totals);

    if ($creditTotal > 0.0005) {
        hr_payroll_gl_ensure_accrual_account($pdo);
        $settings = acc_gl_load_settings($pdo);
        if ((int) ($settings[HR_PAYROLL_ACCRUAL_RULE_CODE]['account_id'] ?? 0) < 1) {
            $expenseId = hr_payroll_gl_resolve_accrual_expense_account($pdo);
            if ($expenseId > 0) {
                hr_payroll_gl_set_posting_account($pdo, HR_PAYROLL_ACCRUAL_RULE_CODE, $expenseId);
                hr_payroll_gl_set_posting_account($pdo, 'salaries_expense', $expenseId);
                $settings = acc_gl_load_settings($pdo);
            }
        }
        if ((int) ($settings[HR_PAYROLL_ACCRUAL_RULE_CODE]['account_id'] ?? 0) < 1) {
            return [
                'ready' => false,
                'message' => 'تعذر إنشاء حساب مصروف «رواتب وأجور» تلقائياً. افتح «شجرة الحسابات» وأضف حساب مصروف نهائي باسم «رواتب وأجور» تحت «المصروفات»، ثم أعد المحاولة.',
            ];
        }
    }

    if ($net > 0.0005 && !hr_payroll_gl_payable_account_valid($pdo, (int) ($settings['salaries_payable']['account_id'] ?? 0))) {
        hr_payroll_gl_apply_default_mapping($pdo, true);
        $settings = acc_gl_load_settings($pdo);
    }
    if ($net > 0.0005 && !hr_payroll_gl_payable_account_valid($pdo, (int) ($settings['salaries_payable']['account_id'] ?? 0))) {
        $label = (string) ($settings['salaries_payable']['label_ar'] ?? 'salaries_payable');
        return [
            'ready' => false,
            'message' => 'حساب «' . $label . '» مربوط بحساب مصروف خطأ (5038). افتح «ربط الحسابات» واضغط «تطبيق ربط الرواتب تلقائياً».',
        ];
    }
    if ($net > 0.0005 && (int) ($settings['salaries_payable']['account_id'] ?? 0) < 1) {
        $label = (string) ($settings['salaries_payable']['label_ar'] ?? 'salaries_payable');
        return [
            'ready' => false,
            'message' => 'اربط حساب «' . $label . '» من شاشة ربط الحسابات.',
        ];
    }
    if ($otherDed > 0.0005 && (int) ($settings[HR_PAYROLL_DEDUCTIONS_RULE_CODE]['account_id'] ?? 0) < 1) {
        $label = (string) ($settings[HR_PAYROLL_DEDUCTIONS_RULE_CODE]['label_ar'] ?? HR_PAYROLL_DEDUCTIONS_RULE_CODE);
        return [
            'ready' => false,
            'message' => 'اربط حساب «' . $label . '» من شاشة ربط الحسابات .',
        ];
    }

    $ssPayable = round($ssEmp + $ssEr, 3);

    if ($ssPayable > 0.0005 && (int) ($settings[HR_SS_PAYABLE_RULE_CODE]['account_id'] ?? 0) < 1) {
        $label = (string) ($settings[HR_SS_PAYABLE_RULE_CODE]['label_ar'] ?? HR_SS_PAYABLE_RULE_CODE);
        return [
            'ready' => false,
            'message' => 'اربط حساب «' . $label . '» من شاشة ربط الحسابات.',
        ];
    }

    if ($incomeTax > 0.0005 && (int) ($settings[HR_INCOME_TAX_RULE_CODE]['account_id'] ?? 0) < 1) {
        $label = (string) ($settings[HR_INCOME_TAX_RULE_CODE]['label_ar'] ?? HR_INCOME_TAX_RULE_CODE);
        return [
            'ready' => false,
            'message' => 'اربط حساب «' . $label . '» من إعدادات ضريبة الدخل أو ربط الحسابات.',
        ];
    }

    return ['ready' => true, 'message' => ''];
}

/**
 * ملخص مبالغ الصرف من صندوق المؤسسة.
 *
 * @return array{
 *   gross:float, net_disbursement:float, employee_ss:float, employer_ss:float,
 *   ss_payable_total:float, other_deductions:float,
 *   fund_salaries:float, fund_ss:float, fund_total:float, has_rows:bool
 * }
 */
function hr_payroll_month_disbursement_totals(PDO $pdo, int $year, int $month): array
{
    $salary = hr_payroll_month_salary_totals($pdo, $year, $month, false);
    $ss = hr_payroll_month_ss_totals($pdo, $year, $month);
    $gross = (float) ($salary['gross'] ?? 0);
    $net = (float) ($salary['net'] ?? 0);
    $empSs = (float) ($salary['employee_ss'] ?? 0);
    $erSs = (float) ($ss['employer_total'] ?? 0);
    $other = (float) ($salary['other_deductions'] ?? 0);
    $ssTotal = round($empSs + $erSs, 3);

    return [
        'gross' => $gross,
        'net_disbursement' => $net,
        'employee_ss' => $empSs,
        'employer_ss' => $erSs,
        'ss_payable_total' => $ssTotal,
        'other_deductions' => $other,
        'fund_salaries' => $net,
        'fund_ss' => $ssTotal,
        'fund_total' => round($net + $ssTotal, 3),
        'has_rows' => $gross > 0.0005 || $ssTotal > 0.0005,
    ];
}

/**
 * قيد ترحيل رواتب شهري — دائن: رواتب مستحقة (صافي) + ضمان مستحق (موظف+شركة).
 *
 * @param array{gross?:float, net?:float, employee_ss?:float, employer_ss?:float, other_deductions?:float} $totals
 * @return list<array{rule:string, debit:float, credit:float, memo?:string}>
 */
function hr_payroll_posting_gl_lines(array $totals): array
{
    $lines = array_merge(
        hr_payroll_posting_gl_accrual_debit_line($totals),
        hr_payroll_posting_gl_salary_lines($totals),
        hr_payroll_posting_gl_ss_lines($totals)
    );

    return array_values(array_filter($lines, static function (array $ln): bool {
        return ((float) ($ln['debit'] ?? 0)) > 0.0005 || ((float) ($ln['credit'] ?? 0)) > 0.0005;
    }));
}

/**
 * مدين موازنة — قاعدة داخلية (لا تظهر في ربط الحسابات).
 *
 * @param array{gross?:float, net?:float, employee_ss?:float, employer_ss?:float, other_deductions?:float, income_tax?:float} $totals
 * @return list<array{rule:string, debit:float, credit:float, memo?:string}>
 */
function hr_payroll_posting_gl_accrual_debit_line(array $totals): array
{
    $debit = hr_payroll_posting_credit_total($totals);
    if ($debit <= 0.0005) {
        return [];
    }

    return [
        [
            'rule' => HR_PAYROLL_ACCRUAL_RULE_CODE,
            'debit' => $debit,
            'credit' => 0,
            'memo' => 'استحقاق رواتب — موازنة قيد الخصوم',
        ],
    ];
}

/**
 * @param array{gross?:float, net?:float, employee_ss?:float, employer_ss?:float, other_deductions?:float} $totals
 * @return list<array{rule:string, debit:float, credit:float, memo?:string}>
 */
function hr_payroll_posting_gl_salary_lines(array $totals): array
{
    $net = round(max(0, (float) ($totals['net'] ?? 0)), 3);
    $otherDed = hr_payroll_gl_other_deductions($totals);
    $incomeTax = round(max(0, (float) ($totals['income_tax'] ?? 0)), 3);

    if ($net <= 0.0005 && $otherDed <= 0.0005 && $incomeTax <= 0.0005) {
        return [];
    }

    $lines = [];

    if ($net > 0.0005) {
        $lines[] = [
            'rule' => 'salaries_payable',
            'debit' => 0,
            'credit' => $net,
            'memo' => 'رواتب مستحقة — صافي للصرف للموظفين (بعد اقتطاع حصة الموظف من الضمان)',
        ];
    }
    if ($otherDed > 0.0005) {
        $lines[] = [
            'rule' => HR_PAYROLL_DEDUCTIONS_RULE_CODE,
            'debit' => 0,
            'credit' => $otherDed,
            'memo' => 'خصومات وسلف أخرى',
        ];
    }
    if ($incomeTax > 0.0005) {
        $lines[] = [
            'rule' => HR_INCOME_TAX_RULE_CODE,
            'debit' => 0,
            'credit' => $incomeTax,
            'memo' => 'ضريبة الدخل المقتطعة',
        ];
    }

    return $lines;
}

function hr_payroll_posting_gl_ss_lines(array $totals): array
{
    $ssEmp = round(max(0, (float) ($totals['employee_ss'] ?? 0)), 3);
    $ssEr = round(max(0, (float) ($totals['employer_ss'] ?? 0)), 3);
    $ssPayable = round($ssEmp + $ssEr, 3);

    if ($ssPayable <= 0.0005) {
        return [];
    }

    $memo = 'ضمان اجتماعي مستحق — حصة موظف + حصة شركة';
    if ($ssEmp > 0.0005 && $ssEr > 0.0005) {
        $memo = sprintf(
            'ضمان اجتماعي مستحق — حصة موظف %.3f + حصة شركة %.3f',
            $ssEmp,
            $ssEr
        );
    } elseif ($ssEmp > 0.0005) {
        $memo = sprintf('ضمان اجتماعي مستحق — حصة موظف %.3f', $ssEmp);
    } elseif ($ssEr > 0.0005) {
        $memo = sprintf('ضمان اجتماعي مستحق — حصة شركة %.3f', $ssEr);
    }

    return [
        [
            'rule' => HR_SS_PAYABLE_RULE_CODE,
            'debit' => 0,
            'credit' => $ssPayable,
            'memo' => $memo,
        ],
    ];
}

function hr_payroll_posting_gl_employer_ss_lines(array $totals): array
{
    return hr_payroll_posting_gl_ss_lines($totals);
}
function hr_payroll_resolve_ss_totals(PDO $pdo, int $year, int $month, array $drafts): array
{
    $ssTotals = hr_payroll_month_ss_totals($pdo, $year, $month);
    $employerTotal = (float) ($ssTotals['employer_total'] ?? 0);
    $employeeTotal = (float) ($ssTotals['employee_total'] ?? 0);
    $payableTotal = (float) ($ssTotals['payable_total'] ?? 0);

    if ($payableTotal <= 0.0005) {
        foreach ($drafts as $row) {
            $empId = (int) ($row['employee_id'] ?? 0);
            if (!hr_employee_subject_to_social_security($pdo, $empId)) {
                continue;
            }
            $employeeTotal += round((float) ($row['social_security_emp'] ?? 0), 3);
            $ss = hr_ss_calc_for_employee($pdo, $empId);
            if ($ss) {
                $employerTotal += (float) ($ss['employer_amount'] ?? 0);
            }
        }
        $employerTotal = round($employerTotal, 3);
        $employeeTotal = round($employeeTotal, 3);
        $payableTotal = round($employerTotal + $employeeTotal, 3);
    }

    return [
        'employer_ss' => $employerTotal,
        'employee_ss' => $employeeTotal,
        'payable_ss' => $payableTotal,
    ];
}

/** @return list<int> */
function hr_payroll_gl_expense_account_ids(PDO $pdo): array
{
    hr_payroll_gl_ensure_accrual_account($pdo);
    $settings = acc_gl_load_settings($pdo);
    $ids = [];
    foreach ([HR_PAYROLL_ACCRUAL_RULE_CODE, 'salaries_expense'] as $rule) {
        $id = (int) ($settings[$rule]['account_id'] ?? 0);
        if ($id > 0) {
            $ids[$id] = true;
        }
    }
    $resolved = hr_payroll_gl_resolve_accrual_expense_account($pdo);
    if ($resolved > 0) {
        $ids[$resolved] = true;
    }

    return array_map('intval', array_keys($ids));
}

function hr_payroll_gl_journal_is_stale(PDO $pdo, int $journalId): bool
{
    $expenseIds = hr_payroll_gl_expense_account_ids($pdo);
    if ($journalId < 1) {
        return false;
    }

    if ($expenseIds !== []) {
        $ph = implode(',', array_fill(0, count($expenseIds), '?'));
        $params = array_merge([$journalId], $expenseIds);
        $st = $pdo->prepare(
            "SELECT COALESCE(SUM(credit), 0) AS sum_credit, COUNT(*) AS line_count
             FROM acc_journal_line
             WHERE journal_id = ? AND account_id IN ($ph)"
        );
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $credit = (float) ($row['sum_credit'] ?? 0);
        $count = (int) ($row['line_count'] ?? 0);
        if ($credit > 0.0005 || $count !== 1) {
            return true;
        }
    }

    $payableId = (int) (acc_gl_load_settings($pdo)['salaries_payable']['account_id'] ?? 0);
    if ($payableId > 0 && !hr_payroll_gl_payable_account_valid($pdo, $payableId)) {
        return true;
    }

    if ($payableId > 0) {
        $st = $pdo->prepare(
            'SELECT 1 FROM acc_journal_line
             WHERE journal_id = ? AND account_id = ? AND credit > 0.0005
             LIMIT 1'
        );
        $st->execute([$journalId, $payableId]);

        return !(bool) $st->fetchColumn();
    }

    return false;
}

/**
 * @param list<array{rule?:string, debit:float, credit:float, memo?:string}> $glLines
 * @return array{debit:float, credit:float, lines:list<array{account_id:int, debit:float, credit:float, memo:string}>}
 */
function hr_payroll_gl_resolve_lines(PDO $pdo, array $glLines): array
{
    $settings = acc_gl_load_settings($pdo);
    $resolved = [];
    foreach ($glLines as $ln) {
        $debit = round(max(0, (float) ($ln['debit'] ?? 0)), 6);
        $credit = round(max(0, (float) ($ln['credit'] ?? 0)), 6);
        if ($debit <= 0 && $credit <= 0) {
            continue;
        }
        $accountId = (int) ($ln['account_id'] ?? 0);
        if ($accountId < 1 && !empty($ln['rule'])) {
            $accountId = acc_gl_account_id($settings, (string) $ln['rule']);
        }
        if ($accountId < 1) {
            continue;
        }
        $resolved[] = [
            'account_id' => $accountId,
            'debit' => $debit,
            'credit' => $credit,
            'memo' => trim((string) ($ln['memo'] ?? '')),
        ];
    }

    return acc_journal_normalize_lines($resolved);
}

function hr_payroll_gl_rebuild_month_journal_if_stale(PDO $pdo, int $year, int $month): bool
{
    if (!acc_journal_has_tables($pdo)) {
        return false;
    }
    $refId = $year * 100 + $month;
    $st = $pdo->prepare(
        "SELECT id FROM acc_journal_entry
         WHERE ref_type = 'hr_payroll_month' AND ref_id = ? AND source = 'auto'
         LIMIT 1"
    );
    $st->execute([$refId]);
    $journalId = (int) ($st->fetchColumn() ?: 0);
    if ($journalId < 1 || !hr_payroll_gl_journal_is_stale($pdo, $journalId)) {
        return false;
    }

    $salaryTotals = hr_payroll_month_salary_totals($pdo, $year, $month, false);
    $ssTotals = hr_payroll_month_ss_totals($pdo, $year, $month);
    $glTotals = array_merge($salaryTotals, [
        'employer_ss' => (float) ($ssTotals['employer_total'] ?? 0),
    ]);
    $glLines = hr_payroll_posting_gl_lines($glTotals);
    if ($glLines === []) {
        return false;
    }

    try {
        hr_payroll_gl_ensure_accrual_account($pdo);
        $normalized = hr_payroll_gl_resolve_lines($pdo, $glLines);
        acc_journal_replace_lines($pdo, $journalId, $normalized['lines']);

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** إصلاح قيود ترحيل قديمة كانت تُسجّل مديناً ودائناً على مصروف الرواتب. */
function hr_payroll_gl_rebuild_stale_journals(PDO $pdo): int
{
    if (!acc_journal_has_tables($pdo)) {
        return 0;
    }
    try {
        $rows = $pdo->query(
            'SELECT DISTINCT pay_year, pay_month
             FROM hr_salary
             WHERE is_posted = 1
             ORDER BY pay_year ASC, pay_month ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return 0;
    }

    $rebuilt = 0;
    foreach ($rows as $row) {
        if (hr_payroll_gl_rebuild_month_journal_if_stale(
            $pdo,
            (int) ($row['pay_year'] ?? 0),
            (int) ($row['pay_month'] ?? 0)
        )) {
            $rebuilt++;
        }
    }

    return $rebuilt;
}

const HR_PAYROLL_LIABILITY_GROUP_CODE = '24';

/** @return array{code:string, name_ar:string, parent_code:string, account_type:string, is_leaf:bool, sort_order:int} */
function hr_payroll_gl_liability_group_spec(): array
{
    return [
        'code' => HR_PAYROLL_LIABILITY_GROUP_CODE,
        'name_ar' => 'مستحقات الموظفين',
        'parent_code' => '2',
        'account_type' => 'liability',
        'is_leaf' => false,
        'sort_order' => 25,
    ];
}

/** @return list<string> */
function hr_payroll_gl_grouped_liability_rule_codes(): array
{
    return [
        'salaries_payable',
        HR_SS_PAYABLE_RULE_CODE,
        HR_INCOME_TAX_RULE_CODE,
        HR_PAYROLL_DEDUCTIONS_RULE_CODE,
    ];
}

function hr_payroll_gl_liability_group_id(PDO $pdo): int
{
    try {
        $st = $pdo->query(
            "SELECT id FROM acc_account
             WHERE is_active = 1 AND is_leaf = 0 AND account_type = 'liability'
               AND (code = '24' OR name_ar LIKE '%مستحقات%موظ%')
             ORDER BY (code = '24') DESC, id ASC
             LIMIT 1"
        );

        return (int) ($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/** @return list<int> */
function hr_payroll_gl_grouped_liability_account_ids(PDO $pdo): array
{
    if (!acc_gl_has_posting_table($pdo)) {
        return [];
    }
    $settings = acc_gl_load_settings($pdo);
    $ids = [];
    foreach (hr_payroll_gl_grouped_liability_rule_codes() as $rule) {
        $id = (int) ($settings[$rule]['account_id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        $acc = acc_account_get($pdo, $id);
        if ($acc && (string) ($acc['account_type'] ?? '') === 'liability') {
            $ids[$id] = true;
        }
    }

    return array_map('intval', array_keys($ids));
}

/** إنشاء مجموعة «مستحقات الموظfين» وربط حسابات الرواتb/الضمان/الضريبة تحتها. */
function hr_payroll_gl_ensure_payroll_liability_group(PDO $pdo): int
{
    if (!acc_journal_has_tables($pdo)) {
        return 0;
    }

    try {
        require_once app_path('includes/acc_coa_bootstrap.php');
        $index = acc_coa_index_accounts($pdo);
        try {
            acc_coa_ensure_account($pdo, $index, hr_payroll_gl_liability_group_spec());
        } catch (Throwable $e) {
            // ignored
        }
    } catch (Throwable $e) {
        // ignored
    }

    $groupId = hr_payroll_gl_liability_group_id($pdo);
    if ($groupId < 1) {
        return 0;
    }

    try {
        $pdo->prepare(
            'UPDATE acc_account SET is_leaf = 0, account_type = ?, is_active = 1 WHERE id = ?'
        )->execute(['liability', $groupId]);
    } catch (Throwable $e) {
        // ignored
    }

    foreach (hr_payroll_gl_grouped_liability_account_ids($pdo) as $accountId) {
        if ($accountId === $groupId) {
            continue;
        }
        try {
            $pdo->prepare(
                'UPDATE acc_account SET parent_id = ?, is_leaf = 1, account_type = ? WHERE id = ?'
            )->execute([$groupId, 'liability', $accountId]);
        } catch (Throwable $e) {
            // ignored
        }
    }

    return $groupId;
}
