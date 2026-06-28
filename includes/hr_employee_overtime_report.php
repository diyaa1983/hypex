<?php
declare(strict_types=1);

require_once app_path('includes/hr_employee_overtime.php');
require_once app_path('includes/hr_overtime.php');
require_once app_path('includes/hr_schema.php');
require_once app_path('includes/hr_salary.php');
require_once app_path('includes/acc_period_lock.php');

/** @return array{from:int, to:int} */
function hr_employee_overtime_report_period_keys(string $dateFrom, string $dateTo): array
{
    $fromTs = strtotime($dateFrom);
    $toTs = strtotime($dateTo);
    if ($fromTs === false || $toTs === false) {
        return ['from' => 0, 'to' => 0];
    }

    return [
        'from' => ((int) date('Y', $fromTs)) * 100 + (int) date('n', $fromTs),
        'to' => ((int) date('Y', $toTs)) * 100 + (int) date('n', $toTs),
    ];
}

/**
 * @param list<array<string, mixed>> $rows
 * @return array{
 *   months: list<array<string, mixed>>,
 *   grand_total_hours: float,
 *   grand_total_amount: float,
 *   row_count: int
 * }
 */
function hr_employee_overtime_report_aggregate_rows(array $rows): array
{
    $months = [];
    $grandHours = 0.0;
    $grandAmount = 0.0;
    $rowCount = 0;

    foreach ($rows as $row) {
        $year = (int) ($row['pay_year'] ?? 0);
        $month = (int) ($row['pay_month'] ?? 0);
        if ($year < 1 || $month < 1 || $month > 12) {
            continue;
        }

        $hours = (float) ($row['overtime_hours'] ?? 0);
        $amount = (float) ($row['overtime_amount'] ?? 0);
        if ($hours <= 0.0005 && $amount <= 0.0005) {
            continue;
        }

        $monthKey = sprintf('%04d-%02d', $year, $month);
        $deptId = (int) ($row['department_id'] ?? 0);
        $deptKey = (string) $deptId;
        $mult = (float) ($row['hour_multiplier'] ?? 0);

        if (!isset($months[$monthKey])) {
            $months[$monthKey] = [
                'year' => $year,
                'month' => $month,
                'month_label' => hr_employee_overtime_report_period_label($year, $month),
                'departments' => [],
                'total_hours' => 0.0,
                'total_amount' => 0.0,
                'row_count' => 0,
            ];
        }
        if (!isset($months[$monthKey]['departments'][$deptKey])) {
            $months[$monthKey]['departments'][$deptKey] = [
                'dept_id' => $deptId,
                'dept_name' => (string) ($row['dept_name'] ?? '—'),
                'rows' => [],
                'total_hours' => 0.0,
                'total_amount' => 0.0,
                'row_count' => 0,
            ];
        }

        $item = [
            'employee_id' => (int) ($row['employee_id'] ?? 0),
            'emp_code' => (string) ($row['emp_code'] ?? ''),
            'emp_name' => (string) ($row['emp_name'] ?? ''),
            'dept_name' => (string) ($row['dept_name'] ?? '—'),
            'period_label' => hr_employee_overtime_report_period_label($year, $month),
            'overtime_hours' => $hours,
            'hour_multiplier' => $mult,
            'multiplier_label' => $mult > 0.0005 ? hr_overtime_multiplier_report_short($mult) : '—',
            'base_salary' => (float) ($row['base_salary'] ?? 0),
            'overtime_amount' => $amount,
            'notes' => (string) ($row['notes'] ?? ''),
        ];

        $months[$monthKey]['departments'][$deptKey]['rows'][] = $item;
        $months[$monthKey]['departments'][$deptKey]['total_hours'] += $hours;
        $months[$monthKey]['departments'][$deptKey]['total_amount'] += $amount;
        $months[$monthKey]['departments'][$deptKey]['row_count']++;

        $months[$monthKey]['total_hours'] += $hours;
        $months[$monthKey]['total_amount'] += $amount;
        $months[$monthKey]['row_count']++;

        $grandHours += $hours;
        $grandAmount += $amount;
        $rowCount++;
    }

    krsort($months);

    $monthList = [];
    foreach ($months as $monthBlock) {
        $deptList = array_values($monthBlock['departments']);
        foreach ($deptList as &$deptBlock) {
            $deptBlock['total_hours'] = round((float) $deptBlock['total_hours'], 3);
            $deptBlock['total_amount'] = round((float) $deptBlock['total_amount'], 3);
        }
        unset($deptBlock);

        $monthList[] = [
            'year' => (int) $monthBlock['year'],
            'month' => (int) $monthBlock['month'],
            'month_label' => (string) $monthBlock['month_label'],
            'departments' => $deptList,
            'total_hours' => round((float) $monthBlock['total_hours'], 3),
            'total_amount' => round((float) $monthBlock['total_amount'], 3),
            'row_count' => (int) $monthBlock['row_count'],
        ];
    }

    return [
        'months' => $monthList,
        'grand_total_hours' => round($grandHours, 3),
        'grand_total_amount' => round($grandAmount, 3),
        'row_count' => $rowCount,
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function hr_employee_overtime_report_fetch_rows(
    PDO $pdo,
    int $periodFromKey,
    int $periodToKey,
    int $departmentId = 0,
    int $employeeId = 0,
    float $multiplierFilter = 0.0
): array {
    $rows = [];
    $seen = [];

    $sql = 'SELECT o.id, o.employee_id, o.pay_year, o.pay_month, o.overtime_hours, o.hour_multiplier,
                   o.base_salary, o.overtime_amount, o.notes,
                   e.emp_code, e.name_ar AS emp_name, e.department_id,
                   COALESCE(d.name_ar, NULLIF(TRIM(e.department), \'\'), \'—\') AS dept_name
            FROM hr_employee_overtime o
            INNER JOIN hr_employee e ON e.id = o.employee_id
            LEFT JOIN hr_department d ON d.id = e.department_id
            WHERE (o.pay_year * 100 + o.pay_month) BETWEEN ? AND ?
              AND (o.overtime_hours > 0.0005 OR o.overtime_amount > 0.0005)';
    $params = [$periodFromKey, $periodToKey];

    if ($departmentId > 0) {
        $sql .= ' AND e.department_id = ?';
        $params[] = $departmentId;
    }
    if ($employeeId > 0) {
        $sql .= ' AND e.id = ?';
        $params[] = $employeeId;
    }
    if ($multiplierFilter > 0.0005) {
        $sql .= ' AND ABS(o.hour_multiplier - ?) < 0.001';
        $params[] = $multiplierFilter;
    }

    $sql .= ' ORDER BY o.pay_year DESC, o.pay_month DESC, dept_name ASC, e.name_ar ASC, o.id ASC';

    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $key = (int) ($row['employee_id'] ?? 0) . '-'
                . (int) ($row['pay_year'] ?? 0) . '-'
                . (int) ($row['pay_month'] ?? 0);
            $seen[$key] = true;
            $rows[] = $row;
        }
    } catch (Throwable $e) {
        // fallback below may still return salary rows
    }

    if ($multiplierFilter > 0.0005) {
        return $rows;
    }

    $salSql = 'SELECT s.employee_id, s.pay_year, s.pay_month, s.overtime AS overtime_amount,
                      0.000 AS overtime_hours, 0.000 AS hour_multiplier, 0.000 AS base_salary,
                      NULL AS notes,
                      e.emp_code, e.name_ar AS emp_name, e.department_id,
                      COALESCE(d.name_ar, NULLIF(TRIM(e.department), \'\'), \'—\') AS dept_name
               FROM hr_salary s
               INNER JOIN hr_employee e ON e.id = s.employee_id
               LEFT JOIN hr_department d ON d.id = e.department_id
               LEFT JOIN hr_employee_overtime o ON o.employee_id = s.employee_id
                    AND o.pay_year = s.pay_year AND o.pay_month = s.pay_month
               WHERE s.overtime > 0.0005
                 AND o.id IS NULL
                 AND (s.pay_year * 100 + s.pay_month) BETWEEN ? AND ?';
    $salParams = [$periodFromKey, $periodToKey];

    if ($departmentId > 0) {
        $salSql .= ' AND e.department_id = ?';
        $salParams[] = $departmentId;
    }
    if ($employeeId > 0) {
        $salSql .= ' AND e.id = ?';
        $salParams[] = $employeeId;
    }

    $salSql .= ' ORDER BY s.pay_year DESC, s.pay_month DESC, dept_name ASC, e.name_ar ASC, s.id ASC';

    try {
        $stSal = $pdo->prepare($salSql);
        $stSal->execute($salParams);
        foreach ($stSal->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $key = (int) ($row['employee_id'] ?? 0) . '-'
                . (int) ($row['pay_year'] ?? 0) . '-'
                . (int) ($row['pay_month'] ?? 0);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $rows[] = $row;
        }
    } catch (Throwable $e) {
        // salary table may be unavailable in some installs
    }

    return $rows;
}

function hr_employee_overtime_report_department_options(PDO $pdo): array
{
    hr_department_ensure_schema($pdo);
    try {
        $rows = $pdo->query(
            'SELECT id, name_ar FROM hr_department WHERE COALESCE(is_active, 1) = 1 ORDER BY name_ar ASC, id ASC'
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

/** @return list<array{id:int, emp_code:string, name_ar:string, department_id:int, dept_name:string}> */
function hr_employee_overtime_report_employee_options(PDO $pdo): array
{
    hr_employee_ensure_schema($pdo);
    hr_department_ensure_schema($pdo);
    try {
        $st = $pdo->query(
            'SELECT e.id, e.emp_code, e.name_ar, e.department_id,
                    COALESCE(d.name_ar, NULLIF(TRIM(e.department), \'\'), \'—\') AS dept_name
             FROM hr_employee e
             LEFT JOIN hr_department d ON d.id = e.department_id
             ORDER BY e.name_ar ASC, e.id ASC'
        );

        return array_map(static function (array $r): array {
            return [
                'id' => (int) ($r['id'] ?? 0),
                'emp_code' => (string) ($r['emp_code'] ?? ''),
                'name_ar' => (string) ($r['name_ar'] ?? ''),
                'department_id' => (int) ($r['department_id'] ?? 0),
                'dept_name' => (string) ($r['dept_name'] ?? ''),
            ];
        }, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    } catch (Throwable $e) {
        return [];
    }
}

function hr_employee_overtime_report_period_label(int $year, int $month): string
{
    $names = acc_period_month_names_ar();

    return sprintf(
        '%02d — %s / %s',
        $month,
        $names[$month] ?? (string) $month,
        (string) $year
    );
}

function hr_employee_overtime_report_multiplier_filter_value(string $raw): float
{
    $raw = trim($raw);
    if ($raw === '' || $raw === '0' || $raw === 'all') {
        return 0.0;
    }

    return (float) str_replace(',', '.', $raw);
}

function hr_overtime_multiplier_report_short(float $multiplier): string
{
    return '× ' . rtrim(rtrim(number_format($multiplier, 2, '.', ''), '0'), '.');
}

/**
 * تجميع التقرير حسب القسم (عبر جميع الأشهر في الفترة).
 *
 * @param array{months:list<array<string,mixed>>, grand_total_hours:float, grand_total_amount:float, row_count:int} $report
 * @return list<array{
 *   dept_id:int,
 *   dept_name:string,
 *   rows:list<array<string,mixed>>,
 *   total_hours:float,
 *   total_amount:float,
 *   row_count:int
 * }>
 */
function hr_employee_overtime_report_departments(array $report): array
{
    $depts = [];

    foreach ($report['months'] ?? [] as $monthBlock) {
        foreach ($monthBlock['departments'] as $deptBlock) {
            $deptId = (int) ($deptBlock['dept_id'] ?? 0);
            $deptName = (string) ($deptBlock['dept_name'] ?? '—');
            $key = $deptId . '|' . $deptName;

            if (!isset($depts[$key])) {
                $depts[$key] = [
                    'dept_id' => $deptId,
                    'dept_name' => $deptName,
                    'rows' => [],
                    'total_hours' => 0.0,
                    'total_amount' => 0.0,
                    'row_count' => 0,
                ];
            }

            foreach ($deptBlock['rows'] as $row) {
                $hours = (float) ($row['overtime_hours'] ?? 0);
                $amount = (float) ($row['overtime_amount'] ?? 0);

                $depts[$key]['rows'][] = [
                    'month_label' => (string) ($monthBlock['month_label'] ?? ''),
                    'emp_code' => (string) ($row['emp_code'] ?? ''),
                    'emp_name' => (string) ($row['emp_name'] ?? ''),
                    'overtime_hours' => $hours,
                    'multiplier_label' => (string) ($row['multiplier_label'] ?? '—'),
                    'overtime_amount' => $amount,
                    'notes' => (string) ($row['notes'] ?? ''),
                ];
                $depts[$key]['total_hours'] += $hours;
                $depts[$key]['total_amount'] += $amount;
                $depts[$key]['row_count']++;
            }
        }
    }

    $list = array_values($depts);
    usort($list, static function (array $a, array $b): int {
        $cmp = strcmp((string) ($a['dept_name'] ?? ''), (string) ($b['dept_name'] ?? ''));
        if ($cmp !== 0) {
            return $cmp;
        }

        return ((int) ($a['dept_id'] ?? 0)) <=> ((int) ($b['dept_id'] ?? 0));
    });

    foreach ($list as &$dept) {
        $dept['total_hours'] = round((float) ($dept['total_hours'] ?? 0), 3);
        $dept['total_amount'] = round((float) ($dept['total_amount'] ?? 0), 3);
    }
    unset($dept);

    return $list;
}

/**
 * @return array{
 *   months: list<array{
 *     year:int,
 *     month:int,
 *     month_label:string,
 *     departments: list<array{
 *       dept_id:int,
 *       dept_name:string,
 *       rows: list<array<string, mixed>>,
 *       total_hours: float,
 *       total_amount: float,
 *       row_count: int
 *     }>,
 *     total_hours: float,
 *     total_amount: float,
 *     row_count: int
 *   }>,
 *   grand_total_hours: float,
 *   grand_total_amount: float,
 *   row_count: int
 * }
 */
function hr_employee_overtime_report_build(
    PDO $pdo,
    string $dateFrom,
    string $dateTo,
    int $departmentId = 0,
    int $employeeId = 0,
    float $multiplierFilter = 0.0
): array {
    hr_employee_overtime_ensure_schema($pdo);
    hr_employee_ensure_schema($pdo);
    hr_department_ensure_schema($pdo);

    $empty = [
        'months' => [],
        'grand_total_hours' => 0.0,
        'grand_total_amount' => 0.0,
        'row_count' => 0,
    ];

    if ($dateFrom === '' || $dateTo === '' || $dateFrom > $dateTo) {
        return $empty;
    }

    $periodKeys = hr_employee_overtime_report_period_keys($dateFrom, $dateTo);
    if ($periodKeys['from'] < 1 || $periodKeys['to'] < 1 || $periodKeys['from'] > $periodKeys['to']) {
        return $empty;
    }

    $rows = hr_employee_overtime_report_fetch_rows(
        $pdo,
        $periodKeys['from'],
        $periodKeys['to'],
        $departmentId,
        $employeeId,
        $multiplierFilter
    );

    return hr_employee_overtime_report_aggregate_rows($rows);
}
