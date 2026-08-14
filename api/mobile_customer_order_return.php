<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/mobile_auth.php');
require_once app_path('includes/crm_sales_rep_schema.php');
require_once app_path('includes/sal_customer_order_return.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !mobile_is_context() || !user_in_mobile_group()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (
    !user_can('m_customer_order_returns')
    && !user_can('m_customer_orders')
    && !user_is_system_admin()
) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'لا صلاحية.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
$uid = (int) (current_user()['id'] ?? 0);
$rep = user_is_system_admin() ? null : crm_sales_rep_id_for_user($pdo, $uid);
$action = trim((string) ($_GET['action'] ?? $_POST['action'] ?? ''));

try {
    sal_customer_order_return_ensure_schema($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'returnable') {
        $customerId = (int) ($_GET['customer_id'] ?? 0) ?: null;
        echo json_encode([
            'ok' => true,
            'orders' => sal_customer_order_returnable_orders($pdo, $rep, $customerId),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['id'])) {
        $id = (int) $_GET['id'];
        $doc = sal_customer_order_return_fetch($pdo, $id);
        if (!$doc) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'message' => 'غير موجود'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($rep !== null && (int) ($doc['sales_rep_id'] ?? 0) !== $rep) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'ممنوع'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode(['ok' => true, 'return' => $doc], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $from = trim((string) ($_GET['from'] ?? ''));
        $to = trim((string) ($_GET['to'] ?? ''));
        $customerId = (int) ($_GET['customer_id'] ?? 0) ?: null;
        $status = trim((string) ($_GET['status'] ?? '')) ?: null;
        echo json_encode([
            'ok' => true,
            'returns' => sal_customer_order_return_list(
                $pdo,
                $rep,
                $customerId,
                $from !== '' ? $from : null,
                $to !== '' ? $to : null,
                $status
            ),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

    $postAction = trim((string) ($body['action'] ?? $action));
    if ($postAction === 'post') {
        $id = (int) ($body['id'] ?? 0);
        sal_customer_order_return_post($pdo, $id, $uid);
        echo json_encode(['ok' => true, 'message' => 'تم ترحيل المرتجع.', 'id' => $id], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $orderId = (int) ($body['order_id'] ?? 0);
    if ($orderId < 1) {
        throw new RuntimeException('اختر الطلب المراد إرجاعه.');
    }
    $lines = is_array($body['lines'] ?? null) ? $body['lines'] : [];
    $id = sal_customer_order_return_save_from_order($pdo, $orderId, $lines, $uid, $rep);
    $doc = sal_customer_order_return_fetch($pdo, $id);
    echo json_encode([
        'ok' => true,
        'message' => 'تم حفظ المرتجع كمسودة.',
        'id' => $id,
        'return_no' => $doc['return_no'] ?? '',
        'return' => $doc,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
