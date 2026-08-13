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
if (
    !user_can('m_rep_visit_report')
    && !user_can('m_rep_visits')
    && !user_can('m_rep_route_today')
    && !user_can('m_customer_orders')
    && !user_can('m_sales_invoices')
    && !user_is_system_admin()
) {
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
    'sales_rep_id' => $repId,
    'method' => strtoupper(trim((string) ($_GET['method'] ?? ''))),
    'status' => trim((string) ($_GET['status'] ?? '')),
    'limit' => 400,
]);

echo json_encode([
    'ok' => true,
    'from' => $from,
    'to' => $to,
    'count' => count($rows),
    'visits' => $rows,
], JSON_UNESCAPED_UNICODE);
