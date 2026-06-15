<?php

declare(strict_types=1);



require_once app_path('includes/mobile_invoice.php');

require_once app_path('includes/sal_gps_list_ui.php');



$listApi = app_url('api/sales_invoice_gps_list.php');

$listJsV = is_file(app_path('assets/mobile/invoice-gps-list.js'))

    ? (string) filemtime(app_path('assets/mobile/invoice-gps-list.js'))

    : '';

$gpsCssPath = app_path('assets/css/sal-invoice-gps.css');

$gpsCssV = is_file($gpsCssPath) ? (string) filemtime($gpsCssPath) : '';

$fromDefault = sal_gps_list_default_from_dmy();

$toDefault = sal_gps_list_default_to_dmy();

?>

<link rel="stylesheet" href="<?= esc(app_url('assets/css/sal-invoice-gps.css')) ?><?= $gpsCssV !== '' ? '?v=' . esc($gpsCssV) : '' ?>">

<div class="m-ora12 m-ora12-invoice">

<div class="m-ora12-workspace">

<div class="m-hub m-hub--list m-hub--invoice-gps">

<div class="m-hub-strip m-hub-strip--invoice" aria-hidden="true">

    <span class="m-hub-strip-badge">مواقع</span>

    <span class="m-hub-strip-hint">اضغط أيقونة GPS لعرض الموقع على الخريطة</span>

</div>

<section class="m-ora12-panel m-ora12-list-panel m-inv-gps-page">

    <h2 class="m-ora12-panel__title">إحداثيات مواقع فواتير البيع</h2>

    <div class="m-ora12-panel__body">

    <div class="m-inv-gps-filters m-meta-grid">

        <label class="m-field">

            <span class="m-field-label">من تاريخ</span>

            <input class="m-input m-input--sm" type="text" id="m-inv-gps-from" inputmode="numeric"

                   placeholder="يوم-شهر-سنة" dir="ltr" value="<?= esc($fromDefault) ?>">

        </label>

        <label class="m-field">

            <span class="m-field-label">إلى تاريخ</span>

            <input class="m-input m-input--sm" type="text" id="m-inv-gps-to" inputmode="numeric"

                   placeholder="يوم-شهر-سنة" dir="ltr" value="<?= esc($toDefault) ?>">

        </label>

        <label class="m-field m-field--full">

            <span class="m-field-label">بحث</span>

            <input class="m-input m-input--sm" type="search" id="m-inv-gps-search"

                   placeholder="رقم الفاتورة، العميل، المنطقة..." autocomplete="off">

        </label>

        <div class="m-field m-field--full">
            <button type="button" class="m-btn m-btn--primary m-btn--block" id="m-inv-gps-show">عرض</button>
        </div>

    </div>

    <p class="muted sal-gps-pending-msg" id="m-inv-gps-pending">حدّد الفترة واضغط «عرض» لعرض البيانات.</p>

    <p class="m-inv-list-loading muted" id="m-inv-gps-loading" hidden>جاري التحميل...</p>

    <p class="m-inv-list-empty muted" id="m-inv-gps-empty" hidden>لا توجد فواتير بإحداثيات في الفترة المحددة</p>

    <div class="m-inv-gps-table-wrap" id="m-inv-gps-table-wrap" hidden>

        <table class="m-inv-gps-table" id="m-inv-gps-table">

            <thead>

                <tr>

                    <th class="m-inv-gps-th-map">GPS</th>

                    <th>الموقع</th>

                    <th>المصدر</th>

                    <th>المستخدم</th>

                    <th>العميل</th>

                    <th>الفاتورة</th>

                    <th>التاريخ</th>

                </tr>

            </thead>

            <tbody id="m-inv-gps-tbody"></tbody>

        </table>

    </div>

    </div>

</section>

</div>

</div>

</div>



<?php

require_once app_path('includes/sal_invoice_gps_map_modal.php');

$gpsMapJsPath = app_path('assets/js/sal-invoice-gps-map.js');

$gpsMapJsV = is_file($gpsMapJsPath) ? (string) filemtime($gpsMapJsPath) : '';

?>

<script>

window.MInvoiceGpsList = {

    listApi: <?= json_encode($listApi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>

};

window.SalInvoiceGpsMapConfig = {

    geocodeApi: <?= json_encode(app_url('api/sales_invoice_gps_geocode.php'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>

};

</script>

<?= sal_invoice_gps_map_modal_html() ?>

<script src="<?= esc(app_url('assets/js/sal-invoice-gps-map.js')) ?><?= $gpsMapJsV !== '' ? '?v=' . esc($gpsMapJsV) : '' ?>" defer></script>

<script src="<?= esc(app_url('assets/mobile/invoice-gps-list.js')) ?><?= $listJsV !== '' ? '?v=' . esc($listJsV) : '' ?>" defer></script>


