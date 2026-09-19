<?php
declare(strict_types=1);

/**
 * خط سير زيارات المندوب لتاريخ اليوم (أو تاريخ محدّد) — لتطبيق الهاتف.
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/mobile_auth.php');
require_once app_path('includes/crm_sales_rep_schema.php');
require_once app_path('includes/sal_rep_route.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!is_logged_in() || !mobile_is_context() || !user_in_mobile_group()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized', 'message' => 'الجلسة منتهية.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!user_can('m_rep_route_today') && !user_can('m_customer_orders') && !user_can('m_sales_invoices') && !user_is_system_admin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden', 'message' => 'لا توجد صلاحية.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
$uid = (int) (current_user()['id'] ?? 0);
$repId = crm_sales_rep_id_for_user($pdo, $uid);
if ($repId === null || $repId < 1) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'error' => 'no_rep',
        'message' => 'حسابك غير مربوط بمندوب مبيعات.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$date = trim((string) ($_GET['date'] ?? ''));
if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = '';
}

$data = sal_rep_route_customers_for_date($pdo, $repId, $date !== '' ? $date : null);
$geofenceOn = sal_rep_visit_geofence_setting_enabled($pdo);

echo json_encode([
    'ok' => true,
    'route_date' => $data['route_date'],
    'route' => $data['route'],
    'customers' => $data['customers'],
    'customer_count' => count($data['customers']),
    'geofence_required' => $geofenceOn,
    'visit_radius_m' => (int) sal_rep_visit_radius_m(),
], JSON_UNESCAPED_UNICODE);
