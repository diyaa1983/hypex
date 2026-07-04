<?php
declare(strict_types=1);

require_once app_path('includes/hr_attendance.php');
require_once app_path('includes/hr_schema.php');

/**
 * @return list<array<string,mixed>>
 */
function hr_att_punch_report_rows(
    PDO $pdo,
    string $dateFrom,
    string $dateTo,
    int $departmentId = 0,
    int $employeeId = 0,
    int $limit = 15000
): array {
    hr_attendance_ensure_schema($pdo);
    hr_employee_ensure_schema($pdo);

    $limit = max(1, min(20000, $limit));
    $where = ['p.punch_time >= ?', 'p.punch_time <= ?'];
    $params = [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'];

    if ($departmentId > 0) {
        $where[] = 'e.department_id = ?';
        $params[] = $departmentId;
    }
    if ($employeeId > 0) {
        $where[] = 'p.employee_id = ?';
        $params[] = $employeeId;
    }

    $sql = 'SELECT p.id, p.employee_id, p.zk_user_id, p.badge_number, p.zk_name,
                   p.punch_time, p.punch_type, p.verify_code, p.sensor_id, p.synced_at,
                   e.emp_code, e.name_ar AS employee_name,
                   d.name_ar AS dept_name
            FROM hr_att_punch p
            LEFT JOIN hr_employee e ON e.id = p.employee_id
            LEFT JOIN hr_department d ON d.id = e.department_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY p.punch_time ASC, p.id ASC
            LIMIT ' . $limit;

    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function hr_att_punch_report_count(
    PDO $pdo,
    string $dateFrom,
    string $dateTo,
    int $departmentId = 0,
    int $employeeId = 0
): int {
    hr_attendance_ensure_schema($pdo);
    hr_employee_ensure_schema($pdo);

    $where = ['p.punch_time >= ?', 'p.punch_time <= ?'];
    $params = [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'];

    if ($departmentId > 0) {
        $where[] = 'e.department_id = ?';
        $params[] = $departmentId;
    }
    if ($employeeId > 0) {
        $where[] = 'p.employee_id = ?';
        $params[] = $employeeId;
    }

    $sql = 'SELECT COUNT(*)
            FROM hr_att_punch p
            LEFT JOIN hr_employee e ON e.id = p.employee_id
            WHERE ' . implode(' AND ', $where);

    $st = $pdo->prepare($sql);
    $st->execute($params);

    return (int) $st->fetchColumn();
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function hr_att_punch_report_group_by_day(array $rows): array
{
    $groups = [];

    foreach ($rows as $row) {
        $punchTime = trim((string) ($row['punch_time'] ?? ''));
        if ($punchTime === '') {
            continue;
        }
        $ts = strtotime($punchTime);
        if ($ts === false) {
            continue;
        }

        $dateIso = date('Y-m-d', $ts);
        $empId = (int) ($row['employee_id'] ?? 0);
        $zkId = (int) ($row['zk_user_id'] ?? 0);
        $groupKey = ($empId > 0 ? 'e' . $empId : 'z' . $zkId) . '|' . $dateIso;

        if (!isset($groups[$groupKey])) {
            $empCode = trim((string) ($row['emp_code'] ?? ''));
            $empName = trim((string) ($row['employee_name'] ?? ''));
            if ($empName === '') {
                $empName = trim((string) ($row['zk_name'] ?? ''));
                if ($empName === '') {
                    $empName = 'غير مربوط';
                }
            }

            $groups[$groupKey] = [
                'employee_id' => $empId,
                'emp_code' => $empId > 0
                    ? $empCode
                    : (trim((string) ($row['badge_number'] ?? '')) ?: (string) $zkId),
                'emp_name' => $empName,
                'date' => date('d/m/Y', $ts),
                'date_iso' => $dateIso,
                'times' => [],
                'time_ts' => [],
                'sensors' => [],
                'dept_name' => trim((string) ($row['dept_name'] ?? '')),
                'is_unlinked' => $empId < 1,
            ];
        }

        $groups[$groupKey]['times'][] = date('H:i', $ts);
        $groups[$groupKey]['time_ts'][] = $ts;
        $sensor = trim((string) ($row['sensor_id'] ?? ''));
        if ($sensor !== '' && !in_array($sensor, $groups[$groupKey]['sensors'], true)) {
            $groups[$groupKey]['sensors'][] = $sensor;
        }
        if ($groups[$groupKey]['dept_name'] === '' && trim((string) ($row['dept_name'] ?? '')) !== '') {
            $groups[$groupKey]['dept_name'] = trim((string) ($row['dept_name'] ?? ''));
        }
    }

    $out = [];
    foreach ($groups as $g) {
        sort($g['time_ts'], SORT_NUMERIC);
        $g['times'] = array_map(static fn (int $ts): string => date('H:i', $ts), $g['time_ts']);
        $g['punch_times'] = implode('   ', $g['times']);
        $g['sensor_label'] = $g['sensors'] !== [] ? implode(', ', $g['sensors']) : '—';
        unset($g['times'], $g['time_ts'], $g['sensors']);
        $out[] = $g;
    }

    usort($out, static function (array $a, array $b): int {
        $codeCmp = strnatcmp((string) ($a['emp_code'] ?? ''), (string) ($b['emp_code'] ?? ''));
        if ($codeCmp !== 0) {
            return $codeCmp;
        }
        $nameCmp = strnatcmp((string) ($a['emp_name'] ?? ''), (string) ($b['emp_name'] ?? ''));
        if ($nameCmp !== 0) {
            return $nameCmp;
        }

        return strcmp((string) ($a['date_iso'] ?? ''), (string) ($b['date_iso'] ?? ''));
    });

    return $out;
}

/**
 * @param list<array<string,mixed>> $rows
 * @return array{total:int,linked:int,unlinked:int,rows:list<array<string,mixed>>,day_rows:list<array<string,mixed>>,truncated:bool,limit:int}
 */
function hr_att_punch_report_build(
    PDO $pdo,
    string $dateFrom,
    string $dateTo,
    int $departmentId = 0,
    int $employeeId = 0,
    int $limit = 15000
): array {
    $total = hr_att_punch_report_count($pdo, $dateFrom, $dateTo, $departmentId, $employeeId);
    $rows = hr_att_punch_report_rows($pdo, $dateFrom, $dateTo, $departmentId, $employeeId, $limit);
    $linked = 0;
    $unlinked = 0;
    foreach ($rows as $row) {
        if ((int) ($row['employee_id'] ?? 0) > 0) {
            $linked++;
        } else {
            $unlinked++;
        }
    }

    $dayRows = hr_att_punch_report_group_by_day($rows);

    return [
        'total' => $total,
        'linked' => $linked,
        'unlinked' => $unlinked,
        'rows' => $rows,
        'day_rows' => $dayRows,
        'truncated' => $total > count($rows),
        'limit' => $limit,
    ];
}

function hr_att_punch_report_format_datetime(?string $dt): array
{
    $dt = trim((string) $dt);
    if ($dt === '') {
        return ['date' => '—', 'time' => '—'];
    }
    $ts = strtotime($dt);
    if ($ts === false) {
        return ['date' => $dt, 'time' => ''];
    }

    return [
        'date' => date('d/m/Y', $ts),
        'time' => date('H:i:s', $ts),
    ];
}

function hr_att_punch_report_employee_label(array $row): string
{
    $empCode = trim((string) ($row['emp_code'] ?? ''));
    $empName = trim((string) ($row['employee_name'] ?? ''));
    if ($empName !== '') {
        return ($empCode !== '' ? $empCode . ' — ' : '') . $empName;
    }
    $zkName = trim((string) ($row['zk_name'] ?? ''));
    if ($zkName !== '') {
        return $zkName . ' (غير مربوط)';
    }

    return 'غير مربوط';
}
