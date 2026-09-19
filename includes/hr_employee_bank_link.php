<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');

/** هل للموظف ربط بنك مسجّل (بنك أو حساب أو اسم بنك محفوظ). */
function hr_employee_has_bank_link_row(array $row): bool
{
    return (int) ($row['salary_bank_id'] ?? 0) > 0
        || trim((string) ($row['bank_account'] ?? '')) !== ''
        || trim((string) ($row['bank_name'] ?? '')) !== '';
}

/**
 * @return array{can_clear:bool, usage_count:int, message:string}
 */
function hr_employee_bank_link_clear_check(PDO $pdo, int $employeeId): array
{
    if ($employeeId < 1) {
        return ['can_clear' => false, 'usage_count' => 0, 'message' => 'معرّف الموظف غير صالح.'];
    }

    hr_employee_ensure_schema($pdo);

    $st = $pdo->prepare('SELECT emp_code, name_ar FROM hr_employee WHERE id = ? LIMIT 1');
    $st->execute([$employeeId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['can_clear' => false, 'usage_count' => 0, 'message' => 'الموظف غير موجود.'];
    }

    $label = trim((string) ($row['name_ar'] ?? ''));
    if ($label === '') {
        $label = trim((string) ($row['emp_code'] ?? ''));
    }
    if ($label === '') {
        $label = (string) $employeeId;
    }

    $count = 0;
    try {
        $stSal = $pdo->prepare('SELECT COUNT(*) FROM hr_salary WHERE employee_id = ?');
        $stSal->execute([$employeeId]);
        $count = (int) $stSal->fetchColumn();
    } catch (Throwable $e) {
        $count = 0;
    }

    if ($count > 0) {
        return [
            'can_clear' => false,
            'usage_count' => $count,
            'message' => 'لا يمكن إزالة ربط البنك للموظف «' . $label . '»: مرتبط بـ '
                . $count . ' حركة رواتب في النظام.',
        ];
    }

    return ['can_clear' => true, 'usage_count' => 0, 'message' => ''];
}
