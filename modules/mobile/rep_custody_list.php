<?php
declare(strict_types=1);

require_once app_path('includes/mobile_rep_custody.php');
require_once app_path('includes/mobile_icons.php');

if (!mobile_can_access_rep_custody_list()) {
    flash_set('error', 'لا توجد صلاحية لعرض قائمة العهدة.');
    redirect(mobile_url('r=m_home'));
}

$stripIcon = mobile_icon_svg('load');
$listApi = app_url('api/mobile_rep_custody_list.php');
$printApi = app_url('api/mobile_rep_transfer_print.php');
$pdfApi = app_url('api/mobile_rep_transfer_pdf.php');
$newUrl = mobile_url('r=m_rep_load');
$editUrl = mobile_url('r=m_rep_load');
$deleteApi = app_url('api/mobile_rep_transfer_delete.php');
$canDelete = mobile_can_delete_rep_custody('load');
$canArchive = mobile_can_archive_warehouse_move();
$archiveApiUrl = app_absolute_url('api/fin_voucher_archive.php');
$listJsV = is_file(app_path('assets/mobile/rep-custody-list.js'))
    ? (string) filemtime(app_path('assets/mobile/rep-custody-list.js'))
    : '';
?>
<div class="m-ora12 m-ora12-invoice">
<div class="m-ora12-workspace">
<div class="m-hub m-hub--list m-hub--rep-custody-list">
<div class="m-hub-strip m-hub-strip--rep" aria-hidden="true">
    <span class="m-hub-strip-badge">قائمة</span>
    <span class="m-hub-strip-hint">اختر عهدة ثم طباعة أو PDF أو أرشيف</span>
</div>
<button type="button" class="m-btn m-btn--primary m-btn--block m-hub-head-btn" id="m-rep-custody-list-new">+ تحميل عهدة جديدة</button>
<section class="m-ora12-panel m-ora12-list-panel m-rep-custody-list-page">
    <h2 class="m-ora12-panel__title">قائمة العهدة المستلمة</h2>
    <div class="m-ora12-panel__body">
    <div class="m-seg m-inv-list-filters" role="group" aria-label="تصفية">
        <label class="m-seg-item"><input type="radio" name="m_rep_custody_filter" value="all" checked> الكل</label>
        <label class="m-seg-item"><input type="radio" name="m_rep_custody_filter" value="unposted"> غير مرحّلة</label>
        <label class="m-seg-item"><input type="radio" name="m_rep_custody_filter" value="posted"> مرحّلة</label>
    </div>
    <div class="m-inv-list-search-row">
        <input class="m-input m-input--sm m-inv-list-search-input" type="search" id="m-rep-custody-list-search"
            placeholder="رقم السند..." autocomplete="off" enterkeyhint="search">
        <button type="button" class="m-btn m-btn--primary m-inv-list-search-btn" id="m-rep-custody-list-search-btn" aria-label="بحث">
            <svg class="m-inv-list-search-btn__ico" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">
                <path fill="currentColor" d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/>
            </svg>
            <span>بحث</span>
        </button>
    </div>
    <p class="m-inv-list-loading muted" id="m-rep-custody-list-loading">جاري التحميل...</p>
    <p class="m-inv-list-empty muted" id="m-rep-custody-list-empty" hidden>لا توجد عهود</p>
    <div id="m-rep-custody-list" class="m-inv-strip-list" role="list" aria-label="قائمة العهدة المستلمة"></div>
    </div>
</section>
</div>
</div>
</div>

<script>
window.MRepCustodyList = {
    listApi: <?= json_encode($listApi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    printApi: <?= json_encode($printApi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    pdfApi: <?= json_encode($pdfApi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    newUrl: <?= json_encode($newUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    editUrl: <?= json_encode($editUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    canEdit: true,
    deleteApi: <?= json_encode($deleteApi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    canDelete: <?= $canDelete ? 'true' : 'false' ?>,
    csrf: <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE) ?>,
    canArchive: <?= $canArchive ? 'true' : 'false' ?>,
    archiveApi: <?= json_encode($archiveApiUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    stripIconHtml: <?= json_encode($stripIcon, JSON_UNESCAPED_UNICODE) ?>
};
</script>
<?php
$gridJsV = is_file(app_path('assets/mobile/doc-list-grid.js'))
    ? (string) filemtime(app_path('assets/mobile/doc-list-grid.js'))
    : '';
$photoArchJsV = is_file(app_path('assets/mobile/invoice-photo-archive.js'))
    ? (string) filemtime(app_path('assets/mobile/invoice-photo-archive.js'))
    : '';
?>
<script src="<?= esc(app_url('assets/mobile/doc-list-grid.js')) ?><?= $gridJsV !== '' ? '?v=' . esc($gridJsV) : '' ?>" defer></script>
<?php if ($canArchive): ?>
<script src="<?= esc(app_url('assets/mobile/invoice-photo-archive.js')) ?><?= $photoArchJsV !== '' ? '?v=' . esc($photoArchJsV) : '' ?>" defer></script>
<?php endif; ?>
<script src="<?= esc(app_url('assets/mobile/rep-custody-list.js')) ?><?= $listJsV !== '' ? '?v=' . esc($listJsV) : '' ?>" defer></script>
