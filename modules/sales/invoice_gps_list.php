<?php

declare(strict_types=1);



require_once app_path('includes/sal_invoice_gps.php');

require_once app_path('includes/sal_gps_list_ui.php');

require_once app_path('includes/sales_oracle12_ui.php');

require_once app_path('includes/nav_helpers.php');

require_once app_path('includes/document_header.php');



$pdo = db();

sal_invoice_gps_ensure_schema($pdo);



$submitted = sal_gps_list_is_submitted();

$formDates = sal_gps_list_initial_form_dates();

$search = '';

$dates = [

    'from_dmy' => $formDates['from_dmy'],

    'to_dmy' => $formDates['to_dmy'],

    'from' => app_default_date_from(),

    'to' => app_default_date_to(),

    'error' => '',

];

$dateErr = '';

$rows = [];

$showResults = false;

$listUrl = sal_gps_list_build_query_url('sales_invoice_gps', $dates['from_dmy'], $dates['to_dmy']);

$exitUrl = nav_exit_url('sales_invoice_gps');

if (!$submitted) {
    $search = trim((string) ($_GET['q'] ?? ''));
    $dates = sal_gps_list_parse_dates(null, null);
    $dateErr = (string) ($dates['error'] ?? '');
    if ($dateErr === '') {
        $rows = sal_invoice_gps_list_rows($pdo, $search, 500, $dates['from'], $dates['to']);
        $showResults = true;
    }
}

if ($submitted) {

    $search = trim((string) ($_GET['q'] ?? ''));

    $dates = sal_gps_list_parse_dates(

        isset($_GET['date_from']) ? (string) $_GET['date_from'] : null,

        isset($_GET['date_to']) ? (string) $_GET['date_to'] : null

    );

    $dateErr = (string) ($dates['error'] ?? '');

    $listUrl = sal_gps_list_build_query_url('sales_invoice_gps', $dates['from_dmy'], $dates['to_dmy'], $search);

    if ($dateErr === '') {
        $rows = sal_invoice_gps_list_rows($pdo, $search, 500, $dates['from'], $dates['to']);
        $showResults = true;
    }

}

$flash = flash_get();

$reportTitle = 'إحداثيات مواقع فواتير البيع';



$gpsCssPath = app_path('assets/css/sal-invoice-gps.css');

$gpsCssUrl = app_url('assets/css/sal-invoice-gps.css')

    . (is_file($gpsCssPath) ? '?v=' . (string) filemtime($gpsCssPath) : '');

$gpsListJsPath = app_path('assets/js/sal-gps-list.js');

$gpsListJsUrl = app_url('assets/js/sal-gps-list.js')

    . (is_file($gpsListJsPath) ? '?v=' . (string) filemtime($gpsListJsPath) : '');



?>

<?php sales_ora12_enqueue_assets(); ?>
<?php sal_gps_list_enqueue_print_assets(); ?>

<link rel="stylesheet" href="<?= esc($gpsCssUrl) ?>">



<div class="dashboard-ora sales-ora12-screen sales-ora-list-page sal-invoice-gps-page sal-gps-list-page"

     data-exit-url="<?= esc($exitUrl) ?>"

     data-report-title="<?= esc($reportTitle) ?>">

    <?php sales_ora12_render_title_bar($reportTitle); ?>

    <?php sales_ora12_workspace_open(); ?>

    <?php if ($flash): ?>

        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> sales-ora-flash no-print"><?= esc($flash['message']) ?></div>

    <?php endif; ?>

    <?php if ($dateErr !== ''): ?>

        <div class="alert alert-error sales-ora-flash no-print"><?= esc($dateErr) ?></div>

    <?php endif; ?>



    <div class="sales-ora-panel card no-print">

        <?php sal_gps_list_render_filter_form(

            'sales_invoice_gps',

            $search,

            $dates['from_dmy'],

            $dates['to_dmy'],

            'رقم فاتورة، عميل، منطقة، معلم…'

        ); ?>

    </div>



    <div class="sales-ora-panel card sal-gps-list-results">

        <div class="report-sales-result report-sales-print-area sal-gps-list-print-area" id="sal-gps-list-print">

            <?php if (!$showResults): ?>

                <?php sal_gps_list_render_pending_message(); ?>

            <?php else: ?>

            <?= sal_gps_list_print_header_html($reportTitle, $pdo, $dates['from_dmy'], $dates['to_dmy']) ?>

            <?php sal_gps_list_render_print_meta(count($rows), $search); ?>



            <?php if ($rows === []): ?>

                <p class="muted sal-gps-empty-msg">لا توجد فواتير مرحّلة في الفترة المحددة.</p>

            <?php else: ?>

                <p class="sal-gps-intro no-print">الفواتير المرحّلة — اضغط GPS لتسجيل موقع أو فتح الخريطة. للفواتير بدون موقع اضغط زر <strong>GPS</strong> من نفس الجهاز.</p>

                <div class="report-sales-table-wrap table-wrap">

                    <table class="data-table sal-invoice-gps-table sal-gps-report-table" id="sal-invoice-gps-table">

                        <colgroup>
                            <col class="col-seq">
                            <col class="col-map">
                            <col class="col-place">
                            <col class="col-src">
                            <col class="col-user">
                            <col class="col-customer">
                            <col class="col-invoice">
                            <col class="col-date">
                        </colgroup>

                        <thead>

                            <tr>

                                <th class="col-seq">#</th>

                                <th class="col-map no-print-col">GPS</th>

                                <th class="col-place">الموقع</th>

                                <th class="col-src">المصدر</th>

                                <th class="col-user">المستخدم</th>

                                <th class="col-customer">العميل</th>

                                <th class="col-invoice">الفاتورة</th>

                                <th class="col-date">التاريخ</th>

                            </tr>

                        </thead>

                        <tbody>

                            <?php $seq = 0; ?>

                            <?php foreach ($rows as $row): ?>

                            <?php $seq++; ?>

                            <tr class="sal-gps-list-row">

                                <td class="col-seq"><?= $seq ?></td>

                                <td class="col-map no-print-col">

                                    <?php if (!empty($row['has_gps'])): ?>
                                    <?= sal_invoice_gps_map_button_html($row) ?>
                                    <button type="button" class="btn btn-sm sal-gps-attach-btn sal-gps-replace-btn no-print"
                                            data-invoice-id="<?= (int) ($row['id'] ?? 0) ?>"
                                            data-invoice-no="<?= esc((string) ($row['invoice_no'] ?? '')) ?>"
                                            data-force="1"
                                            title="تصحيح موقع الفاتورة">تصحيح</button>
                                    <?php else: ?>
                                    <button type="button" class="btn btn-sm sal-gps-attach-btn no-print"
                                            data-invoice-id="<?= (int) ($row['id'] ?? 0) ?>"
                                            data-invoice-no="<?= esc((string) ($row['invoice_no'] ?? '')) ?>"
                                            title="تسجيل موقع هذا الجهاز على الفاتورة">GPS</button>
                                    <?php endif; ?>

                                    <?php if (!empty($row['accuracy_label'])): ?>

                                        <span class="sal-gps-accuracy no-print">دقة ±<?= esc((string) $row['accuracy_label']) ?></span>

                                    <?php endif; ?>

                                </td>

                                <td class="col-place">

                                    <?php if (!empty($row['place_label'])): ?>

                                        <?= sal_invoice_gps_place_link_html(
                                            (float) ($row['post_latitude'] ?? 0),
                                            (float) ($row['post_longitude'] ?? 0),
                                            (string) $row['place_label']
                                        ) ?>

                                    <?php else: ?>

                                        <span class="muted sal-gps-place-pending no-print">يُحدَّد عند فتح الخريطة</span>

                                    <?php endif; ?>

                                    <?php if (!empty($row['landmark_label'])): ?>

                                        <span class="sal-gps-landmark-label"><?= esc((string) $row['landmark_label']) ?></span>

                                    <?php endif; ?>

                                </td>

                                <td class="col-src">

                                    <span class="sal-gps-src <?= esc((string) ($row['source_badge_class'] ?? 'sal-gps-src--desktop')) ?>">

                                        <?= esc((string) ($row['source_label'] ?? 'Windows')) ?>

                                    </span>

                                </td>

                                <td class="col-user">

                                    <?php if (!empty($row['user_label'])): ?>

                                        <span class="sal-gps-user-label"><?= esc((string) $row['user_label']) ?></span>

                                        <?php if (!empty($row['user_label_sub'])): ?>

                                            <span class="sal-gps-user-sub"><?= esc((string) $row['user_label_sub']) ?></span>

                                        <?php endif; ?>

                                    <?php else: ?>

                                        <span class="muted">—</span>

                                    <?php endif; ?>

                                </td>

                                <td class="col-customer"><?= esc((string) ($row['customer_name'] ?? '')) ?></td>

                                <td class="col-invoice"><code><?= esc((string) ($row['invoice_no'] ?? '')) ?></code></td>

                                <td class="col-date">

                                    <?= esc((string) ($row['invoice_date_dmy'] ?? '')) ?>

                                    <?php if (!empty($row['gps_at_dmy'])): ?>

                                        <span class="sal-gps-time-muted"><?= esc((string) $row['gps_at_dmy']) ?></span>

                                    <?php endif; ?>

                                </td>

                            </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

            <?php endif; ?>

        </div>

    </div>

    <?php sales_ora12_workspace_close(); ?>

</div>



<?php

require_once app_path('includes/sal_invoice_gps_map_modal.php');

echo sal_invoice_gps_map_modal_html();

$gpsMapJsPath = app_path('assets/js/sal-invoice-gps-map.js');

$gpsMapJsUrl = app_url('assets/js/sal-invoice-gps-map.js')

    . (is_file($gpsMapJsPath) ? '?v=' . (string) filemtime($gpsMapJsPath) : '');

$geocodeApi = app_url('api/sales_invoice_gps_geocode.php');

?>

<script>

window.SalInvoiceGpsMapConfig = {

    geocodeApi: <?= json_encode($geocodeApi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>

};

window.SalGpsListConfig = {
    attachApi: <?= json_encode(app_url('api/sales_invoice_gps_attach.php'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    csrf: <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE) ?>
};

</script>

<script src="<?= esc($gpsMapJsUrl) ?>" defer></script>

<script src="<?= esc($gpsListJsUrl) ?>" defer></script>


