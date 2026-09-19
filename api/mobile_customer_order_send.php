<?php
declare(strict_types=1);

/**
 * إرسال طلبات شراء الموبايل إلى نظام ويندوز (is_sent=1).
 * POST JSON: { ids: [1,2,3] } أو { id: 1 }
 */
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

$ids = [];
if (isset($body['ids']) && is_array($body['ids'])) {
    foreach ($body['ids'] as $v) {
        $n = (int) $v;
        if ($n > 0) {
            $ids[$n] = $n;
        }
    }
} elseif ((int) ($body['id'] ?? 0) > 0) {
    $ids[(int) $body['id']] = (int) $body['id'];
}
$ids = array_values($ids);
if ($ids === []) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'اختر طلباً واحداً على الأقل.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = db();
    sal_customer_order_ensure_schema($pdo);
    if (!sal_customer_order_has_column($pdo, 'sal_customer_order', 'is_sent')) {
        throw new RuntimeException('عمود الإرسال غير متوفر — شغّل الترحيل 272.');
    }
    $uid = (int) (current_user()['id'] ?? 0);
    $rep = user_is_system_admin() ? null : crm_sales_rep_id_for_user($pdo, $uid);
    $sent = 0;
    $now = date('Y-m-d H:i:s');
    foreach ($ids as $id) {
        $st = $pdo->prepare('SELECT id, status, sales_rep_id, IFNULL(is_sent,1) AS is_sent FROM sal_customer_order WHERE id=? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            continue;
        }
        if ($rep !== null && (int) ($row['sales_rep_id'] ?? 0) !== $rep) {
            continue;
        }
        if ((int) $row['is_sent'] === 1) {
            continue;
        }
        $pdo->prepare(
            'UPDATE sal_customer_order SET is_sent=1, sent_at=?, sent_by=?, updated_by=? WHERE id=?'
        )->execute([$now, $uid, $uid, $id]);
        $sent++;
    }
    if ($sent > 0) {
        require_once app_path('includes/header_check_notifications.php');
        header_check_notifications_invalidate_cache();
    }
    echo json_encode([
        'ok' => true,
        'message' => $sent > 0 ? ('تم إرسال ' . $sent . ' طلب إلى النظام.') : 'لا طلبات جديدة للإرسال.',
        'sent_count' => $sent,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
