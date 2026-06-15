<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/mobile_return.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !user_can_mobile_sales_returns()) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'message' => 'لا توجد صلاحية لعرض قائمة المرتجعات.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = db();
    $filter = trim((string) ($_GET['filter'] ?? 'all'));
    $search = trim((string) ($_GET['q'] ?? ''));
    $rows = mobile_return_list_rows($pdo, $filter, $search, 120);

    echo json_encode([
        'ok' => true,
        'returns' => $rows,
        'count' => count($rows),
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('mobile_returns_list: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'حدث خطأ أثناء تحميل قائمة المرتجعات.',
    ], JSON_UNESCAPED_UNICODE);
}
