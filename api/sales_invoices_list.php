<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/mobile_invoice.php');
require_once app_path('includes/list_pagination.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !mobile_can_access_sales_invoice_api()) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'forbidden',
        'message' => 'لا توجد صلاحية لعرض قائمة الفواتير.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = db();
    $filter = trim((string) ($_GET['filter'] ?? 'all'));
    $q = trim((string) ($_GET['q'] ?? ''));
    $total = mobile_invoice_list_count($pdo, $filter, $q);
    $pager = mobile_list_pager_from_request($pdo, $total);
    $rows = mobile_invoice_list_rows($pdo, $filter, $q, (int) $pager['limit'], (int) $pager['offset']);

    echo json_encode([
        'ok' => true,
        'invoices' => $rows,
        'pager' => mobile_list_pager_meta($pager),
        'rows_per_page' => (int) $pager['per_page'],
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('sales_invoices_list: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'server_error',
        'message' => 'حدث خطأ أثناء تحميل قائمة الفواتير.',
    ], JSON_UNESCAPED_UNICODE);
}
