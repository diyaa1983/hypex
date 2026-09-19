<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');
require_once app_path('includes/hr_employee_advance.php');

function hr_salary_line_ensure_schema(PDO $pdo): void
{
    hr_employee_ensure_schema($pdo);
    hr_payroll_component_ensure_schema($pdo);

    try {
        $pdo->query('SELECT id FROM hr_salary_line LIMIT 1');
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
        sql_migration_run_file($pdo, 'database/migrations/094_hr_salary_lines.sql');
    } catch (Throwable $e) {
        try {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS hr_salary_line (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    salary_id INT UNSIGNED NOT NULL,
                    component_id INT UNSIGNED NOT NULL,
                    amount DECIMAL(14,3) NOT NULL DEFAULT 0.000,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uk_hr_salary_line_comp (salary_id, component_id),
                    KEY idx_hr_salary_line_salary (salary_id),
                    CONSTRAINT fk_hr_salary_line_salary FOREIGN KEY (salary_id) REFERENCES hr_salary(id) ON DELETE CASCADE,
                    CONSTRAINT fk_hr_salary_line_comp FOREIGN KEY (component_id) REFERENCES hr_payroll_component(id) ON DELETE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        } catch (Throwable $e2) {
            // ignored
        }
    }
}

/** @return array<int, array<string, mixed>> */
function hr_payroll_component_active_by_type(PDO $pdo, string $compType): array
{
    if (!in_array($compType, ['allowance', 'deduction'], true)) {
        return [];
    }

    hr_payroll_component_ensure_schema($pdo);
    try {
        $st = $pdo->prepare(
            'SELECT id, comp_code, name_ar, comp_type, is_percent, default_amount
             FROM hr_payroll_component
             WHERE comp_type = ? AND is_active = 1
             ORDER BY CAST(comp_code AS UNSIGNED) ASC, id ASC'
        );
        $st->execute([$compType]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @return array<int, array{component_id:int, amount:float, comp_type:string, name_ar:string, is_percent:int}>
 */
function hr_salary_lines_load(PDO $pdo, int $salaryId): array
{
    if ($salaryId < 1) {
        return [];
    }

    hr_salary_line_ensure_schema($pdo);
    try {
        $st = $pdo->prepare(
            'SELECT l.component_id, l.amount, c.comp_type, c.name_ar, c.is_percent
             FROM hr_salary_line l
             JOIN hr_payroll_component c ON c.id = l.component_id
             WHERE l.salary_id = ?
             ORDER BY c.comp_type ASC, CAST(c.comp_code AS UNSIGNED) ASC, c.id ASC'
        );
        $st->execute([$salaryId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $cid = (int) ($r['component_id'] ?? 0);
            if ($cid < 1) {
                continue;
            }
            $out[$cid] = [
                'component_id' => $cid,
                'amount' => (float) ($r['amount'] ?? 0),
                'comp_type' => (string) ($r['comp_type'] ?? ''),
                'name_ar' => (string) ($r['name_ar'] ?? ''),
                'is_percent' => (int) ($r['is_percent'] ?? 0),
            ];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @param array<int|string, array<string, mixed>> $postedLines
 * @return array{allowances:float, deductions:float, lines:array<int, array{component_id:int, amount:float}>}
 */
function hr_salary_parse_component_lines(PDO $pdo, array $postedLines, float $baseSalary): array
{
    hr_payroll_component_ensure_schema($pdo);
    $allowTotal = 0.0;
    $dedTotal = 0.0;
    $lines = [];

    foreach ($postedLines as $compIdRaw => $line) {
        if (!is_array($line) || empty($line['on'])) {
            continue;
        }
        $compId = (int) $compIdRaw;
        if ($compId < 1) {
            continue;
        }
        $amount = (float) ($line['amount'] ?? 0);
        if ($amount < 0) {
            throw new RuntimeException('مبالغ العلاوات والاقتطاعات يجب أن تكون موجبة أو صفر.');
        }

        $st = $pdo->prepare(
            'SELECT id, comp_type, is_percent, default_amount, is_active
             FROM hr_payroll_component WHERE id = ? LIMIT 1'
        );
        $st->execute([$compId]);
        $comp = $st->fetch(PDO::FETCH_ASSOC);
        if (!$comp || (int) ($comp['is_active'] ?? 0) !== 1) {
            throw new RuntimeException('بند علاوة/اقتطاع غير صالح أو غير مفعّل.');
        }

        if ((int) ($comp['is_percent'] ?? 0) === 1 && $baseSalary > 0) {
            $amount = round($baseSalary * $amount / 100, 3);
        }

        $type = (string) ($comp['comp_type'] ?? '');
        if ($type === 'allowance') {
            $allowTotal += $amount;
        } elseif ($type === 'deduction') {
            $dedTotal += $amount;
        }

        $lines[] = ['component_id' => $compId, 'amount' => $amount];
    }

    return [
        'allowances' => round($allowTotal, 3),
        'deductions' => round($dedTotal, 3),
        'lines' => $lines,
    ];
}

/**
 * @param array<int, array{component_id:int, amount:float}> $lines
 */
function hr_salary_save_lines(PDO $pdo, int $salaryId, array $lines): void
{
    if ($salaryId < 1) {
        return;
    }

    hr_salary_line_ensure_schema($pdo);
    $pdo->prepare('DELETE FROM hr_salary_line WHERE salary_id = ?')->execute([$salaryId]);

    if (!$lines) {
        return;
    }

    $st = $pdo->prepare('INSERT INTO hr_salary_line (salary_id, component_id, amount) VALUES (?, ?, ?)');
    foreach ($lines as $line) {
        $st->execute([$salaryId, (int) $line['component_id'], (float) $line['amount']]);
    }
}

function hr_salary_period_label_ar(int $year, int $month): string
{
    $names = [
        1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل',
        5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس',
        9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر',
    ];
    $m = $names[$month] ?? (string) $month;

    return $m . ' ' . $year;
}

function hr_salary_calc_net(
    float $base,
    float $allowances,
    float $deductions,
    float $overtime = 0,
    float $bonus = 0,
    float $ssEmp = 0,
    float $tax = 0
): float {
    $gross = $base + $allowances + $overtime + $bonus;
    $totalDed = $deductions + $ssEmp + $tax;

    return round($gross - $totalDed, 3);
}

/**
 * @return array<string, mixed>|null
 */
function hr_salary_load_for_print(PDO $pdo, int $salaryId): ?array
{
    if ($salaryId < 1) {
        return null;
    }

    hr_salary_line_ensure_schema($pdo);
    hr_salary_bank_ensure_schema($pdo);

    $bankJoin = '';
    $bankCol = '';
    try {
        $pdo->query('SELECT salary_bank_id FROM hr_employee LIMIT 1');
        $bankJoin = ' LEFT JOIN hr_salary_bank b ON b.id = e.salary_bank_id';
        $bankCol = ', b.name_ar AS salary_bank_name';
    } catch (Throwable $e) {
        // عمود البنك غير موجود بعد
    }

    $ssSubjectCol = '';
    try {
        $pdo->query('SELECT subject_to_social_security FROM hr_employee LIMIT 1');
        $ssSubjectCol = ', e.subject_to_social_security';
    } catch (Throwable $e) {
        // ignored
    }

    $st = $pdo->prepare(
        'SELECT s.*, e.emp_code, e.name_ar AS emp_name, e.job_title, e.department,
                e.hire_date, e.social_security_no, e.national_id,
                e.bank_name, e.bank_account' . $ssSubjectCol . $bankCol . '
         FROM hr_salary s
         JOIN hr_employee e ON e.id = s.employee_id' . $bankJoin . '
         WHERE s.id = ? LIMIT 1'
    );
    $st->execute([$salaryId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $allLines = array_values(hr_salary_lines_load($pdo, $salaryId));
    $row['lines'] = $allLines;
    $row['allowance_lines'] = [];
    $row['deduction_lines'] = [];
    foreach ($allLines as $ln) {
        if (($ln['comp_type'] ?? '') === 'deduction') {
            $row['deduction_lines'][] = $ln;
        } else {
            $row['allowance_lines'][] = $ln;
        }
    }

    foreach (hr_salary_advance_deduction_lines($pdo, $salaryId) as $advLn) {
        $row['deduction_lines'][] = [
            'name_ar' => (string) ($advLn['name_ar'] ?? ''),
            'amount' => (float) ($advLn['amount'] ?? 0),
        ];
    }
    $row['social_security_label'] = 'اقتطاع ضمان اجتماعي';
    $row['income_tax_label'] = 'ضريبة دخل';
    if ((int) ($row['subject_to_social_security'] ?? 0) !== 1) {
        $row['social_security_emp'] = 0;
        $row['net_salary'] = hr_salary_calc_net(
            (float) ($row['base_salary'] ?? 0),
            (float) ($row['allowances'] ?? 0),
            (float) ($row['deductions'] ?? 0),
            (float) ($row['overtime'] ?? 0),
            (float) ($row['bonus'] ?? 0),
            0,
            (float) ($row['income_tax'] ?? 0)
        );
    }
    $row['period_label'] = '';
    $py = (int) ($row['pay_year'] ?? 0);
    $pm = (int) ($row['pay_month'] ?? 0);
    if ($py > 0 && $pm >= 1 && $pm <= 12) {
        $row['period_label'] = hr_salary_period_label_ar($py, $pm);
    }

    return $row;
}

function hr_salary_disbursement_columns_ready(PDO $pdo): bool
{
    try {
        $st = $pdo->query("SHOW COLUMNS FROM hr_salary LIKE 'disbursement_voucher_id'");

        return (bool) $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

/** @return list<array<string, mixed>> */
function hr_salaries_pending_disbursement(PDO $pdo, int $employeeId): array
{
    if ($employeeId < 1 || !hr_salary_disbursement_columns_ready($pdo)) {
        return [];
    }
    try {
        $st = $pdo->prepare(
            'SELECT id, pay_year, pay_month, net_salary, pay_date
             FROM hr_salary
             WHERE employee_id = ?
               AND COALESCE(is_posted, 0) = 1
               AND net_salary > 0.0005
               AND (disbursement_voucher_id IS NULL OR disbursement_voucher_id = 0)
             ORDER BY pay_year DESC, pay_month DESC, id DESC'
        );
        $st->execute([$employeeId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $year = (int) ($row['pay_year'] ?? 0);
            $month = (int) ($row['pay_month'] ?? 0);
            $out[] = [
                'id' => (int) ($row['id'] ?? 0),
                'pay_year' => $year,
                'pay_month' => $month,
                'period_label' => hr_salary_period_label_ar($year, $month),
                'net_salary' => round((float) ($row['net_salary'] ?? 0), 3),
                'pay_date' => (string) ($row['pay_date'] ?? ''),
            ];
        }

        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

function hr_salary_validate_for_disbursement(
    PDO $pdo,
    int $salaryId,
    int $employeeId,
    float $amount,
    int $exceptVoucherId = 0
): ?string {
    if ($salaryId < 1) {
        return 'اختر الراتب المرحّل للصرف.';
    }
    try {
        $st = $pdo->prepare(
            'SELECT employee_id, is_posted, net_salary, disbursement_voucher_id
             FROM hr_salary WHERE id = ? LIMIT 1'
        );
        $st->execute([$salaryId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return 'الراتب غير موجود.';
    }
    if (!$row) {
        return 'الراتب غير موجود.';
    }
    if ((int) ($row['employee_id'] ?? 0) !== $employeeId) {
        return 'الراتب لا يخص الموظف المختار.';
    }
    if ((int) ($row['is_posted'] ?? 0) !== 1) {
        return 'الراتب غير مرحّل من شؤون الموظفين بعد.';
    }
    $linkedVoucherId = (int) ($row['disbursement_voucher_id'] ?? 0);
    if ($linkedVoucherId > 0 && $linkedVoucherId !== $exceptVoucherId) {
        return 'تم ربط هذا الراتب بسند صرف آخر من المحاسبة.';
    }
    $expected = round((float) ($row['net_salary'] ?? 0), 3);
    if ($expected <= 0.0005) {
        return 'صافي الراتب غير صالح للصرف.';
    }
    if (abs($amount - $expected) > 0.009) {
        return 'مبلغ سند الصرف يجب أن يساوي صافي الراتب (' . number_format($expected, 3) . ').';
    }

    return null;
}

function hr_salary_assign_voucher(PDO $pdo, int $salaryId, int $voucherId): void
{
    if (!hr_salary_disbursement_columns_ready($pdo)) {
        return;
    }
    if ($voucherId > 0) {
        $pdo->prepare(
            'UPDATE hr_salary
             SET disbursement_voucher_id = NULL
             WHERE disbursement_voucher_id = ? AND id <> ?'
        )->execute([$voucherId, $salaryId > 0 ? $salaryId : 0]);
    }
    if ($salaryId > 0 && $voucherId > 0) {
        $pdo->prepare(
            'UPDATE hr_salary SET disbursement_voucher_id = ? WHERE id = ?'
        )->execute([$voucherId, $salaryId]);
    }
}

function hr_salary_mark_disbursed(PDO $pdo, int $salaryId, int $voucherId): void
{
    if ($salaryId < 1 || $voucherId < 1 || !hr_salary_disbursement_columns_ready($pdo)) {
        return;
    }
    $pdo->prepare(
        'UPDATE hr_salary SET disbursement_voucher_id = ? WHERE id = ?'
    )->execute([$voucherId, $salaryId]);
}

function hr_salary_clear_disbursement_by_voucher(PDO $pdo, int $voucherId): void
{
    if ($voucherId < 1 || !hr_salary_disbursement_columns_ready($pdo)) {
        return;
    }
    $pdo->prepare(
        'UPDATE hr_salary SET disbursement_voucher_id = NULL WHERE disbursement_voucher_id = ?'
    )->execute([$voucherId]);
}

function hr_salary_month_has_disbursement(PDO $pdo, int $year, int $month): bool
{
    if (!hr_salary_disbursement_columns_ready($pdo)) {
        return false;
    }
    try {
        $st = $pdo->prepare(
            'SELECT 1 FROM hr_salary
             WHERE pay_year = ? AND pay_month = ?
               AND disbursement_voucher_id IS NOT NULL AND disbursement_voucher_id > 0
             LIMIT 1'
        );
        $st->execute([$year, $month]);

        return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}
