<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/sal_invoice_load.php');
require_once app_path('includes/mobile_invoice.php');
require_once app_path('includes/mobile_invoice_print.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !mobile_can_access_sales_invoice_api()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
if ($id < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
sal_invoice_ensure_schema($pdo);
$invoice = sal_invoice_fetch_by_id($pdo, $id, 'all');
if (!$invoice) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$doc = mobile_invoice_print_document($pdo, $invoice);
echo json_encode([
    'ok' => true,
    'html' => $doc['html'],
    'html_pdf' => $doc['html_pdf'] ?? $doc['html'],
    'styles' => $doc['styles'],
    'styles_pdf' => $doc['styles_pdf'],
    'inner' => $doc['inner'],
    'inner_pdf' => $doc['inner_pdf'],
    'mobile_pdf' => $doc['mobile_pdf'] ?? true,
    'pdf_download_url' => $doc['pdf_download_url'] ?? '',
], JSON_UNESCAPED_UNICODE);
