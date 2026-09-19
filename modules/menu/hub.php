<?php
declare(strict_types=1);

require_once app_path('includes/company_settings.php');

$theme = 'basic';
try {
    $theme = app_ui_theme();
} catch (Throwable $e) {
    // ignore
}

require app_path($theme === 'classic' ? 'modules/menu/hub_classic.php' : 'modules/menu/hub_pro.php');
