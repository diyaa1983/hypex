<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');

function hr_employee_advance_ensure_schema(PDO $pdo): void
{
    hr_employee_ensure_schema($pdo);

    try {
        $pdo->query('SELECT id FROM hr_employee_advance LIMIT 1');
        $pdo->query('SELECT id FROM hr_salary_advance_deduction LIMIT 1');

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
        sql_migration_run_file($pdo, 'database/migrations/099_hr_employee_advances.sql');
    } catch (Throwable $e) {
        // ignored
    }
}

function hr_employee_advance_next_code(PDO $pdo): string
{
    hr_employee_advance_ensure_schema($pdo);
    try {
        $max = (int) $pdo->query(
            "SELECT COALESCE(MAX(CAST(advance_code AS UNSIGNED)), 0) FROM hr_employee_advance
             WHERE advance_code REGEXP '^[0-9]+$'"
        )->fetchColumn();
        $maxNum = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM hr_employee_advance')->fetchColumn();

        return (string) max($max, $maxNum) + 1;
    } catch (Throwable $e) {
        return '1';
    }
}

function hr_employee_advance_type_label(string $type): string
{
    return $type === 'long' ? 'سلفة طويلة' : 'سلفة لمرة واحدة';
}

function hr_employee_advance_status_label(string $status): string
{
    return match ($status) {
        'completed' => 'مكتملة',
        'cancelled' => 'ملغاة',
        default => 'فعّالة',
    };
}

/** @return array{year:int, month:int} */
function hr_employee_advance_month_from_date(string $isoDate): array
{
    $isoDate = trim($isoDate);
    if ($isoDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $isoDate)) {
        return ['year' => 0, 'month' => 0];
    }

    return [
        'year' => (int) substr($isoDate, 0, 4),
        'month' => (int) substr($isoDate, 5, 2),
    ];
}

function hr_employee_advance_month_count(string $startIso, string $endIso): int
{
    $s = hr_employee_advance_month_from_date($startIso);
    $e = hr_employee_advance_month_from_date($endIso);
    if ($s['year'] < 1 || $s['month'] < 1 || $e['year'] < 1 || $e['month'] < 1) {
        return 1;
    }

    return max(1, ($e['year'] - $s['year']) * 12 + ($e['month'] - $s['month']) + 1);
}

function hr_employee_advance_month_in_range(int $year, int $month, string $startIso, string $endIso): bool
{
    $s = hr_employee_advance_month_from_date($startIso);
    $e = hr_employee_advance_month_from_date($endIso);
    if ($s['year'] < 1 || $e['year'] < 1) {
        return false;
    }

    $cur = $year * 12 + $month;
    $from = $s['year'] * 12 + $s['month'];
    $to = $e['year'] * 12 + $e['month'];

    return $cur >= $from && $cur <= $to;
}

function hr_employee_advance_installment_amount(float $total, int $months): float
{
    return round($total / max(1, $months), 3);
}

/**
 * @return array{
 *   advance_type:string,
 *   total_amount:float,
 *   start_date:string,
 *   end_date:?string,
 *   employee_id:int,
 *   notes:?string
 * }
 */
function hr_employee_advance_parse_row(array $row): array
{
    $type = (string) ($row['advance_type'] ?? '');
    if (!in_array($type, ['once', 'long'], true)) {
        throw new RuntimeException('حدد نوع السلفة: لمرة واحدة أو طويلة.');
    }

    $employeeId = (int) ($row['employee_id'] ?? 0);
    if ($employeeId < 1) {
        throw new RuntimeException('اختر الموظف.');
    }

    $amount = round((float) str_replace(',', '', (string) ($row['total_amount'] ?? '0')), 3);
    if ($amount <= 0) {
        throw new RuntimeException('مبلغ السلفة يجب أن يكون أكبر من صفر.');
    }

    $notes = trim((string) ($row['notes'] ?? ''));

    if ($type === 'once') {
        $deductIso = parse_date_to_iso(trim((string) ($row['deduct_date'] ?? '')));
        if ($deductIso === null) {
            throw new RuntimeException('أدخل تاريخ شهر الاقتطاع (يوم-شهر-سنة).');
        }

        return [
            'advance_type' => 'once',
            'total_amount' => $amount,
            'start_date' => $deductIso,
            'end_date' => $deductIso,
            'employee_id' => $employeeId,
            'notes' => $notes !== '' ? $notes : null,
        ];
    }

    $startIso = parse_date_to_iso(trim((string) ($row['start_date'] ?? '')));
    $endIso = parse_date_to_iso(trim((string) ($row['end_date'] ?? '')));
    if ($startIso === null || $endIso === null) {
        throw new RuntimeException('أدخل تاريخ بداية ونهاية السلفة الطويلة.');
    }
    if ($endIso < $startIso) {
        throw new RuntimeException('تاريخ النهاية يجب أن يكون بعد تاريخ البداية أو مساوياً له.');
    }

    return [
        'advance_type' => 'long',
        'total_amount' => $amount,
        'start_date' => $startIso,
        'end_date' => $endIso,
        'employee_id' => $employeeId,
        'notes' => $notes !== '' ? $notes : null,
    ];
}

/** @return array{can_delete:bool, message:string} */
function hr_employee_advance_delete_check(PDO $pdo, int $advanceId): array
{
    hr_employee_advance_ensure_schema($pdo);
    if ($advanceId < 1) {
        return ['can_delete' => false, 'message' => 'سلفة غير موجودة.'];
    }

    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM hr_salary_advance_deduction WHERE advance_id = ?');
        $st->execute([$advanceId]);
        if ((int) $st->fetchColumn() > 0) {
            return [
                'can_delete' => false,
                'message' => 'لا يمكن حذف السلفة بعد اقتطاعها من راتب موظف.',
            ];
        }
    } catch (Throwable $e) {
        // ignored
    }

    return ['can_delete' => true, 'message' => ''];
}

function hr_employee_advance_sync_status(PDO $pdo, int $advanceId): void
{
    if ($advanceId < 1) {
        return;
    }

    hr_employee_advance_ensure_schema($pdo);

    $st = $pdo->prepare(
        'SELECT advance_type, total_amount, status FROM hr_employee_advance WHERE id = ? LIMIT 1'
    );
    $st->execute([$advanceId]);
    $adv = $st->fetch(PDO::FETCH_ASSOC);
    if (!$adv || (string) ($adv['status'] ?? '') === 'cancelled') {
        return;
    }

    $stSum = $pdo->prepare(
        'SELECT COALESCE(SUM(amount), 0) FROM hr_salary_advance_deduction WHERE advance_id = ?'
    );
    $stSum->execute([$advanceId]);
    $deducted = round((float) $stSum->fetchColumn(), 3);
    $total = round((float) ($adv['total_amount'] ?? 0), 3);

    $newStatus = 'active';
    if ($deducted <= 0.0005) {
        $newStatus = 'active';
    } elseif ((string) ($adv['advance_type'] ?? '') === 'once') {
        $newStatus = 'completed';
    } elseif ($deducted + 0.001 >= $total) {
        $newStatus = 'completed';
    } else {
        $newStatus = 'active';
    }

    $pdo->prepare('UPDATE hr_employee_advance SET status = ? WHERE id = ?')
        ->execute([$newStatus, $advanceId]);
}

function hr_employee_advance_total_deducted(PDO $pdo, int $advanceId): float
{
    if ($advanceId < 1) {
        return 0.0;
    }

    hr_employee_advance_ensure_schema($pdo);
    try {
        $st = $pdo->prepare(
            'SELECT COALESCE(SUM(amount), 0) FROM hr_salary_advance_deduction WHERE advance_id = ?'
        );
        $st->execute([$advanceId]);

        return round((float) $st->fetchColumn(), 3);
    } catch (Throwable $e) {
        return 0.0;
    }
}

/** قيد راتب مرتبط باقتطاع السلفة لنفس شهر الرواتب (إن وُجد). */
function hr_employee_advance_deduction_salary_id_for_month(
    PDO $pdo,
    int $advanceId,
    int $year,
    int $month
): int {
    if ($advanceId < 1 || $year < 2000 || $month < 1 || $month > 12) {
        return 0;
    }

    hr_employee_advance_ensure_schema($pdo);
    try {
        $st = $pdo->prepare(
            'SELECT sad.salary_id
             FROM hr_salary_advance_deduction sad
             INNER JOIN hr_salary s ON s.id = sad.salary_id
             WHERE sad.advance_id = ? AND s.pay_year = ? AND s.pay_month = ?
             LIMIT 1'
        );
        $st->execute([$advanceId, $year, $month]);

        return (int) ($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * اقتطاعات السلف للشهر (للموظف) عند الاحتساب.
 *
 * @return array{total:float, lines:list<array{advance_id:int, label:string, amount:float}>}
 */
function hr_employee_advance_deductions_for_month(
    PDO $pdo,
    int $employeeId,
    int $year,
    int $month,
    int $currentSalaryId = 0
): array {
    hr_employee_advance_ensure_schema($pdo);
    if ($employeeId < 1 || $year < 2000 || $month < 1 || $month > 12) {
        return ['total' => 0.0, 'lines' => []];
    }

    $st = $pdo->prepare(
        'SELECT id, advance_type, total_amount, start_date, end_date, status
         FROM hr_employee_advance
         WHERE employee_id = ? AND status IN (\'active\', \'completed\')
         ORDER BY id ASC'
    );
    $st->execute([$employeeId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $lines = [];
    $total = 0.0;

    foreach ($rows as $adv) {
        $aid = (int) ($adv['id'] ?? 0);
        if ($aid < 1) {
            continue;
        }

        $type = (string) ($adv['advance_type'] ?? '');
        $amt = 0.0;
        $label = hr_employee_advance_type_label($type);

        if ($type === 'once') {
            if ((string) ($adv['status'] ?? '') === 'cancelled') {
                continue;
            }
            $m = hr_employee_advance_month_from_date((string) ($adv['start_date'] ?? ''));
            if ($m['year'] === $year && $m['month'] === $month) {
                $chk = $pdo->prepare(
                    'SELECT sad.salary_id FROM hr_salary_advance_deduction sad WHERE sad.advance_id = ? LIMIT 1'
                );
                $chk->execute([$aid]);
                $existingSalId = (int) ($chk->fetchColumn() ?: 0);
                if ($existingSalId > 0 && $existingSalId !== $currentSalaryId) {
                    continue;
                }
                $amt = round((float) ($adv['total_amount'] ?? 0), 3);
            }
        } elseif ($type === 'long') {
            if ((string) ($adv['status'] ?? '') === 'cancelled') {
                continue;
            }
            $start = (string) ($adv['start_date'] ?? '');
            $end = (string) ($adv['end_date'] ?? $start);
            if (!hr_employee_advance_month_in_range($year, $month, $start, $end)) {
                continue;
            }

            $totalAdv = round((float) ($adv['total_amount'] ?? 0), 3);
            $remaining = round($totalAdv - hr_employee_advance_total_deducted($pdo, $aid), 3);
            if ($remaining <= 0.0005) {
                continue;
            }

            $monthSalId = hr_employee_advance_deduction_salary_id_for_month($pdo, $aid, $year, $month);
            if ($monthSalId > 0 && $monthSalId !== $currentSalaryId) {
                continue;
            }

            $months = hr_employee_advance_month_count($start, $end);
            $installment = hr_employee_advance_installment_amount($totalAdv, $months);
            $amt = round(min($installment, $remaining), 3);
            $label = 'سلفة طويلة (' . $months . ' أشهر)';

            if ((string) ($adv['status'] ?? '') === 'completed' && $remaining > 0.0005) {
                hr_employee_advance_sync_status($pdo, $aid);
            }
        }

        if ($amt <= 0.0005) {
            continue;
        }

        $lines[] = ['advance_id' => $aid, 'label' => $label, 'amount' => $amt];
        $total += $amt;
    }

    return ['total' => round($total, 3), 'lines' => $lines];
}

/** @param list<array{advance_id:int, label:string, amount:float}> $lines */
function hr_employee_advance_apply_to_salary(PDO $pdo, int $salaryId, array $lines): void
{
    hr_employee_advance_ensure_schema($pdo);
    if ($salaryId < 1) {
        return;
    }

    $pdo->prepare('DELETE FROM hr_salary_advance_deduction WHERE salary_id = ?')->execute([$salaryId]);

    if (!$lines) {
        return;
    }

    $st = $pdo->prepare(
        'INSERT INTO hr_salary_advance_deduction (salary_id, advance_id, amount) VALUES (?, ?, ?)'
    );
    $advanceIds = [];
    foreach ($lines as $ln) {
        $aid = (int) ($ln['advance_id'] ?? 0);
        if ($aid < 1) {
            continue;
        }
        $st->execute([$salaryId, $aid, (float) ($ln['amount'] ?? 0)]);
        $advanceIds[$aid] = true;
    }

    foreach (array_keys($advanceIds) as $aid) {
        hr_employee_advance_sync_status($pdo, (int) $aid);
    }
}

/** @return list<array{name_ar:string, amount:float}> */
function hr_salary_advance_deduction_lines(PDO $pdo, int $salaryId): array
{
    hr_employee_advance_ensure_schema($pdo);
    if ($salaryId < 1) {
        return [];
    }

    try {
        $st = $pdo->prepare(
            'SELECT sad.amount, a.advance_type, a.start_date, a.end_date
             FROM hr_salary_advance_deduction sad
             INNER JOIN hr_employee_advance a ON a.id = sad.advance_id
             WHERE sad.salary_id = ?
             ORDER BY sad.id ASC'
        );
        $st->execute([$salaryId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $type = (string) ($r['advance_type'] ?? '');
            $label = hr_employee_advance_type_label($type);
            if ($type === 'long') {
                $months = hr_employee_advance_month_count(
                    (string) ($r['start_date'] ?? ''),
                    (string) ($r['end_date'] ?? '')
                );
                $label = 'سلفة طويلة (' . $months . ' أشهر)';
            }
            $out[] = [
                'name_ar' => $label,
                'amount' => (float) ($r['amount'] ?? 0),
            ];
        }

        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/** @return list<int> */
function hr_employee_advance_ids_for_salary_month(PDO $pdo, int $year, int $month): array
{
    hr_employee_advance_ensure_schema($pdo);

    try {
        $st = $pdo->prepare(
            'SELECT DISTINCT sad.advance_id
             FROM hr_salary_advance_deduction sad
             INNER JOIN hr_salary s ON s.id = sad.salary_id
             WHERE s.pay_year = ? AND s.pay_month = ?'
        );
        $st->execute([$year, $month]);
        $ids = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $aid = (int) ($row['advance_id'] ?? 0);
            if ($aid > 0) {
                $ids[] = $aid;
            }
        }

        return $ids;
    } catch (Throwable $e) {
        return [];
    }
}

/** إعادة حالة السلف بعد حذف قيود شهر (فك ترحيل). */
function hr_employee_advance_resync_after_salary_month_deleted(PDO $pdo, array $advanceIds): void
{
    foreach ($advanceIds as $aid) {
        if ((int) $aid > 0) {
            hr_employee_advance_sync_status($pdo, (int) $aid);
        }
    }
}
