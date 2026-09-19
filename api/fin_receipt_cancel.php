<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/fin_voucher_cancel.php');
require_once app_path('includes/fin_voucher_schema.php');

header('Content-Type: application/json; charset=utf-8');

require_once app_path('includes/mobile_receipt.php');

if (!is_logged_in() || !user_can_action('action_cancel_cash_receipt')) {
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
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'انتهت صلاحية الجلسة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
fin_voucher_ensure_schema_full($pdo);

$voucherId = (int) ($_POST['voucher_id'] ?? $_POST['id'] ?? 0);
if ($voucherId < 1) {
    echo json_encode(['ok' => false, 'message' => 'معرّف السند غير صالح.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo->beginTransaction();
    $result = fin_voucher_cancel_receipt_by_id($pdo, $voucherId);
    if (!$result['ok']) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'message' => $result['error'] ?? 'تعذر الإلغاء.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $pdo->commit();
    echo json_encode([
        'ok' => true,
        'message' => $result['message'] ?? 'تم إلغاء سند القبض.',
        'is_cancelled' => true,
        'is_posted' => false,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['ok' => false, 'message' => $e->getMessage() ?: 'تعذر الإلغاء.'], JSON_UNESCAPED_UNICODE);
}
