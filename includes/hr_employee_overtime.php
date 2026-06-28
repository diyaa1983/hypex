<?php
declare(strict_types=1);

require_once app_path('includes/hr_overtime.php');
require_once app_path('includes/hr_salary.php');

function hr_employee_overtime_ensure_schema(PDO $pdo): void
{
    hr_overtime_ensure_schema($pdo);

    try {
        $pdo->query('SELECT id FROM hr_employee_overtime LIMIT 1');
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
        sql_migration_run_file($pdo, 'database/migrations/185_hr_overtime.sql');
    } catch (Throwable $e) {
        try {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS hr_employee_overtime (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    employee_id INT UNSIGNED NOT NULL,
                    pay_year SMALLINT UNSIGNED NOT NULL,
                    pay_month TINYINT UNSIGNED NOT NULL,
                    overtime_hours DECIMAL(8,3) NOT NULL DEFAULT 0.000,
                    hour_multiplier DECIMAL(6,3) NOT NULL DEFAULT 1.250,
                    base_salary DECIMAL(14,3) NOT NULL DEFAULT 0.000,
                    overtime_amount DECIMAL(14,3) NOT NULL DEFAULT 0.000,
                    notes VARCHAR(255) NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uk_hr_emp_overtime_period (employee_id, pay_year, pay_month),
                    KEY idx_hr_emp_overtime_period (pay_year, pay_month),
                    CONSTRAINT fk_hr_emp_overtime_emp FOREIGN KEY (employee_id)
                        REFERENCES hr_employee(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        } catch (Throwable $e2) {
            // ignored
        }
    }
}

function hr_employee_overtime_assert_editable(PDO $pdo, int $employeeId, int $year, int $month): void
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
        throw new RuntimeException('راتب هذا الموظف مرحّل لهذا الشهر — لا يمكن تعديل العمل الإضافي.');
    }
}

/** @return array<string, mixed>|null */
function hr_employee_overtime_get(PDO $pdo, int $employeeId, int $year, int $month): ?array
{
    hr_employee_overtime_ensure_schema($pdo);
    if ($employeeId < 1) {
        return null;
    }
    try {
        $st = $pdo->prepare(
            'SELECT * FROM hr_employee_overtime
             WHERE employee_id = ? AND pay_year = ? AND pay_month = ? LIMIT 1'
        );
        $st->execute([$employeeId, $year, $month]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function hr_employee_overtime_amount_for_employee(PDO $pdo, int $employeeId, int $year, int $month): float
{
    $row = hr_employee_overtime_get($pdo, $employeeId, $year, $month);

    return $row ? (float) ($row['overtime_amount'] ?? 0) : 0.0;
}

/**
 * بند العمل الإضافي لعرضه ضمن العلاوات في كشوفات الرواتب.
 *
 * @return array{name_ar:string, amount:float, display:string, source:string, code:string}|null
 */
function hr_payroll_overtime_allowance_line(
    PDO $pdo,
    int $employeeId,
    int $year,
    int $month,
    ?float $salaryOvertimeAmount = null
): ?array {
    hr_employee_overtime_ensure_schema($pdo);

    $ot = hr_employee_overtime_get($pdo, $employeeId, $year, $month);
    $amount = $ot ? round((float) ($ot['overtime_amount'] ?? 0), 3) : 0.0;
    if ($amount <= 0.0005 && $salaryOvertimeAmount !== null) {
        $amount = round(max(0.0, (float) $salaryOvertimeAmount), 3);
    }
    if ($amount <= 0.0005) {
        return null;
    }

    $name = 'عمل اضافة لمرة واحدة';

    return [
        'code' => '',
        'name' => $name,
        'name_ar' => $name,
        'amount' => $amount,
        'display' => number_format($amount, 3),
        'source' => 'إضافي',
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function hr_employee_overtime_list_period(PDO $pdo, int $year, int $month, int $employeeId = 0): array
{
    hr_employee_overtime_ensure_schema($pdo);
    hr_employee_ensure_schema($pdo);

    $sql = 'SELECT o.*, e.emp_code, e.name_ar
            FROM hr_employee_overtime o
            INNER JOIN hr_employee e ON e.id = o.employee_id
            WHERE o.pay_year = ? AND o.pay_month = ?';
    $params = [$year, $month];

    if ($employeeId > 0) {
        $sql .= ' AND o.employee_id = ?';
        $params[] = $employeeId;
    }

    $sql .= ' ORDER BY e.emp_code ASC, e.id ASC';

    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** @param array<string, mixed> $post */
function hr_employee_overtime_save(PDO $pdo, array $post): int
{
    hr_employee_overtime_ensure_schema($pdo);

    $employeeId = (int) ($post['employee_id'] ?? 0);
    $year = (int) ($post['pay_year'] ?? 0);
    $month = (int) ($post['pay_month'] ?? 0);
    $hours = max(0.0, (float) ($post['overtime_hours'] ?? 0));
    $notes = trim((string) ($post['notes'] ?? ''));
    if (strlen($notes) > 255) {
        $notes = substr($notes, 0, 255);
    }

    hr_employee_overtime_assert_editable($pdo, $employeeId, $year, $month);

    $grossSalary = hr_overtime_employee_gross($pdo, $employeeId);
    if ($grossSalary <= 0) {
        throw new RuntimeException('الموظف ليس لديه راتب إجمالي — عرّف الراتب من شاشة رواتب الموظفين.');
    }

    $config = hr_overtime_load_config($pdo);
    $multiplier = hr_overtime_resolve_multiplier($config, (float) ($post['hour_multiplier'] ?? 0));
    $amount = hr_overtime_calc_amount(
        $grossSalary,
        $hours,
        $multiplier,
        (float) $config['monthly_work_days'],
        (float) $config['daily_work_hours']
    );

    $existing = hr_employee_overtime_get($pdo, $employeeId, $year, $month);
    if ($existing) {
        $st = $pdo->prepare(
            'UPDATE hr_employee_overtime SET overtime_hours = ?, hour_multiplier = ?, base_salary = ?,
             overtime_amount = ?, notes = ? WHERE id = ?'
        );
        $st->execute([$hours, $multiplier, $grossSalary, $amount, $notes !== '' ? $notes : null, (int) $existing['id']]);
        $id = (int) $existing['id'];
    } else {
        $st = $pdo->prepare(
            'INSERT INTO hr_employee_overtime
             (employee_id, pay_year, pay_month, overtime_hours, hour_multiplier, base_salary, overtime_amount, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $employeeId, $year, $month, $hours, $multiplier, $grossSalary, $amount,
            $notes !== '' ? $notes : null,
        ]);
        $id = (int) $pdo->lastInsertId();
    }

    hr_employee_overtime_sync_to_salary($pdo, $employeeId, $year, $month, $amount);

    return $id;
}

function hr_employee_overtime_delete(PDO $pdo, int $id): void
{
    hr_employee_overtime_ensure_schema($pdo);
    if ($id < 1) {
        throw new RuntimeException('سجل غير صالح.');
    }

    $st = $pdo->prepare('SELECT employee_id, pay_year, pay_month FROM hr_employee_overtime WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return;
    }

    $employeeId = (int) ($row['employee_id'] ?? 0);
    $year = (int) ($row['pay_year'] ?? 0);
    $month = (int) ($row['pay_month'] ?? 0);
    hr_employee_overtime_assert_editable($pdo, $employeeId, $year, $month);

    $pdo->prepare('DELETE FROM hr_employee_overtime WHERE id = ?')->execute([$id]);
    hr_employee_overtime_sync_to_salary($pdo, $employeeId, $year, $month, 0.0);
}

function hr_employee_overtime_sync_to_salary(PDO $pdo, int $employeeId, int $year, int $month, float $overtimeAmount): void
{
    if (!function_exists('hr_payroll_salary_row')) {
        require_once app_path('includes/hr_payroll_posting.php');
    }

    $sal = hr_payroll_salary_row($pdo, $employeeId, $year, $month);
    if (!$sal || (int) ($sal['is_posted'] ?? 0) === 1) {
        return;
    }

    $overtimeAmount = round(max(0.0, $overtimeAmount), 3);
    $base = (float) ($sal['base_salary'] ?? 0);
    $allow = (float) ($sal['allowances'] ?? 0);
    $deduct = (float) ($sal['deductions'] ?? 0);
    $bonus = (float) ($sal['bonus'] ?? 0);
    $ssEmp = (float) ($sal['social_security_emp'] ?? 0);
    $incomeTax = (float) ($sal['income_tax'] ?? 0);

    require_once app_path('includes/hr_income_tax.php');
    $salaryId = (int) ($sal['id'] ?? 0);
    if (hr_employee_subject_to_income_tax($pdo, $employeeId)) {
        $itCalc = hr_income_tax_calc_for_employee(
            $pdo,
            $employeeId,
            $year,
            $month,
            $base,
            $allow,
            $deduct,
            $overtimeAmount,
            $bonus,
            $ssEmp,
            $salaryId
        );
        $incomeTax = (float) ($itCalc['income_tax'] ?? $incomeTax);
    }

    $net = hr_salary_calc_net($base, $allow, $deduct, $overtimeAmount, $bonus, $ssEmp, $incomeTax);

    $pdo->prepare(
        'UPDATE hr_salary SET overtime = ?, income_tax = ?, net_salary = ? WHERE id = ?'
    )->execute([$overtimeAmount, $incomeTax, $net, (int) $sal['id']]);
}
