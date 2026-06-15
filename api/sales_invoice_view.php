<?php

declare(strict_types=1);



require_once dirname(__DIR__) . '/includes/bootstrap.php';

require_once dirname(__DIR__) . '/includes/sal_invoice_load.php';

require_once dirname(__DIR__) . '/includes/sal_invoice_browse.php';

require_once dirname(__DIR__) . '/includes/crm_customer_ledger.php';

require_once app_path('includes/mobile_invoice.php');

header('Content-Type: application/json; charset=utf-8');



if (!is_logged_in() || !mobile_can_access_sales_invoice_api()) {

    http_response_code(403);

    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);

    exit;

}



$pdo = db();

sal_invoice_ensure_schema($pdo);

crm_ledger_ensure_schema($pdo);



$id = (int) ($_GET['id'] ?? 0);

$no = trim((string) ($_GET['no'] ?? ''));

$dir = trim((string) ($_GET['dir'] ?? ''));

$edge = trim((string) ($_GET['edge'] ?? ''));

$filter = sal_invoice_normalize_browse_filter((string) ($_GET['filter'] ?? 'all'));



if ($edge === 'first') {

    $firstId = sal_invoice_first_in_filter($pdo, $filter);

    if ($firstId === null) {

        echo json_encode([

            'ok' => false,

            'error' => 'empty',

            'message' => 'لا توجد فواتير في هذا التصفّح.',

            'browse_filter' => $filter,

            'browse_count' => 0,

        ], JSON_UNESCAPED_UNICODE);

        exit;

    }

    $id = $firstId;

}



if ($dir === 'prev' || $dir === 'next') {

    if ($id < 1) {

        echo json_encode(['ok' => false, 'error' => 'invalid_id'], JSON_UNESCAPED_UNICODE);

        exit;

    }

    $navId = sal_invoice_nav_neighbor_id($pdo, $id, $dir, $filter);

    if ($navId === null) {

        echo json_encode([

            'ok' => false,

            'error' => 'no_more',

            'message' => $dir === 'prev' ? 'لا توجد فاتورة أقدم في هذا التصفّح.' : 'لا توجد فاتورة أحدث في هذا التصفّح.',

            'browse_filter' => $filter,

        ], JSON_UNESCAPED_UNICODE);

        exit;

    }

    $id = $navId;

}



$invoice = null;

if ($id > 0) {

    $invoice = sal_invoice_fetch_by_id($pdo, $id, $filter);

} elseif ($no !== '') {

    $invoice = sal_invoice_fetch_by_no($pdo, $no, $filter);

}



if (!$invoice) {

    echo json_encode([

        'ok' => false,

        'error' => 'not_found',

        'message' => 'الفاتورة غير موجودة أو لا تطابق التصفّح الحالي.',

        'browse_filter' => $filter,

        'browse_count' => sal_invoice_count_in_filter($pdo, $filter),

    ], JSON_UNESCAPED_UNICODE);

    exit;

}



$invoice = mobile_invoice_enrich_display($pdo, $invoice);

echo json_encode([

    'ok' => true,

    'invoice' => $invoice,

    'browse_filter' => $filter,

    'browse_count' => (int) ($invoice['browse_count'] ?? 0),

], JSON_UNESCAPED_UNICODE);

