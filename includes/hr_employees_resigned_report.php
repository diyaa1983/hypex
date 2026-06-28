<?php
declare(strict_types=1);

require_once app_path('includes/hr_employees_report.php');

/** @return list<array<string, mixed>> */
function hr_employees_resigned_report_rows(PDO $pdo, int $departmentId = 0): array
{
    hr_employee_ensure_schema($pdo);
    hr_employee_ensure_link_columns($pdo);
    hr_department_ensure_schema($pdo);
    hr_job_title_ensure_schema($pdo);

    $sql = 'SELECT e.id, e.emp_code, e.name_ar, e.hire_date, e.resignation_date, e.is_resigned_posted,
                   e.is_active,
                   COALESCE(d.name_ar, NULLIF(TRIM(e.department), \'\'), ?) AS dept_name,
                   COALESCE(jt.name_ar, NULLIF(TRIM(e.job_title), \'\'), ?) AS job_title_name
            FROM hr_employee e
            LEFT JOIN hr_department d ON d.id = e.department_id
            LEFT JOIN hr_job_title jt ON jt.id = e.job_title_id
            WHERE (e.is_active = 0
                   OR COALESCE(e.is_resigned_posted, 0) = 1
                   OR (e.resignation_date IS NOT NULL AND TRIM(e.resignation_date) <> \'\'))';
    $params = ['—', '—'];

    if ($departmentId > 0) {
        $sql .= ' AND e.department_id = ?';
        $params[] = $departmentId;
    }

    $sql .= ' ORDER BY COALESCE(e.resignation_date, e.hire_date) DESC, e.emp_code ASC, e.id ASC';

    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    $seq = 0;
    foreach ($rows as $row) {
        $seq++;
        $out[] = [
            'seq' => $seq,
            'emp_code' => (string) ($row['emp_code'] ?? ''),
            'name_ar' => (string) ($row['name_ar'] ?? ''),
            'dept_name' => (string) ($row['dept_name'] ?? '—'),
            'job_title_name' => (string) ($row['job_title_name'] ?? '—'),
            'hire_date' => format_date_dmY((string) ($row['hire_date'] ?? '')),
            'resignation_date' => format_date_dmY((string) ($row['resignation_date'] ?? '')),
            'posted_label' => hr_employees_resigned_report_posted_label($row),
        ];
    }

    return $out;
}

function hr_employees_resigned_report_posted_label(array $row): string
{
    if ((int) ($row['is_resigned_posted'] ?? 0) === 1) {
        return 'مرحّل';
    }

    $resignDate = trim((string) ($row['resignation_date'] ?? ''));

    return $resignDate !== '' ? 'غير مرحّل' : '—';
}

function hr_employees_resigned_report_title(): string
{
    return 'تقرير الموظفين المستقيلين';
}
