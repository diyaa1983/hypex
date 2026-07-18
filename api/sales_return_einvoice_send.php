<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/einvoice_send_return.php');
require_once app_path('includes/sal_return_schema.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !user_can('sales_returns') || !user_can_action('sales_send_einvoice')) {
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
$reason = trim((string) ($_POST['reason'] ?? ''));
$originalInvoiceUuid = trim((string) ($_POST['original_invoice_uuid'] ?? ''));
$originalInvoiceNo = trim((string) ($_POST['original_invoice_no'] ?? ''));
if ($returnId < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'لم يُحدَّد مرتجع.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($reason === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'سبب الإرجاع مطلوب.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
sal_return_ensure_schema($pdo);
einvoice_ensure_schema($pdo);

try {
    $pdo->beginTransaction();
    $result = einvoice_send_sale_return($pdo, $returnId, $reason, $originalInvoiceUuid, $originalInvoiceNo);
    if ($result['error'] !== null) {
        $pdo->commit(); // نحفظ einv_results للتشخيص رغم الفشل
        echo json_encode([
            'ok' => false,
            'error' => $result['error'],
            'need_original_uuid' => !empty($result['need_original_uuid']),
            'need_original_invoice_no' => !empty($result['need_original_invoice_no']),
            'http_code' => $result['http_code'] ?? null,
            'response' => $result['response'] ?? null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $pdo->commit();

    require_once app_path('includes/header_check_notifications.php');
    header_check_notifications_invalidate_cache();

    $row = einvoice_return_status_row($pdo, $returnId) ?: [];

    echo json_encode([
        'ok' => true,
        'skipped' => (bool) $result['skipped'],
        'message' => $result['message'] ?? 'تمت العملية.',
        'return' => [
            'id' => $returnId,
            'einv_status' => $row['einv_status'] ?? null,
            'einv_qr' => $row['einv_qr'] ?? null,
            'einv_num' => $row['einv_num'] ?? null,
            'einv_results' => $row['einv_results'] ?? null,
            'einv_sent' => !empty($row['einv_qr']),
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'تعذر إرسال الإرجاع للفوترة.'], JSON_UNESCAPED_UNICODE);
}
