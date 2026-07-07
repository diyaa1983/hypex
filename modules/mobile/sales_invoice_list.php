<?php
declare(strict_types=1);

require_once app_path('includes/mobile_invoice.php');
require_once app_path('includes/mobile_icons.php');

$invoiceStripIcon = mobile_icon_svg('invoice');

$listApi = app_url('api/sales_invoices_list.php');
$printApi = app_url('api/mobile_invoice_print.php');
$viewUrl = mobile_url('r=m_sales_invoice_view');
$newUrl = mobile_url('r=m_sales_invoices');
$listJsV = is_file(app_path('assets/mobile/invoice-list.js'))
    ? (string) filemtime(app_path('assets/mobile/invoice-list.js'))
    : '';
?>
<div class="m-ora12 m-ora12-invoice">
<div class="m-ora12-workspace">
<div class="m-hub m-hub--list m-hub--invoice-list">
<div class="m-hub-strip m-hub-strip--invoice" aria-hidden="true">
    <span class="m-hub-strip-badge">قائمة</span>
    <span class="m-hub-strip-hint">اختر فاتورة ثم طباعة أو PDF</span>
</div>
<button type="button" class="m-btn m-btn--primary m-btn--block m-hub-head-btn" id="m-inv-list-new">+ فاتورة جديدة</button>
<section class="m-ora12-panel m-ora12-list-panel m-inv-list-page">
    <h2 class="m-ora12-panel__title">قائمة الفواتير</h2>
    <div class="m-ora12-panel__body">
    <div class="m-seg m-inv-list-filters" role="group" aria-label="تصفية">
        <label class="m-seg-item"><input type="radio" name="m_inv_filter" value="all" checked> الكل</label>
        <label class="m-seg-item"><input type="radio" name="m_inv_filter" value="unposted"> غير مرحّلة</label>
        <label class="m-seg-item"><input type="radio" name="m_inv_filter" value="posted"> مرحّلة</label>
    </div>
    <div class="m-inv-list-search-row">
        <input class="m-input m-input--sm m-inv-list-search-input" type="search" id="m-inv-list-search"
            placeholder="رقم الفاتورة أو اسم العميل..." autocomplete="off" enterkeyhint="search">
        <button type="button" class="m-btn m-btn--primary m-inv-list-search-btn" id="m-inv-list-search-btn" aria-label="بحث">
            <svg class="m-inv-list-search-btn__ico" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">
                <path fill="currentColor" d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/>
            </svg>
            <span>بحث</span>
        </button>
    </div>
    <p class="m-inv-list-loading muted" id="m-inv-list-loading">جاري التحميل...</p>
    <p class="m-inv-list-empty muted" id="m-inv-list-empty" hidden>لا توجد فواتير</p>
    <div id="m-inv-list" class="m-inv-strip-list" role="list" aria-label="فواتير المبيعات"></div>
    </div>
</section>
</div>
</div>
</div>

<div id="m-inv-list-pdf-overlay" class="m-inv-pdf-overlay" hidden aria-hidden="true">
    <div id="m-inv-list-pdf-preview" class="m-inv-pdf-preview"></div>
</div>

<script>
window.MInvoiceList = {
    listApi: <?= json_encode($listApi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    printApi: <?= json_encode($printApi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    deleteApi: <?= json_encode(app_url('api/sales_invoice_delete.php'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    viewUrl: <?= json_encode($viewUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    newUrl: <?= json_encode($newUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    csrf: <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE) ?>,
    canDelete: <?= mobile_can_delete_sales_invoice() ? 'true' : 'false' ?>,
    canEdit: <?= mobile_can_edit_sales_invoice() ? 'true' : 'false' ?>,
    editUrl: <?= json_encode(mobile_url('r=m_sales_invoices'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    stripIconHtml: <?= json_encode($invoiceStripIcon, JSON_UNESCAPED_UNICODE) ?>
};
</script>
<?php
$gridJsV = is_file(app_path('assets/mobile/doc-list-grid.js'))
    ? (string) filemtime(app_path('assets/mobile/doc-list-grid.js'))
    : '';
?>
<script src="<?= esc(app_url('assets/mobile/doc-list-grid.js')) ?><?= $gridJsV !== '' ? '?v=' . esc($gridJsV) : '' ?>" defer></script>
<script src="<?= esc(app_url('assets/mobile/invoice-list.js')) ?><?= $listJsV !== '' ? '?v=' . esc($listJsV) : '' ?>" defer></script>
