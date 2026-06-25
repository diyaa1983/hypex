<?php
declare(strict_types=1);

require_once app_path('includes/purchase_invoice_save.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'save_invoice') {
    handle_purchase_invoice_post();
}

$pdo = db();
require_once app_path('includes/pur_invoice_schema.php');
$flash = flash_get();
$suppliers = $pdo->query(
    'SELECT id, code, name_ar FROM crm_supplier WHERE is_active = 1 ORDER BY name_ar'
)->fetchAll();
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
pur_invoice_ensure_schema($pdo);
$exitUrl = nav_exit_url($activeRoute ?? 'purchase_invoices');
$newInvoiceUrl = app_url('index.php?r=purchase_invoices');
$apiPostInvoice = app_url('api/purchase_invoice_post.php');
$apiUnpostInvoice = app_url('api/purchase_invoice_unpost.php');
$canUnpostInvoice = user_can_action('action_unpost_purchase_invoice');
$apiDeleteInvoice = app_url('api/purchase_invoice_delete.php');
require_once app_path('includes/fin_voucher_archive.php');
fin_voucher_archive_ensure_schema($pdo);
$canArchiveInvoice = user_can_action('action_archive_purchase_invoice');
$archiveApiUrl = app_url('api/fin_voucher_archive.php');
$archiveCssPath = app_path('assets/css/fin-voucher-archive.css');
$archiveCssUrl = app_url('assets/css/fin-voucher-archive.css') . (is_file($archiveCssPath) ? '?v=' . (string) filemtime($archiveCssPath) : '');
$archiveJsPath = app_path('assets/js/fin-voucher-archive.js');
$archiveJsUrl = app_url('assets/js/fin-voucher-archive.js') . (is_file($archiveJsPath) ? '?v=' . (string) filemtime($archiveJsPath) : '');
$listInvoicesUrl = app_url('index.php?r=purchase_invoices_list');
$apiEinvoiceSend = '';
$canSendEinvoice = false;
$initialInvoiceId = (int) ($_GET['id'] ?? 0);
$apiItems = app_url('api/items_search.php');
$apiInvoice = app_url('api/purchase_invoice_view.php');
$apiSendEmail = app_url('api/document_send_email.php');
$priceStep = $unitPriceStep;
$cssInvPath = app_path('assets/css/sales-invoice.css');
$cssInv = app_url('assets/css/sales-invoice.css') . (is_file($cssInvPath) ? '?v=' . (string) filemtime($cssInvPath) : '');
$ora12CssPath = app_path('assets/css/sales-invoice-oracle12.css');
$ora12CssUrl = app_url('assets/css/sales-invoice-oracle12.css') . (is_file($ora12CssPath) ? '?v=' . (string) filemtime($ora12CssPath) : '');
$jsInvPrintPath = app_path('assets/js/inv-invoice-print.js');
$jsInvPrint = app_url('assets/js/inv-invoice-print.js') . (is_readable($jsInvPrintPath) ? '?v=' . (string) filemtime($jsInvPrintPath) : '1');
$jsItemDisplayPath = app_path('assets/js/inv-item-display.js');
$jsItemDisplay = app_url('assets/js/inv-item-display.js') . (is_readable($jsItemDisplayPath) ? '?v=' . (string) filemtime($jsItemDisplayPath) : '1');
$jsInvPath = app_path('assets/js/purchase-invoice.js');
$jsInv = app_url('assets/js/purchase-invoice.js') . '?v=' . (is_readable($jsInvPath) ? (string) filemtime($jsInvPath) : '1');
require_once app_path('includes/item_picker.php');
require_once app_path('includes/ledger_document_view_assets.php');

$ledgerView = nav_is_ledger_view_request();
$screenTitle = $ledgerView ? 'عرض فاتورة شراء' : 'فاتورة شراء';
?>
<?php sales_inv_oracle12_enqueue_assets(); ?>
<link rel="stylesheet" href="<?= esc($archiveCssUrl) ?>">
<?php item_picker_enqueue_assets(); ?>
<?php ledger_document_view_enqueue_assets(); ?>

<div class="dashboard-ora sales-ora12-screen sales-inv-wrap sales-inv-main sales-inv-bold" data-exit-guard="custom">
    <header class="dashboard-ora-screen-title no-print" role="banner">
        <h1 class="dashboard-ora-screen-title__text"><?= esc($screenTitle) ?></h1>
        <span class="dashboard-ora-screen-title__meta sales-inv-status-badges">
            <span id="inv_posted_badge" class="sales-inv-posted-badge badge badge-warn" hidden></span>
        </span>
        <?php nav_render_screen_close($activeRoute ?? 'purchase_invoices'); ?>
    </header>

    <div class="dashboard-ora-workspace">
    <?php if ($flash): ?>
        <div class="alert no-print alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> sales-inv-grid-flash"><?= esc($flash['message']) ?></div>
    <?php endif; ?>

    <div class="dashboard-ora-toolbar sales-inv-toolbar no-print">
        <?php require app_path('includes/ledger_back_button.php'); ?>
        <?php if (!$ledgerView): ?>
            <a class="dashboard-ora-btn dashboard-ora-btn--primary sales-inv-btn-new" href="<?= esc($newInvoiceUrl) ?>">+ فاتورة جديدة</a>
        <?php endif; ?>
    </div>

    <form id="sales-inv-form" class="master-page-form" method="post" action="<?= esc(app_url('index.php?r=purchase_invoices')) ?>" novalidate
          data-api-items="<?= esc($apiItems) ?>"
          data-api-invoice="<?= esc($apiInvoice) ?>"
          data-invoice-post-url="<?= esc($apiPostInvoice) ?>"
          data-invoice-unpost-url="<?= esc($apiUnpostInvoice) ?>"
          data-can-unpost="<?= $canUnpostInvoice ? '1' : '0' ?>"
          data-invoice-delete-url="<?= esc($apiDeleteInvoice) ?>"
          data-list-url="<?= esc($listInvoicesUrl) ?>"
          data-einvoice-send-url="<?= esc($apiEinvoiceSend) ?>"
          data-send-email-url="<?= esc($apiSendEmail) ?>"
          data-can-send-einvoice="<?= $canSendEinvoice ? '1' : '0' ?>"
          data-can-archive="<?= $canArchiveInvoice ? '1' : '0' ?>"
          data-archive-api="<?= esc($archiveApiUrl) ?>"
          data-archive-kind="purchase_invoice"
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
          data-draft-key="purchase_invoices"
          data-company-name="<?= esc($companyNameAr) ?>"
          data-company-logo="<?= esc($companyLogoUrl) ?>">
        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="_action" value="save_invoice">
        <input type="hidden" name="lines_json" id="sales-inv-lines-json" value="[]">

        <section class="dashboard-ora-panel no-print">
            <h2 class="dashboard-ora-panel__title">بيانات الفاتورة</h2>
            <div class="dashboard-ora-panel__body">
        <header class="sales-inv-doc-header sales-inv-meta-panel">
            <div class="sales-inv-meta-row">
                <div class="sales-inv-meta-item sales-inv-meta-no">
                    <label for="inv_no">رقم الفاتورة</label>
                    <div class="sales-inv-no-nav">
                        <button type="button" class="sales-inv-no-arrow" id="inv_no_prev" title="الفاتورة السابقة" aria-label="السابقة">‹</button>
                        <input class="input input-compact sales-inv-no-input" type="text" id="inv_no" value="" placeholder="" title="يُولَّد الرقم تلقائياً عند الحفظ — للبحث اكتب رقم فاتورة محفوظة واضغط Enter">
                        <button type="button" class="sales-inv-no-arrow" id="inv_no_next" title="الفاتورة التالية" aria-label="التالية">›</button>
                    </div>
                    <input type="hidden" name="invoice_id" id="inv_record_id" value="">
                </div>
                <div class="sales-inv-meta-item sales-inv-meta-supplier-inv-no">
                    <label for="inv_supplier_invoice_no">رقم فاتورة المورد</label>
                    <input class="input input-compact" type="text" name="supplier_invoice_no" id="inv_supplier_invoice_no"
                           value="" maxlength="80" placeholder="رقم فاتورة المورد (اختياري)"
                           autocomplete="off" spellcheck="false" dir="ltr">
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
                        <option value="cash" selected>نقدي</option>
                        <option value="credit">ذمم</option>
                    </select>
                </div>
                <div class="sales-inv-meta-item sales-inv-meta-customer">
                    <label for="inv_supplier">المورد</label>
                    <select class="input input-compact" name="supplier_id" id="inv_supplier" required>
                        <option value="">— اختر المورد —</option>
                        <?php foreach ($suppliers as $s): ?>
                            <option value="<?= (int) $s['id'] ?>"><?= esc((string) $s['name_ar']) ?></option>
                        <?php endforeach; ?>
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
        </header>
            </div>
        </section>

        <div class="sales-inv-print-area" id="sales-inv-print-area">
        <div class="sales-inv-print-only">
            <?= document_print_header_html('فاتورة شراء', $pdo) ?>
        </div>

        <div class="sales-inv-card">
            <div class="sales-inv-lines-header">
                <h3 class="sales-inv-lines-title">بنود الفاتورة</h3>
            </div>
            <div class="sales-inv-table-wrap" id="sales-inv-table-wrap">
                <table class="sales-inv-table">
                    <thead>
                    <?php
                    require_once app_path('includes/inv_invoice_line_table.php');
                    inv_invoice_line_table_head();
                    ?>
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
        </div>

    </form>
    </div>
</div>

<template id="sales-inv-line-template">
    <?php inv_invoice_line_table_row_template($taxRates, $unitPriceStep, $amountStep); ?>
</template>

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

<script src="<?= esc($archiveJsUrl) ?>" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="<?= esc(app_url('assets/js/doc-send-email.js')) ?>" defer></script>
<script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js" crossorigin="anonymous"></script>
<script src="<?= esc($jsItemDisplay) ?>" defer></script>
<script src="<?= esc($jsInvPrint) ?>" defer></script>
<script src="<?= esc(app_url('assets/js/inv-invoice-discount.js')) ?>" defer></script>
<script src="<?= esc($jsInv) ?>" defer></script>
