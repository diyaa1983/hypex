<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/inv_wh_move_load.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !user_can('warehouse_moves')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
inv_wh_move_ensure_schema($pdo);

$id = (int) ($_GET['id'] ?? 0);
$no = trim((string) ($_GET['no'] ?? ''));
$dir = trim((string) ($_GET['dir'] ?? ''));
$edge = trim((string) ($_GET['edge'] ?? ''));

if ($edge === 'first') {
    $firstId = inv_wh_move_first_id($pdo);
    if ($firstId === null) {
        echo json_encode([
            'ok' => false,
            'error' => 'empty',
            'message' => 'لا توجد حركات مستودع محفوظة بعد.',
            'browse_count' => 0,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $id = $firstId;
}

if ($dir === 'prev' || $dir === 'next') {
    if ($id < 1) {
        echo json_encode(['ok' => false, 'error' => 'invalid_id'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $navId = inv_wh_move_nav_neighbor_id($pdo, $id, $dir);
    if ($navId === null) {
        echo json_encode([
            'ok' => false,
            'error' => 'no_more',
            'message' => $dir === 'prev' ? 'لا توجد حركة أقدم.' : 'لا توجد حركة أحدث.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $id = $navId;
}

$move = null;
if ($id > 0) {
    $move = inv_wh_move_fetch_by_id($pdo, $id);
} elseif ($no !== '') {
    $move = inv_wh_move_fetch_by_no($pdo, $no);
}

if ($move === null) {
    echo json_encode([
        'ok' => false,
        'error' => 'not_found',
        'message' => 'لم يتم العثور على حركة بهذا الرقم.',
        'browse_count' => inv_wh_move_count_all($pdo),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'move' => $move,
    'browse_count' => (int) ($move['browse_count'] ?? 0),
], JSON_UNESCAPED_UNICODE);
