<?php
declare(strict_types=1);

require_once app_path('includes/mobile_receipt.php');
require_once app_path('includes/mobile_icons.php');

$receiptStripIcon = mobile_icon_svg('receipt');

$listApi = app_url('api/mobile_receipts_list.php');
$printApi = app_url('api/mobile_receipt_print.php');
$viewUrl = mobile_url('r=m_receipt');
$newUrl = mobile_url('r=m_receipt');
$listJsV = is_file(app_path('assets/mobile/receipt-list.js'))
    ? (string) filemtime(app_path('assets/mobile/receipt-list.js'))
    : '';
?>
<div class="m-hub m-hub--list m-hub--receipt-list">
<div class="m-hub-strip m-hub-strip--receipt" aria-hidden="true">
    <span class="m-hub-strip-badge">قائمة</span>
    <span class="m-hub-strip-hint">اختر سنداً ثم طباعة أو PDF</span>
</div>
<section class="m-card m-card--hub-list m-rc-list-page">
    <div class="m-seg m-rc-list-filters" role="group" aria-label="تصفية">
        <label class="m-seg-item"><input type="radio" name="m_rc_filter" value="all" checked> الكل</label>
        <label class="m-seg-item"><input type="radio" name="m_rc_filter" value="unposted"> غير مرحّل</label>
        <label class="m-seg-item"><input type="radio" name="m_rc_filter" value="posted"> مرحّل</label>
    </div>
    <input class="m-input m-input--sm" type="search" id="m-rc-list-search" placeholder="بحث: رقم السند، العميل..." autocomplete="off">
    <p class="m-inv-list-loading muted" id="m-rc-list-loading">جاري التحميل...</p>
    <p class="m-inv-list-empty muted" id="m-rc-list-empty" hidden>لا توجد سندات</p>
    <div id="m-rc-list" class="m-rc-strip-list" role="list" aria-label="سندات القبض"></div>
</section>
<button type="button" class="m-btn m-btn--primary m-btn--block m-hub-foot-btn" id="m-rc-list-new">+ سند قبض جديد</button>
</div>

<div id="m-rc-list-pdf-overlay" class="m-inv-pdf-overlay" hidden aria-hidden="true">
    <div id="m-rc-list-pdf-preview" class="m-inv-pdf-preview"></div>
</div>

<script>
window.MReceiptList = {
    listApi: <?= json_encode($listApi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    printApi: <?= json_encode($printApi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    deleteApi: <?= json_encode(app_url('api/fin_receipt_delete.php'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    viewUrl: <?= json_encode($viewUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    newUrl: <?= json_encode($newUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    csrf: <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE) ?>,
    canDelete: <?= mobile_can_delete_receipt() ? 'true' : 'false' ?>,
    stripIconHtml: <?= json_encode($receiptStripIcon, JSON_UNESCAPED_UNICODE) ?>
};
</script>
<?php
$gridJsV = is_file(app_path('assets/mobile/doc-list-grid.js'))
    ? (string) filemtime(app_path('assets/mobile/doc-list-grid.js'))
    : '';
$pdfJsV = is_file(app_path('assets/mobile/pdf-export.js'))
    ? (string) filemtime(app_path('assets/mobile/pdf-export.js'))
    : '';
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" crossorigin="anonymous"></script>
<script src="<?= esc(app_url('assets/mobile/pdf-export.js')) ?><?= $pdfJsV !== '' ? '?v=' . esc($pdfJsV) : '' ?>" defer></script>
<script src="<?= esc(app_url('assets/mobile/doc-list-grid.js')) ?><?= $gridJsV !== '' ? '?v=' . esc($gridJsV) : '' ?>" defer></script>
<script src="<?= esc(app_url('assets/mobile/receipt-list.js')) ?><?= $listJsV !== '' ? '?v=' . esc($listJsV) : '' ?>" defer></script>
