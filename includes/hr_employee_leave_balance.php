<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');
require_once app_path('includes/hr_leave_type.php');

function hr_employee_leave_balance_ensure_schema(PDO $pdo): void
{
    hr_leave_type_ensure_schema($pdo);

    try {
        $pdo->query('SELECT id FROM hr_employee_leave_balance LIMIT 1');

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
        sql_migration_run_file($pdo, 'database/migrations/202_hr_employee_leave_balance.sql');
    } catch (Throwable $e) {
        // ignored
    }
}

function hr_employee_leave_balance_remaining(float $opening, float $entitled, float $taken): float
{
    return round($opening + $entitled - $taken, 2);
}

/** @return array{from:string,to:string} */
function hr_employee_leave_balance_default_period(): array
{
    $year = (int) date('Y');

    return [
        'from' => $year . '-01-01',
        'to' => $year . '-12-31',
    ];
}

function hr_employee_leave_balance_inclusive_days(string $dateFrom, string $dateTo): int
{
    try {
        $from = new DateTimeImmutable($dateFrom);
        $to = new DateTimeImmutable($dateTo);
    } catch (Throwable $e) {
        return 0;
    }
    if ($to < $from) {
        return 0;
    }

    return $from->diff($to)->days + 1;
}

function hr_employee_leave_entitled_for_period(
    float $annualDays,
    bool $prorateYearly,
    string $periodFrom,
    string $periodTo,
    ?string $hireDate
): float {
    $annualDays = round(max(0.0, $annualDays), 2);
    if ($annualDays <= 0) {
        return 0.0;
    }
    if (!$prorateYearly) {
        return $annualDays;
    }

    $totalPeriodDays = hr_employee_leave_balance_inclusive_days($periodFrom, $periodTo);
    if ($totalPeriodDays < 1) {
        return 0.0;
    }

    $hireIso = $hireDate !== null ? (parse_date_to_iso(trim($hireDate)) ?? '') : '';
    if ($hireIso === '') {
        return $annualDays;
    }

    try {
        $periodStart = new DateTimeImmutable($periodFrom);
        $periodEnd = new DateTimeImmutable($periodTo);
        $hire = new DateTimeImmutable($hireIso);
    } catch (Throwable $e) {
        return $annualDays;
    }

    if ($hire > $periodEnd) {
        return 0.0;
    }
    if ($hire <= $periodStart) {
        return $annualDays;
    }

    $eligibleDays = hr_employee_leave_balance_inclusive_days($hire->format('Y-m-d'), $periodTo);

    return round($annualDays * ($eligibleDays / $totalPeriodDays), 2);
}

/**
 * @return array<int,float>
 */
function hr_employee_leave_taken_by_type_in_period(
    PDO $pdo,
    int $employeeId,
    string $periodFrom,
    string $periodTo
): array {
    hr_employee_leave_balance_ensure_schema($pdo);
    if ($employeeId < 1 || $periodFrom === '' || $periodTo === '') {
        return [];
    }

    try {
        $st = $pdo->prepare(
            'SELECT leave_type_id, COALESCE(SUM(days_count), 0) AS taken_days
             FROM hr_employee_leave
             WHERE employee_id = ?
               AND is_posted = 1
               AND date_to >= ? AND date_from <= ?
             GROUP BY leave_type_id'
        );
        $st->execute([$employeeId, $periodFrom, $periodTo]);
    } catch (Throwable $e) {
        return [];
    }

    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $typeId = (int) ($row['leave_type_id'] ?? 0);
        if ($typeId > 0) {
            $map[$typeId] = round((float) ($row['taken_days'] ?? 0), 2);
        }
    }

    return $map;
}

/**
 * @return list<array<string,mixed>>
 */
function hr_employee_leave_balance_rows_for_employee(
    PDO $pdo,
    int $employeeId,
    string $periodFrom,
    string $periodTo
): array {
    hr_employee_leave_balance_ensure_schema($pdo);
    if ($employeeId < 1) {
        return [];
    }

    if ($periodFrom === '' || $periodTo === '' || $periodFrom > $periodTo) {
        $defaults = hr_employee_leave_balance_default_period();
        $periodFrom = $defaults['from'];
        $periodTo = $defaults['to'];
    }

    $hireDate = null;
    $stEmp = $pdo->prepare('SELECT hire_date FROM hr_employee WHERE id = ? LIMIT 1');
    $stEmp->execute([$employeeId]);
    $hireRaw = $stEmp->fetchColumn();
    if ($hireRaw !== false && trim((string) $hireRaw) !== '') {
        $hireDate = (string) $hireRaw;
    }

    $types = hr_leave_type_list($pdo, true);
    $existing = [];
    $st = $pdo->prepare(
        'SELECT b.*, t.leave_code, t.name_ar AS type_name, t.default_days
         FROM hr_employee_leave_balance b
         INNER JOIN hr_leave_type t ON t.id = b.leave_type_id
         WHERE b.employee_id = ?'
    );
    $st->execute([$employeeId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $existing[(int) ($row['leave_type_id'] ?? 0)] = $row;
    }

    $takenMap = hr_employee_leave_taken_by_type_in_period($pdo, $employeeId, $periodFrom, $periodTo);

    $rows = [];
    foreach ($types as $type) {
        $typeId = (int) ($type['id'] ?? 0);
        if ($typeId < 1) {
            continue;
        }
        $row = $existing[$typeId] ?? null;
        $opening = $row ? (float) ($row['opening_balance'] ?? 0) : 0.0;
        $annualDays = (float) ($type['default_days'] ?? 0);
        $prorateYearly = !empty($type['prorate_yearly']);
        $entitled = hr_employee_leave_entitled_for_period(
            $annualDays,
            $prorateYearly,
            $periodFrom,
            $periodTo,
            $hireDate
        );
        $taken = (float) ($takenMap[$typeId] ?? 0.0);
        $rows[] = [
            'leave_type_id' => $typeId,
            'leave_code' => (string) ($type['leave_code'] ?? ''),
            'type_name' => (string) ($type['name_ar'] ?? ''),
            'prorate_yearly' => $prorateYearly ? 1 : 0,
            'annual_days' => $annualDays,
            'opening_balance' => $opening,
            'entitled_balance' => $entitled,
            'taken_days' => $taken,
            'remaining' => hr_employee_leave_balance_remaining($opening, $entitled, $taken),
        ];
    }

    return $rows;
}

/**
 * صفوف العرض الافتراضية (بدون موظف) — أنواع الإجازات مع أرصدة صفرية.
 *
 * @return list<array<string,mixed>>
 */
function hr_employee_leave_balance_rows_template(PDO $pdo): array
{
    hr_employee_leave_balance_ensure_schema($pdo);

    $rows = [];
    foreach (hr_leave_type_list($pdo, true) as $type) {
        $typeId = (int) ($type['id'] ?? 0);
        if ($typeId < 1) {
            continue;
        }
        $rows[] = [
            'leave_type_id' => $typeId,
            'leave_code' => (string) ($type['leave_code'] ?? ''),
            'type_name' => (string) ($type['name_ar'] ?? ''),
            'prorate_yearly' => !empty($type['prorate_yearly']) ? 1 : 0,
            'annual_days' => (float) ($type['default_days'] ?? 0),
            'opening_balance' => 0.0,
            'entitled_balance' => 0.0,
            'taken_days' => 0.0,
            'remaining' => 0.0,
        ];
    }

    return $rows;
}

function hr_employee_leave_balance_save(PDO $pdo, int $employeeId, array $balances): void
{
    hr_employee_leave_balance_ensure_schema($pdo);
    if ($employeeId < 1) {
        throw new RuntimeException('اختر الموظف.');
    }

    $st = $pdo->prepare(
        'INSERT INTO hr_employee_leave_balance
            (employee_id, leave_type_id, opening_balance, entitled_balance, taken_days)
         VALUES (?, ?, ?, ?, COALESCE((SELECT taken_days FROM hr_employee_leave_balance x
                 WHERE x.employee_id = ? AND x.leave_type_id = ? LIMIT 1), 0))
         ON DUPLICATE KEY UPDATE opening_balance = VALUES(opening_balance),
                                 entitled_balance = VALUES(entitled_balance)'
    );

    $saved = 0;
    foreach ($balances as $typeId => $vals) {
        if (!is_array($vals)) {
            continue;
        }
        $typeId = (int) $typeId;
        if ($typeId < 1) {
            continue;
        }
        $opening = round((float) str_replace(',', '.', (string) ($vals['opening_balance'] ?? '0')), 2);
        $entitled = round((float) str_replace(',', '.', (string) ($vals['entitled_balance'] ?? '0')), 2);
        if ($opening < 0 || $entitled < 0) {
            throw new RuntimeException('رصيد الإجازة لا يمكن أن يكون سالباً.');
        }
        $st->execute([$employeeId, $typeId, $opening, $entitled, $employeeId, $typeId]);
        $saved++;
    }

    if ($saved < 1) {
        throw new RuntimeException('لا توجد بيانات رصيد للحفظ.');
    }
}

/** @return array<string,mixed>|null */
function hr_employee_leave_balance_get(PDO $pdo, int $employeeId, int $leaveTypeId): ?array
{
    hr_employee_leave_balance_ensure_schema($pdo);
    if ($employeeId < 1 || $leaveTypeId < 1) {
        return null;
    }
    $st = $pdo->prepare(
        'SELECT * FROM hr_employee_leave_balance WHERE employee_id = ? AND leave_type_id = ? LIMIT 1'
    );
    $st->execute([$employeeId, $leaveTypeId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function hr_employee_leave_balance_adjust_taken(
    PDO $pdo,
    int $employeeId,
    int $leaveTypeId,
    float $deltaDays,
    ?string $leaveDateFrom = null
): void {
    hr_employee_leave_balance_ensure_schema($pdo);
    if ($employeeId < 1 || $leaveTypeId < 1) {
        throw new RuntimeException('بيانات الرصيد غير مكتملة.');
    }

    $bal = hr_employee_leave_balance_get($pdo, $employeeId, $leaveTypeId);

    $stType = $pdo->prepare(
        'SELECT default_days, prorate_yearly FROM hr_leave_type WHERE id = ? LIMIT 1'
    );
    $stType->execute([$leaveTypeId]);
    $typeRow = $stType->fetch(PDO::FETCH_ASSOC) ?: [];

    $stEmp = $pdo->prepare('SELECT hire_date FROM hr_employee WHERE id = ? LIMIT 1');
    $stEmp->execute([$employeeId]);
    $hireDate = $stEmp->fetchColumn();
    $hireIso = $hireDate !== false && trim((string) $hireDate) !== '' ? (string) $hireDate : null;

    $periodFrom = $leaveDateFrom !== null && strlen($leaveDateFrom) >= 4
        ? substr($leaveDateFrom, 0, 4) . '-01-01'
        : hr_employee_leave_balance_default_period()['from'];
    $periodTo = substr($periodFrom, 0, 4) . '-12-31';

    $annualDays = (float) ($typeRow['default_days'] ?? 0);
    $entitled = hr_employee_leave_entitled_for_period(
        $annualDays,
        !empty($typeRow['prorate_yearly']),
        $periodFrom,
        $periodTo,
        $hireIso
    );

    if (!$bal) {
        $pdo->prepare(
            'INSERT INTO hr_employee_leave_balance (employee_id, leave_type_id, opening_balance, entitled_balance, taken_days)
             VALUES (?, ?, 0, ?, 0)'
        )->execute([$employeeId, $leaveTypeId, $entitled]);
        $bal = hr_employee_leave_balance_get($pdo, $employeeId, $leaveTypeId);
    }

    $opening = (float) ($bal['opening_balance'] ?? 0);
    $takenMap = hr_employee_leave_taken_by_type_in_period($pdo, $employeeId, $periodFrom, $periodTo);
    $taken = (float) ($takenMap[$leaveTypeId] ?? 0.0);
    $newTaken = round($taken + $deltaDays, 2);
    if ($newTaken < 0) {
        throw new RuntimeException('رصيد الإجازة المأخوذ غير صالح.');
    }
    if ($deltaDays > 0) {
        $remaining = hr_employee_leave_balance_remaining($opening, $entitled, $taken);
        if ($deltaDays > $remaining + 0.001) {
            throw new RuntimeException(
                'رصيد الإجازة غير كافٍ. المتبقي: ' . number_format($remaining, 2, '.', '')
            );
        }
    }

    $pdo->prepare(
        'UPDATE hr_employee_leave_balance
         SET entitled_balance = ?, taken_days = ?
         WHERE employee_id = ? AND leave_type_id = ?'
    )->execute([$entitled, $newTaken, $employeeId, $leaveTypeId]);
}

function hr_employee_leave_balance_period_for_date(string $leaveDateFrom): array
{
    $periodFrom = $leaveDateFrom !== '' && strlen($leaveDateFrom) >= 4
        ? substr($leaveDateFrom, 0, 4) . '-01-01'
        : hr_employee_leave_balance_default_period()['from'];
    $periodTo = substr($periodFrom, 0, 4) . '-12-31';

    return ['from' => $periodFrom, 'to' => $periodTo];
}

/**
 * إعادة حساب أيام الإجازة المأخوذة من السندات المرحّلة فقط.
 */
function hr_employee_leave_balance_refresh_taken(
    PDO $pdo,
    int $employeeId,
    int $leaveTypeId,
    string $periodFrom,
    string $periodTo
): void {
    hr_employee_leave_balance_ensure_schema($pdo);
    if ($employeeId < 1 || $leaveTypeId < 1) {
        throw new RuntimeException('بيانات الرصيد غير مكتملة.');
    }

    $stType = $pdo->prepare(
        'SELECT default_days, prorate_yearly FROM hr_leave_type WHERE id = ? LIMIT 1'
    );
    $stType->execute([$leaveTypeId]);
    $typeRow = $stType->fetch(PDO::FETCH_ASSOC) ?: [];

    $stEmp = $pdo->prepare('SELECT hire_date FROM hr_employee WHERE id = ? LIMIT 1');
    $stEmp->execute([$employeeId]);
    $hireDate = $stEmp->fetchColumn();
    $hireIso = $hireDate !== false && trim((string) $hireDate) !== '' ? (string) $hireDate : null;

    $annualDays = (float) ($typeRow['default_days'] ?? 0);
    $entitled = hr_employee_leave_entitled_for_period(
        $annualDays,
        !empty($typeRow['prorate_yearly']),
        $periodFrom,
        $periodTo,
        $hireIso
    );

    $bal = hr_employee_leave_balance_get($pdo, $employeeId, $leaveTypeId);
    if (!$bal) {
        $pdo->prepare(
            'INSERT INTO hr_employee_leave_balance (employee_id, leave_type_id, opening_balance, entitled_balance, taken_days)
             VALUES (?, ?, 0, ?, 0)'
        )->execute([$employeeId, $leaveTypeId, $entitled]);
        $bal = hr_employee_leave_balance_get($pdo, $employeeId, $leaveTypeId);
    }

    $takenMap = hr_employee_leave_taken_by_type_in_period($pdo, $employeeId, $periodFrom, $periodTo);
    $taken = round((float) ($takenMap[$leaveTypeId] ?? 0.0), 2);
    if ($taken < 0) {
        $taken = 0.0;
    }

    $opening = (float) ($bal['opening_balance'] ?? 0);
    $pdo->prepare(
        'UPDATE hr_employee_leave_balance
         SET entitled_balance = ?, taken_days = ?
         WHERE employee_id = ? AND leave_type_id = ?'
    )->execute([$entitled, $taken, $employeeId, $leaveTypeId]);
}

function hr_employee_leave_balance_assert_can_take(
    PDO $pdo,
    int $employeeId,
    int $leaveTypeId,
    float $days,
    string $leaveDateFrom
): void {
    if ($days <= 0) {
        return;
    }

    $period = hr_employee_leave_balance_period_for_date($leaveDateFrom);
    $bal = hr_employee_leave_balance_get($pdo, $employeeId, $leaveTypeId);

    $stType = $pdo->prepare(
        'SELECT default_days, prorate_yearly FROM hr_leave_type WHERE id = ? LIMIT 1'
    );
    $stType->execute([$leaveTypeId]);
    $typeRow = $stType->fetch(PDO::FETCH_ASSOC) ?: [];

    $stEmp = $pdo->prepare('SELECT hire_date FROM hr_employee WHERE id = ? LIMIT 1');
    $stEmp->execute([$employeeId]);
    $hireDate = $stEmp->fetchColumn();
    $hireIso = $hireDate !== false && trim((string) $hireDate) !== '' ? (string) $hireDate : null;

    $annualDays = (float) ($typeRow['default_days'] ?? 0);
    $entitled = hr_employee_leave_entitled_for_period(
        $annualDays,
        !empty($typeRow['prorate_yearly']),
        $period['from'],
        $period['to'],
        $hireIso
    );

    $opening = (float) ($bal['opening_balance'] ?? 0);
    $takenMap = hr_employee_leave_taken_by_type_in_period($pdo, $employeeId, $period['from'], $period['to']);
    $taken = (float) ($takenMap[$leaveTypeId] ?? 0.0);
    $remaining = hr_employee_leave_balance_remaining($opening, $entitled, $taken);
    if ($days > $remaining + 0.001) {
        throw new RuntimeException(
            'رصيد الإجازة غير كافٍ. المتبقي: ' . number_format($remaining, 2, '.', '')
        );
    }
}
