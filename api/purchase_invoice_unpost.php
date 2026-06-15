<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/pur_invoice_schema.php');
require_once app_path('includes/pur_invoice_unpost.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !user_can_purchase_invoices() || !user_can_action('action_unpost_purchase_invoice')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'غير مصرح.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'طريقة غير مدعومة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$csrf = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!verify_csrf($csrf)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'انتهت صلاحية الجلسة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$invoiceId = (int) ($_POST['invoice_id'] ?? 0);
if ($invoiceId < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'لم تُحدَّد الفاتورة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
pur_invoice_ensure_schema($pdo);

try {
    $pdo->beginTransaction();

    $res = pur_invoice_unpost_by_id($pdo, $invoiceId);

    if (!$res['ok']) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode([
            'ok' => false,
            'error' => $res['error'] ?? 'تعذر فك الترحيل.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->commit();

    $msg = $res['message'] ?? 'تم فك الترحيل.';
    $msg .= ' يمكنك الآن تعديل الفاتورة وإعادة ترحيلها.';

    echo json_encode([
        'ok' => true,
        'message' => $msg,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'تعذر فك الترحيل: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
