<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/fin_voucher_load.php');
require_once app_path('includes/fin_voucher_schema.php');
require_once app_path('includes/fin_voucher_checks.php');

header('Content-Type: application/json; charset=utf-8');

require_once app_path('includes/mobile_receipt.php');

if (!is_logged_in() || !mobile_can_access_receipt_api()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
fin_voucher_ensure_schema_full($pdo);

$id = (int) ($_GET['id'] ?? 0);
$no = trim((string) ($_GET['no'] ?? ''));
$dir = trim((string) ($_GET['dir'] ?? ''));
$edge = trim((string) ($_GET['edge'] ?? ''));

if ($edge === 'first') {
    require_once app_path('includes/fin_voucher_browse.php');
    $firstId = fin_voucher_latest_id($pdo, 'receipt');
    if ($firstId === null) {
        echo json_encode([
            'ok' => false,
            'error' => 'empty',
            'message' => 'لا توجد سندات قبض محفوظة بعد.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $id = $firstId;
} elseif ($edge === 'last') {
    require_once app_path('includes/fin_voucher_browse.php');
    $lastId = fin_voucher_oldest_id($pdo, 'receipt');
    if ($lastId === null) {
        echo json_encode([
            'ok' => false,
            'error' => 'empty',
            'message' => 'لا توجد سندات قبض محفوظة بعد.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $id = $lastId;
}

if ($id > 0 && $dir === 'prev') {
    require_once app_path('includes/fin_voucher_browse.php');
    $nid = fin_voucher_nav_neighbor_id($pdo, $id, 'receipt', 'prev');
    $id = $nid ?? 0;
} elseif ($id > 0 && $dir === 'next') {
    require_once app_path('includes/fin_voucher_browse.php');
    $nid = fin_voucher_nav_neighbor_id($pdo, $id, 'receipt', 'next');
    $id = $nid ?? 0;
}

$row = null;
if ($id > 0) {
    $row = fin_voucher_fetch_by_id($pdo, $id, 'receipt');
} elseif ($no !== '') {
    $row = fin_voucher_fetch_by_no($pdo, $no, 'receipt');
}

if (!$row) {
    echo json_encode(['ok' => false, 'error' => 'not_found', 'message' => 'السند غير موجود.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$checks = [];
$checksTotal = 0.0;
if ((string) ($row['pay_method'] ?? '') === 'check') {
    $list = fin_voucher_checks_load($pdo, (int) $row['id']);
    foreach ($list as $chk) {
        $due = (string) ($chk['due_date'] ?? '');
        $checks[] = [
            'check_no' => (string) ($chk['check_no'] ?? ''),
            'bank_name' => (string) ($chk['bank_name'] ?? ''),
            'check_amount' => (float) ($chk['check_amount'] ?? 0),
            'due_date' => $due,
            'due_date_dmy' => $due !== '' ? format_date_dmY($due) : '',
            'notes' => (string) ($chk['notes'] ?? ''),
        ];
        $checksTotal += (float) ($chk['check_amount'] ?? 0);
    }
}

echo json_encode([
    'ok' => true,
    'voucher' => [
        'id' => (int) $row['id'],
        'voucher_no' => (string) $row['voucher_no'],
        'voucher_date' => (string) $row['voucher_date'],
        'voucher_date_dmy' => format_date_dmY((string) $row['voucher_date']),
        'amount' => (float) $row['amount'],
        'check_amount' => (float) ($row['check_amount'] ?? 0),
        'pay_method' => (string) ($row['pay_method'] ?? 'cash'),
        'check_no' => (string) ($row['check_no'] ?? ''),
        'bank_name' => (string) ($row['bank_name'] ?? ''),
        'notes' => (string) ($row['description'] ?? ''),
        'customer_id' => (int) ($row['party_id'] ?? 0),
        'customer_name' => (string) ($row['customer_name'] ?? ''),
        'sales_rep_name' => (string) ($row['sales_rep_name'] ?? ''),
        'cash_account_id' => (int) ($row['cash_account_id'] ?? 0),
        'is_posted' => (bool) ($row['is_posted'] ?? false),
        'is_cancelled' => (bool) ($row['is_cancelled'] ?? false),
        'status_label' => (string) ($row['status_label'] ?? ''),
        'prev_id' => (int) ($row['prev_id'] ?? 0),
        'next_id' => (int) ($row['next_id'] ?? 0),
        'checks' => $checks,
        'checks_total' => round($checksTotal, 6),
    ],
], JSON_UNESCAPED_UNICODE);
