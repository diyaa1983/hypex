<?php

declare(strict_types=1);



require_once app_path('includes/mobile_invoice.php');
require_once app_path('includes/app_gps.php');
require_once app_path('includes/company_settings.php');



$invoiceId = (int) ($_GET['id'] ?? 0);

if ($invoiceId < 1) {

    flash_set('error', 'معرّف الفاتورة غير صالح.');

    redirect(mobile_url('r=m_sales_invoice_list'));

}



$pdo = db();

$settings = company_settings($pdo);

$dp = company_decimal_places($pdo);

$companyName = (string) ($settings['company_name_ar'] ?? 'النظام المحاسبي');



$viewJsV = is_file(app_path('assets/mobile/invoice-view.js'))

    ? (string) filemtime(app_path('assets/mobile/invoice-view.js'))

    : '';

?>

<div class="m-ora12 m-ora12-invoice">
<div class="m-ora12-workspace">
<div id="m-inv-view-loading" class="m-inv-view-loading muted">جاري تحميل الفاتورة...</div>
<div id="m-inv-post-status" class="m-alert" hidden role="status" aria-live="polite"></div>
<?php if (app_gps_enabled() && mobile_can_post_sales_invoice()): ?>
<section class="m-ora12-panel m-inv-gps-place-panel no-print" id="m-inv-gps-place-panel">
    <h2 class="m-ora12-panel__title">موقع الترحيل (GPS)</h2>
    <div class="m-ora12-panel__body">
        <p class="muted m-inv-gps-place-hint">يُسجَّل موقعك تلقائياً عند الترحيل. يمكنك كتابة اسم الشارع أو العنوان للتوضيح.</p>
        <label class="m-field m-field--full">
            <span class="m-field-label">الشارع / العنوان (اختياري)</span>
            <input class="m-input" type="text" id="m-inv-gps-place" maxlength="500"
                   placeholder="مثال: شارع الملك حسين — عمان" autocomplete="off">
        </label>
    </div>
</section>
<?php endif; ?>

<div id="m-inv-view-root" class="m-inv-view-page" hidden>

    <section class="m-inv-view-doc" aria-label="بيانات الفاتورة">

        <div class="m-inv-view-doc-head">

            <div class="m-inv-view-no" id="m-inv-view-no"></div>

            <div class="m-inv-view-tags" id="m-inv-view-tags"></div>

        </div>

        <dl class="m-inv-view-facts">

            <div class="m-inv-view-fact">

                <dt>التاريخ</dt>

                <dd id="m-inv-view-date"></dd>

            </div>

            <div class="m-inv-view-fact m-inv-view-fact--wide">

                <dt>العميل</dt>

                <dd id="m-inv-view-customer"></dd>

            </div>

            <div class="m-inv-view-fact">

                <dt>نوع الدفع</dt>

                <dd id="m-inv-view-payment"></dd>

            </div>

            <div class="m-inv-view-fact" id="m-inv-view-wh-wrap" hidden>

                <dt>المستودع</dt>

                <dd id="m-inv-view-warehouse"></dd>

            </div>

            <div class="m-inv-view-fact" id="m-inv-view-rep-wrap" hidden>

                <dt>المندوب</dt>

                <dd id="m-inv-view-rep"></dd>

            </div>

            <div class="m-inv-view-fact m-inv-view-fact--wide" id="m-inv-view-notes-wrap" hidden>

                <dt>ملاحظات</dt>

                <dd id="m-inv-view-notes"></dd>

            </div>

        </dl>

        <div class="m-inv-view-totals" aria-label="المجاميع">

            <div class="m-inv-view-total-row"><span>قبل الضريبة</span><span id="m-inv-view-sub"></span></div>

            <div class="m-inv-view-total-row"><span>الضريبة</span><span id="m-inv-view-tax"></span></div>

            <div class="m-inv-view-total-row m-inv-view-total-row--grand"><span>الإجمالي</span><strong id="m-inv-view-grand"></strong></div>

            <div class="m-inv-view-lines-count muted" id="m-inv-view-lines-count"></div>

        </div>

    </section>



    <section class="m-inv-view-lines-panel" aria-label="بنود الفاتورة">

        <h3 class="m-inv-view-lines-title">بنود المواد</h3>

        <div class="m-inv-view-lines-scroll" id="m-inv-view-lines-scroll">
            <div id="m-inv-view-lines" class="m-inv-view-lines-list" role="list"></div>
        </div>

    </section>



    <div class="m-inv-view-extra">
        <a class="m-btn m-btn--ghost" href="<?= esc(mobile_url('r=m_sales_invoices')) ?>">جديد</a>
        <a class="m-btn m-btn--ghost" href="<?= esc(mobile_url('r=m_sales_invoice_list')) ?>">القائمة</a>
    </div>

</div>
</div>
</div>

<div id="m-inv-print-host" hidden></div>

<div id="m-inv-pdf-overlay" class="m-inv-pdf-overlay" hidden aria-hidden="true">

    <div id="m-inv-pdf-preview" class="m-inv-pdf-preview"></div>

</div>



<script>

    window.MInvoiceView = {

        invoiceId: <?= (int) $invoiceId ?>,

        invoiceApi: <?= json_encode(app_url('api/sales_invoice_view.php'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,

        printApi: <?= json_encode(app_url('api/mobile_invoice_print.php'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,

        einvoiceApi: <?= json_encode(app_url('api/sales_einvoice_send.php'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,

        editUrl: <?= json_encode(mobile_url('r=m_sales_invoices'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,

        deleteApi: <?= json_encode(app_url('api/sales_invoice_delete.php'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,

        postApi: <?= json_encode(app_url('api/sales_invoice_post.php'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,

        listUrl: <?= json_encode(mobile_url('r=m_sales_invoice_list'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,

        csrf: <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE) ?>,

        decimalPlaces: <?= (int) $dp ?>,

        companyName: <?= json_encode($companyName, JSON_UNESCAPED_UNICODE) ?>,

        canSendEinvoice: <?= mobile_can_send_sales_einvoice() ? 'true' : 'false' ?>,

        canEdit: <?= mobile_can_edit_sales_invoice() ? 'true' : 'false' ?>,

        canDelete: <?= mobile_can_delete_sales_invoice() ? 'true' : 'false' ?>,

        canPost: <?= mobile_can_post_sales_invoice() ? 'true' : 'false' ?>,

        gpsEnabled: <?= app_gps_enabled() ? 'true' : 'false' ?>

    };

</script>
<script src="<?= esc(app_url('assets/mobile/invoice-view.js')) ?><?= $viewJsV !== '' ? '?v=' . esc($viewJsV) : '' ?>" defer></script>

