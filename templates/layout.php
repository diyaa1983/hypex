<?php
declare(strict_types=1);
/** @var string $pageTitle */
/** @var string $routeTitle */
/** @var string $content */
/** @var string $activeRoute */
/** @var bool $masterLedgerPostEnabled */
/** @var string $masterLedgerPostUrl */
/** @var string $masterLedgerPostCsrf */
/** @var bool $hrOracleUi */
$hrOracleUi = $hrOracleUi ?? false;
/** @var bool $reportOracleUi */
$reportOracleUi = $reportOracleUi ?? false;
/** @var bool $ora12PickerUi */
$ora12PickerUi = $ora12PickerUi ?? false;
$routeTitle = $routeTitle ?? '';
$masterLedgerPostUrl = $masterLedgerPostUrl ?? '';
$masterLedgerPostCsrf = $masterLedgerPostCsrf ?? '';

$settingsRow = ['company_name_ar' => 'النظام المحاسبي', 'logo_path' => null];
$appDecimalPlaces = 2;
$appInvoiceUnitPriceDecimals = 2;
$appUiTheme = 'basic';
$appCurrency = ['code' => 'SAR', 'name_ar' => 'ريال سعودي', 'main_ar' => 'ريالاً سعودياً', 'fraction_ar' => 'هللة', 'symbol' => 'ر.س', 'fraction_units' => 100];
try {
    require_once app_path('includes/company_settings.php');
    require_once app_path('includes/company_currency.php');
    $company = company_settings(db());
    $settingsRow = [
        'company_name_ar' => (string) ($company['company_name_ar'] ?? 'النظام المحاسبي'),
        'logo_path' => $company['logo_path'] ?? null,
    ];
    $appDecimalPlaces = company_decimal_places();
    $appInvoiceUnitPriceDecimals = company_invoice_unit_price_decimal_places();
    $appUiTheme = app_ui_theme();
    $appCurrency = company_currency();
} catch (Throwable $e) {
    // DB غير مهيأ بعد
}
$appFormatJsV = is_file(app_path('assets/js/app-format.js'))
    ? (string) filemtime(app_path('assets/js/app-format.js'))
    : '';

require_once app_path('includes/master_toolbar.php');
require_once app_path('includes/nav_helpers.php');
require_once app_path('includes/document_header.php');
require_once app_path('includes/header_check_notifications.php');
require_once app_path('includes/app_window_manager.php');

$headerCheckNotify = header_check_notifications_collect(db());
$headerCheckNotifyJson = '[]';
if ($headerCheckNotify['enabled'] ?? false) {
    $headerCheckNotifyJson = json_encode($headerCheckNotify['checks'] ?? [], JSON_UNESCAPED_UNICODE);
    if ($headerCheckNotifyJson === false) {
        $headerCheckNotifyJson = '[]';
    }
}

$hasDocWatermark = !empty($settingsRow['logo_path']) && is_file(app_path((string) $settingsRow['logo_path']));
$docWatermarkRootCss = $hasDocWatermark ? document_print_watermark_root_css() : '';
$docWatermarkLogoUrl = $hasDocWatermark ? document_print_watermark_logo_url() : '';

$activeRoute = (string) ($activeRoute ?? 'dashboard');
$layoutFocus = nav_layout_is_screen_focus($activeRoute);
$navMenu = ['domains' => []];
$navActiveHub = null;
if (!$layoutFocus) {
    $navMenu = nav_menu_config();
    $navActiveHub = nav_resolve_active_hub($activeRoute);
}
$dashboardUrl = app_url('index.php?r=dashboard');
$logoutUrl = app_url('logout.php');
$user = current_user();
$appUserLabel = document_print_user_label();
$printUserLabel = $appUserLabel;
$tabPageTitle = trim($pageTitle) !== '' ? $pageTitle : (string) ($routeTitle ?? '');
$browserTabTitle = app_browser_tab_title($tabPageTitle, $activeRoute, (string) ($settingsRow['company_name_ar'] ?? ''));

?>
<!DOCTYPE html>
<html lang="<?= esc(app_lang()) ?>" dir="<?= esc(app_dir()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($browserTabTitle) ?></title>
    <?php render_app_favicon_links($settingsRow); ?>
    <?php
    require_once app_path('includes/app_pwa.php');
    render_app_pwa_head($settingsRow);
    ?>
    <?php
    $appCssV = is_file(app_path('assets/css/app.css')) ? (string) filemtime(app_path('assets/css/app.css')) : '';
    $uiDlgCssV = is_file(app_path('assets/css/ui-dialog.css')) ? (string) filemtime(app_path('assets/css/ui-dialog.css')) : '';
    $uiDlgJsV = is_file(app_path('assets/js/ui-dialog.js')) ? (string) filemtime(app_path('assets/js/ui-dialog.js')) : '';
    $docHdrCssV = is_file(app_path('assets/css/document-header.css')) ? (string) filemtime(app_path('assets/css/document-header.css')) : '';
    $docHdrJsV = is_file(app_path('assets/js/document-header.js')) ? (string) filemtime(app_path('assets/js/document-header.js')) : '';
    $sidebarNavJsV = is_file(app_path('assets/js/sidebar-nav.js')) ? (string) filemtime(app_path('assets/js/sidebar-nav.js')) : '';
    $datePickerCssV = is_file(app_path('assets/css/app-date-picker.css')) ? (string) filemtime(app_path('assets/css/app-date-picker.css')) : '';
    $datePickerJsV = is_file(app_path('assets/js/app-date-picker.js')) ? (string) filemtime(app_path('assets/js/app-date-picker.js')) : '';
    $listKeyboardJsV = is_file(app_path('assets/js/app-list-keyboard.js'))
        ? (string) filemtime(app_path('assets/js/app-list-keyboard.js'))
        : '';
    $checkModalCssV = is_file(app_path('assets/css/check-alerts-modal.css'))
        ? (string) filemtime(app_path('assets/css/check-alerts-modal.css'))
        : '';
    $checkBellCssV = is_file(app_path('assets/css/header-check-notifications.css'))
        ? (string) filemtime(app_path('assets/css/header-check-notifications.css'))
        : '';
    $checkUiJsV = is_file(app_path('assets/js/check-alerts-ui.js'))
        ? (string) filemtime(app_path('assets/js/check-alerts-ui.js'))
        : '';
    $checkBellJsV = is_file(app_path('assets/js/header-check-notifications.js'))
        ? (string) filemtime(app_path('assets/js/header-check-notifications.js'))
        : '';
    $dashCssV = is_file(app_path('assets/css/dashboard.css'))
        ? (string) filemtime(app_path('assets/css/dashboard.css'))
        : '';
    $ora12HrCssV = is_file(app_path('assets/css/oracle12-hr.css'))
        ? (string) filemtime(app_path('assets/css/oracle12-hr.css'))
        : '';
    $hrOraCssV = is_file(app_path('assets/css/hr-oracle-forms.css'))
        ? (string) filemtime(app_path('assets/css/hr-oracle-forms.css'))
        : '';
    $hrOraUnsavedJsV = is_file(app_path('assets/js/hr-ora-unsaved.js'))
        ? (string) filemtime(app_path('assets/js/hr-ora-unsaved.js'))
        : '';
$screenExitGuardJsV = is_file(app_path('assets/js/screen-exit-guard.js'))
    ? (string) filemtime(app_path('assets/js/screen-exit-guard.js'))
    : '';
$appHeaderUiJsV = is_file(app_path('assets/js/app-header-ui.js'))
    ? (string) filemtime(app_path('assets/js/app-header-ui.js'))
    : '';
    $docNoNavJsV = is_file(app_path('assets/js/document-no-nav.js'))
        ? (string) filemtime(app_path('assets/js/document-no-nav.js'))
        : '';
    $navPrefetchJsV = is_file(app_path('assets/js/nav-prefetch.js'))
        ? (string) filemtime(app_path('assets/js/nav-prefetch.js'))
        : '';
    $reportOra12CssV = is_file(app_path('assets/css/report-oracle12.css'))
        ? (string) filemtime(app_path('assets/css/report-oracle12.css'))
        : '';
    $appBusyCssV = is_file(app_path('assets/css/app-busy.css'))
        ? (string) filemtime(app_path('assets/css/app-busy.css'))
        : '';
    $appBusyJsV = is_file(app_path('assets/js/app-busy.js'))
        ? (string) filemtime(app_path('assets/js/app-busy.js'))
        : '';
    ?>
    <?php
    $appFontCssV = is_file(app_path('assets/css/app-font.css'))
        ? (string) filemtime(app_path('assets/css/app-font.css'))
        : '';
    ?>
    <link rel="stylesheet" href="<?= esc(app_url('assets/css/app-font.css')) ?><?= $appFontCssV !== '' ? '?v=' . esc($appFontCssV) : '' ?>">
    <link rel="stylesheet" href="<?= esc(app_url('assets/css/app.css')) ?><?= $appCssV !== '' ? '?v=' . esc($appCssV) : '' ?>">
    <?php
    $themeProCssV = is_file(app_path('assets/css/theme-pro.css'))
        ? (string) filemtime(app_path('assets/css/theme-pro.css'))
        : '';
    if (($appUiTheme ?? 'basic') !== 'classic'):
    ?>
    <link rel="stylesheet" href="<?= esc(app_url('assets/css/theme-pro.css')) ?><?= $themeProCssV !== '' ? '?v=' . esc($themeProCssV) : '' ?>">
    <?php endif; ?>
    <?php
    $uiLtrCssV = is_file(app_path('assets/css/ui-lang-ltr.css'))
        ? (string) filemtime(app_path('assets/css/ui-lang-ltr.css'))
        : '';
    ?>
    <link rel="stylesheet" href="<?= esc(app_url('assets/css/ui-lang-ltr.css')) ?><?= $uiLtrCssV !== '' ? '?v=' . esc($uiLtrCssV) : '' ?>">
    <link rel="stylesheet" href="<?= esc(app_url('assets/css/app-busy.css')) ?><?= $appBusyCssV !== '' ? '?v=' . esc($appBusyCssV) : '' ?>">
    <?php app_mdi_enqueue_styles(); ?>
    <?php app_mdi_enqueue_scripts(); ?>
    <link rel="stylesheet" href="<?= esc(app_url('assets/css/ui-dialog.css')) ?><?= $uiDlgCssV !== '' ? '?v=' . esc($uiDlgCssV) : '' ?>">
    <?php if ($hrOracleUi): ?>
    <link rel="stylesheet" href="<?= esc(app_url('assets/css/dashboard.css')) ?><?= $dashCssV !== '' ? '?v=' . esc($dashCssV) : '' ?>">
    <link rel="stylesheet" href="<?= esc(app_url('assets/css/oracle12-hr.css')) ?><?= $ora12HrCssV !== '' ? '?v=' . esc($ora12HrCssV) : '' ?>">
    <link rel="stylesheet" href="<?= esc(app_url('assets/css/hr-oracle-forms.css')) ?><?= $hrOraCssV !== '' ? '?v=' . esc($hrOraCssV) : '' ?>">
    <?php endif; ?>
    <?php if ($reportOracleUi): ?>
    <link rel="stylesheet" href="<?= esc(app_url('assets/css/dashboard.css')) ?><?= $dashCssV !== '' ? '?v=' . esc($dashCssV) : '' ?>">
    <link rel="stylesheet" href="<?= esc(app_url('assets/css/report-oracle12.css')) ?><?= $reportOra12CssV !== '' ? '?v=' . esc($reportOra12CssV) : '' ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= esc(app_url('assets/css/document-header.css')) ?><?= $docHdrCssV !== '' ? '?v=' . esc($docHdrCssV) : '' ?>">
    <link rel="stylesheet" href="<?= esc(app_url('assets/css/app-date-picker.css')) ?><?= $datePickerCssV !== '' ? '?v=' . esc($datePickerCssV) : '' ?>">
    <?php if ($headerCheckNotify['enabled'] ?? false): ?>
    <link rel="stylesheet" href="<?= esc(app_url('assets/css/check-alerts-modal.css')) ?><?= $checkModalCssV !== '' ? '?v=' . esc($checkModalCssV) : '' ?>">
    <link rel="stylesheet" href="<?= esc(app_url('assets/css/header-check-notifications.css')) ?><?= $checkBellCssV !== '' ? '?v=' . esc($checkBellCssV) : '' ?>">
    <?php endif; ?>
    <?php if ($docWatermarkRootCss !== ''): ?>
    <style><?= $docWatermarkRootCss ?></style>
    <?php endif; ?>
    <script>
        window.AppCurrency = <?= json_encode([
            'code' => (string) ($appCurrency['code'] ?? 'SAR'),
            'name_ar' => (string) ($appCurrency['name_ar'] ?? ''),
            'main_ar' => (string) ($appCurrency['main_ar'] ?? 'ريالاً'),
            'fraction_ar' => (string) ($appCurrency['fraction_ar'] ?? 'هللة'),
            'symbol' => (string) ($appCurrency['symbol'] ?? ''),
            'fraction_units' => (int) ($appCurrency['fraction_units'] ?? 100),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <script src="<?= esc(app_url('assets/js/app-busy.js')) ?><?= $appBusyJsV !== '' ? '?v=' . esc($appBusyJsV) : '' ?>" defer></script>
</head>
<?php
require_once app_path('includes/sys_favorites.php');
$favRouteAllowed = sys_favorites_route_allowed($activeRoute);
$favIsFavorite = false;
if ($favRouteAllowed) {
    try {
        $favUid = (int) ($user['id'] ?? 0);
        $favIsFavorite = $favUid > 0 && sys_favorites_is_favorite(db(), $favUid, $activeRoute);
    } catch (Throwable $e) {
        $favIsFavorite = false;
    }
}
?>
<body class="app-body ui-theme-<?= esc($appUiTheme) ?><?= $layoutFocus ? ' app-body--focus' : '' ?><?= $hasDocWatermark ? ' has-doc-watermark' : '' ?><?= $hrOracleUi ? ' hr-ora-ui' : '' ?><?= $reportOracleUi ? ' report-ora12-ui' : '' ?><?= $ora12PickerUi ? ' ora12-picker-ui' : '' ?>" data-lang="<?= esc(app_lang()) ?>" data-dir="<?= esc(app_dir()) ?>" data-decimal-places="<?= (int) $appDecimalPlaces ?>" data-invoice-unit-price-decimals="<?= (int) $appInvoiceUnitPriceDecimals ?>"<?= $docWatermarkLogoUrl !== '' ? ' data-company-logo-url="' . esc($docWatermarkLogoUrl) . '"' : '' ?><?= $printUserLabel !== '' ? ' data-print-user="' . esc($printUserLabel) . '"' : '' ?> data-active-route="<?= esc($activeRoute) ?>" data-csrf="<?= esc(csrf_token()) ?>" data-error-log-api="<?= esc(app_url('api/sys_error_log_client.php')) ?>" data-fav-api="<?= esc(app_url('api/favorite_toggle.php')) ?>" data-fav-allowed="<?= $favRouteAllowed ? '1' : '0' ?>" data-is-favorite="<?= $favIsFavorite ? '1' : '0' ?>">
<?php render_i18n_js(); ?>
<?php render_app_titlebar($tabPageTitle, (string) $routeTitle, $activeRoute, (string) ($settingsRow['company_name_ar'] ?? '')); ?>
<div class="app-shell<?= $layoutFocus ? ' app-shell--focus' : '' ?>">
<?php
    $screenHeadTitle = trim($tabPageTitle) !== '' ? trim($tabPageTitle) : trim((string) $routeTitle);
    if ($screenHeadTitle !== '' && function_exists('__')) {
        $screenHeadTitle = __($screenHeadTitle);
    }
?>
<?php if ($layoutFocus): ?>
    <header class="app-topbar no-print" role="banner">
        <div class="app-topbar-account">
            <?php render_app_header_account($appUserLabel, $logoutUrl); ?>
        </div>
        <div class="app-topbar-main">
            <?php render_header_check_notifications($headerCheckNotify); ?>
            <?php render_master_toolbar(); ?>
        </div>
    </header>
    <main class="main-content main-content--focus">
        <?php
        require_once app_path('includes/report_oracle12_ui.php');
        report_ora12_layout_open($pageTitle, (string) $routeTitle, $activeRoute);
        echo $content;
        report_ora12_layout_close($activeRoute);
        ?>
    </main>
<?php else: ?>
    <?php $showMasterToolbar = $activeRoute !== 'dashboard' && $activeRoute !== 'menu_hub'; ?>
    <header class="app-screen-head no-print" role="banner">
        <div class="app-screen-head-account">
            <?php render_app_header_account($appUserLabel, $logoutUrl); ?>
        </div>
        <?php if (!$showMasterToolbar && $screenHeadTitle !== ''): ?>
        <div class="app-screen-head-title" title="<?= esc($screenHeadTitle) ?>"><?= esc($screenHeadTitle) ?></div>
        <?php endif; ?>
        <div class="app-screen-head-main">
            <?php render_header_check_notifications($headerCheckNotify); ?>
            <?php if ($showMasterToolbar): ?>
            <?php render_master_toolbar(); ?>
            <?php endif; ?>
        </div>
    </header>
    <div class="app-shell-body">
    <aside class="sidebar sidebar--compact-head">
        <nav class="sidebar-nav">
            <?php foreach ($navMenu['domains'] as $domain): ?>
                <?php nav_render_sidebar_domain($domain, $activeRoute, $navActiveHub); ?>
                <?php if ((string) ($domain['id'] ?? '') === 'favorites'): ?>
                    <?php
                    require_once app_path('includes/sys_backup.php');
                    sys_backup_render_sidebar_link($activeRoute);
                    ?>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-foot">
            <div class="user-chip">
                <div>
                    <div class="user-name"><?= esc($appUserLabel) ?></div>
                    <div class="user-meta"><?= esc((string) ($user['username'] ?? '')) ?></div>
                </div>
            </div>
            <div class="sidebar-session-actions">
                <?php render_nav_exit_button($activeRoute); ?>
                <a class="sidebar-logout-btn" href="<?= esc($logoutUrl) ?>">تسجيل خروج</a>
            </div>
        </div>
    </aside>
    <div class="main-wrap<?= !empty($settingsRow['logo_path']) ? ' main-wrap--has-logo' : '' ?>">
        <?php if (!empty($settingsRow['logo_path'])): ?>
        <div class="main-bg-logo" aria-hidden="true">
            <img src="<?= esc(app_url((string) $settingsRow['logo_path'])) ?>" alt="">
        </div>
        <?php endif; ?>
        <main class="main-content">
            <?php
            require_once app_path('includes/report_oracle12_ui.php');
            report_ora12_layout_open($pageTitle, (string) $routeTitle, $activeRoute);
            echo $content;
            report_ora12_layout_close($activeRoute);
            ?>
        </main>
    </div>
    </div>
<?php endif; ?>
</div>
<?php
require_once app_path('includes/nav_helpers.php');
nav_render_floating_screen_exit($activeRoute);
require_once app_path('includes/app_busy.php');
app_busy_render_overlay();
?>
<script>try{sessionStorage.removeItem('manager:mdi-windows-v1');}catch(e){}</script>
<script>
(function () {
  var isElectron = !!(window.hypexDesktop && window.hypexDesktop.isElectron)
    || /\bElectron\//i.test(navigator.userAgent || '');
  if (isElectron) {
    document.documentElement.classList.add('hypex-desktop-app');
    document.body.classList.add('app-body--standalone', 'app-body--electron');
  }
  if (window.matchMedia('(display-mode: standalone)').matches
    || window.matchMedia('(display-mode: fullscreen)').matches) {
    document.body.classList.add('app-body--standalone');
  }
  if (!('windowControlsOverlay' in navigator)) return;
  function syncWco() {
    document.body.classList.toggle('app-body--wco', navigator.windowControlsOverlay.visible);
  }
  syncWco();
  navigator.windowControlsOverlay.addEventListener('geometrychange', syncWco);
})();
</script>
<script src="<?= esc(app_url('assets/js/app-format.js')) ?><?= $appFormatJsV !== '' ? '?v=' . esc($appFormatJsV) : '' ?>" defer></script>
<?php
$favJsPath = app_path('assets/js/favorites.js');
$favJsV = is_file($favJsPath) ? (string) filemtime($favJsPath) : '';
?>
<script src="<?= esc(app_url('assets/js/favorites.js')) ?><?= $favJsV !== '' ? '?v=' . esc($favJsV) : '' ?>" defer></script>
<?php
$appDecimalSyncJsV = is_file(app_path('assets/js/app-decimal-sync.js'))
    ? (string) filemtime(app_path('assets/js/app-decimal-sync.js'))
    : '';
?>
<script src="<?= esc(app_url('assets/js/app-decimal-sync.js')) ?><?= $appDecimalSyncJsV !== '' ? '?v=' . esc($appDecimalSyncJsV) : '' ?>" defer></script>
<script src="<?= esc(app_url('assets/js/document-header.js')) ?><?= $docHdrJsV !== '' ? '?v=' . esc($docHdrJsV) : '' ?>" defer></script>
<?php
$printOrientJsV = is_file(app_path('assets/js/print-orientation.js'))
    ? (string) filemtime(app_path('assets/js/print-orientation.js'))
    : '';
?>
<script src="<?= esc(app_url('assets/js/print-orientation.js')) ?><?= $printOrientJsV !== '' ? '?v=' . esc($printOrientJsV) : '' ?>" defer></script>
<script src="<?= esc(app_url('assets/js/document-no-nav.js')) ?><?= $docNoNavJsV !== '' ? '?v=' . esc($docNoNavJsV) : '' ?>" defer></script>
<script src="<?= esc(app_url('assets/js/ui-dialog.js')) ?><?= $uiDlgJsV !== '' ? '?v=' . esc($uiDlgJsV) : '' ?>" defer></script>
<script src="<?= esc(app_url('assets/js/app-header-ui.js')) ?><?= $appHeaderUiJsV !== '' ? '?v=' . esc($appHeaderUiJsV) : '' ?>" defer></script>
<?php if ($activeRoute === 'system_backup'): ?>
<?php
$sysBackupJsV = is_file(app_path('assets/js/sys-backup.js'))
    ? (string) filemtime(app_path('assets/js/sys-backup.js'))
    : '';
?>
<script src="<?= esc(app_url('assets/js/sys-backup.js')) ?><?= $sysBackupJsV !== '' ? '?v=' . esc($sysBackupJsV) : '' ?>" defer></script>
<?php endif; ?>
<script src="<?= esc(app_url('assets/js/screen-exit-guard.js')) ?><?= $screenExitGuardJsV !== '' ? '?v=' . esc($screenExitGuardJsV) : '' ?>" defer></script>
<?php if ($hrOracleUi): ?>
<script src="<?= esc(app_url('assets/js/hr-ora-unsaved.js')) ?><?= $hrOraUnsavedJsV !== '' ? '?v=' . esc($hrOraUnsavedJsV) : '' ?>" defer></script>
<?php endif; ?>
<script src="<?= esc(app_url('assets/js/master-toolbar.js')) ?>" defer></script>
<script src="<?= esc(app_url('assets/js/app-list-keyboard.js')) ?><?= $listKeyboardJsV !== '' ? '?v=' . esc($listKeyboardJsV) : '' ?>" defer></script>
<?php
$fieldNavJsV = is_file(app_path('assets/js/hx-field-nav.js'))
    ? (string) filemtime(app_path('assets/js/hx-field-nav.js'))
    : '';
?>
<script src="<?= esc(app_url('assets/js/hx-field-nav.js')) ?><?= $fieldNavJsV !== '' ? '?v=' . esc($fieldNavJsV) : '' ?>" defer></script>
<script src="<?= esc(app_url('assets/js/app.js')) ?>" defer></script>
<script src="<?= esc(app_url('assets/js/app-date-picker.js')) ?><?= $datePickerJsV !== '' ? '?v=' . esc($datePickerJsV) : '' ?>" defer></script>
<script src="<?= esc(app_url('assets/js/nav-prefetch.js')) ?><?= $navPrefetchJsV !== '' ? '?v=' . esc($navPrefetchJsV) : '' ?>" defer></script>
<?php if (!$layoutFocus): ?>
<script src="<?= esc(app_url('assets/js/sidebar-nav.js')) ?><?= $sidebarNavJsV !== '' ? '?v=' . esc($sidebarNavJsV) : '' ?>" defer></script>
<?php endif; ?>
<?php if ($headerCheckNotify['enabled'] ?? false): ?>
<script type="application/json" id="app-checks-json"><?= $headerCheckNotifyJson ?></script>
<?php render_header_check_notifications_modal(); ?>
<script src="<?= esc(app_url('assets/js/check-alerts-ui.js')) ?><?= $checkUiJsV !== '' ? '?v=' . esc($checkUiJsV) : '' ?>" defer></script>
<script src="<?= esc(app_url('assets/js/header-check-notifications.js')) ?><?= $checkBellJsV !== '' ? '?v=' . esc($checkBellJsV) : '' ?>" defer></script>
<?php endif; ?>
<?php if (app_gps_enabled()): ?>
<?php
require_once app_path('includes/app_osm.php');
$userGpsJsPath = app_path('assets/js/user-session-gps.js');
$userGpsJsV = is_file($userGpsJsPath) ? (string) filemtime($userGpsJsPath) : '';
$geoJsPath = app_path('assets/js/geo.js');
$geoJsV = is_file($geoJsPath) ? (string) filemtime($geoJsPath) : '';
$geoMapPickCssPath = app_path('assets/css/geo-map-pick.css');
$geoMapPickCssV = is_file($geoMapPickCssPath) ? (string) filemtime($geoMapPickCssPath) : '';
$geoMapPickJsPath = app_path('assets/js/geo-map-pick.js');
$geoMapPickJsV = is_file($geoMapPickJsPath) ? (string) filemtime($geoMapPickJsPath) : '';
$mapLayersJsPath = app_path('assets/js/leaflet-map-layers.js');
$mapLayersJsV = is_file($mapLayersJsPath) ? (string) filemtime($mapLayersJsPath) : '';
?>
<link rel="stylesheet" href="<?= esc(app_url('assets/css/geo-map-pick.css')) ?><?= $geoMapPickCssV !== '' ? '?v=' . esc($geoMapPickCssV) : '' ?>">
<script>
window.APP_GPS_ENABLED = true;
window.APP_GPS_SILENT_POST = <?= (defined('APP_GPS_SILENT_POST') && APP_GPS_SILENT_POST) ? 'true' : 'false' ?>;
window.AppOsmConfig = <?= json_encode(app_osm_js_config(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.UserSessionGpsConfig = {
    pingApi: <?= json_encode(app_url('api/user_location_ping.php'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    csrf: <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE) ?>,
    source: 'desktop',
    intervalMs: 600000,
    initialDelayMs: 45000,
    sampleMs: 12000,
    geoTimeoutMs: 8000,
    goodEnoughM: 30
};
(function () {
    var files = [
        <?= json_encode(app_url('assets/js/leaflet-map-layers.js') . ($mapLayersJsV !== '' ? '?v=' . $mapLayersJsV : ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        <?= json_encode(app_url('assets/js/geo-map-pick.js') . ($geoMapPickJsV !== '' ? '?v=' . $geoMapPickJsV : ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        <?= json_encode(app_url('assets/js/geo.js') . ($geoJsV !== '' ? '?v=' . $geoJsV : ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        <?= json_encode(app_url('assets/js/user-session-gps.js') . ($userGpsJsV !== '' ? '?v=' . $userGpsJsV : ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
    ];
    function loadGpsScripts() {
        files.forEach(function (src) {
            var s = document.createElement('script');
            s.src = src;
            s.defer = true;
            document.body.appendChild(s);
        });
    }
    function schedule() {
        if (window.requestIdleCallback) {
            window.requestIdleCallback(loadGpsScripts, { timeout: 4000 });
        } else {
            window.setTimeout(loadGpsScripts, 2000);
        }
    }
    if (document.readyState === 'complete') {
        schedule();
    } else {
        window.addEventListener('load', schedule, { once: true });
    }
})();
</script>
<?php else: ?>
<script>window.APP_GPS_ENABLED = false;</script>
<?php endif; ?>
<?= document_print_user_footer_html($printUserLabel) ?>
<?php
$appTypoBoldCssV = is_file(app_path('assets/css/app-typography-bold.css'))
    ? (string) filemtime(app_path('assets/css/app-typography-bold.css'))
    : '';
?>
<link rel="stylesheet" href="<?= esc(app_url('assets/css/app-typography-bold.css')) ?><?= $appTypoBoldCssV !== '' ? '?v=' . esc($appTypoBoldCssV) : '' ?>">
</body>
</html>
