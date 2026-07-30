<?php
declare(strict_types=1);

require_once app_path('includes/sales_delivery_save.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'save_delivery') {
    handle_sales_delivery_save();
}

$pdo = db();
require_once app_path('includes/sal_delivery_schema.php');
if (!sal_delivery_ensure_schema($pdo)) {
    echo '<div class="card"><p class="alert alert-error">جدول سند التسليم غير موجود. حدّث الصفحة أو نفّذ <code>database/migrations/028_sal_delivery.sql</code>.</p></div>';
    return;
}

$flash = flash_get();
$customers = $pdo->query(
    'SELECT id, code, name_ar FROM crm_customer WHERE is_active = 1 ORDER BY name_ar'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

require_once app_path('includes/inv_warehouse_items.php');
$warehouses = $pdo->query('SELECT id, code, name_ar FROM inv_warehouse WHERE is_active = 1 ORDER BY name_ar')->fetchAll();
$defaultWarehouseId = inv_default_warehouse_id($pdo);

$today = date('Y-m-d');
require_once app_path('includes/document_header.php');
$docBrand = document_header_brand($pdo);
$companyNameAr = $docBrand['company_name_ar'];
$companyLogoUrl = (string) ($docBrand['logo_url'] ?? '');
require_once app_path('includes/nav_helpers.php');
require_once app_path('includes/sales_oracle12_ui.php');
require_once app_path('includes/app_icons.php');
$exitUrl = nav_exit_url($activeRoute ?? 'sales_delivery');
$newUrl = app_url('index.php?r=sales_delivery');
$apiItems = app_url('api/items_search.php');
$apiDelivery = app_url('api/sales_delivery_view.php');
$apiPost = app_url('api/sales_delivery_post.php');
$apiUnpost = app_url('api/sales_delivery_unpost.php');
$apiUnlinkInvoice = app_url('api/sales_delivery_unlink_invoice.php');
$apiDelete = app_url('api/sales_delivery_delete.php');
$canUnpostDelivery = user_can_action('action_unpost_sales_delivery');
$initialId = (int) ($_GET['id'] ?? 0);
require_once app_path('includes/fin_voucher_archive.php');
fin_voucher_archive_ensure_schema($pdo);
$canArchiveDelivery = user_can_action('action_archive_sales_delivery');
$archiveApiUrl = app_url('api/fin_voucher_archive.php');
$archiveCssPath = app_path('assets/css/fin-voucher-archive.css');
$archiveCssUrl = app_url('assets/css/fin-voucher-archive.css') . (is_file($archiveCssPath) ? '?v=' . (string) filemtime($archiveCssPath) : '');
$archiveJsPath = app_path('assets/js/fin-voucher-archive.js');
$archiveJsUrl = app_url('assets/js/fin-voucher-archive.js') . (is_file($archiveJsPath) ? '?v=' . (string) filemtime($archiveJsPath) : '');
$cssInvPath = app_path('assets/css/sales-invoice.css');
$cssInv = app_url('assets/css/sales-invoice.css') . (is_file($cssInvPath) ? '?v=' . (string) filemtime($cssInvPath) : '');
$cssDlvPath = app_path('assets/css/sales-delivery.css');
$cssDlv = app_url('assets/css/sales-delivery.css') . (is_file($cssDlvPath) ? '?v=' . (string) filemtime($cssDlvPath) : '');
$ora12CssPath = app_path('assets/css/sales-invoice-oracle12.css');
$ora12CssUrl = app_url('assets/css/sales-invoice-oracle12.css') . (is_file($ora12CssPath) ? '?v=' . (string) filemtime($ora12CssPath) : '');
$jsItemDisplayPath = app_path('assets/js/inv-item-display.js');
$jsItemDisplay = app_url('assets/js/inv-item-display.js') . (is_file($jsItemDisplayPath) ? '?v=' . (string) filemtime($jsItemDisplayPath) : '');
$jsPath = app_path('assets/js/sales-delivery.js');
$jsUrl = app_url('assets/js/sales-delivery.js') . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : '');
$bootstrapLineId = 'L-boot-' . str_replace('.', '', uniqid('', true));
$screenTitle = 'سند تسليم بضاعة';
?>
<?php sales_inv_oracle12_enqueue_assets(); ?>
<link rel="stylesheet" href="<?= esc($cssDlv) ?>">
<link rel="stylesheet" href="<?= esc($archiveCssUrl) ?>">
<?php
require_once app_path('includes/customer_picker.php');
require_once app_path('includes/item_picker.php');
item_picker_enqueue_assets();
customer_picker_json_script($customers, 'sales-dlv-customers-json');

require_once app_path('includes/inv_delivery_line_table.php');
?>


<template id="sales-dlv-line-template">
    <?php inv_delivery_line_table_row_template(); ?>
</template>

<div class="dashboard-ora sales-ora12-screen sales-inv-wrap sales-inv-main sales-dlv-wrap sales-inv-bold" data-exit-guard="custom">
    <header class="dashboard-ora-screen-title no-print" role="banner">
        <div class="dashboard-ora-screen-title__group">
            <h1 class="dashboard-ora-screen-title__text"><?= esc($screenTitle) ?></h1>
            <div class="sales-inv-title-actions no-print">
                <a class="dashboard-ora-screen-title__action sales-inv-btn-new sales-inv-title-new"
                   href="<?= esc($newUrl) ?>">+ سند جديد</a>
                <button type="button" class="dashboard-ora-screen-title__action sales-inv-title-action sales-inv-unlink-delivery-btn"
                        id="dlv_unlink_invoice_btn" hidden>فك الربط بالفاتورة</button>
            </div>
        </div>
        <span class="dashboard-ora-screen-title__meta sales-inv-status-badges">
            <span id="dlv_posted_badge" class="sales-inv-posted-badge badge badge-warn" hidden></span>
        </span>
        <?php nav_render_screen_close($activeRoute ?? 'sales_delivery'); ?>
    </header>

    <div class="dashboard-ora-workspace">
    <?php if ($flash): ?>
        <div class="alert no-print alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> sales-inv-grid-flash"><?= esc($flash['message']) ?></div>
    <?php endif; ?>

    <form id="sales-dlv-form" class="master-page-form" method="post" action="<?= esc(app_url('index.php?r=sales_delivery')) ?>" novalidate
          data-app-busy-msg="جاري حفظ سند التسليم..."
          data-api-items="<?= esc($apiItems) ?>"
          data-api-delivery="<?= esc($apiDelivery) ?>"
          data-delivery-post-url="<?= esc($apiPost) ?>"
          data-delivery-unpost-url="<?= esc($apiUnpost) ?>"
          data-delivery-unlink-invoice-url="<?= esc($apiUnlinkInvoice) ?>"
          data-delivery-delete-url="<?= esc($apiDelete) ?>"
          data-can-unpost="<?= $canUnpostDelivery ? '1' : '0' ?>"
          data-can-archive="<?= $canArchiveDelivery ? '1' : '0' ?>"
          data-archive-api="<?= esc($archiveApiUrl) ?>"
          data-archive-kind="sales_delivery"
          data-warehouse-required="<?= count($warehouses) > 0 ? '1' : '0' ?>"
          data-default-warehouse-id="<?= (int) ($defaultWarehouseId ?? 0) ?>"
          data-default-date="<?= esc(format_date_dmY($today)) ?>"
          data-exit-url="<?= esc($exitUrl) ?>"
          data-new-url="<?= esc($newUrl) ?>"
          data-initial-id="<?= (int) $initialId ?>"
          data-company-name="<?= esc($companyNameAr) ?>"
          data-company-logo="<?= esc($companyLogoUrl) ?>">
        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="_action" value="save_delivery">
        <input type="hidden" name="lines_json" id="sales-dlv-lines-json" value="[]">
        <input type="hidden" name="delivery_id" id="dlv_record_id" value="">

        <section class="dashboard-ora-panel no-print">
            <h2 class="dashboard-ora-panel__title">بيانات السند</h2>
            <div class="dashboard-ora-panel__body">
        <header class="sales-inv-doc-header sales-inv-meta-panel">
            <div class="sales-inv-meta-row">
                <div class="sales-inv-meta-item sales-inv-meta-no">
                    <label for="dlv_no">رقم السند</label>
                    <div class="sales-inv-no-nav">
                        <button type="button" class="sales-inv-no-arrow" id="dlv_no_prev" title="السند السابق" aria-label="السابق">‹</button>
                        <input class="input input-compact sales-inv-no-input" type="text" id="dlv_no" value="" placeholder=""
                               title="يُولَّد الرقم تلقائياً عند الحفظ — للبحث اكتب رقم سند محفوظ واضغط Enter">
                        <button type="button" class="sales-inv-no-arrow" id="dlv_no_next" title="السند التالي" aria-label="التالي">›</button>
                    </div>
                </div>
                <div class="sales-inv-meta-item sales-inv-meta-date">
                    <label for="dlv_date">التاريخ</label>
                    <input class="input input-compact js-date-dmy" type="text" name="delivery_date" id="dlv_date"
                           value="<?= esc(format_date_dmY($today)) ?>" placeholder="يوم-شهر-سنة" dir="ltr"
                           autocomplete="off" inputmode="numeric" required>
                </div>
                <?= customer_picker_field([
                    'id' => 'dlv_customer',
                    'label' => 'العميل',
                    'compact' => true,
                    'wrapper_class' => 'sales-inv-meta-item sales-inv-meta-customer',
                    'json_id' => 'sales-dlv-customers-json',
                    'manual_bind' => true,
                ]) ?>
                <?php if (count($warehouses) > 0): ?>
                <div class="sales-inv-meta-item sales-inv-meta-wh">
                    <label for="dlv_wh">المستودع</label>
                    <select class="input input-compact" name="warehouse_id" id="dlv_wh" required>
                        <option value="">— المستودع —</option>
                        <?php foreach ($warehouses as $w): ?>
                        <option value="<?= (int) $w['id'] ?>"<?= $defaultWarehouseId !== null && (int) $w['id'] === $defaultWarehouseId ? ' selected' : '' ?>><?= esc((string) $w['name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>
            <p class="muted no-print sales-dlv-invoice-link-hint" id="dlv_invoice_link_hint" hidden></p>
        </header>
            </div>
        </section>

        <div class="sales-inv-print-area" id="sales-dlv-print-area">
            <div class="sales-inv-print-only">
                <?= document_print_header_html('سند تسليم بضاعة', $pdo) ?>
            </div>
            <section class="dashboard-ora-panel sales-inv-card">
                <h2 class="dashboard-ora-panel__title no-print">مواد التسليم</h2>
                <div class="dashboard-ora-panel__body dashboard-ora-panel__body--flush">
                <div class="sales-inv-table-wrap">
                    <table class="sales-inv-table sales-dlv-table">
                        <thead>
                        <?php inv_delivery_line_table_head(); ?>
                        </thead>
                        <tbody id="sales-dlv-lines-body">
                        <?php if ($initialId < 1): ?>
                        <tr data-line-id="<?= esc((string) $bootstrapLineId) ?>" data-item-id="" data-name-ar="" class="is-entry-row">
                            <td class="sales-inv-col-seq"><span class="js-seq"></span></td>
                            <td class="sales-inv-col-sku">
                                <code class="js-sku"></code>
                                <input type="text" class="input js-barcode-inp" placeholder="مسح أو باركود" autocomplete="off" spellcheck="false" title="امسح الباركود أو أدخل رقم المادة">
                            </td>
                            <td class="sales-inv-item-cell sales-inv-col-item">
                                <div class="sales-inv-item-lov is-empty">
                                    <button type="button" class="sales-inv-item-lov-btn js-pick-open" title="اختيار المادة (F3)" aria-label="اختيار المادة (F3)"></button>
                                    <kbd class="sales-inv-field-hotkey sales-inv-item-hotkey" aria-hidden="true">F3</kbd>
                                    <span class="js-name sales-inv-item-name is-placeholder"></span>
                                </div>
                            </td>
                            <td class="sales-inv-col-qty"><input type="number" class="input input-num js-qty" min="0" step="1" inputmode="decimal" value="" placeholder=""></td>
                            <td class="sales-inv-col-del"><button type="button" class="btn-icon danger js-remove" title="حذف" aria-label="حذف البند" style="visibility:hidden"><?= app_icon_svg('trash', 18) ?></button></td>
                        </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="sales-inv-footer-grid">
                    <div class="sales-inv-notes sales-inv-field no-print">
                        <label for="dlv_notes">ملاحظات</label>
                        <textarea class="input" name="notes" id="dlv_notes" rows="3" placeholder="اختياري"></textarea>
                    </div>
                </div>
                <div class="sales-inv-print-only">
                    <?= document_print_recipient_signature_html() ?>
                </div>
                </div>
            </section>
        </div>
    </form>
    </div>
</div>

<div id="sales-inv-print-overlay" class="sales-inv-print-overlay no-print" hidden>
    <div class="sales-inv-print-overlay-panel">
        <div class="sales-inv-print-overlay-head">
            <h3 class="sales-inv-print-overlay-title">معاينة الطباعة</h3>
            <div class="sales-inv-print-overlay-actions">
                <button type="button" class="btn btn-secondary btn-sm" id="sales-inv-print-run">طباعة</button>
                <button type="button" class="btn btn-secondary btn-sm" id="sales-inv-print-close">إغلاق</button>
            </div>
        </div>
        <div class="sales-inv-print-preview-body" id="sales-inv-print-preview"></div>
    </div>
</div>

<script src="<?= esc($archiveJsUrl) ?>" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" defer crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="<?= esc($jsItemDisplay) ?>" defer></script>
<script src="<?= esc($jsUrl) ?>" defer></script>
