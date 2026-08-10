<?php
declare(strict_types=1);

/**
 * CLI: ملخص كشف حساب Oracle للعميل (مدين / دائن / شيكات قيد التحصيل).
 * Usage: php oracle_customer_ar_summary.php <customer_id>
 * stdout: JSON line
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "cli only\n");
    exit(1);
}

$customerId = (int) ($argv[1] ?? 0);
$from = trim((string) ($argv[2] ?? ''));
$to = trim((string) ($argv[3] ?? ''));

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once app_path('includes/oracle_statement.php');

if ($from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = '';
}
if ($to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = '';
}

$result = oracle_customer_ar_summary(
    db(),
    $customerId,
    $from !== '' ? $from : null,
    $to !== '' ? $to : null
);

$statementUrl = '';
if (!empty($result['ok']) && $customerId > 0) {
    $statementUrl =
        'index.php?r=report_oracle_customer_statement&customer_id=' . $customerId
        . '&from=' . rawurlencode((string) ($result['from'] ?? ''))
        . '&to=' . rawurlencode((string) ($result['to'] ?? ''));
}

echo json_encode([
    'ok' => (bool) ($result['ok'] ?? false),
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
    'statement_path' => $statementUrl,
], JSON_UNESCAPED_UNICODE) . "\n";
