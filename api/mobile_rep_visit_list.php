<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/mobile_auth.php');
require_once app_path('includes/crm_sales_rep_schema.php');
require_once app_path('includes/sal_rep_visit.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!is_logged_in() || !mobile_is_context() || !user_in_mobile_group()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!user_can('m_rep_visits') && !user_can('m_rep_route_today') && !user_can('m_customer_orders') && !user_can('m_sales_invoices') && !user_is_system_admin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden', 'message' => 'لا توجد صلاحية.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
$uid = (int) (current_user()['id'] ?? 0);
$repId = crm_sales_rep_id_for_user($pdo, $uid);
if ($repId === null || $repId < 1) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'حسابك غير مربوط بمندوب مبيعات.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$mode = strtolower(trim((string) ($_GET['mode'] ?? '')));
$month = trim((string) ($_GET['month'] ?? ''));
if ($mode === 'month' || $month !== '') {
    if ($month === '' || !preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = date('Y-m');
    }
    $agenda = sal_rep_visit_month_agenda_for_rep($pdo, $repId, $month);
    echo json_encode(array_merge($agenda, [
        'visit_radius_m' => (int) sal_rep_visit_radius_m($pdo),
        'geofence_required' => sal_rep_visit_geofence_setting_enabled($pdo),
    ]), JSON_UNESCAPED_UNICODE);
    exit;
}

$date = trim((string) ($_GET['date'] ?? ''));
if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = '';
}

$routeDate = $date !== '' ? $date : date('Y-m-d');
$visits = sal_rep_visit_list_for_rep($pdo, $repId, $routeDate);
$wd = (int) date('w', strtotime($routeDate));
$weekdayLabels = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
$plannedCount = 0;
foreach ($visits as $v) {
    if (!empty($v['in_plan'])) {
        $plannedCount++;
    }
}
echo json_encode([
    'ok' => true,
    'route_date' => $routeDate,
    'weekday' => $wd,
    'weekday_label' => $weekdayLabels[$wd] ?? '',
    'visit_radius_m' => (int) sal_rep_visit_radius_m($pdo),
    'geofence_required' => sal_rep_visit_geofence_setting_enabled($pdo),
    'no_order_reasons' => sal_rep_visit_no_order_reasons($pdo),
    'visits' => $visits,
    'count' => count($visits),
    'planned_count' => $plannedCount,
], JSON_UNESCAPED_UNICODE);
