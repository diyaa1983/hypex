<?php
declare(strict_types=1);

require_once app_path('includes/hr_social_security_rate.php');
require_once app_path('includes/hr_employee_salary.php');
require_once app_path('includes/acc_gl.php');

/** رموز ربط حسابات الضمان — يظهر في الربط: hr_social_insurance_payable فقط. */
const HR_SS_EMPLOYEE_RULE_CODE = 'hr_social_insurance_employee';
const HR_SS_EMPLOYER_RULE_CODE = 'hr_social_insurance_employer';
const HR_SS_PAYABLE_RULE_CODE = 'hr_social_insurance_payable';

function hr_ss_ensure_posting_rule(PDO $pdo): void
{
    if (!acc_gl_has_posting_table($pdo)) {
        return;
    }
    try {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/104_hr_ss_posting_employee_employer.sql');
        sql_migration_run_file($pdo, 'database/migrations/113_hr_ss_posting_single_account.sql');
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
            HR_SS_PAYABLE_RULE_CODE,
            'ضمان اجتماعي مستحق',
            'دائن عند ترحيل الرواتب — مجموع حصة الموظف + حصة الشركة للتسديد للضمان الاجتماعي',
            84,
        ]);
    } catch (Throwable $e) {
        // ignored
    }
}

/**
 * @return array<string, mixed>|null
 */
function hr_ss_active_rate(PDO $pdo): ?array
{
    hr_social_security_rate_ensure_schema($pdo);
    try {
        $row = $pdo->query(
            'SELECT id, rate_code, employee_percent, employer_percent, description
             FROM hr_social_security_rate
             WHERE is_active = 1
             ORDER BY CAST(rate_code AS UNSIGNED) ASC, id ASC
             LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** هل الموظف مفعّل له «خاضع للضمان الاجتماعي» في بيانات الموظف؟ */
function hr_employee_subject_to_social_security(PDO $pdo, int $employeeId): bool
{
    if ($employeeId < 1) {
        return false;
    }
    hr_employee_ensure_link_columns($pdo);
    try {
        $st = $pdo->prepare(
            'SELECT subject_to_social_security FROM hr_employee WHERE id = ? LIMIT 1'
        );
        $st->execute([$employeeId]);
        return (int) ($st->fetchColumn() ?: 0) === 1;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * إزالة اقتطاع الضمان من قيود الرواتب غير المرحّلة عند إلغاء «خاضع للضمان».
 */
function hr_ss_clear_employee_unposted_payroll(PDO $pdo, int $employeeId): void
{
    if ($employeeId < 1) {
        return;
    }
    require_once app_path('includes/hr_salary.php');

    try {
        $st = $pdo->prepare(
            'SELECT id, base_salary, allowances, deductions, overtime, bonus, income_tax
             FROM hr_salary WHERE employee_id = ? AND is_posted = 0'
        );
        $st->execute([$employeeId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $upd = $pdo->prepare(
            'UPDATE hr_salary SET social_security_emp = 0, net_salary = ? WHERE id = ?'
        );
        foreach ($rows as $row) {
            $net = hr_salary_calc_net(
                (float) ($row['base_salary'] ?? 0),
                (float) ($row['allowances'] ?? 0),
                (float) ($row['deductions'] ?? 0),
                (float) ($row['overtime'] ?? 0),
                (float) ($row['bonus'] ?? 0),
                0,
                (float) ($row['income_tax'] ?? 0)
            );
            $upd->execute([$net, (int) ($row['id'] ?? 0)]);
        }

        $pdo->prepare(
            'DELETE FROM hr_social_security WHERE employee_id = ? AND is_posted = 0'
        )->execute([$employeeId]);
    } catch (Throwable $e) {
        // ignored
    }
}

/** الراتب الخاضع للضمان من شاشة رواتب الموظف (أساسي + علاوات). */
function hr_employee_payroll_gross(PDO $pdo, int $employeeId): float
{
    if ($employeeId < 1) {
        return 0.0;
    }
    hr_employee_ensure_schema($pdo);
    hr_employee_salary_line_ensure_schema($pdo);

    $st = $pdo->prepare('SELECT base_salary FROM hr_employee WHERE id = ? LIMIT 1');
    $st->execute([$employeeId]);
    $base = (float) ($st->fetchColumn() ?: 0);
    $lines = hr_employee_salary_allowance_lines_only(hr_employee_salary_lines_load($pdo, $employeeId));
    $totals = hr_employee_salary_totals($base, $lines);

    return (float) ($totals['gross'] ?? 0);
}

/**
 * @return array{
 *   gross:float,
 *   employee_percent:float,
 *   employer_percent:float,
 *   employee_deduct:float,
 *   employer_amount:float,
 *   rate_id:int
 * }|null
 */
function hr_ss_calc_for_employee(PDO $pdo, int $employeeId, ?float $grossOverride = null): ?array
{
    if ($employeeId < 1 || !hr_employee_subject_to_social_security($pdo, $employeeId)) {
        return null;
    }

    $rate = hr_ss_active_rate($pdo);
    if (!$rate) {
        return null;
    }

    $gross = $grossOverride !== null ? (float) $grossOverride : hr_employee_payroll_gross($pdo, $employeeId);
    if ($gross < 0) {
        $gross = 0.0;
    }

    $empPct = (float) ($rate['employee_percent'] ?? 0);
    $erPct = (float) ($rate['employer_percent'] ?? 0);
    $empDeduct = round($gross * $empPct / 100, 3);
    $erAmount = round($gross * $erPct / 100, 3);

    return [
        'gross' => $gross,
        'employee_percent' => $empPct,
        'employer_percent' => $erPct,
        'employee_deduct' => $empDeduct,
        'employer_amount' => $erAmount,
        'rate_id' => (int) ($rate['id'] ?? 0),
    ];
}

function hr_ss_payable_account_id(PDO $pdo): ?int
{
    hr_ss_ensure_posting_rule($pdo);
    if (!acc_gl_has_posting_table($pdo)) {
        return null;
    }
    try {
        $st = $pdo->prepare(
            'SELECT account_id FROM acc_posting_setting WHERE rule_code = ? LIMIT 1'
        );
        $st->execute([HR_SS_PAYABLE_RULE_CODE]);
        $id = (int) ($st->fetchColumn() ?: 0);
        return $id > 0 ? $id : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * @return array{ready:bool, message:string, account_id:?int}
 */
function hr_ss_company_posting_ready(PDO $pdo): array
{
    return hr_ss_payroll_posting_ready($pdo, 0.0, 0.0);
}

/**
 * @return array{employee_total:float, employer_total:float, payable_total:float}
 */
function hr_payroll_month_ss_totals(PDO $pdo, int $year, int $month): array
{
    $emp = 0.0;
    $er = 0.0;
    try {
        $st = $pdo->prepare(
            'SELECT COALESCE(SUM(employee_share), 0), COALESCE(SUM(employer_share), 0)
             FROM hr_social_security WHERE pay_year = ? AND pay_month = ?'
        );
        $st->execute([$year, $month]);
        $row = $st->fetch(PDO::FETCH_NUM);
        if ($row) {
            $emp = round((float) ($row[0] ?? 0), 3);
            $er = round((float) ($row[1] ?? 0), 3);
        }
    } catch (Throwable $e) {
        // ignored
    }

    return [
        'employee_total' => $emp,
        'employer_total' => $er,
        'payable_total' => round($emp + $er, 3),
    ];
}

/**
 * @return array{ready:bool, message:string}
 */
function hr_ss_payroll_posting_ready(PDO $pdo, float $employerTotal, float $employeeTotal): array
{
    hr_ss_ensure_posting_rule($pdo);
    $payableTotal = round($employerTotal + $employeeTotal, 3);
    if ($payableTotal <= 0.0005) {
        return ['ready' => true, 'message' => ''];
    }

    if (hr_ss_payable_account_id($pdo) === null) {
        return [
            'ready' => false,
            'message' => 'اربط حساب «ضمان اجتماعي مستحق» من شاشة ربط الحسابات.',
        ];
    }

    return ['ready' => true, 'message' => ''];
}

/**
 * @return list<array{rule:string, debit:float, credit:float, memo?:string}>
 */
function hr_ss_payroll_posting_gl_lines(float $employerTotal, float $employeeTotal): array
{
    $employerTotal = round(max(0, $employerTotal), 3);
    $employeeTotal = round(max(0, $employeeTotal), 3);
    $payableTotal = round($employerTotal + $employeeTotal, 3);
    if ($payableTotal <= 0.0005) {
        return [];
    }

    $memo = 'ضمان اجتماعي مستحق';
    if ($employeeTotal > 0.0005 && $employerTotal > 0.0005) {
        $memo = sprintf(
            'ضمان اجتماعي مستحق — حصة موظف %.3f + حصة شركة %.3f',
            $employeeTotal,
            $employerTotal
        );
    }

    return [
        [
            'rule' => HR_SS_PAYABLE_RULE_CODE,
            'debit' => 0,
            'credit' => $payableTotal,
            'memo' => $memo,
        ],
    ];
}
