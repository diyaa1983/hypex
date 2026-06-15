<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');

function hr_salary_bank_usage_count(PDO $pdo, int $bankId, string $bankName = ''): int
{
    if ($bankId < 1) {
        return 0;
    }

    hr_salary_bank_ensure_schema($pdo);
    hr_employee_ensure_schema($pdo);

    $count = 0;

    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM hr_employee WHERE salary_bank_id = ?');
        $st->execute([$bankId]);
        $count += (int) $st->fetchColumn();
    } catch (Throwable $e) {
        // ignored
    }

    $name = trim($bankName);
    if ($name !== '') {
        try {
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM hr_employee
                 WHERE (salary_bank_id IS NULL OR salary_bank_id = 0)
                   AND TRIM(IFNULL(bank_name, \'\')) = ?'
            );
            $st->execute([$name]);
            $count += (int) $st->fetchColumn();
        } catch (Throwable $e) {
            // ignored
        }
    }

    return $count;
}

/**
 * @return array{can_delete:bool, usage_count:int, message:string}
 */
function hr_salary_bank_delete_check(PDO $pdo, int $bankId): array
{
    if ($bankId < 1) {
        return ['can_delete' => false, 'usage_count' => 0, 'message' => 'معرّف البنك غير صالح.'];
    }

    hr_salary_bank_ensure_schema($pdo);

    $st = $pdo->prepare('SELECT bank_code, name_ar FROM hr_salary_bank WHERE id = ? LIMIT 1');
    $st->execute([$bankId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['can_delete' => false, 'usage_count' => 0, 'message' => 'البنك غير موجود.'];
    }

    $label = trim((string) ($row['name_ar'] ?? ''));
    if ($label === '') {
        $label = trim((string) ($row['bank_code'] ?? ''));
    }
    if ($label === '') {
        $label = (string) $bankId;
    }

    $count = hr_salary_bank_usage_count($pdo, $bankId, (string) ($row['name_ar'] ?? ''));
    if ($count > 0) {
        return [
            'can_delete' => false,
            'usage_count' => $count,
            'message' => 'لا يمكن حذف البنك «' . $label . '»: مرتبط بـ '
                . $count . ' حركة في النظام (موظفون، …).',
        ];
    }

    return ['can_delete' => true, 'usage_count' => 0, 'message' => ''];
}
