<?php
declare(strict_types=1);

/**
 * تشخيص رصيد مادة في Oracle — يعرض صفوف STOCK الخام وأعمدة الكمية.
 * php tools/oracle_stock_probe.php 600029 4 1 1 6
 */
$root = dirname(__DIR__);
require $root . '/includes/bootstrap.php';
require_once app_path('includes/oracle_order_post.php');

$item = trim((string) ($argv[1] ?? '600029'));
$store = (int) ($argv[2] ?? 4);
$comp = (int) ($argv[3] ?? 1);
$need = (float) ($argv[4] ?? 1);
$cat = trim((string) ($argv[5] ?? ''));

$conn = oracle_connect();
if (empty($conn['ok'])) {
    echo json_encode(['ok' => false, 'message' => $conn['message'] ?? 'connect fail'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}

$cfg = oracle_order_stock_cfg();
$owner = (string) $cfg['owner'];
$table = (string) $cfg['table'];
$cols = [];
try {
    foreach (oracle_describe_table($conn, $owner, $table) as $m) {
        $cols[] = (string) ($m['column_name'] ?? '');
    }
} catch (Throwable $e) {
    $cols = ['error' => $e->getMessage()];
}

$batches = oracle_order_stock_batches($conn, $comp, $store, $item, $cat);
$check = oracle_order_check_stock($conn, $comp, $store, [
    [
        'item' => $item,
        'cat' => $cat,
        'batch' => '0',
        'qty' => $need,
        'bonus' => 0,
        'tr_unit' => '1',
        'name' => '',
    ],
]);

$rawSample = [];
try {
    $from = oracle_order_quoted($owner, $table);
    $rawSample = oracle_query_all(
        $conn,
        "SELECT * FROM {$from}
          WHERE STORE = :store AND TRIM(TO_CHAR(ITEM)) = TRIM(:item) AND ROWNUM <= 15",
        ['store' => $store, 'item' => $item]
    );
} catch (Throwable $e) {
    try {
        $from = oracle_order_quoted($owner, $table);
        $rawSample = oracle_query_all(
            $conn,
            "SELECT * FROM {$from}
              WHERE TRIM(TO_CHAR(STORE)) = TRIM(:store) AND TRIM(TO_CHAR(ITEM)) = TRIM(:item) AND ROWNUM <= 15",
            ['store' => (string) $store, 'item' => $item]
        );
    } catch (Throwable $e2) {
        $rawSample = ['error' => $e2->getMessage()];
    }
}

echo json_encode([
    'cfg' => $cfg,
    'stock_columns' => $cols,
    'item' => $item,
    'cat' => $cat,
    'store' => $store,
    'comp_num' => $comp,
    'batches' => $batches,
    'check' => $check,
    'raw_sample' => $rawSample,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
