<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/sal_customer_order.php');
require_once app_path('includes/crm_sales_rep_schema.php');
require_once app_path('includes/document_header.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !mobile_can_access_customer_order_api()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$pdo = db();
sal_customer_order_ensure_schema($pdo);

$id = (int) ($_GET['id'] ?? 0);
$customerIdQ = (int) ($_GET['customer_id'] ?? 0);
$rep = user_is_system_admin() ? null : crm_mobile_scoped_sales_rep_id($pdo);

if ($id > 0) {
    $order = sal_customer_order_fetch($pdo, $id);
    if (
        !$order
        || (!user_is_system_admin() && ($rep === null || (int) $order['sales_rep_id'] !== $rep))
    ) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'not_found']);
        exit;
    }
    $order = document_header_attach_brand($order, $pdo);
    $scopeCustomerId = $customerIdQ > 0 ? $customerIdQ : (int) ($order['customer_id'] ?? 0);
    $nav = sal_customer_order_browse_neighbors(
        $pdo,
        $id,
        user_is_system_admin() ? null : $rep,
        $scopeCustomerId > 0 ? $scopeCustomerId : null
    );
    echo json_encode(
        [
            'ok' => true,
            'order' => $order,
            'first_id' => $nav['first_id'],
            'prev_id' => $nav['prev_id'],
            'next_id' => $nav['next_id'],
            'last_id' => $nav['last_id'],
        ],
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

// مستند جديد: جيران النطاق فقط (السابق = آخر طلب)
if (!user_is_system_admin() && $rep === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'not_found']);
    exit;
}

$scopeCustomerId = $customerIdQ > 0 ? $customerIdQ : null;
$nav = sal_customer_order_browse_neighbors(
    $pdo,
    0,
    user_is_system_admin() ? null : $rep,
    $scopeCustomerId
);

echo json_encode(
    [
        'ok' => true,
        'order' => null,
        'first_id' => $nav['first_id'],
        'prev_id' => $nav['prev_id'],
        'next_id' => $nav['next_id'],
        'last_id' => $nav['last_id'],
    ],
    JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
);
