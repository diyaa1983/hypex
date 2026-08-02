<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/sal_customer_order.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !sal_customer_order_user_can_delete_managed() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden', 'message' => 'لا توجد صلاحية حذف الطلب.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode((string) file_get_contents('php://input'), true);
$data = is_array($data) ? $data : $_POST;
if (!verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($data['_csrf'] ?? null))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'انتهت صلاحية الجلسة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = db();
    sal_customer_order_ensure_schema($pdo);
    sal_customer_order_delete($pdo, (int) ($data['id'] ?? 0), null);
    require_once app_path('includes/header_check_notifications.php');
    header_check_notifications_invalidate_cache();
    echo json_encode(['ok' => true, 'message' => 'تم حذف الطلب.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
