<?php
declare(strict_types=1);
/** @var string $pageTitle */
/** @var string $routeTitle */
/** @var string $content */
/** @var string $activeRoute */
/** @var bool $hrOracleUi */
/** @var bool $reportOracleUi */
/** @var bool $ora12PickerUi */
$hrOracleUi = $hrOracleUi ?? false;
$reportOracleUi = $reportOracleUi ?? false;
$ora12PickerUi = $ora12PickerUi ?? false;
$routeTitle = $routeTitle ?? '';

$settingsRow = ['company_name_ar' => 'النظام المحاسبي', 'logo_path' => null];
$appDecimalPlaces = 2;
$appInvoiceUnitPriceDecimals = 2;
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
    $appCurrency = company_currency();
} catch (Throwable $e) {
    // DB غير مهيأ بعد
}
$appFormatJsV = is_file(app_path('assets/js/app-format.js'))
    ? (string) filemtime(app_path('assets/js/app-format.js'))
    : '';

require_once app_path('includes/master_toolbar.php');
require_once app_path('includes/document_header.php');

$hasDocWatermark = !empty($settingsRow['logo_path']) && is_file(app_path((string) $settingsRow['logo_path']));
$docWatermarkRootCss = $hasDocWatermark ? document_print_watermark_root_css() : '';
$docWatermarkLogoUrl = $hasDocWatermark ? document_print_watermark_logo_url() : '';

$activeRoute = (string) ($activeRoute ?? 'dashboard');
$tabPageTitle = trim($pageTitle) !== '' ? $pageTitle : (string) ($routeTitle ?? '');
$browserTabTitle = app_browser_tab_title($tabPageTitle, $activeRoute, (string) ($settingsRow['company_name_ar'] ?? ''));
$printUserLabel = document_print_user_label();

$appCssV = is_file(app_path('assets/css/app.css')) ? (string) filemtime(app_path('assets/css/app.css')) : '';
$uiDlgCssV = is_file(app_path('assets/css/ui-dialog.css')) ? (string) filemtime(app_path('assets/css/ui-dialog.css')) : '';
$uiDlgJsV = is_file(app_path('assets/js/ui-dialog.js')) ? (string) filemtime(app_path('assets/js/ui-dialog.js')) : '';
$docHdrCssV = is_file(app_path('assets/css/document-header.css')) ? (string) filemtime(app_path('assets/css/document-header.css')) : '';
$docHdrJsV = is_file(app_path('assets/js/document-header.js')) ? (string) filemtime(app_path('assets/js/document-header.js')) : '';
$datePickerCssV = is_file(app_path('assets/css/app-date-picker.css')) ? (string) filemtime(app_path('assets/css/app-date-picker.css')) : '';
$datePickerJsV = is_file(app_path('assets/js/app-date-picker.js')) ? (string) filemtime(app_path('assets/js/app-date-picker.js')) : '';
$listKeyboardJsV = is_file(app_path('assets/js/app-list-keyboard.js'))
    ? (string) filemtime(app_path('assets/js/app-list-keyboard.js'))
    : '';
$screenExitGuardJsV = is_file(app_path('assets/js/screen-exit-guard.js'))
    ? (string) filemtime(app_path('assets/js/screen-exit-guard.js'))
    : '';
$docNoNavJsV = is_file(app_path('assets/js/document-no-nav.js'))
    ? (string) filemtime(app_path('assets/js/document-no-nav.js'))
    : '';
$hrOraUnsavedJsV = is_file(app_path('assets/js/hr-ora-unsaved.js'))
    ? (string) filemtime(app_path('assets/js/hr-ora-unsaved.js'))
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
$reportOra12CssV = is_file(app_path('assets/css/report-oracle12.css'))
    ? (string) filemtime(app_path('assets/css/report-oracle12.css'))
    : '';
$appDecimalSyncJsV = is_file(app_path('assets/js/app-decimal-sync.js'))
    ? (string) filemtime(app_path('assets/js/app-decimal-sync.js'))
    : '';

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($browserTabTitle) ?></title>
    <?php render_app_favicon_links($settingsRow); ?>
    <link rel="stylesheet" href="<?= esc(app_url('assets/css/app.css')) ?><?= $appCssV !== '' ? '?v=' . esc($appCssV) : '' ?>">
    <style>
      html { height: 100%; }
      body.app-body--embed {
        min-height: 100%;
        height: auto;
        overflow-x: hidden;
        overflow-y: auto;
      }
      body.app-body--embed.app-body--focus {
        overflow-x: hidden !important;
        overflow-y: auto !important;
        height: auto !important;
        min-height: 100%;
        max-height: none !important;
      }
      body.app-body--embed .app-embed-main {
        overflow: visible;
        min-height: calc(100% - 2.75rem);
      }
    </style>
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
    <?php if ($docWatermarkRootCss !== ''): ?>
    <style><?= $docWatermarkRootCss ?></style>
    <?php endif; ?>
    <script>
        window.APP_EMBED = true;
        window.AppCurrency = <?= json_encode([
            'code' => (string) ($appCurrency['code'] ?? 'SAR'),
            'name_ar' => (string) ($appCurrency['name_ar'] ?? ''),
            'main_ar' => (string) ($appCurrency['main_ar'] ?? 'ريالاً'),
            'fraction_ar' => (string) ($appCurrency['fraction_ar'] ?? 'هللة'),
            'symbol' => (string) ($appCurrency['symbol'] ?? ''),
            'fraction_units' => (int) ($appCurrency['fraction_units'] ?? 100),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
</head>
<body class="app-body app-body--embed app-body--focus<?= $hasDocWatermark ? ' has-doc-watermark' : '' ?><?= $hrOracleUi ? ' hr-ora-ui' : '' ?><?= $reportOracleUi ? ' report-ora12-ui' : '' ?><?= $ora12PickerUi ? ' ora12-picker-ui' : '' ?>" data-decimal-places="<?= (int) $appDecimalPlaces ?>" data-invoice-unit-price-decimals="<?= (int) $appInvoiceUnitPriceDecimals ?>"<?= $docWatermarkLogoUrl !== '' ? ' data-company-logo-url="' . esc($docWatermarkLogoUrl) . '"' : '' ?><?= $printUserLabel !== '' ? ' data-print-user="' . esc($printUserLabel) . '"' : '' ?>>
<header class="app-embed-head no-print" role="banner">
    <?php app_mdi_render_embed_minimize_btn(); ?>
    <?php render_master_toolbar(); ?>
</header>
<main class="app-embed-main">
    <?php
    require_once app_path('includes/report_oracle12_ui.php');
    report_ora12_layout_open($pageTitle, (string) $routeTitle, $activeRoute);
    echo $content;
    report_ora12_layout_close($activeRoute);
    ?>
</main>
<script src="<?= esc(app_url('assets/js/app-format.js')) ?><?= $appFormatJsV !== '' ? '?v=' . esc($appFormatJsV) : '' ?>" defer></script>
<script src="<?= esc(app_url('assets/js/app-decimal-sync.js')) ?><?= $appDecimalSyncJsV !== '' ? '?v=' . esc($appDecimalSyncJsV) : '' ?>" defer></script>
<script src="<?= esc(app_url('assets/js/document-header.js')) ?><?= $docHdrJsV !== '' ? '?v=' . esc($docHdrJsV) : '' ?>" defer></script>
<script src="<?= esc(app_url('assets/js/document-no-nav.js')) ?><?= $docNoNavJsV !== '' ? '?v=' . esc($docNoNavJsV) : '' ?>" defer></script>
<script src="<?= esc(app_url('assets/js/ui-dialog.js')) ?><?= $uiDlgJsV !== '' ? '?v=' . esc($uiDlgJsV) : '' ?>" defer></script>
<script src="<?= esc(app_url('assets/js/screen-exit-guard.js')) ?><?= $screenExitGuardJsV !== '' ? '?v=' . esc($screenExitGuardJsV) : '' ?>" defer></script>
<?php if ($hrOracleUi): ?>
<script src="<?= esc(app_url('assets/js/hr-ora-unsaved.js')) ?><?= $hrOraUnsavedJsV !== '' ? '?v=' . esc($hrOraUnsavedJsV) : '' ?>" defer></script>
<?php endif; ?>
<script src="<?= esc(app_url('assets/js/master-toolbar.js')) ?>" defer></script>
<script src="<?= esc(app_url('assets/js/app-list-keyboard.js')) ?><?= $listKeyboardJsV !== '' ? '?v=' . esc($listKeyboardJsV) : '' ?>" defer></script>
<script src="<?= esc(app_url('assets/js/app.js')) ?>" defer></script>
<script src="<?= esc(app_url('assets/js/app-date-picker.js')) ?><?= $datePickerJsV !== '' ? '?v=' . esc($datePickerJsV) : '' ?>" defer></script>
<script>
(function () {
  function navigateFromEmbed(href) {
    if (!href) {
      return;
    }
    try {
      if (window.top && window.top !== window) {
        window.top.location.href = href;
        return;
      }
    } catch (e) {
      /* ignore */
    }
    window.location.href = href;
  }

  document.addEventListener(
    'click',
    function (e) {
      var anchor = e.target && e.target.closest ? e.target.closest('a[href]') : null;
      if (!anchor || anchor.target === '_blank' || anchor.hasAttribute('download')) {
        return;
      }
      var href = anchor.getAttribute('href') || '';
      if (!href || href.charAt(0) === '#' || href.indexOf('index.php') < 0) {
        return;
      }
      var isExit =
        anchor.classList.contains('ora12-title-bar__close') ||
        anchor.classList.contains('hr-ora-title-bar__close') ||
        !!anchor.closest('.nav-exit-btn');
      try {
        var u = new URL(anchor.href, window.location.origin);
        var route = u.searchParams.get('r') || '';
        if (isExit || (route !== '' && route !== 'menu_hub' && route !== 'dashboard')) {
          e.preventDefault();
          e.stopImmediatePropagation();
          navigateFromEmbed(anchor.href);
          return;
        }
      } catch (err) {
        return;
      }
      if (anchor.classList.contains('nav-hub-ora-tile') && !anchor.classList.contains('nav-hub-ora-tile--folder')) {
        e.preventDefault();
        e.stopImmediatePropagation();
        navigateFromEmbed(anchor.href);
      }
    },
    true
  );
})();
</script>
<?= document_print_user_footer_html($printUserLabel) ?>
</body>
</html>
