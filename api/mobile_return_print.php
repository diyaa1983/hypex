<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/sal_return_load.php');
require_once app_path('includes/mobile_return.php');
require_once app_path('includes/mobile_return_print.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !user_can_mobile_sales_returns()) {
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
$ret = sal_return_fetch_full($pdo, $id);
if (!$ret) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$doc = mobile_return_print_document($pdo, $ret);
echo json_encode(array_merge(['ok' => true], $doc), JSON_UNESCAPED_UNICODE);
