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
    return app_pwa_icon_png_url(192);
}

/** @return string */
function app_pwa_icon_png_url(int $size): string
{
    $size = $size >= 512 ? 512 : 192;
    $file = 'icon-' . $size . '.png';
    $path = app_path('assets/pwa/' . $file);
    if (!is_file($path)) {
        $svgPath = app_path('assets/pwa/app-icon.svg');
        $v = is_file($svgPath) ? (string) filemtime($svgPath) : '';

        return app_url('assets/pwa/app-icon.svg') . ($v !== '' ? '?v=' . rawurlencode($v) : '');
    }
    $v = (string) filemtime($path);

    return app_url('assets/pwa/' . $file) . ($v !== '' ? '?v=' . rawurlencode($v) : '');
}

/** @return list<array{src: string, sizes: string, type: string, purpose?: string}> */
function app_pwa_icons(?array $settingsRow = null): array
{
    unset($settingsRow);
    $icon192 = app_pwa_icon_png_url(192);
    $icon512 = app_pwa_icon_png_url(512);
    $isPng = str_contains($icon512, '.png');

    return [
        [
            'src' => $icon512,
            'sizes' => '512x512',
            'type' => $isPng ? 'image/png' : 'image/svg+xml',
            'purpose' => 'any',
        ],
        [
            'src' => $icon192,
            'sizes' => '192x192',
            'type' => $isPng ? 'image/png' : 'image/svg+xml',
            'purpose' => 'any',
        ],
        [
            'src' => $icon512,
            'sizes' => '512x512',
            'type' => $isPng ? 'image/png' : 'image/svg+xml',
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
        'display' => 'fullscreen',
        'display_override' => ['fullscreen', 'standalone', 'minimal-ui'],
        'orientation' => 'any',
        'dir' => 'rtl',
        'lang' => 'ar',
        'background_color' => '#0b1220',
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
    $swUrl = app_pwa_sw_url();
    $scope = app_pwa_scope();

    echo '<link rel="manifest" href="' . $manifest . '">' . "\n";
    echo '<meta name="theme-color" content="#1e3a5f">' . "\n";
    echo '<meta name="theme-color" media="(prefers-color-scheme: light)" content="#1e3a5f">' . "\n";
    echo '<meta name="theme-color" media="(prefers-color-scheme: dark)" content="#1e3a5f">' . "\n";
    $brand192 = esc(app_pwa_icon_png_url(192));
    $brand512 = esc(app_pwa_icon_png_url(512));
    echo '<link rel="icon" type="image/png" sizes="192x192" href="' . $brand192 . '">' . "\n";
    echo '<link rel="icon" type="image/png" sizes="512x512" href="' . $brand512 . '">' . "\n";
    echo '<link rel="apple-touch-icon" href="' . $brand512 . '">' . "\n";
    echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
    echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
    echo '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">' . "\n";
    $appTitle = trim((string) ($settingsRow['company_name_ar'] ?? 'Manager'));
    echo '<meta name="apple-mobile-web-app-title" content="' . esc($appTitle) . '">' . "\n";
    echo '<meta name="application-name" content="' . esc($appTitle) . '">' . "\n";
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
