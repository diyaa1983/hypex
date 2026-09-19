<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/sal_invoice_gps.php');
require_once app_path('includes/mobile_invoice.php');

header('Content-Type: application/json; charset=utf-8');

$mayView = is_logged_in() && (
    user_can('sales_invoice_gps')
    || user_can('sales_documents_list')
    || mobile_can_access_sales_invoice_api()
);

if (!$mayView) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'forbidden',
        'message' => 'لا توجد صلاحية لعرض إحداثيات الفواتير.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_once app_path('includes/sal_gps_list_ui.php');
    if (!sal_gps_list_is_submitted()) {
        echo json_encode(['ok' => true, 'rows' => [], 'pending' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $pdo = db();
    $q = trim((string) ($_GET['q'] ?? ''));
    $dates = sal_gps_list_parse_dates(
        isset($_GET['date_from']) ? (string) $_GET['date_from'] : null,
        isset($_GET['date_to']) ? (string) $_GET['date_to'] : null
    );
    if (($dates['error'] ?? '') !== '') {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => 'invalid_dates',
            'message' => (string) $dates['error'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $rows = sal_invoice_gps_list_rows($pdo, $q, 500, $dates['from'], $dates['to']);

    echo json_encode([
        'ok' => true,
        'rows' => $rows,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('sales_invoice_gps_list: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'server_error',
        'message' => 'حدث خطأ أثناء تحميل الإحداثيات.',
    ], JSON_UNESCAPED_UNICODE);
}
