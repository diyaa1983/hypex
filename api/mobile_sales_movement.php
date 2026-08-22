<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

try {
    require dirname(__DIR__) . '/includes/bootstrap.php';
    require_once app_path('includes/mobile_auth.php');

    if (!is_logged_in()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'unauthorized', 'message' => 'الجلسة منتهية.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (
        !user_can('m_sales_movement')
        && !user_can('m_sales_invoices')
        && !user_can('m_customer_orders')
        && !user_is_system_admin()
    ) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden', 'message' => 'لا توجد صلاحية.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $inc = app_path('includes/sal_mobile_sales_movement.php');
    if (!is_file($inc)) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => 'missing_include',
            'message' => 'ملف التقرير غير موجود على السيرفر. ارفع includes/sal_mobile_sales_movement.php',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    require_once $inc;

    try {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file(db(), 'database/migrations/281_mobile_sales_movement_screen.sql');
    } catch (Throwable $e) {
        // ignore
    }

    $pdo = db();
    $today = date('Y-m-d');
    $from = parse_date_to_iso(trim((string) ($_GET['date_from'] ?? $_GET['from'] ?? ''))) ?? $today;
    $to = parse_date_to_iso(trim((string) ($_GET['date_to'] ?? $_GET['to'] ?? ''))) ?? $today;
    $customerId = (int) ($_GET['customer_id'] ?? 0);
    $itemId = (int) ($_GET['item_id'] ?? 0);

    require_once app_path('includes/crm_sales_rep_schema.php');
    $salesRepId = user_is_system_admin() ? null : crm_mobile_scoped_sales_rep_id($pdo);

    $result = sal_mobile_sales_movement_report($pdo, [
        'from' => $from,
        'to' => $to,
        'customer_id' => $customerId,
        'item_id' => $itemId,
        'sales_rep_id' => $salesRepId,
    ]);

    echo json_encode([
        'ok' => true,
        'from' => $from,
        'to' => $to,
        'customer_id' => $customerId,
        'item_id' => $itemId,
        'rows' => $result['rows'],
        'totals' => $result['totals'],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('mobile_sales_movement: ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'ok' => false,
        'error' => 'server_error',
        'message' => 'تعذر تحميل التقرير.',
    ], JSON_UNESCAPED_UNICODE);
}
