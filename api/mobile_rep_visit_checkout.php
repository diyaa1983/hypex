<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/mobile_auth.php');
require_once app_path('includes/crm_sales_rep_schema.php');
require_once app_path('includes/sal_rep_visit.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!is_logged_in() || !mobile_is_context() || !user_in_mobile_group() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!user_can('m_rep_visits') && !user_can('m_rep_route_today') && !user_is_system_admin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'لا توجد صلاحية.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$body = json_decode((string) file_get_contents('php://input'), true);
$body = is_array($body) ? $body : $_POST;
if (!verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($body['_csrf'] ?? null))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'انتهت صلاحية الجلسة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
$uid = (int) (current_user()['id'] ?? 0);
$repId = crm_sales_rep_id_for_user($pdo, $uid);
if ($repId === null || $repId < 1) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'حسابك غير مربوط بمندوب مبيعات.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$customerId = (int) ($body['customer_id'] ?? 0);
$method = (string) ($body['method'] ?? 'GPS');
$reason = isset($body['reason']) ? (string) $body['reason'] : null;
$gps = sal_rep_visit_parse_gps($body);
$result = sal_rep_visit_checkout($pdo, $repId, $customerId, $method, $gps, $reason, $uid);
http_response_code(!empty($result['ok']) ? 200 : 422);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
