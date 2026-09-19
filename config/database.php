<?php
declare(strict_types=1);

/**
 * إعدادات الاتصال بـ MySQL.
 * إذا كان MySQL يرفض root بدون كلمة مرور (خطأ 1045)، أنشئ الملف:
 *   config/database.local.php
 * انسخ من database.local.example.php وضع كلمة مرور root الصحيحة.
 */
$base = [
    'host' => '127.0.0.1',
    'name' => 'hypex',
    'user' => 'root',
    'pass' => 'root',
    'charset' => 'utf8mb4',
];

$localFile = __DIR__ . '/database.local.php';
if (is_file($localFile)) {
    $local = require $localFile;
    if (is_array($local)) {
        $base = array_replace($base, $local);
    }
}

return $base;
