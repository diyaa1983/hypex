<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');

function hr_leave_type_ensure_schema(PDO $pdo): void
{
    hr_employee_ensure_schema($pdo);

    try {
        $pdo->query('SELECT id FROM hr_leave_type LIMIT 1');
    } catch (Throwable $e) {
        if (
            strpos($e->getMessage(), "doesn't exist") === false
            && strpos($e->getMessage(), 'no such table') === false
            && strpos($e->getMessage(), 'Base table or view not found') === false
        ) {
            return;
        }

        try {
            require_once app_path('includes/sql_migration.php');
            sql_migration_run_file($pdo, 'database/migrations/201_hr_leave_types.sql');
        } catch (Throwable $e2) {
            // ignored
        }
    }

    try {
        $pdo->query('SELECT prorate_yearly FROM hr_leave_type LIMIT 1');
    } catch (Throwable $e) {
        if (strpos($e->getMessage(), 'prorate_yearly') === false) {
            return;
        }
        try {
            require_once app_path('includes/sql_migration.php');
            sql_migration_run_file($pdo, 'database/migrations/204_hr_leave_type_prorate_yearly.sql');
        } catch (Throwable $e2) {
            // ignored
        }
        try {
            $pdo->exec(
                'ALTER TABLE hr_leave_type ADD COLUMN prorate_yearly TINYINT(1) NOT NULL DEFAULT 0 AFTER default_days'
            );
        } catch (Throwable $e3) {
            // column may already exist
        }
    }
}

function hr_leave_type_next_code(PDO $pdo): string
{
    hr_leave_type_ensure_schema($pdo);
    try {
        $max = (int) $pdo->query(
            "SELECT COALESCE(MAX(CAST(leave_code AS UNSIGNED)), 0) FROM hr_leave_type
             WHERE leave_code REGEXP '^[0-9]+$'"
        )->fetchColumn();

        return (string) ($max + 1);
    } catch (Throwable $e) {
        return '1';
    }
}

/**
 * @return array{leave_code:string,name_ar:string,default_days:float,is_active:int,prorate_yearly:int}
 */
function hr_leave_type_parse_row(array $row, PDO $pdo, int $id = 0): array
{
    hr_leave_type_ensure_schema($pdo);
    $name = trim((string) ($row['name_ar'] ?? ''));
    if ($name === '') {
        throw new RuntimeException('اسم نوع الإجازة مطلوب.');
    }

    if ($id > 0) {
        $st = $pdo->prepare('SELECT leave_code FROM hr_leave_type WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $code = trim((string) ($st->fetchColumn() ?: ''));
        if ($code === '') {
            throw new RuntimeException('نوع الإجازة غير موجود.');
        }
    } else {
        $code = hr_leave_type_next_code($pdo);
    }

    $defaultDays = (float) str_replace(',', '.', trim((string) ($row['default_days'] ?? '0')));
    if ($defaultDays < 0) {
        throw new RuntimeException('عدد الأيام لا يمكن أن يكون سالباً.');
    }

    $stChk = $pdo->prepare('SELECT id FROM hr_leave_type WHERE leave_code = ? AND id <> ? LIMIT 1');
    $stChk->execute([$code, $id]);
    if ($stChk->fetchColumn()) {
        throw new RuntimeException('رقم الإجازة مستخدم مسبقاً.');
    }

    return [
        'leave_code' => $code,
        'name_ar' => $name,
        'default_days' => round($defaultDays, 2),
        'is_active' => !empty($row['is_active']) ? 1 : 0,
        'prorate_yearly' => !empty($row['prorate_yearly']) ? 1 : 0,
    ];
}

/**
 * @return list<array<string,mixed>>
 */
function hr_leave_type_list(PDO $pdo, bool $activeOnly = false): array
{
    hr_leave_type_ensure_schema($pdo);
    $hasProrate = true;
    try {
        $pdo->query('SELECT prorate_yearly FROM hr_leave_type LIMIT 1');
    } catch (Throwable $e) {
        $hasProrate = false;
    }

    $sql = 'SELECT id, leave_code, name_ar, default_days';
    if ($hasProrate) {
        $sql .= ', prorate_yearly';
    }
    $sql .= ', is_active FROM hr_leave_type';
    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY CAST(leave_code AS UNSIGNED) ASC, id ASC';

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$hasProrate) {
        foreach ($rows as &$row) {
            $row['prorate_yearly'] = 0;
        }
        unset($row);
    }

    return $rows;
}

function hr_leave_type_delete_check(PDO $pdo, int $id): array
{
    if ($id < 1) {
        return ['can_delete' => false, 'message' => 'معرّف غير صالح.'];
    }
    hr_leave_type_ensure_schema($pdo);

    foreach (['hr_employee_leave_balance', 'hr_employee_leave'] as $table) {
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE leave_type_id = ?");
            $st->execute([$id]);
            if ((int) $st->fetchColumn() > 0) {
                return ['can_delete' => false, 'message' => 'لا يمكن حذف نوع إجازة مستخدم في النظام.'];
            }
        } catch (Throwable $e) {
            // table may not exist yet
        }
    }

    return ['can_delete' => true, 'message' => ''];
}
