<?php
declare(strict_types=1);

require_once app_path('includes/hr_employee_departure.php');
require_once app_path('includes/hr_departure_type.php');
require_once app_path('includes/hr_schema.php');

/** @return list<array{id:int, name_ar:string}> */
function hr_employee_departures_report_department_options(PDO $pdo): array
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

/** @return list<array{id:int, type_code:string, name_ar:string}> */
function hr_employee_departures_report_departure_type_options(PDO $pdo): array
{
    $types = hr_departure_type_list($pdo, true);

    return array_map(static function (array $r): array {
        return [
            'id' => (int) ($r['id'] ?? 0),
            'type_code' => (string) ($r['type_code'] ?? ''),
            'name_ar' => (string) ($r['name_ar'] ?? ''),
        ];
    }, $types);
}

function hr_employee_departures_report_duration_hhmm(string $timeFrom, string $timeTo): string
{
    $from = hr_employee_departure_format_time($timeFrom);
    $to = hr_employee_departure_format_time($timeTo);
    $mins = max(0, hr_employee_departure_time_minutes($to) - hr_employee_departure_time_minutes($from));

    return sprintf('%02d:%02d', intdiv($mins, 60), $mins % 60);
}

/**
 * @param list<array<string, mixed>> $rows
 * @return array{
 *   departments: list<array{
 *     dept_id:int,
 *     dept_name:string,
 *     rows:list<array<string,mixed>>,
 *     row_count:int,
 *     total_minutes:int
 *   }>,
 *   row_count:int,
 *   grand_total_minutes:int
 * }
 */
function hr_employee_departures_report_aggregate_rows(array $rows): array
{
    $depts = [];
    $rowCount = 0;
    $grandMinutes = 0;

    foreach ($rows as $row) {
        $deptId = (int) ($row['department_id'] ?? 0);
        $deptName = (string) ($row['dept_name'] ?? '—');
        $deptKey = $deptId . '|' . $deptName;
        $timeFrom = (string) ($row['time_from'] ?? '');
        $timeTo = (string) ($row['time_to'] ?? '');
        $durationMins = max(
            0,
            hr_employee_departure_time_minutes(hr_employee_departure_format_time($timeTo))
            - hr_employee_departure_time_minutes(hr_employee_departure_format_time($timeFrom))
        );

        if (!isset($depts[$deptKey])) {
            $depts[$deptKey] = [
                'dept_id' => $deptId,
                'dept_name' => $deptName,
                'rows' => [],
                'row_count' => 0,
                'total_minutes' => 0,
            ];
        }

        $depts[$deptKey]['rows'][] = [
            'voucher_no' => (string) ($row['voucher_no'] ?? ''),
            'emp_code' => (string) ($row['emp_code'] ?? ''),
            'emp_name' => (string) ($row['emp_name'] ?? ''),
            'type_name' => (string) ($row['type_name'] ?? ''),
            'departure_date' => (string) ($row['departure_date'] ?? ''),
            'time_from' => hr_employee_departure_format_time($timeFrom),
            'time_to' => hr_employee_departure_format_time($timeTo),
            'duration_label' => hr_employee_departures_report_duration_hhmm($timeFrom, $timeTo),
            'duration_minutes' => $durationMins,
            'posted_label' => hr_employee_departure_posted_label((int) ($row['is_posted'] ?? 0)),
            'notes' => (string) ($row['notes'] ?? ''),
        ];
        $depts[$deptKey]['row_count']++;
        $depts[$deptKey]['total_minutes'] += $durationMins;
        $rowCount++;
        $grandMinutes += $durationMins;
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
        $mins = (int) ($dept['total_minutes'] ?? 0);
        $dept['total_duration_label'] = sprintf('%02d:%02d', intdiv($mins, 60), $mins % 60);
    }
    unset($dept);

    return [
        'departments' => $list,
        'row_count' => $rowCount,
        'grand_total_minutes' => $grandMinutes,
        'grand_total_duration_label' => sprintf('%02d:%02d', intdiv($grandMinutes, 60), $grandMinutes % 60),
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function hr_employee_departures_report_fetch_rows(
    PDO $pdo,
    string $dateFrom,
    string $dateTo,
    int $departmentId = 0,
    int $departureTypeId = 0
): array {
    hr_employee_departure_ensure_schema($pdo);
    hr_employee_ensure_schema($pdo);
    hr_department_ensure_schema($pdo);

    $sql = 'SELECT d.id, d.voucher_no, d.departure_date, d.time_from, d.time_to,
                   d.is_posted, d.notes,
                   e.emp_code, e.name_ar AS emp_name, e.department_id,
                   COALESCE(dept.name_ar, NULLIF(TRIM(e.department), \'\'), \'—\') AS dept_name,
                   t.name_ar AS type_name
            FROM hr_employee_departure d
            INNER JOIN hr_employee e ON e.id = d.employee_id
            INNER JOIN hr_departure_type t ON t.id = d.departure_type_id
            LEFT JOIN hr_department dept ON dept.id = e.department_id
            WHERE d.departure_date >= ? AND d.departure_date <= ?';
    $params = [$dateFrom, $dateTo];

    if ($departmentId > 0) {
        $sql .= ' AND e.department_id = ?';
        $params[] = $departmentId;
    }
    if ($departureTypeId > 0) {
        $sql .= ' AND d.departure_type_id = ?';
        $params[] = $departureTypeId;
    }

    $sql .= ' ORDER BY dept_name ASC, e.name_ar ASC, d.departure_date ASC, d.time_from ASC, CAST(d.voucher_no AS UNSIGNED) ASC, d.id ASC';

    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return array{
 *   departments: list<array<string,mixed>>,
 *   row_count:int,
 *   grand_total_minutes:int,
 *   grand_total_duration_label:string
 * }
 */
function hr_employee_departures_report_build(
    PDO $pdo,
    string $dateFrom,
    string $dateTo,
    int $departmentId = 0,
    int $departureTypeId = 0
): array {
    $empty = [
        'departments' => [],
        'row_count' => 0,
        'grand_total_minutes' => 0,
        'grand_total_duration_label' => '00:00',
    ];

    if ($dateFrom === '' || $dateTo === '' || $dateFrom > $dateTo) {
        return $empty;
    }

    $rows = hr_employee_departures_report_fetch_rows($pdo, $dateFrom, $dateTo, $departmentId, $departureTypeId);

    return hr_employee_departures_report_aggregate_rows($rows);
}
