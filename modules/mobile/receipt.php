<?php
declare(strict_types=1);

require_once app_path('includes/fin_receipt_save.php');
require_once app_path('includes/mobile_auth.php');
require_once app_path('includes/mobile_receipt.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'save_receipt') {
    handle_fin_receipt_save();
}

$pdo = db();
require_once app_path('includes/fin_voucher_schema.php');
require_once app_path('includes/crm_sales_rep_schema.php');

if (!fin_voucher_ensure_schema_full($pdo)) {
    echo '<div class="m-alert m-alert--error">جدول سندات القبض غير متوفر.</div>';
    return;
}

$cashAccounts = fin_voucher_load_cash_accounts($pdo);
if (!$cashAccounts) {
    echo '<div class="m-alert m-alert--error">لا توجد حسابات صندوق/بنك.</div>';
    return;
}

crm_sales_rep_ensure_customer_invoice_links($pdo);
$customers = $pdo->query(
    'SELECT c.id, c.name_ar, c.sales_rep_id, r.name_ar AS sales_rep_name
     FROM crm_customer c
     LEFT JOIN crm_sales_rep r ON r.id = c.sales_rep_id AND r.is_active = 1
     WHERE c.is_active = 1
     ORDER BY c.name_ar
     LIMIT 800'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

require_once app_path('includes/acc_gl.php');
$cashBoxAccountId = acc_gl_cash_box_account_id($pdo);
$defaultCashId = $cashBoxAccountId > 0 ? $cashBoxAccountId : (int) ($cashAccounts[0]['id'] ?? 0);
$bankAccountId = acc_gl_receipt_bank_deposit_account_id($pdo);
require_once app_path('includes/acc_coa_bootstrap.php');
$checksFundAccountId = (int) (acc_coa_ensure_checks_fund_account($pdo)['account_id'] ?? 0);

$flash = flash_get();
$today = format_date_dmY(date('Y-m-d'));
$initialId = (int) ($_GET['id'] ?? 0);
$dp = company_decimal_places($pdo);

$jsV = is_file(app_path('assets/mobile/receipt.js'))
    ? (string) filemtime(app_path('assets/mobile/receipt.js'))
    : '';
?>
<?php if ($flash): ?>
<div class="m-alert m-alert--<?= esc($flash['type'] === 'error' ? 'error' : 'success') ?>">
    <?= esc($flash['message']) ?>
</div>
<?php endif; ?>

<div id="m-rc-status" class="m-alert" hidden role="status"></div>

<form id="m-rc-form" class="m-invoice-form" method="post" action="<?= esc(mobile_url('r=m_receipt')) ?>" novalidate>
    <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
    <input type="hidden" name="_action" value="save_receipt">
    <input type="hidden" name="voucher_id" id="m-rc-id" value="<?= (int) $initialId ?>">
    <input type="hidden" name="party_type" value="customer">
    <input type="hidden" name="customer_id" id="m-rc-customer-id" value="">
    <input type="hidden" name="cash_account_id" id="m-rc-cash-account" value="<?= (int) $defaultCashId ?>">
    <input type="hidden" name="check_amount" id="m-rc-check-amount-hidden" value="">
    <input type="hidden" name="check_no" id="m-rc-check-no-hidden" value="">
    <input type="hidden" name="bank_name" id="m-rc-bank-hidden" value="">

    <section class="m-card m-card--doc-entry">
        <p class="m-doc-entry-kicker">إدخال مستند</p>
        <div class="m-card-head">
            <h2 class="m-card-title">سند قبض</h2>
            <span id="m-rc-posted-badge" class="m-tag m-tag--warn" hidden>غير مرحّل</span>
        </div>
        <div class="m-meta-grid">
            <label class="m-field">
                <span class="m-field-label">رقم السند</span>
                <input class="m-input" type="text" name="voucher_no" id="m-rc-no" placeholder="يُولَّد عند الحفظ" autocomplete="off">
            </label>
            <label class="m-field">
                <span class="m-field-label">التاريخ *</span>
                <input class="m-input" type="text" name="voucher_date" id="m-rc-date" required
                       inputmode="numeric" placeholder="يوم-شهر-سنة" dir="ltr" value="<?= esc($today) ?>">
            </label>
            <div class="m-field m-field--full m-field--customer-pick">
                <span class="m-field-label">العميل *</span>
                <div class="m-customer-chosen" id="m-rc-customer-chosen" hidden>
                    <span class="m-customer-chosen-name" id="m-rc-customer-label"></span>
                </div>
                <button type="button" class="m-btn m-btn--pick m-btn--block" id="m-rc-open-customer">
                    <svg class="m-btn-pick-ico" viewBox="0 0 16 16" width="18" height="18" aria-hidden="true"><rect x="1.5" y="1.5" width="5.5" height="5.5" rx="1" fill="currentColor"/><rect x="9" y="1.5" width="5.5" height="5.5" rx="1" fill="currentColor"/><rect x="1.5" y="9" width="5.5" height="5.5" rx="1" fill="currentColor"/><rect x="9" y="9" width="5.5" height="5.5" rx="1" fill="currentColor"/></svg>
                    <span class="m-btn-pick-label">اختيار العميل</span>
                </button>
            </div>
            <label class="m-field m-field--full">
                <span class="m-field-label">المندوب</span>
                <input class="m-input" type="text" id="m-rc-rep" readonly tabindex="-1" placeholder="—">
            </label>
            <div class="m-field m-field--full">
                <span class="m-field-label">طريقة الدفع</span>
                <div class="m-seg" role="group">
                    <label class="m-seg-item"><input type="radio" name="pay_method" value="cash" id="m-rc-pay-cash" checked> نقداً</label>
                    <label class="m-seg-item"><input type="radio" name="pay_method" value="bank" id="m-rc-pay-bank"> بنك</label>
                    <label class="m-seg-item"><input type="radio" name="pay_method" value="check" id="m-rc-pay-check"> شيك</label>
                </div>
            </div>
            <label class="m-field m-field--full" id="m-rc-amount-wrap">
                <span class="m-field-label">المبلغ *</span>
                <input class="m-input" type="text" name="amount" id="m-rc-amount" inputmode="decimal" dir="ltr" placeholder="0">
            </label>
            <label class="m-field m-field--full">
                <span class="m-field-label">وذلك عن</span>
                <textarea class="m-input" name="notes" id="m-rc-notes" rows="2" placeholder="بيان السند"></textarea>
            </label>
        </div>
    </section>

    <section class="m-card" id="m-rc-checks-section" hidden>
        <div class="m-card-head">
            <h2 class="m-card-title">الشيكات</h2>
            <button type="button" class="m-btn m-btn--ghost m-btn--sm" id="m-rc-check-add">+ شيك</button>
        </div>
        <div id="m-rc-checks-list" class="m-rc-checks-list"></div>
    </section>

    <div class="m-inv-view-extra m-inv-view-extra--receipt">
        <a class="m-btn m-btn--ghost" href="<?= esc(mobile_url('r=m_receipt')) ?>">جديد</a>
        <a class="m-btn m-btn--ghost" href="<?= esc(mobile_url('r=m_receipt_list')) ?>">القائمة</a>
    </div>
</form>

<div id="m-rc-customer-picker" class="m-picker m-picker--customers" hidden aria-hidden="true">
    <header class="m-picker-head">
        <button type="button" class="m-picker-back" id="m-rc-customer-close" aria-label="رجوع">← رجوع</button>
        <h3 class="m-picker-title">اختيار العميل</h3>
    </header>
    <div class="m-picker-search-wrap">
        <input type="search" class="m-input" id="m-rc-customer-search" placeholder="بحث..." autocomplete="off">
    </div>
    <div class="m-picker-body">
        <div id="m-rc-customer-grid" class="m-customer-grid"></div>
        <p id="m-rc-customer-empty" class="m-picker-empty muted" hidden>لا نتائج</p>
    </div>
</div>

<div id="m-rc-pdf-overlay" class="m-inv-pdf-overlay" hidden aria-hidden="true">
    <div id="m-rc-pdf-preview" class="m-inv-pdf-preview"></div>
</div>

<template id="m-rc-check-tpl">
    <article class="m-rc-check-card">
        <div class="m-rc-check-head">
            <strong class="m-rc-check-title">شيك</strong>
            <button type="button" class="m-btn m-btn--danger m-btn--sm m-rc-check-remove" title="حذف">×</button>
        </div>
        <div class="m-meta-grid">
            <label class="m-field"><span class="m-field-label">رقم الشيك</span><input class="m-input m-rc-chk-no" type="text" dir="ltr"></label>
            <label class="m-field"><span class="m-field-label">المبلغ</span><input class="m-input m-rc-chk-amt" type="text" inputmode="decimal" dir="ltr"></label>
            <label class="m-field m-field--full"><span class="m-field-label">البنك</span><input class="m-input m-rc-chk-bank" type="text"></label>
            <label class="m-field m-field--full"><span class="m-field-label">الاستحقاق</span><input class="m-input m-rc-chk-due" type="text" inputmode="numeric" placeholder="يوم-شهر-سنة" dir="ltr"></label>
        </div>
    </article>
</template>

<div id="m-rc-pdf-overlay" class="m-inv-pdf-overlay" hidden aria-hidden="true">
    <div id="m-rc-pdf-preview" class="m-inv-pdf-preview"></div>
</div>

<script>
window.MReceipt = {
    initialId: <?= (int) $initialId ?>,
    decimalPlaces: <?= (int) $dp ?>,
    customers: <?= json_encode($customers, JSON_UNESCAPED_UNICODE) ?>,
    saveUrl: <?= json_encode(mobile_url('r=m_receipt'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    viewApi: <?= json_encode(app_url('api/fin_receipt_view.php'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    postApi: <?= json_encode(app_url('api/fin_receipt_post.php'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    printApi: <?= json_encode(app_url('api/mobile_receipt_print.php'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    deleteApi: <?= json_encode(app_url('api/fin_receipt_delete.php'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    listUrl: <?= json_encode(mobile_url('r=m_receipt_list'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    csrf: <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE) ?>,
    canPost: <?= mobile_can_post_receipt() ? 'true' : 'false' ?>,
    canDelete: <?= mobile_can_delete_receipt() ? 'true' : 'false' ?>,
    defaultCashId: <?= (int) $defaultCashId ?>,
    bankAccountId: <?= (int) $bankAccountId ?>
};
</script>
<?php
$gridJsV = is_file(app_path('assets/mobile/doc-list-grid.js'))
    ? (string) filemtime(app_path('assets/mobile/doc-list-grid.js'))
    : '';
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" crossorigin="anonymous"></script>
<script src="<?= esc(app_url('assets/mobile/doc-list-grid.js')) ?><?= $gridJsV !== '' ? '?v=' . esc($gridJsV) : '' ?>" defer></script>
<script src="<?= esc(app_url('assets/mobile/receipt.js')) ?><?= $jsV !== '' ? '?v=' . esc($jsV) : '' ?>" defer></script>
