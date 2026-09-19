<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_once app_path('includes/nav_helpers.php');

$settingsRow = ['logo_path' => null];
try {
    $st = db()->query('SELECT logo_path FROM sys_company_settings WHERE id = 1 LIMIT 1');
    $row = $st->fetch();
    if ($row) {
        $settingsRow = $row;
    }
} catch (Throwable $e) {
}

$meta = app_favicon_meta($settingsRow);
header('Location: ' . $meta['href'], true, 302);
header('Cache-Control: public, max-age=86400');
exit;
