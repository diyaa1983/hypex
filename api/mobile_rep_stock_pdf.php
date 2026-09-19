<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/mobile_rep_stock_access.php');
require_once app_path('includes/mobile_rep_stock_print.php');
require_once app_path('includes/mobile_dompdf.php');

if (!is_logged_in() || !user_can('m_rep_stock')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'forbidden';
    exit;
}

$pdo = db();
$access = mobile_rep_stock_access($pdo);
if (!$access['ok']) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'no_warehouse';
    exit;
}

$requestedWh = (int) ($_GET['warehouse_id'] ?? $_POST['warehouse_id'] ?? 0);
$warehouse = mobile_rep_stock_pick_warehouse($access, $requestedWh);
if ($warehouse === null || (int) $warehouse['id'] < 1) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'warehouse_forbidden';
    exit;
}

$search = trim((string) ($_GET['q'] ?? $_POST['q'] ?? ''));
$ctx = mobile_rep_stock_print_ctx($access, $warehouse);
$doc = mobile_rep_stock_print_document($pdo, $ctx, $search);
if ($doc === null) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'no_doc';
    exit;
}

$html = (string) ($doc['html_pdf'] ?? '');
if ($html === '') {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'no_html';
    exit;
}

mobile_dompdf_stream_pdf($html, mobile_rep_stock_print_pdf_filename($ctx));
