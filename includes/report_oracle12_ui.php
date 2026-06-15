<?php
declare(strict_types=1);

/** شاشات البيانات الأساسية والإعدادات (غلاف Oracle عبر layout). */
function report_ora12_master_routes(): array
{
    return [
        'items',
        'item_categories',
        'item_units',
        'warehouses',
        'inv_movement_types_settings',
        'chart_of_accounts',
        'account_mapping',
        'inventory_align_warehouse',
        'users',
        'groups',
        'permissions',
        'settings',
        'system_backup',
        'system_license',
        'tax_rates_settings',
        'einvoice_settings',
    ];
}

/** هل المسار يُعرض بنمط Oracle 12c عبر غلاف layout؟ (تقارير + بيانات أساسية) */
function report_ora12_route_enabled(string $route): bool
{
    if ($route === '' || $route === 'dashboard' || $route === 'menu_hub') {
        return false;
    }
    if (str_starts_with($route, 'report_')) {
        return true;
    }
    if (in_array($route, ['hr_payroll_ss_report', 'item_stock_movements'], true)) {
        return true;
    }

    return in_array($route, report_ora12_master_routes(), true);
}

function report_ora12_enqueue_assets(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    foreach (['dashboard.css', 'report-oracle12.css'] as $file) {
        $path = app_path('assets/css/' . $file);
        $url = app_url('assets/css/' . $file);
        if (is_file($path)) {
            $url .= '?v=' . (string) filemtime($path);
        }
        echo '<link rel="stylesheet" href="' . esc($url) . '">' . "\n";
    }
}

function report_ora12_render_fav_button(string $activeRoute): void
{
    if ($activeRoute === '' || $activeRoute === 'dashboard' || $activeRoute === 'favorites_empty' || !is_logged_in()) {
        return;
    }
    require_once app_path('includes/sys_favorites.php');
    try {
        $userId = (int) (current_user()['id'] ?? 0);
        $isFav = $userId > 0 && sys_favorites_is_favorite(db(), $userId, $activeRoute);
    } catch (Throwable $e) {
        $isFav = false;
    }
    $favTitle = $isFav ? 'إزالة من المفضلة' : 'إضافة إلى المفضلة';
    echo '<button type="button" class="app-screen-fav-btn report-ora12-fav-btn no-print' . ($isFav ? ' is-active' : '') . '"';
    echo ' data-favorite-toggle';
    echo ' data-screen-code="' . esc($activeRoute) . '"';
    echo ' data-csrf="' . esc(csrf_token()) . '"';
    echo ' data-api-url="' . esc(app_url('api/favorite_toggle.php')) . '"';
    echo ' aria-pressed="' . ($isFav ? 'true' : 'false') . '"';
    echo ' aria-label="' . esc($favTitle) . '" title="' . esc($favTitle) . '">';
    echo '<svg class="app-screen-fav-icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">';
    echo '<path d="M12 17.27 18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"';
    echo ' fill="currentColor" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/>';
    echo '</svg>';
    echo '</button>';
}

function report_ora12_render_title_bar(string $title, string $activeRoute): void
{
    $title = trim($title);
    if ($title === '') {
        return;
    }
    echo '<div class="dashboard-ora report-ora12-screen" data-exit-guard="off">';
    echo '<header class="dashboard-ora-screen-title report-ora12-screen-title" role="banner">';
    echo '<h1 class="dashboard-ora-screen-title__text">' . esc($title) . '</h1>';
    echo '<span class="dashboard-ora-screen-title__meta report-ora12-screen-title__actions">';
    report_ora12_render_fav_button($activeRoute);
    echo '</span>';
    nav_render_screen_close($activeRoute);
    echo '</header>';
    echo '<div class="dashboard-ora-workspace report-ora12-workspace">';
}

function report_ora12_layout_open(string $pageTitle, string $routeTitle, string $activeRoute): void
{
    if (!report_ora12_route_enabled($activeRoute)) {
        render_app_screen_title($pageTitle, $activeRoute);
        return;
    }
    $barTitle = trim($pageTitle) !== '' ? trim($pageTitle) : trim($routeTitle);
    report_ora12_render_title_bar($barTitle, $activeRoute);
}

function report_ora12_layout_close(string $activeRoute): void
{
    if (!report_ora12_route_enabled($activeRoute)) {
        return;
    }
    echo '</div></div>';
}
