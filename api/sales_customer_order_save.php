<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/sal_customer_order.php');
header('Content-Type: application/json; charset=utf-8');
if (!is_logged_in() || !sal_customer_order_user_can_edit_drafts() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden', 'message' => 'لا توجد صلاحية حفظ طلب شراء عميل.'], JSON_UNESCAPED_UNICODE);
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
    $id = sal_customer_order_save(
        $pdo,
        $data,
        is_array($data['lines'] ?? null) ? $data['lines'] : [],
        (int) (current_user()['id'] ?? 0)
    );
    require_once app_path('includes/header_check_notifications.php');
    header_check_notifications_invalidate_cache();
    echo json_encode(
        ['ok' => true, 'order_id' => $id, 'order' => sal_customer_order_fetch($pdo, $id)],
        JSON_UNESCAPED_UNICODE
    );
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
