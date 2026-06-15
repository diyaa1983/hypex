<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/mobile_pwa.php');

$settingsRow = ['company_name_ar' => 'النظام المحاسبي', 'logo_path' => null];
try {
    $st = db()->query('SELECT company_name_ar, logo_path FROM sys_company_settings WHERE id = 1 LIMIT 1');
    $row = $st->fetch();
    if ($row) {
        $settingsRow = $row;
    }
} catch (Throwable $e) {
    // ignore
}

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache');
echo json_encode(mobile_pwa_manifest($settingsRow), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
