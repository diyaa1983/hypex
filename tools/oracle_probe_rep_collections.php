<?php
declare(strict_types=1);

/**
 * تشخيص تحصيلات المندوب 1 ومقارنة مع Forms (44944.244).
 * تشغيل: C:\xampp\php\php.exe tools/oracle_probe_rep_collections.php
 */

$root = dirname(__DIR__);
require $root . '/includes/bootstrap.php';
require_once app_path('includes/oracle_pdo.php');
require_once app_path('includes/oracle_statement.php');
require_once app_path('includes/oracle_rep_collections.php');

header_remove();
$nl = PHP_EOL;
$out = static function (string $s) use ($nl): void {
    echo $s . $nl;
};

if (!oracle_is_enabled()) {
    $out('disabled: ' . oracle_config_status_message());
    exit(1);
}
$conn = oracle_connect();
if (empty($conn['ok'])) {
    $out('connect FAIL: ' . (string) ($conn['message'] ?? ''));
    exit(1);
}
$out('connect OK driver=' . (string) ($conn['driver'] ?? ''));

$vodCols = oracle_statement_table_columns($conn, 'ACCINV', 'GLVODMF');
$chqCols = oracle_statement_table_columns($conn, 'ACCINV', 'GLCHEQF');
$cusCols = oracle_statement_table_columns($conn, 'ACCINV', 'CUSTOMER');
$out('GLVODMF cols: ' . implode(', ', $vodCols));
$out('GLCHEQF cols: ' . implode(', ', $chqCols));
$out('CUSTOMER man-like: ' . implode(', ', array_values(array_filter($cusCols, static fn ($c) => (bool) preg_match('/MAN|SAL|REP|EMP|TITLE|NUM/', $c)))));

$out('');
$out('=== FLAG/TYPE totals credit side Nov 2026 (joined CUSTOMER, CUS_SALESMAN=1) ===');
try {
    $rows = oracle_query_all(
        $conn,
        "SELECT v.VOD_FLAG, v.VOD_TYPE, COUNT(*) CNT, ROUND(SUM(ABS(v.VOD_AMOUNT)),3) AMT
         FROM ACCINV.GLVODMF v
         INNER JOIN ACCINV.CUSTOMER c ON TRIM(TO_CHAR(c.CUS_NUM)) = TRIM(TO_CHAR(v.VOD_ACC))
         WHERE v.VOD_DATE >= TO_DATE('2026-11-01','YYYY-MM-DD')
           AND v.VOD_DATE < TO_DATE('2026-11-19','YYYY-MM-DD')
           AND v.VOD_SIDE = 2
           AND NVL(c.CUS_SALESMAN,0) = 1
         GROUP BY v.VOD_FLAG, v.VOD_TYPE
         ORDER BY AMT DESC"
    );
    foreach ($rows as $r) {
        $out(json_encode($r, JSON_UNESCAPED_UNICODE));
    }
} catch (Throwable $e) {
    $out('err1: ' . $e->getMessage());
}

$out('');
$out('=== current fetch rep=1 ===');
$res = oracle_fetch_rep_collections('2026-11-01', '2026-11-18', 1, 1, 1);
$out('ok=' . (!empty($res['ok']) ? '1' : '0') . ' msg=' . ($res['message'] ?? ''));
$out('count=' . (int) ($res['totals']['count'] ?? 0) . ' amount=' . (string) ($res['totals']['amount'] ?? 0));
$out('filters=' . json_encode($res['filters'] ?? [], JSON_UNESCAPED_UNICODE));
$out('FORMS target amount=44944.244');

$out('');
$out('=== sample rows (first 15) ===');
foreach (array_slice($res['rows'] ?? [], 0, 15) as $r) {
    $out(json_encode($r, JSON_UNESCAPED_UNICODE));
}

$out('');
$out('=== try MAN_NUM on voucher only (if col exists) ===');
$manCols = array_values(array_filter($vodCols, static fn ($c) => (bool) preg_match('/MAN|SAL|EMP|REP/', $c)));
$out('vod man cols: ' . implode(', ', $manCols));

foreach ([1, 2, 9, 10, 11, 15, 16] as $flag) {
    try {
        $rows = oracle_query_all(
            $conn,
            "SELECT COUNT(*) CNT, ROUND(SUM(ABS(v.VOD_AMOUNT)),3) AMT
             FROM ACCINV.GLVODMF v
             INNER JOIN ACCINV.CUSTOMER c ON TRIM(TO_CHAR(c.CUS_NUM)) = TRIM(TO_CHAR(v.VOD_ACC))
             WHERE v.VOD_DATE >= TO_DATE('2026-11-01','YYYY-MM-DD')
               AND v.VOD_DATE < TO_DATE('2026-11-19','YYYY-MM-DD')
               AND v.VOD_SIDE = 2
               AND v.VOD_FLAG = :f
               AND NVL(c.CUS_SALESMAN,0) = 1",
            ['f' => $flag]
        );
        $out("flag={$flag} " . json_encode($rows[0] ?? [], JSON_UNESCAPED_UNICODE));
    } catch (Throwable $e) {
        $out("flag={$flag} err " . $e->getMessage());
        break;
    }
}

$out('done');
