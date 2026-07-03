<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/mobile_rep_custody.php');
require_once app_path('includes/inv_warehouse_items.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$direction = trim((string) ($_GET['direction'] ?? 'load'));
$perm = $direction === 'return' ? 'm_rep_return' : 'm_rep_load';
if (!user_can($perm)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
$ctx = mobile_rep_custody_context($pdo);
if ($ctx === null) {
    echo json_encode([
        'ok' => false,
        'error' => 'no_rep',
        'message' => 'حسابك غير مربوط بمندوب أو مستودع عهدة.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$warehouseId = mobile_rep_custody_source_warehouse_id($ctx, $direction);
$q = trim((string) ($_GET['q'] ?? ''));
$listAll = isset($_GET['list']) && (string) $_GET['list'] === '1';
$positiveOnly = isset($_GET['positive']) && (string) $_GET['positive'] === '1';

if ($q === '' && !$listAll) {
    echo json_encode(['ok' => true, 'items' => [], 'warehouse_id' => $warehouseId], JSON_UNESCAPED_UNICODE);
    exit;
}

$built = inv_warehouse_items_search_query($pdo, $warehouseId, $q, $listAll || $q === '');
if ($built['sql'] === '') {
    echo json_encode(['ok' => true, 'items' => [], 'warehouse_id' => $warehouseId], JSON_UNESCAPED_UNICODE);
    exit;
}

$st = $pdo->prepare($built['sql']);
$st->execute($built['params']);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$items = [];
foreach ($rows as $row) {
    $stockQty = isset($row['stock_qty']) ? (float) $row['stock_qty'] : null;
    if ($positiveOnly && ($stockQty === null || $stockQty <= 0)) {
        continue;
    }
    if (!isset($row['barcode']) || $row['barcode'] === '') {
        $row['barcode'] = $row['sku'] ?? '';
    }
    if ($stockQty !== null) {
        $row['stock_qty'] = company_round_amount($stockQty);
    }
    $items[] = $row;
}

echo json_encode([
    'ok' => true,
    'items' => $items,
    'warehouse_id' => $warehouseId,
    'uses_stock_balance' => !empty($built['has_stock']),
], JSON_UNESCAPED_UNICODE);
