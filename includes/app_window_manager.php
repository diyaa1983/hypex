<?php
declare(strict_types=1);

function app_mdi_is_embed_request(): bool
{
    return isset($_GET['embed']) && (string) $_GET['embed'] === '1';
}

/** iframe القائمة عند التصغير — نفس layout مع الشريط الجانبي */
function app_mdi_is_park_menu_embed(): bool
{
    return isset($_GET['embed']) && (string) $_GET['embed'] === 'menu';
}

/** رابط داخل iframe القائمة — بدون شريط مهام مكرر */
function app_mdi_embed_url(string $url): string
{
    if ($url === '' || str_contains($url, 'embed=1')) {
        return $url;
    }
    $parts = parse_url($url);
    if ($parts === false) {
        return $url;
    }
    parse_str((string) ($parts['query'] ?? ''), $query);
    $query['embed'] = '1';
    unset($query['mdi_id']);
    $path = (string) ($parts['path'] ?? '');
    $built = $path . '?' . http_build_query($query);
    if (!empty($parts['fragment'])) {
        $built .= '#' . (string) $parts['fragment'];
    }

    return $built;
}

/** رابط القائمة داخل iframe التصغير — مع الشريط الجانبي */
function app_mdi_park_menu_url(string $url): string
{
    if ($url === '' || str_contains($url, 'embed=menu')) {
        return $url;
    }
    $parts = parse_url($url);
    if ($parts === false) {
        return $url;
    }
    parse_str((string) ($parts['query'] ?? ''), $query);
    $query['embed'] = 'menu';
    unset($query['mdi_id']);
    $path = (string) ($parts['path'] ?? '');
    $built = $path . '?' . http_build_query($query);
    if (!empty($parts['fragment'])) {
        $built .= '#' . (string) $parts['fragment'];
    }

    return $built;
}

/** في iframe القائمة أو التصغير: روابط المجلدات تبقى داخل الإطار، روابط الشاشات تفتح الصفحة الأم */
function app_mdi_hub_nav_url(string $url, bool $openInParent = false): string
{
    if (!app_mdi_is_embed_request() && !app_mdi_is_park_menu_embed()) {
        return $url;
    }
    if ($openInParent) {
        return $url;
    }

    if (app_mdi_is_park_menu_embed()) {
        return app_mdi_park_menu_url($url);
    }

    return app_mdi_embed_url($url);
}

/** @return list<string> */
function app_mdi_excluded_routes(): array
{
    $path = app_path('config/mdi_screens.php');
    if (!is_file($path)) {
        return ['dashboard', 'menu_hub'];
    }
    $cfg = require $path;
    if (!is_array($cfg)) {
        return ['dashboard', 'menu_hub'];
    }
    if (isset($cfg['exclude']) && is_array($cfg['exclude'])) {
        return array_values(array_filter(array_map('strval', $cfg['exclude'])));
    }

    return ['dashboard', 'menu_hub'];
}

/** @return array<string, array{title: string}> */
function app_mdi_routes_catalog(): array
{
    $routesPath = app_path('config/routes.php');
    if (!is_file($routesPath)) {
        return [];
    }
    $routes = require $routesPath;
    if (!is_array($routes)) {
        return [];
    }
    $exclude = array_flip(app_mdi_excluded_routes());
    $out = [];
    foreach ($routes as $code => $meta) {
        $code = (string) $code;
        if ($code === '' || isset($exclude[$code])) {
            continue;
        }
        $title = trim((string) (is_array($meta) ? ($meta['title'] ?? '') : ''));
        if ($title === '') {
            $title = $code;
        }
        $out[$code] = ['title' => $title];
    }

    return $out;
}

function app_mdi_resolve_route(string $activeRoute = ''): string
{
    if ($activeRoute !== '') {
        return $activeRoute;
    }
    $activeRoute = (string) ($GLOBALS['activeRoute'] ?? '');
    if ($activeRoute !== '') {
        return $activeRoute;
    }

    return trim((string) ($_GET['r'] ?? ''));
}

function app_mdi_route_allowed(string $route): bool
{
    $route = app_mdi_resolve_route($route);
    if ($route === '') {
        return false;
    }

    return !in_array($route, app_mdi_excluded_routes(), true);
}

function app_mdi_enqueue_styles(): void
{
    if (app_mdi_is_embed_request() || app_mdi_is_park_menu_embed()) {
        return;
    }
    $cssPath = app_path('assets/css/app-window-manager.css');
    if (!is_file($cssPath)) {
        return;
    }
    $v = (string) filemtime($cssPath);
    echo '<link rel="stylesheet" href="' . esc(app_url('assets/css/app-window-manager.css')) . '?v=' . esc($v) . '">' . "\n";
}

function app_mdi_render_layer(): void
{
    if (app_mdi_is_embed_request()) {
        return;
    }
    echo '<div id="app-mdi-hub-overlay" class="app-mdi-hub-overlay no-print" hidden aria-hidden="true">' . "\n";
    echo '<iframe id="app-mdi-hub-frame" class="app-mdi-hub-frame" title="القائمة"></iframe>' . "\n";
    echo '</div>' . "\n";
    echo '<div id="app-mdi-layer" class="app-mdi-layer no-print" aria-hidden="true"></div>' . "\n";
    echo '<div id="app-mdi-taskbar" class="app-mdi-taskbar no-print" hidden>' . "\n";
    echo '<div class="app-mdi-taskbar-windows"></div>' . "\n";
    echo '</div>' . "\n";
}

function app_mdi_render_title_bar_controls(string $activeRoute, ?string $overrideUrl = null, ?string $overrideHint = null): void
{
    if (app_mdi_is_embed_request() || !app_mdi_route_allowed($activeRoute)) {
        return;
    }

    require_once app_path('includes/nav_helpers.php');
    if ($overrideUrl !== null && $overrideUrl !== '' && nav_is_safe_back_url($overrideUrl)) {
        $url = $overrideUrl;
        $hint = $overrideHint ?? 'إغلاق والعودة';
    } else {
        $info = nav_screen_close_info($activeRoute);
        $url = (string) ($info['url'] ?? '');
        $hint = (string) ($info['hint'] ?? 'إغلاق والعودة');
    }

    echo '<div class="ora12-title-bar__controls no-print">';
    echo '<button type="button" class="ora12-title-bar__btn ora12-title-bar__minimize" id="app-mdi-minimize-screen" title="تصغير الشاشة" aria-label="تصغير الشاشة">_</button>';
    echo '<a class="ora12-title-bar__btn ora12-title-bar__close" href="' . esc($url) . '"';
    echo ' title="' . esc($hint) . '" aria-label="' . esc($hint) . '">×</a>';
    echo '</div>' . "\n";
}

function app_mdi_render_screen_minimize_btn(string $activeRoute): void
{
    /* زر التصغير أصبح على شريط عنوان الشاشة الأزرق. */
}

function app_mdi_render_embed_minimize_btn(): void
{
    if (!app_mdi_is_embed_request()) {
        return;
    }
    if (!app_mdi_route_allowed('')) {
        return;
    }
    echo '<button type="button" class="app-mdi-screen-minimize-btn app-mdi-screen-minimize-btn--embed ora12-title-bar__minimize" id="app-mdi-minimize-embed" title="تصغير إلى الشريط السفلي" aria-label="تصغير">_</button>' . "\n";
}

function app_mdi_after_minimize_url(string $activeRoute): string
{
    require_once app_path('includes/nav_helpers.php');

    $hubD = trim((string) ($_GET['hub_d'] ?? ''));
    $hubS = trim((string) ($_GET['hub_s'] ?? ''));
    if ($hubD !== '' && $hubS !== '') {
        return nav_hub_url($hubD, $hubS);
    }
    if ($hubD !== '') {
        return nav_domain_hub_url($hubD);
    }

    $sessionHub = $_SESSION['nav_return_hub'] ?? null;
    if (is_array($sessionHub)) {
        $sd = trim((string) ($sessionHub['d'] ?? ''));
        $ss = trim((string) ($sessionHub['s'] ?? ''));
        if ($sd !== '' && $ss !== '') {
            return nav_hub_url($sd, $ss);
        }
        if ($sd !== '') {
            return nav_domain_hub_url($sd);
        }
    }

    $hub = nav_resolve_active_hub($activeRoute);
    if ($hub !== null && ($hub['domain_id'] ?? '') !== '') {
        $domainId = (string) $hub['domain_id'];
        $subId = (string) ($hub['sub_id'] ?? '');
        if ($subId !== '') {
            return nav_hub_url($domainId, $subId);
        }

        return nav_domain_hub_url($domainId);
    }

    return app_url('index.php?r=dashboard');
}

function app_mdi_enqueue_scripts(): void
{
    if (app_mdi_is_embed_request() || app_mdi_is_park_menu_embed()) {
        return;
    }
    $jsPath = app_path('assets/js/app-window-manager.js');
    if (!is_file($jsPath)) {
        return;
    }
    global $activeRoute, $routeTitle, $pageTitle;
    $screenTitle = trim($pageTitle) !== '' ? trim($pageTitle) : trim((string) ($routeTitle ?? ''));
    $activeRouteStr = (string) ($activeRoute ?? '');
    $payload = [
        'baseUrl' => app_url('index.php'),
        'afterMinimizeUrl' => app_mdi_after_minimize_url($activeRouteStr),
        'currentRoute' => $activeRouteStr,
        'currentTitle' => $screenTitle,
        'routes' => app_mdi_routes_catalog(),
        'excludeRoutes' => app_mdi_excluded_routes(),
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        $json = '{}';
    }
    $v = (string) filemtime($jsPath);
    echo '<script>window.AppMdiConfig=' . $json . ';</script>' . "\n";
    echo '<script src="' . esc(app_url('assets/js/app-window-manager.js')) . '?v=' . esc($v) . '" defer></script>' . "\n";
}
