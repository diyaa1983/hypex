<?php
declare(strict_types=1);

/**
 * Usage:
 * php tools/license_generate.php --fingerprint=<64hex> [--expires=YYYY-MM-DD]
 *                                [--customer="Company Name"] [--license-no="LIC-001"]
 *                                [--max-users=25]
 */

require dirname(__DIR__) . '/config/app.php';
require_once app_path('includes/license.php');

$opts = getopt('', ['fingerprint:', 'expires::', 'customer::', 'license-no::', 'max-users::']);
$fingerprint = strtolower(trim((string) ($opts['fingerprint'] ?? '')));
$expires = isset($opts['expires']) ? trim((string) $opts['expires']) : null;
$customer = trim((string) ($opts['customer'] ?? ''));
$licenseNo = trim((string) ($opts['license-no'] ?? ''));
$maxUsersRaw = isset($opts['max-users']) ? trim((string) $opts['max-users']) : '';
$maxUsers = null;
$secret = license_secret();

if (!preg_match('/^[a-f0-9]{64}$/', $fingerprint)) {
    fwrite(STDERR, "Error: --fingerprint must be a 64-char SHA256 hex.\n");
    exit(1);
}

if ($secret === '' || strlen($secret) < 16) {
    fwrite(STDERR, "Error: APP_LICENSE_SECRET is missing or too short in config/app.local.php\n");
    exit(1);
}

if ($maxUsersRaw !== '') {
    if (!preg_match('/^\d+$/', $maxUsersRaw)) {
        fwrite(STDERR, "Error: --max-users must be a positive integer.\n");
        exit(1);
    }
    $maxUsers = (int) $maxUsersRaw;
    if ($maxUsers <= 0) {
        fwrite(STDERR, "Error: --max-users must be greater than zero.\n");
        exit(1);
    }
}

try {
    $key = license_generate_key($fingerprint, $secret, $expires, $customer, $licenseNo, $maxUsers);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "Fingerprint: " . $fingerprint . PHP_EOL;
if ($customer !== '') {
    echo "Customer: " . $customer . PHP_EOL;
}
if ($licenseNo !== '') {
    echo "License No: " . $licenseNo . PHP_EOL;
}
if ($maxUsers !== null) {
    echo "Max Users: " . $maxUsers . PHP_EOL;
}
if ($expires !== null && $expires !== '') {
    echo "Expires: " . $expires . PHP_EOL;
} else {
    echo "Expires: no expiry" . PHP_EOL;
}
echo "License Key:" . PHP_EOL;
echo $key . PHP_EOL;
