<?php
declare(strict_types=1);

/**
 * أين تُخزَّن التشغيلة في Oracle؟ — يبحث في الجداول الشائعة.
 * php tools/oracle_batch_find.php 0263278 600029 6 4
 */
$root = dirname(__DIR__);
require $root . '/includes/bootstrap.php';
require_once app_path('includes/oracle_order_post.php');

$batch = trim((string) ($argv[1] ?? '0263278'));
$item = trim((string) ($argv[2] ?? '600029'));
$cat = trim((string) ($argv[3] ?? '6'));
$store = (int) ($argv[4] ?? 4);
$batchNorm = oracle_order_batch_norm_key($batch);

$conn = oracle_connect();
if (empty($conn['ok'])) {
    echo json_encode(['ok' => false, 'message' => $conn['message'] ?? 'connect fail'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}

$sc = oracle_order_stock_cfg();
$owner = (string) $sc['owner'];
$results = [];

$tables = [
    'BALANCE', 'STOCK', 'DAILY', 'MASTER_D', 'MASCARD', 'PRODD', 'PRODUCTION', 'TRACE',
    'BATCH', 'STOCK_D', 'STK', 'ITEM_STOCK', 'STOCK_BAL',
];

foreach ($tables as $tbl) {
    $from = oracle_order_quoted($owner, $tbl);
    $queries = [
        "SELECT * FROM {$from} WHERE TRIM(TO_CHAR(BATCH)) = :b AND ROWNUM <= 5" => ['b' => $batch],
        "SELECT * FROM {$from} WHERE LTRIM(TRIM(TO_CHAR(BATCH)), '0') = :bn AND ROWNUM <= 5" => ['bn' => $batchNorm !== '' ? $batchNorm : $batch],
        "SELECT * FROM {$from} WHERE TRIM(TO_CHAR(BATCH)) LIKE :like AND ROWNUM <= 5" => ['like' => '%' . ($batchNorm !== '' ? $batchNorm : $batch) . '%'],
    ];
    foreach ($queries as $sql => $binds) {
        try {
            $rows = oracle_query_all($conn, $sql, $binds);
            if (is_array($rows) && $rows !== []) {
                $results[] = [
                    'table' => $owner . '.' . $tbl,
                    'sql' => $sql,
                    'binds' => $binds,
                    'rows' => array_slice($rows, 0, 3),
                ];
                break;
            }
        } catch (Throwable $e) {
            // جدول غير موجود
        }
    }
}

// جداول فيها عمود BATCH
$batchTables = [];
try {
    $meta = oracle_query_all(
        $conn,
        "SELECT TABLE_NAME FROM ALL_TAB_COLUMNS
          WHERE OWNER = :ow AND UPPER(COLUMN_NAME) = 'BATCH'
          ORDER BY TABLE_NAME",
        ['ow' => $owner]
    );
    foreach ($meta as $m) {
        $tn = trim(oracle_statement_row_val($m, 'TABLE_NAME'));
        if ($tn !== '') {
            $batchTables[] = $tn;
        }
    }
} catch (Throwable $e) {
    $batchTables = ['error' => $e->getMessage()];
}

$stockPositive = oracle_order_stock_toad_positive_rows($conn, $store, $item, $cat, $owner, (string) $sc['table']);

echo json_encode([
    'batch' => $batch,
    'batch_norm' => $batchNorm,
    'item' => $item,
    'cat' => $cat,
    'store' => $store,
    'hits' => $results,
    'tables_with_batch_column' => array_slice($batchTables, 0, 40),
    'stock_positive_toad' => $stockPositive,
    'forms_note' => 'شاشة Forms «التشغيلات المتوفرة» = MAS.BALANCE.QTY_OH · STOCK للرصيد المحاسبي فقط.',
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
