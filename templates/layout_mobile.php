<?php
declare(strict_types=1);
/** @var string $pageTitle */
/** @var string $content */
/** @var string $activeRoute */

require_once app_path('includes/nav_helpers.php');
require_once app_path('includes/mobile_auth.php');
require_once app_path('includes/mobile_icons.php');
require_once app_path('includes/mobile_main_toolbar.php');
require_once app_path('includes/mobile_pwa.php');

$user = current_user();
$cssV = is_file(app_path('assets/mobile/app.css')) ? (string) filemtime(app_path('assets/mobile/app.css')) : '';
$uiDlgCssV = is_file(app_path('assets/css/ui-dialog.css')) ? (string) filemtime(app_path('assets/css/ui-dialog.css')) : '';
$uiDlgJsV = is_file(app_path('assets/js/ui-dialog.js')) ? (string) filemtime(app_path('assets/js/ui-dialog.js')) : '';
$jsV = is_file(app_path('assets/mobile/app.js')) ? (string) filemtime(app_path('assets/mobile/app.js')) : '';
$pdfFnV = is_file(app_path('assets/mobile/pdf-filename.js'))
    ? (string) filemtime(app_path('assets/mobile/pdf-filename.js'))
    : '';

$docBrand = document_header_brand(db());
$companyNameAr = (string) ($docBrand['company_name_ar'] ?? 'النظام المحاسبي');
$companyLogoUrl = $docBrand['logo_url'] ?? null;
$companySettings = company_settings(db());
$settingsRow = [
    'company_name_ar' => $companyNameAr,
    'logo_path' => $companySettings['logo_path'] ?? null,
];

/** @var array<string, array<string, mixed>> $mobileRoutes */
$mobileRoutes = require app_path('config/routes_mobile.php');
$activeRouteKey = (string) ($activeRoute ?? '');
$routeMeta = $mobileRoutes[$activeRouteKey] ?? [];
$pageTileKind = (string) ($routeMeta['tile_kind'] ?? '');
$pageBodyClass = 'm-body';
if ($pageTileKind === 'list') {
    $pageBodyClass .= ' m-page-list';
} elseif ($pageTileKind === 'doc') {
    $pageBodyClass .= ' m-page-doc';
}
if ($activeRouteKey === 'm_receipt') {
    $pageBodyClass .= ' m-page-receipt-doc';
} elseif ($activeRouteKey === 'm_sales_invoices') {
    $pageBodyClass .= ' m-page-invoice-doc m-ora12-invoice-ui';
} elseif ($activeRouteKey === 'm_sales_invoice_list') {
    $pageBodyClass .= ' m-page-invoice-list m-ora12-invoice-ui';
} elseif ($activeRouteKey === 'm_sales_invoice_gps' || $activeRouteKey === 'm_user_gps_locations') {
    $pageBodyClass .= ' m-page-invoice-gps m-ora12-invoice-ui';
} elseif ($activeRouteKey === 'm_sales_invoice_view') {
    $pageBodyClass .= ' m-ora12-invoice-ui';
} elseif ($activeRouteKey === 'm_party_statement') {
    $pageBodyClass .= ' m-ora12-invoice-ui';
} elseif ($activeRouteKey === 'm_receipt_list') {
    $pageBodyClass .= ' m-page-receipt-list';
} elseif ($activeRouteKey === 'm_sales_returns') {
    $pageBodyClass .= ' m-page-sales-return';
} elseif ($activeRouteKey === 'm_sales_returns_list') {
    $pageBodyClass .= ' m-page-return-list';
} elseif ($activeRouteKey === 'm_rep_load' || $activeRouteKey === 'm_rep_return') {
    $pageBodyClass .= ' m-page-rep-custody';
} elseif ($activeRouteKey === 'm_rep_stock') {
    $pageBodyClass .= ' m-page-rep-stock';
}

$showBottomDock = $activeRouteKey !== 'm_home';
if (!$showBottomDock) {
    $pageBodyClass .= ' m-page-home';
}

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0572ce">
    <title><?= esc(trim($pageTitle) !== '' ? $pageTitle . ' — هاتف' : 'تطبيق الهاتف') ?></title>
    <?php render_app_favicon_links($settingsRow); ?>
    <?php render_mobile_pwa_head($settingsRow); ?>
    <link rel="stylesheet" href="<?= esc(app_url('assets/css/ui-dialog.css')) ?><?= $uiDlgCssV !== '' ? '?v=' . esc($uiDlgCssV) : '' ?>">
    <link rel="stylesheet" href="<?= esc(app_url('assets/mobile/app.css')) ?><?= $cssV !== '' ? '?v=' . esc($cssV) : '' ?>">
<?php
$invOra12Routes = ['m_sales_invoices', 'm_sales_invoice_list', 'm_sales_invoice_view', 'm_sales_invoice_gps', 'm_user_gps_locations', 'm_party_statement'];
if (in_array($activeRouteKey, $invOra12Routes, true)) {
    $invOra12V = is_file(app_path('assets/mobile/invoice-ora12.css'))
        ? (string) filemtime(app_path('assets/mobile/invoice-ora12.css'))
        : '';
    ?>
    <link rel="stylesheet" href="<?= esc(app_url('assets/mobile/invoice-ora12.css')) ?><?= $invOra12V !== '' ? '?v=' . esc($invOra12V) : '' ?>">
<?php } ?>
    <script>
        window.AppMobile = {
            baseUrl: <?= json_encode(app_url(''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
            mobileUrl: <?= json_encode(mobile_url(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
            csrf: <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE) ?>,
            activeRoute: <?= json_encode($activeRoute, JSON_UNESCAPED_UNICODE) ?>
        };
    </script>
<?php
$toolbarJsVHead = is_file(app_path('assets/mobile/mobile-toolbar.js'))
    ? (string) filemtime(app_path('assets/mobile/mobile-toolbar.js'))
    : '';
$toolbarRoutesJsV = is_file(app_path('assets/mobile/mobile-toolbar-routes.js'))
    ? (string) filemtime(app_path('assets/mobile/mobile-toolbar-routes.js'))
    : '';
?>
    <script src="<?= esc(app_url('assets/mobile/mobile-toolbar.js')) ?><?= $toolbarJsVHead !== '' ? '?v=' . esc($toolbarJsVHead) : '' ?>"></script>
    <script src="<?= esc(app_url('assets/mobile/mobile-toolbar-routes.js')) ?><?= $toolbarRoutesJsV !== '' ? '?v=' . esc($toolbarRoutesJsV) : '' ?>" defer></script>
</head>
<body class="<?= esc($pageBodyClass) ?><?= $showBottomDock ? ' m-has-action-dock' : '' ?><?= ($activeRoute ?? '') === 'm_sales_invoice_view' ? ' m-page-inv-view' : '' ?><?= ($activeRoute ?? '') === 'm_party_statement' ? ' m-page-party-stmt' : '' ?>">
<header class="m-header">
    <?php
    $backUrl = mobile_url('r=m_home');
    if ($activeRoute === 'm_sales_invoice_view') {
        $backUrl = mobile_url('r=m_sales_invoice_list');
    } elseif ($activeRoute === 'm_sales_invoices' || $activeRoute === 'm_sales_invoice_list' || $activeRoute === 'm_sales_invoice_gps' || $activeRoute === 'm_user_gps_locations') {
        $backUrl = mobile_url('r=m_home');
    } elseif ($activeRoute === 'm_receipt') {
        $backUrl = mobile_url('r=m_receipt_list');
    } elseif ($activeRoute === 'm_receipt_list') {
        $backUrl = mobile_url('r=m_home');
    } elseif ($activeRoute === 'm_sales_returns') {
        $backUrl = mobile_url('r=m_sales_returns_list');
    } elseif ($activeRoute === 'm_sales_returns_list') {
        $backUrl = mobile_url('r=m_home');
    } elseif ($activeRoute !== 'm_home') {
        $backUrl = mobile_url('r=m_home');
    }
    $showBack = ($activeRoute ?? '') !== 'm_home';
    ?>
    <div class="m-header-bar">
        <div class="m-header-side m-header-side--start">
            <?php if ($showBack): ?>
            <a class="m-header-back" href="<?= esc($backUrl) ?>" aria-label="رجوع">←</a>
            <?php endif; ?>
            <h1 class="m-header-title"><?= esc($pageTitle) ?></h1>
        </div>
        <div class="m-header-side m-header-side--center" aria-label="الشركة">
            <div class="m-header-brand">
                <?php if (is_string($companyLogoUrl) && $companyLogoUrl !== ''): ?>
                <span class="m-header-logo-wrap">
                    <img class="m-header-logo" src="<?= esc($companyLogoUrl) ?>" alt="">
                </span>
                <?php endif; ?>
                <span class="m-header-company"><?= esc($companyNameAr) ?></span>
            </div>
        </div>
        <div class="m-header-side m-header-side--end">
            <a class="m-header-logout" href="<?= esc(app_url('m/logout.php')) ?>" title="تسجيل خروج">خروج</a>
        </div>
    </div>
</header>
<?php if ($showBottomDock): ?>
<div class="m-bottom-dock" id="m-bottom-dock">
    <div class="m-action-dock-slot" id="m-action-dock-slot" aria-live="polite">
        <?= mobile_main_toolbar_html() ?>
    </div>
<nav class="m-tabbar" aria-label="التنقل الرئيسي">
    <?php $tabHome = mobile_icon_tile('home'); $tabInv = mobile_icon_tile('invoice'); $tabList = mobile_icon_tile('list'); ?>
    <a class="m-tabbar-item<?= $activeRoute === 'm_home' ? ' is-active' : '' ?>" href="<?= esc(mobile_url('r=m_home')) ?>">
        <span class="m-tabbar-icon-wrap m-tabbar-icon-wrap--tab <?= esc($tabHome['class']) ?>" aria-hidden="true"><?= $tabHome['html'] ?></span>
        <span>الرئيسية</span>
    </a>
    <?php if (user_can('m_sales_invoices')): ?>
    <a class="m-tabbar-item<?= $activeRoute === 'm_sales_invoices' ? ' is-active' : '' ?>" href="<?= esc(mobile_url('r=m_sales_invoices')) ?>">
        <span class="m-tabbar-icon-wrap m-tabbar-icon-wrap--tab <?= esc($tabInv['class']) ?>" aria-hidden="true"><?= $tabInv['html'] ?></span>
        <span>فواتير المبيعات</span>
    </a>
    <?php endif; ?>
    <?php if (user_can('m_sales_invoices')): ?>
    <a class="m-tabbar-item<?= ($activeRoute === 'm_sales_invoice_list' || $activeRoute === 'm_sales_invoice_view') ? ' is-active' : '' ?>" href="<?= esc(mobile_url('r=m_sales_invoice_list')) ?>">
        <span class="m-tabbar-icon-wrap m-tabbar-icon-wrap--tab <?= esc($tabList['class']) ?>" aria-hidden="true"><?= $tabList['html'] ?></span>
        <span>قائمة الفواتير</span>
    </a>
    <?php endif; ?>
</nav>
</div>
<?php endif; ?>
<main class="m-main">
    <?= $content ?>
</main>
<script src="<?= esc(app_url('assets/js/ui-dialog.js')) ?><?= $uiDlgJsV !== '' ? '?v=' . esc($uiDlgJsV) : '' ?>"></script>
<?php
$pdfExportV = is_file(app_path('assets/mobile/pdf-export.js'))
    ? (string) filemtime(app_path('assets/mobile/pdf-export.js'))
    : '';
?>
<script src="<?= esc(app_url('assets/mobile/pdf-filename.js')) ?><?= $pdfFnV !== '' ? '?v=' . esc($pdfFnV) : '' ?>"></script>
<script src="<?= esc(app_url('assets/mobile/pdf-export.js')) ?><?= $pdfExportV !== '' ? '?v=' . esc($pdfExportV) : '' ?>"></script>
<script src="<?= esc(app_url('assets/mobile/app.js')) ?><?= $jsV !== '' ? '?v=' . esc($jsV) : '' ?>"></script>
<?php
$browserHintV = is_file(app_path('assets/mobile/app-browser-hint.js'))
    ? (string) filemtime(app_path('assets/mobile/app-browser-hint.js'))
    : '';
?>
<script src="<?= esc(app_url('assets/mobile/app-browser-hint.js')) ?><?= $browserHintV !== '' ? '?v=' . esc($browserHintV) : '' ?>"></script>
<script>
(function () {
  try {
    if (window.Capacitor && Capacitor.isNativePlatform && Capacitor.isNativePlatform()) {
      document.documentElement.classList.add('cap-native-app');
    }
  } catch (e) { /* ignore */ }
})();
</script>
<?php if (app_gps_enabled()): ?>
<?php
$userGpsJsPath = app_path('assets/js/user-session-gps.js');
$userGpsJsV = is_file($userGpsJsPath) ? (string) filemtime($userGpsJsPath) : '';
$geoJsPath = app_path('assets/js/geo.js');
$geoJsV = is_file($geoJsPath) ? (string) filemtime($geoJsPath) : '';
$geoMapPickCssPath = app_path('assets/css/geo-map-pick.css');
$geoMapPickCssV = is_file($geoMapPickCssPath) ? (string) filemtime($geoMapPickCssPath) : '';
$geoMapPickJsPath = app_path('assets/js/geo-map-pick.js');
$geoMapPickJsV = is_file($geoMapPickJsPath) ? (string) filemtime($geoMapPickJsPath) : '';
?>
<link rel="stylesheet" href="<?= esc(app_url('assets/css/geo-map-pick.css')) ?><?= $geoMapPickCssV !== '' ? '?v=' . esc($geoMapPickCssV) : '' ?>">
<script>
window.APP_GPS_ENABLED = true;
window.UserSessionGpsConfig = {
    pingApi: <?= json_encode(app_url('api/user_location_ping.php'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    csrf: <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE) ?>,
    source: 'mobile',
    intervalMs: 600000,
    initialDelayMs: 45000,
    nativeIntervalMs: 180000,
    nativeInitialDelayMs: 3000,
    nativeGeoTimeoutMs: 25000,
    sampleMs: 15000,
    geoTimeoutMs: 8000,
    goodEnoughM: 18
};
</script>
<script src="<?= esc(app_url('assets/js/geo-map-pick.js')) ?><?= $geoMapPickJsV !== '' ? '?v=' . esc($geoMapPickJsV) : '' ?>" defer></script>
<script src="<?= esc(app_url('assets/js/geo.js')) ?><?= $geoJsV !== '' ? '?v=' . esc($geoJsV) : '' ?>" defer></script>
<?php
$nativeGpsJsPath = app_path('assets/js/app-native-gps.js');
$nativeGpsJsV = is_file($nativeGpsJsPath) ? (string) filemtime($nativeGpsJsPath) : '';
?>
<script src="<?= esc(app_url('assets/js/app-native-gps.js')) ?><?= $nativeGpsJsV !== '' ? '?v=' . esc($nativeGpsJsV) : '' ?>" defer></script>
<script src="<?= esc(app_url('assets/js/user-session-gps.js')) ?><?= $userGpsJsV !== '' ? '?v=' . esc($userGpsJsV) : '' ?>" defer></script>
<?php else: ?>
<script>window.APP_GPS_ENABLED = false;</script>
<?php endif; ?>
</body>
</html>
