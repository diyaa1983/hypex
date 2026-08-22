<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/sal_rep_visit.php');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!user_can('report_sales_rep_visits') && !user_is_system_admin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
sal_rep_visit_ensure_schema($pdo);

$from = parse_date_to_iso(trim((string) ($_GET['from'] ?? ''))) ?? date('Y-m-01');
$to = parse_date_to_iso(trim((string) ($_GET['to'] ?? ''))) ?? date('Y-m-d');
$salesRepId = (int) ($_GET['sales_rep_id'] ?? 0);
$method = strtoupper(trim((string) ($_GET['method'] ?? '')));
$status = trim((string) ($_GET['status'] ?? ''));

$rows = sal_rep_visit_report_rows($pdo, [
    'from' => $from,
    'to' => $to,
    'sales_rep_id' => $salesRepId,
    'method' => $method,
    'status' => $status,
    'limit' => 1500,
]);

$outRows = [];
foreach ($rows as $r) {
    $outRows[] = [
        'line_id' => (int) ($r['line_id'] ?? 0),
        'route_date' => (string) ($r['route_date'] ?? ''),
        'route_date_label' => sal_rep_visit_date_with_weekday((string) ($r['route_date'] ?? '')),
        'sales_rep_id' => (int) ($r['sales_rep_id'] ?? 0),
        'sales_rep_name' => (string) ($r['sales_rep_name'] ?? ''),
        'customer_name' => (string) ($r['customer_name'] ?? ''),
        'in_plan' => !empty($r['in_plan']),
        'plan_scope_label' => (string) ($r['plan_scope_label'] ?? ''),
        'no_order_reasons' => (string) ($r['no_order_reasons'] ?? ''),
        'location' => strip_tags(sal_rep_visit_location_inline($r)),
        'checkin_time' => sal_rep_visit_fmt_time_only($r['visit_checkin_at'] ?? '') ?: '—',
        'checkout_time' => sal_rep_visit_fmt_time_only($r['visit_checkout_at'] ?? '') ?: '—',
        'duration_label' => (string) ($r['duration_label'] ?? sal_rep_visit_duration_label(
            ($r['visit_checkin_at'] ?? '') !== '' ? (string) $r['visit_checkin_at'] : null,
            ($r['visit_checkout_at'] ?? '') !== '' ? (string) $r['visit_checkout_at'] : null
        )),
        'checkin_method_label' => sal_rep_visit_checkin_method_only_label($r),
        'order_total' => (float) ($r['order_total'] ?? 0),
        'order_count' => (int) ($r['order_count'] ?? 0),
        'status_label' => (string) ($r['status_label'] ?? ''),
        'row_class' => sal_rep_visit_report_row_class($r),
    ];
}

echo json_encode([
    'ok' => true,
    'from' => $from,
    'to' => $to,
    'rows' => $outRows,
    'totals' => sal_rep_visit_report_totals($rows),
], JSON_UNESCAPED_UNICODE);
