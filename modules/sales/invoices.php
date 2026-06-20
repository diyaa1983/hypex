<?php
declare(strict_types=1);

require_once app_path('includes/sales_invoice_post.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'save_invoice') {
    handle_sales_invoice_post();
}

$pdo = db();
require_once app_path('includes/sal_invoice_schema.php');
require_once app_path('includes/einvoice_schema.php');
sal_invoice_ensure_schema($pdo);
einvoice_ensure_schema($pdo);
require_once app_path('includes/crm_sales_rep_schema.php');
$flash = flash_get();
crm_sales_rep_ensure_customer_invoice_links($pdo);
require_once app_path('includes/customer_picker.php');
$customers = crm_customers_for_picker($pdo);
require_once app_path('includes/inv_warehouse_items.php');
$warehouses = $pdo->query('SELECT id, code, name_ar FROM inv_warehouse WHERE is_active = 1 ORDER BY name_ar')->fetchAll();
$defaultWarehouseId = inv_default_warehouse_id($pdo);

$settings = company_settings($pdo);
$dp = company_decimal_places($pdo);
$unitPriceDp = company_invoice_unit_price_decimal_places($pdo);
$printDp = company_invoice_print_decimal_places($pdo);
$printUnitPriceDp = company_invoice_print_unit_price_decimal_places($pdo);
$unitPriceStep = company_invoice_unit_price_decimal_step($pdo);
$amountStep = company_decimal_step($dp);
$defaultTax = (float) ($settings['tax_rate_percent'] ?? 15);

$taxRates = [];
try {
    $taxRates = $pdo->query('SELECT id, name_ar, rate_percent FROM sys_tax_rate WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll();
} catch (Throwable $e) {
    $taxRates = [];
}
if (!$taxRates) {
    $taxRates = [['id' => 0, 'name_ar' => 'افتراضي (' . $defaultTax . '%)', 'rate_percent' => $defaultTax]];
}

$today = date('Y-m-d');
require_once app_path('includes/document_header.php');
$docBrand = document_header_brand($pdo);
$companyNameAr = $docBrand['company_name_ar'];
$companyLogoUrl = (string) ($docBrand['logo_url'] ?? '');
require_once app_path('includes/nav_helpers.php');
require_once app_path('includes/sales_oracle12_ui.php');
$exitUrl = nav_exit_url($activeRoute ?? 'sales_invoices');
$newInvoiceUrl = app_url('index.php?r=sales_invoices');
$apiPostInvoice = app_url('api/sales_invoice_post.php');
$apiUnpostInvoice = app_url('api/sales_invoice_unpost.php');
$apiDeleteInvoice = app_url('api/sales_invoice_delete.php');
$listInvoicesUrl = app_url('index.php?r=sales_invoices_list');
$apiEinvoiceSend = app_url('api/sales_einvoice_send.php');
$apiEinvoiceReset = app_url('api/sales_einvoice_reset.php');
$apiSendEmail = app_url('api/document_send_email.php');
$canSendEinvoice = user_can_action('sales_send_einvoice');
$canUnpostInvoice = user_can_action('action_unpost_sales_invoice');
$canDeleteInvoice = user_can_action('action_delete_sales_invoice');
$canPostInvoice = user_can_action('action_post_sales_invoice');
$initialInvoiceId = (int) ($_GET['id'] ?? 0);
$apiItems = app_url('api/items_search.php');
$apiInvoice = app_url('api/sales_invoice_view.php');
$apiDeliveryPick = app_url('api/sales_delivery_pick_list.php');
$apiLinkDelivery = app_url('api/sales_invoice_link_delivery.php');
$apiUnlinkDelivery = app_url('api/sales_invoice_unlink_delivery.php');
$priceStep = $unitPriceStep;
$cssInvPath = app_path('assets/css/sales-invoice.css');
$cssInv = app_url('assets/css/sales-invoice.css') . (is_file($cssInvPath) ? '?v=' . (string) filemtime($cssInvPath) : '');
$ora12CssPath = app_path('assets/css/sales-invoice-oracle12.css');
$ora12CssUrl = app_url('assets/css/sales-invoice-oracle12.css') . (is_file($ora12CssPath) ? '?v=' . (string) filemtime($ora12CssPath) : '');
$jsInvPrintPath = app_path('assets/js/inv-invoice-print.js');
$jsInvPrint = app_url('assets/js/inv-invoice-print.js') . (is_file($jsInvPrintPath) ? '?v=' . (string) filemtime($jsInvPrintPath) : '');
$jsItemDisplayPath = app_path('assets/js/inv-item-display.js');
$jsItemDisplay = app_url('assets/js/inv-item-display.js') . (is_file($jsItemDisplayPath) ? '?v=' . (string) filemtime($jsItemDisplayPath) : '');
$jsInvPath = app_path('assets/js/sales-invoice.js');
$jsInv = app_url('assets/js/sales-invoice.js') . (is_file($jsInvPath) ? '?v=' . (string) filemtime($jsInvPath) : '');
require_once app_path('includes/item_picker.php');
require_once app_path('includes/ledger_document_view_assets.php');

$ledgerView = nav_is_ledger_view_request();
$screenTitle = $ledgerView ? 'عرض فاتورة مبيعات' : 'فاتورة مبيعات';
?>
<?php sales_inv_oracle12_enqueue_assets(); ?>
<?php item_picker_enqueue_assets(); ?>
<?php ledger_document_view_enqueue_assets(); ?>
<?php customer_picker_json_script($customers, 'sales-inv-customers-json'); ?>

<?php require_once app_path('includes/inv_invoice_line_table.php'); ?>

<div class="dashboard-ora sales-ora12-screen sales-inv-wrap sales-inv-main sales-inv-bold" data-exit-guard="custom">
    <header class="dashboard-ora-screen-title no-print" role="banner">
        <div class="dashboard-ora-screen-title__group">
            <h1 class="dashboard-ora-screen-title__text"><?= esc($screenTitle) ?></h1>
            <?php if (!$ledgerView): ?>
                <div class="sales-inv-title-actions no-print">
                    <a class="dashboard-ora-screen-title__action sales-inv-btn-new sales-inv-title-new"
                       href="<?= esc($newInvoiceUrl) ?>">+ فاتورة جديدة</a>
                    <button type="button" class="dashboard-ora-screen-title__action sales-inv-title-action" id="inv_pull_delivery_btn">سحب سند تسليم</button>
                    <button type="button" class="dashboard-ora-screen-title__action sales-inv-title-action sales-inv-unlink-delivery-btn" id="inv_unlink_delivery_btn" hidden>فك ربط السند</button>
                </div>
            <?php endif; ?>
        </div>
        <span class="dashboard-ora-screen-title__meta sales-inv-status-badges">
            <span id="inv_posted_badge" class="sales-inv-posted-badge badge badge-warn" hidden></span>
            <span id="inv_einv_badge" class="sales-inv-posted-badge badge badge-ok" hidden title="حالة الفوترة"></span>
        </span>
        <?php nav_render_screen_close($activeRoute ?? 'sales_invoices'); ?>
    </header>

    <div class="dashboard-ora-workspace">
    <?php if ($flash): ?>
        <div class="alert no-print alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> sales-inv-grid-flash"><?= esc($flash['message']) ?></div>
    <?php endif; ?>

    <?php if ($ledgerView): ?>
    <div class="dashboard-ora-toolbar sales-inv-toolbar no-print">
        <?php require app_path('includes/ledger_back_button.php'); ?>
    </div>
    <?php endif; ?>

    <form id="sales-inv-form" class="master-page-form" method="post" action="<?= esc(app_url('index.php?r=sales_invoices')) ?>" novalidate
          data-api-items="<?= esc($apiItems) ?>"
          data-api-invoice="<?= esc($apiInvoice) ?>"
          data-delivery-pick-url="<?= esc($apiDeliveryPick) ?>"
          data-link-delivery-url="<?= esc($apiLinkDelivery) ?>"
          data-unlink-delivery-url="<?= esc($apiUnlinkDelivery) ?>"
          data-invoice-post-url="<?= esc($apiPostInvoice) ?>"
          data-invoice-unpost-url="<?= esc($apiUnpostInvoice) ?>"
          data-invoice-delete-url="<?= esc($apiDeleteInvoice) ?>"
          data-list-url="<?= esc($listInvoicesUrl) ?>"
          data-einvoice-send-url="<?= esc($apiEinvoiceSend) ?>"
          data-einvoice-reset-url="<?= esc($apiEinvoiceReset) ?>"
          data-send-email-url="<?= esc($apiSendEmail) ?>"
          data-can-send-einvoice="<?= $canSendEinvoice ? '1' : '0' ?>"
          data-can-unpost="<?= $canUnpostInvoice ? '1' : '0' ?>"
          data-can-delete="<?= $canDeleteInvoice ? '1' : '0' ?>"
          data-can-post="<?= $canPostInvoice ? '1' : '0' ?>"
          data-decimals="<?= (int) $dp ?>"
          data-unit-price-decimals="<?= (int) $unitPriceDp ?>"
          data-print-decimals="<?= (int) $printDp ?>"
          data-print-unit-price-decimals="<?= (int) $printUnitPriceDp ?>"
          data-warehouse-required="<?= count($warehouses) > 0 ? '1' : '0' ?>"
          data-default-warehouse-id="<?= (int) ($defaultWarehouseId ?? 0) ?>"
          data-default-tax-rate="<?= esc((string) $defaultTax) ?>"
          data-default-date="<?= esc(format_date_dmY($today)) ?>"
          data-exit-url="<?= esc($exitUrl) ?>"
          data-ledger-return-qs="<?= esc(nav_ledger_return_query_from_request()) ?>"
          data-ledger-view="<?= $ledgerView ? '1' : '0' ?>"
          data-new-url="<?= esc($newInvoiceUrl) ?>"
          data-initial-id="<?= (int) $initialInvoiceId ?>"
          data-draft-key="sales_invoices"
          data-company-name="<?= esc($companyNameAr) ?>"
          data-company-logo="<?= esc($companyLogoUrl) ?>">
        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="_action" value="save_invoice">
        <input type="hidden" name="lines_json" id="sales-inv-lines-json" value="[]">
        <input type="hidden" name="delivery_id" id="inv_delivery_id" value="">

        <section class="dashboard-ora-panel no-print">
            <h2 class="dashboard-ora-panel__title">بيانات الفاتورة</h2>
            <div class="dashboard-ora-panel__body">
        <header class="sales-inv-doc-header sales-inv-meta-panel">
            <div class="sales-inv-meta-row">
                <div class="sales-inv-meta-item sales-inv-meta-no">
                    <label for="inv_no">رقم الفاتورة</label>
                    <div class="sales-inv-no-nav">
                        <button type="button" class="sales-inv-no-arrow" id="inv_no_prev" title="الفاتورة السابقة" aria-label="الفاتورة السابقة">‹</button>
                        <input class="input input-compact sales-inv-no-input" type="text" id="inv_no" value="" placeholder="" title="يُولَّد الرقم تلقائياً عند الحفظ — للبحث اكتب رقم فاتورة محفوظة واضغط Enter">
                        <button type="button" class="sales-inv-no-arrow" id="inv_no_next" title="الفاتورة التالية" aria-label="الفاتورة التالية">›</button>
                    </div>
                    <input type="hidden" name="invoice_id" id="inv_record_id" value="">
                </div>
                <div class="sales-inv-meta-item sales-inv-meta-date">
                    <label for="inv_date">التاريخ</label>
                    <input class="input input-compact js-date-dmy" type="text" name="invoice_date" id="inv_date"
                           value="<?= esc(format_date_dmY($today)) ?>" placeholder="يوم-شهر-سنة" dir="ltr"
                           autocomplete="off" inputmode="numeric" required>
                </div>
                <div class="sales-inv-meta-item sales-inv-meta-type">
                    <label for="inv_payment_type">النوع</label>
                    <select class="input input-compact" name="payment_type" id="inv_payment_type">
                        <option value="cash">نقدي</option>
                        <option value="credit" selected>ذمم</option>
                    </select>
                </div>
                <?= customer_picker_field([
                    'id' => 'inv_customer',
                    'label' => 'العميل',
                    'compact' => true,
                    'wrapper_class' => 'sales-inv-meta-item sales-inv-meta-customer',
                    'json_id' => 'sales-inv-customers-json',
                    'manual_bind' => true,
                ]) ?>
                <div class="sales-inv-meta-item sales-inv-meta-rep">
                    <label for="inv_sales_rep">المندوب</label>
                    <select class="input input-compact" name="sales_rep_id" id="inv_sales_rep" disabled
                            title="يُجلب من بيانات العميل — اختر المندوب إذا كان للعميل أكثر من مندوب">
                        <option value="">— بدون مندوب —</option>
                    </select>
                </div>
                <?php if (count($warehouses) > 0): ?>
                    <div class="sales-inv-meta-item sales-inv-meta-wh">
                        <label for="inv_wh">المستودع</label>
                        <select class="input input-compact" name="warehouse_id" id="inv_wh" required>
                            <option value="">— المستودع —</option>
                            <?php foreach ($warehouses as $w): ?>
                                <option value="<?= (int) $w['id'] ?>"<?= $defaultWarehouseId !== null && (int) $w['id'] === $defaultWarehouseId ? ' selected' : '' ?>><?= esc((string) $w['name_ar']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
            </div>
            <p class="muted no-print sales-inv-delivery-link-hint" id="inv_delivery_link_hint" hidden></p>
        </header>
            </div>
        </section>

        <div class="sales-inv-print-area" id="sales-inv-print-area">
        <div class="sales-inv-print-only">
            <?= document_print_header_html('فاتورة مبيعات', $pdo) ?>
        </div>

        <section class="dashboard-ora-panel sales-inv-card">
            <h2 class="dashboard-ora-panel__title no-print">بنود الفاتورة</h2>
            <div class="dashboard-ora-panel__body dashboard-ora-panel__body--flush">
            <div class="sales-inv-table-wrap" id="sales-inv-table-wrap">
                <table class="sales-inv-table">
                    <thead>
                    <?php inv_invoice_line_table_head(); ?>
                    </thead>
                    <tbody id="sales-inv-lines-body">
                    <?php if ($initialInvoiceId < 1): ?>
                        <?php require app_path('includes/inv_invoice_entry_row.php'); ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="sales-inv-footer-grid">
            <div class="sales-inv-notes sales-inv-field">
                <label for="inv_notes">ملاحظات</label>
                <textarea class="input" name="notes" id="inv_notes" rows="3" placeholder="اختياري"></textarea>
            </div>
            <div class="sales-inv-totals">
                <div class="row sales-inv-totals-disc">
                    <label for="inv-invoice-discount">خصم الفاتورة <span class="sales-inv-disc-hint">10% أو مبلغ</span></label>
                    <input type="text" class="input input-compact input-num" name="invoice_discount" id="inv-invoice-discount"
                           value="" title="يُطبَّق على مجموع الفاتورة قبل الضريبة (بعد خصم البنود إن وُجد) ويُوزَّع تناسبياً" autocomplete="off">
                </div>
                <div class="row"><span>مجموع الخصم</span><span id="sales-inv-sum-disc"><?= esc(format_amount(0)) ?></span></div>
                <div class="row"><span>المجموع بدون ضريبة</span><span id="sales-inv-sum-sub"><?= esc(format_amount(0)) ?></span></div>
                <div class="row"><span>مجموع الضريبة</span><span id="sales-inv-sum-tax"><?= esc(format_amount(0)) ?></span></div>
                <div class="row grand"><span>الإجمالي</span><span id="sales-inv-sum-grand"><?= esc(format_amount(0)) ?></span></div>
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

<template id="sales-inv-line-template">
    <?php inv_invoice_line_table_row_template($taxRates, $unitPriceStep, $amountStep); ?>
</template>

<div id="inv-delivery-pick-modal" class="sales-inv-dlv-pick-modal no-print" hidden>
    <div class="sales-inv-dlv-pick-modal__backdrop" data-close="1"></div>
    <div class="sales-inv-dlv-pick-modal__panel" role="dialog" aria-labelledby="inv-delivery-pick-title">
        <header class="sales-inv-dlv-pick-modal__head">
            <h3 id="inv-delivery-pick-title">سحب سند تسليم</h3>
            <button type="button" class="sales-inv-dlv-pick-modal__close" id="inv_delivery_pick_close" aria-label="إغلاق">×</button>
        </header>
        <p class="muted sales-inv-dlv-pick-modal__hint">سندات مرحّلة غير مربوطة بفاتورة مبيعات.</p>
        <ul class="sales-inv-dlv-pick-list" id="inv_delivery_pick_list"></ul>
        <p class="muted sales-inv-dlv-pick-empty" id="inv_delivery_pick_empty" hidden>لا توجد سندات متاحة.</p>
    </div>
</div>

<div id="sales-inv-print-overlay" class="sales-inv-print-overlay no-print" hidden>
    <div class="sales-inv-print-overlay-panel">
        <div class="sales-inv-print-overlay-head">
            <h3 class="sales-inv-print-overlay-title">معاينة الطباعة</h3>
            <div class="sales-inv-print-overlay-actions">
                <button type="button" class="btn btn-secondary btn-sm" id="sales-inv-print-close">إغلاق</button>
            </div>
        </div>
        <div class="sales-inv-print-preview-body" id="sales-inv-print-preview"></div>
    </div>
</div>

<div id="sales-inv-export-host" class="sales-inv-export-host" aria-hidden="true"></div>

<script src="<?= esc($jsItemDisplay) ?>" defer></script>
<script src="<?= esc($jsInvPrint) ?>" defer></script>
<script src="<?= esc(app_url('assets/js/inv-invoice-discount.js')) ?>" defer></script>
<script src="<?= esc($jsInv) ?>" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" defer crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="<?= esc(app_url('assets/js/doc-send-email.js')) ?>" defer></script>
<script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js" defer crossorigin="anonymous"></script>
