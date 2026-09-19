<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/pur_order_load.php');
require_once app_path('includes/pur_order_browse.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !user_can_purchase_orders()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
pur_order_ensure_schema($pdo);

$id = (int) ($_GET['id'] ?? 0);
$no = trim((string) ($_GET['no'] ?? ''));
$dir = trim((string) ($_GET['dir'] ?? ''));
$edge = trim((string) ($_GET['edge'] ?? ''));
$filter = pur_order_normalize_browse_filter((string) ($_GET['filter'] ?? 'all'));

if ($edge === 'first') {
    $firstId = pur_order_first_in_filter($pdo, $filter);
    if ($firstId === null) {
        echo json_encode([
            'ok' => false,
            'error' => 'empty',
            'message' => 'لا توجد طلبات في هذا التصفّح.',
            'browse_filter' => $filter,
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
    $navId = pur_order_nav_neighbor_id($pdo, $id, $dir, $filter);
    if ($navId === null) {
        echo json_encode([
            'ok' => false,
            'error' => 'no_more',
            'message' => $dir === 'prev' ? 'لا يوجد طلب أقدم في هذا التصفّح.' : 'لا يوجد طلب أحدث في هذا التصفّح.',
            'browse_filter' => $filter,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $id = $navId;
}

$order = null;
if ($id > 0) {
    $order = pur_order_fetch_by_id($pdo, $id, $filter);
} elseif ($no !== '') {
    $order = pur_order_fetch_by_no($pdo, $no, $filter);
}

if (!$order) {
    echo json_encode([
        'ok' => false,
        'error' => 'not_found',
        'message' => 'طلب الشراء غير موجود أو لا يطابق التصفّح الحالي.',
        'browse_filter' => $filter,
        'browse_count' => pur_order_count_in_filter($pdo, $filter),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true, 'order' => $order, 'invoice' => $order], JSON_UNESCAPED_UNICODE);
