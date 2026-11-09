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
if (!user_can('m_rep_visit_report') && !user_is_system_admin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden', 'message' => 'لا توجد صلاحية.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
$uid = (int) (current_user()['id'] ?? 0);
$seeAll = mobile_manager_sees_all();
$requestedRep = (int) ($_GET['sales_rep_id'] ?? 0);
$repId = $seeAll ? 0 : (int) (crm_sales_rep_id_for_user($pdo, $uid) ?? 0);
if ($seeAll && $requestedRep > 0) {
    $repId = $requestedRep;
}
if (!$seeAll && $repId < 1) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'حسابك غير مربوط بمندوب مبيعات.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
if ($from === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = date('Y-m-01');
}
if ($to === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = date('Y-m-d');
}

$rows = sal_rep_visit_report_rows($pdo, [
    'from' => $from,
    'to' => $to,
    'sales_rep_id' => $repId > 0 ? $repId : 0,
    'customer_id' => (int) ($_GET['customer_id'] ?? 0),
    'method' => strtoupper(trim((string) ($_GET['method'] ?? ''))),
    'status' => trim((string) ($_GET['status'] ?? '')),
    'limit' => $seeAll ? 1500 : 400,
]);

if ($repId > 0) {
    $customersSt = $pdo->prepare(
        'SELECT DISTINCT c.id, c.code, c.name_ar AS name
         FROM sal_rep_route_line l
         INNER JOIN sal_rep_route r ON r.id = l.route_id
         INNER JOIN crm_customer c ON c.id = l.customer_id
         WHERE r.sales_rep_id = ?
           AND r.route_date BETWEEN ? AND ?
           AND l.visit_checkin_at IS NOT NULL
         ORDER BY c.name_ar, c.code'
    );
    $customersSt->execute([$repId, $from, $to]);
} else {
    $customersSt = $pdo->prepare(
        'SELECT DISTINCT c.id, c.code, c.name_ar AS name
         FROM sal_rep_route_line l
         INNER JOIN sal_rep_route r ON r.id = l.route_id
         INNER JOIN crm_customer c ON c.id = l.customer_id
         WHERE r.route_date BETWEEN ? AND ?
           AND l.visit_checkin_at IS NOT NULL
         ORDER BY c.name_ar, c.code'
    );
    $customersSt->execute([$from, $to]);
}
$customers = array_map(static fn(array $r): array => [
    'id' => (int) $r['id'],
    'code' => (string) ($r['code'] ?? ''),
    'name' => (string) ($r['name'] ?? ''),
], $customersSt->fetchAll(PDO::FETCH_ASSOC) ?: []);

$orderTotal = 0.0;
$orderCount = 0;
foreach ($rows as $row) {
    $orderTotal += (float) ($row['order_total'] ?? 0);
    $orderCount += (int) ($row['order_count'] ?? 0);
}

$reps = [];
if ($seeAll) {
    try {
        foreach ($pdo->query('SELECT id, code, name_ar FROM crm_sales_rep WHERE is_active = 1 ORDER BY name_ar, id')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $reps[] = [
                'id' => (int) ($r['id'] ?? 0),
                'code' => (string) ($r['code'] ?? ''),
                'name_ar' => (string) ($r['name_ar'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        $reps = [];
    }
}

echo json_encode([
    'ok' => true,
    'see_all' => $seeAll,
    'sales_rep_id' => $repId,
    'reps' => $reps,
    'from' => $from,
    'to' => $to,
    'count' => count($rows),
    'customer_id' => (int) ($_GET['customer_id'] ?? 0),
    'customers' => $customers,
    'order_count' => $orderCount,
    'order_total' => $orderTotal,
    'visits' => $rows,
], JSON_UNESCAPED_UNICODE);
