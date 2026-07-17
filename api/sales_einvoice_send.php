<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/einvoice_send.php');
require_once app_path('includes/sal_invoice_schema.php');

header('Content-Type: application/json; charset=utf-8');

require_once app_path('includes/mobile_invoice.php');

if (!is_logged_in() || !mobile_can_access_sales_invoice_api() || !mobile_can_send_sales_einvoice()) {
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

$invoiceId = (int) ($_POST['invoice_id'] ?? 0);
if ($invoiceId < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'لم تُحدَّد فاتورة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
sal_invoice_ensure_schema($pdo);
einvoice_ensure_schema($pdo);

try {
    $pdo->beginTransaction();
    $result = einvoice_send_sale_invoice($pdo, $invoiceId);
    if ($result['error'] !== null) {
        $pdo->commit(); // نحفظ einv_results للتشخيص رغم الفشل
        echo json_encode([
            'ok' => false,
            'error' => $result['error'],
            'http_code' => $result['http_code'] ?? null,
            'response' => $result['response'] ?? null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $pdo->commit();

    require_once app_path('includes/header_check_notifications.php');
    header_check_notifications_invalidate_cache();

    require_once app_path('includes/sal_invoice_load.php');
    $row = sal_invoice_fetch_by_id($pdo, $invoiceId) ?: [];

    echo json_encode([
        'ok' => true,
        'skipped' => (bool) $result['skipped'],
        'message' => $result['message'] ?? 'تمت العملية.',
        'invoice' => [
            'id' => $invoiceId,
            'einv_status' => $row['einv_status'] ?? null,
            'einv_qr' => $row['einv_qr'] ?? null,
            'einv_num' => $row['einv_num'] ?? null,
            'einv_results' => $row['einv_results'] ?? null,
            'einv_sent' => !empty($row['einv_qr']),
            'reference_status' => $row['reference_status'] ?? null,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'تعذر الإرسال للفوترة.'], JSON_UNESCAPED_UNICODE);
}
