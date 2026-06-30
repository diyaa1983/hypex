<?php
declare(strict_types=1);

require_once app_path('includes/purchase_order_save.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((string) ($_POST['_action'] ?? ''), ['save_order', 'save_invoice'], true)) {
    handle_purchase_order_post();
}

$pdo = db();
require_once app_path('includes/pur_order_schema.php');
pur_order_ensure_schema($pdo);
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

$exitUrl = nav_exit_url($activeRoute ?? 'purchase_orders');
$newOrderUrl = app_url('index.php?r=purchase_orders');
$apiApprove = app_url('api/purchase_order_approve.php');
$apiUnapprove = app_url('api/purchase_order_unapprove.php');
$canUnapprove = user_can_action('action_unapprove_purchase_order');
$apiDelete = app_url('api/purchase_order_delete.php');
$listOrdersUrl = app_url('index.php?r=purchase_orders_list');
$apiConvert = app_url('api/purchase_order_convert.php');
$canConvert = user_can_action('action_convert_purchase_order');
$initialOrderId = (int) ($_GET['id'] ?? 0);
$apiItems = app_url('api/items_search.php');
$apiOrder = app_url('api/purchase_order_view.php');

$cssInvPath = app_path('assets/css/sales-invoice.css');
$cssInv = app_url('assets/css/sales-invoice.css') . (is_file($cssInvPath) ? '?v=' . (string) filemtime($cssInvPath) : '');
$ora12CssPath = app_path('assets/css/sales-invoice-oracle12.css');
$ora12CssUrl = app_url('assets/css/sales-invoice-oracle12.css') . (is_file($ora12CssPath) ? '?v=' . (string) filemtime($ora12CssPath) : '');
$jsInvPrintPath = app_path('assets/js/inv-invoice-print.js');
$jsInvPrint = app_url('assets/js/inv-invoice-print.js') . (is_readable($jsInvPrintPath) ? '?v=' . (string) filemtime($jsInvPrintPath) : '1');
$jsItemDisplayPath = app_path('assets/js/inv-item-display.js');
$jsItemDisplay = app_url('assets/js/inv-item-display.js') . (is_readable($jsItemDisplayPath) ? '?v=' . (string) filemtime($jsItemDisplayPath) : '1');
$jsPoPath = app_path('assets/js/purchase-order.js');
$jsPo = app_url('assets/js/purchase-order.js') . '?v=' . (is_readable($jsPoPath) ? (string) filemtime($jsPoPath) : '1');
require_once app_path('includes/item_picker.php');

$screenTitle = 'طلب شراء';
?>
<?php sales_inv_oracle12_enqueue_assets(); ?>
<?php item_picker_enqueue_assets(); ?>

<div class="dashboard-ora sales-ora12-screen sales-inv-wrap sales-inv-main sales-inv-bold" data-exit-guard="custom">
    <header class="dashboard-ora-screen-title no-print" role="banner">
        <div class="dashboard-ora-screen-title__group">
            <h1 class="dashboard-ora-screen-title__text"><?= esc($screenTitle) ?></h1>
            <div class="sales-inv-title-actions no-print">
                <a class="dashboard-ora-screen-title__action sales-inv-btn-new sales-inv-title-new"
                   href="<?= esc($newOrderUrl) ?>">+ طلب جديد</a>
                <?php if ($canConvert): ?>
                    <button type="button" class="dashboard-ora-screen-title__action sales-inv-title-action"
                            id="po_convert_invoice_btn" hidden>تحويل إلى فاتورة شراء</button>
                <?php endif; ?>
            </div>
        </div>
        <span class="dashboard-ora-screen-title__meta sales-inv-status-badges">
            <span id="inv_posted_badge" class="sales-inv-posted-badge badge badge-warn" hidden></span>
        </span>
        <?php nav_render_screen_close($activeRoute ?? 'purchase_orders'); ?>
    </header>

    <div class="dashboard-ora-workspace">
    <?php if ($flash): ?>
        <div class="alert no-print alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> sales-inv-grid-flash"><?= esc($flash['message']) ?></div>
    <?php endif; ?>

    <form id="sales-inv-form" class="master-page-form" method="post" action="<?= esc(app_url('index.php?r=purchase_orders')) ?>" novalidate
          data-app-busy-msg="جاري حفظ أمر الشراء..."
          data-api-items="<?= esc($apiItems) ?>"
          data-api-order="<?= esc($apiOrder) ?>"
          data-order-approve-url="<?= esc($apiApprove) ?>"
          data-order-unapprove-url="<?= esc($apiUnapprove) ?>"
          data-can-unpost="<?= $canUnapprove ? '1' : '0' ?>"
          data-order-delete-url="<?= esc($apiDelete) ?>"
          data-order-convert-url="<?= $canConvert ? esc($apiConvert) : '' ?>"
          data-list-url="<?= esc($listOrdersUrl) ?>"
          data-einvoice-send-url=""
          data-send-email-url=""
          data-can-send-einvoice="0"
          data-decimals="<?= (int) $dp ?>"
          data-unit-price-decimals="<?= (int) $unitPriceDp ?>"
          data-print-decimals="<?= (int) $printDp ?>"
          data-print-unit-price-decimals="<?= (int) $printUnitPriceDp ?>"
          data-warehouse-required="<?= count($warehouses) > 0 ? '1' : '0' ?>"
          data-default-warehouse-id="<?= (int) ($defaultWarehouseId ?? 0) ?>"
          data-default-tax-rate="<?= esc((string) $defaultTax) ?>"
          data-default-date="<?= esc(format_date_dmY($today)) ?>"
          data-exit-url="<?= esc($exitUrl) ?>"
          data-ledger-view="0"
          data-new-url="<?= esc($newOrderUrl) ?>"
          data-initial-id="<?= (int) $initialOrderId ?>"
          data-draft-key="purchase_orders"
          data-company-name="<?= esc($companyNameAr) ?>"
          data-company-logo="<?= esc($companyLogoUrl) ?>">
        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="_action" value="save_order">
        <input type="hidden" name="lines_json" id="sales-inv-lines-json" value="[]">

        <section class="dashboard-ora-panel no-print">
            <h2 class="dashboard-ora-panel__title">بيانات الطلب</h2>
            <div class="dashboard-ora-panel__body">
        <header class="sales-inv-doc-header sales-inv-meta-panel">
            <div class="sales-inv-meta-row">
                <div class="sales-inv-meta-item sales-inv-meta-no">
                    <label for="inv_no">رقم الطلب</label>
                    <div class="sales-inv-no-nav">
                        <button type="button" class="sales-inv-no-arrow" id="inv_no_prev" title="الطلب السابق" aria-label="السابق">‹</button>
                        <input class="input input-compact sales-inv-no-input" type="text" id="inv_no" value="" placeholder=""
                               title="يُولَّد الرقم تلقائياً عند الحفظ — للبحث اكتب رقم طلب محفوظ واضغط Enter">
                        <button type="button" class="sales-inv-no-arrow" id="inv_no_next" title="الطلب التالي" aria-label="التالي">›</button>
                    </div>
                    <input type="hidden" name="order_id" id="inv_record_id" value="">
                </div>
                <div class="sales-inv-meta-item sales-inv-meta-supplier-inv-no">
                    <label for="inv_supplier_invoice_no">مرجع المورد / عرض السعر</label>
                    <input class="input input-compact" type="text" name="reference_no" id="inv_supplier_invoice_no"
                           value="" maxlength="80" placeholder="رقم عرض السعر أو مرجع المورد (اختياري)"
                           autocomplete="off" spellcheck="false" dir="ltr">
                </div>
                <div class="sales-inv-meta-item sales-inv-meta-date">
                    <label for="inv_date">تاريخ الطلب</label>
                    <input class="input input-compact js-date-dmy" type="text" name="order_date" id="inv_date"
                           value="<?= esc(format_date_dmY($today)) ?>" placeholder="يوم-شهر-سنة" dir="ltr"
                           autocomplete="off" inputmode="numeric" required>
                </div>
                <div class="sales-inv-meta-item sales-inv-meta-date">
                    <label for="po_expected_date">التسليم المتوقع</label>
                    <input class="input input-compact js-date-dmy" type="text" name="expected_date" id="po_expected_date"
                           value="" placeholder="يوم-شهر-سنة (اختياري)" dir="ltr"
                           autocomplete="off" inputmode="numeric">
                </div>
                <div class="sales-inv-meta-item sales-inv-meta-type">
                    <label for="inv_payment_type">طريقة الدفع</label>
                    <select class="input input-compact" name="payment_type" id="inv_payment_type">
                        <option value="credit" selected>ذمم</option>
                        <option value="cash">نقدي</option>
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
                        <label for="inv_wh">مستودع الاستلام</label>
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
            <?= document_print_header_html('طلب شراء', $pdo) ?>
        </div>

        <div class="sales-inv-card">
            <div class="sales-inv-lines-header">
                <h3 class="sales-inv-lines-title">بنود الطلب</h3>
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
                    <?php if ($initialOrderId < 1): ?>
                        <?php require app_path('includes/inv_invoice_entry_row.php'); ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="sales-inv-footer-grid">
            <div class="sales-inv-notes sales-inv-field">
                <label for="inv_notes">ملاحظات</label>
                <textarea class="input" name="notes" id="inv_notes" rows="3" placeholder="شروط التسليم، ملاحظات للمورد…"></textarea>
            </div>
            <div class="sales-inv-totals">
                <div class="row sales-inv-totals-disc">
                    <label for="inv-invoice-discount">خصم الطلب <span class="sales-inv-disc-hint">10% أو مبلغ</span></label>
                    <input type="text" class="input input-compact input-num" name="invoice_discount" id="inv-invoice-discount"
                           value="" autocomplete="off">
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

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="<?= esc($jsItemDisplay) ?>" defer></script>
<script src="<?= esc($jsInvPrint) ?>" defer></script>
<script src="<?= esc(app_url('assets/js/inv-invoice-discount.js')) ?>" defer></script>
<script src="<?= esc($jsPo) ?>" defer></script>
