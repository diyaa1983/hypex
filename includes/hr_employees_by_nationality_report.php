<?php
declare(strict_types=1);

require_once app_path('includes/hr_employees_report.php');

/**
 * @return array{
 *   nationalities: list<array{
 *     nat_id: int,
 *     nat_name: string,
 *     rows: list<array<string, mixed>>,
 *     employee_count: int,
 *     total_salary: float
 *   }>,
 *   grand: array{employee_count: int, total_salary: float}
 * }
 */
function hr_employees_by_nationality_report_build(PDO $pdo, string $status = 'all', int $nationalityId = 0): array
{
    hr_employee_ensure_schema($pdo);
    hr_employee_ensure_link_columns($pdo);
    hr_nationality_ensure_schema($pdo);
    hr_job_title_ensure_schema($pdo);

    $status = hr_employees_report_normalize_status($status);
    $empty = [
        'nationalities' => [],
        'grand' => ['employee_count' => 0, 'total_salary' => 0.0],
    ];

    $sql = 'SELECT e.id, e.emp_code, e.name_ar, e.hire_date, e.base_salary, e.allowances,
                   e.is_active, e.resignation_date, e.is_resigned_posted,
                   e.nationality_id,
                   COALESCE(n.name_ar, ?) AS nat_name,
                   COALESCE(n.id, 0) AS nat_id_sort,
                   COALESCE(jt.name_ar, NULLIF(TRIM(e.job_title), \'\'), ?) AS job_title_name
            FROM hr_employee e
            LEFT JOIN hr_nationality n ON n.id = e.nationality_id
            LEFT JOIN hr_job_title jt ON jt.id = e.job_title_id
            WHERE 1=1';
    $params = ['— بدون جنسية —', '—'];

    if ($status === 'active') {
        $sql .= ' AND e.is_active = 1
                  AND COALESCE(e.is_resigned_posted, 0) = 0
                  AND (e.resignation_date IS NULL OR TRIM(e.resignation_date) = \'\')';
    } elseif ($status === 'resigned') {
        $sql .= ' AND (e.is_active = 0
                  OR COALESCE(e.is_resigned_posted, 0) = 1
                  OR (e.resignation_date IS NOT NULL AND TRIM(e.resignation_date) <> \'\'))';
    }

    if ($nationalityId > 0) {
        $sql .= ' AND e.nationality_id = ?';
        $params[] = $nationalityId;
    }

    $sql .= ' ORDER BY nat_name ASC,
              CASE WHEN e.emp_code REGEXP \'^[0-9]+$\' THEN CAST(e.emp_code AS UNSIGNED) ELSE 999999999 END ASC,
              e.emp_code ASC, e.id ASC';

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

    $groups = [];
    foreach ($raw as $row) {
        $natKey = (string) ($row['nat_name'] ?? '— بدون جنسية —');
        $natIdSort = (int) ($row['nat_id_sort'] ?? 0);
        if (!isset($groups[$natKey])) {
            $groups[$natKey] = [
                'nat_id' => $natIdSort,
                'nat_name' => $natKey,
                'rows' => [],
                'employee_count' => 0,
                'total_salary' => 0.0,
            ];
        }

        $salary = (float) ($row['base_salary'] ?? 0) + (float) ($row['allowances'] ?? 0);
        $groups[$natKey]['rows'][] = [
            'emp_code' => (string) ($row['emp_code'] ?? ''),
            'name_ar' => (string) ($row['name_ar'] ?? ''),
            'job_title_name' => (string) ($row['job_title_name'] ?? '—'),
            'hire_date' => format_date_dmY((string) ($row['hire_date'] ?? '')),
            'salary' => $salary,
            'status_label' => hr_employees_report_is_resigned($row) ? 'مستقيل' : 'على رأس العمل',
        ];
        $groups[$natKey]['employee_count']++;
        $groups[$natKey]['total_salary'] += $salary;
    }

    $nationalities = [];
    $grand = ['employee_count' => 0, 'total_salary' => 0.0];
    foreach ($groups as $group) {
        $seq = 1;
        foreach ($group['rows'] as &$row) {
            $row['seq'] = $seq++;
        }
        unset($row);
        $group['total_salary'] = round($group['total_salary'], 3);
        $nationalities[] = $group;
        $grand['employee_count'] += $group['employee_count'];
        $grand['total_salary'] += $group['total_salary'];
    }

    $grand['total_salary'] = round($grand['total_salary'], 3);

    return [
        'nationalities' => $nationalities,
        'grand' => $grand,
    ];
}

function hr_employees_by_nationality_report_title(string $status): string
{
    $status = hr_employees_report_normalize_status($status);

    return match ($status) {
        'active' => 'تقرير الموظفين حسب الجنسية — على رأس العمل',
        'resigned' => 'تقرير الموظفين حسب الجنسية — المستقيلون',
        default => 'تقرير الموظفين حسب الجنسية',
    };
}
