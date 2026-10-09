<?php
declare(strict_types=1);

/**
 * إدارة جولات المندوبين — اطلاع مدير المبيعات على جولة أي مندوب لأي تاريخ.
 * GET بدون sales_rep_id: قائمة المندوبين
 * GET مع sales_rep_id (+ date اختياري): زيارات الجولة لذلك اليوم
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/mobile_auth.php');
require_once app_path('includes/crm_sales_rep_schema.php');
require_once app_path('includes/sal_rep_visit.php');
require_once app_path('includes/sal_rep_tour.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!is_logged_in() || !mobile_is_context() || !user_in_mobile_group()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized', 'message' => 'الجلسة منتهية.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!user_can('m_rep_tours_manage') && !user_is_system_admin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden', 'message' => 'لا توجد صلاحية.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
sal_rep_tour_ensure_monthly_rollover($pdo);
crm_sales_rep_ensure_schema($pdo);

$reps = [];
try {
    $hasCode = false;
    try {
        $pdo->query('SELECT code FROM crm_sales_rep LIMIT 1');
        $hasCode = true;
    } catch (Throwable $e) {
    }
    $sql = $hasCode
        ? 'SELECT id, code, name_ar FROM crm_sales_rep WHERE is_active = 1 ORDER BY name_ar'
        : 'SELECT id, name_ar FROM crm_sales_rep WHERE is_active = 1 ORDER BY name_ar';
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $reps[] = [
            'id' => (int) ($r['id'] ?? 0),
            'code' => (string) ($r['code'] ?? ''),
            'name_ar' => (string) ($r['name_ar'] ?? ''),
        ];
    }
} catch (Throwable $e) {
    $reps = [];
}

$salesRepId = (int) ($_GET['sales_rep_id'] ?? 0);
if ($salesRepId < 1) {
    echo json_encode([
        'ok' => true,
        'reps' => $reps,
        'count' => count($reps),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$repName = '';
$repCode = '';
foreach ($reps as $r) {
    if ((int) $r['id'] === $salesRepId) {
        $repName = (string) $r['name_ar'];
        $repCode = (string) $r['code'];
        break;
    }
}
if ($repName === '') {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'المندوب غير موجود أو غير نشط.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$date = trim((string) ($_GET['date'] ?? ''));
if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

$allVisits = sal_rep_visit_list_for_rep($pdo, $salesRepId, $date);
$visits = [];
foreach ($allVisits as $v) {
    $inPlan = !empty($v['in_plan']);
    $st = (string) ($v['status'] ?? 'idle');
    $hasActivity = in_array($st, ['checked_in', 'checked_out', 'pending_manual_checkout'], true);
    if ($inPlan || $hasActivity) {
        $visits[] = $v;
    }
}

$wd = (int) date('w', strtotime($date));
$weekdayLabels = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
$plannedCount = 0;
$doneCount = 0;
$openCount = 0;
foreach ($visits as $v) {
    if (!empty($v['in_plan'])) {
        $plannedCount++;
    }
    $st = (string) ($v['status'] ?? '');
    if ($st === 'checked_out') {
        $doneCount++;
    } elseif ($st === 'checked_in' || $st === 'pending_manual_checkout') {
        $openCount++;
    }
}

echo json_encode([
    'ok' => true,
    'reps' => $reps,
    'sales_rep_id' => $salesRepId,
    'sales_rep_name' => $repName,
    'sales_rep_code' => $repCode,
    'route_date' => $date,
    'weekday' => $wd,
    'weekday_label' => $weekdayLabels[$wd] ?? '',
    'visit_radius_m' => (int) sal_rep_visit_radius_m($pdo),
    'geofence_required' => sal_rep_visit_geofence_setting_enabled($pdo),
    'visits' => $visits,
    'count' => count($visits),
    'planned_count' => $plannedCount,
    'done_count' => $doneCount,
    'open_count' => $openCount,
], JSON_UNESCAPED_UNICODE);
