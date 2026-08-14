<?php
declare(strict_types=1);

/**
 * كشف حساب عميل من Oracle للمندوب (عرض + بيانات طباعة).
 * GET: customer_id, from?, to?
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/oracle_statement.php');
require_once app_path('includes/oracle_customer_sync.php');
require_once app_path('includes/mobile_auth.php');
require_once app_path('includes/document_header.php');

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
try {
    if (function_exists('oracle_customer_schema_ensure')) {
        oracle_customer_schema_ensure($pdo);
    }
} catch (Throwable $e) {
    //
}

$st = $pdo->prepare(
    'SELECT id, code, name_ar, oracle_key FROM crm_customer WHERE id = ? LIMIT 1'
);
$st->execute([$customerId]);
$party = $st->fetch(PDO::FETCH_ASSOC) ?: null;
if (!$party) {
    echo json_encode(['ok' => false, 'message' => 'العميل غير موجود.'], JSON_UNESCAPED_UNICODE);
    exit;
}

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

$accountNo = trim((string) ($party['oracle_key'] ?? ''));
if ($accountNo === '') {
    $accountNo = preg_replace('/\D+/', '', (string) ($party['code'] ?? '')) ?? '';
}
if ($accountNo === '' || !preg_match('/^\d+$/', $accountNo)) {
    echo json_encode([
        'ok' => false,
        'message' => 'لا يوجد رقم حساب Oracle لهذا العميل.',
        'party_name' => (string) ($party['name_ar'] ?? ''),
        'party_code' => (string) ($party['code'] ?? ''),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = oracle_fetch_customer_statement($accountNo, $from, $to);
if (!$stmt['ok']) {
    echo json_encode([
        'ok' => false,
        'message' => (string) ($stmt['message'] ?? 'تعذر جلب الكشف من Oracle.'),
        'account' => $accountNo,
        'party_name' => (string) ($party['name_ar'] ?? ''),
        'party_code' => (string) ($party['code'] ?? ''),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$name = (string) ($stmt['name'] ?? '');
if ($name === '') {
    $name = (string) ($party['name_ar'] ?? '');
}

$lines = [];
foreach ((array) ($stmt['lines'] ?? []) as $ln) {
    if (!is_array($ln)) {
        continue;
    }
    $lnDate = (string) ($ln['trn_date'] ?? $ln['date'] ?? $ln['doc_date'] ?? '');
    $lnDesc = (string) ($ln['description'] ?? $ln['remark'] ?? $ln['desc'] ?? '');
    $lines[] = [
        'date' => $lnDate,
        'trn_date' => $lnDate,
        'doc_no' => (string) ($ln['doc_no'] ?? $ln['num'] ?? $ln['number'] ?? ''),
        'doc_type' => (string) ($ln['doc_type'] ?? ''),
        'description' => $lnDesc,
        'remark' => $lnDesc,
        'debit' => (float) ($ln['debit'] ?? 0),
        'credit' => (float) ($ln['credit'] ?? 0),
        'balance' => (float) ($ln['balance'] ?? 0),
    ];
}

$brand = [];
try {
    $brand = document_header_brand_api($pdo);
} catch (Throwable $e) {
    $brand = [];
}

$cheques = is_array($stmt['cheques'] ?? null) ? $stmt['cheques'] : [];

echo json_encode([
    'ok' => true,
    'message' => '',
    'source' => 'oracle',
    'company_name' => (string) ($brand['company_name'] ?? 'الشركة'),
    'logo_url' => $brand['logo_url'] ?? null,
    'customer_id' => $customerId,
    'party_type' => 'customer',
    'party_id' => $customerId,
    'party_name' => $name,
    'party_code' => (string) ($party['code'] ?? ''),
    'account' => (string) ($stmt['account'] ?? $accountNo),
    'from' => (string) ($stmt['from'] ?? $from),
    'to' => (string) ($stmt['to'] ?? $to),
    'opening' => (float) ($stmt['opening'] ?? 0),
    'total_debit' => (float) ($stmt['total_debit'] ?? 0),
    'total_credit' => (float) ($stmt['total_credit'] ?? 0),
    'balance' => (float) ($stmt['balance'] ?? 0),
    'lines' => $lines,
    'rows' => $lines,
    'cheques' => $cheques,
    'cheque_total' => (float) ($stmt['cheque_total'] ?? 0),
    'cheque_count' => count($cheques),
], JSON_UNESCAPED_UNICODE);
