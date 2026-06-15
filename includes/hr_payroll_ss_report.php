<?php
declare(strict_types=1);

require_once app_path('includes/hr_payroll_posting.php');

/** @return list<int> */
function hr_payroll_ss_report_posted_years(PDO $pdo): array
{
    try {
        $rows = $pdo->query(
            'SELECT DISTINCT pay_year FROM hr_salary WHERE is_posted = 1 ORDER BY pay_year DESC'
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];

        return array_map('intval', $rows);
    } catch (Throwable $e) {
        return [];
    }
}

/** @return list<array{month:int, label:string}> */
function hr_payroll_ss_report_posted_month_options(PDO $pdo, int $year): array
{
    $posted = hr_payroll_posted_months_for_year($pdo, $year);
    $monthNames = [
        1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل', 5 => 'مايو', 6 => 'يونيو',
        7 => 'يوليو', 8 => 'أغسطس', 9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر',
    ];
    $out = [];
    foreach ($posted as $row) {
        $m = (int) ($row['month'] ?? 0);
        if ($m < 1 || $m > 12) {
            continue;
        }
        $out[] = [
            'month' => $m,
            'label' => $m . ' — ' . ($monthNames[$m] ?? (string) $m),
        ];
    }

    return $out;
}

function hr_payroll_ss_report_month_is_posted(PDO $pdo, int $year, int $month): bool
{
    if ($year < 2000 || $month < 1 || $month > 12) {
        return false;
    }
    try {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM hr_salary WHERE pay_year = ? AND pay_month = ? AND is_posted = 1 LIMIT 1'
        );
        $st->execute([$year, $month]);

        return (int) $st->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @return array{
 *   rows: list<array{
 *     seq:int,
 *     emp_code:string,
 *     name_ar:string,
 *     social_security_no:string,
 *     national_id:string,
 *     gross:float,
 *     ss_emp:float,
 *     subject_to_ss:bool
 *   }>,
 *   totals: array{gross:float, ss_emp:float},
 *   period_label: string,
 *   emp_count: int
 * }
 */
function hr_payroll_ss_report_build(PDO $pdo, int $year, int $month): array
{
    hr_payroll_validate_period($year, $month);
    hr_employee_ensure_schema($pdo);

    $periodLabel = hr_payroll_period_label($year, $month);
    $empty = [
        'rows' => [],
        'totals' => ['gross' => 0.0, 'ss_emp' => 0.0],
        'period_label' => $periodLabel,
        'emp_count' => 0,
    ];

    if (!hr_payroll_ss_report_month_is_posted($pdo, $year, $month)) {
        return $empty;
    }

    try {
        $st = $pdo->prepare(
            'SELECT s.base_salary, s.allowances, s.overtime, s.bonus, s.social_security_emp,
                    e.emp_code, e.name_ar, e.social_security_no, e.national_id, e.subject_to_social_security
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
    $totals = ['gross' => 0.0, 'ss_emp' => 0.0];
    $seq = 1;
    foreach ($raw as $r) {
        $subjectSs = (int) ($r['subject_to_social_security'] ?? 0) === 1;
        $gross = round(
            (float) ($r['base_salary'] ?? 0)
            + (float) ($r['allowances'] ?? 0)
            + (float) ($r['overtime'] ?? 0)
            + (float) ($r['bonus'] ?? 0),
            3
        );
        $ssEmp = $subjectSs ? round((float) ($r['social_security_emp'] ?? 0), 3) : 0.0;

        $rows[] = [
            'seq' => $seq++,
            'emp_code' => (string) ($r['emp_code'] ?? ''),
            'name_ar' => (string) ($r['name_ar'] ?? ''),
            'social_security_no' => trim((string) ($r['social_security_no'] ?? '')),
            'national_id' => trim((string) ($r['national_id'] ?? '')),
            'gross' => $gross,
            'ss_emp' => $ssEmp,
            'subject_to_ss' => $subjectSs,
        ];
        $totals['gross'] += $gross;
        $totals['ss_emp'] += $ssEmp;
    }

    $totals['gross'] = round($totals['gross'], 3);
    $totals['ss_emp'] = round($totals['ss_emp'], 3);

    return [
        'rows' => $rows,
        'totals' => $totals,
        'period_label' => $periodLabel,
        'emp_count' => count($rows),
    ];
}

function hr_payroll_ss_report_ss_display(array $row): string
{
    if (empty($row['subject_to_ss'])) {
        return '—';
    }

    return number_format((float) ($row['ss_emp'] ?? 0), 3);
}
