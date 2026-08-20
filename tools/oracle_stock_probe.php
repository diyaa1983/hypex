<?php
declare(strict_types=1);

/**
 * اختبار رصيد مادة في MAS.STOCK — نفس منطق الترحيل.
 * الاستخدام: php tools/oracle_stock_probe.php 600009 4 1
 */
$root = dirname(__DIR__);
require $root . '/includes/bootstrap.php';
require_once app_path('includes/oracle_order_post.php');

$item = trim((string) ($argv[1] ?? ''));
$store = (int) ($argv[2] ?? 4);
$comp = (int) ($argv[3] ?? 1);
$need = (float) ($argv[4] ?? 1);

if ($item === '') {
    fwrite(STDERR, "usage: php oracle_stock_probe.php <ITEM> [STORE] [COMP_NUM] [NEED_QTY]\n");
    exit(1);
}

$conn = oracle_connect();
if (empty($conn['ok'])) {
    echo json_encode(['ok' => false, 'message' => $conn['message'] ?? 'connect fail'], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}

$batches = oracle_order_stock_batches($conn, $comp, $store, $item);
$check = oracle_order_check_stock($conn, $comp, $store, [
    [
        'item' => $item,
        'batch' => '0',
        'qty' => $need,
        'bonus' => 0,
        'tr_unit' => '1',
        'name' => '',
    ],
]);

echo json_encode([
    'cfg' => oracle_order_stock_cfg(),
    'item' => $item,
    'store' => $store,
    'comp_num' => $comp,
    'need' => $need,
    'batches' => $batches,
    'check' => $check,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
