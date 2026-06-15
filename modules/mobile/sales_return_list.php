<?php
declare(strict_types=1);

require_once app_path('includes/mobile_return.php');
require_once app_path('includes/mobile_icons.php');

$returnStripIcon = mobile_icon_svg('return-list');

$listApi = app_url('api/mobile_returns_list.php');
$printApi = app_url('api/mobile_return_print.php');
$viewUrl = mobile_url('r=m_sales_returns');
$newUrl = mobile_url('r=m_sales_returns');
$listJsV = is_file(app_path('assets/mobile/return-list.js'))
    ? (string) filemtime(app_path('assets/mobile/return-list.js'))
    : '';
?>
<div class="m-hub m-hub--list m-hub--return-list">
<div class="m-hub-strip m-hub-strip--return" aria-hidden="true">
    <span class="m-hub-strip-badge">قائمة</span>
    <span class="m-hub-strip-hint">اختر مرتجعاً للعرض أو الترحيل</span>
</div>
<section class="m-card m-card--hub-list m-ret-list-page">
    <div class="m-seg m-ret-list-filters" role="group" aria-label="تصفية">
        <label class="m-seg-item"><input type="radio" name="m_ret_filter" value="all" checked> الكل</label>
        <label class="m-seg-item"><input type="radio" name="m_ret_filter" value="unposted"> غير مرحّل</label>
        <label class="m-seg-item"><input type="radio" name="m_ret_filter" value="posted"> مرحّل</label>
    </div>
    <input class="m-input m-input--sm" type="search" id="m-ret-list-search" placeholder="بحث: رقم المرتجع، الفاتورة، العميل..." autocomplete="off">
    <p class="m-inv-list-loading muted" id="m-ret-list-loading">جاري التحميل...</p>
    <p class="m-inv-list-empty muted" id="m-ret-list-empty" hidden>لا توجد مرتجعات</p>
    <div id="m-ret-list" class="m-ret-strip-list" role="list" aria-label="مرتجعات المبيعات"></div>
</section>
<button type="button" class="m-btn m-btn--primary m-btn--block m-hub-foot-btn" id="m-ret-list-new">+ مرتجع جديد</button>
</div>

<div id="m-ret-list-pdf-overlay" class="m-inv-pdf-overlay" hidden aria-hidden="true">
    <div id="m-ret-list-pdf-preview" class="m-inv-pdf-preview"></div>
</div>

<script>
window.MReturnList = {
    listApi: <?= json_encode($listApi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    printApi: <?= json_encode($printApi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    viewUrl: <?= json_encode($viewUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    newUrl: <?= json_encode($newUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    deleteApi: <?= json_encode(app_url('api/sales_return_delete.php'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    postApi: <?= json_encode(app_url('api/sales_return_post.php'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    csrf: <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE) ?>,
    canDelete: <?= mobile_can_delete_sales_return() ? 'true' : 'false' ?>,
    canPost: <?= mobile_can_post_sales_return() ? 'true' : 'false' ?>,
    canEdit: <?= mobile_can_edit_sales_return() ? 'true' : 'false' ?>,
    stripIconHtml: <?= json_encode($returnStripIcon, JSON_UNESCAPED_UNICODE) ?>
};
</script>
<?php
$gridJsV = is_file(app_path('assets/mobile/doc-list-grid.js'))
    ? (string) filemtime(app_path('assets/mobile/doc-list-grid.js'))
    : '';
?>
<script src="<?= esc(app_url('assets/mobile/doc-list-grid.js')) ?><?= $gridJsV !== '' ? '?v=' . esc($gridJsV) : '' ?>" defer></script>
<?php
$pdfJsV = is_file(app_path('assets/mobile/pdf-export.js'))
    ? (string) filemtime(app_path('assets/mobile/pdf-export.js'))
    : '';
$pdfFnJsV = is_file(app_path('assets/mobile/pdf-filename.js'))
    ? (string) filemtime(app_path('assets/mobile/pdf-filename.js'))
    : '';
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" crossorigin="anonymous"></script>
<script src="<?= esc(app_url('assets/mobile/pdf-filename.js')) ?><?= $pdfFnJsV !== '' ? '?v=' . esc($pdfFnJsV) : '' ?>" defer></script>
<script src="<?= esc(app_url('assets/mobile/pdf-export.js')) ?><?= $pdfJsV !== '' ? '?v=' . esc($pdfJsV) : '' ?>" defer></script>
<script src="<?= esc(app_url('assets/mobile/return-list.js')) ?><?= $listJsV !== '' ? '?v=' . esc($listJsV) : '' ?>" defer></script>
