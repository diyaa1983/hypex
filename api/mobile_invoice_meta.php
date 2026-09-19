<?php
declare(strict_types=1);

/**
 * بيانات مساعدة لإنشاء فاتورة مبيعات من تطبيق الهاتف الأصلي:
 * المستودعات المتاحة للصرف + المستودع الافتراضي + الخانات العشرية + نسب الضريبة.
 * قراءة فقط، جلسة الكوكيز الحالية.
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/mobile_auth.php');
require_once app_path('includes/mobile_invoice.php');
require_once app_path('includes/warehouse_access.php');
require_once app_path('includes/company_settings.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!is_logged_in() || !mobile_can_access_sales_invoice_api()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = db();
    $warehouses = array_map(static function (array $w): array {
        return [
            'id' => (int) ($w['id'] ?? 0),
            'name' => (string) ($w['name_ar'] ?? ''),
        ];
    }, wh_access_list_warehouses($pdo, 'issue'));

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

    // نفس افتراضي شاشة الموبايل الحالية: 5% إن وُجدت، وإلا ضريبة الشركة.
    $mobileDefaultTaxPercent = $defaultTaxPercent;
    foreach ($taxRates as $rate) {
        if (abs((float) ($rate['rate_percent'] ?? 0) - 5.0) < 0.001) {
            $mobileDefaultTaxPercent = 5.0;
            break;
        }
    }
    $taxRates = array_map(static function (array $rate): array {
        return [
            'id' => (int) ($rate['id'] ?? 0),
            'name' => (string) ($rate['name_ar'] ?? ''),
            'rate_percent' => (float) ($rate['rate_percent'] ?? 0),
        ];
    }, $taxRates);

    echo json_encode([
        'ok' => true,
        'warehouses' => $warehouses,
        'default_warehouse_id' => wh_access_default_issue_warehouse_id($pdo),
        'decimal_places' => company_decimal_places($pdo),
        'default_tax_percent' => $mobileDefaultTaxPercent,
        'tax_rates' => $taxRates,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('mobile_invoice_meta: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error'], JSON_UNESCAPED_UNICODE);
}
