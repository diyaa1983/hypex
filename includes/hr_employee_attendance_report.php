<?php
declare(strict_types=1);

require_once app_path('includes/hr_attendance.php');
require_once app_path('includes/hr_attendance_shift.php');
require_once app_path('includes/hr_employee_schedule.php');
require_once app_path('includes/hr_schema.php');
require_once app_path('includes/hr_employee_departure.php');
require_once app_path('includes/hr_employee_leave.php');

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

function hr_attendance_report_diff_late_minutes(
    string $scheduled,
    string $actual,
    int $scheduledDayOffset = 0,
    int $actualDayOffset = 0
): int {
    $diff = (hr_attendance_report_time_to_minutes($actual) + $actualDayOffset * 1440)
        - (hr_attendance_report_time_to_minutes($scheduled) + $scheduledDayOffset * 1440);

    return $diff > 0 ? $diff : 0;
}

function hr_attendance_report_diff_early_minutes(
    string $scheduled,
    string $actual,
    int $scheduledDayOffset = 0,
    int $actualDayOffset = 0
): int {
    $diff = (hr_attendance_report_time_to_minutes($scheduled) + $scheduledDayOffset * 1440)
        - (hr_attendance_report_time_to_minutes($actual) + $actualDayOffset * 1440);

    return $diff > 0 ? $diff : 0;
}

function hr_attendance_report_next_date(string $date): string
{
    try {
        return (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
    } catch (Throwable $e) {
        return '';
    }
}

/** أقصى وقت صباحي لبصمة خروج شفت ليلي (نهاية الشفت + 8 ساعات كحد أقصى، أو 12:00). */
function hr_attendance_report_overnight_exit_cutoff_minutes(string $shiftEnd): int
{
    $endMins = hr_attendance_report_time_to_minutes($shiftEnd);

    return min($endMins + 480, 12 * 60);
}

/** أول وقت مسموح لبصمة دخول شفت ليلي (بداية الشفت − ساعتان). */
function hr_attendance_report_overnight_entry_from_minutes(string $shiftStart): int
{
    return max(0, hr_attendance_report_time_to_minutes($shiftStart) - 120);
}

/**
 * شفت يعبر يومين: بصمة دخول في اليوم الأول وبصمة خروج صباح اليوم التالي.
 *
 * @param list<array<string,mixed>> $dayPunches
 * @param list<array<string,mixed>> $nextDayPunches
 * @param array<string,mixed> $shift
 * @return array{entry:?string,exit:?string,leave_start:?string,leave_end:?string}
 */
function hr_attendance_report_overnight_punch_times(
    array $dayPunches,
    array $nextDayPunches,
    array $shift
): array {
    $shiftStart = trim((string) ($shift['start'] ?? ''));
    $shiftEnd = trim((string) ($shift['end'] ?? ''));
    if (!hr_attendance_shift_is_overnight($shiftStart, $shiftEnd)) {
        return hr_attendance_report_day_punch_times($dayPunches);
    }

    $entryFrom = hr_attendance_report_overnight_entry_from_minutes($shiftStart);
    $exitUntil = hr_attendance_report_overnight_exit_cutoff_minutes($shiftEnd);

    $entry = null;
    $exitCandidates = [];

    foreach ($dayPunches as $p) {
        $time = substr((string) ($p['punch_time'] ?? ''), 11, 5);
        if ($time === '') {
            continue;
        }
        $mins = hr_attendance_report_time_to_minutes($time);
        if ($mins >= $entryFrom && $entry === null) {
            $entry = $time;
        }
    }

    foreach ($nextDayPunches as $p) {
        $time = substr((string) ($p['punch_time'] ?? ''), 11, 5);
        if ($time === '') {
            continue;
        }
        $mins = hr_attendance_report_time_to_minutes($time);
        if ($mins <= $exitUntil) {
            $exitCandidates[] = $time;
        }
    }

    return [
        'entry' => $entry,
        'exit' => $exitCandidates !== [] ? $exitCandidates[count($exitCandidates) - 1] : null,
        'leave_start' => null,
        'leave_end' => null,
    ];
}

/**
 * @param list<array<string,mixed>> $dayPunches
 * @param list<array<string,mixed>> $nextDayPunches
 * @param array<string,mixed> $shift
 * @return array{entry:?string,exit:?string,leave_start:?string,leave_end:?string}
 */
function hr_attendance_report_workday_punch_times(
    array $dayPunches,
    array $nextDayPunches,
    array $shift
): array {
    $shiftStart = trim((string) ($shift['start'] ?? ''));
    $shiftEnd = trim((string) ($shift['end'] ?? ''));
    if (hr_attendance_shift_is_overnight($shiftStart, $shiftEnd)) {
        return hr_attendance_report_overnight_punch_times($dayPunches, $nextDayPunches, $shift);
    }

    return hr_attendance_report_day_punch_times($dayPunches);
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
 * استخراج دخول/خروج اليوم من سجلات hr_att_punch (مزامنة CHECKINOUT).
 * أول بصمة = دخول، آخر بصمة = خروج.
 * البصمات الوسطى (عند 4 بصمات أو أكثر) = بداية/نهاية المغادرة الفعلية.
 *
 * @param list<array<string,mixed>> $punches
 * @return array{entry:?string,exit:?string,leave_start:?string,leave_end:?string}
 */
function hr_attendance_report_day_punch_times(array $punches): array
{
    $empty = [
        'entry' => null,
        'exit' => null,
        'leave_start' => null,
        'leave_end' => null,
    ];

    if ($punches === []) {
        return $empty;
    }

    usort($punches, static function (array $a, array $b): int {
        return strcmp((string) ($a['punch_time'] ?? ''), (string) ($b['punch_time'] ?? ''));
    });

    $times = [];
    foreach ($punches as $p) {
        $time = substr((string) ($p['punch_time'] ?? ''), 11, 5);
        if ($time !== '') {
            $times[] = $time;
        }
    }

    if ($times === []) {
        return $empty;
    }

    $entry = $times[0];
    $count = count($times);
    $exit = $count >= 2 ? $times[$count - 1] : null;
    $leaveStart = null;
    $leaveEnd = null;

    if ($count >= 4) {
        $leaveStart = $times[1];
        $leaveEnd = $times[$count - 2];
    } elseif ($count === 3) {
        $leaveStart = $times[1];
        $gapMins = hr_attendance_report_time_to_minutes($times[2])
            - hr_attendance_report_time_to_minutes($times[1]);
        if ($gapMins > 0 && $gapMins <= 240) {
            $leaveEnd = $times[2];
            $exit = null;
        }
    }

    return [
        'entry' => $entry,
        'exit' => $exit,
        'leave_start' => $leaveStart,
        'leave_end' => $leaveEnd,
    ];
}

function hr_attendance_report_shift_name_only(array $shift): string
{
    $name = trim((string) ($shift['name'] ?? ''));
    if ($name !== '') {
        return $name;
    }

    $code = trim((string) ($shift['code'] ?? ''));

    return $code !== '' ? $code : '—';
}

/**
 * @param array{entry:?string,exit:?string,leave_start:?string,leave_end:?string} $times
 * @param array{type_name?:string} $departure
 * @param array{type_name?:string} $vacation
 * @return array<string,mixed>
 */
function hr_attendance_report_build_day_row(
    string $workDate,
    array $times,
    array $shift,
    array $departure = [],
    array $vacation = []
): array {
    $shiftStart = trim((string) ($shift['start'] ?? ''));
    $shiftEnd = trim((string) ($shift['end'] ?? ''));
    $shiftNameOnly = hr_attendance_report_shift_name_only($shift);
    $isHolidayShift = !empty($shift['is_holiday'])
        || ($shiftStart !== '' && $shiftEnd !== '' && hr_attendance_shift_is_holiday($shiftStart, $shiftEnd));
    $hasAssignedShift = $shiftStart !== '' && $shiftEnd !== '' && !$isHolidayShift;
    $isOvernightShift = $hasAssignedShift && hr_attendance_shift_is_overnight($shiftStart, $shiftEnd);
    $exitDayOffset = $isOvernightShift ? 1 : 0;

    $leaveStart = null;
    $leaveEnd = null;
    $departureTypeName = trim((string) ($departure['type_name'] ?? ''));
    $hasPostedDeparture = $departureTypeName !== '';
    if ($hasPostedDeparture) {
        $leaveStart = ($times['leave_start'] ?? null) !== null && (string) ($times['leave_start'] ?? '') !== ''
            ? (string) $times['leave_start']
            : null;
        $leaveEnd = ($times['leave_end'] ?? null) !== null && (string) ($times['leave_end'] ?? '') !== ''
            ? (string) $times['leave_end']
            : null;
    }
    $vacationTypeName = trim((string) ($vacation['type_name'] ?? ''));
    $hasPostedVacation = $vacationTypeName !== '';

    $status = 'normal';
    $shiftLabel = $shiftNameOnly;

    // أولوية العرض: إجازة مرحّلة ← مغادرة مرحّلة ← عطلة من الجدول ← دوام عادي
    if ($hasPostedVacation) {
        $status = 'vacation';
        $shiftLabel = $vacationTypeName;
    } elseif ($hasPostedDeparture) {
        $status = 'leave';
        $shiftLabel = $departureTypeName !== '' ? $departureTypeName : 'مغادرة';
    } elseif ($isHolidayShift) {
        $status = 'holiday';
        $shiftLabel = $shiftNameOnly !== '—' ? $shiftNameOnly : 'عطلة';
    } elseif (!$hasAssignedShift) {
        $shiftLabel = '—';
        if (($times['entry'] ?? null) === null && ($times['exit'] ?? null) === null) {
            $status = 'absent';
        }
    } elseif (($times['entry'] ?? null) === null && ($times['exit'] ?? null) === null) {
        $status = 'absent';
    }

    $entry = $times['entry'] ?? null;
    $exit = $times['exit'] ?? null;

    $morningDelay = '';
    $eveningDelay = '';
    $overtime = '';

    $calcAsWorkDay = $hasAssignedShift && $status !== 'vacation' && $status !== 'holiday';

    if ($calcAsWorkDay && $entry !== null) {
        $morningDelay = hr_attendance_report_minutes_to_hhmm(
            hr_attendance_report_diff_late_minutes($shiftStart, $entry)
        );
    }
    if ($calcAsWorkDay && $exit !== null) {
        $eveningDelay = hr_attendance_report_minutes_to_hhmm(
            hr_attendance_report_diff_early_minutes($shiftEnd, $exit, $exitDayOffset, $exitDayOffset)
        );
        $otMins = hr_attendance_report_diff_late_minutes($shiftEnd, $exit, $exitDayOffset, $exitDayOffset);
        $overtime = hr_attendance_report_minutes_to_hhmm($otMins);
        if ($eveningDelay !== '') {
            $overtime = '';
        }
    }

    if ($status === 'vacation' || $status === 'holiday') {
        $shiftStart = '00:00';
        $shiftEnd = '00:00';
        $entry = null;
        $exit = null;
        $leaveStart = null;
        $leaveEnd = null;
        $morningDelay = '';
        $eveningDelay = '';
        $overtime = '';
    } elseif ($status === 'leave' && $hasPostedDeparture && $isHolidayShift) {
        $shiftStart = '00:00';
        $shiftEnd = '00:00';
        $entry = null;
        $exit = null;
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
        'is_overnight_shift' => $isOvernightShift,
        'overtime' => $overtime,
        'is_weekend' => false,
        'is_holiday' => $status === 'holiday',
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

/** @param list<array{id:int, emp_code:string, name_ar:string, department_id:int, dept_name:string}> $employees */
function hr_employee_attendance_report_picker_json(array $employees): string
{
    $rows = array_map(static function (array $e): array {
        $pname = (string) ($e['name_ar'] ?? '');
        $code = trim((string) ($e['emp_code'] ?? ''));
        $dept = (string) ($e['dept_name'] ?? '');
        $name = $pname !== '' ? $pname : '—';
        $search = $pname . ' ' . $code . ' ' . $dept;

        return [
            'id' => (int) ($e['id'] ?? 0),
            'code' => $code,
            'name_ar' => $name,
            'label' => $name,
            'department_id' => (int) ($e['department_id'] ?? 0),
            'search' => function_exists('mb_strtolower')
                ? mb_strtolower($search, 'UTF-8')
                : strtolower($search),
        ];
    }, $employees);

    $jsonFlags = JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }

    return json_encode($rows, $jsonFlags) ?: '[]';
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

    try {
        $fetchDateTo = (new DateTimeImmutable($dateTo))->modify('+1 day')->format('Y-m-d');
    } catch (Throwable $e) {
        $fetchDateTo = $dateTo;
    }

    $sql = 'SELECT p.punch_time, p.punch_type, p.verify_code, p.badge_number,
                   e.id AS employee_id, e.emp_code, e.name_ar AS emp_name, e.department_id,
                   COALESCE(d.name_ar, NULLIF(TRIM(e.department), \'\'), \'—\') AS dept_name
            FROM hr_att_punch p
            INNER JOIN hr_employee e ON e.id = p.employee_id
            LEFT JOIN hr_department d ON d.id = e.department_id
            WHERE p.employee_id IS NOT NULL AND p.employee_id > 0
              AND p.punch_time >= ? AND p.punch_time <= ?';
    $params = [$dateFrom . ' 00:00:00', $fetchDateTo . ' 23:59:59'];

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
    $grouped = hr_employee_attendance_report_group_punches($pdo, $dateFrom, $dateTo, $departmentId, $employeeId);
    $departuresGrouped = hr_employee_departures_report_grouped($pdo, $dateFrom, $dateTo, $departmentId, $employeeId);
    $leavesGrouped = hr_employee_leaves_report_grouped($pdo, $dateFrom, $dateTo, $departmentId, $employeeId);

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

    foreach ($departuresGrouped as $empId => $daysMap) {
        if (isset($grouped[$empId])) {
            continue;
        }
        $st = $pdo->prepare(
            'SELECT e.id AS employee_id, e.emp_code, e.name_ar AS emp_name, e.department_id,
                    COALESCE(d.name_ar, NULLIF(TRIM(e.department), \'\'), \'—\') AS dept_name
             FROM hr_employee e
             LEFT JOIN hr_department d ON d.id = e.department_id
             WHERE e.id = ? LIMIT 1'
        );
        $st->execute([$empId]);
        $meta = $st->fetch(PDO::FETCH_ASSOC);
        if (!$meta) {
            continue;
        }
        if ($departmentId > 0 && (int) ($meta['department_id'] ?? 0) !== $departmentId) {
            continue;
        }
        $grouped[$empId] = [
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

    foreach ($leavesGrouped as $empId => $daysMap) {
        if (isset($grouped[$empId])) {
            continue;
        }
        $st = $pdo->prepare(
            'SELECT e.id AS employee_id, e.emp_code, e.name_ar AS emp_name, e.department_id,
                    COALESCE(d.name_ar, NULLIF(TRIM(e.department), \'\'), \'—\') AS dept_name
             FROM hr_employee e
             LEFT JOIN hr_department d ON d.id = e.department_id
             WHERE e.id = ? LIMIT 1'
        );
        $st->execute([$empId]);
        $meta = $st->fetch(PDO::FETCH_ASSOC);
        if (!$meta) {
            continue;
        }
        if ($departmentId > 0 && (int) ($meta['department_id'] ?? 0) !== $departmentId) {
            continue;
        }
        $grouped[$empId] = [
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

    $employees = [];
    foreach ($grouped as $block) {
        $meta = $block['meta'];
        $empId = (int) ($meta['employee_id'] ?? 0);
        $dayRows = [];
        foreach ($dates as $workDate) {
            $nextDate = hr_attendance_report_next_date($workDate);
            $punches = $block['days'][$workDate] ?? [];
            $nextPunches = $nextDate !== '' ? ($block['days'][$nextDate] ?? []) : [];
            $scheduledShift = hr_employee_schedule_resolve_shift($pdo, $empId, $workDate);
            $hasSchedule = $scheduledShift !== null;
            $dayShift = $scheduledShift ?? [
                'code' => '',
                'name' => '',
                'start' => '',
                'end' => '',
                'label' => '',
                'is_holiday' => false,
            ];
            $times = hr_attendance_report_workday_punch_times($punches, $nextPunches, $dayShift);
            $departure = $hasSchedule ? ($departuresGrouped[$empId][$workDate] ?? []) : [];
            $vacation = $hasSchedule ? ($leavesGrouped[$empId][$workDate] ?? []) : [];
            $dayRows[] = hr_attendance_report_build_day_row($workDate, $times, $dayShift, $departure, $vacation);
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

/**
 * @param list<array<string,mixed>> $dayRows
 * @return list<array{
 *   year:int,
 *   month:int,
 *   month_key:string,
 *   month_label:string,
 *   days:list<array<string,mixed>>
 * }>
 */
function hr_employee_attendance_report_month_blocks(array $dayRows): array
{
    require_once app_path('includes/acc_period_lock.php');
    $monthNames = acc_period_month_names_ar();
    $months = [];

    foreach ($dayRows as $dayRow) {
        $workDate = (string) ($dayRow['work_date'] ?? '');
        if ($workDate === '') {
            continue;
        }
        $ts = strtotime($workDate);
        if ($ts === false) {
            continue;
        }
        $year = (int) date('Y', $ts);
        $month = (int) date('n', $ts);
        $key = sprintf('%04d-%02d', $year, $month);
        if (!isset($months[$key])) {
            $months[$key] = [
                'year' => $year,
                'month' => $month,
                'month_key' => $key,
                'month_label' => sprintf(
                    '%02d — %s / %s',
                    $month,
                    $monthNames[$month] ?? (string) $month,
                    (string) $year
                ),
                'days' => [],
            ];
        }
        $months[$key]['days'][] = $dayRow;
    }

    ksort($months);

    return array_values($months);
}

function hr_attendance_report_format_time_cell(?string $time): string
{
    if ($time === null || trim($time) === '') {
        return '';
    }

    return trim($time);
}
