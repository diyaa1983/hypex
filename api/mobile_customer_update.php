<?php
declare(strict_types=1);

/**
 * تعديل بيانات عميل من تطبيق الهاتف — بدون الاسم والرمز.
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/mobile_auth.php');
require_once app_path('includes/crm_sales_rep_schema.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method', 'message' => 'الطريقة غير مسموحة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_logged_in() || !mobile_is_context() || !user_in_mobile_group()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized', 'message' => 'الجلسة منتهية.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!user_can('m_customer_list') && !user_can('m_customer_add') && !user_is_system_admin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden', 'message' => 'لا توجد صلاحية لتعديل عميل.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$body = $_POST;
$raw = file_get_contents('php://input');
if (is_string($raw) && $raw !== '' && str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $body = $decoded;
    }
}

if (!verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($body['_csrf'] ?? null))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'csrf', 'message' => 'انتهت صلاحية الجلسة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$uid = (int) (current_user()['id'] ?? 0);
$customerId = (int) ($body['id'] ?? $body['customer_id'] ?? 0);

try {
    $result = crm_mobile_customer_update_for_user(db(), $uid, $customerId, $body);
    if (!$result['ok']) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'validation', 'message' => $result['message']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode([
        'ok' => true,
        'pending' => !empty($result['pending']),
        'message' => $result['message'],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('mobile_customer_update: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error', 'message' => 'تعذر حفظ بيانات العميل.'], JSON_UNESCAPED_UNICODE);
}
