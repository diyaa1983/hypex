<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/sal_delivery_delete.php');
require_once app_path('includes/sal_delivery_schema.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !user_can('sales_delivery') || !user_can_action('action_delete_sales_delivery')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!verify_csrf($_POST['_csrf'] ?? null)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'csrf'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
sal_delivery_ensure_schema($pdo);

$id = (int) ($_POST['delivery_id'] ?? 0);
$result = sal_delivery_delete_by_id($pdo, $id);

if (!$result['ok']) {
    echo json_encode(['ok' => false, 'error' => $result['error']], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'message' => 'تم حذف السند «' . ($result['delivery_no'] ?? '') . '».',
], JSON_UNESCAPED_UNICODE);
