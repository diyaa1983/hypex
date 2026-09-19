<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/crm_region.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || (
    !user_can('customers')
    && !user_can('customer_regions')
    && !user_can('sales_reps')
    && !user_can_sales_invoices()
)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
crm_region_ensure_schema($pdo);
$regionId = (int) ($_GET['region_id'] ?? 0);
$addresses = crm_region_address_load($pdo, $regionId, true);

echo json_encode([
    'ok' => true,
    'region_id' => $regionId,
    'addresses' => array_map(static function (array $a): array {
        return [
            'id' => (int) $a['id'],
            'name_ar' => (string) $a['name_ar'],
        ];
    }, $addresses),
], JSON_UNESCAPED_UNICODE);
