<?php
declare(strict_types=1);

require_once app_path('includes/hr_attendance_shift.php');
require_once app_path('includes/hr_employee_attendance_report.php');

function hr_employee_schedule_ensure_schema(PDO $pdo): void
{
    hr_attendance_shift_ensure_schema($pdo);
    hr_employee_ensure_schema($pdo);

    try {
        $pdo->query('SELECT employee_id FROM hr_att_employee_default_shift LIMIT 1');
        return;
    } catch (Throwable $e) {
        if (
            strpos($e->getMessage(), "doesn't exist") === false
            && strpos($e->getMessage(), 'no such table') === false
            && strpos($e->getMessage(), 'Base table or view not found') === false
        ) {
            return;
        }
    }

    try {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/198_hr_employee_schedule.sql');
    } catch (Throwable $e) {
        // ignored — migration runs on boot
    }
}

/** @return array<int,string> */
function hr_employee_schedule_day_names(): array
{
    return hr_attendance_report_day_names();
}

/** @return list<array<string,mixed>> */
function hr_employee_schedule_shift_options(PDO $pdo): array
{
    hr_attendance_shift_ensure_schema($pdo);
    $rows = hr_attendance_shift_list_active($pdo);

    return array_map(static function (array $r): array {
        $id = (int) ($r['id'] ?? 0);
        $code = (string) ($r['shift_code'] ?? '');
        $name = (string) ($r['shift_name'] ?? '');
        $start = hr_attendance_shift_format_time(isset($r['start_time']) ? (string) $r['start_time'] : null);
        $end = hr_attendance_shift_format_time(isset($r['end_time']) ? (string) $r['end_time'] : null);
        $isHoliday = hr_attendance_shift_is_holiday($start, $end);
        if ($isHoliday) {
            $label = $name !== '' ? $name : ('شفت ' . $code);
            $label .= ' (00:00-00:00)';
        } else {
            $label = $code . ' — ' . ($name !== '' ? $name : 'شفت');
            if ($start !== '' && $end !== '') {
                $label .= ' (' . $start . '-' . $end . ')';
            }
        }

        return [
            'id' => $id,
            'code' => $code,
            'name' => $name,
            'label' => $label !== '—' ? $label : ('شفت #' . $id),
            'is_holiday' => $isHoliday,
        ];
    }, $rows);
}

function hr_employee_schedule_shift_label(PDO $pdo, int $shiftId): string
{
    if ($shiftId < 1) {
        return '—';
    }
    foreach (hr_employee_schedule_shift_options($pdo) as $opt) {
        if ((int) ($opt['id'] ?? 0) === $shiftId) {
            return (string) ($opt['label'] ?? '—');
        }
    }

    return '—';
}

/**
 * @return array{
 *   employee_id:int,
 *   default_shift_id:int,
 *   weekly_periods:list<array<string,mixed>>
 * }
 */
function hr_employee_schedule_load(PDO $pdo, int $employeeId): array
{
    hr_employee_schedule_ensure_schema($pdo);

    $empty = [
        'employee_id' => $employeeId,
        'default_shift_id' => 0,
        'weekly_periods' => [],
    ];

    if ($employeeId < 1) {
        return $empty;
    }

    $stDef = $pdo->prepare('SELECT shift_id FROM hr_att_employee_default_shift WHERE employee_id = ? LIMIT 1');
    $stDef->execute([$employeeId]);
    $defaultShiftId = (int) ($stDef->fetchColumn() ?: 0);

    $stWeek = $pdo->prepare(
        'SELECT id, date_from, date_to
         FROM hr_att_employee_weekly
         WHERE employee_id = ?
         ORDER BY date_from ASC, id ASC'
    );
    $stWeek->execute([$employeeId]);
    $periods = [];

    $stDays = $pdo->prepare(
        'SELECT day_index, shift_id FROM hr_att_employee_weekly_day WHERE weekly_id = ? ORDER BY day_index ASC'
    );

    foreach ($stWeek->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $weeklyId = (int) ($row['id'] ?? 0);
        $days = [];
        for ($i = 0; $i <= 6; $i++) {
            $days[$i] = 0;
        }
        $stDays->execute([$weeklyId]);
        foreach ($stDays->fetchAll(PDO::FETCH_ASSOC) ?: [] as $d) {
            $idx = (int) ($d['day_index'] ?? 0);
            if ($idx >= 0 && $idx <= 6) {
                $days[$idx] = (int) ($d['shift_id'] ?? 0);
            }
        }

        $periods[] = [
            'id' => $weeklyId,
            'date_from' => (string) ($row['date_from'] ?? ''),
            'date_to' => (string) ($row['date_to'] ?? ''),
            'date_from_dmY' => format_date_dmY((string) ($row['date_from'] ?? '')),
            'date_to_dmY' => format_date_dmY((string) ($row['date_to'] ?? '')),
            'days' => $days,
        ];
    }

    return [
        'employee_id' => $employeeId,
        'default_shift_id' => $defaultShiftId,
        'weekly_periods' => $periods,
    ];
}

function hr_employee_schedule_assert_employee(PDO $pdo, int $employeeId): void
{
    if ($employeeId < 1) {
        throw new RuntimeException('اختر الموظف.');
    }
    $st = $pdo->prepare('SELECT id FROM hr_employee WHERE id = ? LIMIT 1');
    $st->execute([$employeeId]);
    if (!$st->fetchColumn()) {
        throw new RuntimeException('الموظف غير موجود.');
    }
}

function hr_employee_schedule_assert_shift(PDO $pdo, int $shiftId): void
{
    if ($shiftId < 1) {
        return;
    }
    $st = $pdo->prepare('SELECT id FROM hr_att_shift WHERE id = ? AND is_active = 1 LIMIT 1');
    $st->execute([$shiftId]);
    if (!$st->fetchColumn()) {
        throw new RuntimeException('الشفت المختار غير موجود أو غير مفعّل.');
    }
}

function hr_employee_schedule_week_days_count(): int
{
    return 7;
}

/** السبت (بداية الأسبوع) لتاريخ ضمن نفس الأسبوع. */
function hr_employee_schedule_week_saturday(string $isoDate): string
{
    $isoDate = parse_date_to_iso(trim($isoDate)) ?? '';
    if ($isoDate === '') {
        throw new RuntimeException('تاريخ غير صالح.');
    }

    $dayIndex = hr_attendance_report_day_index($isoDate);
    if ($dayIndex === 0) {
        return $isoDate;
    }

    $ts = strtotime($isoDate . ' -' . $dayIndex . ' days');
    if ($ts === false) {
        throw new RuntimeException('تاريخ غير صالح.');
    }

    return date('Y-m-d', $ts);
}

function hr_employee_schedule_week_friday(string $saturdayIso): string
{
    $saturdayIso = hr_employee_schedule_week_saturday($saturdayIso);
    $ts = strtotime($saturdayIso . ' +' . (hr_employee_schedule_week_days_count() - 1) . ' days');
    if ($ts === false) {
        throw new RuntimeException('تاريخ غير صالح.');
    }

    return date('Y-m-d', $ts);
}

/**
 * @return array{date_from:string,date_to:string,date_from_dmY:string,date_to_dmY:string}
 */
function hr_employee_schedule_normalize_week_range(string $dateFrom, string $dateTo): array
{
    $dateFrom = parse_date_to_iso(trim($dateFrom)) ?? '';
    $dateTo = parse_date_to_iso(trim($dateTo)) ?? '';
    if ($dateFrom === '' || $dateTo === '') {
        throw new RuntimeException('أدخل تاريخ البداية والنهاية بصيغة صحيحة.');
    }
    if ($dateFrom > $dateTo) {
        throw new RuntimeException('تاريخ البداية يجب أن يكون قبل أو يساوي تاريخ النهاية.');
    }

    if (hr_attendance_report_day_index($dateFrom) !== 0) {
        throw new RuntimeException('تاريخ البداية يجب أن يكون يوم سبت.');
    }
    if (hr_attendance_report_day_index($dateTo) !== 6) {
        throw new RuntimeException('تاريخ النهاية يجب أن يكون يوم جمعة.');
    }

    $expectedEnd = hr_employee_schedule_week_friday($dateFrom);
    if ($dateTo !== $expectedEnd) {
        throw new RuntimeException(
            'مدة الفترة يجب أن تكون 7 أيام (من السبت إلى الجمعة).'
            . ' لتاريخ البداية ' . format_date_dmY($dateFrom)
            . ' يجب أن يكون تاريخ النهاية ' . format_date_dmY($expectedEnd) . '.'
        );
    }

    return [
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'date_from_dmY' => format_date_dmY($dateFrom),
        'date_to_dmY' => format_date_dmY($dateTo),
    ];
}

function hr_employee_schedule_assert_no_week_overlap(
    PDO $pdo,
    int $employeeId,
    string $dateFrom,
    string $dateTo,
    int $excludeWeeklyId = 0
): void {
    $st = $pdo->prepare(
        'SELECT id, date_from, date_to FROM hr_att_employee_weekly
         WHERE employee_id = ? AND id <> ?
           AND date_from <= ? AND date_to >= ?
         LIMIT 1'
    );
    $st->execute([$employeeId, $excludeWeeklyId, $dateTo, $dateFrom]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return;
    }

    throw new RuntimeException(
        'تتداخل هذه الفترة مع أسبوع آخر للموظف: '
        . format_date_dmY((string) ($row['date_from'] ?? ''))
        . ' — '
        . format_date_dmY((string) ($row['date_to'] ?? ''))
        . '.'
    );
}

/**
 * @param list<array<string,mixed>> $weeklyPeriods
 * @return array{date_from:string,date_to:string,date_from_dmY:string,date_to_dmY:string}
 */
function hr_employee_schedule_suggest_next_week(array $weeklyPeriods): array
{
    if ($weeklyPeriods !== []) {
        $last = $weeklyPeriods[count($weeklyPeriods) - 1];
        $lastTo = parse_date_to_iso((string) ($last['date_to'] ?? '')) ?? '';
        if ($lastTo !== '') {
            $ts = strtotime($lastTo . ' +1 day');
            if ($ts !== false) {
                $weekStart = hr_employee_schedule_week_saturday(date('Y-m-d', $ts));

                return [
                    'date_from' => $weekStart,
                    'date_to' => hr_employee_schedule_week_friday($weekStart),
                    'date_from_dmY' => format_date_dmY($weekStart),
                    'date_to_dmY' => format_date_dmY(hr_employee_schedule_week_friday($weekStart)),
                ];
            }
        }
    }

    $weekStart = hr_employee_schedule_week_saturday(date('Y-m-d'));

    return [
        'date_from' => $weekStart,
        'date_to' => hr_employee_schedule_week_friday($weekStart),
        'date_from_dmY' => format_date_dmY($weekStart),
        'date_to_dmY' => format_date_dmY(hr_employee_schedule_week_friday($weekStart)),
    ];
}

function hr_employee_schedule_save_default(PDO $pdo, int $employeeId, int $shiftId): void
{
    hr_employee_schedule_ensure_schema($pdo);
    hr_employee_schedule_assert_employee($pdo, $employeeId);
    hr_employee_schedule_assert_shift($pdo, $shiftId);

    if ($shiftId < 1) {
        $pdo->prepare('DELETE FROM hr_att_employee_default_shift WHERE employee_id = ?')->execute([$employeeId]);
        return;
    }

    $st = $pdo->prepare(
        'INSERT INTO hr_att_employee_default_shift (employee_id, shift_id) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE shift_id = VALUES(shift_id)'
    );
    $st->execute([$employeeId, $shiftId]);
}

/**
 * @param array<int,int|string> $dayShifts keyed 0..6
 */
function hr_employee_schedule_save_weekly(
    PDO $pdo,
    int $employeeId,
    int $weeklyId,
    string $dateFrom,
    string $dateTo,
    array $dayShifts
): int {
    hr_employee_schedule_ensure_schema($pdo);
    hr_employee_schedule_assert_employee($pdo, $employeeId);

    $weekRange = hr_employee_schedule_normalize_week_range($dateFrom, $dateTo);
    $dateFrom = $weekRange['date_from'];
    $dateTo = $weekRange['date_to'];
    hr_employee_schedule_assert_no_week_overlap($pdo, $employeeId, $dateFrom, $dateTo, $weeklyId);

    $parsedDays = [];
    $hasAnyDay = false;
    for ($i = 0; $i <= 6; $i++) {
        $shiftId = (int) ($dayShifts[$i] ?? 0);
        if ($shiftId > 0) {
            hr_employee_schedule_assert_shift($pdo, $shiftId);
            $hasAnyDay = true;
        }
        $parsedDays[$i] = $shiftId > 0 ? $shiftId : null;
    }

    if (!$hasAnyDay) {
        $stDef = $pdo->prepare('SELECT shift_id FROM hr_att_employee_default_shift WHERE employee_id = ? LIMIT 1');
        $stDef->execute([$employeeId]);
        if ((int) ($stDef->fetchColumn() ?: 0) < 1) {
            throw new RuntimeException('عيّن شفتاً ليوم واحد على الأقل، أو عيّن الشفت الافتراضي في التبويب المخصص.');
        }
    }

    if ($weeklyId > 0) {
        $stChk = $pdo->prepare('SELECT id FROM hr_att_employee_weekly WHERE id = ? AND employee_id = ? LIMIT 1');
        $stChk->execute([$weeklyId, $employeeId]);
        if (!$stChk->fetchColumn()) {
            throw new RuntimeException('الفترة الأسبوعية غير موجودة.');
        }
        $st = $pdo->prepare('UPDATE hr_att_employee_weekly SET date_from = ?, date_to = ? WHERE id = ?');
        $st->execute([$dateFrom, $dateTo, $weeklyId]);
        $pdo->prepare('DELETE FROM hr_att_employee_weekly_day WHERE weekly_id = ?')->execute([$weeklyId]);
    } else {
        $st = $pdo->prepare(
            'INSERT INTO hr_att_employee_weekly (employee_id, date_from, date_to) VALUES (?, ?, ?)'
        );
        $st->execute([$employeeId, $dateFrom, $dateTo]);
        $weeklyId = (int) $pdo->lastInsertId();
    }

    $stDay = $pdo->prepare(
        'INSERT INTO hr_att_employee_weekly_day (weekly_id, day_index, shift_id) VALUES (?, ?, ?)'
    );
    foreach ($parsedDays as $dayIndex => $shiftId) {
        if ($shiftId === null) {
            continue;
        }
        $stDay->execute([$weeklyId, (int) $dayIndex, $shiftId]);
    }

    return $weeklyId;
}

function hr_employee_schedule_weekly_covers_date(PDO $pdo, int $employeeId, string $workDate): bool
{
    if ($employeeId < 1 || $workDate === '') {
        return false;
    }

    hr_employee_schedule_ensure_schema($pdo);
    $st = $pdo->prepare(
        'SELECT 1 FROM hr_att_employee_weekly
         WHERE employee_id = ? AND ? BETWEEN date_from AND date_to
         LIMIT 1'
    );
    $st->execute([$employeeId, $workDate]);

    return (bool) $st->fetchColumn();
}

function hr_employee_schedule_delete_weekly(PDO $pdo, int $employeeId, int $weeklyId): void
{
    hr_employee_schedule_ensure_schema($pdo);
    if ($weeklyId < 1) {
        throw new RuntimeException('معرّف الفترة غير صالح.');
    }
    $st = $pdo->prepare('DELETE FROM hr_att_employee_weekly WHERE id = ? AND employee_id = ?');
    $st->execute([$weeklyId, $employeeId]);
    if ($st->rowCount() < 1) {
        throw new RuntimeException('الفترة الأسبوعية غير موجودة.');
    }
}

/**
 * @return array{code:string,name:string,start:string,end:string,label:string}|null
 */
function hr_employee_schedule_resolve_shift(PDO $pdo, int $employeeId, string $workDate): ?array
{
    if ($employeeId < 1 || $workDate === '') {
        return null;
    }

    hr_employee_schedule_ensure_schema($pdo);

    $dayIndex = hr_attendance_report_day_index($workDate);
    $shiftId = 0;

    $stWeek = $pdo->prepare(
        'SELECT id FROM hr_att_employee_weekly
         WHERE employee_id = ? AND ? BETWEEN date_from AND date_to
         ORDER BY id DESC LIMIT 1'
    );
    $stWeek->execute([$employeeId, $workDate]);
    $weeklyId = (int) ($stWeek->fetchColumn() ?: 0);

    if ($weeklyId > 0) {
        $stDay = $pdo->prepare(
            'SELECT shift_id FROM hr_att_employee_weekly_day
             WHERE weekly_id = ? AND day_index = ? LIMIT 1'
        );
        $stDay->execute([$weeklyId, $dayIndex]);
        $shiftId = (int) ($stDay->fetchColumn() ?: 0);
    }

    if ($shiftId < 1) {
        $stDef = $pdo->prepare('SELECT shift_id FROM hr_att_employee_default_shift WHERE employee_id = ? LIMIT 1');
        $stDef->execute([$employeeId]);
        $shiftId = (int) ($stDef->fetchColumn() ?: 0);
    }

    if ($shiftId < 1) {
        return null;
    }

    $stShift = $pdo->prepare(
        'SELECT shift_code, shift_name, start_time, end_time
         FROM hr_att_shift WHERE id = ? AND is_active = 1 LIMIT 1'
    );
    $stShift->execute([$shiftId]);
    $row = $stShift->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    return hr_attendance_shift_to_settings($row);
}
