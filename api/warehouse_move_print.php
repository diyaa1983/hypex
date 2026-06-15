<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/warehouse_move_print.php';

if (!is_logged_in() || !user_can('warehouse_moves')) {
    http_response_code(403);
    exit('Forbidden');
}

$moveId = (int) ($_GET['id'] ?? 0);
$format = strtolower(trim((string) ($_GET['format'] ?? 'html')));
$embed = isset($_GET['embed']) && (string) $_GET['embed'] === '1';

if ($moveId < 1) {
    http_response_code(400);
    if ($embed) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'معرّف الحركة مطلوب.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    exit('معرّف الحركة مطلوب.');
}

$pdo = db();
inv_wh_move_ensure_schema($pdo);
$built = warehouse_move_print_build($pdo, $moveId);

if ($embed) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'html' => $built['html'],
        'title' => $built['title'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

document_print_emit_standalone_page(
    $built['title'],
    $built['html'],
    $pdo,
    $format !== 'html' || !isset($_GET['preview'])
);
