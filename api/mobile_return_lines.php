<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/mobile_auth.php');
require_once app_path('includes/mobile_return.php');
require_once app_path('includes/sal_return_schema.php');
require_once app_path('includes/sal_invoice_post.php');
require_once app_path('includes/sal_return_invoice_lines.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !user_can_mobile_sales_returns()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'ليس لديك صلاحية.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$invoiceId = (int) ($_GET['invoice_id'] ?? 0);
$customerId = (int) ($_GET['customer_id'] ?? 0);
$excludeReturnId = (int) ($_GET['exclude_return_id'] ?? 0);

if ($invoiceId < 1) {
    echo json_encode(['ok' => false, 'message' => 'اختر فاتورة البيع.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
sal_return_ensure_schema($pdo);

$invSt = $pdo->prepare('SELECT id, customer_id, invoice_no, status FROM sal_invoice WHERE id = ? LIMIT 1');
$invSt->execute([$invoiceId]);
$inv = $invSt->fetch(PDO::FETCH_ASSOC);

if (!$inv || (string) ($inv['status'] ?? '') !== 'confirmed') {
    echo json_encode(['ok' => false, 'message' => 'فاتورة البيع غير موجودة أو غير مؤكدة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($customerId > 0 && (int) ($inv['customer_id'] ?? 0) !== $customerId) {
    echo json_encode(['ok' => false, 'message' => 'الفاتورة لا تخص العميل المختار.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!sal_invoice_is_posted($pdo, $invoiceId)) {
    echo json_encode([
        'ok' => false,
        'message' => 'لا يمكن إرجاع إلا فواتير مبيعات مرحّلة. رحّل الفاتورة أولاً.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$lines = sal_return_fetch_invoice_lines($pdo, $invoiceId, $excludeReturnId);

require_once app_path('includes/company_settings.php');
$defaultTax = (float) (company_settings($pdo)['tax_rate_percent'] ?? 0);

echo json_encode([
    'ok' => true,
    'invoice_no' => (string) ($inv['invoice_no'] ?? ''),
    'default_tax_percent' => $defaultTax,
    'lines' => $lines,
], JSON_UNESCAPED_UNICODE);
