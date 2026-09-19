<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/sal_customer_order.php');
require_once app_path('includes/crm_sales_rep_schema.php');
require_once app_path('includes/list_pagination.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !mobile_can_access_customer_order_api()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$pdo = db();
sal_customer_order_ensure_schema($pdo);
$rep = user_is_system_admin() ? null : crm_mobile_scoped_sales_rep_id($pdo);
$status = trim((string) ($_GET['status'] ?? ''));
$status = $status !== '' ? $status : null;
$q = trim((string) ($_GET['q'] ?? ''));
$customerId = (int) ($_GET['customer_id'] ?? 0);
$customerId = $customerId > 0 ? $customerId : null;
$dateFrom = trim((string) ($_GET['from'] ?? ''));
$dateTo = trim((string) ($_GET['to'] ?? ''));
$dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) ? $dateFrom : null;
$dateTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) ? $dateTo : null;

$isSent = null;
if (array_key_exists('is_sent', $_GET)) {
    $raw = $_GET['is_sent'];
    if ($raw === '0' || $raw === 0 || $raw === false || $raw === 'false') {
        $isSent = 0;
    } elseif ($raw === '1' || $raw === 1 || $raw === true || $raw === 'true') {
        $isSent = 1;
    }
}

$rows = [];
$total = 0;
if (user_is_system_admin() || $rep !== null) {
    $total = sal_customer_order_list_count($pdo, $q, $rep, $status, $customerId, $isSent, $dateFrom, $dateTo);
}
$pager = mobile_list_pager_from_request($pdo, $total);
if ($total > 0) {
    $rows = sal_customer_order_list_fetch(
        $pdo,
        $q,
        $rep,
        $status,
        $customerId,
        (int) $pager['limit'],
        (int) $pager['offset'],
        $isSent,
        $dateFrom,
        $dateTo
    );
}

echo json_encode([
    'ok' => true,
    'orders' => $rows,
    'pager' => mobile_list_pager_meta($pager),
    'rows_per_page' => (int) $pager['per_page'],
], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
