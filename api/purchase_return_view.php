<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/pur_return_schema.php');
require_once app_path('includes/pur_return_load.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !user_can_purchase_returns()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
pur_return_ensure_schema($pdo);

$id = (int) ($_GET['id'] ?? 0);
$no = trim((string) ($_GET['no'] ?? ''));
$dir = trim((string) ($_GET['dir'] ?? ''));
$edge = trim((string) ($_GET['edge'] ?? ''));

if ($edge === 'first') {
    $firstId = pur_return_first_id($pdo);
    if ($firstId === null) {
        echo json_encode([
            'ok' => false,
            'error' => 'empty',
            'message' => 'لا توجد مردودات مشتريات محفوظة.',
            'browse_count' => 0,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $id = $firstId;
}

if ($dir === 'prev' || $dir === 'next') {
    if ($id < 1) {
        echo json_encode(['ok' => false, 'error' => 'id_required'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $nid = pur_return_nav_neighbor_id($pdo, $id, $dir);
    if ($nid === null) {
        echo json_encode([
            'ok' => false,
            'error' => 'no_neighbor',
            'message' => $dir === 'prev' ? 'لا يوجد مردود أقدم.' : 'لا يوجد مردود أحدث.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $id = $nid;
}

$ret = null;
if ($id > 0) {
    $ret = pur_return_fetch_full($pdo, $id);
} elseif ($no !== '') {
    $ret = pur_return_fetch_by_no($pdo, $no);
}

if (!$ret) {
    echo json_encode([
        'ok' => false,
        'error' => 'not_found',
        'message' => 'المردود غير موجود.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true, 'return' => $ret], JSON_UNESCAPED_UNICODE);
