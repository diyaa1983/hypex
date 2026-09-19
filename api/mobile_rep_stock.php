<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/mobile_rep_stock_access.php');
require_once app_path('includes/inv_warehouse_items_report.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !user_can('m_rep_stock')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
$access = mobile_rep_stock_access($pdo);
if (!$access['ok']) {
    echo json_encode([
        'ok' => false,
        'error' => (string) ($access['error'] ?? 'no_warehouse'),
        'message' => (string) ($access['message'] ?? 'لا يوجد مستودع متاح.'),
        'warehouses' => [],
        'items' => [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$requestedWh = (int) ($_GET['warehouse_id'] ?? 0);
$warehouse = mobile_rep_stock_pick_warehouse($access, $requestedWh);
if ($warehouse === null || (int) $warehouse['id'] < 1) {
    echo json_encode([
        'ok' => false,
        'error' => 'no_warehouse',
        'message' => 'المستودع غير متاح أو غير مسموح.',
        'warehouses' => $access['warehouses'],
        'items' => [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
$lines = inv_report_warehouse_items_lines($pdo, (int) $warehouse['id'], true);

if ($q !== '') {
    $qLower = mb_strtolower($q, 'UTF-8');
    $lines = array_values(array_filter($lines, static function (array $row) use ($qLower): bool {
        $hay = mb_strtolower(
            (string) ($row['item_name'] ?? '') . ' '
            . (string) ($row['item_sku'] ?? '') . ' '
            . (string) ($row['category_name'] ?? ''),
            'UTF-8'
        );

        return str_contains($hay, $qLower);
    }));
}

foreach ($lines as &$row) {
    $row['qty'] = company_round_amount((float) ($row['qty'] ?? 0));
}
unset($row);

echo json_encode([
    'ok' => true,
    'items' => $lines,
    'warehouse' => [
        'id' => (int) $warehouse['id'],
        'name_ar' => (string) $warehouse['name_ar'],
        'code' => (string) $warehouse['code'],
        'is_van' => !empty($warehouse['is_van']),
    ],
    'warehouses' => $access['warehouses'],
    'rep_name' => (string) $access['rep_name'],
    'has_rep' => !empty($access['has_rep']),
    'source' => !empty($warehouse['is_van']) ? 'van' : 'acl',
], JSON_UNESCAPED_UNICODE);
