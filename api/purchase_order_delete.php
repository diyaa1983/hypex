<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/pur_order_schema.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !user_can_purchase_orders() || !user_can_action('action_delete_purchase_order')) {
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
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'انتهت صلاحية الجلسة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$orderId = (int) ($_POST['order_id'] ?? $_POST['invoice_id'] ?? 0);
if ($orderId < 1) {
    echo json_encode(['ok' => false, 'message' => 'معرّف الطلب غير صالح.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
pur_order_ensure_schema($pdo);

$status = pur_order_fetch_status($pdo, $orderId);
if (!in_array($status, ['draft', 'submitted', 'cancelled'], true) && pur_order_linked_invoice_count($pdo, $orderId) > 0) {
    echo json_encode(['ok' => false, 'message' => 'لا يمكن حذف طلب مرتبط بفواتير.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$snap = null;
try {
    require_once app_path('includes/pur_order_load.php');
    $snap = pur_order_fetch_by_id($pdo, $orderId);
} catch (Throwable $e) {
    try {
        $stSnap = $pdo->prepare('SELECT order_no, order_date, total, status FROM pur_order WHERE id = ? LIMIT 1');
        $stSnap->execute([$orderId]);
        $snap = $stSnap->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e2) {
        $snap = null;
    }
}

$pdo->prepare('DELETE FROM pur_order WHERE id = ?')->execute([$orderId]);

try {
    require_once app_path('includes/sys_audit_log.php');
    sys_audit_log_pur_order($pdo, 'delete', $orderId, is_array($snap) ? $snap : null);
} catch (Throwable $e) {
}

echo json_encode(['ok' => true, 'message' => 'تم حذف طلب الشراء.'], JSON_UNESCAPED_UNICODE);
