<?php
declare(strict_types=1);

require_once app_path('includes/nav_helpers.php');

/** رابط CSS المشترك لعناصر Oracle Forms في شاشات الموارد البشرية. */
function hr_ora_ui_link_tags(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $path = app_path('assets/css/hr-oracle-forms.css');
    $url = app_url('assets/css/hr-oracle-forms.css');
    if (is_file($path)) {
        $url .= '?v=' . (string) filemtime($path);
    }
    echo '<link rel="stylesheet" href="' . esc($url) . '">' . "\n";
}

/** شريط عنوان Oracle 12c مع زر إغلاق */
function hr_ora_render_title_bar(string $title, string $activeRoute = ''): void
{
    require_once app_path('includes/app_window_manager.php');
    $activeRoute = app_mdi_resolve_route($activeRoute);

    echo '<header class="dashboard-ora-screen-title ora12-title-bar" role="banner">';
    echo '<h1 class="dashboard-ora-screen-title__text">' . esc($title) . '</h1>';
    nav_render_screen_close($activeRoute);
    echo '</header>';
}
