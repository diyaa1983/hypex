<?php
declare(strict_types=1);

/**
 * اكتشاف الجدول الذي يحتوي التشغيلة — يمسح كل جداول Oracle (عمود BATCH).
 *
 * الاستخدام:
 *   php tools/oracle_batch_discover.php 0263278
 *   php tools/oracle_batch_discover.php 0263278 600029 6 4
 *
 * يطبع JSON + استعلامات Toad جاهزة للنسخ.
 */
$root = dirname(__DIR__);
require $root . '/includes/bootstrap.php';
require_once app_path('includes/oracle_order_post.php');
require_once app_path('includes/oracle_statement.php');

$batch = trim((string) ($argv[1] ?? '0263278'));
$item = trim((string) ($argv[2] ?? ''));
$cat = trim((string) ($argv[3] ?? ''));
$store = (int) ($argv[4] ?? 0);
$batchNorm = oracle_order_batch_norm_key($batch);
$batchDigits = preg_replace('/\D+/', '', $batch) ?? '';

$conn = oracle_connect();
if (empty($conn['ok'])) {
    fwrite(STDERR, json_encode(['ok' => false, 'message' => $conn['message'] ?? 'connect fail'], JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}

$cfg = oracle_config();
$sc = oracle_order_stock_cfg();
$owners = array_values(array_unique(array_filter([
    strtoupper(trim((string) ($sc['owner'] ?? 'MAS'))),
    strtoupper(trim((string) (($cfg['sales_invoice'] ?? [])['owner'] ?? 'MAS'))),
    'MAS',
    'ACCINV',
])));

/** @var list<string> $batchTables — owner.table:column */
$batchTables = [];
$batchColNames = ['BATCH', 'BATCH_NO', 'LOT', 'LOT_NO', 'OP_NO', 'RUN_NO'];
foreach ($owners as $ow) {
    try {
        $rows = oracle_query_all(
            $conn,
            'SELECT TABLE_NAME, COLUMN_NAME FROM ALL_TAB_COLUMNS
              WHERE OWNER = :ow AND UPPER(COLUMN_NAME) IN (\'BATCH\',\'BATCH_NO\',\'LOT\',\'LOT_NO\',\'OP_NO\',\'RUN_NO\')
              ORDER BY TABLE_NAME, COLUMN_ID',
            ['ow' => $ow]
        );
        foreach ($rows as $r) {
            $tn = strtoupper(trim(oracle_statement_row_val($r, 'TABLE_NAME')));
            $cn = strtoupper(trim(oracle_statement_row_val($r, 'COLUMN_NAME')));
            if ($tn !== '' && $cn !== '') {
                $batchTables[] = $ow . '.' . $tn . ':' . $cn;
            }
        }
    } catch (Throwable $e) {
        // owner غير متاح
    }
}
$batchTables = array_values(array_unique($batchTables));

/**
 * @return list<string>
 */
function oracle_discover_table_columns(array $conn, string $owner, string $table): array
{
    try {
        $rows = oracle_query_all(
            $conn,
            'SELECT COLUMN_NAME FROM ALL_TAB_COLUMNS
              WHERE OWNER = :ow AND TABLE_NAME = :tbl
              ORDER BY COLUMN_ID',
            ['ow' => $owner, 'tbl' => $table]
        );
    } catch (Throwable $e) {
        return [];
    }
    $cols = [];
    foreach ($rows as $r) {
        $c = strtoupper(trim(oracle_statement_row_val($r, 'COLUMN_NAME')));
        if ($c !== '') {
            $cols[] = $c;
        }
    }

    return $cols;
}

function oracle_discover_pick_col(array $cols, array $candidates): ?string
{
    foreach ($candidates as $c) {
        $u = strtoupper($c);
        if (in_array($u, $cols, true)) {
            return $u;
        }
    }

    return null;
}

/**
 * @return array<string,mixed>
 */
function oracle_discover_row_summary(array $row, array $cols): array
{
    $pick = static function (array $names) use ($row, $cols): string {
        foreach ($names as $n) {
            $v = oracle_statement_row_val($row, $n);
            if ($v !== '') {
                return $v;
            }
        }
        foreach ($cols as $c) {
            if (preg_match('/(QTY|QNT|BAL)/', $c) && !preg_match('/(COMP_NUM|ITEM_NUM|DATE|FLAG|CODE|NAME)/', $c)) {
                $v = oracle_statement_row_val($row, $c);
                if ($v !== '' && is_numeric(str_replace([',', ' '], ['', ''], $v))) {
                    return $c . '=' . $v;
                }
            }
        }

        return '';
    };

    return [
        'batch' => oracle_statement_row_val($row, 'BATCH'),
        'item' => $pick(['ITEM', 'ITEM_NO', 'ITEM_CODE']),
        'cat' => $pick(['CAT', 'CATE', 'CATEGORY']),
        'store' => $pick(['STORE', 'STO', 'STO_NO', 'WAREHOUSE']),
        'sys_qty' => oracle_statement_row_val($row, 'SYS_QTY'),
        'man_qty' => oracle_statement_row_val($row, 'MAN_QTY'),
        'exp_date' => $pick(['EXP_DATE', 'EXPIRE_DATE', 'EXPIRY']),
        'qty_hint' => $pick(['SYS_QTY', 'MAN_QTY', 'QTY', 'AV_QTY']),
    ];
}

/** @var list<array<string,mixed>> $hits */
$hits = [];
/** @var list<string> $toadSql */
$toadSql = [];
$toadSql[] = '-- ============================================================';
$toadSql[] = '-- اكتشاف تشغيلة ' . $batch . ' (norm=' . ($batchNorm !== '' ? $batchNorm : $batch) . ')';
$toadSql[] = '-- ============================================================';

$batchWhere = "(TRIM(TO_CHAR(BATCH)) = '" . str_replace("'", "''", $batch) . "'"
    . " OR LTRIM(TRIM(TO_CHAR(BATCH)), '0') = '" . str_replace("'", "''", $batchNorm !== '' ? $batchNorm : $batch) . "'"
    . " OR TRIM(TO_CHAR(BATCH)) LIKE '%" . str_replace("'", "''", $batchNorm !== '' ? $batchNorm : $batchDigits) . "%')";

/**
 * @return string SQL WHERE clause for a batch-like column name
 */
function oracle_discover_batch_where(string $col, string $batch, string $batchNorm, string $batchDigits): string
{
    $b = str_replace("'", "''", $batch);
    $bn = str_replace("'", "''", $batchNorm !== '' ? $batchNorm : $batch);
    $bd = str_replace("'", "''", $batchNorm !== '' ? $batchNorm : $batchDigits);

    return "(TRIM(TO_CHAR({$col})) = '{$b}'"
        . " OR LTRIM(TRIM(TO_CHAR({$col})), '0') = '{$bn}'"
        . " OR TRIM(TO_CHAR({$col})) LIKE '%{$bd}%')";
}

$scanned = 0;
$errors = 0;
foreach ($batchTables as $fullCol) {
    $parts = explode(':', $fullCol, 2);
    $full = $parts[0];
    $batchCol = $parts[1] ?? 'BATCH';
    [$owner, $table] = explode('.', $full, 2);
    $from = oracle_order_quoted($owner, $table);
    $cols = oracle_discover_table_columns($conn, $owner, $table);
    if ($cols === []) {
        continue;
    }
    $scanned++;

    $extra = '';
    if ($item !== '') {
        $itemCol = oracle_discover_pick_col($cols, ['ITEM', 'ITEM_NO', 'ITEM_CODE']);
        if ($itemCol !== null) {
            $extra .= " AND TRIM(TO_CHAR({$itemCol})) = '" . str_replace("'", "''", $item) . "'";
        }
    }
    if ($cat !== '') {
        $catCol = oracle_discover_pick_col($cols, ['CAT', 'CATE', 'CATEGORY']);
        if ($catCol !== null) {
            $extra .= " AND TRIM(TO_CHAR({$catCol})) = '" . str_replace("'", "''", $cat) . "'";
        }
    }
    if ($store > 0) {
        $storeCol = oracle_discover_pick_col($cols, ['STORE', 'STO', 'STO_NO', 'WAREHOUSE']);
        if ($storeCol !== null) {
            $extra .= ' AND ' . $storeCol . ' = ' . $store;
        }
    }

    $sql = 'SELECT * FROM ' . $from . ' WHERE ' . oracle_discover_batch_where($batchCol, $batch, $batchNorm, $batchDigits) . $extra . ' AND ROWNUM <= 10';
    $toadSql[] = '';
    $toadSql[] = '-- ' . $full . ' (' . $batchCol . ')';
    $toadSql[] = $sql . ';';

    try {
        $rows = oracle_query_all($conn, $sql, []);
        if (!is_array($rows) || $rows === []) {
            continue;
        }
        $summaries = [];
        foreach (array_slice($rows, 0, 5) as $r) {
            if (is_array($r)) {
                $summaries[] = oracle_discover_row_summary($r, $cols);
            }
        }
        $hits[] = [
            'table' => $full,
            'batch_column' => $batchCol,
            'row_count' => count($rows),
            'columns' => $cols,
            'sample' => $summaries,
            'toad_sql' => $sql,
        ];
    } catch (Throwable $e) {
        $errors++;
        $hits[] = [
            'table' => $full,
            'error' => $e->getMessage(),
            'toad_sql' => $sql,
        ];
    }
}

// Views تحتوي BATCH
/** @var list<string> $batchViews */
$batchViews = [];
foreach ($owners as $ow) {
    try {
        $vrows = oracle_query_all(
            $conn,
            'SELECT VIEW_NAME FROM ALL_VIEWS WHERE OWNER = :ow ORDER BY VIEW_NAME',
            ['ow' => $ow]
        );
        foreach ($vrows as $vr) {
            $vn = strtoupper(trim(oracle_statement_row_val($vr, 'VIEW_NAME')));
            if ($vn === '') {
                continue;
            }
            $vcols = oracle_discover_table_columns($conn, $ow, $vn);
            if (in_array('BATCH', $vcols, true)) {
                $batchViews[] = $ow . '.' . $vn;
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
}

foreach (array_slice($batchViews, 0, 30) as $full) {
    [$owner, $view] = explode('.', $full, 2);
    $from = oracle_order_quoted($owner, $view);
    $sql = 'SELECT * FROM ' . $from . ' WHERE ' . $batchWhere . ' AND ROWNUM <= 5';
    $toadSql[] = '';
    $toadSql[] = '-- VIEW ' . $full;
    $toadSql[] = $sql . ';';
    try {
        $rows = oracle_query_all($conn, $sql, []);
        if (is_array($rows) && $rows !== []) {
            $summaries = [];
            foreach ($rows as $r) {
                if (is_array($r)) {
                    $summaries[] = oracle_discover_row_summary($r, oracle_discover_table_columns($conn, $owner, $view));
                }
            }
            $hits[] = [
                'table' => $full . ' (VIEW)',
                'row_count' => count($rows),
                'sample' => array_slice($summaries, 0, 5),
                'toad_sql' => $sql,
            ];
        }
    } catch (Throwable $e) {
        // view may not be directly queryable
    }
}

$stockPositive = null;
if ($item !== '' && $store > 0) {
    if ($cat === '') {
        $cat = oracle_order_item_cat_resolve($conn, $item, '');
    }
    $stockPositive = oracle_order_stock_toad_positive_rows(
        $conn,
        $store,
        $item,
        $cat,
        (string) $sc['owner'],
        (string) $sc['table']
    );
}

$sqlFile = $root . '/tools/oracle_batch_discover_' . preg_replace('/\W+/', '', $batch) . '.sql';
file_put_contents($sqlFile, implode(PHP_EOL, $toadSql) . PHP_EOL);

echo json_encode([
    'ok' => true,
    'batch' => $batch,
    'batch_norm' => $batchNorm,
    'item' => $item,
    'cat' => $cat,
    'store' => $store,
    'owners_scanned' => $owners,
    'tables_with_batch_column' => count($batchTables),
    'tables_scanned' => $scanned,
    'scan_errors' => $errors,
    'hits' => $hits,
    'hit_count' => count(array_filter($hits, static fn($h) => isset($h['row_count']) && (int) $h['row_count'] > 0)),
    'views_with_batch' => array_slice($batchViews, 0, 40),
    'stock_positive_for_item' => $stockPositive,
    'toad_sql_file' => $sqlFile,
    'toad_quick' => [
        'list_batch_tables' => "SELECT OWNER, TABLE_NAME FROM ALL_TAB_COLUMNS WHERE UPPER(COLUMN_NAME)='BATCH' AND OWNER IN ('MAS','ACCINV') ORDER BY OWNER, TABLE_NAME;",
        'search_stock' => "SELECT BATCH, SYS_QTY, MAN_QTY, EXP_DATE, CAT, ITEM, STORE FROM MAS.STOCK WHERE " . $batchWhere . ($item !== '' ? " AND TRIM(TO_CHAR(ITEM))='" . $item . "'" : '') . ($cat !== '' ? " AND CAT=" . $cat : '') . ($store > 0 ? ' AND STORE=' . $store : '') . ';',
        'positive_stock' => "SELECT BATCH, SYS_QTY, MAN_QTY FROM MAS.STOCK WHERE CAT=6 AND ITEM=600029 AND STORE=4 AND (NVL(SYS_QTY,0)>0 OR NVL(MAN_QTY,0)>0);",
    ],
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
