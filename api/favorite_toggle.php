<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/sys_favorites.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'غير مصرح.'], JSON_UNESCAPED_UNICODE);
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
    echo json_encode(['ok' => false, 'message' => 'انتهت صلاحية الجلسة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$screen = trim((string) ($_POST['screen'] ?? ''));
if ($screen === '') {
    echo json_encode(['ok' => false, 'message' => 'الشاشة غير محدّدة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = current_user();
$userId = (int) ($user['id'] ?? 0);
if ($userId < 1) {
    echo json_encode(['ok' => false, 'message' => 'مستخدم غير معروف.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = sys_favorites_toggle(db(), $userId, $screen);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
