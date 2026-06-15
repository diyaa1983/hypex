<?php
declare(strict_types=1);

require_once app_path('includes/fin_receipt_save.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'save_receipt') {
    handle_fin_receipt_save();
}

$pdo = db();
require_once app_path('includes/fin_voucher_schema.php');
require_once app_path('includes/crm_sales_rep_schema.php');

if (!fin_voucher_ensure_schema_full($pdo)) {
    echo '<div class="card"><p class="alert alert-error">جدول سندات القبض غير موجود. نفّذ <code>database/migrations/027_fin_voucher.sql</code> و<code>029_fin_voucher_receipt_ext.sql</code>.</p></div>';
    return;
}

$cashAccounts = fin_voucher_load_cash_accounts($pdo);
if (!$cashAccounts) {
    echo '<div class="card"><p class="alert alert-error">لا توجد حسابات صندوق/بنك. نفّذ ترحيل <code>026_acc_journal_tables.sql</code> أولاً.</p></div>';
    return;
}

crm_sales_rep_ensure_customer_invoice_links($pdo);
$customers = $pdo->query(
    'SELECT c.id, c.code, c.name_ar, c.sales_rep_id, r.name_ar AS sales_rep_name
     FROM crm_customer c
     LEFT JOIN crm_sales_rep r ON r.id = c.sales_rep_id AND r.is_active = 1
     WHERE c.is_active = 1
     ORDER BY c.name_ar'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$flash = flash_get();
$today = date('Y-m-d');
require_once app_path('includes/document_header.php');
$docBrand = document_header_brand($pdo);
$companyNameAr = $docBrand['company_name_ar'];
$companyLogoUrl = (string) ($docBrand['logo_url'] ?? '');
require_once app_path('includes/nav_helpers.php');
$exitUrl = nav_exit_url($activeRoute ?? 'cash_receipt');
$newUrl = app_url('index.php?r=cash_receipt');
$apiVoucher = app_url('api/fin_receipt_view.php');
$apiPost = app_url('api/fin_receipt_post.php');
$apiUnpost = app_url('api/fin_receipt_unpost.php');
$apiDelete = app_url('api/fin_receipt_delete.php');
$initialId = (int) ($_GET['id'] ?? 0);
require_once app_path('includes/acc_gl.php');
$cashBoxAccountId = acc_gl_cash_box_account_id($pdo);
$defaultCashId = $cashBoxAccountId > 0 ? $cashBoxAccountId : (int) ($cashAccounts[0]['id'] ?? 0);
$bankAccountId = acc_gl_receipt_bank_deposit_account_id($pdo);
require_once app_path('includes/acc_coa_bootstrap.php');
$checksFundAccountId = (int) (acc_coa_ensure_checks_fund_account($pdo)['account_id'] ?? 0);
$cssInvPath = app_path('assets/css/sales-invoice.css');
$cssInv = app_url('assets/css/sales-invoice.css') . (is_file($cssInvPath) ? '?v=' . (string) filemtime($cssInvPath) : '');
$cssRcPath = app_path('assets/css/fin-receipt.css');
$cssRc = app_url('assets/css/fin-receipt.css') . (is_file($cssRcPath) ? '?v=' . (string) filemtime($cssRcPath) : '');
$ora12CssPath = app_path('assets/css/sales-invoice-oracle12.css');
$ora12CssUrl = app_url('assets/css/sales-invoice-oracle12.css') . (is_file($ora12CssPath) ? '?v=' . (string) filemtime($ora12CssPath) : '');
require_once app_path('includes/sales_oracle12_ui.php');
$jsPath = app_path('assets/js/fin-receipt.js');
$jsUrl = app_url('assets/js/fin-receipt.js') . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : '');
$docNoNavJsPath = app_path('assets/js/document-no-nav.js');
$docNoNavJsUrl = app_url('assets/js/document-no-nav.js') . (is_file($docNoNavJsPath) ? '?v=' . (string) filemtime($docNoNavJsPath) : '');
?>
<?php sales_inv_oracle12_enqueue_assets(); ?>
<link rel="stylesheet" href="<?= esc($cssRc) ?>">
<?php
require_once app_path('includes/customer_picker.php');
customer_picker_enqueue_assets();
customer_picker_json_script($customers, 'fin-rc-customers-json');
?>

<div class="dashboard-ora sales-ora12-screen sales-inv-wrap sales-inv-main fin-rc-wrap fin-rc-pay-is-cash sales-inv-bold" data-exit-guard="custom">
    <header class="dashboard-ora-screen-title no-print" role="banner">
        <h1 class="dashboard-ora-screen-title__text">سند قبض</h1>
        <span class="dashboard-ora-screen-title__meta sales-inv-status-badges">
            <span id="rc_posted_badge" class="sales-inv-posted-badge badge badge-warn" hidden>غير مرحّل</span>
        </span>
        <?php nav_render_screen_close($activeRoute ?? 'cash_receipt'); ?>
    </header>

    <div class="dashboard-ora-workspace">
    <?php if ($flash): ?>
        <div class="alert no-print alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> sales-inv-grid-flash"><?= esc($flash['message']) ?></div>
    <?php endif; ?>

    <div class="dashboard-ora-toolbar sales-inv-toolbar no-print">
        <a class="dashboard-ora-btn dashboard-ora-btn--primary sales-inv-btn-new" href="<?= esc($newUrl) ?>">+ سند جديد</a>
        <a class="dashboard-ora-btn" href="<?= esc(app_url('index.php?r=cash_receipts_list')) ?>">ترحيل السندات</a>
    </div>

    <form id="fin-rc-form" class="master-page-form" method="post" action="<?= esc(app_url('index.php?r=cash_receipt')) ?>" novalidate
          data-api-voucher="<?= esc($apiVoucher) ?>"
          data-voucher-post-url="<?= esc($apiPost) ?>"
          data-voucher-unpost-url="<?= esc($apiUnpost) ?>"
          data-voucher-delete-url="<?= esc($apiDelete) ?>"
          data-default-date="<?= esc(format_date_dmY($today)) ?>"
          data-exit-url="<?= esc($exitUrl) ?>"
          data-new-url="<?= esc($newUrl) ?>"
          data-initial-id="<?= (int) $initialId ?>"
          data-default-cash-id="<?= (int) $defaultCashId ?>"
          data-bank-account-id="<?= (int) $bankAccountId ?>"
          data-checks-fund-account-id="<?= (int) $checksFundAccountId ?>"
          data-company-name="<?= esc($companyNameAr) ?>"
          data-company-logo="<?= esc($companyLogoUrl) ?>">
        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="_action" value="save_receipt">
        <input type="hidden" name="voucher_id" id="rc_record_id" value="">
        <input type="hidden" name="party_type" value="customer">
        <input type="hidden" name="cash_account_id" id="rc_cash_account_id" value="<?= (int) $defaultCashId ?>">

        <section class="dashboard-ora-panel no-print">
            <h2 class="dashboard-ora-panel__title">بيانات السند</h2>
            <div class="dashboard-ora-panel__body">
        <header class="sales-inv-doc-header sales-inv-meta-panel">
            <div class="sales-inv-meta-row">
                <div class="sales-inv-meta-item sales-inv-meta-no">
                    <label for="rc_no">رقم السند</label>
                    <div class="sales-inv-no-nav">
                        <button type="button" class="sales-inv-no-arrow" id="rc_no_prev" title="السابق">‹</button>
                        <input class="input input-compact sales-inv-no-input" type="text" name="voucher_no" id="rc_no"
                               value="" placeholder="يُولَّد تلقائياً عند الحفظ — للبحث اكتب رقم سند محفوظ واضغط Enter"
                               title="يُولَّد الرقم تلقائياً عند الحفظ — للبحث اكتب رقم سند محفوظ واضغط Enter">
                        <button type="button" class="sales-inv-no-arrow" id="rc_no_next" title="التالي">›</button>
                    </div>
                </div>
                <div class="sales-inv-meta-item sales-inv-meta-date">
                    <label for="rc_date">التاريخ *</label>
                    <input class="input input-compact js-date-dmy" type="text" name="voucher_date" id="rc_date"
                           value="<?= esc(format_date_dmY($today)) ?>" placeholder="يوم-شهر-سنة" dir="ltr" required>
                </div>
                <?= customer_picker_field([
                    'id' => 'rc_customer',
                    'label' => 'العميل *',
                    'compact' => true,
                    'wrapper_class' => 'sales-inv-meta-item sales-inv-meta-customer',
                    'json_id' => 'fin-rc-customers-json',
                    'manual_bind' => true,
                ]) ?>
                <div class="sales-inv-meta-item fin-rc-meta-rep">
                    <label>المندوب</label>
                    <input class="input input-compact" type="text" id="rc_sales_rep" readonly placeholder="—" tabindex="-1">
                </div>
            </div>
        </header>
            </div>
        </section>

        <div class="sales-inv-print-area" id="fin-rc-print-area">
            <div class="sales-inv-print-only">
                <?= document_print_header_html('سند قبض', $pdo) ?>
            </div>
            <div class="sales-inv-card fin-rc-body">
                <div class="fin-rc-pay-row no-print">
                    <label class="fin-rc-pay-opt">
                        <input type="radio" name="pay_method" value="cash" id="rc_pay_cash" checked> نقداً
                    </label>
                    <label class="fin-rc-pay-opt">
                        <input type="radio" name="pay_method" value="bank" id="rc_pay_bank"> بنك
                    </label>
                    <label class="fin-rc-pay-opt">
                        <input type="radio" name="pay_method" value="check" id="rc_pay_check"> شيك
                    </label>
                </div>
                <div class="sales-inv-meta-row fin-rc-amount-row">
                    <div class="sales-inv-meta-item fin-rc-field-cash" id="rc_cash_amount_wrap">
                        <label for="rc_amount">المبلغ *</label>
                        <input class="input input-compact" type="text" name="amount" id="rc_amount" dir="ltr" placeholder="0.00">
                    </div>
                </div>

                <input type="hidden" name="check_amount" id="rc_check_amount" value="">
                <input type="hidden" name="check_no" id="rc_check_no" value="">
                <input type="hidden" name="bank_name" id="rc_bank_name" value="">

                <div class="fin-rc-checks-wrap fin-rc-field-check" id="rc_checks_wrap" hidden>
                    <div class="fin-rc-checks-header no-print">
                        <span class="fin-rc-checks-title">قائمة الشيكات</span>
                        <button type="button" class="btn btn-secondary btn-sm" id="rc_check_add">+ إضافة شيك</button>
                    </div>
                    <div class="fin-rc-checks-table-wrap">
                        <table class="table fin-rc-checks-table" id="rc_checks_table">
                            <thead>
                                <tr>
                                    <th class="fin-rc-col-no">#</th>
                                    <th class="fin-rc-col-num">رقم الشيك</th>
                                    <th class="fin-rc-col-amount">المبلغ *</th>
                                    <th class="fin-rc-col-bank">البنك</th>
                                    <th class="fin-rc-col-due">تاريخ الاستحقاق</th>
                                    <th class="fin-rc-col-act no-print">&nbsp;</th>
                                </tr>
                            </thead>
                            <tbody id="rc_checks_tbody"></tbody>
                            <tfoot>
                                <tr class="fin-rc-checks-total-row">
                                    <td colspan="2" class="fin-rc-checks-total-label">الإجمالي</td>
                                    <td class="fin-rc-checks-total-value" id="rc_checks_total">0.00</td>
                                    <td colspan="3"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <template id="rc_check_row_tpl">
                    <tr class="fin-rc-check-row">
                        <td class="fin-rc-col-no fin-rc-row-index">1</td>
                        <td class="fin-rc-col-num">
                            <input class="input input-compact fin-rc-check-no" type="text" name="checks[__IDX__][check_no]" dir="ltr">
                        </td>
                        <td class="fin-rc-col-amount">
                            <input class="input input-compact fin-rc-check-amount" type="text" name="checks[__IDX__][check_amount]" dir="ltr" placeholder="0.00">
                        </td>
                        <td class="fin-rc-col-bank">
                            <input class="input input-compact fin-rc-check-bank" type="text" name="checks[__IDX__][bank_name]">
                        </td>
                        <td class="fin-rc-col-due">
                            <input class="input input-compact js-date-dmy fin-rc-check-due" type="text" name="checks[__IDX__][due_date]" dir="ltr" placeholder="يوم-شهر-سنة">
                        </td>
                        <td class="fin-rc-col-act no-print">
                            <button type="button" class="btn btn-danger btn-sm fin-rc-check-remove" title="حذف">×</button>
                        </td>
                    </tr>
                </template>
                <div class="fin-rc-notes no-print">
                    <label for="rc_notes">وذلك عن</label>
                    <textarea class="input" name="notes" id="rc_notes" rows="2" placeholder="بيان السند / سبب الدفع"></textarea>
                </div>
                <div class="sales-inv-print-only">
                    <?= document_print_recipient_signature_html() ?>
                </div>
            </div>
        </div>
    </form>
    </div>
</div>

<script src="<?= esc($docNoNavJsUrl) ?>"></script>
<script src="<?= esc($jsUrl) ?>"></script>
