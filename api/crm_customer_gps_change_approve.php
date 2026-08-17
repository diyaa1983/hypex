<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/crm_customer_gps_change.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!is_logged_in() || !crm_customer_gps_change_user_can_approve()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'ممنوع.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
crm_customer_gps_change_ensure($pdo);
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    $status = (string) ($_GET['status'] ?? 'pending');
    $rows = crm_customer_gps_change_list($pdo, $status);
    echo json_encode(['ok' => true, 'rows' => $rows, 'count' => count($rows)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method'], JSON_UNESCAPED_UNICODE);
    exit;
}

$body = json_decode((string) file_get_contents('php://input'), true);
$body = is_array($body) ? $body : $_POST;
if (!verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($body['_csrf'] ?? null))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'انتهت صلاحية الجلسة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$id = (int) ($body['id'] ?? $_POST['id'] ?? 0);
$action = strtolower(trim((string) ($body['action'] ?? $_POST['action'] ?? '')));
$note = isset($body['note']) ? (string) $body['note'] : (isset($_POST['note']) ? (string) $_POST['note'] : null);
$uid = (int) (current_user()['id'] ?? 0);

if ($id < 1 || !in_array($action, ['approve', 'reject'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'طلب غير صالح.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = crm_customer_gps_change_decide($pdo, $id, $action === 'approve', $uid, $note);
http_response_code(!empty($result['ok']) ? 200 : 422);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
