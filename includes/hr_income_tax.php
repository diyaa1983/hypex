<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');
require_once app_path('includes/hr_salary.php');
require_once app_path('includes/acc_gl.php');

const HR_INCOME_TAX_RULE_CODE = 'hr_income_tax';
const HR_INCOME_TAX_ACCOUNT_CODE = '2007';

function hr_income_tax_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    hr_employee_ensure_schema($pdo);
    try {
        $pdo->query('SELECT id FROM hr_income_tax_config LIMIT 1');
        hr_income_tax_ensure_employee_column($pdo);
        $done = true;
        hr_income_tax_ensure_posting_rule($pdo);

        return;
    } catch (Throwable $e) {
        if (
            strpos($e->getMessage(), "doesn't exist") === false
            && strpos($e->getMessage(), 'no such table') === false
            && strpos($e->getMessage(), 'Base table or view not found') === false
        ) {
            $done = true;

            return;
        }
    }

    try {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/107_hr_income_tax.sql');
    } catch (Throwable $e) {
        hr_income_tax_ensure_employee_column($pdo);
    }
    $done = true;
    hr_income_tax_ensure_posting_rule($pdo);
}

function hr_income_tax_ensure_employee_column(PDO $pdo): void
{
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM hr_employee')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $has = false;
        foreach ($cols as $c) {
            if (strtolower((string) ($c['Field'] ?? '')) === 'subject_to_income_tax') {
                $has = true;
                break;
            }
        }
        if (!$has) {
            $pdo->exec(
                'ALTER TABLE hr_employee ADD COLUMN subject_to_income_tax TINYINT(1) NOT NULL DEFAULT 0
                 AFTER subject_to_social_security'
            );
        }
    } catch (Throwable $e) {
        // ignored
    }
}

function hr_income_tax_account_spec(): array
{
    return [
        'code' => HR_INCOME_TAX_ACCOUNT_CODE,
        'name_ar' => 'ضريبة دخل مستحقة',
        'parent_code' => '2',
        'account_type' => 'liability',
        'sort_order' => 27,
        'role_keywords' => ['ضريبة', 'دخل', 'مستحق'],
    ];
}

function hr_income_tax_normalize_account_code(PDO $pdo, int $accountId): void
{
    if ($accountId < 1) {
        return;
    }
    require_once app_path('includes/acc_account_tree.php');
    require_once app_path('includes/acc_account_reassign.php');
    $acc = acc_account_get($pdo, $accountId);
    if (!$acc || (int) ($acc['is_leaf'] ?? 0) !== 1) {
        return;
    }
    $code = trim((string) ($acc['code'] ?? ''));
    $digits = preg_replace('/\D/', '', $code) ?? '';
    if ($digits === HR_INCOME_TAX_ACCOUNT_CODE) {
        return;
    }
    $legacy = in_array($digits, ['24', '2004'], true) || strlen($digits) <= 2;
    if (!$legacy) {
        return;
    }
    try {
        require_once app_path('includes/acc_coa_bootstrap.php');
        $existing = acc_account_get_by_code($pdo, HR_INCOME_TAX_ACCOUNT_CODE);
        if ($existing && (int) ($existing['id'] ?? 0) > 0 && (int) $existing['id'] !== $accountId) {
            $targetId = (int) $existing['id'];
            if (acc_gl_has_posting_table($pdo)) {
                $st = $pdo->prepare('UPDATE acc_posting_setting SET account_id = ? WHERE rule_code = ?');
                $st->execute([$targetId, HR_INCOME_TAX_RULE_CODE]);
            }
            if (acc_coa_journal_line_count($pdo, $accountId) === 0) {
                $pdo->prepare('UPDATE acc_account SET is_active = 0 WHERE id = ?')->execute([$accountId]);
            }

            return;
        }
        $pdo->prepare('UPDATE acc_account SET code = ? WHERE id = ?')
            ->execute([HR_INCOME_TAX_ACCOUNT_CODE, $accountId]);
    } catch (Throwable $e) {
        // ignored
    }
}

function hr_income_tax_read_linked_account_id(PDO $pdo): int
{
    if (!acc_gl_has_posting_table($pdo)) {
        return 0;
    }
    try {
        $st = $pdo->prepare('SELECT account_id FROM acc_posting_setting WHERE rule_code = ? LIMIT 1');
        $st->execute([HR_INCOME_TAX_RULE_CODE]);
        $id = $st->fetchColumn();

        return $id !== false && $id !== null ? (int) $id : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

function hr_income_tax_ensure_posting_rule_row(PDO $pdo): void
{
    if (!acc_gl_has_posting_table($pdo)) {
        return;
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
            HR_INCOME_TAX_RULE_CODE,
            'ضريبة دخل مستحقة',
            'دائن عند ترحيل الرواتب — اقتطاع ضريبة الدخل من الموظف',
            88,
        ]);
    } catch (Throwable $e) {
        // ignored
    }
}

function hr_income_tax_ensure_gl_account(PDO $pdo): int
{
    static $done = false;
    static $cachedId = 0;
    if ($done) {
        return $cachedId;
    }

    if (!acc_gl_has_posting_table($pdo) || !acc_journal_has_tables($pdo)) {
        $done = true;

        return 0;
    }

    $linked = hr_income_tax_read_linked_account_id($pdo);
    if ($linked > 0) {
        $done = true;
        $cachedId = $linked;

        return $cachedId;
    }

    $accId = 0;
    try {
        require_once app_path('includes/acc_coa_bootstrap.php');
        $index = acc_coa_index_accounts($pdo);
        $accId = acc_coa_resolve_leaf($pdo, $index, hr_income_tax_account_spec());
        if ($accId > 0) {
            hr_income_tax_normalize_account_code($pdo, $accId);
            $st = $pdo->prepare(
                'UPDATE acc_posting_setting SET account_id = ?
                 WHERE rule_code = ? AND (account_id IS NULL OR account_id = 0)'
            );
            $st->execute([$accId, HR_INCOME_TAX_RULE_CODE]);
        }
    } catch (Throwable $e) {
        try {
            $st = $pdo->query(
                "SELECT id FROM acc_account
                 WHERE is_active = 1 AND is_leaf = 1
                   AND (code = '2007' OR name_ar LIKE '%ضريبة%دخل%')
                 ORDER BY (code = '2007') DESC, id ASC
                 LIMIT 1"
            );
            $accId = $st ? (int) $st->fetchColumn() : 0;
            if ($accId > 0) {
                hr_income_tax_normalize_account_code($pdo, $accId);
                $upd = $pdo->prepare(
                    'UPDATE acc_posting_setting SET account_id = ?
                     WHERE rule_code = ? AND (account_id IS NULL OR account_id = 0)'
                );
                $upd->execute([$accId, HR_INCOME_TAX_RULE_CODE]);
            }
        } catch (Throwable $e2) {
            $accId = 0;
        }
    }

    $cachedId = hr_income_tax_read_linked_account_id($pdo) ?: $accId;
    $done = true;

    return $cachedId;
}

function hr_income_tax_ensure_posting_rule(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    hr_income_tax_ensure_posting_rule_row($pdo);
    hr_income_tax_ensure_gl_account($pdo);
    $done = true;
}

/** @return array<string, float> */
function hr_income_tax_default_config(): array
{
    return [
        'single_exempt_monthly' => 750.0,
        'single_exempt_annual' => 9000.0,
        'married_exempt_monthly' => 1500.0,
        'married_exempt_annual' => 18000.0,
    ];
}

/** @return array<string, float> */
function hr_income_tax_load_config(PDO $pdo): array
{
    hr_income_tax_ensure_schema($pdo);
    $defaults = hr_income_tax_default_config();
    try {
        $row = $pdo->query('SELECT * FROM hr_income_tax_config WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $defaults;
        }

        return [
            'single_exempt_monthly' => (float) ($row['single_exempt_monthly'] ?? $defaults['single_exempt_monthly']),
            'single_exempt_annual' => (float) ($row['single_exempt_annual'] ?? $defaults['single_exempt_annual']),
            'married_exempt_monthly' => (float) ($row['married_exempt_monthly'] ?? $defaults['married_exempt_monthly']),
            'married_exempt_annual' => (float) ($row['married_exempt_annual'] ?? $defaults['married_exempt_annual']),
        ];
    } catch (Throwable $e) {
        return $defaults;
    }
}

/** @param array<string, float|string> $data */
function hr_income_tax_save_config(PDO $pdo, array $data): void
{
    hr_income_tax_ensure_schema($pdo);
    $st = $pdo->prepare(
        'INSERT INTO hr_income_tax_config (id, single_exempt_monthly, single_exempt_annual, married_exempt_monthly, married_exempt_annual)
         VALUES (1, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           single_exempt_monthly = VALUES(single_exempt_monthly),
           single_exempt_annual = VALUES(single_exempt_annual),
           married_exempt_monthly = VALUES(married_exempt_monthly),
           married_exempt_annual = VALUES(married_exempt_annual)'
    );
    $st->execute([
        max(0, (float) ($data['single_exempt_monthly'] ?? 750)),
        max(0, (float) ($data['single_exempt_annual'] ?? 9000)),
        max(0, (float) ($data['married_exempt_monthly'] ?? 1500)),
        max(0, (float) ($data['married_exempt_annual'] ?? 18000)),
    ]);
}

/** @return list<array{id:int, marital_status:string, salary_from:float, salary_to:float|null, tax_percent:float, sort_order:int}> */
function hr_income_tax_brackets(PDO $pdo, string $maritalStatus): array
{
    hr_income_tax_ensure_schema($pdo);
    if (!in_array($maritalStatus, ['single', 'married'], true)) {
        return [];
    }
    try {
        $st = $pdo->prepare(
            'SELECT id, marital_status, salary_from, salary_to, tax_percent, sort_order
             FROM hr_income_tax_bracket
             WHERE marital_status = ?
             ORDER BY sort_order ASC, salary_from ASC, id ASC'
        );
        $st->execute([$maritalStatus]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $to = $r['salary_to'];
            $out[] = [
                'id' => (int) ($r['id'] ?? 0),
                'marital_status' => (string) ($r['marital_status'] ?? ''),
                'salary_from' => (float) ($r['salary_from'] ?? 0),
                'salary_to' => $to === null || $to === '' ? null : (float) $to,
                'tax_percent' => (float) ($r['tax_percent'] ?? 0),
                'sort_order' => (int) ($r['sort_order'] ?? 0),
            ];
        }

        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @return array{marital_status:string, salary_from:float, salary_to:float|null, tax_percent:float}
 */
function hr_income_tax_bracket_parse_row(array $row, int $id = 0): array
{
    $status = (string) ($row['marital_status'] ?? 'single');
    if (!in_array($status, ['single', 'married'], true)) {
        throw new RuntimeException('اختر الحالة الاجتماعية (أعزب أو متزوج).');
    }
    $from = round(max(0, (float) ($row['salary_from'] ?? 0)), 3);
    $toRaw = $row['salary_to'] ?? null;
    $to = ($toRaw === null || $toRaw === '') ? null : round(max(0, (float) $toRaw), 3);
    $pct = (float) ($row['tax_percent'] ?? 0);
    if ($pct < 0 || $pct > 100) {
        throw new RuntimeException('نسبة الضريبة يجب أن تكون بين 0 و 100.');
    }
    if ($to !== null && $to > 0.0005 && $to < $from) {
        throw new RuntimeException('«إلى» يجب أن يكون أكبر من «من» في شرائح الضريبة.');
    }
    if ($from <= 0.0005 && ($to === null || $to <= 0.0005) && abs($pct) < 0.0005) {
        throw new RuntimeException('أدخل بيانات الشريحة (من — إلى — النسبة).');
    }

    return [
        'marital_status' => $status,
        'salary_from' => $from,
        'salary_to' => $to,
        'tax_percent' => round($pct, 3),
    ];
}

function hr_income_tax_bracket_next_sort(PDO $pdo, string $maritalStatus): int
{
    $st = $pdo->prepare(
        'SELECT COALESCE(MAX(sort_order), 0) FROM hr_income_tax_bracket WHERE marital_status = ?'
    );
    $st->execute([$maritalStatus]);

    return (int) $st->fetchColumn() + 10;
}

function hr_income_tax_bracket_exists(PDO $pdo, int $id): bool
{
    if ($id < 1) {
        return false;
    }
    $st = $pdo->prepare('SELECT id FROM hr_income_tax_bracket WHERE id = ? LIMIT 1');
    $st->execute([$id]);

    return (bool) $st->fetchColumn();
}

function hr_income_tax_save_bracket_one(PDO $pdo, int $id, array $parsed): void
{
    hr_income_tax_ensure_schema($pdo);
    if ($id > 0) {
        if (!hr_income_tax_bracket_exists($pdo, $id)) {
            throw new RuntimeException('الشريحة غير موجودة.');
        }
        $st = $pdo->prepare(
            'UPDATE hr_income_tax_bracket
             SET marital_status = ?, salary_from = ?, salary_to = ?, tax_percent = ?
             WHERE id = ?'
        );
        $st->execute([
            $parsed['marital_status'],
            $parsed['salary_from'],
            $parsed['salary_to'],
            $parsed['tax_percent'],
            $id,
        ]);

        return;
    }
    $order = hr_income_tax_bracket_next_sort($pdo, $parsed['marital_status']);
    $st = $pdo->prepare(
        'INSERT INTO hr_income_tax_bracket (marital_status, salary_from, salary_to, tax_percent, sort_order)
         VALUES (?, ?, ?, ?, ?)'
    );
    $st->execute([
        $parsed['marital_status'],
        $parsed['salary_from'],
        $parsed['salary_to'],
        $parsed['tax_percent'],
        $order,
    ]);
}

function hr_income_tax_delete_bracket(PDO $pdo, int $id): bool
{
    hr_income_tax_ensure_schema($pdo);
    if ($id < 1) {
        throw new RuntimeException('حدد شريحة للحذف.');
    }
    if (!hr_income_tax_bracket_exists($pdo, $id)) {
        return false;
    }
    $st = $pdo->prepare('DELETE FROM hr_income_tax_bracket WHERE id = ?');
    $st->execute([$id]);

    return true;
}

/** @param list<array{salary_from?:float|string, salary_to?:float|string|null, tax_percent?:float|string}> $items */
function hr_income_tax_save_brackets_batch(PDO $pdo, string $maritalStatus, array $items): int
{
    hr_income_tax_ensure_schema($pdo);
    if (!in_array($maritalStatus, ['single', 'married'], true)) {
        throw new RuntimeException('نوع الحالة الاجتماعية غير صالح.');
    }
    $saved = 0;
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $parsed = hr_income_tax_bracket_parse_row(array_merge($item, ['marital_status' => $maritalStatus]), 0);
        hr_income_tax_save_bracket_one($pdo, 0, $parsed);
        $saved++;
    }
    if ($saved < 1) {
        throw new RuntimeException('أضف شريحة واحدة على الأقل ثم احفظ من شريط الأدوات.');
    }

    return $saved;
}

function hr_income_tax_format_amount(float $amount): string
{
    if (abs($amount - round($amount)) < 0.0005) {
        return number_format($amount, 0, '.', ',');
    }

    return number_format($amount, 3, '.', ',');
}

function hr_income_tax_marital_label(string $status): string
{
    return $status === 'married' ? 'متزوج' : 'أعزب';
}

/** @param list<array{salary_from?:float|string, salary_to?:float|string|null, tax_percent?:float|string, sort_order?:int|string}> $rows */
function hr_income_tax_save_brackets(PDO $pdo, string $maritalStatus, array $rows): void
{
    hr_income_tax_ensure_schema($pdo);
    if (!in_array($maritalStatus, ['single', 'married'], true)) {
        throw new RuntimeException('نوع الحالة الاجتماعية غير صالح.');
    }
    $pdo->prepare('DELETE FROM hr_income_tax_bracket WHERE marital_status = ?')->execute([$maritalStatus]);
    $st = $pdo->prepare(
        'INSERT INTO hr_income_tax_bracket (marital_status, salary_from, salary_to, tax_percent, sort_order)
         VALUES (?, ?, ?, ?, ?)'
    );
    $order = 0;
    foreach ($rows as $row) {
        $from = round(max(0, (float) ($row['salary_from'] ?? 0)), 3);
        $toRaw = $row['salary_to'] ?? null;
        $to = ($toRaw === null || $toRaw === '') ? null : round(max(0, (float) $toRaw), 3);
        $pct = (float) ($row['tax_percent'] ?? 0);
        if ($pct < 0 || $pct > 100) {
            throw new RuntimeException('نسبة الضريبة يجب أن تكون بين 0 و 100.');
        }
        if ($to !== null && $to > 0.0005 && $to < $from) {
            throw new RuntimeException('«إلى» يجب أن يكون أكبر من «من» في شرائح الضريبة.');
        }
        if ($from <= 0.0005 && ($to === null || $to <= 0.0005) && abs($pct) < 0.0005) {
            continue;
        }
        $order += 10;
        $st->execute([$maritalStatus, $from, $to, round($pct, 3), $order]);
    }
}

function hr_employee_subject_to_income_tax(PDO $pdo, int $employeeId): bool
{
    if ($employeeId < 1) {
        return false;
    }
    hr_income_tax_ensure_employee_column($pdo);
    try {
        $st = $pdo->prepare('SELECT subject_to_income_tax FROM hr_employee WHERE id = ? LIMIT 1');
        $st->execute([$employeeId]);

        return (int) ($st->fetchColumn() ?: 0) === 1;
    } catch (Throwable $e) {
        return false;
    }
}

function hr_employee_is_married(PDO $pdo, int $employeeId): bool
{
    if ($employeeId < 1) {
        return false;
    }
    hr_employee_ensure_link_columns($pdo);
    try {
        $st = $pdo->prepare('SELECT is_married FROM hr_employee WHERE id = ? LIMIT 1');
        $st->execute([$employeeId]);

        return (int) ($st->fetchColumn() ?: 0) === 1;
    } catch (Throwable $e) {
        return false;
    }
}

/** الراتب الخاضع للضريبة = بعد الاقتطاعات وقبل ضريبة الدخل. */
function hr_income_tax_taxable_base(
    float $base,
    float $allowances,
    float $deductions,
    float $overtime = 0,
    float $bonus = 0,
    float $ssEmp = 0
): float {
    $gross = round($base + $allowances + $overtime + $bonus, 3);

    return round(max(0, $gross - $deductions - $ssEmp), 3);
}

function hr_income_tax_ytd_taxable_base(
    PDO $pdo,
    int $employeeId,
    int $year,
    int $month,
    int $excludeSalaryId = 0
): float {
    if ($employeeId < 1 || $year < 2000 || $month < 1 || $month > 12) {
        return 0.0;
    }
    try {
        $sql = 'SELECT COALESCE(SUM(base_salary + allowances + overtime + bonus - deductions - social_security_emp), 0)
                FROM hr_salary
                WHERE employee_id = ? AND pay_year = ? AND pay_month < ?';
        $params = [$employeeId, $year, $month];
        if ($excludeSalaryId > 0) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeSalaryId;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);

        return round(max(0, (float) $st->fetchColumn()), 3);
    } catch (Throwable $e) {
        return 0.0;
    }
}

/**
 * @param list<array{salary_from:float, salary_to:float|null, tax_percent:float}> $brackets
 */
function hr_income_tax_calc_bracket_amount(float $taxable, array $brackets): float
{
    if ($taxable <= 0.0005 || !$brackets) {
        return 0.0;
    }
    usort($brackets, static function (array $a, array $b): int {
        return ((float) ($a['salary_from'] ?? 0)) <=> ((float) ($b['salary_from'] ?? 0));
    });
    $tax = 0.0;
    foreach ($brackets as $b) {
        $from = (float) ($b['salary_from'] ?? 0);
        $to = $b['salary_to'] ?? null;
        if ($taxable <= $from + 0.0005) {
            continue;
        }
        $cap = ($to === null || (float) $to <= 0.0005) ? $taxable : min($taxable, (float) $to);
        $slice = $cap - $from;
        if ($slice > 0.0005) {
            $tax += $slice * ((float) ($b['tax_percent'] ?? 0) / 100);
        }
    }

    return round(max(0, $tax), 3);
}

function hr_income_tax_is_exempt(
    array $config,
    bool $married,
    float $monthlyTaxable,
    float $ytdIncludingCurrent
): bool {
    if ($monthlyTaxable <= 0.0005) {
        return true;
    }
    $monthlyCap = $married
        ? (float) ($config['married_exempt_monthly'] ?? 1500)
        : (float) ($config['single_exempt_monthly'] ?? 750);
    $annualCap = $married
        ? (float) ($config['married_exempt_annual'] ?? 18000)
        : (float) ($config['single_exempt_annual'] ?? 9000);

    if ($monthlyTaxable > $monthlyCap + 0.0005) {
        return false;
    }
    if ($ytdIncludingCurrent > $annualCap + 0.0005) {
        return false;
    }

    return true;
}

/**
 * @return array{taxable_base:float, income_tax:float, exempt:bool, married:bool}
 */
function hr_income_tax_calc_for_employee(
    PDO $pdo,
    int $employeeId,
    int $year,
    int $month,
    float $base,
    float $allowances,
    float $deductions,
    float $overtime = 0,
    float $bonus = 0,
    float $ssEmp = 0,
    int $excludeSalaryId = 0
): array {
    $empty = ['taxable_base' => 0.0, 'income_tax' => 0.0, 'exempt' => true, 'married' => false];
    if ($employeeId < 1 || !hr_employee_subject_to_income_tax($pdo, $employeeId)) {
        return $empty;
    }
    $taxable = hr_income_tax_taxable_base($base, $allowances, $deductions, $overtime, $bonus, $ssEmp);
    $married = hr_employee_is_married($pdo, $employeeId);
    $config = hr_income_tax_load_config($pdo);
    $ytdBefore = hr_income_tax_ytd_taxable_base($pdo, $employeeId, $year, $month, $excludeSalaryId);
    $ytdTotal = round($ytdBefore + $taxable, 3);
    $exempt = hr_income_tax_is_exempt($config, $married, $taxable, $ytdTotal);
    if ($exempt) {
        return [
            'taxable_base' => $taxable,
            'income_tax' => 0.0,
            'exempt' => true,
            'married' => $married,
        ];
    }
    $status = $married ? 'married' : 'single';
    $brackets = hr_income_tax_brackets($pdo, $status);
    $tax = hr_income_tax_calc_bracket_amount($taxable, $brackets);

    return [
        'taxable_base' => $taxable,
        'income_tax' => $tax,
        'exempt' => false,
        'married' => $married,
    ];
}

function hr_income_tax_clear_employee_unposted_payroll(PDO $pdo, int $employeeId): void
{
    if ($employeeId < 1) {
        return;
    }
    try {
        $st = $pdo->prepare(
            'SELECT id, base_salary, allowances, deductions, overtime, bonus, social_security_emp
             FROM hr_salary WHERE employee_id = ? AND is_posted = 0'
        );
        $st->execute([$employeeId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $upd = $pdo->prepare(
            'UPDATE hr_salary SET income_tax = 0, net_salary = ? WHERE id = ?'
        );
        foreach ($rows as $row) {
            $net = hr_salary_calc_net(
                (float) ($row['base_salary'] ?? 0),
                (float) ($row['allowances'] ?? 0),
                (float) ($row['deductions'] ?? 0),
                (float) ($row['overtime'] ?? 0),
                (float) ($row['bonus'] ?? 0),
                (float) ($row['social_security_emp'] ?? 0),
                0
            );
            $upd->execute([$net, (int) ($row['id'] ?? 0)]);
        }
    } catch (Throwable $e) {
        // ignored
    }
}

function hr_income_tax_account_id(PDO $pdo): int
{
    hr_income_tax_ensure_posting_rule_row($pdo);
    $id = hr_income_tax_read_linked_account_id($pdo);
    if ($id > 0) {
        return $id;
    }

    return hr_income_tax_ensure_gl_account($pdo);
}
