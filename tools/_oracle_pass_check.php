<?php
declare(strict_types=1);

/**
 * تشخيص سريع لطول كلمة مرور Oracle (بدون طباعة السر).
 * تشغيل: C:\xampp\php\php.exe tools\_oracle_pass_check.php
 */
$path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'oracle.local.php';
if (!is_file($path)) {
    fwrite(STDERR, "missing: $path\n");
    exit(1);
}

$cfg = require $path;
if (!is_array($cfg)) {
    fwrite(STDERR, "oracle.local.php did not return array\n");
    exit(1);
}

$user = (string) ($cfg['user'] ?? '');
$pass = (string) ($cfg['pass'] ?? '');
$host = (string) ($cfg['host'] ?? '');
$sid = (string) ($cfg['sid'] ?? '');
$svc = (string) ($cfg['service_name'] ?? '');

echo "file_ok=1\n";
echo 'user_len=' . strlen($user) . "\n";
echo 'pass_len=' . strlen($pass) . "\n";
echo 'pass_bytes=' . strlen($pass) . "\n";
echo 'host=' . $host . "\n";
echo 'sid=' . $sid . "\n";
echo 'service_name=' . $svc . "\n";
echo 'enabled=' . (!empty($cfg['enabled']) ? '1' : '0') . "\n";
echo 'oci8_loaded=' . (extension_loaded('oci8') ? '1' : '0') . "\n";
echo 'pdo_oci_loaded=' . (extension_loaded('pdo_oci') ? '1' : '0') . "\n";

if (strlen($pass) > 200) {
    echo "WARN: pass_len unusually large — check quotes/encoding in oracle.local.php\n";
}

if (function_exists('oci_connect') && !empty($cfg['enabled'])) {
    $tns = !empty($svc)
        ? sprintf('(DESCRIPTION=(ADDRESS=(PROTOCOL=TCP)(HOST=%s)(PORT=%d))(CONNECT_DATA=(SERVICE_NAME=%s)))', $host, (int) ($cfg['port'] ?? 1521), $svc)
        : sprintf('(DESCRIPTION=(ADDRESS=(PROTOCOL=TCP)(HOST=%s)(PORT=%d))(CONNECT_DATA=(SID=%s)))', $host, (int) ($cfg['port'] ?? 1521), $sid);
    $charset = (string) ($cfg['charset'] ?? 'AL32UTF8');
    $conn = @oci_connect($user, $pass, $tns, $charset);
    if ($conn) {
        echo "connect=OK\n";
        oci_close($conn);
    } else {
        $err = function_exists('oci_error') ? (oci_error()['message'] ?? 'fail') : 'fail';
        echo "connect=FAIL\n";
        echo 'error=' . $err . "\n";
    }
} else {
    echo "connect=SKIP (oci8 not loaded or disabled)\n";
}
