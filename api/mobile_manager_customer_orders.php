<?php
declare(strict_types=1);

/**
 * طلبات شراء العملاء — مدير المبيعات: اختيار مندوب وعرض طلبات عملائه.
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/mobile_auth.php');
require_once app_path('includes/crm_sales_rep_schema.php');
require_once app_path('includes/sal_customer_order.php');
require_once app_path('includes/list_pagination.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!is_logged_in() || !mobile_is_context() || !user_in_mobile_group()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized', 'message' => 'الجلسة منتهية.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!user_can('m_manager_customer_orders') && !user_is_system_admin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden', 'message' => 'لا توجد صلاحية.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
sal_customer_order_ensure_schema($pdo);
crm_sales_rep_ensure_schema($pdo);

$reps = [];
try {
    $hasCode = false;
    try {
        $pdo->query('SELECT code FROM crm_sales_rep LIMIT 1');
        $hasCode = true;
    } catch (Throwable $e) {
    }
    $sql = $hasCode
        ? 'SELECT id, code, name_ar FROM crm_sales_rep WHERE is_active = 1 ORDER BY name_ar'
        : 'SELECT id, name_ar FROM crm_sales_rep WHERE is_active = 1 ORDER BY name_ar';
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $reps[] = [
            'id' => (int) ($r['id'] ?? 0),
            'code' => (string) ($r['code'] ?? ''),
            'name_ar' => (string) ($r['name_ar'] ?? ''),
        ];
    }
} catch (Throwable $e) {
    $reps = [];
}

$salesRepId = (int) ($_GET['sales_rep_id'] ?? 0);
if ($salesRepId < 1) {
    echo json_encode([
        'ok' => true,
        'reps' => $reps,
        'count' => count($reps),
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$repName = '';
$repCode = '';
foreach ($reps as $r) {
    if ((int) $r['id'] === $salesRepId) {
        $repName = (string) $r['name_ar'];
        $repCode = (string) $r['code'];
        break;
    }
}
if ($repName === '') {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'المندوب غير موجود أو غير نشط.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
$dateFrom = trim((string) ($_GET['from'] ?? ''));
$dateTo = trim((string) ($_GET['to'] ?? ''));
$dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) ? $dateFrom : null;
$dateTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) ? $dateTo : null;

$isSent = null;
if (array_key_exists('is_sent', $_GET)) {
    $raw = $_GET['is_sent'];
    if ($raw === '0' || $raw === 0 || $raw === false || $raw === 'false') {
        $isSent = 0;
    } elseif ($raw === '1' || $raw === 1 || $raw === true || $raw === 'true') {
        $isSent = 1;
    }
}

$total = sal_customer_order_list_count($pdo, $q, $salesRepId, null, null, $isSent, $dateFrom, $dateTo);
$pager = mobile_list_pager_from_request($pdo, $total);
$rows = [];
if ($total > 0) {
    $rows = sal_customer_order_list_fetch(
        $pdo,
        $q,
        $salesRepId,
        null,
        null,
        (int) $pager['limit'],
        (int) $pager['offset'],
        $isSent,
        $dateFrom,
        $dateTo
    );
}

echo json_encode([
    'ok' => true,
    'reps' => $reps,
    'sales_rep_id' => $salesRepId,
    'sales_rep_name' => $repName,
    'sales_rep_code' => $repCode,
    'orders' => $rows,
    'pager' => mobile_list_pager_meta($pager),
    'rows_per_page' => (int) $pager['per_page'],
], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
