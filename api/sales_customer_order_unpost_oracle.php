<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/sal_customer_order.php');
require_once app_path('includes/oracle_order_post.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !sal_customer_order_user_can_approve() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'لا توجد صلاحية.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode((string) file_get_contents('php://input'), true);
$data = is_array($data) ? $data : $_POST;
if (!verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($data['_csrf'] ?? null))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'انتهت صلاحية الجلسة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$id = (int) ($data['id'] ?? 0);
$result = oracle_unpost_customer_order(db(), $id, (int) (current_user()['id'] ?? 0));
http_response_code(!empty($result['ok']) ? 200 : 422);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
