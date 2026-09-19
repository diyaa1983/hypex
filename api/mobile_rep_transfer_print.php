<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/mobile_rep_custody.php');
require_once app_path('includes/mobile_rep_transfer_print.php');
require_once app_path('includes/inv_wh_move_schema.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$moveId = (int) ($_GET['id'] ?? 0);
$direction = trim((string) ($_GET['direction'] ?? 'load'));
$perm = $direction === 'return' ? 'm_rep_return' : 'm_rep_load';

if ($moveId < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!user_can($perm)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
inv_wh_move_ensure_schema($pdo);
$ctx = mobile_rep_custody_context($pdo);
if ($ctx === null) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'no_rep'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int) (current_user()['id'] ?? 0);
if (!mobile_rep_custody_move_belongs_to_rep($pdo, $ctx, $direction, $moveId, $userId)) {
    $alt = $direction === 'return' ? 'load' : 'return';
    if (!mobile_rep_custody_move_belongs_to_rep($pdo, $ctx, $alt, $moveId, $userId)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'not_found'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $direction = $alt;
}

$doc = mobile_rep_transfer_print_document($pdo, $moveId, $ctx, $direction);
if ($doc === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(array_merge(['ok' => true], $doc), JSON_UNESCAPED_UNICODE);
