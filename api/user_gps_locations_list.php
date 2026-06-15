<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/sys_user_location.php');

header('Content-Type: application/json; charset=utf-8');

if (!sys_user_location_may_view(db())) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'forbidden',
        'message' => 'لا توجد صلاحية لعرض مواقع المستخدمين.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_once app_path('includes/sal_gps_list_ui.php');
    if (!sal_gps_list_is_submitted()) {
        echo json_encode(['ok' => true, 'rows' => [], 'pending' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }
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
    $rows = sys_user_location_list_rows(db(), $q, 500, $dates['from'], $dates['to']);

    echo json_encode([
        'ok' => true,
        'rows' => $rows,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('user_gps_locations_list: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'server_error',
        'message' => 'حدث خطأ أثناء تحميل المواقع.',
    ], JSON_UNESCAPED_UNICODE);
}
