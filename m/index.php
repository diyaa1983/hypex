<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/mobile_auth.php');
require_once app_path('includes/sql_migration.php');

$pdo = db();
sql_migration_run_files_once($pdo, [
    'database/migrations/080_mobile_app.sql',
    'database/migrations/081_mobile_invoice_list.sql',
    'database/migrations/082_mobile_party_receipt.sql',
    'database/migrations/083_mobile_receipt_list.sql',
    'database/migrations/084_mobile_screen_labels.sql',
    'database/migrations/128_sys_user_location.sql',
    'database/migrations/129_user_gps_locations_screens.sql',
    'database/migrations/130_sys_user_location_landmark.sql',
    'database/migrations/131_sys_user_location_place.sql',
    'database/migrations/207_crm_sales_rep_mobile_custody.sql',
    'database/migrations/208_mobile_rep_custody.sql',
    'database/migrations/209_sys_group_warehouse.sql',
    'database/migrations/210_hr_attendance_sync_token.sql',
    'database/migrations/211_report_hr_att_punch_movements.sql',
    'database/migrations/212_hr_attendance_sync_screens.sql',
    'database/migrations/213_warehouse_move_archive.sql',
    'database/migrations/214_mobile_rep_custody_list.sql',
    'database/migrations/235_sal_customer_order.sql',
    'database/migrations/236_report_customer_orders.sql',
]);

require_mobile_login();
$_SESSION['app_context'] = 'mobile';

$routes = require app_path('config/routes_mobile.php');
$r = isset($_GET['r']) ? (string) $_GET['r'] : 'm_home';

if (!isset($routes[$r])) {
    http_response_code(404);
    exit('الصفحة غير موجودة');
}

$route = $routes[$r];
if ($r === 'm_rep_custody_list') {
    require_once app_path('includes/mobile_rep_custody.php');
    if (!mobile_can_access_rep_custody_list()) {
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>ممنوع</title><body style="font-family:system-ui;padding:1.5rem;text-align:center;">';
        echo '<p>ليس لديك صلاحية لهذه الشاشة على الهاتف.</p>';
        echo '<p><a href="' . esc(mobile_url('r=m_home')) . '">العودة</a></p></body></html>';
        exit;
    }
} else {
    require_mobile_permission((string) $route['permission']);
}

$pageTitle = (string) ($route['title'] ?? '');
$activeRoute = $r;

ob_start();
require app_path($route['file']);
$content = ob_get_clean();

require app_path('templates/layout_mobile.php');
