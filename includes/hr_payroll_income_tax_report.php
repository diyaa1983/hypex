<?php
declare(strict_types=1);

require_once app_path('includes/hr_payroll_posting.php');
require_once app_path('includes/hr_payroll_ss_report.php');
require_once app_path('includes/hr_income_tax.php');

function hr_payroll_income_tax_report_year_is_posted(PDO $pdo, int $year): bool
{
    if ($year < 2000) {
        return false;
    }
    try {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM hr_salary WHERE pay_year = ? AND is_posted = 1 LIMIT 1'
        );
        $st->execute([$year]);

        return (int) $st->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function hr_payroll_income_tax_report_marital_label(bool $isMarried): string
{
    return $isMarried ? 'متزوج' : 'أعزب';
}

function hr_payroll_income_tax_report_tax_display(array $row): string
{
    if (empty($row['subject_to_tax'])) {
        return '—';
    }

    return number_format((float) ($row['income_tax'] ?? 0), 3);
}

function hr_payroll_income_tax_report_taxable_display(array $row): string
{
    if (empty($row['subject_to_tax'])) {
        return '—';
    }

    return number_format((float) ($row['taxable_base'] ?? 0), 3);
}

/**
 * @return array{
 *   rows: list<array<string,mixed>>,
 *   totals: array{taxable_base:float, income_tax:float},
 *   period_label: string,
 *   emp_count: int
 * }
 */
function hr_payroll_income_tax_report_build_monthly(PDO $pdo, int $year, int $month): array
{
    hr_payroll_validate_period($year, $month);
    hr_employee_ensure_schema($pdo);

    $periodLabel = hr_payroll_period_label($year, $month);
    $empty = [
        'rows' => [],
        'totals' => ['taxable_base' => 0.0, 'income_tax' => 0.0],
        'period_label' => $periodLabel,
        'emp_count' => 0,
    ];

    if (!hr_payroll_ss_report_month_is_posted($pdo, $year, $month)) {
        return $empty;
    }

    try {
        $st = $pdo->prepare(
            'SELECT s.base_salary, s.allowances, s.overtime, s.bonus, s.deductions,
                    s.social_security_emp, s.income_tax,
                    e.emp_code, e.name_ar, e.national_id, e.subject_to_income_tax, e.is_married
             FROM hr_salary s
             INNER JOIN hr_employee e ON e.id = s.employee_id
             WHERE s.pay_year = ? AND s.pay_month = ? AND s.is_posted = 1
             ORDER BY e.emp_code ASC, e.name_ar ASC, e.id ASC'
        );
        $st->execute([$year, $month]);
        $raw = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return $empty;
    }

    $rows = [];
    $totals = ['taxable_base' => 0.0, 'income_tax' => 0.0];
    $seq = 1;
    foreach ($raw as $r) {
        $subjectTax = (int) ($r['subject_to_income_tax'] ?? 0) === 1;
        $taxableBase = $subjectTax
            ? hr_income_tax_taxable_base(
                (float) ($r['base_salary'] ?? 0),
                (float) ($r['allowances'] ?? 0),
                (float) ($r['deductions'] ?? 0),
                (float) ($r['overtime'] ?? 0),
                (float) ($r['bonus'] ?? 0),
                (float) ($r['social_security_emp'] ?? 0)
            )
            : 0.0;
        $incomeTax = $subjectTax ? round((float) ($r['income_tax'] ?? 0), 3) : 0.0;

        $rows[] = [
            'seq' => $seq++,
            'emp_code' => (string) ($r['emp_code'] ?? ''),
            'name_ar' => (string) ($r['name_ar'] ?? ''),
            'national_id' => trim((string) ($r['national_id'] ?? '')),
            'is_married' => (int) ($r['is_married'] ?? 0) === 1,
            'marital_label' => hr_payroll_income_tax_report_marital_label((int) ($r['is_married'] ?? 0) === 1),
            'taxable_base' => $taxableBase,
            'income_tax' => $incomeTax,
            'subject_to_tax' => $subjectTax,
        ];
        $totals['taxable_base'] += $taxableBase;
        $totals['income_tax'] += $incomeTax;
    }

    $totals['taxable_base'] = round($totals['taxable_base'], 3);
    $totals['income_tax'] = round($totals['income_tax'], 3);

    return [
        'rows' => $rows,
        'totals' => $totals,
        'period_label' => $periodLabel,
        'emp_count' => count($rows),
    ];
}

/**
 * @return array{
 *   rows: list<array<string,mixed>>,
 *   monthly_summary: list<array{month:int, label:string, emp_count:int, taxable_base:float, income_tax:float}>,
 *   totals: array{taxable_base:float, income_tax:float},
 *   period_label: string,
 *   emp_count: int
 * }
 */
function hr_payroll_income_tax_report_build_annual(PDO $pdo, int $year): array
{
    hr_employee_ensure_schema($pdo);

    $periodLabel = 'السنة ' . $year;
    $empty = [
        'rows' => [],
        'monthly_summary' => [],
        'totals' => ['taxable_base' => 0.0, 'income_tax' => 0.0],
        'period_label' => $periodLabel,
        'emp_count' => 0,
    ];

    if (!hr_payroll_income_tax_report_year_is_posted($pdo, $year)) {
        return $empty;
    }

    $monthNames = [
        1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل', 5 => 'مايو', 6 => 'يونيو',
        7 => 'يوليو', 8 => 'أغسطس', 9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر',
    ];

    try {
        $st = $pdo->prepare(
            'SELECT s.pay_month, s.base_salary, s.allowances, s.overtime, s.bonus, s.deductions,
                    s.social_security_emp, s.income_tax,
                    e.id AS employee_id, e.emp_code, e.name_ar, e.national_id,
                    e.subject_to_income_tax, e.is_married
             FROM hr_salary s
             INNER JOIN hr_employee e ON e.id = s.employee_id
             WHERE s.pay_year = ? AND s.is_posted = 1
             ORDER BY e.emp_code ASC, e.name_ar ASC, e.id ASC, s.pay_month ASC'
        );
        $st->execute([$year]);
        $raw = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return $empty;
    }

    $byEmployee = [];
    $byMonth = [];
    foreach ($raw as $r) {
        $empId = (int) ($r['employee_id'] ?? 0);
        $month = (int) ($r['pay_month'] ?? 0);
        if ($empId < 1 || $month < 1 || $month > 12) {
            continue;
        }

        $subjectTax = (int) ($r['subject_to_income_tax'] ?? 0) === 1;
        $taxableBase = $subjectTax
            ? hr_income_tax_taxable_base(
                (float) ($r['base_salary'] ?? 0),
                (float) ($r['allowances'] ?? 0),
                (float) ($r['deductions'] ?? 0),
                (float) ($r['overtime'] ?? 0),
                (float) ($r['bonus'] ?? 0),
                (float) ($r['social_security_emp'] ?? 0)
            )
            : 0.0;
        $incomeTax = $subjectTax ? round((float) ($r['income_tax'] ?? 0), 3) : 0.0;

        if (!isset($byEmployee[$empId])) {
            $byEmployee[$empId] = [
                'emp_code' => (string) ($r['emp_code'] ?? ''),
                'name_ar' => (string) ($r['name_ar'] ?? ''),
                'national_id' => trim((string) ($r['national_id'] ?? '')),
                'is_married' => (int) ($r['is_married'] ?? 0) === 1,
                'subject_to_tax' => $subjectTax,
                'taxable_base' => 0.0,
                'income_tax' => 0.0,
                'month_count' => 0,
            ];
        }

        if ($subjectTax) {
            $byEmployee[$empId]['subject_to_tax'] = true;
        }
        $byEmployee[$empId]['taxable_base'] += $taxableBase;
        $byEmployee[$empId]['income_tax'] += $incomeTax;
        $byEmployee[$empId]['month_count']++;

        if (!isset($byMonth[$month])) {
            $byMonth[$month] = [
                'month' => $month,
                'label' => ($monthNames[$month] ?? (string) $month),
                'emp_count' => 0,
                'taxable_base' => 0.0,
                'income_tax' => 0.0,
                'employee_ids' => [],
            ];
        }
        $byMonth[$month]['taxable_base'] += $taxableBase;
        $byMonth[$month]['income_tax'] += $incomeTax;
        $byMonth[$month]['employee_ids'][$empId] = true;
    }

    $rows = [];
    $totals = ['taxable_base' => 0.0, 'income_tax' => 0.0];
    $seq = 1;
    foreach ($byEmployee as $emp) {
        $taxableBase = round((float) $emp['taxable_base'], 3);
        $incomeTax = round((float) $emp['income_tax'], 3);
        $rows[] = [
            'seq' => $seq++,
            'emp_code' => (string) $emp['emp_code'],
            'name_ar' => (string) $emp['name_ar'],
            'national_id' => (string) $emp['national_id'],
            'is_married' => (bool) $emp['is_married'],
            'marital_label' => hr_payroll_income_tax_report_marital_label((bool) $emp['is_married']),
            'taxable_base' => $taxableBase,
            'income_tax' => $incomeTax,
            'month_count' => (int) $emp['month_count'],
            'subject_to_tax' => !empty($emp['subject_to_tax']),
        ];
        $totals['taxable_base'] += $taxableBase;
        $totals['income_tax'] += $incomeTax;
    }

    $monthlySummary = [];
    ksort($byMonth);
    foreach ($byMonth as $m) {
        $monthlySummary[] = [
            'month' => (int) $m['month'],
            'label' => (string) $m['label'],
            'emp_count' => count($m['employee_ids']),
            'taxable_base' => round((float) $m['taxable_base'], 3),
            'income_tax' => round((float) $m['income_tax'], 3),
        ];
    }

    $totals['taxable_base'] = round($totals['taxable_base'], 3);
    $totals['income_tax'] = round($totals['income_tax'], 3);

    return [
        'rows' => $rows,
        'monthly_summary' => $monthlySummary,
        'totals' => $totals,
        'period_label' => $periodLabel,
        'emp_count' => count($rows),
    ];
}
