<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');

function hr_social_security_rate_ensure_schema(PDO $pdo): void
{
    try {
        $pdo->query('SELECT id FROM hr_social_security_rate LIMIT 1');
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
        sql_migration_run_file($pdo, 'database/migrations/096_hr_social_security_rates.sql');
    } catch (Throwable $e) {
        try {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS hr_social_security_rate (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    rate_code VARCHAR(40) NULL,
                    employee_percent DECIMAL(6,3) NOT NULL DEFAULT 0.000,
                    employer_percent DECIMAL(6,3) NOT NULL DEFAULT 0.000,
                    description TEXT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uk_hr_ss_rate_code (rate_code)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        } catch (Throwable $e2) {
            // ignored
        }
    }
}

function hr_social_security_rate_next_code(PDO $pdo): string
{
    hr_social_security_rate_ensure_schema($pdo);
    $maxNum = 0;
    try {
        $maxNum = (int) $pdo->query(
            "SELECT COALESCE(MAX(CAST(rate_code AS UNSIGNED)), 0) FROM hr_social_security_rate
             WHERE rate_code REGEXP '^[0-9]+$'"
        )->fetchColumn();
    } catch (Throwable $e) {
        $maxNum = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM hr_social_security_rate')->fetchColumn();
    }

    return (string) ($maxNum + 1);
}

/**
 * @return array{code:string,emp_pct:float,er_pct:float,desc:?string,active:int}
 */
function hr_social_security_rate_parse_row(PDO $pdo, array $row, int $id): array
{
    $empPct = (float) ($row['employee_percent'] ?? 0);
    $erPct = (float) ($row['employer_percent'] ?? 0);
    $description = trim((string) ($row['description'] ?? ''));
    $isActive = !empty($row['is_active']) ? 1 : 0;

    if ($empPct < 0 || $empPct > 100) {
        throw new RuntimeException('نسبة الموظف يجب أن تكون بين 0 و 100.');
    }
    if ($erPct < 0 || $erPct > 100) {
        throw new RuntimeException('نسبة الشركة يجب أن تكون بين 0 و 100.');
    }

    $code = '';
    if ($id > 0) {
        $stCur = $pdo->prepare('SELECT rate_code FROM hr_social_security_rate WHERE id = ? LIMIT 1');
        $stCur->execute([$id]);
        $cur = $stCur->fetch(PDO::FETCH_ASSOC);
        if (!$cur) {
            throw new RuntimeException('النسبة غير موجودة.');
        }
        $code = trim((string) ($cur['rate_code'] ?? ''));
        if ($code === '' || !ctype_digit($code)) {
            $code = hr_social_security_rate_next_code($pdo);
        }
    } else {
        $code = hr_social_security_rate_next_code($pdo);
        if (!array_key_exists('is_active', $row)) {
            $isActive = 1;
        }
    }

    $stChk = $pdo->prepare('SELECT id FROM hr_social_security_rate WHERE rate_code = ? AND id <> ? LIMIT 1');
    $stChk->execute([$code, $id]);
    if ($stChk->fetchColumn()) {
        throw new RuntimeException('تعذر توليد رقم تسلسلي، أعد المحاولة.');
    }

    return [
        'code' => $code,
        'emp_pct' => round($empPct, 3),
        'er_pct' => round($erPct, 3),
        'desc' => $description !== '' ? $description : null,
        'active' => $isActive,
    ];
}

/**
 * @return array{can_delete:bool, usage_count:int, message:string}
 */
function hr_social_security_rate_delete_check(PDO $pdo, int $rateId): array
{
    if ($rateId < 1) {
        return ['can_delete' => false, 'usage_count' => 0, 'message' => 'معرّف غير صالح.'];
    }

    hr_social_security_rate_ensure_schema($pdo);

    $st = $pdo->prepare('SELECT rate_code FROM hr_social_security_rate WHERE id = ? LIMIT 1');
    $st->execute([$rateId]);
    if (!$st->fetchColumn()) {
        return ['can_delete' => false, 'usage_count' => 0, 'message' => 'النسبة غير موجودة.'];
    }

    return ['can_delete' => true, 'usage_count' => 0, 'message' => ''];
}

function hr_social_security_rate_format_pct(float $pct): string
{
    return rtrim(rtrim(number_format($pct, 3), '0'), '.') . ' %';
}
