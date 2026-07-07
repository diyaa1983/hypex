<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/mobile_rep_custody.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$direction = trim((string) ($_GET['direction'] ?? 'load'));
$perm = $direction === 'return' ? 'm_rep_return' : 'm_rep_load';
if (!user_can($perm)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$moveId = (int) ($_GET['id'] ?? 0);
if ($moveId < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
inv_wh_move_ensure_schema($pdo);
$ctx = mobile_rep_custody_context($pdo);
if ($ctx === null) {
    echo json_encode([
        'ok' => false,
        'error' => 'no_rep',
        'message' => 'حسابك غير مربوط بمندوب أو مستودع عهدة.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int) (current_user()['id'] ?? 0);
$move = mobile_rep_custody_fetch_for_edit($pdo, $ctx, $direction, $moveId, $userId);
if ($move === null) {
    http_response_code(404);
    echo json_encode([
        'ok' => false,
        'error' => 'not_editable',
        'message' => 'العهدة غير موجودة أو مرحّلة ولا يمكن تعديلها.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'move' => $move,
], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
