<?php
declare(strict_types=1);

/**
 * فك إرسال مرتجع مبيعات للفوترة (مسح علامة الإرسال المحلية).
 * يُستخدم لاستعادة إمكانية فك الترحيل بعد وسم يدوي أو إرسال خاطئ.
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/einvoice_schema.php');
require_once app_path('includes/sal_return_unpost.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !user_can_sales_returns() || !user_can_action('sales_send_einvoice')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method'], JSON_UNESCAPED_UNICODE);
    exit;
}

$csrf = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!verify_csrf($csrf)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'csrf'], JSON_UNESCAPED_UNICODE);
    exit;
}

$returnId = (int) ($_POST['return_id'] ?? 0);
if ($returnId < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'لم يُحدَّد مرتجع.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
einvoice_ensure_schema($pdo);

$st = $pdo->prepare('SELECT id, return_no FROM sal_return WHERE id = ? LIMIT 1');
$st->execute([$returnId]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'المرتجع غير موجود.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$wasSent = sal_return_einvoice_is_sent($pdo, $returnId);
if (!$wasSent) {
    echo json_encode([
        'ok' => true,
        'was_sent' => false,
        'message' => 'المرتجع غير مُعلَّم كمرسل للفوترة.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

sal_return_clear_einvoice_data($pdo, $returnId);

require_once app_path('includes/sys_audit_log.php');
sys_audit_log_sal_return($pdo, 'einvoice_reset', $returnId);

require_once app_path('includes/header_check_notifications.php');
header_check_notifications_invalidate_cache();

echo json_encode([
    'ok' => true,
    'was_sent' => true,
    'message' => 'تم فك إرسال المرتجع ' . (string) $row['return_no'] . ' للفوترة. يمكنك الآن فك الترحيل أو إعادة الإرسال.',
], JSON_UNESCAPED_UNICODE);
