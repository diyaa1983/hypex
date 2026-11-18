<?php
declare(strict_types=1);

/**
 * تشخيص تحصيلات المندوب 3 ومقارنة مع Forms (43660.696).
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
$manLike = array_values(array_filter($vodCols, static fn ($c) => (bool) preg_match('/MAN|SAL|EMP|REP/', $c)));
$out('GLVODMF man-like: ' . implode(', ', $manLike));
$out('CUSTOMER man-like: ' . implode(', ', array_values(array_filter($cusCols, static fn ($c) => (bool) preg_match('/MAN|SAL|REP|EMP|TITLE|NUM/', $c)))));

$rep = 3;
$formsTarget = 43660.696;
$out('');
$out("=== current fetch rep={$rep} (cfg) ===");
$res = oracle_fetch_rep_collections('2026-11-01', '2026-11-18', $rep, $rep, 1);
$out('ok=' . (!empty($res['ok']) ? '1' : '0') . ' msg=' . ($res['message'] ?? ''));
$out('count=' . (int) ($res['totals']['count'] ?? 0) . ' amount=' . (string) ($res['totals']['amount'] ?? 0));
$out('delta_vs_forms=' . round((float) ($res['totals']['amount'] ?? 0) - $formsTarget, 3));
$out('filters=' . json_encode($res['filters'] ?? [], JSON_UNESCAPED_UNICODE));
$out("FORMS target amount={$formsTarget}");

$cashN = 0;
$chqN = 0;
foreach ($res['rows'] ?? [] as $r) {
    if (($r['rcv_type'] ?? '') === 'قبض شيك') {
        $chqN++;
    } else {
        $cashN++;
    }
}
$out("rows cash={$cashN} cheque={$chqN}");

$out('');
$out('=== sample rows (first 20) ===');
foreach (array_slice($res['rows'] ?? [], 0, 20) as $r) {
    $out(json_encode($r, JSON_UNESCAPED_UNICODE));
}

$manCol = $manLike[0] ?? null;
if ($manCol !== null) {
    $out('');
    $out("=== FLAG totals credit Nov1-18 MAN={$rep} via {$manCol} ===");
    try {
        $rows = oracle_query_all(
            $conn,
            "SELECT v.VOD_FLAG, COUNT(*) CNT, ROUND(SUM(ABS(v.VOD_AMOUNT)),3) AMT,
                    MIN(v.VOD_NUM) MIN_NO, MAX(v.VOD_NUM) MAX_NO
             FROM ACCINV.GLVODMF v
             WHERE v.VOD_DATE >= TO_DATE('2026-11-01','YYYY-MM-DD')
               AND v.VOD_DATE < TO_DATE('2026-11-19','YYYY-MM-DD')
               AND v.VOD_SIDE = 2
               AND NVL(v.{$manCol},0) = :r
               AND v.VOD_NUM >= 10000
             GROUP BY v.VOD_FLAG
             ORDER BY AMT DESC",
            ['r' => $rep]
        );
        foreach ($rows as $r) {
            $out(json_encode($r, JSON_UNESCAPED_UNICODE));
        }
    } catch (Throwable $e) {
        $out('err man flags: ' . $e->getMessage());
    }
}

$out('done');
