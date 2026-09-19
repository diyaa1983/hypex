<?php
declare(strict_types=1);

/**
 * اتصال بقواعد admin / Galaxy لنسخ إعدادات الفوترة.
 * يمكن تجاوزها عبر config/einvoice.local.php
 */
$base = [
    'admin' => [
        'host' => '127.0.0.1',
        'name' => 'admin',
        'user' => 'root',
        'pass' => 'root',
        'charset' => 'utf8mb4',
        'prefix' => 'glx_',
    ],
    'galaxy' => [
        'host' => '127.0.0.1',
        'name' => 'galaxy',
        'user' => 'root',
        'pass' => 'root',
        'charset' => 'utf8mb4',
        'prefix' => 'glx_',
    ],
    'jofotara_api_url' => 'https://backend.jofotara.gov.jo/core/invoices/',
];

$localFile = __DIR__ . '/einvoice.local.php';
if (is_file($localFile)) {
    $local = require $localFile;
    if (is_array($local)) {
        $base = array_replace_recursive($base, $local);
    }
}

return $base;
