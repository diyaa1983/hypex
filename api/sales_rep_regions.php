<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/crm_region_excel_import.php');

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
$repId = (int) ($_GET['sales_rep_id'] ?? $_GET['id'] ?? 0);
$regions = crm_sales_rep_regions_detail($pdo, $repId);

echo json_encode([
    'ok' => true,
    'sales_rep_id' => $repId,
    'regions' => $regions,
], JSON_UNESCAPED_UNICODE);
