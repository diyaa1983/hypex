<?php

declare(strict_types=1);



require_once dirname(__DIR__) . '/includes/bootstrap.php';

require_once app_path('includes/fin_voucher_load.php');

require_once app_path('includes/fin_voucher_schema.php');



header('Content-Type: application/json; charset=utf-8');



if (!is_logged_in() || !user_can('cash_payment')) {

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

    $firstId = fin_voucher_latest_id($pdo, 'payment');

    if ($firstId === null) {

        echo json_encode([

            'ok' => false,

            'error' => 'empty',

            'message' => 'لا توجد سندات صرف محفوظة بعد.',

        ], JSON_UNESCAPED_UNICODE);

        exit;

    }

    $id = $firstId;

} elseif ($edge === 'last') {

    require_once app_path('includes/fin_voucher_browse.php');

    $lastId = fin_voucher_oldest_id($pdo, 'payment');

    if ($lastId === null) {

        echo json_encode([

            'ok' => false,

            'error' => 'empty',

            'message' => 'لا توجد سندات صرف محفوظة بعد.',

        ], JSON_UNESCAPED_UNICODE);

        exit;

    }

    $id = $lastId;

}



if ($id > 0 && $dir === 'prev') {

    require_once app_path('includes/fin_voucher_browse.php');

    $nid = fin_voucher_nav_neighbor_id($pdo, $id, 'payment', 'prev');

    $id = $nid ?? 0;

} elseif ($id > 0 && $dir === 'next') {

    require_once app_path('includes/fin_voucher_browse.php');

    $nid = fin_voucher_nav_neighbor_id($pdo, $id, 'payment', 'next');

    $id = $nid ?? 0;

}



$row = null;

if ($id > 0) {

    $row = fin_voucher_fetch_by_id($pdo, $id, 'payment');

} elseif ($no !== '') {

    $row = fin_voucher_fetch_by_no($pdo, $no, 'payment');

}



if (!$row) {

    echo json_encode(['ok' => false, 'error' => 'not_found', 'message' => 'السند غير موجود.'], JSON_UNESCAPED_UNICODE);

    exit;

}



require_once app_path('includes/fin_payment_parties.php');

$partyType = fin_payment_normalize_party_type((string) ($row['party_type'] ?? 'supplier'));

$partyId = (int) ($row['party_id'] ?? 0);

$offsetAccountId = (int) ($row['offset_account_id'] ?? 0);



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

        'party_type' => $partyType,

        'party_id' => $partyId,

        'party_name' => (string) ($row['party_name'] ?? ''),

        'customer_id' => $partyType === 'customer' ? $partyId : 0,

        'customer_name' => (string) ($row['customer_name'] ?? ''),

        'supplier_id' => $partyType === 'supplier' ? $partyId : 0,

        'supplier_name' => (string) ($row['supplier_name'] ?? ''),

        'employee_id' => $partyType === 'employee' ? $partyId : 0,

        'employee_name' => (string) ($row['employee_name'] ?? ''),

        'offset_account_id' => $offsetAccountId,

        'offset_account_label' => (string) ($row['offset_account_label'] ?? ''),

        'employee_pay_kind' => (string) ($row['employee_pay_kind'] ?? 'other'),

        'hr_advance_id' => (int) ($row['hr_advance_id'] ?? 0),

        'hr_advance_code' => (string) ($row['hr_advance_code'] ?? ''),

        'hr_advance_amount' => (float) ($row['hr_advance_amount'] ?? 0),

        'sales_rep_name' => (string) ($row['sales_rep_name'] ?? ''),

        'cash_account_id' => (int) ($row['cash_account_id'] ?? 0),

        'is_posted' => (bool) ($row['is_posted'] ?? false),

        'is_cancelled' => (bool) ($row['is_cancelled'] ?? false),

        'status_label' => (string) ($row['status_label'] ?? ''),

        'prev_id' => (int) ($row['prev_id'] ?? 0),

        'next_id' => (int) ($row['next_id'] ?? 0),

    ],

], JSON_UNESCAPED_UNICODE);
