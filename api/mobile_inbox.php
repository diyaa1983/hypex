<?php
declare(strict_types=1);

/**
 * صندوق إشعارات المندوب على الموبايل/التاب.
 * GET: القائمة + عدد غير المقروء
 * POST: mark_read / mark_all_read
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/mobile_auth.php');
require_once app_path('includes/sys_user_inbox.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!is_logged_in() || !mobile_is_context() || !user_in_mobile_group()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized', 'message' => 'الجلسة منتهية.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$uid = (int) (current_user()['id'] ?? 0);
$pdo = db();
sys_user_inbox_ensure($pdo);

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'POST') {
    $body = $_POST;
    $raw = file_get_contents('php://input');
    if (is_string($raw) && $raw !== '' && str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $body = $decoded;
        }
    }
    if (!verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($body['_csrf'] ?? null))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'csrf', 'message' => 'انتهت صلاحية الجلسة.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $action = strtolower(trim((string) ($body['action'] ?? 'mark_read')));
    if ($action === 'mark_all_read') {
        sys_user_inbox_mark_all_read($pdo, $uid);
    } else {
        $ids = $body['ids'] ?? [];
        if (!is_array($ids) && isset($body['id'])) {
            $ids = [$body['id']];
        }
        if (is_array($ids)) {
            sys_user_inbox_mark_read($pdo, $uid, $ids);
        }
    }
}

$items = sys_user_inbox_list($pdo, $uid, 50);
echo json_encode([
    'ok' => true,
    'unread_count' => sys_user_inbox_unread_count($pdo, $uid),
    'items' => $items,
], JSON_UNESCAPED_UNICODE);
