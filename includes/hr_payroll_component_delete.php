<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');
require_once app_path('includes/hr_salary.php');
require_once app_path('includes/hr_employee_salary.php');
require_once app_path('includes/hr_employee_monthly_payroll.php');

/**
 * عدد الارتباطات المرتبطة ببند علاوة/اقتطاع (جاهز للتوسعة عند ربط الرواتب).
 */
function hr_payroll_component_usage_count(PDO $pdo, int $compId): int
{
    if ($compId < 1) {
        return 0;
    }

    hr_payroll_component_ensure_schema($pdo);
    $count = 0;

    hr_salary_line_ensure_schema($pdo);
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM hr_salary_line WHERE component_id = ?');
        $st->execute([$compId]);
        $count += (int) $st->fetchColumn();
    } catch (Throwable $e) {
        // ignored
    }

    hr_employee_salary_line_ensure_schema($pdo);
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM hr_employee_salary_line WHERE component_id = ?');
        $st->execute([$compId]);
        $count += (int) $st->fetchColumn();
    } catch (Throwable $e) {
        // ignored
    }

    hr_employee_monthly_payroll_line_ensure_schema($pdo);
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM hr_employee_monthly_payroll_line WHERE component_id = ?');
        $st->execute([$compId]);
        $count += (int) $st->fetchColumn();
    } catch (Throwable $e) {
        // ignored
    }

    foreach (['hr_salary_detail', 'hr_payroll_run_line'] as $tbl) {
        try {
            $pdo->query('SELECT id FROM ' . $tbl . ' LIMIT 1');
        } catch (Throwable $e) {
            continue;
        }
        try {
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM ' . $tbl . ' WHERE component_id = ? OR payroll_component_id = ?'
            );
            $st->execute([$compId, $compId]);
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
function hr_payroll_component_delete_check(PDO $pdo, int $compId): array
{
    if ($compId < 1) {
        return ['can_delete' => false, 'usage_count' => 0, 'message' => 'معرّف البند غير صالح.'];
    }

    hr_payroll_component_ensure_schema($pdo);

    $st = $pdo->prepare(
        'SELECT comp_code, name_ar, comp_type FROM hr_payroll_component WHERE id = ? LIMIT 1'
    );
    $st->execute([$compId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['can_delete' => false, 'usage_count' => 0, 'message' => 'البند غير موجود.'];
    }

    $label = trim((string) ($row['name_ar'] ?? ''));
    if ($label === '') {
        $label = trim((string) ($row['comp_code'] ?? ''));
    }
    if ($label === '') {
        $label = (string) $compId;
    }

    $count = hr_payroll_component_usage_count($pdo, $compId);
    if ($count > 0) {
        $typeLabel = (string) ($row['comp_type'] ?? '') === 'deduction' ? 'اقتطاع' : 'علاوة';

        return [
            'can_delete' => false,
            'usage_count' => $count,
            'message' => 'لا يمكن حذف ' . $typeLabel . ' «' . $label . '»: مرتبط بـ '
                . $count . ' حركة في النظام.',
        ];
    }

    return ['can_delete' => true, 'usage_count' => 0, 'message' => ''];
}
