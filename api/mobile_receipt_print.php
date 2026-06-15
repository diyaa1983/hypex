<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/mobile_receipt.php');
require_once app_path('includes/mobile_receipt_print.php');
require_once app_path('includes/fin_voucher_schema.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !mobile_can_access_receipt_api()) {
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
fin_voucher_ensure_schema_full($pdo);
$doc = mobile_receipt_print_document($pdo, $id);
if ($doc === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(array_merge(['ok' => true], $doc), JSON_UNESCAPED_UNICODE);
