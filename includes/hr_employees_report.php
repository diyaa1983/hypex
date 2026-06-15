<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');

function hr_employees_report_is_resigned(array $row): bool
{
    if ((int) ($row['is_active'] ?? 1) === 0) {
        return true;
    }
    if ((int) ($row['is_resigned_posted'] ?? 0) === 1) {
        return true;
    }

    return trim((string) ($row['resignation_date'] ?? '')) !== '';
}

/** @return list<string> */
function hr_employees_report_status_options(): array
{
    return [
        'all' => 'الكل',
        'active' => 'على رأس العمل',
        'resigned' => 'مستقيل',
    ];
}

function hr_employees_report_status_label(string $status): string
{
    $options = hr_employees_report_status_options();

    return (string) ($options[$status] ?? $options['all']);
}

function hr_employees_report_title(string $status): string
{
    $status = hr_employees_report_normalize_status($status);

    return match ($status) {
        'active' => 'تقرير الموظفين الذين على رأس عملهم',
        'resigned' => 'تقرير الموظفين المستقيلين',
        default => 'تقرير الموظفين',
    };
}

function hr_employees_report_normalize_status(string $status): string
{
    return array_key_exists($status, hr_employees_report_status_options()) ? $status : 'all';
}

/** @return list<array<string, mixed>> */
function hr_employees_report_rows(PDO $pdo, string $status = 'all'): array
{
    hr_employee_ensure_schema($pdo);
    hr_employee_ensure_link_columns($pdo);

    $status = hr_employees_report_normalize_status($status);

    $sql = 'SELECT id, emp_code, name_ar, base_salary, allowances, is_active, resignation_date, is_resigned_posted
            FROM hr_employee';
    $params = [];

    if ($status === 'active') {
        $sql .= ' WHERE is_active = 1
                  AND COALESCE(is_resigned_posted, 0) = 0
                  AND (resignation_date IS NULL OR TRIM(resignation_date) = \'\')';
    } elseif ($status === 'resigned') {
        $sql .= ' WHERE is_active = 0
                  OR COALESCE(is_resigned_posted, 0) = 1
                  OR (resignation_date IS NOT NULL AND TRIM(resignation_date) <> \'\')';
    }

    $sql .= ' ' . hr_employee_list_order_sql();

    try {
        $st = $params === [] ? $pdo->query($sql) : $pdo->prepare($sql);
        if ($params !== []) {
            $st->execute($params);
        }
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    $seq = 0;
    foreach ($rows as $row) {
        $seq++;
        $salary = (float) ($row['base_salary'] ?? 0) + (float) ($row['allowances'] ?? 0);
        $out[] = [
            'seq' => $seq,
            'emp_code' => (string) ($row['emp_code'] ?? ''),
            'name_ar' => (string) ($row['name_ar'] ?? ''),
            'salary' => $salary,
            'status_label' => hr_employees_report_is_resigned($row) ? 'مستقيل' : 'على رأس العمل',
        ];
    }

    return $out;
}

/** @param list<array<string, mixed>> $rows */
function hr_employees_report_total_salary(array $rows): float
{
    $total = 0.0;
    foreach ($rows as $row) {
        $total += (float) ($row['salary'] ?? 0);
    }

    return $total;
}
