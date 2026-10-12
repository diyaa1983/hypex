<?php
declare(strict_types=1);

/**
 * اعتماد / فك اعتماد طلب شراء من تابلت مدير المبيعات.
 * POST JSON: { id: int, approve?: bool }  — approve افتراضي true
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/mobile_auth.php');
require_once app_path('includes/sal_customer_order.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!is_logged_in() || !mobile_is_context() || !user_in_mobile_group()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized', 'message' => 'الجلسة منتهية.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!mobile_can_manage_customer_orders()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden', 'message' => 'لا توجد صلاحية اعتماد الطلبات.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'طريقة غير مسموحة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$body = json_decode((string) file_get_contents('php://input'), true);
$body = is_array($body) ? $body : $_POST;
if (!verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($body['_csrf'] ?? null))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'انتهت صلاحية الجلسة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$id = (int) ($body['id'] ?? 0);
$approve = true;
if (array_key_exists('approve', $body)) {
    $raw = $body['approve'];
    $approve = !($raw === false || $raw === 0 || $raw === '0' || $raw === 'false');
}

try {
    $pdo = db();
    sal_customer_order_ensure_schema($pdo);
    $order = sal_customer_order_fetch($pdo, $id);
    if (!$order) {
        throw new RuntimeException('الطلب غير موجود.');
    }
    if ($approve) {
        $sent = (int) ($order['is_sent'] ?? 0) === 1;
        if (!$sent) {
            throw new RuntimeException('لا يمكن اعتماد طلب لم يُرحَّل بعد من المندوب.');
        }
        if ((string) ($order['status'] ?? '') === 'approved') {
            throw new RuntimeException('الطلب معتمد بالفعل.');
        }
    }
    sal_customer_order_set_approved(
        $pdo,
        $id,
        $approve,
        (int) (current_user()['id'] ?? 0)
    );
    require_once app_path('includes/header_check_notifications.php');
    header_check_notifications_invalidate_cache();
    $fresh = sal_customer_order_fetch($pdo, $id);
    echo json_encode([
        'ok' => true,
        'message' => $approve ? 'تم اعتماد الطلب.' : 'تم فك اعتماد الطلب.',
        'order' => $fresh,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
