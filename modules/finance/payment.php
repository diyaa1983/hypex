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

require_once app_path('includes/fin_payment_parties.php');
require_once app_path('includes/fin_voucher.php');

$initialId = (int) ($_GET['id'] ?? 0);
$disburseAdvanceId = (int) ($_GET['disburse_advance'] ?? 0);
$disburseCashAccountId = (int) ($_GET['cash_account_id'] ?? 0);
$disburseBootstrap = null;
if ($disburseAdvanceId > 0 && $initialId < 1) {
    $disburseBootstrap = fin_payment_disburse_advance_bootstrap($pdo, $disburseAdvanceId, $disburseCashAccountId);
    if ($disburseBootstrap === null) {
        flash_set('error', 'السلفة غير متاحة للصرف (غير مرحّلة أو تم صرفها مسبقاً).');
    }
}

$savedCashAccountId = 0;
if ($initialId > 0) {
    $existingVoucher = fin_voucher_load($pdo, $initialId, 'payment');
    $savedCashAccountId = (int) ($existingVoucher['cash_account_id'] ?? 0);
}

$cashAccounts = fin_voucher_deduct_accounts_ensure_saved(
    $pdo,
    fin_voucher_load_cash_bank_accounts($pdo),
    $savedCashAccountId
);
if (!$cashAccounts) {
    echo '<div class="card"><p class="alert alert-error">لا توجد حسابات صرف (صناديق، شيكات، أو بنوك). أضف حسابات تحت «الصناديق» أو «صندوق الشيكات» في شجرة الحسابات.</p></div>';
    return;
}

crm_sales_rep_ensure_customer_invoice_links($pdo);
require_once app_path('includes/supplier_picker.php');
require_once app_path('includes/employee_picker.php');
require_once app_path('includes/account_picker.php');
$pickerEmployees = hr_employee_picker_list($pdo);
$employeeOtherAccounts = fin_payment_employee_other_offset_accounts($pdo);
$otherOffsetAccounts = fin_payment_other_offset_accounts($pdo);
$advancePayableRows = fin_payment_employee_advance_payable_account($pdo);
$advancePayableLabel = $advancePayableRows !== []
    ? trim((string) ($advancePayableRows[0]['code'] ?? '')) . ' — ' . trim((string) ($advancePayableRows[0]['label'] ?? ''))
    : '2009 — سلف موظفين مستحقة الصرف';
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
$apiEmployeeAdvances = app_url('api/fin_payment_employee_advances.php');
$apiEmployeeSalaries = app_url('api/fin_payment_employee_salaries.php');
$salariesPayableAccountId = fin_payment_salaries_payable_account_id($pdo);
$apiPost = app_url('api/fin_payment_post.php');
$apiUnpost = app_url('api/fin_payment_unpost.php');
$apiCancel = app_url('api/fin_payment_cancel.php');
$apiDelete = app_url('api/fin_payment_delete.php');
$apiEditUnlock = app_url('api/fin_payment_edit_unlock.php');
$canEditPayment = user_can_action('action_edit_cash_payment');
require_once app_path('includes/acc_gl.php');
$cashBoxAccountId = acc_gl_cash_box_account_id($pdo);
$defaultCashId = $cashBoxAccountId > 0 ? $cashBoxAccountId : (int) ($cashAccounts[0]['id'] ?? 0);
if ($disburseBootstrap !== null) {
    $pickedCashId = (int) ($disburseBootstrap['cash_account_id'] ?? 0);
    if ($pickedCashId > 0 && fin_payment_cash_bank_account_valid($cashAccounts, $pickedCashId)) {
        $defaultCashId = $pickedCashId;
    }
}
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
employee_picker_enqueue_assets();
employee_picker_json_script($pickerEmployees, 'fin-py-employees-json');
account_picker_enqueue_assets();
account_picker_json_script($otherOffsetAccounts, 'fin-py-offset-accounts-json');
?>
<script type="application/json" id="fin-py-suppliers-json"><?= crm_suppliers_picker_json($suppliers) ?></script>
<?php if ($disburseBootstrap !== null): ?>
<script type="application/json" id="fin-py-disburse-bootstrap-json"><?= json_encode($disburseBootstrap, JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>

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
          data-api-employee-advances="<?= esc($apiEmployeeAdvances) ?>"
          data-api-employee-salaries="<?= esc($apiEmployeeSalaries) ?>"
          data-salaries-payable-account-id="<?= (int) $salariesPayableAccountId ?>"
          data-voucher-post-url="<?= esc($apiPost) ?>"
          data-voucher-unpost-url="<?= esc($apiUnpost) ?>"
          data-voucher-cancel-url="<?= esc($apiCancel) ?>"
          data-voucher-delete-url="<?= esc($apiDelete) ?>"
          data-api-edit-unlock="<?= esc($apiEditUnlock) ?>"
          data-can-edit="<?= $canEditPayment ? '1' : '0' ?>"
          data-default-date="<?= esc(format_date_dmY($today)) ?>"
          data-exit-url="<?= esc($exitUrl) ?>"
          data-new-url="<?= esc($newUrl) ?>"
          data-list-url="<?= esc($listUrl) ?>"
          data-initial-id="<?= (int) $initialId ?>"
          data-check-action-url="<?= esc(app_url('api/fin_check_action.php')) ?>"
          data-company-name="<?= esc($companyNameAr) ?>"
          data-company-logo="<?= esc($companyLogoUrl) ?>">
        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="_action" value="save_payment">
        <input type="hidden" name="voucher_id" id="py_record_id" value="">
        <input type="hidden" name="party_type" id="py_party_type" value="supplier">
        <input type="hidden" name="offset_account_id" id="py_offset_account_id" value="">
        <input type="hidden" name="employee_pay_kind" id="py_employee_pay_kind" value="advance">
        <input type="hidden" name="hr_advance_id" id="py_hr_advance_id" value="">
        <input type="hidden" name="hr_salary_id" id="py_hr_salary_id" value="">

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
                        <label class="fin-py-party-opt">
                            <input type="radio" name="party_type_ui" value="employee" id="py_party_employee"> موظف
                        </label>
                        <label class="fin-py-party-opt">
                            <input type="radio" name="party_type_ui" value="account" id="py_party_account"> حساب آخر
                        </label>
                    </div>
                </div>
            </div>
            <div class="fin-py-party-fields sales-inv-meta-row">
                <div id="py-party-customer-wrap" class="fin-py-party-customer fin-py-party-panel" hidden>
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
                <div id="py-party-supplier-wrap" class="fin-py-party-supplier fin-py-party-panel">
                <?= supplier_picker_field([
                    'id' => 'py_supplier',
                    'name' => 'supplier_id',
                    'label' => 'المورد *',
                    'wrapper_class' => 'sales-inv-meta-item sales-inv-meta-supplier',
                    'json_id' => 'fin-py-suppliers-json',
                    'manual_bind' => true,
                ]) ?>
                </div>
                <div id="py-party-employee-wrap" class="fin-py-party-employee fin-py-party-panel" hidden>
                <?= employee_picker_field([
                    'id' => 'py_employee',
                    'name' => 'employee_id',
                    'label' => 'الموظف *',
                    'compact' => true,
                    'wrapper_class' => 'sales-inv-meta-item sales-inv-meta-employee',
                    'json_id' => 'fin-py-employees-json',
                    'manual_bind' => true,
                ]) ?>
                <div class="sales-inv-meta-item fin-py-meta-employee-kind no-print">
                    <label>نوع الصرف للموظف *</label>
                    <div class="fin-py-party-row">
                        <label class="fin-py-party-opt">
                            <input type="radio" name="employee_pay_kind_ui" value="advance" id="py_emp_pay_advance" checked> صرف سلفة معتمدة
                        </label>
                        <label class="fin-py-party-opt">
                            <input type="radio" name="employee_pay_kind_ui" value="other" id="py_emp_pay_other"> راتب / التزام آخر
                        </label>
                    </div>
                </div>
                <div id="py-employee-advance-panel" class="fin-py-employee-advance-panel">
                    <div class="sales-inv-meta-item fin-py-meta-advance-account">
                        <label>حساب السلفة المستحقة للصرف</label>
                        <input class="input input-compact" type="text" id="py_advance_payable_display" readonly
                               value="<?= esc($advancePayableLabel) ?>" tabindex="-1">
                    </div>
                    <div class="fin-py-advances-box">
                        <div class="fin-py-advances-head">السلف المعتمدة من شؤون الموظفين — للصرف</div>
                        <div id="py_advances_list" class="fin-py-advances-list">
                            <p class="fin-py-advances-empty muted">اختر الموظف لعرض السلف المعتمدة غير المُصرفة.</p>
                        </div>
                    </div>
                </div>
                <div id="py-employee-other-panel" class="fin-py-employee-other-panel" hidden>
                <div class="sales-inv-meta-item fin-py-meta-employee-offset">
                    <label for="py_employee_offset">يُصرف إلى حساب *</label>
                    <select class="input input-compact" id="py_employee_offset">
                        <option value="">— اختر حساب الالتزام —</option>
                        <?php foreach ($employeeOtherAccounts as $acc): ?>
                            <option value="<?= (int) $acc['id'] ?>">
                                <?= esc((string) ($acc['code'] ?? '') . ' — ' . (string) ($acc['label'] ?? $acc['name_ar'] ?? '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="py-employee-salary-panel" class="fin-py-employee-salary-panel" hidden>
                    <div class="fin-py-advances-box">
                        <div class="fin-py-advances-head">الرواتب المرحّلة من شؤون الموظفين — للصرف</div>
                        <div id="py_salaries_list" class="fin-py-advances-list">
                            <p class="fin-py-advances-empty muted">اختر الموظف وحساب «رواتب مستحقة» لعرض الرواتب.</p>
                        </div>
                    </div>
                </div>
                </div>
                </div>
                <div id="py-party-account-wrap" class="fin-py-party-account fin-py-party-panel" hidden>
                <div class="sales-inv-meta-item sales-inv-meta-offset-account">
                    <label for="py_account_target_open">الحساب المُصروف إليه *</label>
                    <?= account_picker_field([
                        'id' => 'py_account_target',
                        'placeholder' => 'اضغط لاختيار حساب (خصوم / مصروف)',
                        'json_id' => 'fin-py-offset-accounts-json',
                        'manual_bind' => true,
                    ]) ?>
                </div>
                </div>
                <div class="sales-inv-meta-item fin-py-meta-rep fin-py-party-customer-only" hidden>
                    <label>المندوب</label>
                    <input class="input input-compact" type="text" id="py_sales_rep" readonly placeholder="—" tabindex="-1">
                </div>
            </div>
            <p class="fin-py-party-hint no-print muted">
                <strong>مورد:</strong> دفع مستحقات مورد —
                <strong>عميل:</strong> رد مبلغ للعميل —
                <strong>موظف — سلفة:</strong> اختر الموظف ثم السلفة المعتمدة من الشؤون (يُعبَّأ المبلغ تلقائياً) —
                <strong>موظف — آخر:</strong> صرف راتب أو التزام (رواتب مستحقة، ضمان…) —
                <strong>حساب آخر:</strong> صرف على حساب خصوم أو مصروف من الشجرة —
                <strong>يُخصم من:</strong> أي حساب تحت الصناديق أو صندوق الشيكات أو البنوك في شجرة الحسابات.
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
                        <input class="input input-compact" type="text" name="amount" id="py_amount" dir="ltr" placeholder="0.00" title="يُعبَّأ تلقائياً من شؤون الموظفين عند اختيار سلفة أو راتب">
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
                <div class="fin-py-check-manage no-print" id="py_check_manage_wrap" hidden></div>
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
