<?php
declare(strict_types=1);

require_once app_path('includes/hr_salary.php');
require_once app_path('includes/hr_employee_salary.php');

function hr_employee_monthly_payroll_line_ensure_schema(PDO $pdo): void
{
    hr_employee_ensure_schema($pdo);
    hr_payroll_component_ensure_schema($pdo);

    try {
        $pdo->query('SELECT id FROM hr_employee_monthly_payroll_line LIMIT 1');
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
        sql_migration_run_file($pdo, 'database/migrations/152_hr_employee_monthly_payroll_line.sql');
    } catch (Throwable $e) {
        try {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS hr_employee_monthly_payroll_line (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    employee_id INT UNSIGNED NOT NULL,
                    pay_year SMALLINT UNSIGNED NOT NULL,
                    pay_month TINYINT UNSIGNED NOT NULL,
                    component_id INT UNSIGNED NOT NULL,
                    amount DECIMAL(14,3) NOT NULL DEFAULT 0.000,
                    notes VARCHAR(255) NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uk_hr_emp_monthly_line (employee_id, pay_year, pay_month, component_id),
                    KEY idx_hr_emp_monthly_period (pay_year, pay_month),
                    KEY idx_hr_emp_monthly_emp (employee_id),
                    CONSTRAINT fk_hr_emp_monthly_line_emp FOREIGN KEY (employee_id) REFERENCES hr_employee(id) ON DELETE CASCADE,
                    CONSTRAINT fk_hr_emp_monthly_line_comp FOREIGN KEY (component_id) REFERENCES hr_payroll_component(id) ON DELETE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        } catch (Throwable $e2) {
            // ignored
        }
    }
}

function hr_employee_monthly_payroll_assert_editable(PDO $pdo, int $employeeId, int $year, int $month): void
{
    if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
        throw new RuntimeException('الفترة غير صحيحة.');
    }
    if ($employeeId < 1) {
        throw new RuntimeException('اختر الموظف.');
    }

    if (!function_exists('hr_payroll_assert_can_edit')) {
        require_once app_path('includes/hr_payroll_posting.php');
    }
    hr_payroll_assert_can_edit($pdo, $year, $month);

    $existing = hr_payroll_salary_row($pdo, $employeeId, $year, $month);
    if ($existing && (int) ($existing['is_posted'] ?? 0) === 1) {
        throw new RuntimeException('راتب هذا الموظف مرحّل لهذا الشهر — لا يمكن التعديل.');
    }
}

/**
 * @return array<int, array{component_id:int, amount:float, comp_type:string, name_ar:string, comp_code:string, is_percent:int, notes:?string, line_id:int}>
 */
function hr_employee_monthly_payroll_lines_load(PDO $pdo, int $employeeId, int $year, int $month): array
{
    if ($employeeId < 1) {
        return [];
    }

    hr_employee_monthly_payroll_line_ensure_schema($pdo);
    try {
        $st = $pdo->prepare(
            'SELECT l.id AS line_id, l.component_id, l.amount, l.notes,
                    c.comp_type, c.name_ar, c.comp_code, c.is_percent
             FROM hr_employee_monthly_payroll_line l
             JOIN hr_payroll_component c ON c.id = l.component_id
             WHERE l.employee_id = ? AND l.pay_year = ? AND l.pay_month = ?
             ORDER BY c.comp_type ASC, CAST(c.comp_code AS UNSIGNED) ASC, c.id ASC'
        );
        $st->execute([$employeeId, $year, $month]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $cid = (int) ($r['component_id'] ?? 0);
            if ($cid < 1) {
                continue;
            }
            $out[$cid] = [
                'line_id' => (int) ($r['line_id'] ?? 0),
                'component_id' => $cid,
                'amount' => (float) ($r['amount'] ?? 0),
                'comp_type' => (string) ($r['comp_type'] ?? ''),
                'name_ar' => (string) ($r['name_ar'] ?? ''),
                'comp_code' => (string) ($r['comp_code'] ?? ''),
                'is_percent' => (int) ($r['is_percent'] ?? 0),
                'notes' => isset($r['notes']) && $r['notes'] !== '' ? (string) $r['notes'] : null,
            ];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @return list<array<string, mixed>>
 */
function hr_employee_monthly_payroll_lines_list(PDO $pdo, int $employeeId, int $year, int $month, ?string $compType = null): array
{
    $map = hr_employee_monthly_payroll_lines_load($pdo, $employeeId, $year, $month);
    $rows = array_values($map);
    if ($compType !== null && in_array($compType, ['allowance', 'deduction'], true)) {
        $rows = array_values(array_filter(
            $rows,
            static fn (array $r): bool => ($r['comp_type'] ?? '') === $compType
        ));
    }

    return $rows;
}

/**
 * @return array{employee_id:int, pay_year:int, pay_month:int, component_id:int, amount:float, notes:?string}
 */
function hr_employee_monthly_payroll_parse_row(PDO $pdo, array $row, float $baseSalary): array
{
    hr_payroll_component_ensure_schema($pdo);

    $employeeId = (int) ($row['employee_id'] ?? 0);
    $year = (int) ($row['pay_year'] ?? 0);
    $month = (int) ($row['pay_month'] ?? 0);
    $componentId = (int) ($row['component_id'] ?? 0);
    $notes = trim((string) ($row['notes'] ?? ''));

    if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
        throw new RuntimeException('الفترة غير صحيحة.');
    }
    if ($employeeId < 1) {
        throw new RuntimeException('اختر الموظف.');
    }
    if ($componentId < 1) {
        throw new RuntimeException('اختر علاوة أو اقتطاع.');
    }
    $stEmp = $pdo->prepare('SELECT id, base_salary FROM hr_employee WHERE id = ? LIMIT 1');
    $stEmp->execute([$employeeId]);
    $emp = $stEmp->fetch(PDO::FETCH_ASSOC);
    if (!$emp) {
        throw new RuntimeException('الموظف غير موجود.');
    }
    if ($baseSalary <= 0) {
        $baseSalary = (float) ($emp['base_salary'] ?? 0);
    }
    if ($baseSalary <= 0) {
        throw new RuntimeException('لم يُعرَّف راتب للموظف — من شاشة رواتب الموظفين.');
    }

    $stComp = $pdo->prepare(
        'SELECT id, comp_type, is_percent, default_amount, is_active FROM hr_payroll_component WHERE id = ? LIMIT 1'
    );
    $stComp->execute([$componentId]);
    $comp = $stComp->fetch(PDO::FETCH_ASSOC);
    if (!$comp || (int) ($comp['is_active'] ?? 0) !== 1) {
        throw new RuntimeException('بند علاوة/اقتطاع غير صالح أو غير مفعّل.');
    }

    $amountRaw = (float) ($comp['default_amount'] ?? 0);
    if ($amountRaw < 0) {
        throw new RuntimeException('قيمة البند المعرفة غير صالحة.');
    }
    $amount = $amountRaw;
    if ((int) ($comp['is_percent'] ?? 0) === 1) {
        if ($amountRaw > 100) {
            throw new RuntimeException('النسبة المئوية يجب أن تكون بين 0 و 100.');
        }
        $amount = round($baseSalary * $amountRaw / 100, 3);
    }

    return [
        'employee_id' => $employeeId,
        'pay_year' => $year,
        'pay_month' => $month,
        'component_id' => $componentId,
        'amount' => $amount,
        'notes' => $notes !== '' ? $notes : null,
    ];
}

function hr_employee_monthly_payroll_save_line(PDO $pdo, int $lineId, array $parsed): int
{
    hr_employee_monthly_payroll_line_ensure_schema($pdo);
    hr_employee_monthly_payroll_assert_editable(
        $pdo,
        (int) $parsed['employee_id'],
        (int) $parsed['pay_year'],
        (int) $parsed['pay_month']
    );

    $stDup = $pdo->prepare(
        'SELECT id FROM hr_employee_monthly_payroll_line
         WHERE employee_id = ? AND pay_year = ? AND pay_month = ? AND component_id = ? AND id <> ?
         LIMIT 1'
    );
    $stDup->execute([
        $parsed['employee_id'],
        $parsed['pay_year'],
        $parsed['pay_month'],
        $parsed['component_id'],
        max(0, $lineId),
    ]);
    if ($stDup->fetchColumn()) {
        throw new RuntimeException('هذا البند مُسجَّل مسبقاً لهذا الموظف في الشهر — عدّله أو احذفه أولاً.');
    }

    if ($lineId > 0) {
        $st = $pdo->prepare(
            'UPDATE hr_employee_monthly_payroll_line
             SET employee_id = ?, pay_year = ?, pay_month = ?, component_id = ?, amount = ?, notes = ?
             WHERE id = ?'
        );
        $st->execute([
            $parsed['employee_id'],
            $parsed['pay_year'],
            $parsed['pay_month'],
            $parsed['component_id'],
            $parsed['amount'],
            $parsed['notes'],
            $lineId,
        ]);
        return $lineId;
    }

    $st = $pdo->prepare(
        'INSERT INTO hr_employee_monthly_payroll_line
         (employee_id, pay_year, pay_month, component_id, amount, notes)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $st->execute([
        $parsed['employee_id'],
        $parsed['pay_year'],
        $parsed['pay_month'],
        $parsed['component_id'],
        $parsed['amount'],
        $parsed['notes'],
    ]);

    return (int) $pdo->lastInsertId();
}

function hr_employee_monthly_payroll_delete_line(PDO $pdo, int $lineId): void
{
    if ($lineId < 1) {
        return;
    }

    hr_employee_monthly_payroll_line_ensure_schema($pdo);
    $st = $pdo->prepare(
        'SELECT employee_id, pay_year, pay_month FROM hr_employee_monthly_payroll_line WHERE id = ? LIMIT 1'
    );
    $st->execute([$lineId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('البند غير موجود.');
    }

    hr_employee_monthly_payroll_assert_editable(
        $pdo,
        (int) ($row['employee_id'] ?? 0),
        (int) ($row['pay_year'] ?? 0),
        (int) ($row['pay_month'] ?? 0)
    );

    $pdo->prepare('DELETE FROM hr_employee_monthly_payroll_line WHERE id = ?')->execute([$lineId]);
}

/**
 * @return array{base:float, allowances:float, deductions:float, gross:float, lines:array<int, array{component_id:int, amount:float}>}
 */
function hr_payroll_snapshot_for_month(PDO $pdo, int $employeeId, int $year, int $month): array
{
    if ($employeeId < 1) {
        throw new RuntimeException('موظف غير صالح.');
    }

    hr_employee_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT base_salary FROM hr_employee WHERE id = ? LIMIT 1');
    $st->execute([$employeeId]);
    $base = (float) ($st->fetchColumn() ?: 0);
    if ($base <= 0) {
        throw new RuntimeException('لم يُعرَّف راتب للموظف — من شاشة رواتب الموظفين.');
    }

    $linesMap = hr_employee_salary_lines_load($pdo, $employeeId);
    $monthlyMap = hr_employee_monthly_payroll_lines_load($pdo, $employeeId, $year, $month);
    foreach ($monthlyMap as $cid => $line) {
        $linesMap[$cid] = [
            'component_id' => $cid,
            'amount' => (float) ($line['amount'] ?? 0),
            'comp_type' => (string) ($line['comp_type'] ?? ''),
            'name_ar' => (string) ($line['name_ar'] ?? ''),
            'is_percent' => (int) ($line['is_percent'] ?? 0),
        ];
    }

    $lines = [];
    foreach ($linesMap as $line) {
        $lines[] = [
            'component_id' => (int) ($line['component_id'] ?? 0),
            'amount' => (float) ($line['amount'] ?? 0),
        ];
    }

    $totals = hr_employee_salary_totals($base, $linesMap);

    return [
        'base' => $base,
        'allowances' => (float) ($totals['allowances'] ?? 0),
        'deductions' => (float) ($totals['deductions'] ?? 0),
        'gross' => (float) ($totals['gross'] ?? 0),
        'lines' => $lines,
    ];
}

/**
 * @return array<string, mixed>|null
 */
function hr_employee_monthly_payroll_line_by_id(PDO $pdo, int $lineId): ?array
{
    if ($lineId < 1) {
        return null;
    }

    hr_employee_monthly_payroll_line_ensure_schema($pdo);
    try {
        $st = $pdo->prepare(
            'SELECT l.id, l.employee_id, l.pay_year, l.pay_month, l.component_id, l.amount, l.notes,
                    c.comp_type, c.name_ar, c.comp_code, c.is_percent, c.default_amount,
                    e.emp_code, e.name_ar AS emp_name, e.base_salary
             FROM hr_employee_monthly_payroll_line l
             JOIN hr_payroll_component c ON c.id = l.component_id
             JOIN hr_employee e ON e.id = l.employee_id
             WHERE l.id = ?
             LIMIT 1'
        );
        $st->execute([$lineId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function hr_employee_monthly_payroll_count_for_period(PDO $pdo, int $employeeId, int $year, int $month): int
{
    if ($employeeId < 1) {
        return 0;
    }

    hr_employee_monthly_payroll_line_ensure_schema($pdo);
    try {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM hr_employee_monthly_payroll_line
             WHERE employee_id = ? AND pay_year = ? AND pay_month = ?'
        );
        $st->execute([$employeeId, $year, $month]);

        return (int) $st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function hr_employee_monthly_payroll_format_amount_display(array $row, float $baseSalary): string
{
    if ((int) ($row['is_percent'] ?? 0) === 1 && $baseSalary > 0) {
        $pct = round((float) ($row['amount'] ?? 0) / $baseSalary * 100, 3);
        return number_format($pct, 2) . ' %';
    }

    return number_format((float) ($row['amount'] ?? 0), 2);
}

function hr_employee_monthly_payroll_amount_input_value(array $row, float $baseSalary): string
{
    if ((int) ($row['is_percent'] ?? 0) === 1 && $baseSalary > 0) {
        return (string) round((float) ($row['amount'] ?? 0) / $baseSalary * 100, 3);
    }

    return (string) ((float) ($row['amount'] ?? 0));
}
