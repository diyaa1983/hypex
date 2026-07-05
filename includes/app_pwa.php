<?php
declare(strict_types=1);

/**
 * PWA لتطبيق سطح المكتب — تثبيت بأيقونة مستقلة (مثل ERPNext) بدون واجهة المتصفح.
 */

function app_pwa_scope(): string
{
    $base = rtrim(APP_URL_BASE, '/');

    return ($base !== '' ? $base . '/' : '/');
}

function app_pwa_start_url(): string
{
    return app_url('login.php');
}

/** @return string */
function app_pwa_brand_icon_url(): string
{
    $path = app_path('assets/pwa/app-icon.svg');
    $v = is_file($path) ? (string) filemtime($path) : '';

    return app_url('assets/pwa/app-icon.svg') . ($v !== '' ? '?v=' . rawurlencode($v) : '');
}

/** @return list<array{src: string, sizes: string, type: string, purpose?: string}> */
function app_pwa_icons(?array $settingsRow = null): array
{
    unset($settingsRow);
    $brand = app_pwa_brand_icon_url();

    return [
        [
            'src' => $brand,
            'sizes' => '512x512',
            'type' => 'image/svg+xml',
            'purpose' => 'any',
        ],
        [
            'src' => $brand,
            'sizes' => '192x192',
            'type' => 'image/svg+xml',
            'purpose' => 'any',
        ],
        [
            'src' => $brand,
            'sizes' => '512x512',
            'type' => 'image/svg+xml',
            'purpose' => 'maskable',
        ],
    ];
}

/** @return array<string, mixed> */
function app_pwa_manifest(?array $settingsRow = null): array
{
    $company = trim((string) ($settingsRow['company_name_ar'] ?? ''));
    if ($company === '') {
        $company = 'النظام المحاسبي';
    }
    $short = mb_strlen($company) > 14 ? mb_substr($company, 0, 14) : $company;
    $scope = app_pwa_scope();

    return [
        'id' => $scope,
        'name' => $company,
        'short_name' => $short,
        'description' => 'تطبيق سطح المكتب — ' . $company,
        'start_url' => app_pwa_start_url(),
        'scope' => $scope,
        'display' => 'standalone',
        'display_override' => ['window-controls-overlay', 'standalone', 'minimal-ui', 'browser'],
        'orientation' => 'any',
        'dir' => 'rtl',
        'lang' => 'ar',
        'background_color' => '#d4d0c8',
        'theme_color' => '#1e3a5f',
        'icons' => app_pwa_icons($settingsRow),
        'prefer_related_applications' => false,
    ];
}

function app_pwa_manifest_url(): string
{
    return app_url('manifest.php');
}

function app_pwa_sw_url(): string
{
    $v = is_file(app_path('sw.js')) ? (string) filemtime(app_path('sw.js')) : '';

    return app_url('sw.js') . ($v !== '' ? '?v=' . rawurlencode($v) : '');
}

function render_app_pwa_head(?array $settingsRow = null): void
{
    $manifest = esc(app_pwa_manifest_url());
    $theme = '#1e3a5f';
    $swUrl = app_pwa_sw_url();
    $scope = app_pwa_scope();

    echo '<link rel="manifest" href="' . $manifest . '">' . "\n";
    echo '<meta name="theme-color" content="' . esc($theme) . '">' . "\n";
    $brandIcon = esc(app_pwa_brand_icon_url());
    echo '<link rel="apple-touch-icon" href="' . $brandIcon . '">' . "\n";
    echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
    echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
    echo '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">' . "\n";
    $appTitle = trim((string) ($settingsRow['company_name_ar'] ?? 'Manager'));
    echo '<meta name="apple-mobile-web-app-title" content="' . esc($appTitle) . '">' . "\n";
    echo '<script>if("serviceWorker"in navigator){window.addEventListener("load",function(){navigator.serviceWorker.register('
        . json_encode($swUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        . ',{scope:' . json_encode($scope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        . '}).catch(function(){});});}</script>' . "\n";
}

function render_app_pwa_install_banner(): void
{
    $cssPath = app_path('assets/css/app-pwa-install.css');
    $cssUrl = app_url('assets/css/app-pwa-install.css')
        . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
    $jsPath = app_path('assets/js/app-pwa-install.js');
    $jsUrl = app_url('assets/js/app-pwa-install.js')
        . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : '');

    echo '<link rel="stylesheet" href="' . esc($cssUrl) . '">' . "\n";
    echo '<div id="app-pwa-install" class="app-pwa-install" hidden>';
    echo '<p class="app-pwa-install__text">ثبّت النظام كتطبيق على سطح المكتب — أيقونة مستقلة بدون متصفح.</p>';
    echo '<button type="button" class="btn btn-secondary btn-sm" id="app-pwa-install-btn">تثبيت التطبيق</button>';
    echo '<button type="button" class="app-pwa-install__dismiss" id="app-pwa-install-dismiss" aria-label="إخفاء">×</button>';
    echo '</div>' . "\n";
    echo '<script src="' . esc($jsUrl) . '" defer></script>' . "\n";
}
