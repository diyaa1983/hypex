<?php
/**
 * تشخيص سريع لاتصال Oracle والمزامنة — احذف الملف بعد الاستخدام إن رغبت.
 * الاستخدام: php tools/oracle_sync_diagnose.php
 * أو من السيرفر: C:\xampp\php\php.exe C:\xampp\htdocs\system\tools\oracle_sync_diagnose.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/oracle_pdo.php');
require_once app_path('includes/oracle_sync_service.php');

header('Content-Type: text/plain; charset=utf-8');
$nl = PHP_EOL;

echo "=== Oracle diagnose ===" . $nl;
echo 'path: ' . app_path('config/oracle.local.php') . $nl;
echo 'enabled: ' . (oracle_is_enabled() ? 'yes' : 'no') . $nl;
echo 'drivers: ' . json_encode(oracle_php_drivers_status(), JSON_UNESCAPED_UNICODE) . $nl;
echo 'status: ' . oracle_config_status_message() . $nl;

$cfg = oracle_config();
echo 'host: ' . ($cfg['host'] ?? '') . $nl;
echo 'sid: ' . ($cfg['sid'] ?? '') . $nl;
echo 'user: ' . ($cfg['user'] ?? '') . $nl;
echo 'sync_token set: ' . (trim((string) ($cfg['sync_token'] ?? '')) !== '' ? 'yes' : 'NO') . $nl;
$c = is_array($cfg['customers'] ?? null) ? $cfg['customers'] : [];
echo 'customers: ' . ($c['owner'] ?? '') . '.' . ($c['table'] ?? '')
    . ' prefix=' . ($c['code_prefix'] ?? '(default 112)') . $nl;
echo 'columns: ' . json_encode($c['columns'] ?? [], JSON_UNESCAPED_UNICODE) . $nl;

$conn = oracle_connect();
echo 'connect: ' . ($conn['ok'] ? 'OK driver=' . ($conn['driver'] ?? '') : 'FAIL ' . ($conn['message'] ?? '')) . $nl;
if (!$conn['ok']) {
    exit(1);
}

$map = oracle_customers_saved_mapping();
if ($map === null) {
    echo "mapping: MISSING" . $nl;
} else {
    echo 'mapping: ' . $map['owner'] . '.' . $map['table'] . $nl;
    $pdo = db();
    $res = oracle_sync_customers_to_mysql($pdo, $conn, $map['owner'], $map['table'], $map['columns']);
    echo 'sync result: ' . json_encode($res, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . $nl;
}

echo "DONE" . $nl;
