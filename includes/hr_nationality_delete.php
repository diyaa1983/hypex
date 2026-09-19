<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');

/**
 * عدد الارتباطات المرتبطة بالجنسية.
 */
function hr_nationality_usage_count(PDO $pdo, int $natId): int
{
    if ($natId < 1) {
        return 0;
    }

    hr_nationality_ensure_schema($pdo);
    hr_employee_ensure_schema($pdo);

    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM hr_employee WHERE nationality_id = ?');
        $st->execute([$natId]);
        return (int) $st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * @return array{can_delete:bool, usage_count:int, message:string}
 */
function hr_nationality_delete_check(PDO $pdo, int $natId): array
{
    if ($natId < 1) {
        return ['can_delete' => false, 'usage_count' => 0, 'message' => 'معرّف الجنسية غير صالح.'];
    }

    hr_nationality_ensure_schema($pdo);

    $st = $pdo->prepare('SELECT nat_code, name_ar FROM hr_nationality WHERE id = ? LIMIT 1');
    $st->execute([$natId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['can_delete' => false, 'usage_count' => 0, 'message' => 'الجنسية غير موجودة.'];
    }

    $label = trim((string) ($row['name_ar'] ?? ''));
    if ($label === '') {
        $label = trim((string) ($row['nat_code'] ?? ''));
    }
    if ($label === '') {
        $label = (string) $natId;
    }

    $count = hr_nationality_usage_count($pdo, $natId);
    if ($count > 0) {
        return [
            'can_delete' => false,
            'usage_count' => $count,
            'message' => 'لا يمكن حذف الجنسية «' . $label . '»: مرتبطة بـ '
                . $count . ' موظف في النظام.',
        ];
    }

    return ['can_delete' => true, 'usage_count' => 0, 'message' => ''];
}
