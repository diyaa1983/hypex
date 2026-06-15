<?php
declare(strict_types=1);

require_once app_path('includes/hr_payroll_posting.php');
require_once app_path('includes/hr_social_security_payroll.php');

/**
 * @return array{
 *   departments: list<array{
 *     dept_id:int,
 *     dept_name:string,
 *     rows: list<array{seq:int, emp_code:string, name_ar:string, gross:float, net:float, ss_emp:float, ss_er:float}>,
 *     totals: array{gross:float, net:float, ss_emp:float, ss_er:float, ss_payable:float}
 *   }>,
 *   grand: array{gross:float, net:float, ss_emp:float, ss_er:float, ss_payable:float},
 *   period_label: string
 * }
 */
function hr_payroll_dept_report_build(PDO $pdo, int $year, int $month, int $departmentId = 0): array
{
    hr_payroll_validate_period($year, $month);
    hr_employee_ensure_schema($pdo);
    hr_department_ensure_schema($pdo);

    $periodLabel = hr_payroll_period_label($year, $month);
    $empty = [
        'departments' => [],
        'grand' => ['gross' => 0.0, 'net' => 0.0, 'ss_emp' => 0.0, 'ss_er' => 0.0, 'ss_payable' => 0.0],
        'period_label' => $periodLabel,
    ];

    $sql = 'SELECT s.id AS salary_id, s.employee_id, s.base_salary, s.allowances, s.social_security_emp, s.net_salary,
                   e.emp_code, e.name_ar, e.department_id, e.department, e.subject_to_social_security,
                   COALESCE(d.name_ar, NULLIF(TRIM(e.department), \'\'), ?) AS dept_name,
                   COALESCE(d.id, 0) AS dept_id_sort
            FROM hr_salary s
            INNER JOIN hr_employee e ON e.id = s.employee_id
            LEFT JOIN hr_department d ON d.id = e.department_id
            WHERE s.pay_year = ? AND s.pay_month = ?';
    $params = ['— بدون قسم —', $year, $month];

    if ($departmentId > 0) {
        $sql .= ' AND e.department_id = ?';
        $params[] = $departmentId;
    }

    $sql .= ' ORDER BY dept_name ASC, e.name_ar ASC, e.id ASC';

    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $raw = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return $empty;
    }

    if (!$raw) {
        return $empty;
    }

    $ssSt = $pdo->prepare(
        'SELECT employer_share FROM hr_social_security
         WHERE employee_id = ? AND pay_year = ? AND pay_month = ? LIMIT 1'
    );

    $groups = [];
    foreach ($raw as $r) {
        $deptKey = (string) ($r['dept_name'] ?? '— بدون قسم —');
        $deptIdSort = (int) ($r['dept_id_sort'] ?? 0);
        if (!isset($groups[$deptKey])) {
            $groups[$deptKey] = [
                'dept_id' => $deptIdSort,
                'dept_name' => $deptKey,
                'rows' => [],
                'totals' => ['gross' => 0.0, 'net' => 0.0, 'ss_emp' => 0.0, 'ss_er' => 0.0, 'ss_payable' => 0.0],
            ];
        }

        $empId = (int) ($r['employee_id'] ?? 0);
        $subjectSs = (int) ($r['subject_to_social_security'] ?? 0) === 1;
        $gross = round((float) ($r['base_salary'] ?? 0) + (float) ($r['allowances'] ?? 0), 3);
        $net = round((float) ($r['net_salary'] ?? 0), 3);
        $ssEmp = $subjectSs ? round((float) ($r['social_security_emp'] ?? 0), 3) : 0.0;

        $ssEr = 0.0;
        if ($subjectSs) {
            $ssSt->execute([$empId, $year, $month]);
            $erStored = $ssSt->fetchColumn();
            if ($erStored !== false && $erStored !== null) {
                $ssEr = round((float) $erStored, 3);
            } else {
                $calc = hr_ss_calc_for_employee($pdo, $empId, $gross);
                $ssEr = $calc ? round((float) ($calc['employer_amount'] ?? 0), 3) : 0.0;
            }
        }

        $groups[$deptKey]['rows'][] = [
            'emp_code' => (string) ($r['emp_code'] ?? ''),
            'name_ar' => (string) ($r['name_ar'] ?? ''),
            'gross' => $gross,
            'net' => $net,
            'subject_to_ss' => $subjectSs,
            'ss_emp' => $ssEmp,
            'ss_er' => $ssEr,
        ];
        $groups[$deptKey]['totals']['gross'] += $gross;
        $groups[$deptKey]['totals']['net'] += $net;
        $groups[$deptKey]['totals']['ss_emp'] += $ssEmp;
        $groups[$deptKey]['totals']['ss_er'] += $ssEr;
    }

    $departments = [];
    $grand = ['gross' => 0.0, 'net' => 0.0, 'ss_emp' => 0.0, 'ss_er' => 0.0, 'ss_payable' => 0.0];
    foreach ($groups as $g) {
        $g['totals']['gross'] = round($g['totals']['gross'], 3);
        $g['totals']['net'] = round($g['totals']['net'], 3);
        $g['totals']['ss_emp'] = round($g['totals']['ss_emp'], 3);
        $g['totals']['ss_er'] = round($g['totals']['ss_er'], 3);
        $g['totals']['ss_payable'] = round($g['totals']['ss_emp'] + $g['totals']['ss_er'], 3);
        $seq = 1;
        foreach ($g['rows'] as &$row) {
            $row['seq'] = $seq++;
        }
        unset($row);
        $departments[] = $g;
        $grand['gross'] += $g['totals']['gross'];
        $grand['net'] += $g['totals']['net'];
        $grand['ss_emp'] += $g['totals']['ss_emp'];
        $grand['ss_er'] += $g['totals']['ss_er'];
        $grand['ss_payable'] += $g['totals']['ss_payable'];
    }

    $grand['gross'] = round($grand['gross'], 3);
    $grand['net'] = round($grand['net'], 3);
    $grand['ss_emp'] = round($grand['ss_emp'], 3);
    $grand['ss_er'] = round($grand['ss_er'], 3);
    $grand['ss_payable'] = round($grand['ss_payable'], 3);

    return [
        'departments' => $departments,
        'grand' => $grand,
        'period_label' => $periodLabel,
    ];
}

/** عرض مبلغ الضمان أو «غير خاضع». */
function hr_payroll_dept_report_ss_display(array $row, string $field): string
{
    if (empty($row['subject_to_ss'])) {
        return 'غير خاضع';
    }
    $key = $field === 'er' ? 'ss_er' : 'ss_emp';

    return number_format((float) ($row[$key] ?? 0), 3);
}

function hr_payroll_dept_report_ss_is_na(array $row): bool
{
    return empty($row['subject_to_ss']);
}

/** @return array<int, array{id:int, name_ar:string}> */
function hr_payroll_dept_report_department_options(PDO $pdo): array
{
    hr_department_ensure_schema($pdo);
    try {
        $rows = $pdo->query(
            'SELECT id, name_ar FROM hr_department ORDER BY name_ar ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $r): array {
            return [
                'id' => (int) ($r['id'] ?? 0),
                'name_ar' => (string) ($r['name_ar'] ?? ''),
            ];
        }, $rows);
    } catch (Throwable $e) {
        return [];
    }
}
