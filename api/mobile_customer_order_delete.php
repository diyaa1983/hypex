<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/sal_customer_order.php');
require_once app_path('includes/crm_sales_rep_schema.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !mobile_can_access_customer_order_api() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$body = json_decode((string) file_get_contents('php://input'), true);
$body = is_array($body) ? $body : $_POST;
if (!verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($body['_csrf'] ?? null))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'انتهت صلاحية الجلسة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = db();
    sal_customer_order_ensure_schema($pdo);
    $uid = (int) (current_user()['id'] ?? 0);
    $rep = user_is_system_admin() ? null : crm_mobile_scoped_sales_rep_id($pdo);
    // حذف إداري لأي طلب يتطلب صلاحية الإجراء؛ المندوب يحذف مسوداته فقط
    $scoped = null;
    if (sal_customer_order_user_can_delete_managed()) {
        $scoped = null;
    } else {
        if ($rep === null) {
            throw new RuntimeException('حسابك غير مربوط بمندوب مبيعات.');
        }
        $scoped = $rep;
    }
    unset($uid);
    $result = sal_customer_order_delete($pdo, (int) ($body['id'] ?? 0), $scoped);
    require_once app_path('includes/header_check_notifications.php');
    header_check_notifications_invalidate_cache();
    echo json_encode([
        'ok' => true,
        'message' => !empty($result['visit_reset'])
            ? 'تم حذف الطلب وإلغاء تسجيل الزيارة.'
            : 'تم حذف الطلب.',
        'visit_reset' => !empty($result['visit_reset']),
        'visit_route_line_id' => $result['visit_route_line_id'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
