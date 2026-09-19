<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/sys_backup.php');

if (!is_logged_in()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'غير مصرح.';
    exit;
}

if (!user_can('system_backup')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'لا تملك صلاحية النسخ الاحتياطي.';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'طريقة غير مدعومة.';
    exit;
}

$dateFolder = trim((string) ($_GET['date'] ?? ''));
$fileKey = trim((string) ($_GET['file'] ?? 'bundle'));

try {
    $pdo = db();
    sys_backup_ensure_schema($pdo);
    $resolved = sys_backup_resolve_download($pdo, $dateFolder, $fileKey);
    if ($resolved === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'النسخة المطلوبة غير موجودة.';
        exit;
    }

    $downloadName = 'manager_backup_' . $dateFolder . '_' . $resolved['label'];
    sys_backup_stream_file($resolved['file'], $downloadName);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage() ?: 'تعذر تنزيل الملف.';
    exit;
}
