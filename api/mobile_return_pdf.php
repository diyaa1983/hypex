<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/sal_return_load.php');
require_once app_path('includes/mobile_return.php');
require_once app_path('includes/mobile_return_print.php');
require_once app_path('includes/mobile_dompdf.php');

header('X-Content-Type-Options: nosniff');

if (!is_logged_in() || !user_can_mobile_sales_returns()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'forbidden';
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
if ($id < 1) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'invalid_id';
    exit;
}

try {
    $pdo = db();
    $ret = sal_return_fetch_full($pdo, $id);
    if (!$ret) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'not_found';
        exit;
    }

    $doc = mobile_return_print_document($pdo, $ret);
    $html = (string) ($doc['html_pdf'] ?? '');
    if ($html === '') {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'no_html';
        exit;
    }

    $no = trim((string) ($ret['return_no'] ?? ''));
    $fname = $no !== '' ? 'مرتجع مبيعات - ' . $no . '.pdf' : 'مرتجع مبيعات.pdf';

    mobile_dompdf_stream_pdf($html, $fname);
} catch (Throwable $e) {
    error_log('mobile_return_pdf id=' . $id . ': ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'pdf_error';
}
