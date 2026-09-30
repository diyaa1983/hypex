<?php
declare(strict_types=1);

/**
 * تشخيص ربط رقم المندوب بالاسم: EMP_INFO مقابل ACCINV.REP
 * php tools/oracle_probe_rep_names.php [man_num]
 */
$root = dirname(__DIR__);
require $root . '/includes/bootstrap.php';
require_once app_path('includes/oracle_pdo.php');

$manNum = (int) ($argv[1] ?? 39);
$nl = PHP_EOL;
$out = static function (string $s) use ($nl): void {
    echo $s . $nl;
};

if (!oracle_is_enabled()) {
    $out('Oracle disabled');
    exit(1);
}
$conn = oracle_connect();
if (empty($conn['ok'])) {
    $out('connect FAIL: ' . (string) ($conn['message'] ?? ''));
    exit(1);
}
$out('connect OK');

$describe = static function (array $conn, string $owner, string $table) use ($out): void {
    try {
        $rows = oracle_query_all(
            $conn,
            "SELECT COLUMN_NAME, DATA_TYPE FROM ALL_TAB_COLUMNS
             WHERE OWNER = :own AND TABLE_NAME = :tbl
             ORDER BY COLUMN_ID",
            ['own' => strtoupper($owner), 'tbl' => strtoupper($table)]
        );
        $out("--- {$owner}.{$table} columns (" . count($rows) . ') ---');
        foreach ($rows as $r) {
            $out('  ' . oracle_statement_row_val($r, 'COLUMN_NAME') . '  ' . oracle_statement_row_val($r, 'DATA_TYPE'));
        }
    } catch (Throwable $e) {
        $out("--- {$owner}.{$table} FAIL: " . $e->getMessage());
    }
};

$describe($conn, 'ACCINV', 'REP');
$describe($conn, 'ACCINV', 'EMP_INFO');

$trySelect = static function (array $conn, string $label, string $sql, array $binds = []) use ($out): void {
    $out("--- {$label} ---");
    try {
        $rows = oracle_query_all($conn, $sql, $binds);
        $out('rows=' . count($rows));
        foreach (array_slice($rows, 0, 15) as $r) {
            $parts = [];
            foreach ($r as $k => $v) {
                if (is_object($v) && method_exists($v, 'load')) {
                    $v = $v->load();
                }
                $parts[] = $k . '=' . (is_scalar($v) || $v === null ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE));
            }
            $out('  ' . implode(' | ', $parts));
        }
    } catch (Throwable $e) {
        $out('FAIL: ' . $e->getMessage());
    }
};

$trySelect(
    $conn,
    "EMP_INFO EMP_NO={$manNum}",
    'SELECT EMP_NO, EMP_NAME, JOB_TYPE FROM ACCINV.EMP_INFO WHERE EMP_NO = :n',
    ['n' => $manNum]
);

$trySelect(
    $conn,
    "REP sample / filter {$manNum}",
    'SELECT * FROM ACCINV.REP WHERE ROWNUM <= 40'
);

// محاولات أعمدة شائعة لرقم/اسم المندوب في REP
$candidates = [
    'SELECT REP_NO, REP_NAME FROM ACCINV.REP WHERE REP_NO = :n',
    'SELECT REP_NUM, REP_NAME FROM ACCINV.REP WHERE REP_NUM = :n',
    'SELECT MAN_NUM, MAN_NAME FROM ACCINV.REP WHERE MAN_NUM = :n',
    'SELECT EMP_NO, EMP_NAME FROM ACCINV.REP WHERE EMP_NO = :n',
    'SELECT CODE, NAME FROM ACCINV.REP WHERE CODE = :n',
    'SELECT SALESMAN, SALESMAN_NAME FROM ACCINV.REP WHERE SALESMAN = :n',
    'SELECT REP_CODE, REP_DESC FROM ACCINV.REP WHERE REP_CODE = :n',
    'SELECT NUM, NAME FROM ACCINV.REP WHERE NUM = :n',
];
foreach ($candidates as $sql) {
    $trySelect($conn, $sql, $sql, ['n' => $manNum]);
}

$trySelect(
    $conn,
    "DAILY MAN_NUM={$manNum} Sep2026 distinct",
    "SELECT MAN_NUM, COUNT(*) CNT FROM MAS.DAILY
     WHERE COMP_NUM=1 AND TYPE=9 AND STORE=4
       AND VDATE >= DATE '2026-09-01' AND VDATE < DATE '2026-10-01'
       AND MAN_NUM = :n
     GROUP BY MAN_NUM",
    ['n' => $manNum]
);
