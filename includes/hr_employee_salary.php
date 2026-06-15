<?php
declare(strict_types=1);

require_once app_path('includes/hr_salary.php');

function hr_employee_salary_line_ensure_schema(PDO $pdo): void
{
    hr_employee_ensure_schema($pdo);
    hr_payroll_component_ensure_schema($pdo);

    try {
        $pdo->query('SELECT id FROM hr_employee_salary_line LIMIT 1');
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
        sql_migration_run_file($pdo, 'database/migrations/095_hr_employee_salary_lines.sql');
    } catch (Throwable $e) {
        try {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS hr_employee_salary_line (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    employee_id INT UNSIGNED NOT NULL,
                    component_id INT UNSIGNED NOT NULL,
                    amount DECIMAL(14,3) NOT NULL DEFAULT 0.000,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uk_hr_emp_salary_line_comp (employee_id, component_id),
                    KEY idx_hr_emp_salary_line_emp (employee_id),
                    CONSTRAINT fk_hr_emp_salary_line_emp FOREIGN KEY (employee_id) REFERENCES hr_employee(id) ON DELETE CASCADE,
                    CONSTRAINT fk_hr_emp_salary_line_comp FOREIGN KEY (component_id) REFERENCES hr_payroll_component(id) ON DELETE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        } catch (Throwable $e2) {
            // ignored
        }
    }
}

/**
 * @return array<int, array{component_id:int, amount:float, comp_type:string, name_ar:string, is_percent:int}>
 */
function hr_employee_salary_lines_load(PDO $pdo, int $employeeId): array
{
    if ($employeeId < 1) {
        return [];
    }

    hr_employee_salary_line_ensure_schema($pdo);
    try {
        $st = $pdo->prepare(
            'SELECT l.component_id, l.amount, c.comp_type, c.name_ar, c.is_percent
             FROM hr_employee_salary_line l
             JOIN hr_payroll_component c ON c.id = l.component_id
             WHERE l.employee_id = ?
             ORDER BY c.comp_type ASC, CAST(c.comp_code AS UNSIGNED) ASC, c.id ASC'
        );
        $st->execute([$employeeId]);
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
 * @param array<int, array{component_id:int, amount:float}> $lines
 */
function hr_employee_salary_save_lines(PDO $pdo, int $employeeId, array $lines): void
{
    if ($employeeId < 1) {
        return;
    }

    hr_employee_salary_line_ensure_schema($pdo);
    $pdo->prepare('DELETE FROM hr_employee_salary_line WHERE employee_id = ?')->execute([$employeeId]);

    if (!$lines) {
        return;
    }

    $st = $pdo->prepare('INSERT INTO hr_employee_salary_line (employee_id, component_id, amount) VALUES (?, ?, ?)');
    foreach ($lines as $line) {
        $st->execute([$employeeId, (int) $line['component_id'], (float) $line['amount']]);
    }
}

/**
 * @param array<int, array{comp_type:string, amount:float}> $lines
 * @return array{allowances:float, deductions:float, net:float, gross:float}
 */
function hr_employee_salary_totals(float $baseSalary, array $lines): array
{
    $allow = 0.0;
    $ded = 0.0;
    foreach ($lines as $line) {
        $amt = (float) ($line['amount'] ?? 0);
        if (($line['comp_type'] ?? '') === 'deduction') {
            $ded += $amt;
        } else {
            $allow += $amt;
        }
    }
    $allow = round($allow, 3);
    $ded = round($ded, 3);
    $gross = round($baseSalary + $allow, 3);

    return [
        'allowances' => $allow,
        'deductions' => $ded,
        'net' => hr_salary_calc_net($baseSalary, $allow, $ded),
        'gross' => $gross,
    ];
}

/**
 * يبقي على بنود العلاوات فقط (شاشة الراتب الأساسي).
 *
 * @param array<int|string, array<string, mixed>> $postedLines
 * @return array<int|string, array<string, mixed>>
 */
function hr_employee_salary_filter_allowance_lines(PDO $pdo, array $postedLines): array
{
    hr_payroll_component_ensure_schema($pdo);
    $out = [];
    foreach ($postedLines as $compIdRaw => $line) {
        if (!is_array($line) || empty($line['on'])) {
            continue;
        }
        $compId = (int) $compIdRaw;
        if ($compId < 1) {
            continue;
        }
        $st = $pdo->prepare('SELECT comp_type FROM hr_payroll_component WHERE id = ? LIMIT 1');
        $st->execute([$compId]);
        $type = (string) ($st->fetchColumn() ?: '');
        if ($type === 'allowance') {
            $out[$compId] = $line;
        }
    }
    return $out;
}

/** @return array<int, array{component_id:int, amount:float, comp_type:string, name_ar:string, is_percent:int}> */
function hr_employee_salary_allowance_lines_only(array $lines): array
{
    $out = [];
    foreach ($lines as $cid => $line) {
        if (($line['comp_type'] ?? '') !== 'deduction') {
            $out[$cid] = $line;
        }
    }
    return $out;
}

/** @return list<array<string, mixed>> */
function hr_employee_salary_allowance_lines_list(PDO $pdo, int $employeeId): array
{
    if ($employeeId < 1) {
        return [];
    }

    hr_employee_salary_line_ensure_schema($pdo);
    try {
        $st = $pdo->prepare(
            'SELECT l.component_id, l.amount, c.comp_code, c.name_ar, c.is_percent, c.comp_type
             FROM hr_employee_salary_line l
             JOIN hr_payroll_component c ON c.id = l.component_id
             WHERE l.employee_id = ? AND c.comp_type = \'allowance\'
             ORDER BY CAST(c.comp_code AS UNSIGNED) ASC, c.id ASC'
        );
        $st->execute([$employeeId]);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function hr_employee_salary_format_amount_display(array $row, float $baseSalary): string
{
    $amount = (float) ($row['amount'] ?? 0);
    if ((int) ($row['is_percent'] ?? 0) === 1) {
        if ($baseSalary > 0) {
            $pct = round($amount / $baseSalary * 100, 3);

            return rtrim(rtrim(number_format($pct, 3, '.', ''), '0'), '.') . ' %';
        }

        return '0 %';
    }

    return number_format($amount, 2);
}

function hr_employee_salary_amount_input_value(array $row, float $baseSalary): string
{
    $amount = (float) ($row['amount'] ?? 0);
    if ((int) ($row['is_percent'] ?? 0) === 1 && $baseSalary > 0) {
        return (string) round($amount / $baseSalary * 100, 3);
    }

    return (string) $amount;
}

function hr_employee_salary_recalc_allowances(PDO $pdo, int $employeeId): void
{
    if ($employeeId < 1) {
        return;
    }

    $st = $pdo->prepare('SELECT base_salary FROM hr_employee WHERE id = ? LIMIT 1');
    $st->execute([$employeeId]);
    $base = (float) ($st->fetchColumn() ?: 0);
    $lines = hr_employee_salary_allowance_lines_only(hr_employee_salary_lines_load($pdo, $employeeId));
    $totals = hr_employee_salary_totals($base, $lines);
    $pdo->prepare('UPDATE hr_employee SET allowances = ? WHERE id = ?')->execute([
        $totals['allowances'],
        $employeeId,
    ]);
}

function hr_employee_salary_save_allowance_line(
    PDO $pdo,
    int $employeeId,
    int $componentId,
    float $inputAmount,
    float $baseSalary
): void {
    if ($employeeId < 1 || $componentId < 1) {
        throw new RuntimeException('بيانات غير صالحة.');
    }
    if ($inputAmount < 0) {
        throw new RuntimeException('مبلغ العلاوة يجب أن يكون موجباً أو صفر.');
    }

    hr_payroll_component_ensure_schema($pdo);
    $st = $pdo->prepare(
        'SELECT id, comp_type, is_percent, is_active FROM hr_payroll_component WHERE id = ? LIMIT 1'
    );
    $st->execute([$componentId]);
    $comp = $st->fetch(PDO::FETCH_ASSOC);
    if (!$comp || (string) ($comp['comp_type'] ?? '') !== 'allowance') {
        throw new RuntimeException('علاوة غير صالحة.');
    }
    if ((int) ($comp['is_active'] ?? 0) !== 1) {
        throw new RuntimeException('العلاوة غير مفعّلة.');
    }

    $amount = $inputAmount;
    if ((int) ($comp['is_percent'] ?? 0) === 1 && $baseSalary > 0) {
        $amount = round($baseSalary * $inputAmount / 100, 3);
    }

    hr_employee_salary_line_ensure_schema($pdo);
    $pdo->prepare(
        'INSERT INTO hr_employee_salary_line (employee_id, component_id, amount) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE amount = VALUES(amount)'
    )->execute([$employeeId, $componentId, $amount]);
    hr_employee_salary_recalc_allowances($pdo, $employeeId);
}

function hr_employee_salary_delete_allowance_line(PDO $pdo, int $employeeId, int $componentId): void
{
    if ($employeeId < 1 || $componentId < 1) {
        return;
    }

    hr_employee_salary_line_ensure_schema($pdo);
    $pdo->prepare(
        'DELETE l FROM hr_employee_salary_line l
         INNER JOIN hr_payroll_component c ON c.id = l.component_id
         WHERE l.employee_id = ? AND l.component_id = ? AND c.comp_type = \'allowance\''
    )->execute([$employeeId, $componentId]);
    hr_employee_salary_recalc_allowances($pdo, $employeeId);
}

function hr_employee_salary_recalc_percent_lines(PDO $pdo, int $employeeId, float $oldBase, float $newBase): void
{
    if ($employeeId < 1 || $oldBase <= 0 || $newBase <= 0 || abs($oldBase - $newBase) < 0.0001) {
        return;
    }

    $lines = hr_employee_salary_allowance_lines_list($pdo, $employeeId);
    $upd = $pdo->prepare(
        'UPDATE hr_employee_salary_line SET amount = ? WHERE employee_id = ? AND component_id = ?'
    );
    foreach ($lines as $line) {
        if ((int) ($line['is_percent'] ?? 0) !== 1) {
            continue;
        }
        $compId = (int) ($line['component_id'] ?? 0);
        if ($compId < 1) {
            continue;
        }
        $pct = (float) ($line['amount'] ?? 0) / $oldBase * 100;
        $upd->execute([round($newBase * $pct / 100, 3), $employeeId, $compId]);
    }
    hr_employee_salary_recalc_allowances($pdo, $employeeId);
}

function hr_employee_salary_clear(PDO $pdo, int $employeeId): void
{
    if ($employeeId < 1) {
        return;
    }
    hr_employee_salary_line_ensure_schema($pdo);
    $pdo->prepare('UPDATE hr_employee SET base_salary = 0, allowances = 0 WHERE id = ?')->execute([$employeeId]);
    $pdo->prepare('DELETE FROM hr_employee_salary_line WHERE employee_id = ?')->execute([$employeeId]);
}

/**
 * @return array<string, mixed>|null
 */
function hr_employee_salary_load_for_print(PDO $pdo, int $employeeId): ?array
{
    if ($employeeId < 1) {
        return null;
    }

    hr_employee_salary_line_ensure_schema($pdo);
    hr_salary_bank_ensure_schema($pdo);

    $bankJoin = '';
    $bankCol = '';
    try {
        $pdo->query('SELECT salary_bank_id FROM hr_employee LIMIT 1');
        $bankJoin = ' LEFT JOIN hr_salary_bank b ON b.id = e.salary_bank_id';
        $bankCol = ', b.name_ar AS salary_bank_name';
    } catch (Throwable $e) {
        // ignored
    }

    $st = $pdo->prepare(
        'SELECT e.id, e.emp_code, e.name_ar AS emp_name, e.job_title, e.department,
                e.base_salary, e.allowances, e.bank_name, e.bank_account' . $bankCol . '
         FROM hr_employee e' . $bankJoin . '
         WHERE e.id = ? LIMIT 1'
    );
    $st->execute([$employeeId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $lines = hr_employee_salary_allowance_lines_only(hr_employee_salary_lines_load($pdo, $employeeId));
    $totals = hr_employee_salary_totals((float) ($row['base_salary'] ?? 0), $lines);
    $row['lines'] = array_values($lines);
    $row['allowances'] = $totals['allowances'];
    $row['gross_salary'] = $totals['gross'];

    return $row;
}

/** @return array<int, array<string, mixed>> */
function hr_employee_salary_grid_rows(PDO $pdo): array
{
    hr_employee_ensure_schema($pdo);
    hr_employee_salary_line_ensure_schema($pdo);

    try {
        $rows = $pdo->query(
            'SELECT id, emp_code, name_ar, base_salary, is_active
             FROM hr_employee
             ORDER BY name_ar ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    foreach ($rows as &$row) {
        $empId = (int) ($row['id'] ?? 0);
        $base = (float) ($row['base_salary'] ?? 0);
        $lines = hr_employee_salary_allowance_lines_only(hr_employee_salary_lines_load($pdo, $empId));
        $totals = hr_employee_salary_totals($base, $lines);
        $row['allow_total'] = $totals['allowances'];
        $row['gross_total'] = $totals['gross'];
        $row['has_setup'] = $base > 0 || $lines !== [];
    }
    unset($row);

    return $rows;
}
