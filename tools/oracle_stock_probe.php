<?php
declare(strict_types=1);

/**
 * تشخيص رصيد مادة في Oracle — يعرض صفوف STOCK الخام وأعمدة الكمية.
 * php tools/oracle_stock_probe.php 600029 4 1 1 6 0263278
 */
$root = dirname(__DIR__);
require $root . '/includes/bootstrap.php';
require_once app_path('includes/oracle_order_post.php');

$item = trim((string) ($argv[1] ?? '600029'));
$store = (int) ($argv[2] ?? 4);
$comp = (int) ($argv[3] ?? 1);
$need = (float) ($argv[4] ?? 1);
$cat = trim((string) ($argv[5] ?? ''));
$batchProbe = trim((string) ($argv[6] ?? '0263278'));

if ($cat === '') {
    $connTmp = oracle_connect();
    if (!empty($connTmp['ok'])) {
        $cat = oracle_order_item_cat_resolve($connTmp, $item, '');
    }
}

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

$batchNorm = oracle_order_batch_norm_key($batchProbe);
$toadHint = 'SELECT BATCH, SYS_QTY, MAN_QTY, EXP_DATE, CAT, ITEM, STORE, COMP_NUM FROM MAS.STOCK'
    . ' WHERE CAT = ' . ($cat !== '' ? $cat : '?')
    . " AND TRIM(TO_CHAR(ITEM)) = '" . $item . "'"
    . ' AND STORE = ' . $store
    . ' ORDER BY EXP_DATE NULLS LAST, BATCH';

$batchSql = 'SELECT BATCH, SYS_QTY, MAN_QTY, EXP_DATE, CAT, ITEM, STORE, COMP_NUM FROM MAS.STOCK'
    . " WHERE STORE = {$store}"
    . " AND LTRIM(TRIM(TO_CHAR(BATCH)), '0') = '" . ($batchNorm !== '' ? $batchNorm : $batchProbe) . "'"
    . ' AND ROWNUM <= 20';

$rawSample = [];
$batchRows = [];
try {
    $from = oracle_order_quoted($owner, $table);
    $rawSample = oracle_query_all(
        $conn,
        "SELECT BATCH, SYS_QTY, MAN_QTY, EXP_DATE, CAT, ITEM, STORE, COMP_NUM
           FROM {$from}
          WHERE STORE = :store
            AND TRIM(TO_CHAR(ITEM)) = TRIM(:item)
            AND (TRIM(TO_CHAR(CAT)) = TRIM(:cat) OR :cat = ' ')
            AND ROWNUM <= 30",
        ['store' => $store, 'item' => $item, 'cat' => $cat !== '' ? $cat : ' ']
    );
    $batchRows = oracle_query_all(
        $conn,
        "SELECT BATCH, SYS_QTY, MAN_QTY, EXP_DATE, CAT, ITEM, STORE, COMP_NUM
           FROM {$from}
          WHERE STORE = :store
            AND LTRIM(TRIM(TO_CHAR(BATCH)), '0') = :bnorm
            AND ROWNUM <= 20",
        ['store' => $store, 'bnorm' => $batchNorm !== '' ? $batchNorm : $batchProbe]
    );
} catch (Throwable $e) {
    $rawSample = ['error' => $e->getMessage(), 'hint_sql' => $toadHint];
}

echo json_encode([
    'cfg' => $cfg,
    'stock_columns' => $cols,
    'item' => $item,
    'cat' => $cat,
    'store' => $store,
    'comp_num' => $comp,
    'batch_probe' => $batchProbe,
    'batch_norm' => $batchNorm,
    'toad_sql' => $toadHint,
    'batch_sql' => $batchSql,
    'batch_rows' => $batchRows,
    'batches' => $batches,
    'check' => $check,
    'raw_sample' => $rawSample,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
