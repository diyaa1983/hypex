<?php
declare(strict_types=1);

require_once app_path('includes/journal_voucher_save.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = (string) ($_POST['_action'] ?? '');
    if ($postAction === 'save_journal_voucher' || $postAction === 'save_journal_voucher_post') {
        handle_journal_voucher_save();
    }
}

$pdo = db();
require_once app_path('includes/acc_journal.php');

if (!acc_journal_ensure_schema($pdo)) {
    echo '<div class="card"><p class="alert alert-error">جداول القيود غير موجودة. نفّذ <code>database/migrations/026_acc_journal_tables.sql</code>.</p></div>';
    return;
}

require_once app_path('includes/acc_coa_bootstrap.php');
$checksFund = acc_coa_ensure_checks_fund_account($pdo);
if (!empty($checksFund['created']) && !empty($checksFund['message'])) {
    flash_set('success', (string) $checksFund['message']);
}

$accounts = acc_journal_accounts_picker($pdo);
if (!$accounts) {
    echo '<div class="card"><p class="alert alert-error">لا توجد حسابات نهائية في شجرة الحسابات. أضف حسابات فرعية قابلة للترحيل أولاً.</p></div>';
    return;
}

$flash = flash_get();
$today = date('Y-m-d');
require_once app_path('includes/document_header.php');
$docBrand = document_header_brand($pdo);
require_once app_path('includes/nav_helpers.php');
$exitUrl = nav_exit_url($activeRoute ?? 'journal_voucher');
$newUrl = app_url('index.php?r=journal_voucher');
$apiView = app_url('api/journal_voucher_view.php');
$apiDelete = app_url('api/journal_voucher_delete.php');
$apiPost = app_url('api/journal_voucher_post.php');
$apiUnpost = app_url('api/journal_voucher_unpost.php');
$apiEditUnlock = app_url('api/journal_voucher_edit_unlock.php');
$apiCancel = app_url('api/journal_voucher_cancel.php');
$canUnpostJv = user_can_action('action_unpost_journal_voucher');
$canEditJv = user_can_action('action_edit_journal_voucher');
$initialId = (int) ($_GET['id'] ?? 0);

$cssInvPath = app_path('assets/css/sales-invoice.css');
$cssInv = app_url('assets/css/sales-invoice.css') . (is_file($cssInvPath) ? '?v=' . (string) filemtime($cssInvPath) : '');
$cssJePath = app_path('assets/css/journal-entry.css');
$cssJe = app_url('assets/css/journal-entry.css') . (is_file($cssJePath) ? '?v=' . (string) filemtime($cssJePath) : '');
$cssJvPath = app_path('assets/css/fin-journal-voucher.css');
$cssJv = app_url('assets/css/fin-journal-voucher.css') . (is_file($cssJvPath) ? '?v=' . (string) filemtime($cssJvPath) : '');
$ora12CssPath = app_path('assets/css/sales-invoice-oracle12.css');
$ora12CssUrl = app_url('assets/css/sales-invoice-oracle12.css') . (is_file($ora12CssPath) ? '?v=' . (string) filemtime($ora12CssPath) : '');
require_once app_path('includes/sales_oracle12_ui.php');
$jsPath = app_path('assets/js/fin-journal-voucher.js');
$jsUrl = app_url('assets/js/fin-journal-voucher.js') . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : '');
require_once app_path('includes/account_picker.php');
require_once app_path('includes/customer_picker.php');
require_once app_path('includes/supplier_picker.php');
require_once app_path('includes/acc_journal_party.php');
require_once app_path('includes/acc_gl.php');

acc_gl_ensure_schema($pdo);
$partyAccountIds = acc_journal_party_ar_ap_ids($pdo);
$customers = crm_customers_for_picker($pdo, false);
$suppliers = crm_suppliers_for_picker($pdo);
?>
<?php sales_inv_oracle12_enqueue_assets(); ?>
<link rel="stylesheet" href="<?= esc($cssJe) ?>">
<link rel="stylesheet" href="<?= esc($cssJv) ?>">

<div class="dashboard-ora sales-ora12-screen sales-inv-wrap sales-inv-main fin-jv-wrap sales-inv-bold"
     data-exit-guard-root data-exit-url="<?= esc($exitUrl) ?>">
    <header class="dashboard-ora-screen-title no-print" role="banner">
        <h1 class="dashboard-ora-screen-title__text">سند قيد</h1>
        <span class="dashboard-ora-screen-title__meta sales-inv-status-badges">
            <span id="jv_posted_badge" class="sales-inv-posted-badge badge badge-warn" hidden></span>
        </span>
        <?php nav_render_screen_close($activeRoute ?? 'journal_voucher'); ?>
    </header>

    <div class="dashboard-ora-workspace">
    <?php if ($flash): ?>
        <div class="alert no-print alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> sales-inv-grid-flash"><?= esc($flash['message']) ?></div>
    <?php endif; ?>

    <div class="dashboard-ora-toolbar sales-inv-toolbar no-print">
        <a class="dashboard-ora-btn dashboard-ora-btn--primary sales-inv-btn-new" href="<?= esc($newUrl) ?>">+ سند قيد جديد</a>
        <button type="button" class="dashboard-ora-btn" id="jv-print-btn">طباعة</button>
        <a class="dashboard-ora-btn" href="<?= esc(app_url('index.php?r=journal_entries')) ?>">قائمة القيود</a>
    </div>

    <form id="fin-jv-form" class="master-page-form journal-entry-form" method="post"
          action="<?= esc(app_url('index.php?r=journal_voucher')) ?>" novalidate
          data-api-view="<?= esc($apiView) ?>"
          data-api-delete="<?= esc($apiDelete) ?>"
          data-api-post="<?= esc($apiPost) ?>"
          data-api-unpost="<?= esc($apiUnpost) ?>"
          data-api-edit-unlock="<?= esc($apiEditUnlock) ?>"
          data-api-cancel="<?= esc($apiCancel) ?>"
          data-can-unpost="<?= $canUnpostJv ? '1' : '0' ?>"
          data-can-edit="<?= $canEditJv ? '1' : '0' ?>"
          data-default-date="<?= esc(format_date_dmY($today)) ?>"
          data-exit-url="<?= esc($exitUrl) ?>"
          data-new-url="<?= esc($newUrl) ?>"
          data-initial-id="<?= (int) $initialId ?>"
          data-company-name="<?= esc((string) ($docBrand['company_name_ar'] ?? '')) ?>"
          data-company-logo="<?= esc((string) ($docBrand['logo_url'] ?? '')) ?>"
          data-ar-account-id="<?= (int) ($partyAccountIds['ar'] ?? 0) ?>"
          data-ap-account-id="<?= (int) ($partyAccountIds['ap'] ?? 0) ?>">
        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="_action" value="save_journal_voucher">
        <input type="hidden" name="entry_id" id="jv_entry_id" value="">
        <input type="hidden" name="lines_json" id="jv_lines_json" value="[]">

        <section class="dashboard-ora-panel no-print">
            <h2 class="dashboard-ora-panel__title">بيانات السند</h2>
            <div class="dashboard-ora-panel__body">
        <header class="sales-inv-doc-header sales-inv-meta-panel">
            <div class="sales-inv-meta-row journal-entry-header" style="margin-bottom:0;">
                <div class="sales-inv-meta-item sales-inv-meta-no">
                    <label for="jv_no">رقم السند</label>
                    <div class="sales-inv-no-nav">
                        <button type="button" class="sales-inv-no-arrow" id="jv_no_prev" title="السابق">‹</button>
                        <input class="input input-compact sales-inv-no-input" type="text" name="entry_no" id="jv_no"
                               value="" placeholder="رقم السند — Enter للبحث" autocomplete="off"
                               title="أدخل رقم السند واضغط Enter أو زر بحث في شريط الأدوات">
                        <button type="button" class="sales-inv-no-arrow" id="jv_no_next" title="التالي">›</button>
                    </div>
                </div>
                <div class="sales-inv-meta-item sales-inv-meta-date">
                    <label for="jv_date">التاريخ *</label>
                    <input class="input input-compact js-date-dmy" type="text" name="entry_date" id="jv_date"
                           value="<?= esc(format_date_dmY($today)) ?>" placeholder="يوم-شهر-سنة" dir="ltr" required>
                </div>
                <label class="field field-grow" style="margin:0;flex:1 1 240px;">
                    <span class="field-label">بيان السند (عام)</span>
                    <input class="input input-compact" type="text" name="description_ar" id="jv_description"
                           maxlength="500" placeholder="وصف مختصر لسند القيد">
                </label>
            </div>
        </header>
            </div>
        </section>

        <div class="sales-inv-card journal-entry-card" style="padding:0.75rem;">
            <div class="journal-lines-wrap">
                <table class="data-table journal-lines-table" id="jv-lines-table">
                    <thead>
                    <tr>
                        <th class="col-acc">الحساب (من شجرة الحسابات) *</th>
                        <th class="col-party">عميل / مورد</th>
                        <th class="col-money">مدين</th>
                        <th class="col-money">دائن</th>
                        <th class="col-memo">البيان</th>
                        <th class="col-act no-print"></th>
                    </tr>
                    </thead>
                    <tbody id="jv-lines-body"></tbody>
                    <tfoot>
                    <tr class="journal-totals-row">
                        <td><strong>المجموع</strong></td>
                        <td class="col-money" id="jv-total-debit">0.00</td>
                        <td class="col-money" id="jv-total-credit">0.00</td>
                        <td colspan="2">
                            <span id="jv-balance-hint" class="journal-balance-ok">متوازن</span>
                        </td>
                    </tr>
                    </tfoot>
                </table>
            </div>
            <div class="journal-entry-actions no-print">
                <button type="button" class="btn btn-secondary btn-sm" id="jv-add-line">+ سطر حركة</button>
            </div>
        </div>
    </form>
    </div>
</div>

<?php
account_picker_enqueue_assets();
customer_picker_enqueue_assets();
supplier_picker_enqueue_assets();
account_picker_json_script($accounts, 'jv-accounts-json');
customer_picker_json_script($customers, 'jv-customers-json');
supplier_picker_json_script($suppliers, 'jv-suppliers-json');
?>
<script src="<?= esc($jsUrl) ?>" defer></script>
