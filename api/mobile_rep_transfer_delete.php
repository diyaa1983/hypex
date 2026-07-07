<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/mobile_rep_custody.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'غير مصرح.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$direction = trim((string) ($_POST['direction'] ?? $_GET['direction'] ?? 'load'));
$perm = $direction === 'return' ? 'm_rep_return' : 'm_rep_load';
if (!user_can($perm) && !user_can('m_rep_custody_list')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'لا توجد صلاحية.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!mobile_can_delete_rep_custody($direction)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'ليس لديك صلاحية حذف العهدة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'طريقة غير مسموحة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$csrf = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!verify_csrf($csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'انتهت صلاحية الجلسة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$moveId = (int) ($_POST['move_id'] ?? 0);
if ($moveId < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'معرّف العهدة مطلوب.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
inv_wh_move_ensure_schema($pdo);
$ctx = mobile_rep_custody_context($pdo);
if ($ctx === null) {
    echo json_encode([
        'ok' => false,
        'message' => 'حسابك غير مربوط بمندوب أو مستودع عهدة.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int) (current_user()['id'] ?? 0);
$res = mobile_rep_custody_delete_move($pdo, $ctx, $direction, $moveId, $userId);

echo json_encode([
    'ok' => (bool) ($res['ok'] ?? false),
    'message' => $res['message'] ?? $res['error'] ?? null,
    'error' => $res['error'] ?? null,
], JSON_UNESCAPED_UNICODE);
