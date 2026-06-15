<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/sal_invoice_load.php');
require_once app_path('includes/mobile_invoice.php');
require_once app_path('includes/mobile_invoice_print.php');
require_once app_path('includes/mobile_dompdf.php');

if (!is_logged_in() || !mobile_can_access_sales_invoice_api()) {
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

$pdo = db();
sal_invoice_ensure_schema($pdo);
$invoice = sal_invoice_fetch_by_id($pdo, $id, 'all');
if (!$invoice) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'not_found';
    exit;
}

$doc = mobile_invoice_print_document($pdo, $invoice);
$html = (string) ($doc['html_pdf'] ?? '');
if ($html === '') {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'no_html';
    exit;
}

$no = trim((string) ($invoice['invoice_no'] ?? ''));
$fname = $no !== '' ? 'فاتورة - ' . $no . '.pdf' : 'فاتورة.pdf';

mobile_dompdf_stream_pdf($html, $fname);
