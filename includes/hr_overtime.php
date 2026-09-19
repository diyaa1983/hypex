<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');

/** @return array{hour_multiplier: float, hour_multiplier_b: float, monthly_work_days: float, daily_work_hours: float, monthly_work_hours: float} */
function hr_overtime_default_config(): array
{
    return [
        'hour_multiplier' => 1.25,
        'hour_multiplier_b' => 1.5,
        'monthly_work_days' => 30.0,
        'daily_work_hours' => 8.0,
        'monthly_work_hours' => 240.0,
    ];
}

function hr_overtime_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    hr_employee_ensure_schema($pdo);
    try {
        $pdo->query('SELECT id FROM hr_overtime_config LIMIT 1');
        try {
            $pdo->query('SELECT hour_multiplier_b FROM hr_overtime_config LIMIT 1');
        } catch (Throwable $e) {
            try {
                require_once app_path('includes/sql_migration.php');
                sql_migration_run_file($pdo, 'database/migrations/187_hr_overtime_multiplier_b.sql');
            } catch (Throwable $e2) {
                try {
                    $pdo->exec(
                        'ALTER TABLE hr_overtime_config
                         ADD COLUMN hour_multiplier_b DECIMAL(6,3) NOT NULL DEFAULT 1.500 AFTER hour_multiplier'
                    );
                } catch (Throwable $e3) {
                    // ignored
                }
            }
        }
        try {
            $pdo->query('SELECT monthly_work_days FROM hr_overtime_config LIMIT 1');
        } catch (Throwable $e) {
            try {
                require_once app_path('includes/sql_migration.php');
                sql_migration_run_file($pdo, 'database/migrations/186_hr_overtime_days_hours.sql');
            } catch (Throwable $e2) {
                // ignored
            }
        }
        $done = true;

        return;
    } catch (Throwable $e) {
        if (
            strpos($e->getMessage(), "doesn't exist") === false
            && strpos($e->getMessage(), 'no such table') === false
            && strpos($e->getMessage(), 'Base table or view not found') === false
        ) {
            $done = true;

            return;
        }
    }

    try {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/185_hr_overtime.sql');
        sql_migration_run_file($pdo, 'database/migrations/186_hr_overtime_days_hours.sql');
        sql_migration_run_file($pdo, 'database/migrations/187_hr_overtime_multiplier_b.sql');
    } catch (Throwable $e) {
        try {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS hr_overtime_config (
                    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
                    hour_multiplier DECIMAL(6,3) NOT NULL DEFAULT 1.250,
                    hour_multiplier_b DECIMAL(6,3) NOT NULL DEFAULT 1.500,
                    monthly_work_days DECIMAL(6,3) NOT NULL DEFAULT 30.000,
                    daily_work_hours DECIMAL(6,3) NOT NULL DEFAULT 8.000,
                    monthly_work_hours DECIMAL(8,3) NOT NULL DEFAULT 240.000,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
            $pdo->exec('INSERT INTO hr_overtime_config (id) VALUES (1) ON DUPLICATE KEY UPDATE id = id');
        } catch (Throwable $e2) {
            // ignored
        }
    }
    $done = true;
}

/** @param array<string, mixed> $row */
function hr_overtime_normalize_config_row(array $row): array
{
    $defaults = hr_overtime_default_config();
    $days = max(1.0, (float) ($row['monthly_work_days'] ?? $defaults['monthly_work_days']));
    $dailyHours = max(0.01, (float) ($row['daily_work_hours'] ?? $defaults['daily_work_hours']));

    return [
        'hour_multiplier' => max(0.01, (float) ($row['hour_multiplier'] ?? $defaults['hour_multiplier'])),
        'hour_multiplier_b' => max(0.01, (float) ($row['hour_multiplier_b'] ?? $defaults['hour_multiplier_b'])),
        'monthly_work_days' => $days,
        'daily_work_hours' => $dailyHours,
        'monthly_work_hours' => round($days * $dailyHours, 3),
    ];
}

/**
 * @return list<array{value: float, label: string}>
 */
function hr_overtime_multiplier_options(array $config): array
{
    $a = max(0.01, (float) ($config['hour_multiplier'] ?? 1.25));
    $b = max(0.01, (float) ($config['hour_multiplier_b'] ?? 1.5));
    $options = [
        ['value' => $a, 'label' => hr_overtime_multiplier_label($a)],
    ];
    if (abs($a - $b) > 0.0005) {
        $options[] = ['value' => $b, 'label' => hr_overtime_multiplier_label($b)];
    }

    return $options;
}

function hr_overtime_multiplier_matches(float $value, float $candidate): bool
{
    return abs($value - $candidate) < 0.0005;
}

/** @param array<string, mixed> $config */
function hr_overtime_resolve_multiplier(array $config, float $requested): float
{
    $options = hr_overtime_multiplier_options($config);
    foreach ($options as $opt) {
        if (hr_overtime_multiplier_matches($requested, (float) $opt['value'])) {
            return (float) $opt['value'];
        }
    }

    return (float) $options[0]['value'];
}

/** @return array{hour_multiplier: float, hour_multiplier_b: float, monthly_work_days: float, daily_work_hours: float, monthly_work_hours: float} */
function hr_overtime_load_config(PDO $pdo): array
{
    hr_overtime_ensure_schema($pdo);
    $defaults = hr_overtime_default_config();
    try {
        $row = $pdo->query(
            'SELECT hour_multiplier, hour_multiplier_b, monthly_work_days, daily_work_hours, monthly_work_hours
             FROM hr_overtime_config WHERE id = 1 LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $defaults;
        }
        if (
            !array_key_exists('monthly_work_days', $row)
            || (float) ($row['monthly_work_days'] ?? 0) <= 0
            || !array_key_exists('daily_work_hours', $row)
            || (float) ($row['daily_work_hours'] ?? 0) <= 0
        ) {
            $legacyHours = max(1.0, (float) ($row['monthly_work_hours'] ?? $defaults['monthly_work_hours']));
            $row['monthly_work_days'] = $defaults['monthly_work_days'];
            $row['daily_work_hours'] = $defaults['daily_work_hours'];
            if ($legacyHours > 0 && abs($legacyHours - 240.0) > 0.001) {
                $row['daily_work_hours'] = round($legacyHours / $row['monthly_work_days'], 3);
            }
        }

        return hr_overtime_normalize_config_row($row);
    } catch (Throwable $e) {
        try {
            $row = $pdo->query('SELECT hour_multiplier, monthly_work_hours FROM hr_overtime_config WHERE id = 1 LIMIT 1')
                ->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return $defaults;
            }
            $row['monthly_work_days'] = $defaults['monthly_work_days'];
            $row['daily_work_hours'] = $defaults['daily_work_hours'];
            $row['hour_multiplier_b'] = $defaults['hour_multiplier_b'];

            return hr_overtime_normalize_config_row($row);
        } catch (Throwable $e2) {
            return $defaults;
        }
    }
}

/** @param array<string, float|string> $data */
function hr_overtime_save_config(PDO $pdo, array $data): void
{
    hr_overtime_ensure_schema($pdo);
    $normalized = hr_overtime_normalize_config_row([
        'hour_multiplier' => $data['hour_multiplier'] ?? 1.25,
        'hour_multiplier_b' => $data['hour_multiplier_b'] ?? 1.5,
        'monthly_work_days' => $data['monthly_work_days'] ?? 30,
        'daily_work_hours' => $data['daily_work_hours'] ?? 8,
    ]);
    $st = $pdo->prepare(
        'INSERT INTO hr_overtime_config (id, hour_multiplier, hour_multiplier_b, monthly_work_days, daily_work_hours, monthly_work_hours)
         VALUES (1, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           hour_multiplier = VALUES(hour_multiplier),
           hour_multiplier_b = VALUES(hour_multiplier_b),
           monthly_work_days = VALUES(monthly_work_days),
           daily_work_hours = VALUES(daily_work_hours),
           monthly_work_hours = VALUES(monthly_work_hours)'
    );
    $st->execute([
        $normalized['hour_multiplier'],
        $normalized['hour_multiplier_b'],
        $normalized['monthly_work_days'],
        $normalized['daily_work_hours'],
        $normalized['monthly_work_hours'],
    ]);
}

function hr_overtime_monthly_hours(float $monthlyWorkDays, float $dailyWorkHours): float
{
    return max(1.0, $monthlyWorkDays) * max(0.01, $dailyWorkHours);
}

/** أجر الساعة = إجمالي الراتب ÷ أيام الشهر ÷ ساعات اليوم. */
function hr_overtime_hourly_rate(float $salaryBasis, float $monthlyWorkDays, float $dailyWorkHours): float
{
    $divisor = hr_overtime_monthly_hours($monthlyWorkDays, $dailyWorkHours);
    if ($salaryBasis <= 0 || $divisor <= 0) {
        return 0.0;
    }

    return round($salaryBasis / $divisor, 6);
}

/** إجمالي الراتب من شاشة رواتب الموظفين (أساسي + علاوات). */
function hr_overtime_employee_gross(PDO $pdo, int $employeeId): float
{
    if ($employeeId < 1) {
        return 0.0;
    }
    if (!function_exists('hr_employee_payroll_gross')) {
        require_once app_path('includes/hr_social_security_payroll.php');
    }

    return max(0.0, hr_employee_payroll_gross($pdo, $employeeId));
}

/** مبلغ العمل الإضافي = الساعات × (أجر الساعة × مضاعف الساعة). */
function hr_overtime_calc_amount(
    float $salaryBasis,
    float $overtimeHours,
    float $hourMultiplier,
    float $monthlyWorkDays,
    float $dailyWorkHours
): float {
    if ($overtimeHours <= 0 || $salaryBasis <= 0) {
        return 0.0;
    }
    $hourly = hr_overtime_hourly_rate($salaryBasis, $monthlyWorkDays, $dailyWorkHours);
    $overtimeHourly = $hourly * $hourMultiplier;

    return round($overtimeHours * $overtimeHourly, 3);
}

/** @param array<string, mixed>|null $config */
function hr_overtime_calc_amount_with_config(
    float $salaryBasis,
    float $overtimeHours,
    ?array $config = null
): float {
    $cfg = $config ?? hr_overtime_default_config();

    return hr_overtime_calc_amount(
        $salaryBasis,
        $overtimeHours,
        (float) ($cfg['hour_multiplier'] ?? 1.25),
        (float) ($cfg['monthly_work_days'] ?? 30),
        (float) ($cfg['daily_work_hours'] ?? 8)
    );
}

function hr_overtime_multiplier_label(float $multiplier): string
{
    if (abs($multiplier - 1.0) < 0.001) {
        return 'ساعة = ساعة';
    }
    if (abs($multiplier - 1.25) < 0.001) {
        return 'ساعة = ساعة وربع';
    }
    if (abs($multiplier - 1.5) < 0.001) {
        return 'ساعة = ساعة ونصف';
    }
    if (abs($multiplier - 2.0) < 0.001) {
        return 'ساعة = ساعتان';
    }

    return 'ساعة = ' . rtrim(rtrim(number_format($multiplier, 3, '.', ''), '0'), '.') . ' ساعة';
}

function hr_overtime_multiplier_display(float $multiplier): string
{
    return rtrim(rtrim(number_format($multiplier, 3, '.', ''), '0'), '.')
        . ' — ' . hr_overtime_multiplier_label($multiplier);
}
