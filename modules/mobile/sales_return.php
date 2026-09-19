<?php
declare(strict_types=1);

require_once app_path('includes/sales_return_post.php');
require_once app_path('includes/mobile_auth.php');
require_once app_path('includes/mobile_return.php');
require_once app_path('includes/mobile_icons.php');
require_once app_path('includes/sal_return_schema.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'save_return') {
    handle_sales_return_post();
}

$pdo = db();
$schemaOk = sal_return_ensure_schema($pdo);

$flash = flash_get();
require_once app_path('includes/crm_sales_rep_schema.php');
$customers = crm_mobile_customers_for_picker($pdo, 800);

$dp = company_decimal_places($pdo);
$today = date('Y-m-d');
$editReturnId = (int) ($_GET['id'] ?? 0);
$saveUrl = mobile_url('r=m_sales_returns');
$invoicesApi = app_url('api/mobile_return_invoices.php');
$linesApi = app_url('api/mobile_return_lines.php');
$returnApi = app_url('api/sales_return_view.php');
$postApi = app_url('api/sales_return_post.php');
$deleteApi = app_url('api/sales_return_delete.php');
$printApi = app_url('api/mobile_return_print.php');

$srJsV = is_file(app_path('assets/mobile/sales-return.js'))
    ? (string) filemtime(app_path('assets/mobile/sales-return.js'))
    : '';
$returnDocIcon = mobile_icon_tile('return');
?>
<?php if ($flash): ?>
<div class="m-alert m-alert--<?= esc($flash['type'] === 'error' ? 'error' : 'success') ?>">
    <?= esc($flash['message']) ?>
</div>
<?php endif; ?>

<p class="m-doc-list-link-wrap">
    <a class="m-doc-list-link" href="<?= esc(mobile_url('r=m_sales_returns_list')) ?>">← قائمة المرتجعات</a>
</p>

<?php if (!$schemaOk): ?>
<div class="m-alert m-alert--error">تعذر تجهيز جداول مرتجع المبيعات. نفّذ ترحيل قاعدة البيانات.</div>
<?php endif; ?>

<div id="m-ret-status-banner" class="m-alert" hidden role="status"></div>

<form id="m-ret-form" class="m-invoice-form m-ret-form" method="post" action="<?= esc($saveUrl) ?>">
    <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
    <input type="hidden" name="_action" value="save_return">
    <input type="hidden" name="return_id" id="m-ret-record-id" value="<?= (int) $editReturnId ?>">
    <input type="hidden" name="lines_json" id="m-ret-lines-json" value="[]">
    <input type="hidden" name="customer_id" id="m-ret-customer-id" value="" required>
    <input type="hidden" name="invoice_id" id="m-ret-invoice-id" value="">

    <section class="m-card m-card--header m-card--doc-entry">
        <div class="m-doc-entry-head">
            <span class="m-tile-icon-wrap m-doc-entry-icon <?= esc($returnDocIcon['class']) ?>" aria-hidden="true"><?= $returnDocIcon['html'] ?></span>
            <div class="m-doc-entry-head-text">
                <p class="m-doc-entry-kicker">إدخال مستند</p>
                <h2 class="m-card-title">مرتجع مبيعات</h2>
            </div>
        </div>
        <p class="m-ret-hint muted">يُسمح بإرجاع <strong>فواتير بيع مرحّلة</strong> فقط. اختر العميل والفاتورة ثم حدّد المواد وكميات الإرجاع.</p>
        <div class="m-meta-grid">
            <label class="m-field">
                <span class="m-field-label">تاريخ الإرجاع</span>
                <input class="m-input" type="date" name="return_date" id="m-ret-date" value="<?= esc($today) ?>" required>
            </label>
            <div class="m-field m-field--full m-ps-pick-block" id="m-ret-pick-customer">
                <span class="m-field-label">العميل</span>
                <div class="m-customer-chosen" id="m-ret-customer-chosen" hidden>
                    <span class="m-customer-chosen-name" id="m-ret-customer-label"></span>
                    <button type="button" class="m-ps-pick-clear" id="m-ret-clear-customer" aria-label="إلغاء اختيار العميل">×</button>
                </div>
                <button type="button" class="m-btn m-btn--pick m-btn--block" id="m-ret-open-customer">
                    <svg class="m-btn-pick-ico" viewBox="0 0 16 16" width="18" height="18" aria-hidden="true" focusable="false">
                        <rect x="1.5" y="1.5" width="5.5" height="5.5" rx="1" fill="currentColor"/>
                        <rect x="9" y="1.5" width="5.5" height="5.5" rx="1" fill="currentColor"/>
                        <rect x="1.5" y="9" width="5.5" height="5.5" rx="1" fill="currentColor"/>
                        <rect x="9" y="9" width="5.5" height="5.5" rx="1" fill="currentColor"/>
                    </svg>
                    <span class="m-btn-pick-label">اختيار العميل</span>
                </button>
            </div>
            <div class="m-field m-field--full">
                <span class="m-field-label">فاتورة البيع</span>
                <div class="m-customer-chosen" id="m-ret-invoice-chosen" hidden>
                    <span class="m-customer-chosen-name" id="m-ret-invoice-label"></span>
                </div>
                <button type="button" class="m-btn m-btn--pick m-btn--block" id="m-ret-open-invoice" disabled>
                    <svg class="m-btn-pick-ico" viewBox="0 0 16 16" width="18" height="18" aria-hidden="true" focusable="false">
                        <path fill="currentColor" d="M3 2h10v12H3zm1 1v2h8V3zm0 3v7h8V6z"/>
                    </svg>
                    <span class="m-btn-pick-label">اختيار فاتورة البيع</span>
                </button>
            </div>
            <label class="m-field m-field--full">
                <span class="m-field-label">ملاحظات</span>
                <input class="m-input" type="text" name="notes" id="m-ret-notes" placeholder="اختياري">
            </label>
            <label class="m-field m-field--full" id="m-ret-reason-wrap">
                <span class="m-field-label">سبب الإرجاع <span class="muted">(للفوترة الإلكترونية)</span></span>
                <textarea class="m-input" name="reason_return" id="m-ret-reason" rows="2" placeholder="مثال: منتج معيب"></textarea>
            </label>
        </div>
    </section>

    <section class="m-card">
        <div class="m-card-head">
            <h2 class="m-card-title">مواد المرتجع</h2>
            <span class="m-lines-count muted" id="m-ret-lines-count">0 سطر</span>
        </div>
        <p class="m-lines-empty muted" id="m-ret-lines-empty">اختر العميل وفاتورة البيع لعرض المواد القابلة للإرجاع.</p>
        <div class="m-ret-lines-wrap" id="m-ret-lines-wrap" hidden>
            <div class="m-ret-pick-all-wrap">
                <label class="m-ret-pick-all">
                    <input type="checkbox" id="m-ret-pick-all" aria-label="تحديد الكل">
                    <span>تحديد الكل</span>
                </label>
            </div>
            <div id="m-ret-lines" class="m-ret-lines" aria-live="polite"></div>
        </div>
    </section>

    <section class="m-card m-card--total">
        <div class="m-total-grid">
            <div class="m-total-row">
                <span>قبل الضريبة</span>
                <span id="m-ret-subtotal">0</span>
            </div>
            <div class="m-total-row">
                <span>الضريبة</span>
                <span id="m-ret-tax-total">0</span>
            </div>
            <div class="m-total-row m-total-row--grand">
                <span>الإجمالي</span>
                <strong id="m-ret-grand-total">0</strong>
            </div>
        </div>
    </section>
</form>

<div id="m-ret-customer-picker" class="m-picker m-picker--customers" hidden aria-hidden="true">
    <header class="m-picker-head">
        <button type="button" class="m-picker-back" id="m-ret-customer-close" aria-label="رجوع">← رجوع</button>
        <h3 class="m-picker-title">اختيار العميل</h3>
    </header>
    <div class="m-picker-search-wrap">
        <input type="search" class="m-input" id="m-ret-customer-search" placeholder="بحث بالاسم..." autocomplete="off">
    </div>
    <div class="m-picker-body">
        <div id="m-ret-customer-grid" class="m-customer-grid" role="listbox" aria-label="قائمة العملاء"></div>
        <p id="m-ret-customer-empty" class="m-picker-empty muted" hidden>لا نتائج</p>
    </div>
</div>

<div id="m-ret-invoice-picker" class="m-picker" hidden aria-hidden="true">
    <header class="m-picker-head">
        <button type="button" class="m-picker-back" id="m-ret-invoice-close" aria-label="رجوع">← رجوع</button>
        <h3 class="m-picker-title">اختيار فاتورة البيع</h3>
    </header>
    <div class="m-picker-body">
        <div id="m-ret-invoice-list" class="m-ret-invoice-list" role="listbox"></div>
        <p class="m-picker-empty muted" id="m-ret-invoice-empty" hidden>لا توجد فواتير مرحّلة قابلة للإرجاع لهذا العميل</p>
        <p class="m-picker-loading muted" id="m-ret-invoice-loading" hidden>جاري التحميل...</p>
    </div>
</div>

<div id="m-ret-pdf-overlay" class="m-inv-pdf-overlay" hidden aria-hidden="true">
    <div id="m-ret-pdf-preview" class="m-inv-pdf-preview"></div>
</div>

<script>
    window.MSalesReturn = {
        invoicesApi: <?= json_encode($invoicesApi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        linesApi: <?= json_encode($linesApi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        returnApi: <?= json_encode($returnApi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        postApi: <?= json_encode($postApi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        deleteApi: <?= json_encode($deleteApi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        editReturnId: <?= (int) $editReturnId ?>,
        startEdit: <?= (isset($_GET['edit']) && (int) $editReturnId > 0) ? 'true' : 'false' ?>,
        newUrl: <?= json_encode(mobile_url('r=m_sales_returns'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        canEdit: <?= mobile_can_edit_sales_return() ? 'true' : 'false' ?>,
        csrf: <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE) ?>,
        decimalPlaces: <?= (int) $dp ?>,
        customers: <?= json_encode($customers, JSON_UNESCAPED_UNICODE) ?>,
        canPost: <?= mobile_can_post_sales_return() ? 'true' : 'false' ?>,
        canDelete: <?= mobile_can_delete_sales_return() ? 'true' : 'false' ?>,
        printApi: <?= json_encode($printApi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
    };
</script>
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
<script src="<?= esc(app_url('assets/mobile/sales-return.js')) ?><?= $srJsV !== '' ? '?v=' . esc($srJsV) : '' ?>"></script>
