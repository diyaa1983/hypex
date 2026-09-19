<?php
declare(strict_types=1);

/**
 * كشف حساب عميل من Oracle للمندوب (عرض + بيانات طباعة).
 * GET: customer_id, from?, to?
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/mobile_auth.php');
require_once app_path('includes/oracle_mobile_statement_cache.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'غير مصرح'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!(
    user_can('m_party_statement')
    || user_can('m_customer_orders')
    || user_can('report_oracle_customer_statement')
    || user_can('customers')
    || user_is_admin()
)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'غير مصرح'], JSON_UNESCAPED_UNICODE);
    exit;
}

$customerId = (int) ($_GET['customer_id'] ?? $_GET['id'] ?? 0);
$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
if ($from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = '';
}
if ($to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = '';
}
if ($from === '') {
    $from = date('Y') . '-01-01';
}
if ($to === '') {
    $to = date('Y-m-d');
}

if ($customerId < 1) {
    echo json_encode(['ok' => false, 'message' => 'اختر العميل أولاً.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();

// تقييد المندوب بعملائه المربوطين به
try {
    require_once app_path('includes/crm_sales_rep_schema.php');
    $uid = (int) (current_user()['id'] ?? 0);
    $repId = function_exists('crm_sales_rep_id_for_user')
        ? crm_sales_rep_id_for_user($pdo, $uid)
        : null;
    if ($repId !== null
        && function_exists('crm_customer_is_linked_to_sales_rep')
        && !crm_customer_is_linked_to_sales_rep($pdo, $customerId, (int) $repId)
        && !user_is_system_admin()
    ) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'هذا العميل غير مربوط بمندوبك.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
} catch (Throwable $e) {
    // لا نمنع الجلب بسبب فحص الربط إن فشل المخطط
}

$payload = oracle_mobile_customer_statement_payload($pdo, $customerId, $from, $to);
echo json_encode($payload, JSON_UNESCAPED_UNICODE);
