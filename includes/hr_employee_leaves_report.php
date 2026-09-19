<?php
declare(strict_types=1);

require_once app_path('includes/hr_employee_leave.php');
require_once app_path('includes/hr_leave_type.php');
require_once app_path('includes/hr_schema.php');

/** @return list<array{id:int, name_ar:string}> */
function hr_employee_leaves_report_department_options(PDO $pdo): array
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

/** @return list<array{id:int, leave_code:string, name_ar:string}> */
function hr_employee_leaves_report_leave_type_options(PDO $pdo): array
{
    $types = hr_leave_type_list($pdo, true);

    return array_map(static function (array $r): array {
        return [
            'id' => (int) ($r['id'] ?? 0),
            'leave_code' => (string) ($r['leave_code'] ?? ''),
            'name_ar' => (string) ($r['name_ar'] ?? ''),
        ];
    }, $types);
}

/**
 * @param list<array<string, mixed>> $rows
 * @return array{
 *   departments: list<array{
 *     dept_id:int,
 *     dept_name:string,
 *     rows:list<array<string,mixed>>,
 *     row_count:int,
 *     total_days:float
 *   }>,
 *   row_count:int,
 *   grand_total_days:float
 * }
 */
function hr_employee_leaves_report_aggregate_rows(array $rows): array
{
    $depts = [];
    $rowCount = 0;
    $grandDays = 0.0;

    foreach ($rows as $row) {
        $deptId = (int) ($row['department_id'] ?? 0);
        $deptName = (string) ($row['dept_name'] ?? '—');
        $deptKey = $deptId . '|' . $deptName;
        $days = (float) ($row['days_count'] ?? 0);

        if (!isset($depts[$deptKey])) {
            $depts[$deptKey] = [
                'dept_id' => $deptId,
                'dept_name' => $deptName,
                'rows' => [],
                'row_count' => 0,
                'total_days' => 0.0,
            ];
        }

        $depts[$deptKey]['rows'][] = [
            'voucher_no' => (string) ($row['voucher_no'] ?? ''),
            'emp_code' => (string) ($row['emp_code'] ?? ''),
            'emp_name' => (string) ($row['emp_name'] ?? ''),
            'type_name' => (string) ($row['type_name'] ?? ''),
            'leave_date' => (string) ($row['leave_date'] ?? ''),
            'date_from' => (string) ($row['date_from'] ?? ''),
            'date_to' => (string) ($row['date_to'] ?? ''),
            'days_count' => $days,
            'posted_label' => hr_employee_leave_posted_label((int) ($row['is_posted'] ?? 0)),
            'notes' => (string) ($row['notes'] ?? ''),
        ];
        $depts[$deptKey]['row_count']++;
        $depts[$deptKey]['total_days'] += $days;
        $rowCount++;
        $grandDays += $days;
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
        $dept['total_days'] = round((float) ($dept['total_days'] ?? 0), 2);
    }
    unset($dept);

    return [
        'departments' => $list,
        'row_count' => $rowCount,
        'grand_total_days' => round($grandDays, 2),
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function hr_employee_leaves_report_fetch_rows(
    PDO $pdo,
    string $dateFrom,
    string $dateTo,
    int $departmentId = 0,
    int $leaveTypeId = 0
): array {
    hr_employee_leave_ensure_schema($pdo);
    hr_employee_ensure_schema($pdo);
    hr_department_ensure_schema($pdo);

    $sql = 'SELECT l.id, l.voucher_no, l.leave_date, l.date_from, l.date_to, l.days_count,
                   l.is_posted, l.notes,
                   e.emp_code, e.name_ar AS emp_name, e.department_id,
                   COALESCE(d.name_ar, NULLIF(TRIM(e.department), \'\'), \'—\') AS dept_name,
                   t.name_ar AS type_name
            FROM hr_employee_leave l
            INNER JOIN hr_employee e ON e.id = l.employee_id
            INNER JOIN hr_leave_type t ON t.id = l.leave_type_id
            LEFT JOIN hr_department d ON d.id = e.department_id
            WHERE l.date_to >= ? AND l.date_from <= ?';
    $params = [$dateFrom, $dateTo];

    if ($departmentId > 0) {
        $sql .= ' AND e.department_id = ?';
        $params[] = $departmentId;
    }
    if ($leaveTypeId > 0) {
        $sql .= ' AND l.leave_type_id = ?';
        $params[] = $leaveTypeId;
    }

    $sql .= ' ORDER BY dept_name ASC, e.name_ar ASC, l.date_from ASC, CAST(l.voucher_no AS UNSIGNED) ASC, l.id ASC';

    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return array{
 *   departments: list<array<string,mixed>>,
 *   row_count:int,
 *   grand_total_days:float
 * }
 */
function hr_employee_leaves_report_build(
    PDO $pdo,
    string $dateFrom,
    string $dateTo,
    int $departmentId = 0,
    int $leaveTypeId = 0
): array {
    $empty = [
        'departments' => [],
        'row_count' => 0,
        'grand_total_days' => 0.0,
    ];

    if ($dateFrom === '' || $dateTo === '' || $dateFrom > $dateTo) {
        return $empty;
    }

    $rows = hr_employee_leaves_report_fetch_rows($pdo, $dateFrom, $dateTo, $departmentId, $leaveTypeId);

    return hr_employee_leaves_report_aggregate_rows($rows);
}
