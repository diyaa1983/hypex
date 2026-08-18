<?php
declare(strict_types=1);

/**
 * خطوة 1: وصف MAS.DAILY وعينة فاتورة بيع (TYPE=9) لربط طلب الشراء.
 * الاستخدام: php tools/oracle_daily_describe.php
 */
$root = dirname(__DIR__);
require $root . '/includes/bootstrap.php';
require_once app_path('includes/oracle_pdo.php');
require_once app_path('includes/oracle_sales_invoice.php');

header_remove();
$nl = PHP_EOL;
$out = static function (string $s) use ($nl): void {
    echo $s . $nl;
};

$out('=== MAS.DAILY describe ===');
if (!oracle_is_enabled()) {
    $out('Oracle disabled: ' . oracle_config_status_message());
    exit(1);
}
$conn = oracle_connect();
if (empty($conn['ok'])) {
    $out('connect FAIL: ' . (string) ($conn['message'] ?? ''));
    exit(1);
}
$out('connect OK driver=' . (string) ($conn['driver'] ?? ''));

$sc = oracle_sales_invoice_cfg();
$owner = $sc['owner'];
$table = $sc['table'];
$stype = (int) $sc['sale_type'];
$out("table: {$owner}.{$table} TYPE={$stype}");

$cols = oracle_describe_table($conn, $owner, $table);
$out('columns (' . count($cols) . '):');
foreach ($cols as $c) {
    $out('  ' . $c['column_name'] . '  ' . $c['data_type']);
}

$hints = ['SALES', 'SELLER', 'EMP', 'CASH', 'CREDIT', 'PAY', 'FLAG', 'STAT', 'POST', 'ORDER', 'ORD', 'REQ', 'TAX', 'NOTE', 'REMARK', 'COMM'];
$out('--- likely mapping columns ---');
foreach ($cols as $c) {
    $n = strtoupper($c['column_name']);
    foreach ($hints as $h) {
        if (str_contains($n, $h)) {
            $out('  * ' . $c['column_name'] . ' (' . $c['data_type'] . ')');
            break;
        }
    }
}

$from = '"' . str_replace('"', '""', $owner) . '"."' . str_replace('"', '""', $table) . '"';
$sql = "SELECT * FROM (
          SELECT * FROM {$from} WHERE TYPE = :stype ORDER BY VYEAR DESC, V_NUM DESC
        ) WHERE ROWNUM <= 2";
try {
    $rows = oracle_query_all($conn, $sql, ['stype' => $stype]);
} catch (Throwable $e) {
    $out('sample FAIL: ' . $e->getMessage());
    exit(1);
}
$out('--- sample TYPE=' . $stype . ' rows: ' . count($rows) . ' ---');
foreach ($rows as $i => $r) {
    $out('ROW ' . ($i + 1));
    foreach ($r as $k => $v) {
        if (is_object($v) && method_exists($v, 'load')) {
            $v = $v->load();
        }
        if (is_object($v)) {
            $v = (string) $v;
        }
        $sv = is_string($v) ? trim($v) : $v;
        if ($sv === null || $sv === '') {
            continue;
        }
        $out('  ' . $k . ' = ' . (is_scalar($sv) ? (string) $sv : json_encode($sv, JSON_UNESCAPED_UNICODE)));
    }
}

$out('DONE');
