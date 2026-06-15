<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/inv_price_adj_load.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !user_can('item_sale_price_adjust')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
try {
    if (!inv_price_adj_ensure_schema($pdo)) {
        echo json_encode([
            'ok' => false,
            'error' => 'schema',
            'message' => 'جداول تعديل الأسعار غير جاهزة. نفّذ ترحيل 091_inv_price_adj_doc.sql.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => 'schema',
        'message' => 'تعذر تهيئة جداول تعديل الأسعار.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
$no = trim((string) ($_GET['no'] ?? ''));
$dir = trim((string) ($_GET['dir'] ?? ''));
$edge = trim((string) ($_GET['edge'] ?? ''));

if ($edge === 'first') {
    $firstId = inv_price_adj_first_id($pdo);
    if ($firstId === null) {
        echo json_encode([
            'ok' => false,
            'error' => 'empty',
            'message' => 'لا توجد حركات تعديل أسعار محفوظة بعد.',
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
    $navId = inv_price_adj_nav_neighbor_id($pdo, $id, $dir);
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

$doc = null;
if ($id > 0) {
    $doc = inv_price_adj_fetch_by_id($pdo, $id);
} elseif ($no !== '') {
    $doc = inv_price_adj_fetch_by_no($pdo, $no);
}

if ($doc === null) {
    echo json_encode([
        'ok' => false,
        'error' => 'not_found',
        'message' => 'لم يتم العثور على حركة بهذا الرقم.',
        'browse_count' => inv_price_adj_count_all($pdo),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'doc' => $doc,
    'browse_count' => (int) ($doc['browse_count'] ?? 0),
], JSON_UNESCAPED_UNICODE);
