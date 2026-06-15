<?php
declare(strict_types=1);

/**
 * إعدادات PWA لتطبيق الهاتف (تثبيت من المتصفح + دعم Capacitor).
 */

/** @return list<array{src: string, sizes: string, type: string, purpose?: string}> */
function mobile_pwa_icons(?array $settingsRow = null): array
{
    $icons = [];
    $logoPath = trim((string) ($settingsRow['logo_path'] ?? ''));
    if ($logoPath !== '' && is_file(app_path($logoPath))) {
        $ext = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
        $types = [
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
        ];
        $type = $types[$ext] ?? 'image/png';
        $v = (string) (filemtime(app_path($logoPath)) ?: time());
        $href = app_url($logoPath) . '?v=' . rawurlencode($v);
        $icons[] = ['src' => $href, 'sizes' => '512x512', 'type' => $type, 'purpose' => 'any'];
        $icons[] = ['src' => $href, 'sizes' => '192x192', 'type' => $type, 'purpose' => 'any maskable'];
    }

    $icons[] = [
        'src' => app_url('assets/mobile/icon-192.png'),
        'sizes' => '192x192',
        'type' => 'image/png',
        'purpose' => 'any',
    ];
    $icons[] = [
        'src' => app_url('assets/mobile/icon-512.png'),
        'sizes' => '512x512',
        'type' => 'image/png',
        'purpose' => 'any maskable',
    ];

    return $icons;
}

/** @return array<string, mixed> */
function mobile_pwa_manifest(?array $settingsRow = null): array
{
    $company = trim((string) ($settingsRow['company_name_ar'] ?? ''));
    if ($company === '') {
        $company = 'النظام المحاسبي';
    }
    $short = mb_strlen($company) > 12 ? mb_substr($company, 0, 12) : $company;

    return [
        'id' => app_url('m/'),
        'name' => $company . ' — هاتف',
        'short_name' => $short,
        'description' => 'تطبيق الهاتف للنظام المحاسبي',
        'start_url' => mobile_url('r=m_home'),
        'scope' => app_url('m/'),
        'display' => 'standalone',
        'display_override' => ['standalone', 'fullscreen', 'minimal-ui'],
        'orientation' => 'portrait-primary',
        'dir' => 'rtl',
        'lang' => 'ar',
        'background_color' => '#e8eaed',
        'theme_color' => '#0572ce',
        'icons' => mobile_pwa_icons($settingsRow),
    ];
}

function render_mobile_pwa_head(?array $settingsRow = null): void
{
    $manifestUrl = app_url('m/manifest.php');
    $swUrl = app_url('m/sw.js');
    $v = is_file(app_path('m/sw.js')) ? (string) filemtime(app_path('m/sw.js')) : '';
    if ($v !== '') {
        $swUrl .= '?v=' . rawurlencode($v);
    }
    echo '<link rel="manifest" href="' . esc($manifestUrl) . '">' . "\n";
    echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
    echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
    echo '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">' . "\n";
    echo '<meta name="apple-mobile-web-app-title" content="' . esc((string) ($settingsRow['company_name_ar'] ?? 'محاسبة')) . '">' . "\n";
    echo '<script>if("serviceWorker"in navigator){window.addEventListener("load",function(){navigator.serviceWorker.register('
        . json_encode($swUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        . ',{scope:' . json_encode(app_url('m/'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '}).catch(function(){});});}</script>' . "\n";
}
