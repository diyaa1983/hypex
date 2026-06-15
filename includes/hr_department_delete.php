<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');

/**
 * عدد الارتباطات (حركات/سجلات) المرتبطة بالقسم.
 */
function hr_department_usage_count(PDO $pdo, int $deptId, string $deptName = ''): int
{
    if ($deptId < 1) {
        return 0;
    }

    hr_department_ensure_schema($pdo);
    hr_job_title_ensure_schema($pdo);
    hr_employee_ensure_schema($pdo);

    $count = 0;

    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM hr_employee WHERE department_id = ?');
        $st->execute([$deptId]);
        $count += (int) $st->fetchColumn();
    } catch (Throwable $e) {
        // ignored
    }

    $name = trim($deptName);
    if ($name !== '') {
        try {
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM hr_employee
                 WHERE (department_id IS NULL OR department_id = 0)
                   AND TRIM(IFNULL(department, \'\')) = ?'
            );
            $st->execute([$name]);
            $count += (int) $st->fetchColumn();
        } catch (Throwable $e) {
            // ignored
        }
    }

    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM hr_job_title WHERE department_id = ?');
        $st->execute([$deptId]);
        $count += (int) $st->fetchColumn();
    } catch (Throwable $e) {
        // ignored
    }

    return $count;
}

/**
 * @return array{can_delete:bool, usage_count:int, message:string}
 */
function hr_department_delete_check(PDO $pdo, int $deptId): array
{
    if ($deptId < 1) {
        return ['can_delete' => false, 'usage_count' => 0, 'message' => 'معرّف القسم غير صالح.'];
    }

    hr_department_ensure_schema($pdo);

    $st = $pdo->prepare('SELECT dept_code, name_ar FROM hr_department WHERE id = ? LIMIT 1');
    $st->execute([$deptId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['can_delete' => false, 'usage_count' => 0, 'message' => 'القسم غير موجود.'];
    }

    $label = trim((string) ($row['name_ar'] ?? ''));
    if ($label === '') {
        $label = trim((string) ($row['dept_code'] ?? ''));
    }
    if ($label === '') {
        $label = (string) $deptId;
    }

    $count = hr_department_usage_count($pdo, $deptId, (string) ($row['name_ar'] ?? ''));
    if ($count > 0) {
        return [
            'can_delete' => false,
            'usage_count' => $count,
            'message' => 'لا يمكن حذف القسم «' . $label . '»: مرتبط بـ '
                . $count . ' حركة في النظام (موظفون، مسميات وظيفية، …).',
        ];
    }

    return ['can_delete' => true, 'usage_count' => 0, 'message' => ''];
}
