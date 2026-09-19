<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/mobile_receipt.php');
require_once app_path('includes/fin_voucher_schema.php');
require_once app_path('includes/list_pagination.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !mobile_can_access_receipt_api()) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'forbidden',
        'message' => 'لا توجد صلاحية لعرض قائمة سندات القبض.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = db();
    if (!fin_voucher_ensure_schema_full($pdo)) {
        throw new RuntimeException('fin_voucher table missing');
    }

    $filter = trim((string) ($_GET['filter'] ?? 'all'));
    $search = trim((string) ($_GET['q'] ?? ''));
    $basePager = list_pager_from_request($pdo);
    $page = mobile_receipt_list_page(
        $pdo,
        $filter,
        $search,
        (int) $basePager['limit'],
        (int) $basePager['offset']
    );
    $pager = !empty($page['pager'])
        ? $page['pager']
        : list_pager_with_total($basePager, (int) ($page['total'] ?? 0));

    echo json_encode([
        'ok' => true,
        'receipts' => $page['rows'],
        'count' => count($page['rows']),
        'pager' => mobile_list_pager_meta($pager),
        'rows_per_page' => (int) ($pager['per_page'] ?? $basePager['per_page']),
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('mobile_receipts_list: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'server_error',
        'message' => 'حدث خطأ أثناء تحميل قائمة السندات.',
    ], JSON_UNESCAPED_UNICODE);
}
