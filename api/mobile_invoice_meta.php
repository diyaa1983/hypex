<?php
declare(strict_types=1);

/**
 * بيانات مساعدة لإنشاء فاتورة مبيعات من تطبيق الهاتف الأصلي:
 * المستودعات المتاحة للصرف + المستودع الافتراضي + الخانات العشرية + نسبة الضريبة.
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

    echo json_encode([
        'ok' => true,
        'warehouses' => $warehouses,
        'default_warehouse_id' => wh_access_default_issue_warehouse_id($pdo),
        'decimal_places' => company_decimal_places($pdo),
        'default_tax_percent' => (float) ($settings['tax_rate_percent'] ?? 15),
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('mobile_invoice_meta: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error'], JSON_UNESCAPED_UNICODE);
}
