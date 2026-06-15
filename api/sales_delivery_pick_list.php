<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/sal_delivery_invoice_link.php');
require_once app_path('includes/sal_delivery_load.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !user_can('sales_invoices')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
sal_delivery_ensure_schema($pdo);
sal_delivery_invoice_link_ensure($pdo);

$id = (int) ($_GET['id'] ?? 0);
$customerId = (int) ($_GET['customer_id'] ?? 0);

if ($id > 0) {
    $row = sal_delivery_fetch_by_id($pdo, $id);
    if (!$row || !(bool) ($row['is_posted'] ?? false)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'not_found'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (sal_delivery_has_linked_invoice($pdo, $id)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'linked'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $settings = company_settings($pdo);
    $defaultTax = (float) ($settings['tax_rate_percent'] ?? 15);
    $linesOut = [];
    foreach ($row['lines'] as $ln) {
        $itemId = (int) ($ln['item_id'] ?? 0);
        $qty = (float) ($ln['qty'] ?? 0);
        $priceSt = $pdo->prepare('SELECT default_sale FROM inv_item WHERE id = ? LIMIT 1');
        $priceSt->execute([$itemId]);
        $unitPrice = (float) ($priceSt->fetchColumn() ?: 0);
        $linesOut[] = [
            'item_id' => $itemId,
            'name_ar' => (string) ($ln['name_ar'] ?? ''),
            'barcode' => (string) ($ln['barcode'] ?? ''),
            'sku' => (string) ($ln['sku'] ?? ''),
            'line_desc' => (string) ($ln['line_desc'] ?? ''),
            'qty' => $qty,
            'qty_extra' => 0,
            'unit_price' => $unitPrice,
            'tax_rate_percent' => $defaultTax,
            'discount_pct' => 0,
        ];
    }

    echo json_encode([
        'ok' => true,
        'delivery' => [
            'id' => (int) $row['id'],
            'delivery_no' => (string) $row['delivery_no'],
            'delivery_date' => (string) $row['delivery_date'],
            'delivery_date_dmy' => format_date_dmY((string) $row['delivery_date']),
            'customer_id' => (int) $row['customer_id'],
            'customer_name' => (string) ($row['customer_name'] ?? ''),
            'warehouse_id' => (int) ($row['warehouse_id'] ?? 0),
            'warehouse_name' => (string) ($row['warehouse_name'] ?? ''),
            'notes' => (string) ($row['notes'] ?? ''),
        ],
        'lines' => $linesOut,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$rows = sal_delivery_list_available_for_invoice($pdo, $customerId);
$list = [];
foreach ($rows as $row) {
    $list[] = [
        'id' => (int) ($row['id'] ?? 0),
        'delivery_no' => (string) ($row['delivery_no'] ?? ''),
        'delivery_date' => (string) ($row['delivery_date'] ?? ''),
        'delivery_date_dmy' => format_date_dmY((string) ($row['delivery_date'] ?? '')),
        'customer_id' => (int) ($row['customer_id'] ?? 0),
        'customer_name' => (string) ($row['customer_name'] ?? ''),
        'warehouse_name' => (string) ($row['warehouse_name'] ?? ''),
    ];
}

echo json_encode(['ok' => true, 'deliveries' => $list], JSON_UNESCAPED_UNICODE);
