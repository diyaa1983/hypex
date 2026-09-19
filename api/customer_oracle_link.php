<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/crm_sales_rep_schema.php');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!user_can('customers') && !user_can('oracle_customers_sync') && !user_is_system_admin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$key = trim((string) ($_GET['oracle_key'] ?? $_GET['key'] ?? ''));

if ($method === 'GET') {
    $result = crm_customer_oracle_lookup_name($key);
    if (!$result['ok']) {
        http_response_code(400);
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method'], JSON_UNESCAPED_UNICODE);
    exit;
}

$body = $_POST;
$raw = file_get_contents('php://input');
if (is_string($raw) && $raw !== '' && str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $body = $decoded;
    }
}

$customerId = (int) ($body['customer_id'] ?? 0);
$oracleKey = trim((string) ($body['oracle_key'] ?? $body['key'] ?? ''));

try {
    $result = crm_customer_link_oracle(db(), $customerId, $oracleKey);
    if (!$result['ok']) {
        http_response_code(400);
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('customer_oracle_link: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'تعذر ربط العميل.'], JSON_UNESCAPED_UNICODE);
}
