<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/inv_wh_move_delete.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !user_can_action('action_delete_warehouse_move')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'ليس لديك صلاحية الحذف.'], JSON_UNESCAPED_UNICODE);
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
    echo json_encode(['ok' => false, 'message' => 'معرّف الحركة مطلوب.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
inv_wh_move_ensure_schema($pdo);

$res = inv_wh_move_delete_by_id($pdo, $moveId);

echo json_encode([
    'ok' => (bool) ($res['ok'] ?? false),
    'message' => $res['message'] ?? $res['error'] ?? null,
    'error' => $res['error'] ?? null,
], JSON_UNESCAPED_UNICODE);
