<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/sal_delivery_load.php');
require_once app_path('includes/sal_delivery_schema.php');
require_once app_path('includes/sal_delivery_browse.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !user_can('sales_delivery')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
sal_delivery_ensure_schema($pdo);

$id = (int) ($_GET['id'] ?? 0);
$no = trim((string) ($_GET['no'] ?? ''));
$dir = trim((string) ($_GET['dir'] ?? ''));
$edge = trim((string) ($_GET['edge'] ?? ''));

if ($edge === 'first') {
    $firstId = sal_delivery_first_id($pdo);
    if ($firstId === null) {
        echo json_encode([
            'ok' => false,
            'error' => 'empty',
            'message' => 'لا توجد سندات محفوظة بعد.',
            'browse_count' => 0,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $id = $firstId;
}

if ($id > 0 && $dir === 'prev') {
    $st = $pdo->prepare('SELECT id FROM sal_delivery WHERE id < ? ORDER BY id DESC LIMIT 1');
    $st->execute([$id]);
    $nid = $st->fetchColumn();
    $id = $nid !== false ? (int) $nid : 0;
} elseif ($id > 0 && $dir === 'next') {
    $st = $pdo->prepare('SELECT id FROM sal_delivery WHERE id > ? ORDER BY id ASC LIMIT 1');
    $st->execute([$id]);
    $nid = $st->fetchColumn();
    $id = $nid !== false ? (int) $nid : 0;
}

$row = null;
if ($id > 0) {
    $row = sal_delivery_fetch_by_id($pdo, $id);
} elseif ($no !== '') {
    $row = sal_delivery_fetch_by_no($pdo, $no);
}

if (!$row) {
    echo json_encode(['ok' => false, 'error' => 'not_found', 'message' => 'السند غير موجود.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$linesOut = [];
foreach ($row['lines'] as $ln) {
    $linesOut[] = [
        'line_id' => (int) ($ln['id'] ?? 0),
        'item_id' => (int) ($ln['item_id'] ?? 0),
        'name_ar' => (string) ($ln['name_ar'] ?? ''),
        'barcode' => (string) ($ln['barcode'] ?? ''),
        'sku' => (string) ($ln['sku'] ?? ''),
        'line_desc' => (string) ($ln['line_desc'] ?? ''),
        'qty' => (float) ($ln['qty'] ?? 0),
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
        'is_posted' => (bool) ($row['is_posted'] ?? false),
        'prev_id' => (int) ($row['prev_id'] ?? 0),
        'next_id' => (int) ($row['next_id'] ?? 0),
        'browse_count' => (int) ($row['browse_count'] ?? 0),
        'linked_invoice_id' => (int) ($row['linked_invoice_id'] ?? 0),
        'linked_invoice_no' => (string) ($row['linked_invoice_no'] ?? ''),
        'linked_invoice_is_posted' => !empty($row['linked_invoice_is_posted']),
    ],
    'lines' => $linesOut,
], JSON_UNESCAPED_UNICODE);
