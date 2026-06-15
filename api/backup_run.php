<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/sys_backup.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'غير مصرح.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!user_can('system_backup')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'لا تملك صلاحية النسخ الاحتياطي.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'طريقة غير مدعومة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$csrf = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!verify_csrf($csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'انتهت صلاحية الجلسة، أعد المحاولة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = db();
    sys_backup_ensure_schema($pdo);
    $userId = (int) (current_user()['id'] ?? 0);
    $result = sys_backup_run($pdo, $userId);
    echo json_encode([
        'ok' => (bool) ($result['ok'] ?? false),
        'message' => (string) ($result['message'] ?? ''),
        'path' => (string) ($result['path'] ?? ''),
        'date_folder' => (string) ($result['date_folder'] ?? ''),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => $e->getMessage() ?: 'تعذر إتمام النسخ الاحتياطي.',
    ], JSON_UNESCAPED_UNICODE);
}
