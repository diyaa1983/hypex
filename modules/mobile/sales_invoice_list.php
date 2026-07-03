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
    <input class="m-input m-input--sm" type="search" id="m-inv-list-search" placeholder="بحث: رقم الفاتورة، العميل..." autocomplete="off">
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
