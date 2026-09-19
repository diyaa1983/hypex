<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/oracle_statement.php');
require_once app_path('includes/oracle_customer_sync.php');
require_once app_path('includes/crm_sales_rep_schema.php');
require_once app_path('includes/mobile_party_statement_print.php');
require_once app_path('includes/mobile_dompdf.php');

if (!is_logged_in()) {
    http_response_code(403);
    echo 'forbidden';
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
    echo 'forbidden';
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
    http_response_code(400);
    echo 'customer_required';
    exit;
}

$pdo = db();
$st = $pdo->prepare('SELECT id, code, name_ar, oracle_key FROM crm_customer WHERE id = ? LIMIT 1');
$st->execute([$customerId]);
$party = $st->fetch(PDO::FETCH_ASSOC) ?: null;
if (!$party) {
    http_response_code(404);
    echo 'not_found';
    exit;
}

try {
    $uid = (int) (current_user()['id'] ?? 0);
    $repId = function_exists('crm_sales_rep_id_for_user')
        ? crm_sales_rep_id_for_user($pdo, $uid)
        : null;
    if (
        $repId !== null
        && function_exists('crm_customer_is_linked_to_sales_rep')
        && !crm_customer_is_linked_to_sales_rep($pdo, $customerId, (int) $repId)
        && !user_is_system_admin()
    ) {
        http_response_code(403);
        echo 'forbidden';
        exit;
    }
} catch (Throwable $e) {
    //
}

$accountNo = trim((string) ($party['oracle_key'] ?? ''));
if ($accountNo === '') {
    $accountNo = preg_replace('/\D+/', '', (string) ($party['code'] ?? '')) ?? '';
}
if ($accountNo === '' || !preg_match('/^\d+$/', $accountNo)) {
    http_response_code(400);
    echo 'no_oracle_account';
    exit;
}

$stmt = oracle_fetch_customer_statement($accountNo, $from, $to);
if (!$stmt['ok']) {
    http_response_code(500);
    echo 'oracle_error';
    exit;
}

require_once app_path('includes/sal_customer_order_statement.php');
$stmt = sal_customer_order_statement_merge_oracle($pdo, $customerId, $stmt, $from, $to);

$name = (string) ($stmt['name'] ?? '');
if ($name === '') {
    $name = (string) ($party['name_ar'] ?? '');
}

$rows = [];
foreach ((array) ($stmt['lines'] ?? []) as $ln) {
    if (!is_array($ln)) {
        continue;
    }
    $lnDate = (string) ($ln['trn_date'] ?? $ln['date'] ?? $ln['doc_date'] ?? '');
    $rows[] = [
        'date' => $lnDate,
        'description' => oracle_statement_clean_description(
            (string) ($ln['description'] ?? $ln['remark'] ?? $ln['desc'] ?? '')
        ),
        'doc_no' => (string) ($ln['doc_no'] ?? $ln['num'] ?? $ln['number'] ?? ''),
        'debit' => (float) ($ln['debit'] ?? 0),
        'credit' => (float) ($ln['credit'] ?? 0),
        'balance' => (float) ($ln['balance'] ?? 0),
    ];
}

$opening = (float) ($stmt['opening'] ?? 0);
$built = [
    'opening_balance' => $opening,
    'opening_debit' => $opening > 0 ? $opening : 0.0,
    'opening_credit' => $opening < 0 ? abs($opening) : 0.0,
    'total_debit' => (float) ($stmt['total_debit'] ?? 0),
    'total_credit' => (float) ($stmt['total_credit'] ?? 0),
    'closing_balance' => (float) ($stmt['balance'] ?? 0),
];

$salesRepNames = '';
try {
    $salesRepNames = crm_customer_sales_rep_names($pdo, $customerId);
} catch (Throwable $e) {
    $salesRepNames = '';
}

$partyName = $name;
$partyCode = (string) ($party['code'] ?? '');
$doc = mobile_party_statement_print_document(
    $pdo,
    'customer',
    $customerId,
    $partyName,
    $partyCode,
    $from,
    $to,
    $rows,
    $built,
    $salesRepNames
);
$html = (string) ($doc['html_pdf'] ?? $doc['html'] ?? '');
if ($html === '') {
    http_response_code(500);
    echo 'no_html';
    exit;
}

$fname = 'كشف حساب عميل - ' . ($partyName !== '' ? $partyName : 'report') . '.pdf';
mobile_dompdf_stream_pdf($html, $fname);
