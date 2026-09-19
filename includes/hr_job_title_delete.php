<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');

function hr_job_title_usage_count(PDO $pdo, int $jtId, string $jtName = ''): int
{
    if ($jtId < 1) {
        return 0;
    }

    hr_job_title_ensure_schema($pdo);
    hr_employee_ensure_schema($pdo);

    $count = 0;

    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM hr_employee WHERE job_title_id = ?');
        $st->execute([$jtId]);
        $count += (int) $st->fetchColumn();
    } catch (Throwable $e) {
        // ignored
    }

    $name = trim($jtName);
    if ($name !== '') {
        try {
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM hr_employee
                 WHERE (job_title_id IS NULL OR job_title_id = 0)
                   AND TRIM(IFNULL(job_title, \'\')) = ?'
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
function hr_job_title_delete_check(PDO $pdo, int $jtId): array
{
    if ($jtId < 1) {
        return ['can_delete' => false, 'usage_count' => 0, 'message' => 'معرّف المسمى غير صالح.'];
    }

    hr_job_title_ensure_schema($pdo);

    $st = $pdo->prepare('SELECT title_code, name_ar FROM hr_job_title WHERE id = ? LIMIT 1');
    $st->execute([$jtId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['can_delete' => false, 'usage_count' => 0, 'message' => 'المسمى الوظيفي غير موجود.'];
    }

    $label = trim((string) ($row['name_ar'] ?? ''));
    if ($label === '') {
        $label = trim((string) ($row['title_code'] ?? ''));
    }
    if ($label === '') {
        $label = (string) $jtId;
    }

    $count = hr_job_title_usage_count($pdo, $jtId, (string) ($row['name_ar'] ?? ''));
    if ($count > 0) {
        return [
            'can_delete' => false,
            'usage_count' => $count,
            'message' => 'لا يمكن حذف المسمى «' . $label . '»: مرتبط بـ '
                . $count . ' حركة في النظام (موظفون، …).',
        ];
    }

    return ['can_delete' => true, 'usage_count' => 0, 'message' => ''];
}
