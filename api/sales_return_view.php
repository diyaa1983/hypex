<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/sal_return_schema.php');
require_once app_path('includes/sal_return_load.php');

header('Content-Type: application/json; charset=utf-8');

require_once app_path('includes/mobile_return.php');
if (!is_logged_in() || !user_can_mobile_sales_returns()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
sal_return_ensure_schema($pdo);

$id = (int) ($_GET['id'] ?? 0);
$no = trim((string) ($_GET['no'] ?? ''));
$dir = trim((string) ($_GET['dir'] ?? ''));
$edge = trim((string) ($_GET['edge'] ?? ''));

if ($edge === 'first') {
    $firstId = sal_return_first_id($pdo);
    if ($firstId === null) {
        echo json_encode([
            'ok' => false,
            'error' => 'empty',
            'message' => 'لا توجد مرتجعات محفوظة.',
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
    $nid = sal_return_nav_neighbor_id($pdo, $id, $dir);
    if ($nid === null) {
        echo json_encode([
            'ok' => false,
            'error' => 'no_neighbor',
            'message' => $dir === 'prev' ? 'لا يوجد مرتجع أقدم.' : 'لا يوجد مرتجع أحدث.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $id = $nid;
}

$ret = null;
if ($id > 0) {
    $ret = sal_return_fetch_full($pdo, $id);
} elseif ($no !== '') {
    $ret = sal_return_fetch_by_no($pdo, $no);
}

if (!$ret) {
    echo json_encode([
        'ok' => false,
        'error' => 'not_found',
        'message' => 'المرتجع غير موجود.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true, 'return' => $ret], JSON_UNESCAPED_UNICODE);
