<?php
declare(strict_types=1);

require_once app_path('includes/fin_payment_save.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'save_payment') {
    handle_fin_payment_save();
}

$pdo = db();
require_once app_path('includes/fin_voucher_schema.php');
require_once app_path('includes/crm_sales_rep_schema.php');

if (!fin_voucher_ensure_schema_full($pdo)) {
    echo '<div class="card"><p class="alert alert-error">جدول سندات الصرف غير موجود. نفّذ <code>database/migrations/027_fin_voucher.sql</code> و<code>029_fin_voucher_receipt_ext.sql</code>.</p></div>';
    return;
}

$cashAccounts = fin_voucher_load_cash_accounts($pdo);
if (!$cashAccounts) {
    echo '<div class="card"><p class="alert alert-error">لا توجد حسابات صندوق/بنك. نفّذ ترحيل <code>026_acc_journal_tables.sql</code> أولاً.</p></div>';
    return;
}

crm_sales_rep_ensure_customer_invoice_links($pdo);
require_once app_path('includes/supplier_picker.php');
$customers = $pdo->query(
    'SELECT c.id, c.code, c.name_ar, c.sales_rep_id, r.name_ar AS sales_rep_name
     FROM crm_customer c
     LEFT JOIN crm_sales_rep r ON r.id = c.sales_rep_id AND r.is_active = 1
     WHERE c.is_active = 1
     ORDER BY c.name_ar'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$suppliers = crm_suppliers_for_picker($pdo);

$flash = flash_get();
$today = date('Y-m-d');
require_once app_path('includes/document_header.php');
$docBrand = document_header_brand($pdo);
$companyNameAr = $docBrand['company_name_ar'];
$companyLogoUrl = (string) ($docBrand['logo_url'] ?? '');
require_once app_path('includes/nav_helpers.php');
$exitUrl = nav_exit_url($activeRoute ?? 'cash_payment');
$newUrl = app_url('index.php?r=cash_payment');
$listUrl = app_url('index.php?r=cash_payments_list');
$apiVoucher = app_url('api/fin_payment_view.php');
$apiPost = app_url('api/fin_payment_post.php');
$apiUnpost = app_url('api/fin_payment_unpost.php');
$apiDelete = app_url('api/fin_payment_delete.php');
$initialId = (int) ($_GET['id'] ?? 0);
require_once app_path('includes/acc_gl.php');
$cashBoxAccountId = acc_gl_cash_box_account_id($pdo);
$defaultCashId = $cashBoxAccountId > 0 ? $cashBoxAccountId : (int) ($cashAccounts[0]['id'] ?? 0);
$cssInvPath = app_path('assets/css/sales-invoice.css');
$cssInv = app_url('assets/css/sales-invoice.css') . (is_file($cssInvPath) ? '?v=' . (string) filemtime($cssInvPath) : '');
$cssPyPath = app_path('assets/css/fin-payment.css');
$cssPy = app_url('assets/css/fin-payment.css') . (is_file($cssPyPath) ? '?v=' . (string) filemtime($cssPyPath) : '');
$ora12CssPath = app_path('assets/css/sales-invoice-oracle12.css');
$ora12CssUrl = app_url('assets/css/sales-invoice-oracle12.css') . (is_file($ora12CssPath) ? '?v=' . (string) filemtime($ora12CssPath) : '');
require_once app_path('includes/sales_oracle12_ui.php');
$jsPath = app_path('assets/js/fin-payment.js');
$jsUrl = app_url('assets/js/fin-payment.js') . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : '');
?>
<?php sales_inv_oracle12_enqueue_assets(); ?>
<link rel="stylesheet" href="<?= esc($cssPy) ?>">
<?php
require_once app_path('includes/customer_picker.php');
customer_picker_enqueue_assets();
customer_picker_json_script($customers, 'fin-py-customers-json');
supplier_picker_enqueue_assets();
?>
<script type="application/json" id="fin-py-suppliers-json"><?= crm_suppliers_picker_json($suppliers) ?></script>

<div class="dashboard-ora sales-ora12-screen sales-inv-wrap sales-inv-main fin-py-wrap fin-py-pay-is-cash fin-py-party-is-supplier sales-inv-bold" data-exit-guard="custom">
    <header class="dashboard-ora-screen-title no-print" role="banner">
        <h1 class="dashboard-ora-screen-title__text">سند صرف</h1>
        <span class="dashboard-ora-screen-title__meta sales-inv-status-badges">
            <span id="py_posted_badge" class="sales-inv-posted-badge badge badge-warn" hidden>غير مرحّل</span>
        </span>
        <?php nav_render_screen_close($activeRoute ?? 'cash_payment'); ?>
    </header>

    <div class="dashboard-ora-workspace">
    <?php if ($flash): ?>
        <div class="alert no-print alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> sales-inv-grid-flash"><?= esc($flash['message']) ?></div>
    <?php endif; ?>

    <div class="dashboard-ora-toolbar sales-inv-toolbar no-print">
        <a class="dashboard-ora-btn dashboard-ora-btn--primary sales-inv-btn-new" href="<?= esc($newUrl) ?>">+ سند جديد</a>
        <a class="dashboard-ora-btn" href="<?= esc($listUrl) ?>">ترحيل السندات</a>
    </div>

    <form id="fin-py-form" class="master-page-form" method="post" action="<?= esc(app_url('index.php?r=cash_payment')) ?>" novalidate
          data-api-voucher="<?= esc($apiVoucher) ?>"
          data-voucher-post-url="<?= esc($apiPost) ?>"
          data-voucher-unpost-url="<?= esc($apiUnpost) ?>"
          data-voucher-delete-url="<?= esc($apiDelete) ?>"
          data-default-date="<?= esc(format_date_dmY($today)) ?>"
          data-exit-url="<?= esc($exitUrl) ?>"
          data-new-url="<?= esc($newUrl) ?>"
          data-list-url="<?= esc($listUrl) ?>"
          data-initial-id="<?= (int) $initialId ?>"
          data-company-name="<?= esc($companyNameAr) ?>"
          data-company-logo="<?= esc($companyLogoUrl) ?>">
        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="_action" value="save_payment">
        <input type="hidden" name="voucher_id" id="py_record_id" value="">
        <input type="hidden" name="party_type" id="py_party_type" value="supplier">

        <section class="dashboard-ora-panel no-print">
            <h2 class="dashboard-ora-panel__title">بيانات السند</h2>
            <div class="dashboard-ora-panel__body">
        <header class="sales-inv-doc-header sales-inv-meta-panel">
            <div class="sales-inv-meta-row">
                <div class="sales-inv-meta-item sales-inv-meta-no">
                    <label for="py_no">رقم السند</label>
                    <div class="sales-inv-no-nav">
                        <button type="button" class="sales-inv-no-arrow" id="py_no_prev" title="السابق">‹</button>
                        <input class="input input-compact sales-inv-no-input" type="text" name="voucher_no" id="py_no"
                               value="" placeholder="يُولَّد تلقائياً عند الحفظ — للبحث اكتب رقم سند محفوظ واضغط Enter"
                               title="يُولَّد الرقم تلقائياً عند الحفظ — للبحث اكتب رقم سند محفوظ واضغط Enter">
                        <button type="button" class="sales-inv-no-arrow" id="py_no_next" title="التالي">›</button>
                    </div>
                </div>
                <div class="sales-inv-meta-item sales-inv-meta-date">
                    <label for="py_date">التاريخ *</label>
                    <input class="input input-compact js-date-dmy" type="text" name="voucher_date" id="py_date"
                           value="<?= esc(format_date_dmY($today)) ?>" placeholder="يوم-شهر-سنة" dir="ltr" required>
                </div>
                <div class="sales-inv-meta-item fin-py-meta-party-type no-print">
                    <label>نوع الطرف *</label>
                    <div class="fin-py-party-row">
                        <label class="fin-py-party-opt">
                            <input type="radio" name="party_type_ui" value="supplier" id="py_party_supplier" checked> مورد
                        </label>
                        <label class="fin-py-party-opt">
                            <input type="radio" name="party_type_ui" value="customer" id="py_party_customer"> عميل
                        </label>
                    </div>
                </div>
                <div id="py-party-customer-wrap" class="fin-py-party-customer" hidden>
                <?= customer_picker_field([
                    'id' => 'py_customer',
                    'name' => 'customer_id',
                    'label' => 'العميل *',
                    'compact' => true,
                    'wrapper_class' => 'sales-inv-meta-item sales-inv-meta-customer',
                    'json_id' => 'fin-py-customers-json',
                    'manual_bind' => true,
                ]) ?>
                </div>
                <div id="py-party-supplier-wrap" class="fin-py-party-supplier">
                <?= supplier_picker_field([
                    'id' => 'py_supplier',
                    'name' => 'supplier_id',
                    'label' => 'المورد *',
                    'wrapper_class' => 'sales-inv-meta-item sales-inv-meta-supplier',
                    'json_id' => 'fin-py-suppliers-json',
                    'manual_bind' => true,
                ]) ?>
                </div>
                <div class="sales-inv-meta-item fin-py-meta-rep fin-py-party-customer-only" hidden>
                    <label>المندوب</label>
                    <input class="input input-compact" type="text" id="py_sales_rep" readonly placeholder="—" tabindex="-1">
                </div>
            </div>
            <p class="fin-py-party-hint no-print muted">
                <strong>مورد:</strong> دفع مستحقات مورد —
                <strong>عميل:</strong> رد مبلغ للعميل —
                <strong>يُخصم من:</strong> اختر الصندوق، البنك، أو حساب الشريك (جاري/حصة شريك) حسب شجرتك.
            </p>
        </header>
            </div>
        </section>

        <div class="sales-inv-print-area" id="fin-py-print-area">
            <div class="sales-inv-print-only">
                <?= document_print_header_html('سند صرف', $pdo) ?>
            </div>
            <div class="sales-inv-card fin-py-body">
                <div class="fin-py-pay-row no-print">
                    <label class="fin-py-pay-opt">
                        <input type="radio" name="pay_method" value="cash" id="py_pay_cash" checked> نقداً
                    </label>
                    <label class="fin-py-pay-opt">
                        <input type="radio" name="pay_method" value="check" id="py_pay_check"> شيك
                    </label>
                </div>
                <div class="sales-inv-meta-row fin-py-cash-account-row no-print">
                    <div class="sales-inv-meta-item fin-py-meta-cash-account">
                        <label for="py_cash_account_id">يُخصم من حساب *</label>
                        <select class="input input-compact" name="cash_account_id" id="py_cash_account_id" required>
                            <?php
                            $cashGroup = '';
                            foreach ($cashAccounts as $acc):
                                $gk = (string) ($acc['group_key'] ?? 'liquid');
                                $gl = (string) ($acc['group_label'] ?? '');
                                if ($gk !== $cashGroup):
                                    if ($cashGroup !== '') {
                                        echo '</optgroup>';
                                    }
                                    $cashGroup = $gk;
                                    if ($gl !== '') {
                                        echo '<optgroup label="' . esc($gl) . '">';
                                    }
                                endif;
                                ?>
                                <option value="<?= (int) $acc['id'] ?>"
                                        data-code="<?= esc((string) $acc['code']) ?>"
                                        data-group="<?= esc($gk) ?>"
                                    <?= (int) $acc['id'] === (int) $defaultCashId ? ' selected' : '' ?>>
                                    <?= esc($acc['code'] . ' — ' . $acc['name_ar']) ?>
                                </option>
                            <?php endforeach;
                            if ($cashGroup !== '') {
                                echo '</optgroup>';
                            }
                            ?>
                        </select>
                    </div>
                </div>
                <div class="sales-inv-meta-row fin-py-amount-row">
                    <div class="sales-inv-meta-item fin-py-field-cash" id="py_cash_amount_wrap">
                        <label for="py_amount">المبلغ *</label>
                        <input class="input input-compact" type="text" name="amount" id="py_amount" dir="ltr" placeholder="0.00">
                    </div>
                    <div class="sales-inv-meta-item fin-py-field-check" id="py_check_fields" hidden>
                        <label for="py_check_amount">قيمة الشيك *</label>
                        <input class="input input-compact" type="text" name="check_amount" id="py_check_amount" dir="ltr" placeholder="0.00">
                    </div>
                    <div class="sales-inv-meta-item fin-py-field-check" id="py_check_no_wrap" hidden>
                        <label for="py_check_no">رقم الشيك</label>
                        <input class="input input-compact" type="text" name="check_no" id="py_check_no" dir="ltr">
                    </div>
                    <div class="sales-inv-meta-item fin-py-field-check" id="py_bank_wrap" hidden>
                        <label for="py_bank_name">البنك</label>
                        <input class="input input-compact" type="text" name="bank_name" id="py_bank_name">
                    </div>
                </div>
                <div class="fin-py-notes no-print">
                    <label for="py_notes">ملاحظات</label>
                    <textarea class="input" name="notes" id="py_notes" rows="2" placeholder="اختياري"></textarea>
                </div>
                <div class="sales-inv-print-only">
                    <?= document_print_recipient_signature_html() ?>
                </div>
            </div>
        </div>
    </form>
    </div>
</div>

<script src="<?= esc($jsUrl) ?>"></script>
