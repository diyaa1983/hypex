<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/mobile_receipt.php');
require_once app_path('includes/mobile_receipt_print.php');
require_once app_path('includes/mobile_dompdf.php');
require_once app_path('includes/fin_voucher_schema.php');

if (!is_logged_in() || !mobile_can_access_receipt_api()) {
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
fin_voucher_ensure_schema_full($pdo);
$doc = mobile_receipt_print_document($pdo, $id);
if ($doc === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'not_found';
    exit;
}

$html = (string) ($doc['html_pdf'] ?? '');
if ($html === '') {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'no_html';
    exit;
}

$row = fin_voucher_fetch_by_id($pdo, $id, 'receipt');
$no = trim((string) ($row['voucher_no'] ?? ''));
$fname = $no !== '' ? 'سند قبض - ' . $no . '.pdf' : 'سند قبض.pdf';

mobile_dompdf_stream_pdf($html, $fname);
