<?php
declare(strict_types=1);

/**
 * ملخص كشف حساب Oracle للعميل — رصيد مستحق + شيكات قيد التحصيل.
 * GET: customer_id
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/oracle_statement.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'غير مصرح'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!(
    user_can('sales_customer_orders')
    || user_can('sales_customer_orders_approve')
    || user_can('sales_customer_order_entry')
    || user_can('sales_customer_order_entry_approve')
    || user_can('sales_customer_orders_approved')
    || user_can('report_oracle_customer_statement')
    || user_can('customers')
    || user_can('m_customer_orders')
    || user_can('m_party_statement')
    || user_can('m_sales_invoices')
    || user_can_sales_invoices()
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

$pdo = db();
$result = oracle_customer_ar_summary(
    $pdo,
    $customerId,
    $from !== '' ? $from : null,
    $to !== '' ? $to : null
);

$statementUrl = '';
if ($result['ok'] && $customerId > 0) {
    $statementUrl = app_url(
        'index.php?r=report_oracle_customer_statement&customer_id=' . $customerId
        . '&from=' . rawurlencode((string) $result['from'])
        . '&to=' . rawurlencode((string) $result['to'])
    );
}

echo json_encode([
    'ok' => (bool) $result['ok'],
    'message' => (string) ($result['message'] ?? ''),
    'customer_id' => (int) ($result['customer_id'] ?? $customerId),
    'account' => (string) ($result['account'] ?? ''),
    'name' => (string) ($result['name'] ?? ''),
    'from' => (string) ($result['from'] ?? ''),
    'to' => (string) ($result['to'] ?? ''),
    'balance' => (float) ($result['balance'] ?? 0),
    'total_debit' => (float) ($result['total_debit'] ?? 0),
    'total_credit' => (float) ($result['total_credit'] ?? 0),
    'opening' => (float) ($result['opening'] ?? 0),
    'cheques' => is_array($result['cheques'] ?? null) ? $result['cheques'] : [],
    'cheque_total' => (float) ($result['cheque_total'] ?? 0),
    'cheque_count' => (int) ($result['cheque_count'] ?? 0),
    'statement_url' => $statementUrl,
], JSON_UNESCAPED_UNICODE);
