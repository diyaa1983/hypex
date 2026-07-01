<?php
declare(strict_types=1);

require_once app_path('includes/hr_attendance.php');

function hr_attendance_shift_ensure_schema(PDO $pdo): void
{
    hr_attendance_ensure_schema($pdo);

    try {
        $pdo->query('SELECT id FROM hr_att_shift LIMIT 1');
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
        sql_migration_run_file($pdo, 'database/migrations/196_hr_attendance_shifts.sql');
    } catch (Throwable $e) {
        try {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS hr_att_shift (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    shift_code VARCHAR(20) NOT NULL,
                    shift_name VARCHAR(80) NOT NULL,
                    start_time TIME NOT NULL,
                    end_time TIME NOT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uk_hr_att_shift_code (shift_code)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        } catch (Throwable $e2) {
            // ignored
        }
    }
}

function hr_attendance_shift_next_code(PDO $pdo): string
{
    hr_attendance_shift_ensure_schema($pdo);
    $maxNum = 0;
    try {
        $maxNum = (int) $pdo->query(
            "SELECT COALESCE(MAX(CAST(shift_code AS UNSIGNED)), 0) FROM hr_att_shift
             WHERE shift_code REGEXP '^[0-9]+$'"
        )->fetchColumn();
    } catch (Throwable $e) {
        $maxNum = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM hr_att_shift')->fetchColumn();
    }

    return (string) ($maxNum + 1);
}

function hr_attendance_shift_parse_code(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '' || !ctype_digit($raw)) {
        throw new RuntimeException('رقم الشفت يجب أن يكون رقماً صحيحاً فقط.');
    }
    $num = (int) $raw;
    if ($num < 1) {
        throw new RuntimeException('رقم الشفت يجب أن يكون أكبر من صفر.');
    }

    return (string) $num;
}

function hr_attendance_shift_code_taken(PDO $pdo, string $code, int $excludeId = 0): bool
{
    $code = hr_attendance_shift_parse_code($code);
    hr_attendance_shift_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT id FROM hr_att_shift WHERE shift_code = ? AND id <> ? LIMIT 1');
    $st->execute([$code, $excludeId]);

    return (bool) $st->fetchColumn();
}

function hr_attendance_shift_format_time(?string $time): string
{
    if ($time === null || trim($time) === '') {
        return '';
    }

    return substr(trim($time), 0, 5);
}

function hr_attendance_shift_parse_time_input(string $raw, string $label): string
{
    $raw = trim($raw);
    if ($raw === '') {
        throw new RuntimeException($label . ' مطلوب.');
    }
    if (!preg_match('/^\d{1,2}:\d{2}$/', $raw)) {
        throw new RuntimeException($label . ' بصيغة ساعة:دقيقة مثل 07:00 أو 14:30.');
    }
    [$h, $m] = array_map('intval', explode(':', $raw, 2));
    if ($h < 0 || $h > 23 || $m < 0 || $m > 59) {
        throw new RuntimeException($label . ' غير صالح.');
    }

    return sprintf('%02d:%02d', $h, $m);
}

function hr_attendance_shift_is_holiday(string $start, string $end): bool
{
    $start = hr_attendance_shift_format_time($start);
    $end = hr_attendance_shift_format_time($end);

    return $start === '00:00' && $end === '00:00';
}

function hr_attendance_shift_name_taken(PDO $pdo, string $name, int $excludeId = 0): bool
{
    $name = trim($name);
    if ($name === '') {
        return false;
    }
    hr_attendance_shift_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT id FROM hr_att_shift WHERE shift_name = ? AND id <> ? LIMIT 1');
    $st->execute([$name, $excludeId]);

    return (bool) $st->fetchColumn();
}

/**
 * @return array{code:string,name:string,start:string,end:string,active:int}
 */
function hr_attendance_shift_parse_row(PDO $pdo, array $row, int $id): array
{
    $name = trim((string) ($row['shift_name'] ?? ''));
    $start = hr_attendance_shift_parse_time_input((string) ($row['start_time'] ?? ''), 'وقت بداية الشفت');
    $end = hr_attendance_shift_parse_time_input((string) ($row['end_time'] ?? ''), 'وقت نهاية الشفت');
    $isActive = !empty($row['is_active']) ? 1 : 0;

    if ($name === '') {
        throw new RuntimeException('اسم الشفت مطلوب.');
    }
    if ($start === $end && !hr_attendance_shift_is_holiday($start, $end)) {
        throw new RuntimeException('وقت البداية يجب أن يختلف عن وقت النهاية (ما عدا العطل: 00:00 — 00:00).');
    }

    $code = '';
    if ($id > 0) {
        $stCur = $pdo->prepare('SELECT shift_code FROM hr_att_shift WHERE id = ? LIMIT 1');
        $stCur->execute([$id]);
        $cur = $stCur->fetch(PDO::FETCH_ASSOC);
        if (!$cur) {
            throw new RuntimeException('الشفت غير موجود.');
        }
        $code = hr_attendance_shift_parse_code((string) ($cur['shift_code'] ?? ''));
    } else {
        $rawCode = trim((string) ($row['shift_code'] ?? ''));
        $code = $rawCode !== ''
            ? hr_attendance_shift_parse_code($rawCode)
            : hr_attendance_shift_next_code($pdo);
        if (!array_key_exists('is_active', $row)) {
            $isActive = 1;
        }
    }

    if (hr_attendance_shift_code_taken($pdo, $code, $id)) {
        throw new RuntimeException('رقم الشفت ' . $code . ' مستخدم مسبقاً.');
    }

    return [
        'code' => $code,
        'name' => $name,
        'start' => $start . ':00',
        'end' => $end . ':00',
        'active' => $isActive,
    ];
}

/** @return list<array<string,mixed>> */
function hr_attendance_shift_list(PDO $pdo): array
{
    hr_attendance_shift_ensure_schema($pdo);
    $st = $pdo->query(
        'SELECT id, shift_code, shift_name, start_time, end_time, is_active
         FROM hr_att_shift
         ORDER BY CAST(shift_code AS UNSIGNED) ASC, id ASC'
    );

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return list<array<string,mixed>> */
function hr_attendance_shift_list_active(PDO $pdo): array
{
    hr_attendance_shift_ensure_schema($pdo);
    $st = $pdo->query(
        'SELECT id, shift_code, shift_name, start_time, end_time, is_active
         FROM hr_att_shift
         WHERE is_active = 1
         ORDER BY CAST(shift_code AS UNSIGNED) ASC, id ASC'
    );

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @param array<string,mixed> $row
 * @return array{code:string,name:string,start:string,end:string,label:string,is_holiday:bool}
 */
function hr_attendance_shift_to_settings(array $row): array
{
    $code = trim((string) ($row['shift_code'] ?? '1'));
    if ($code === '' || !ctype_digit($code)) {
        $code = '1';
    }
    $name = trim((string) ($row['shift_name'] ?? ''));
    $start = hr_attendance_shift_format_time(isset($row['start_time']) ? (string) $row['start_time'] : null);
    $end = hr_attendance_shift_format_time(isset($row['end_time']) ? (string) $row['end_time'] : null);
    $isHoliday = hr_attendance_shift_is_holiday($start, $end);

    if ($isHoliday) {
        $start = '00:00';
        $end = '00:00';
    } else {
        if ($start === '') {
            $start = '07:00';
        }
        if ($end === '') {
            $end = '15:00';
        }
    }

    $label = $isHoliday
        ? ($name !== '' ? $name : ('شفت ' . $code))
        : ($name !== ''
            ? $name . ' (' . $start . '-' . $end . ')'
            : $code . ' (' . $start . '-' . $end . ')');

    return [
        'code' => $code,
        'name' => $name,
        'start' => $start,
        'end' => $end,
        'label' => $label,
        'is_holiday' => $isHoliday,
    ];
}

/**
 * @return array{code:string,name:string,start:string,end:string,weekend_days:list<int>,label:string}|null
 */
function hr_attendance_shift_default_settings(PDO $pdo): ?array
{
    hr_attendance_shift_ensure_schema($pdo);
    $st = $pdo->query(
        'SELECT shift_code, shift_name, start_time, end_time
         FROM hr_att_shift
         WHERE is_active = 1
         ORDER BY CAST(shift_code AS UNSIGNED) ASC, id ASC
         LIMIT 1'
    );
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $settings = hr_attendance_shift_to_settings($row);
    $settings['weekend_days'] = [5, 6];

    return $settings;
}

/**
 * @return array{can_delete:bool,usage_count:int,message:string}
 */
function hr_attendance_shift_delete_check(PDO $pdo, int $shiftId): array
{
    if ($shiftId < 1) {
        return ['can_delete' => false, 'usage_count' => 0, 'message' => 'معرّف غير صالح.'];
    }

    return ['can_delete' => true, 'usage_count' => 0, 'message' => ''];
}
