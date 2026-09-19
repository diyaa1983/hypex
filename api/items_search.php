<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/inv_warehouse_items.php';

header('Content-Type: application/json; charset=utf-8');

if (
    !is_logged_in()
    || (
        !user_can_sales_invoices()
        && !user_can('m_sales_invoices')
        && !user_can_purchase_invoices()
        && !user_can_purchase_orders()
        && !user_can_purchase_returns()
        && !user_can('sales_delivery')
        && !user_can('item_stock_movements')
        && !user_can('item_sale_price_adjust')
        && !user_can('items')
        && !user_can('report_warehouse_items')
        && !user_can('m_sales_returns')
        && !user_can('m_rep_load')
        && !user_can('m_rep_return')
        && !user_can('warehouse_moves')
        && !user_can('report_sales_qty_extra')
        && !user_can('report_sales_by_item')
        && !user_can('report_purchases_by_item')
        && !user_can('report_sales')
        && !user_can('report_purchases')
        && !user_can('report_inventory')
        && !user_can('m_customer_orders')
        && !user_can('sales_customer_orders')
        && !user_can('sales_customer_orders_approve')
        && !user_can('report_customer_orders')
    )
) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
$exactCode = trim((string) ($_GET['code'] ?? ''));
$warehouseId = (int) ($_GET['warehouse_id'] ?? 0);
$listAll = isset($_GET['list']) && (string) $_GET['list'] === '1';
$pickerMode = isset($_GET['picker']) && (string) $_GET['picker'] === '1';

$pdo = db();
require_once dirname(__DIR__) . '/includes/inv_item_units.php';
inv_item_units_ensure_schema($pdo);

require_once dirname(__DIR__) . '/includes/warehouse_access.php';

$whAccessMode = 'view';
if (
    user_can_sales_invoices()
    || user_can('m_sales_invoices')
    || user_can('sales_delivery')
    || user_can('m_sales_returns')
    || user_can('warehouse_moves')
    || user_can('m_rep_load')
    || user_can('m_rep_return')
) {
    $whAccessMode = 'issue';
}

if ($warehouseId > 0 && !wh_access_can($pdo, $warehouseId, $whAccessMode)) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => $whAccessMode === 'issue'
            ? wh_access_deny_issue_message()
            : wh_access_deny_view_message(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($exactCode !== '') {
    $rows = inv_items_find_by_code($pdo, $exactCode, $warehouseId);
    foreach ($rows as &$row) {
        if (!isset($row['barcode']) || $row['barcode'] === '') {
            $row['barcode'] = $row['sku'] ?? '';
        }
        if (isset($row['stock_qty'])) {
            $row['stock_qty'] = company_round_amount((float) $row['stock_qty']);
        }
    }
    unset($row);
    $rows = inv_item_units_attach_to_items($pdo, $rows);

    echo json_encode([
        'ok' => true,
        'items' => $rows,
        'warehouse_id' => $warehouseId,
        'exact' => true,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($q === '' && !$listAll) {
    echo json_encode(['ok' => true, 'items' => [], 'warehouse_id' => $warehouseId]);
    exit;
}

if ($pickerMode) {
    $built = inv_items_picker_query($pdo, $q, $listAll || $q === '');
    $built['has_stock'] = false;
} elseif ($warehouseId > 0) {
    $built = inv_warehouse_items_search_query($pdo, $warehouseId, $q, $listAll || $q === '');
} else {
    $built = inv_items_search_all_query($pdo, $q, $listAll || $q === '');
    $built['has_stock'] = false;
}

if ($built['sql'] === '') {
    echo json_encode(['ok' => true, 'items' => [], 'warehouse_id' => $warehouseId]);
    exit;
}

$st = $pdo->prepare($built['sql']);
$st->execute($built['params']);
$rows = $st->fetchAll();

foreach ($rows as &$row) {
    if (!isset($row['barcode']) || $row['barcode'] === '') {
        $row['barcode'] = $row['sku'] ?? '';
    }
    if (isset($row['stock_qty'])) {
        $row['stock_qty'] = company_round_amount((float) $row['stock_qty']);
    }
}
unset($row);
$rows = inv_item_units_attach_to_items($pdo, $rows);

echo json_encode([
    'ok' => true,
    'items' => $rows,
    'warehouse_id' => $warehouseId,
    'uses_stock_balance' => !empty($built['has_stock']),
], JSON_UNESCAPED_UNICODE);
