<?php
declare(strict_types=1);

require_once app_path('includes/nav_helpers.php');

/** تحميل CSS المشترك لشاشات المبيعات — Oracle 12c */
function sales_ora12_enqueue_assets(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    foreach (['dashboard.css', 'sales-oracle12.css'] as $file) {
        $path = app_path('assets/css/' . $file);
        $url = app_url('assets/css/' . $file);
        if (is_file($path)) {
            $url .= '?v=' . (string) filemtime($path);
        }
        echo '<link rel="stylesheet" href="' . esc($url) . '">' . "\n";
    }
}

/** جدول بنود الفاتورة/السند — sales-invoice.css ثم oracle12 (يُحمَّل أخيراً لنمط SQL) */
function sales_inv_oracle12_enqueue_assets(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    sales_ora12_enqueue_assets();

    foreach (['sales-invoice.css', 'sales-invoice-oracle12.css'] as $file) {
        $path = app_path('assets/css/' . $file);
        $url = app_url('assets/css/' . $file);
        if (is_file($path)) {
            $url .= '?v=' . (string) filemtime($path);
        }
        echo '<link rel="stylesheet" href="' . esc($url) . '">' . "\n";
    }
}

/** شريط عنوان أزرق Oracle 12c */
function sales_ora12_render_title_bar(string $title, string $meta = '', string $activeRoute = ''): void
{
    require_once app_path('includes/app_window_manager.php');
    require_once app_path('includes/sys_favorites.php');
    if ($activeRoute === '') {
        $activeRoute = (string) ($GLOBALS['activeRoute'] ?? ($_GET['r'] ?? ''));
    }
    $activeRoute = app_mdi_resolve_route($activeRoute);
    echo '<header class="dashboard-ora-screen-title" role="banner">';
    echo '<h1 class="dashboard-ora-screen-title__text">' . esc($title) . '</h1>';
    if ($meta !== '') {
        echo '<span class="dashboard-ora-screen-title__meta">' . esc($meta) . '</span>';
    }
    sys_favorites_render_toggle_button($activeRoute, [
        'class' => 'app-screen-fav-btn--on-blue',
        'icon_size' => 20,
    ]);
    nav_render_screen_close($activeRoute);
    echo '</header>';
}

function sales_ora12_workspace_open(): void
{
    echo '<div class="dashboard-ora-workspace">' . "\n";
}

function sales_ora12_workspace_close(): void
{
    echo '</div>' . "\n";
}
