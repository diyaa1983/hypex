<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/sal_delivery_post.php');
require_once app_path('includes/sal_delivery_schema.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !user_can('sales_delivery') || !user_can_action('action_post_sales_delivery')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method'], JSON_UNESCAPED_UNICODE);
    exit;
}

$csrf = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!verify_csrf($csrf)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'csrf'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
sal_delivery_ensure_schema($pdo);

$ids = [];
if (isset($_POST['delivery_id'])) {
    $ids[] = (int) $_POST['delivery_id'];
}
$ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

if ($ids === []) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'لم يُحدَّد سند.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo->beginTransaction();
    $result = sal_delivery_post_by_ids($pdo, $ids);
    if ($result['errors'] !== []) {
        $pdo->rollBack();
    } else {
        $pdo->commit();
    }

    $posted = (int) $result['posted'];
    $skipped = (int) $result['skipped'];
    if ($posted > 0) {
        $msg = 'تم ترحيل السند — صُرف المخزون بدون ذمة على العميل.';
    } elseif ($skipped > 0) {
        $msg = 'السند مرحّل مسبقاً.';
    } else {
        $msg = 'لم يُرحَّل السند.';
    }
    if ($result['errors'] !== []) {
        $msg .= ' ' . implode('؛ ', array_slice($result['errors'], 0, 2));
    }

    echo json_encode([
        'ok' => $posted > 0 || ($result['errors'] === [] && $skipped > 0),
        'posted' => $posted,
        'skipped' => $skipped,
        'errors' => $result['errors'],
        'message' => $msg,
        'is_posted' => $posted > 0 || $skipped > 0,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
