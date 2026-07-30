<?php
declare(strict_types=1);

require_once app_path('includes/sales_invoice_post.php');
require_once app_path('includes/mobile_auth.php');
require_once app_path('includes/mobile_invoice.php');
require_once app_path('includes/mobile_icons.php');
require_once app_path('includes/app_gps.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'save_invoice') {
    handle_sales_invoice_post();
}

$pdo = db();
require_once app_path('includes/sal_invoice_schema.php');
sal_invoice_ensure_schema($pdo);

$flash = flash_get();
require_once app_path('includes/crm_sales_rep_schema.php');
$customers = crm_mobile_customers_for_picker($pdo, 500);
require_once app_path('includes/warehouse_access.php');
$warehouses = wh_access_list_warehouses($pdo, 'issue');
$defaultWarehouseId = wh_access_default_issue_warehouse_id($pdo);

$settings = company_settings($pdo);
$dp = company_decimal_places($pdo);
$defaultTax = (float) ($settings['tax_rate_percent'] ?? 15);
$today = date('Y-m-d');
$todayDmy = date('d/m/Y');

$taxRates = [];
try {
    $taxRates = $pdo->query(
        'SELECT id, name_ar, rate_percent FROM sys_tax_rate WHERE is_active = 1 ORDER BY sort_order, id'
    )->fetchAll();
} catch (Throwable $e) {
    $taxRates = [];
}
if (!$taxRates) {
    $taxRates = [['id' => 0, 'name_ar' => 'افتراضي (' . $defaultTax . '%)', 'rate_percent' => $defaultTax]];
}
$mobileDefaultTaxPercent = 5.0;
$defaultTaxRateId = (int) ($taxRates[0]['id'] ?? 0);
$foundMobileTax = false;
foreach ($taxRates as $tr) {
    if (abs((float) $tr['rate_percent'] - $mobileDefaultTaxPercent) < 0.001) {
        $defaultTaxRateId = (int) $tr['id'];
        $foundMobileTax = true;
        break;
    }
}
if (!$foundMobileTax) {
    foreach ($taxRates as $tr) {
        if (abs((float) $tr['rate_percent'] - $defaultTax) < 0.001) {
            $defaultTaxRateId = (int) $tr['id'];
            break;
        }
    }
}

$itemsApi = app_url('api/items_search.php');
$saveUrl = mobile_url('r=m_sales_invoices');
$editInvoiceId = (int) ($_GET['id'] ?? 0);
$invoiceViewApi = app_url('api/sales_invoice_view.php');
$invoicePrintApi = app_url('api/mobile_invoice_print.php');
$invoiceDeleteApi = app_url('api/sales_invoice_delete.php');
$canDeleteInvoice = mobile_can_delete_sales_invoice();
$canArchiveInvoice = mobile_can_archive_sales_invoice();
$canEditInvoice = mobile_can_edit_sales_invoice();
$archiveApiUrl = app_absolute_url('api/fin_voucher_archive.php');

$siJsV = is_file(app_path('assets/mobile/sales-invoice.js'))
    ? (string) filemtime(app_path('assets/mobile/sales-invoice.js'))
    : '';
?>
<div class="m-ora12 m-ora12-invoice m-inv-app">
<div class="m-ora12-workspace">
<?php if ($flash): ?>
<div class="m-alert m-alert--<?= esc($flash['type'] === 'error' ? 'error' : 'success') ?>">
    <?= esc($flash['message']) ?>
</div>
<?php endif; ?>

<div id="m-invoice-edit-banner" class="m-alert" hidden role="status"></div>

<form id="m-invoice-form" class="m-invoice-form" method="post" action="<?= esc($saveUrl) ?>">
    <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
    <input type="hidden" name="_action" value="save_invoice">
    <input type="hidden" name="invoice_id" id="m-invoice-id" value="<?= (int) $editInvoiceId ?>">
    <input type="hidden" name="lines_json" id="m-lines-json" value="[]">
    <input type="hidden" name="customer_id" id="m-customer-id" value="" required>

    <section class="m-inv-app-sheet" aria-label="بيانات الفاتورة">
        <button type="button" class="m-inv-app-customer" id="m-open-customer-picker">
            <span class="m-inv-app-customer__avatar" id="m-customer-avatar" aria-hidden="true">
                <span class="m-inv-app-customer__avatar-empty" aria-hidden="true">👤</span>
            </span>
            <span class="m-inv-app-customer__info">
                <span class="m-inv-app-customer__lbl">العميل</span>
                <span class="m-inv-app-customer__name is-placeholder" id="m-customer-label">اضغط لاختيار العميل</span>
            </span>
            <span class="m-inv-app-customer__arrow" aria-hidden="true">‹</span>
        </button>

        <button type="button" class="m-inv-app-items-btn" id="m-open-picker">
            <svg class="m-inv-app-items-btn__ico" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false">
                <path fill="currentColor" d="M11 11V5h2v6h6v2h-6v6h-2v-6H5v-2h6z"/>
            </svg>
            <span>إضافة مواد</span>
        </button>

        <div class="m-inv-app-meta">
            <label class="m-inv-app-meta__field m-inv-app-meta__field--date">
                <span class="m-inv-app-meta__lbl">التاريخ</span>
                <input class="m-inv-app-meta__input m-input--date-digits" type="text" name="invoice_date" id="m-invoice-date"
                    value="<?= esc($todayDmy) ?>" inputmode="numeric" autocomplete="off"
                    placeholder="يوم/شهر/سنة" dir="ltr" required>
            </label>
            <label class="m-inv-app-meta__field">
                <span class="m-inv-app-meta__lbl">النوع</span>
                <select class="m-inv-app-meta__input m-select" name="payment_type" id="m-payment-type">
                    <option value="credit" selected>ذمة</option>
                    <option value="cash">نقدي</option>
                </select>
            </label>
            <?php if (count($warehouses) > 0): ?>
            <label class="m-inv-app-meta__field m-inv-app-meta__field--wh">
                <span class="m-inv-app-meta__lbl">المستودع</span>
                <select class="m-inv-app-meta__input m-select m-select--truncate" name="warehouse_id" id="m-warehouse-id" required>
                    <?php foreach ($warehouses as $w): ?>
                    <option value="<?= (int) $w['id'] ?>"<?= (int) $w['id'] === $defaultWarehouseId ? ' selected' : '' ?>>
                        <?= esc((string) $w['name_ar']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php else: ?>
            <div class="m-alert m-alert--error m-inv-app-meta__field--full">
                لا توجد صلاحية صرف من أي مستودع. راجع صلاحيات المستودعات للمجموعة.
            </div>
            <input type="hidden" name="warehouse_id" value="0" id="m-warehouse-id">
            <?php endif; ?>
        </div>

        <label class="m-inv-app-notes">
            <span class="m-inv-app-notes__lbl">ملاحظات</span>
            <input class="m-inv-app-notes__input" type="text" name="notes" placeholder="اختياري">
        </label>
    </section>

    <section class="m-inv-app-section" aria-label="بنود الفاتورة">
        <div class="m-inv-app-section__head">
            <h2 class="m-inv-app-section__title">بنود الفاتورة</h2>
            <span class="m-inv-app-section__badge" id="m-lines-count">0 سطر</span>
        </div>
        <p class="m-inv-app-empty muted" id="m-lines-empty">لا توجد بنود — اضغط «إضافة مواد»، اختر المادة، أدخل الكمية والسعر.</p>
        <p class="m-lines-swipe-hint muted" id="m-lines-swipe-hint" hidden>اضغط مطوّلاً على البند ثم اسحب لليمين للحذف</p>
        <div class="m-lines-list" id="m-lines-list" hidden aria-live="polite"></div>
    </section>

    <section class="m-inv-app-totals" aria-label="المجاميع">
        <div class="m-inv-app-totals__row">
            <span>قبل الضريبة</span>
            <span id="m-subtotal">0</span>
        </div>
        <div class="m-inv-app-totals__row">
            <span>الضريبة</span>
            <span id="m-tax-total">0</span>
        </div>
        <div class="m-inv-app-totals__row m-inv-app-totals__row--grand">
            <span>الإجمالي</span>
            <strong id="m-grand-total">0</strong>
        </div>
    </section>

</form>
</div>
</div>

<!-- شاشة اختيار العميل — شبكة مربعات -->
<div id="m-customer-picker" class="m-picker m-picker--customers" hidden aria-hidden="true">
    <header class="m-picker-head">
        <button type="button" class="m-picker-back" id="m-customer-picker-close" aria-label="رجوع">← رجوع</button>
        <h3 class="m-picker-title"><span class="m-picker-title-ico" aria-hidden="true">👥</span> اختيار العميل</h3>
    </header>
    <div class="m-picker-search">
        <span class="m-picker-search-ico" aria-hidden="true">🔍</span>
        <input class="m-input m-input--sm" type="search" id="m-customer-picker-search" placeholder="بحث باسم العميل..." autocomplete="off">
    </div>
    <div class="m-picker-body">
        <div id="m-customer-grid" class="m-customer-grid" role="listbox" aria-label="قائمة العملاء"></div>
        <p class="m-picker-empty muted" id="m-customer-picker-empty" hidden>لا يوجد عملاء</p>
    </div>
    <footer class="m-picker-foot">
        <p class="m-picker-hint muted">اضغط على العميل لاختياره والعودة للفاتورة</p>
    </footer>
</div>

<!-- شاشة اختيار المواد — شبكة مربعات -->
<div id="m-item-picker" class="m-picker" hidden aria-hidden="true">
    <header class="m-picker-head">
        <button type="button" class="m-picker-back" id="m-picker-close" aria-label="رجوع">← رجوع</button>
        <h3 class="m-picker-title">اختيار المواد</h3>
        <span class="m-picker-count muted" id="m-picker-selected-count">0</span>
    </header>
    <div class="m-picker-search">
        <input class="m-input m-input--sm" type="search" id="m-picker-search" placeholder="بحث: اسم، رمز، باركود..." autocomplete="off">
    </div>
    <div class="m-picker-body">
        <div id="m-item-grid" class="m-item-grid" role="listbox" aria-label="قائمة المواد"></div>
        <p class="m-picker-empty muted" id="m-picker-empty" hidden>لا توجد مواد</p>
        <p class="m-picker-loading muted" id="m-picker-loading">جاري التحميل...</p>
    </div>
    <footer class="m-picker-foot">
        <p class="m-picker-hint muted">اضغط على المادة لإدخال الكمية والسعر — مادة مادة</p>
        <button type="button" class="m-btn m-btn--primary m-btn--block" id="m-picker-done">تم</button>
    </footer>
    <div id="m-item-quick" class="m-item-quick" hidden aria-hidden="true">
        <div class="m-item-quick-backdrop" id="m-item-quick-backdrop"></div>
        <div class="m-item-quick-panel" role="dialog" aria-labelledby="m-item-quick-name">
            <h4 class="m-item-quick-title" id="m-item-quick-name"></h4>
            <p class="m-item-quick-code muted" id="m-item-quick-code"></p>
            <div class="m-item-quick-grid">
                <label class="m-inv-mini">
                    <span>الكمية</span>
                    <input type="text" class="m-input m-input--sm m-input--num" id="m-item-quick-qty" inputmode="decimal" autocomplete="off" placeholder="">
                </label>
                <label class="m-inv-mini">
                    <span>السعر الإفرادي</span>
                    <input type="text" class="m-input m-input--sm m-input--num" id="m-item-quick-unit" inputmode="decimal" autocomplete="off" placeholder="">
                </label>
                <label class="m-inv-mini m-inv-mini--full">
                    <span>السعر الإجمالي (قبل الضريبة)</span>
                    <input type="text" class="m-input m-input--sm m-input--num" id="m-item-quick-total" inputmode="decimal" autocomplete="off" placeholder="">
                </label>
            </div>
            <div class="m-item-quick-actions">
                <button type="button" class="m-btn m-btn--secondary" id="m-item-quick-cancel">إلغاء</button>
                <button type="button" class="m-btn m-btn--primary" id="m-item-quick-confirm">تأكيد</button>
            </div>
        </div>
    </div>
</div>

<script>
    window.MSalesInvoice = {
        itemsApi: <?= json_encode($itemsApi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        invoiceApi: <?= json_encode($invoiceViewApi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        editInvoiceId: <?= (int) $editInvoiceId ?>,
        editUrl: <?= json_encode(mobile_url('r=m_sales_invoices'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        viewUrl: <?= json_encode(mobile_url('r=m_sales_invoice_view'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        printApi: <?= json_encode($invoicePrintApi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        deleteApi: <?= json_encode($invoiceDeleteApi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        csrf: <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE) ?>,
        canDelete: <?= $canDeleteInvoice ? 'true' : 'false' ?>,
        canEdit: <?= $canEditInvoice ? 'true' : 'false' ?>,
        canArchive: <?= $canArchiveInvoice ? 'true' : 'false' ?>,
        archiveApi: <?= json_encode($archiveApiUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        canPost: <?= mobile_can_post_sales_invoice() ? 'true' : 'false' ?>,
        postApi: <?= json_encode(app_absolute_url('api/sales_invoice_post.php'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        gpsEnabled: <?= app_gps_enabled() ? 'true' : 'false' ?>,
        decimalPlaces: <?= (int) $dp ?>,
        defaultTax: <?= json_encode($defaultTax, JSON_UNESCAPED_UNICODE) ?>,
        mobileDefaultTax: <?= json_encode($mobileDefaultTaxPercent, JSON_UNESCAPED_UNICODE) ?>,
        defaultTaxRateId: <?= (int) $defaultTaxRateId ?>,
        taxRates: <?= json_encode($taxRates, JSON_UNESCAPED_UNICODE) ?>,
        customers: <?= json_encode($customers, JSON_UNESCAPED_UNICODE) ?>,
        trashIconHtml: <?= json_encode(mobile_icon_svg('trash'), JSON_UNESCAPED_UNICODE) ?>,
        mobileSave: true
    };
    (function () {
        var TB = window.MobileToolbar;
        var cfg = window.MSalesInvoice;
        if (!TB || !TB.show || !cfg) return;
        if (TB.pinToBottomDock) TB.pinToBottomDock();
        document.body.classList.remove('m-inv-actions-inline');
        document.body.classList.add('m-inv-full-toolbar');
        var vis = {
            save: true, post: true, delete: true, edit: true,
            print: true, pdf: true, archive: true
        };
        if (!cfg.canPost) delete vis.post;
        if (!cfg.canDelete) delete vis.delete;
        if (!cfg.canEdit) delete vis.edit;
        if (!cfg.canArchive) {
            delete vis.archive;
        }
        TB.show(vis, {
            formId: 'm-invoice-form',
            disabled: {
                post: true, delete: true, edit: true,
                print: true, pdf: true
            }
        });
    })();
</script>
<?php
$discJsV = is_file(app_path('assets/js/inv-invoice-discount.js'))
    ? (string) filemtime(app_path('assets/js/inv-invoice-discount.js'))
    : '';
?>
<script src="<?= esc(app_url('assets/js/inv-invoice-discount.js')) ?><?= $discJsV !== '' ? '?v=' . esc($discJsV) : '' ?>"></script>
<?php
$gridJsV = is_file(app_path('assets/mobile/doc-list-grid.js'))
    ? (string) filemtime(app_path('assets/mobile/doc-list-grid.js'))
    : '';
?>
<script src="<?= esc(app_url('assets/mobile/doc-list-grid.js')) ?><?= $gridJsV !== '' ? '?v=' . esc($gridJsV) : '' ?>"></script>
<?php
$swipeJsV = is_file(app_path('assets/mobile/inv-line-swipe.js'))
    ? (string) filemtime(app_path('assets/mobile/inv-line-swipe.js'))
    : '';
?>
<script src="<?= esc(app_url('assets/mobile/inv-line-swipe.js')) ?><?= $swipeJsV !== '' ? '?v=' . esc($swipeJsV) : '' ?>"></script>
<?php
$photoArchJsV = is_file(app_path('assets/mobile/invoice-photo-archive.js'))
    ? (string) filemtime(app_path('assets/mobile/invoice-photo-archive.js'))
    : '';
?>
<script src="<?= esc(app_url('assets/mobile/invoice-photo-archive.js')) ?><?= $photoArchJsV !== '' ? '?v=' . esc($photoArchJsV) : '' ?>"></script>
<script src="<?= esc(app_url('assets/mobile/sales-invoice.js')) ?><?= $siJsV !== '' ? '?v=' . esc($siJsV) : '' ?>"></script>
