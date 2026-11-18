<?php
declare(strict_types=1);

/**
 * اكتشاف أعمدة تحصيلات المندوب (قراءة فقط) — GLVODMF / GLCHEQF / جداول مشابهة.
 * تشغيل: php tools/oracle_probe_rep_collections.php
 */

$root = dirname(__DIR__);
require $root . '/includes/bootstrap.php';
require_once app_path('includes/oracle_pdo.php');

header_remove();

if (!oracle_is_enabled()) {
    fwrite(STDERR, 'Oracle disabled: ' . oracle_config_status_message() . PHP_EOL);
    exit(1);
}
$conn = oracle_connect();
if (empty($conn['ok'])) {
    fwrite(STDERR, 'connect FAIL: ' . (string) ($conn['message'] ?? '') . PHP_EOL);
    exit(1);
}
echo 'connect OK driver=' . (string) ($conn['driver'] ?? '') . PHP_EOL;

$owners = ['ACCINV', 'MAS', 'GL'];
$tables = [
    'GLVODMF', 'GLVOHDR', 'GLVOUCH', 'GLCHEQF', 'GLRECV', 'RECV', 'RECEIPT',
    'CASH_REC', 'COLLECT', 'COLLECTION', 'MANCOLL', 'REPCOLL', 'GLMAN',
];

function out(string $s): void
{
    echo $s . "\n";
}

out('=== tables present ===');
foreach ($owners as $ow) {
    foreach ($tables as $tb) {
        try {
            $cols = oracle_describe_table($conn, $ow, $tb);
            if ($cols) {
                $names = array_map(static fn ($c) => strtoupper((string) ($c['column_name'] ?? '')), $cols);
                $hit = array_values(array_filter(
                    $names,
                    static fn ($n) => (bool) preg_match('/MAN|SAL|REP|CHQ|VOD|CUS|DATE|AMT|AMOUNT|FLAG|TYPE|NUM|STATUS|DUE|RIGHT|COMP/', $n)
                ));
                out("{$ow}.{$tb} cols=" . count($names));
                out('  relevant: ' . implode(', ', $hit));
            }
        } catch (Throwable $e) {
            // ignore missing
        }
    }
}

out('');
out('=== ALL_TAB_COLUMNS search MAN/SAL on GL% ===');
try {
    $rows = oracle_query_all(
        $conn,
        "SELECT OWNER, TABLE_NAME, COLUMN_NAME
         FROM ALL_TAB_COLUMNS
         WHERE OWNER IN ('ACCINV','MAS','GL')
           AND (
             UPPER(COLUMN_NAME) LIKE '%MAN%'
             OR UPPER(COLUMN_NAME) LIKE '%SAL%'
             OR UPPER(COLUMN_NAME) LIKE '%REP%'
           )
           AND (
             UPPER(TABLE_NAME) LIKE 'GL%'
             OR UPPER(TABLE_NAME) LIKE '%CHQ%'
             OR UPPER(TABLE_NAME) LIKE '%RECV%'
             OR UPPER(TABLE_NAME) LIKE '%COLL%'
             OR UPPER(TABLE_NAME) LIKE '%CASH%'
           )
         ORDER BY OWNER, TABLE_NAME, COLUMN_NAME"
    );
    foreach ($rows as $r) {
        out(($r['OWNER'] ?? '') . '.' . ($r['TABLE_NAME'] ?? '') . '.' . ($r['COLUMN_NAME'] ?? ''));
    }
    out('count=' . count($rows));
} catch (Throwable $e) {
    out('search err: ' . $e->getMessage());
}

out('');
out('=== sample GLVODMF flags/types Nov 2026 ===');
try {
    $rows = oracle_query_all(
        $conn,
        "SELECT VOD_FLAG, VOD_TYPE, COUNT(*) CNT, SUM(VOD_AMOUNT) AMT
         FROM ACCINV.GLVODMF
         WHERE VOD_DATE >= TO_DATE('2026-11-01','YYYY-MM-DD')
           AND VOD_DATE < TO_DATE('2026-11-19','YYYY-MM-DD')
         GROUP BY VOD_FLAG, VOD_TYPE
         ORDER BY CNT DESC"
    );
    foreach ($rows as $r) {
        out(json_encode($r, JSON_UNESCAPED_UNICODE));
    }
} catch (Throwable $e) {
    out('flag sample err: ' . $e->getMessage());
}

out('');
out('=== GLVODMF columns with MAN/SAL ===');
try {
    $cols = oracle_describe_table($conn, 'ACCINV', 'GLVODMF');
    foreach ($cols as $c) {
        $n = strtoupper((string) ($c['column_name'] ?? ''));
        if (preg_match('/MAN|SAL|REP|EMP|COLLECT|CHQ/', $n)) {
            out($n);
        }
    }
    out('total cols ' . count($cols));
    out('ALL: ' . implode(', ', array_map(static fn ($c) => strtoupper((string) ($c['column_name'] ?? '')), $cols)));
} catch (Throwable $e) {
    out($e->getMessage());
}

out('');
out('=== GLCHEQF columns ===');
try {
    $cols = oracle_describe_table($conn, 'ACCINV', 'GLCHEQF');
    out('ALL: ' . implode(', ', array_map(static fn ($c) => strtoupper((string) ($c['column_name'] ?? '')), $cols)));
} catch (Throwable $e) {
    out($e->getMessage());
}

out('done');
