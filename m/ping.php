<?php
declare(strict_types=1);

/**
 * فحص اتصال تطبيق الهاتف بالسيرفر — يُستدعى قبل حفظ عنوان النظام في التطبيق.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Accept');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

echo json_encode([
    'ok' => true,
    'app' => 'manager-mobile',
    'version' => 1,
], JSON_UNESCAPED_UNICODE);
