<?php
declare(strict_types=1);

require_once app_path('includes/hr_attendance.php');
require_once app_path('includes/hr_attendance_shift.php');
require_once app_path('includes/hr_employee_schedule.php');
require_once app_path('includes/hr_schema.php');

/** @return array{code:string,start:string,end:string,weekend_days:list<int>,label:string} */
function hr_attendance_report_shift_settings(PDO $pdo): array
{
    hr_attendance_shift_ensure_schema($pdo);
    $fromTable = hr_attendance_shift_default_settings($pdo);
    if ($fromTable !== null) {
        return $fromTable;
    }

    return [
        'code' => '1',
        'start' => '07:00',
        'end' => '15:00',
        'weekend_days' => [5, 6],
        'label' => '1 (07:00-15:00)',
    ];
}

/** @return array<int,string> */
function hr_attendance_report_day_names(): array
{
    return [
        0 => 'السبت',
        1 => 'الأحد',
        2 => 'الاثنين',
        3 => 'الثلاثاء',
        4 => 'الأربعاء',
        5 => 'الخميس',
        6 => 'الجمعة',
    ];
}

function hr_attendance_report_day_index(string $date): int
{
    $w = (int) date('w', strtotime($date));

    return ($w + 1) % 7;
}

function hr_attendance_report_day_name(string $date): string
{
    $names = hr_attendance_report_day_names();
    $idx = hr_attendance_report_day_index($date);

    return $names[$idx] ?? '—';
}

function hr_attendance_report_is_weekend(string $date, array $weekendDays): bool
{
    $w = (int) date('w', strtotime($date));

    return in_array($w, $weekendDays, true);
}

function hr_attendance_report_time_to_minutes(string $hhmm): int
{
    $hhmm = trim($hhmm);
    if ($hhmm === '' || !preg_match('/^\d{1,2}:\d{2}$/', $hhmm)) {
        return 0;
    }
    [$h, $m] = array_map('intval', explode(':', $hhmm, 2));

    return max(0, $h * 60 + $m);
}

function hr_attendance_report_minutes_to_hhmm(int $minutes): string
{
    if ($minutes <= 0) {
        return '';
    }

    return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
}

function hr_attendance_report_diff_late_minutes(string $scheduled, string $actual): int
{
    $diff = hr_attendance_report_time_to_minutes($actual) - hr_attendance_report_time_to_minutes($scheduled);

    return $diff > 0 ? $diff : 0;
}

function hr_attendance_report_diff_early_minutes(string $scheduled, string $actual): int
{
    $diff = hr_attendance_report_time_to_minutes($scheduled) - hr_attendance_report_time_to_minutes($actual);

    return $diff > 0 ? $diff : 0;
}

/** @return list<string> */
function hr_attendance_report_date_range(string $dateFrom, string $dateTo): array
{
    $dates = [];
    try {
        $cur = new DateTimeImmutable($dateFrom);
        $end = new DateTimeImmutable($dateTo);
    } catch (Throwable $e) {
        return [];
    }
    if ($cur > $end) {
        return [];
    }
    while ($cur <= $end) {
        $dates[] = $cur->format('Y-m-d');
        $cur = $cur->modify('+1 day');
    }

    return $dates;
}

/**
 * @param list<array<string,mixed>> $punches
 * @return array{entry:?string,exit:?string,leave_start:?string,leave_end:?string}
 */
function hr_attendance_report_day_punch_times(array $punches): array
{
    if ($punches === []) {
        return [
            'entry' => null,
            'exit' => null,
            'leave_start' => null,
            'leave_end' => null,
        ];
    }

    usort($punches, static function (array $a, array $b): int {
        return strcmp((string) ($a['punch_time'] ?? ''), (string) ($b['punch_time'] ?? ''));
    });

    $entry = null;
    $exit = null;
    $ins = [];
    $outs = [];

    foreach ($punches as $p) {
        $time = substr((string) ($p['punch_time'] ?? ''), 11, 5);
        if ($time === '') {
            continue;
        }
        $type = strtoupper(trim((string) ($p['punch_type'] ?? '')));
        if ($type === 'I' || $type === '0') {
            $ins[] = $time;
        } elseif ($type === 'O' || $type === '1') {
            $outs[] = $time;
        }
    }

    if ($ins !== []) {
        $entry = min($ins);
    }
    if ($outs !== []) {
        $exit = max($outs);
    }

    if ($entry === null) {
        $entry = substr((string) ($punches[0]['punch_time'] ?? ''), 11, 5) ?: null;
    }
    if ($exit === null) {
        $last = $punches[count($punches) - 1];
        $exit = substr((string) ($last['punch_time'] ?? ''), 11, 5) ?: null;
    }

    $leaveStart = null;
    $leaveEnd = null;
    if (count($punches) >= 4 && $entry !== null && $exit !== null) {
        $middle = array_slice($punches, 1, -1);
        if ($middle !== []) {
            $leaveStart = substr((string) ($middle[0]['punch_time'] ?? ''), 11, 5) ?: null;
            $leaveEnd = substr((string) ($middle[count($middle) - 1]['punch_time'] ?? ''), 11, 5) ?: null;
        }
    }

    return [
        'entry' => $entry,
        'exit' => $exit,
        'leave_start' => $leaveStart,
        'leave_end' => $leaveEnd,
    ];
}

/**
 * @param array{entry:?string,exit:?string,leave_start:?string,leave_end:?string} $times
 * @return array<string,mixed>
 */
function hr_attendance_report_build_day_row(
    string $workDate,
    array $times,
    array $shift,
    bool $isWeekend
): array {
    $shiftStart = (string) ($shift['start'] ?? '07:00');
    $shiftEnd = (string) ($shift['end'] ?? '15:00');
    $shiftLabel = (string) ($shift['label'] ?? '');
    $shiftName = trim((string) ($shift['name'] ?? ''));
    $isHolidayShift = !empty($shift['is_holiday'])
        || hr_attendance_shift_is_holiday($shiftStart, $shiftEnd);
    $status = 'normal';

    if ($isHolidayShift) {
        $status = 'holiday';
        $shiftLabel = $shiftName !== '' ? $shiftName : ($shiftLabel !== '' ? $shiftLabel : 'عطلة');
    } elseif ($isWeekend) {
        $status = 'weekend';
        $shiftLabel = 'عطلة اسبوعية';
    } elseif (($times['leave_start'] ?? null) !== null && ($times['leave_end'] ?? null) !== null) {
        $status = 'leave';
        $shiftLabel = 'مغادرة خاصة';
    } elseif (($times['entry'] ?? null) === null && ($times['exit'] ?? null) === null) {
        $status = 'absent';
    }

    $entry = $times['entry'] ?? null;
    $exit = $times['exit'] ?? null;
    $leaveStart = $times['leave_start'] ?? null;
    $leaveEnd = $times['leave_end'] ?? null;

    $morningDelay = '';
    $eveningDelay = '';
    $overtime = '';

    if (!$isHolidayShift && !$isWeekend && $entry !== null) {
        $morningDelay = hr_attendance_report_minutes_to_hhmm(
            hr_attendance_report_diff_late_minutes($shiftStart, $entry)
        );
    }
    if (!$isHolidayShift && !$isWeekend && $exit !== null) {
        $eveningDelay = hr_attendance_report_minutes_to_hhmm(
            hr_attendance_report_diff_early_minutes($shiftEnd, $exit)
        );
        $otMins = hr_attendance_report_diff_late_minutes($shiftEnd, $exit);
        $overtime = hr_attendance_report_minutes_to_hhmm($otMins);
        if ($eveningDelay !== '') {
            $overtime = '';
        }
    }

    if ($isHolidayShift || $isWeekend) {
        $shiftStart = '00:00';
        $shiftEnd = '00:00';
        $entry = null;
        $exit = null;
        $leaveStart = null;
        $leaveEnd = null;
        $morningDelay = '';
        $eveningDelay = '';
        $overtime = '';
    }

    return [
        'day_index' => hr_attendance_report_day_index($workDate),
        'day_name' => hr_attendance_report_day_name($workDate),
        'work_date' => $workDate,
        'shift_label' => $shiftLabel,
        'shift_status' => $status,
        'shift_start' => $shiftStart,
        'shift_end' => $shiftEnd,
        'morning_delay' => $morningDelay,
        'evening_delay' => $eveningDelay,
        'leave_start' => $leaveStart,
        'leave_end' => $leaveEnd,
        'entry_time' => $entry,
        'exit_time' => $exit,
        'overtime' => $overtime,
        'is_weekend' => $isWeekend && !$isHolidayShift,
        'is_holiday' => $isHolidayShift,
        'is_status_row' => $status !== 'normal' && $status !== 'absent',
    ];
}

/** @return list<array{id:int, name_ar:string}> */
function hr_employee_attendance_report_department_options(PDO $pdo): array
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

/**
 * @return list<array{id:int, emp_code:string, name_ar:string, department_id:int, dept_name:string}>
 */
function hr_employee_attendance_report_employee_options(PDO $pdo): array
{
    hr_attendance_ensure_schema($pdo);
    hr_employee_ensure_schema($pdo);
    hr_department_ensure_schema($pdo);

    try {
        $st = $pdo->query(
            'SELECT DISTINCT e.id, e.emp_code, e.name_ar, e.department_id,
                    COALESCE(d.name_ar, NULLIF(TRIM(e.department), \'\'), \'—\') AS dept_name
             FROM hr_att_punch p
             INNER JOIN hr_employee e ON e.id = p.employee_id
             LEFT JOIN hr_department d ON d.id = e.department_id
             WHERE p.employee_id IS NOT NULL AND p.employee_id > 0
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

/**
 * @return array<int, array<string, array<int, list<array<string,mixed>>>>>
 */
function hr_employee_attendance_report_group_punches(
    PDO $pdo,
    string $dateFrom,
    string $dateTo,
    int $departmentId = 0,
    int $employeeId = 0
): array {
    hr_attendance_ensure_schema($pdo);

    $sql = 'SELECT p.punch_time, p.punch_type, p.verify_code, p.badge_number,
                   e.id AS employee_id, e.emp_code, e.name_ar AS emp_name, e.department_id,
                   COALESCE(d.name_ar, NULLIF(TRIM(e.department), \'\'), \'—\') AS dept_name
            FROM hr_att_punch p
            INNER JOIN hr_employee e ON e.id = p.employee_id
            LEFT JOIN hr_department d ON d.id = e.department_id
            WHERE p.employee_id IS NOT NULL AND p.employee_id > 0
              AND p.punch_time >= ? AND p.punch_time <= ?';
    $params = [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'];

    if ($departmentId > 0) {
        $sql .= ' AND e.department_id = ?';
        $params[] = $departmentId;
    }
    if ($employeeId > 0) {
        $sql .= ' AND e.id = ?';
        $params[] = $employeeId;
    }

    $sql .= ' ORDER BY e.id ASC, p.punch_time ASC';

    $st = $pdo->prepare($sql);
    $st->execute($params);

    $grouped = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $empId = (int) ($row['employee_id'] ?? 0);
        if ($empId < 1) {
            continue;
        }
        $workDate = substr((string) ($row['punch_time'] ?? ''), 0, 10);
        if ($workDate === '') {
            continue;
        }
        if (!isset($grouped[$empId])) {
            $grouped[$empId] = [
                'meta' => [
                    'employee_id' => $empId,
                    'emp_code' => (string) ($row['emp_code'] ?? ''),
                    'emp_name' => (string) ($row['emp_name'] ?? ''),
                    'department_id' => (int) ($row['department_id'] ?? 0),
                    'dept_name' => (string) ($row['dept_name'] ?? '—'),
                ],
                'days' => [],
            ];
        }
        $grouped[$empId]['days'][$workDate][] = $row;
    }

    return $grouped;
}

/**
 * @return array{
 *   shift: array<string,mixed>,
 *   employees: list<array<string,mixed>>,
 *   day_count: int,
 *   employee_count: int
 * }
 */
function hr_employee_attendance_report_build(
    PDO $pdo,
    string $dateFrom,
    string $dateTo,
    int $departmentId = 0,
    int $employeeId = 0
): array {
    hr_attendance_ensure_schema($pdo);

    $empty = [
        'shift' => hr_attendance_report_shift_settings($pdo),
        'employees' => [],
        'day_count' => 0,
        'employee_count' => 0,
    ];

    if ($dateFrom === '' || $dateTo === '' || $dateFrom > $dateTo) {
        return $empty;
    }

    $dates = hr_attendance_report_date_range($dateFrom, $dateTo);
    if ($dates === []) {
        return $empty;
    }

    $shift = hr_attendance_report_shift_settings($pdo);
    $weekendDays = $shift['weekend_days'];
    $grouped = hr_employee_attendance_report_group_punches($pdo, $dateFrom, $dateTo, $departmentId, $employeeId);

    if ($employeeId > 0 && !isset($grouped[$employeeId])) {
        $st = $pdo->prepare(
            'SELECT e.id AS employee_id, e.emp_code, e.name_ar AS emp_name, e.department_id,
                    COALESCE(d.name_ar, NULLIF(TRIM(e.department), \'\'), \'—\') AS dept_name
             FROM hr_employee e
             LEFT JOIN hr_department d ON d.id = e.department_id
             WHERE e.id = ? LIMIT 1'
        );
        $st->execute([$employeeId]);
        $meta = $st->fetch(PDO::FETCH_ASSOC);
        if ($meta) {
            $grouped[$employeeId] = [
                'meta' => [
                    'employee_id' => (int) $meta['employee_id'],
                    'emp_code' => (string) ($meta['emp_code'] ?? ''),
                    'emp_name' => (string) ($meta['emp_name'] ?? ''),
                    'department_id' => (int) ($meta['department_id'] ?? 0),
                    'dept_name' => (string) ($meta['dept_name'] ?? '—'),
                ],
                'days' => [],
            ];
        }
    }

    $employees = [];
    foreach ($grouped as $block) {
        $meta = $block['meta'];
        $empId = (int) ($meta['employee_id'] ?? 0);
        $dayRows = [];
        foreach ($dates as $workDate) {
            $punches = $block['days'][$workDate] ?? [];
            $times = hr_attendance_report_day_punch_times($punches);
            $scheduledShift = hr_employee_schedule_resolve_shift($pdo, $empId, $workDate);
            $dayShift = $scheduledShift ?? hr_attendance_report_shift_settings($pdo);
            // عند وجود دوام معرّف للموظف نعتمد الشفت المختار ولا نفرض عطلة أسبوعية عامة
            $isWeekend = $scheduledShift === null
                && hr_attendance_report_is_weekend($workDate, $weekendDays);
            $dayRows[] = hr_attendance_report_build_day_row($workDate, $times, $dayShift, $isWeekend);
        }

        $employees[] = [
            'employee_id' => (int) ($meta['employee_id'] ?? 0),
            'emp_code' => (string) ($meta['emp_code'] ?? ''),
            'emp_name' => (string) ($meta['emp_name'] ?? ''),
            'dept_name' => (string) ($meta['dept_name'] ?? '—'),
            'days' => $dayRows,
        ];
    }

    usort($employees, static function (array $a, array $b): int {
        $cmp = strcmp((string) ($a['dept_name'] ?? ''), (string) ($b['dept_name'] ?? ''));
        if ($cmp !== 0) {
            return $cmp;
        }

        return strcmp((string) ($a['emp_name'] ?? ''), (string) ($b['emp_name'] ?? ''));
    });

    return [
        'shift' => $shift,
        'employees' => $employees,
        'day_count' => count($dates),
        'employee_count' => count($employees),
    ];
}

function hr_attendance_report_format_time_cell(?string $time): string
{
    if ($time === null || trim($time) === '') {
        return '';
    }

    return trim($time);
}
