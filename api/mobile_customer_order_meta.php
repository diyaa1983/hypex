<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/mobile_auth.php');
require_once app_path('includes/sal_customer_order.php');
require_once app_path('includes/warehouse_access.php');
require_once app_path('includes/company_settings.php');
header('Content-Type: application/json; charset=utf-8');
if (!is_logged_in() || !mobile_can_access_customer_order_api()) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'forbidden']); exit; }
$pdo=db(); sal_customer_order_ensure_schema($pdo);
$settings = company_settings($pdo);
$defaultTaxPercent = (float) ($settings['tax_rate_percent'] ?? 15);
$taxRates = [];
try {
    $taxRates = $pdo->query(
        'SELECT id, name_ar, rate_percent
         FROM sys_tax_rate
         WHERE is_active = 1
         ORDER BY sort_order, id'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $taxRates = [];
}
if ($taxRates === []) {
    $taxRates = [[
        'id' => 0,
        'name_ar' => 'افتراضي',
        'rate_percent' => $defaultTaxPercent,
    ]];
}
echo json_encode([
    'ok' => true,
    'warehouses' => array_map(
        static fn($w) => ['id' => (int) $w['id'], 'name' => (string) $w['name_ar']],
        wh_access_list_warehouses($pdo, 'issue')
    ),
    'default_warehouse_id' => wh_access_default_issue_warehouse_id($pdo),
    'decimal_places' => company_decimal_places($pdo),
    'default_tax_percent' => $defaultTaxPercent,
    'tax_rates' => array_map(static fn(array $rate): array => [
        'id' => (int) ($rate['id'] ?? 0),
        'name' => (string) ($rate['name_ar'] ?? ''),
        'rate_percent' => (float) ($rate['rate_percent'] ?? 0),
    ], $taxRates),
], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
