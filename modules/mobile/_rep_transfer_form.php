<?php
declare(strict_types=1);

/** @var string $repTransferDirection load|return */
/** @var string $repTransferRoute m_rep_load|m_rep_return */
/** @var string $repTransferTitle */
/** @var string $repTransferHint */
/** @var string $repTransferIconKey */

require_once app_path('includes/mobile_auth.php');
require_once app_path('includes/mobile_rep_custody.php');
require_once app_path('includes/mobile_icons.php');
require_once app_path('includes/inv_wh_move_schema.php');

$pdo = db();
inv_wh_move_ensure_schema($pdo);
$ctx = mobile_rep_custody_context($pdo);
$flash = flash_get();
$today = format_date_dmY(date('Y-m-d'));
$previewMoveNo = mobile_rep_custody_preview_next_move_no($pdo);
$bagIcon = mobile_icon_svg('bag');
$stockIcon = mobile_icon_svg('stock');
$itemsApi = app_url('api/mobile_rep_items.php?direction=' . rawurlencode($repTransferDirection));
$saveApi = app_url('api/mobile_rep_transfer.php');
$viewApi = app_url('api/mobile_rep_transfer_view.php');
$printApi = app_url('api/mobile_rep_transfer_print.php');
$pdfApi = app_url('api/mobile_rep_transfer_pdf.php');
$stockUrl = mobile_url('r=m_rep_stock');
$custodyListUrl = mobile_url('r=m_rep_custody_list');
$editMoveId = (int) ($_GET['id'] ?? 0);
$canArchiveMove = mobile_can_archive_warehouse_move();
$archiveApiUrl = app_absolute_url('api/fin_voucher_archive.php');
$jsV = is_file(app_path('assets/mobile/rep-transfer.js'))
    ? (string) filemtime(app_path('assets/mobile/rep-transfer.js'))
    : '';

$fromLabel = $repTransferDirection === 'return'
    ? (string) ($ctx['van_warehouse_name'] ?? '—')
    : (string) ($ctx['main_warehouse_name'] ?? '—');
$toLabel = $repTransferDirection === 'return'
    ? (string) ($ctx['main_warehouse_name'] ?? '—')
    : (string) ($ctx['van_warehouse_name'] ?? '—');
?>
<?php if ($flash): ?>
<div class="m-alert m-alert--<?= esc($flash['type'] === 'error' ? 'error' : 'success') ?>">
    <?= esc($flash['message']) ?>
</div>
<?php endif; ?>

<?php if ($ctx === null): ?>
<div class="m-alert m-alert--error">
    حسابك غير مربوط بمندوب نشط أو مستودع عهدة.
</div>
<?php return; endif; ?>

<div class="m-rep-topbar">
    <button type="button" class="m-rep-topbar-btn m-rep-topbar-btn--bag" id="m-rep-bag-fab" aria-label="سلة العهدة">
        <span class="m-rep-topbar-ico" aria-hidden="true"><?= $bagIcon ?></span>
        <span class="m-rep-topbar-label">السلة</span>
        <span class="m-rep-bag-badge" id="m-rep-bag-count" hidden>0</span>
    </button>
    <a class="m-rep-topbar-btn m-rep-topbar-btn--pdf" id="m-rep-topbar-pdf" href="#" target="_blank" rel="noopener" hidden>PDF</a>
    <a class="m-rep-topbar-btn m-rep-topbar-btn--stock" href="<?= esc($stockUrl) ?>">
        <span class="m-rep-topbar-ico" aria-hidden="true"><?= $stockIcon ?></span>
        <span class="m-rep-topbar-label">رصيد العهدة</span>
    </a>
    <?php if (mobile_can_access_rep_custody_list()): ?>
    <a class="m-rep-topbar-btn m-rep-topbar-btn--list" href="<?= esc($custodyListUrl) ?>">
        <span class="m-rep-topbar-ico" aria-hidden="true"><?= mobile_icon_svg('list') ?></span>
        <span class="m-rep-topbar-label">العهود</span>
    </a>
    <?php endif; ?>
</div>

<div id="m-rep-status" class="m-alert" hidden role="status"></div>

<div id="m-rep-pdf-banner" class="m-rep-pdf-banner" hidden role="status">
    <p class="m-rep-pdf-banner-msg" id="m-rep-pdf-banner-msg"></p>
    <div class="m-rep-pdf-banner-actions">
        <a class="m-btn m-btn--primary m-btn--sm" id="m-rep-banner-pdf" href="#" target="_blank" rel="noopener">تحميل PDF</a>
        <button type="button" class="m-btn m-btn--ghost m-btn--sm" id="m-rep-pdf-banner-done">سند جديد</button>
    </div>
</div>

<form id="m-rep-form" class="m-rep-custody-form" novalidate onsubmit="return false;">
    <input type="hidden" id="m-rep-move-id" value="0">

    <section class="m-card m-rep-custody-head">
        <div class="m-rep-custody-meta-row m-rep-custody-meta-row--inline">
            <label class="m-field m-rep-field-inline">
                <span class="m-field-label">رقم السند</span>
                <input class="m-input m-input--xs" type="text" id="m-rep-move-no"
                       value="<?= esc($previewMoveNo) ?>" readonly tabindex="-1" dir="ltr">
            </label>
            <label class="m-field m-rep-field-inline">
                <span class="m-field-label">التاريخ</span>
                <input class="m-input m-input--xs" type="text" id="m-rep-date"
                       inputmode="numeric" placeholder="يوم-شهر-سنة" dir="ltr"
                       value="<?= esc($today) ?>">
            </label>
            <label class="m-field m-rep-field-inline m-rep-field-inline--rep">
                <span class="m-field-label">المندوب</span>
                <input class="m-input m-input--xs" type="text" readonly tabindex="-1"
                       value="<?= esc((string) $ctx['rep_name']) ?>">
            </label>
        </div>
        <p class="m-rep-custody-wh muted">
            من <strong><?= esc($fromLabel) ?></strong> إلى <strong><?= esc($toLabel) ?></strong>
        </p>
    </section>

    <section class="m-card m-rep-custody-items">
        <div class="m-card-head">
            <h2 class="m-card-title">اختيار المواد</h2>
        </div>
        <p class="m-rep-custody-hint muted"><?= esc($repTransferHint) ?></p>
        <div class="m-picker-search-wrap">
            <input type="search" class="m-input" id="m-rep-item-search"
                   placeholder="بحث بالاسم أو الرمز..." autocomplete="off">
        </div>
        <div id="m-rep-item-grid" class="m-rep-item-grid" role="list"></div>
        <p id="m-rep-item-empty" class="muted m-rep-item-empty" hidden>لا توجد مواد متاحة.</p>
    </section>
</form>

<div id="m-rep-qty-mini" class="m-rep-qty-mini" hidden aria-hidden="true">
    <div class="m-rep-qty-mini-backdrop" id="m-rep-qty-mini-backdrop"></div>
    <div class="m-rep-qty-mini-box" role="dialog" aria-modal="true">
        <p class="m-rep-qty-mini-name" id="m-rep-qty-mini-name">—</p>
        <p class="m-rep-qty-mini-meta muted" id="m-rep-qty-mini-meta"></p>
        <label class="m-field m-rep-qty-mini-field">
            <span class="m-field-label">الكمية</span>
            <input class="m-input m-input--xs" type="text" id="m-rep-qty-mini-input"
                   inputmode="decimal" dir="ltr" placeholder="0" autocomplete="off">
        </label>
        <div class="m-rep-qty-mini-actions">
            <button type="button" class="m-btn m-btn--ghost m-btn--sm" id="m-rep-qty-mini-cancel">إلغاء</button>
            <button type="button" class="m-btn m-btn--primary m-btn--sm" id="m-rep-qty-mini-add">إضافة للسلة</button>
        </div>
    </div>
</div>

<div id="m-rep-cart" class="m-rep-cart" hidden aria-hidden="true">
    <div class="m-rep-cart-backdrop" id="m-rep-cart-backdrop"></div>
    <div class="m-rep-cart-sheet" role="dialog" aria-modal="true" aria-labelledby="m-rep-cart-title">
        <header class="m-rep-cart-head">
            <h2 class="m-rep-cart-title" id="m-rep-cart-title">سلة العهدة</h2>
            <button type="button" class="m-rep-cart-close" id="m-rep-cart-close" aria-label="إغلاق">×</button>
        </header>
        <p class="m-rep-cart-summary muted" id="m-rep-cart-summary">لا توجد مواد في السلة.</p>
        <div class="m-rep-cart-table-wrap">
            <table class="m-rep-cart-table">
                <thead>
                <tr>
                    <th>المادة</th>
                    <th>الكمية</th>
                    <th></th>
                </tr>
                </thead>
                <tbody id="m-rep-cart-body"></tbody>
            </table>
        </div>
        <p id="m-rep-cart-posted-msg" class="m-rep-cart-posted-msg" hidden role="status"></p>
        <footer class="m-rep-cart-footer" id="m-rep-cart-footer">
            <div class="m-rep-cart-footer-normal<?= $canArchiveMove ? '' : ' m-rep-cart-footer-normal--no-archive' ?>" id="m-rep-cart-footer-normal">
                <button type="button" class="m-btn m-btn--ghost m-rep-cart-footer-btn" id="m-rep-cart-clear">تفريغ</button>
                <?php if ($canArchiveMove): ?>
                <button type="button" class="m-btn m-btn--secondary m-rep-cart-footer-btn m-rep-cart-archive-btn" id="m-rep-cart-archive" title="أرشيف وتصوير">
                    <svg class="m-rep-cart-archive-ico" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false">
                        <path fill="currentColor" d="M12 15.2a3.2 3.2 0 1 0 0-6.4 3.2 3.2 0 0 0 0 6.4z"/>
                        <path fill="currentColor" d="M9 4.5 7.2 6H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-2.2L15 4.5zM12 17.5a5.2 5.2 0 1 1 0-10.4 5.2 5.2 0 0 1 0 10.4z"/>
                    </svg>
                    <span class="m-rep-cart-archive-lbl">أرشيف</span>
                </button>
                <?php endif; ?>
                <button type="button" class="m-btn m-btn--secondary m-rep-cart-footer-btn" id="m-rep-btn-save">حفظ</button>
                <button type="button" class="m-btn m-btn--primary m-rep-cart-footer-btn" id="m-rep-btn-post">ترحيل</button>
                <button type="button" class="m-btn m-btn--pdf m-rep-cart-footer-btn" id="m-rep-btn-pdf" disabled title="ترحيل ثم PDF">PDF</button>
            </div>
            <button type="button" class="m-btn m-btn--ghost m-rep-cart-footer-done" id="m-rep-cart-done" hidden>سند جديد</button>
        </footer>
    </div>
</div>

<div id="m-rep-pdf-view" class="m-rep-pdf-view" hidden aria-hidden="true">
    <div class="m-rep-pdf-view-head">
        <strong>قائمة العهدة — PDF</strong>
        <button type="button" class="m-rep-pdf-view-close" id="m-rep-pdf-view-close" aria-label="إغلاق">×</button>
    </div>
    <iframe class="m-rep-pdf-view-frame" id="m-rep-pdf-view-frame" title="PDF"></iframe>
    <div class="m-rep-pdf-view-actions">
        <a class="m-btn m-btn--primary m-btn--sm" id="m-rep-pdf-view-dl" href="#" download="عهدة.pdf">تحميل</a>
        <button type="button" class="m-btn m-btn--ghost m-btn--sm" id="m-rep-pdf-view-done">سند جديد</button>
    </div>
</div>

<script>
window.MRepTransferConfig = {
    direction: <?= json_encode($repTransferDirection, JSON_UNESCAPED_UNICODE) ?>,
    itemsApi: <?= json_encode($itemsApi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    saveApi: <?= json_encode($saveApi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    viewApi: <?= json_encode($viewApi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    editMoveId: <?= (int) $editMoveId ?>,
    printApi: <?= json_encode($printApi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    pdfApi: <?= json_encode($pdfApi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    csrf: <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE) ?>,
    decimalPlaces: <?= (int) company_decimal_places($pdo) ?>,
    positiveStockOnly: <?= $repTransferDirection === 'return' ? 'true' : 'false' ?>,
    previewMoveNo: <?= json_encode($previewMoveNo, JSON_UNESCAPED_UNICODE) ?>,
    todayDate: <?= json_encode($today, JSON_UNESCAPED_UNICODE) ?>,
    canArchive: <?= $canArchiveMove ? 'true' : 'false' ?>,
    archiveApi: <?= json_encode($archiveApiUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
};
</script>
<?php if ($canArchiveMove):
$photoArchJsV = is_file(app_path('assets/mobile/invoice-photo-archive.js'))
    ? (string) filemtime(app_path('assets/mobile/invoice-photo-archive.js'))
    : '';
?>
<script src="<?= esc(app_url('assets/mobile/invoice-photo-archive.js')) ?><?= $photoArchJsV !== '' ? '?v=' . esc($photoArchJsV) : '' ?>"></script>
<?php endif; ?>
<script src="<?= esc(app_url('assets/mobile/rep-transfer.js')) ?><?= $jsV !== '' ? '?v=' . esc($jsV) : '' ?>" defer></script>
