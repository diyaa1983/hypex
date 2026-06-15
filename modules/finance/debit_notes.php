<?php
declare(strict_types=1);

require_once app_path('includes/fin_debit_note_save.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'save_debit_note') {
    handle_fin_debit_note_save();
}

$pdo = db();
require_once app_path('includes/fin_debit_note.php');

if (!fin_debit_note_ensure_schema($pdo)) {
    echo '<div class="card"><p class="alert alert-error">جداول إشعارات المدينة غير موجودة. تأكد من تنفيذ <code>database/schema.sql</code> أو الترحيلات ذات الصلة.</p></div>';
    return;
}

require_once app_path('includes/customer_picker.php');
require_once app_path('includes/supplier_picker.php');
require_once app_path('includes/item_picker.php');

$customers = $pdo->query(
    'SELECT id, code, name_ar FROM crm_customer WHERE is_active = 1 ORDER BY name_ar'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$suppliers = crm_suppliers_for_picker($pdo);

$flash = flash_get();
$today = date('Y-m-d');
require_once app_path('includes/document_header.php');
$docBrand = document_header_brand($pdo);
$companyNameAr = $docBrand['company_name_ar'];
$companyLogoUrl = (string) ($docBrand['logo_url'] ?? '');
require_once app_path('includes/nav_helpers.php');
$exitUrl = nav_exit_url($activeRoute ?? 'debit_notes');
$newUrl = app_url('index.php?r=debit_notes');
$apiNote = app_url('api/fin_debit_note_view.php');
$apiDelete = app_url('api/fin_debit_note_delete.php');
$apiItems = app_url('api/items_search.php');
$initialId = (int) ($_GET['id'] ?? 0);
$dp = company_decimal_places($pdo);
$priceStep = $dp > 0 ? '0.' . str_repeat('0', $dp - 1) . '1' : '1';

$cssInvPath = app_path('assets/css/sales-invoice.css');
$cssInv = app_url('assets/css/sales-invoice.css') . (is_file($cssInvPath) ? '?v=' . (string) filemtime($cssInvPath) : '');
$cssDnPath = app_path('assets/css/fin-debit-note.css');
$cssDn = app_url('assets/css/fin-debit-note.css') . (is_file($cssDnPath) ? '?v=' . (string) filemtime($cssDnPath) : '');
$ora12CssPath = app_path('assets/css/sales-invoice-oracle12.css');
$ora12CssUrl = app_url('assets/css/sales-invoice-oracle12.css') . (is_file($ora12CssPath) ? '?v=' . (string) filemtime($ora12CssPath) : '');
require_once app_path('includes/sales_oracle12_ui.php');
$jsPath = app_path('assets/js/fin-debit-note.js');
$jsUrl = app_url('assets/js/fin-debit-note.js') . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : '');
?>
<?php sales_inv_oracle12_enqueue_assets(); ?>
<link rel="stylesheet" href="<?= esc($cssDn) ?>">
<?php
supplier_picker_enqueue_assets();
item_picker_enqueue_assets();
customer_picker_json_script($customers, 'fin-dn-customers-json');
echo '<script type="application/json" id="fin-dn-suppliers-json">' . crm_suppliers_picker_json($suppliers) . '</script>' . "\n";
?>

<div class="dashboard-ora sales-ora12-screen sales-inv-wrap sales-inv-main fin-dn-wrap sales-inv-bold">
    <header class="dashboard-ora-screen-title no-print" role="banner">
        <h1 class="dashboard-ora-screen-title__text">إشعار مدين</h1>
        <?php nav_render_screen_close($activeRoute ?? 'debit_notes'); ?>
    </header>

    <div class="dashboard-ora-workspace">
    <?php if ($flash): ?>
        <div class="alert no-print alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> sales-inv-grid-flash"><?= esc($flash['message']) ?></div>
    <?php endif; ?>

    <div class="dashboard-ora-toolbar sales-inv-toolbar no-print">
        <a class="dashboard-ora-btn dashboard-ora-btn--primary sales-inv-btn-new" href="<?= esc($newUrl) ?>">+ إشعار جديد</a>
    </div>

    <form id="fin-dn-form" class="master-page-form" method="post" action="<?= esc(app_url('index.php?r=debit_notes')) ?>" novalidate
          data-api-note="<?= esc($apiNote) ?>"
          data-api-delete="<?= esc($apiDelete) ?>"
          data-api-items="<?= esc($apiItems) ?>"
          data-default-date="<?= esc(format_date_dmY($today)) ?>"
          data-exit-url="<?= esc($exitUrl) ?>"
          data-new-url="<?= esc($newUrl) ?>"
          data-initial-id="<?= (int) $initialId ?>"
          data-decimal-places="<?= (int) $dp ?>"
          data-company-name="<?= esc($companyNameAr) ?>"
          data-company-logo="<?= esc($companyLogoUrl) ?>">
        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="_action" value="save_debit_note">
        <input type="hidden" name="note_id" id="dn_record_id" value="">
        <input type="hidden" name="party_type" id="dn_party_type" value="customer">
        <input type="hidden" name="party_id" id="dn_party_id" value="">
        <input type="hidden" name="lines_json" id="dn_lines_json" value="[]">

        <section class="dashboard-ora-panel no-print">
            <h2 class="dashboard-ora-panel__title">بيانات الإشعار</h2>
            <div class="dashboard-ora-panel__body">
        <header class="sales-inv-doc-header sales-inv-meta-panel">
            <div class="sales-inv-meta-row">
                <div class="sales-inv-meta-item sales-inv-meta-no">
                    <label for="dn_no">رقم الإشعار</label>
                    <div class="sales-inv-no-nav">
                        <button type="button" class="sales-inv-no-arrow" id="dn_no_prev" title="السابق">‹</button>
                        <input class="input input-compact sales-inv-no-input" type="text" name="note_no_display" id="dn_no" readonly tabindex="-1" placeholder="يُولَّد تلقائياً">
                        <button type="button" class="sales-inv-no-arrow" id="dn_no_next" title="التالي">›</button>
                    </div>
                </div>
                <div class="sales-inv-meta-item sales-inv-meta-date">
                    <label for="dn_date">التاريخ *</label>
                    <input class="input input-compact js-date-dmy" type="text" name="note_date" id="dn_date"
                           value="<?= esc(format_date_dmY($today)) ?>" placeholder="يوم-شهر-سنة" dir="ltr" required>
                </div>
                <div class="sales-inv-meta-item fin-dn-party-type-wrap no-print">
                    <span class="field-label">نوع الطرف *</span>
                    <div class="fin-dn-party-type">
                        <label><input type="radio" name="party_type_ui" value="customer" checked> عميل</label>
                        <label><input type="radio" name="party_type_ui" value="supplier"> مورد</label>
                    </div>
                </div>
                <div class="fin-dn-party-customer" id="dn_party_customer_wrap">
                    <?= customer_picker_field([
                        'id' => 'dn_customer',
                        'label' => 'العميل *',
                        'compact' => true,
                        'wrapper_class' => 'sales-inv-meta-item sales-inv-meta-customer',
                        'json_id' => 'fin-dn-customers-json',
                        'manual_bind' => true,
                    ]) ?>
                </div>
                <div class="fin-dn-party-supplier" id="dn_party_supplier_wrap" hidden>
                    <?= supplier_picker_field([
                        'id' => 'dn_supplier',
                        'label' => 'المورد *',
                        'compact' => true,
                        'wrapper_class' => 'sales-inv-meta-item sales-inv-meta-customer',
                        'json_id' => 'fin-dn-suppliers-json',
                        'manual_bind' => true,
                    ]) ?>
                </div>
            </div>
            <div class="sales-inv-meta-row fin-dn-reason-row">
                <label class="field" style="flex:1;margin:0;">
                    <span class="field-label">السبب / ملاحظات</span>
                    <input class="input input-compact" type="text" name="reason" id="dn_reason" maxlength="500" placeholder="اختياري">
                </label>
            </div>
        </header>
            </div>
        </section>

        <div class="sales-inv-card">
            <div class="sales-inv-lines-toolbar no-print">
                <button type="button" class="btn btn-secondary btn-sm" id="dn_add_line">+ سطر</button>
            </div>
            <div class="table-wrap sales-inv-table-wrap">
                <table class="data-table sales-inv-lines" id="dn_lines_table">
                    <thead>
                    <tr>
                        <th class="sales-inv-col-seq">#</th>
                        <th class="sales-inv-col-item">المادة / الوصف</th>
                        <th class="sales-inv-col-qty">الكمية</th>
                        <th class="sales-inv-col-price">السعر</th>
                        <th class="sales-inv-col-total">المجموع</th>
                        <th class="sales-inv-col-del"></th>
                    </tr>
                    </thead>
                    <tbody id="dn_lines_body"></tbody>
                    <tfoot>
                    <tr>
                        <td colspan="4" class="sales-inv-tfoot-label">الإجمالي</td>
                        <td class="sales-inv-col-total" id="dn_grand_total">0.00</td>
                        <td></td>
                    </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </form>
    </div>
</div>

<template id="fin-dn-line-template">
    <tr class="fin-dn-line" data-line-id="">
        <td class="sales-inv-col-seq"><span class="js-seq"></span></td>
        <td class="sales-inv-item-cell sales-inv-col-item">
            <button type="button" class="sales-inv-item-pick js-pick-open">
                <span class="js-name sales-inv-item-name is-placeholder">اختر مادة أو أدخل وصفاً</span>
            </button>
            <input type="text" class="input js-desc" placeholder="وصف إضافي" style="margin-top:0.35rem;width:100%;">
        </td>
        <td class="sales-inv-col-qty"><input type="number" class="input input-num js-qty" min="0.000001" step="1" value="1"></td>
        <td class="sales-inv-col-price"><input type="number" class="input input-num js-price" min="0" step="<?= esc($priceStep) ?>" value="0"></td>
        <td class="sales-inv-col-total js-line-total">0.00</td>
        <td class="sales-inv-col-del"><button type="button" class="btn-icon danger js-remove" title="حذف">✕</button></td>
    </tr>
</template>

<script src="<?= esc($jsUrl) ?>" defer></script>
