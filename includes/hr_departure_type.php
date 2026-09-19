<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');

function hr_departure_type_ensure_schema(PDO $pdo): void
{
    hr_employee_ensure_schema($pdo);

    try {
        $pdo->query('SELECT id FROM hr_departure_type LIMIT 1');

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
        sql_migration_run_file($pdo, 'database/migrations/199_hr_departure_types.sql');
    } catch (Throwable $e) {
        // ignored
    }
}

function hr_departure_type_next_code(PDO $pdo): string
{
    hr_departure_type_ensure_schema($pdo);
    try {
        $max = (int) $pdo->query(
            "SELECT COALESCE(MAX(CAST(type_code AS UNSIGNED)), 0) FROM hr_departure_type
             WHERE type_code REGEXP '^[0-9]+$'"
        )->fetchColumn();

        return (string) ($max + 1);
    } catch (Throwable $e) {
        return '1';
    }
}

/**
 * @return array{type_code:string,name_ar:string,is_active:int}
 */
function hr_departure_type_parse_row(array $row, PDO $pdo, int $id = 0): array
{
    hr_departure_type_ensure_schema($pdo);
    $name = trim((string) ($row['name_ar'] ?? ''));
    if ($name === '') {
        throw new RuntimeException('اسم نوع المغادرة مطلوب.');
    }

    if ($id > 0) {
        $st = $pdo->prepare('SELECT type_code FROM hr_departure_type WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $code = trim((string) ($st->fetchColumn() ?: ''));
        if ($code === '') {
            throw new RuntimeException('نوع المغادرة غير موجود.');
        }
    } else {
        $code = hr_departure_type_next_code($pdo);
    }

    $stChk = $pdo->prepare('SELECT id FROM hr_departure_type WHERE type_code = ? AND id <> ? LIMIT 1');
    $stChk->execute([$code, $id]);
    if ($stChk->fetchColumn()) {
        throw new RuntimeException('رقم نوع المغادرة مستخدم مسبقاً.');
    }

    $stName = $pdo->prepare('SELECT id FROM hr_departure_type WHERE name_ar = ? AND id <> ? LIMIT 1');
    $stName->execute([$name, $id]);
    if ($stName->fetchColumn()) {
        throw new RuntimeException('اسم نوع المغادرة مستخدم مسبقاً.');
    }

    return [
        'type_code' => $code,
        'name_ar' => $name,
        'is_active' => !empty($row['is_active']) ? 1 : 0,
    ];
}

/**
 * @return list<array<string,mixed>>
 */
function hr_departure_type_list(PDO $pdo, bool $activeOnly = false): array
{
    hr_departure_type_ensure_schema($pdo);
    $sql = 'SELECT id, type_code, name_ar, is_active
            FROM hr_departure_type';
    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY CAST(type_code AS UNSIGNED) ASC, id ASC';

    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function hr_departure_type_delete_check(PDO $pdo, int $id): array
{
    hr_departure_type_ensure_schema($pdo);
    if ($id < 1) {
        return ['can_delete' => false, 'message' => 'معرّف غير صالح.'];
    }

    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM hr_employee_departure WHERE departure_type_id = ?');
        $st->execute([$id]);
        if ((int) $st->fetchColumn() > 0) {
            return ['can_delete' => false, 'message' => 'لا يمكن حذف نوع مغادرة مستخدم في سندات مغادرة.'];
        }
    } catch (Throwable $e) {
        // table may not exist yet
    }

    return ['can_delete' => true, 'message' => ''];
}
