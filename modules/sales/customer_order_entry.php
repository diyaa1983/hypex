<?php
declare(strict_types=1);

/**
 * إنشاء / تعديل مسودة طلب شراء عميل — واجهة سند (مثل شاشات التسليم/الفواتير).
 */
require_once app_path('includes/sal_customer_order.php');
require_once app_path('includes/inv_item_units.php');
require_once app_path('includes/inv_warehouse_items.php');
require_once app_path('includes/sales_oracle12_ui.php');
require_once app_path('includes/crm_sales_rep_schema.php');
require_once app_path('includes/item_picker.php');
require_once app_path('includes/customer_picker.php');
require_once app_path('includes/warehouse_access.php');
require_once app_path('includes/nav_helpers.php');
require_once app_path('includes/app_icons.php');

if (!sal_customer_order_user_can_edit_drafts()) {
    require_permission('sales_customer_orders');
}

$pdo = db();
sal_customer_order_ensure_schema($pdo);
inv_item_units_ensure_schema($pdo);
crm_sales_rep_ensure_schema($pdo);
item_picker_enqueue_assets();
customer_picker_enqueue_assets();

$id = (int) ($_GET['id'] ?? 0);
$order = $id > 0 ? sal_customer_order_fetch($pdo, $id) : null;
if ($id > 0 && !$order) {
    flash_set('error', 'الطلب غير موجود.');
    redirect(app_url('index.php?r=sales_customer_orders'));
}
if ($order && (string) ($order['status'] ?? '') === 'approved') {
    redirect(app_url('index.php?r=sales_customer_orders_approved&id=' . (int) $order['id']));
}

$warehouses = $pdo->query(
    'SELECT id, code, name_ar FROM inv_warehouse WHERE is_active = 1 ORDER BY name_ar'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$allWarehouses = $warehouses;
$warehouses = array_values(array_filter($warehouses, static function (array $w) use ($pdo): bool {
    return wh_access_can_issue($pdo, (int) $w['id']) || wh_access_can_view($pdo, (int) $w['id']);
}));
if ($warehouses === []) {
    $warehouses = $allWarehouses;
}

$defaultWarehouseId = inv_default_warehouse_id($pdo);
$salesReps = $pdo->query(
    'SELECT id, name_ar FROM crm_sales_rep WHERE is_active = 1 ORDER BY name_ar'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$customers = $pdo->query(
    'SELECT id, code, name_ar FROM crm_customer WHERE is_active = 1 ORDER BY name_ar'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$settings = company_settings($pdo);
$dp = company_decimal_places($pdo);
$unitPriceDp = company_invoice_unit_price_decimal_places($pdo);
$unitPriceStep = company_invoice_unit_price_decimal_step($pdo);
$amountStep = company_decimal_step($dp);
$defaultTax = (float) ($settings['tax_rate_percent'] ?? 15);
$taxRates = [];
try {
    $taxRates = $pdo->query(
        'SELECT id, name_ar, rate_percent FROM sys_tax_rate WHERE is_active = 1 ORDER BY sort_order, id'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $taxRates = [];
}
if (!$taxRates) {
    $taxRates = [['id' => 0, 'name_ar' => 'افتراضي (' . $defaultTax . '%)', 'rate_percent' => $defaultTax]];
}

$orderLinesJson = [];
if ($order) {
    foreach ($order['lines'] as $line) {
        $line['units'] = inv_item_units_for_item($pdo, (int) $line['item_id']);
        $orderLinesJson[] = $line;
    }
}

$today = date('Y-m-d');
$orderDate = $order ? (string) $order['order_date'] : $today;
$warehouseId = $order
    ? (int) ($order['warehouse_id'] ?? 0)
    : (int) ($defaultWarehouseId ?? 0);
$customerIdSel = $order ? (int) ($order['customer_id'] ?? 0) : 0;
$salesRepSel = $order ? (int) ($order['sales_rep_id'] ?? 0) : 0;
$orderNo = $order ? (string) ($order['order_no'] ?? '') : '';
$notes = $order ? (string) ($order['notes'] ?? '') : '';
$headerDisc = $order ? (string) ($order['invoice_discount_input'] ?? '') : '';

$canApprove = sal_customer_order_user_can_approve();
$canDelete = sal_customer_order_user_can_delete_managed();

$listUrl = user_can('sales_customer_orders')
    ? app_url('index.php?r=sales_customer_orders')
    : app_url('index.php?r=sales_customer_orders_approve');
$newUrl = user_can('sales_customer_orders')
    ? app_url('index.php?r=sales_customer_order_entry')
    : app_url('index.php?r=sales_customer_order_entry_approve');
$entryUrl = $newUrl;
$activeRoute = (string) ($GLOBALS['activeRoute'] ?? 'sales_customer_order_entry');
if (isset($_GET['r']) && (string) $_GET['r'] === 'sales_customer_order_entry_approve') {
    $entryUrl = app_url('index.php?r=sales_customer_order_entry_approve');
    $newUrl = $entryUrl;
    $activeRoute = 'sales_customer_order_entry_approve';
}
if (!in_array($activeRoute, ['sales_customer_order_entry', 'sales_customer_order_entry_approve'], true)) {
    $activeRoute = 'sales_customer_order_entry';
}

$exitUrl = nav_exit_url($activeRoute);
$screenTitle = 'طلب شراء عميل';
$isNew = !$order;
$flash = flash_get();

$cssDocPath = app_path('assets/css/customer-order-doc.css');
$cssDoc = app_url('assets/css/customer-order-doc.css') . (is_file($cssDocPath) ? '?v=' . (string) filemtime($cssDocPath) : '');
$jsDiscPath = app_path('assets/js/inv-invoice-discount.js');
$jsDisc = app_url('assets/js/inv-invoice-discount.js') . (is_readable($jsDiscPath) ? '?v=' . (string) filemtime($jsDiscPath) : '1');
$jsCoPath = app_path('assets/js/customer-order-lines.js');
$jsCo = app_url('assets/js/customer-order-lines.js') . (is_readable($jsCoPath) ? '?v=' . (string) filemtime($jsCoPath) : '1');

require_once app_path('includes/inv_invoice_line_table.php');
sales_inv_oracle12_enqueue_assets();
customer_picker_json_script($customers, 'co-entry-customers-json');
?>
<link rel="stylesheet" href="<?= esc($cssDoc) ?>">

<div class="dashboard-ora sales-ora12-screen sales-inv-wrap sales-inv-main co-doc" data-exit-guard="custom">
    <header class="dashboard-ora-screen-title no-print" role="banner">
        <div class="dashboard-ora-screen-title__group">
            <h1 class="dashboard-ora-screen-title__text"><?= esc($screenTitle) ?></h1>
            <div class="sales-inv-title-actions no-print">
                <a class="dashboard-ora-screen-title__action sales-inv-btn-new sales-inv-title-new"
                   href="<?= esc($newUrl) ?>">+ طلب جديد</a>
            </div>
        </div>
        <span class="dashboard-ora-screen-title__meta sales-inv-status-badges">
            <span class="sales-inv-posted-badge badge badge-warn">
                <?= $isNew ? 'مسودة جديدة' : 'مسودة' ?>
            </span>
            <?php if ($orderNo !== ''): ?>
                <span class="sales-inv-posted-badge"><?= esc($orderNo) ?></span>
            <?php endif; ?>
        </span>
        <?php nav_render_screen_close($activeRoute); ?>
    </header>

    <div class="dashboard-ora-workspace">
        <?php if ($flash): ?>
            <div class="alert no-print alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> sales-inv-grid-flash"><?= esc($flash['message']) ?></div>
        <?php endif; ?>

        <!-- شريط أوامر كشاشة السند -->
        <div class="co-doc-toolbar no-print" role="toolbar" aria-label="أوامر الطلب">
            <a class="co-tb-btn co-tb-btn--ok" href="<?= esc($newUrl) ?>" title="طلب جديد">
                <span class="co-tb-ico">＋</span><span>جديد</span>
            </a>
            <button type="button" class="co-tb-btn co-tb-btn--ok" id="co-save" title="حفظ">
                <span class="co-tb-ico">✓</span><span>حفظ</span>
            </button>
            <?php if ($canApprove): ?>
                <button type="button" class="co-tb-btn co-tb-btn--ok" id="co-approve" title="اعتماد">
                    <span class="co-tb-ico">✔</span><span>اعتماد</span>
                </button>
            <?php endif; ?>
            <?php if ($canDelete): ?>
                <button type="button" class="co-tb-btn co-tb-btn--danger" id="co-delete" title="حذف" <?= $isNew ? 'disabled' : '' ?>>
                    <span class="co-tb-ico">✕</span><span>حذف</span>
                </button>
            <?php else: ?>
                <button type="button" class="co-tb-btn co-tb-btn--danger" id="co-delete" hidden disabled></button>
            <?php endif; ?>
            <a class="co-tb-btn" href="<?= esc($listUrl) ?>" title="بحث / قائمة الطلبات">
                <span class="co-tb-ico">⌕</span><span>بحث</span>
            </a>
            <?php if (user_can('sales_customer_orders_approve')): ?>
                <a class="co-tb-btn" href="<?= esc(app_url('index.php?r=sales_customer_orders_approve')) ?>" title="اعتماد الطلبات">
                    <span class="co-tb-ico">▤</span><span>اعتمادات</span>
                </a>
            <?php endif; ?>
            <a class="co-tb-btn co-tb-btn--exit" href="<?= esc($exitUrl) ?>" title="خروج">
                <span class="co-tb-ico">↩</span><span>خروج</span>
            </a>
        </div>

        <form id="customer-order-form" class="master-page-form co-doc-form"
              data-decimals="<?= (int) $dp ?>"
              data-unit-price-decimals="<?= (int) $unitPriceDp ?>"
              data-default-tax-rate="<?= esc((string) $defaultTax) ?>"
              data-warehouse-id="<?= (int) $warehouseId ?>"
              data-api-items="<?= esc(app_url('api/items_search.php')) ?>"
              data-invoice-discount="<?= esc($headerDisc) ?>">
            <input type="hidden" id="order-id" value="<?= (int) ($order['id'] ?? 0) ?>">

            <section class="dashboard-ora-panel no-print">
                <h2 class="dashboard-ora-panel__title">بيانات السند</h2>
                <div class="dashboard-ora-panel__body">
                    <header class="sales-inv-doc-header sales-inv-meta-panel">
                        <div class="sales-inv-meta-row">
                            <div class="sales-inv-meta-item sales-inv-meta-no">
                                <label for="co_order_no">رقم السند</label>
                                <div class="sales-inv-no-nav">
                                    <a class="sales-inv-no-arrow" href="<?= esc($listUrl) ?>" title="قائمة الطلبات" aria-label="بحث">‹</a>
                                    <input class="input input-compact sales-inv-no-input" type="text" id="co_order_no"
                                           value="<?= esc($orderNo) ?>" placeholder="يُولَّد عند الحفظ" readonly>
                                    <a class="sales-inv-no-arrow" href="<?= esc($newUrl) ?>" title="طلب جديد" aria-label="جديد">›</a>
                                </div>
                            </div>
                            <div class="sales-inv-meta-item sales-inv-meta-date">
                                <label for="order-date">تاريخ السند</label>
                                <input class="input input-compact js-date-dmy" type="text" id="order-date"
                                       value="<?= esc(format_date_dmY($orderDate)) ?>"
                                       placeholder="يوم-شهر-سنة" dir="ltr"
                                       autocomplete="off" inputmode="numeric" required>
                            </div>
                            <?= customer_picker_field([
                                'id' => 'co_customer',
                                'label' => 'العميل',
                                'compact' => true,
                                'wrapper_class' => 'sales-inv-meta-item sales-inv-meta-customer',
                                'json_id' => 'co-entry-customers-json',
                                'manual_bind' => false,
                                'hotkey' => 'F7',
                                'value' => $customerIdSel > 0 ? (string) $customerIdSel : '',
                            ]) ?>
                            <div class="sales-inv-meta-item">
                                <label for="co_sales_rep">المندوب</label>
                                <select class="input input-compact" id="co_sales_rep" name="sales_rep_id">
                                    <option value="">— بدون مندوب —</option>
                                    <?php foreach ($salesReps as $rep): ?>
                                        <option value="<?= (int) $rep['id'] ?>" <?= $salesRepSel === (int) $rep['id'] ? 'selected' : '' ?>>
                                            <?= esc((string) $rep['name_ar']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="sales-inv-meta-item sales-inv-meta-wh">
                                <label for="warehouse-id">المستودع</label>
                                <select class="input input-compact" id="warehouse-id" required>
                                    <option value="">— المستودع —</option>
                                    <?php foreach ($warehouses as $w): ?>
                                        <option value="<?= (int) $w['id'] ?>" <?= $warehouseId === (int) $w['id'] ? 'selected' : '' ?>>
                                            <?= esc((string) $w['name_ar']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </header>
                </div>
            </section>

            <section class="dashboard-ora-panel sales-inv-card">
                <h2 class="dashboard-ora-panel__title no-print">تفاصيل المواد</h2>
                <div class="dashboard-ora-panel__body dashboard-ora-panel__body--flush">
                    <div class="sales-inv-table-wrap" id="sales-inv-table-wrap">
                        <table class="sales-inv-table">
                            <thead>
                            <?php inv_invoice_line_table_head(false); ?>
                            </thead>
                            <tbody id="co-lines-body"></tbody>
                        </table>
                    </div>

                    <div class="co-doc-addline no-print">
                        <button type="button" id="co-add-item" class="co-add-line-btn">＋ إضافة سطر</button>
                    </div>

                    <div class="sales-inv-footer-grid co-doc-footer">
                        <div class="sales-inv-notes sales-inv-field no-print">
                            <label for="inv_notes">ملاحظات</label>
                            <textarea class="input" name="notes" id="inv_notes" rows="3" placeholder="اختياري"><?= esc($notes) ?></textarea>
                            <p id="co-message" class="co-doc-msg muted"></p>
                        </div>
                        <div class="sales-inv-totals co-doc-totals">
                            <div class="row sales-inv-totals-disc">
                                <label for="inv-invoice-discount">خصم الطلب</label>
                                <input type="text" class="input input-compact input-num" name="invoice_discount" id="inv-invoice-discount"
                                       value="<?= esc($headerDisc) ?>" title="خصم على مستوى الطلب" autocomplete="off">
                            </div>
                            <div class="row sales-inv-totals-header-disc" id="sales-inv-header-disc-row" hidden>
                                <span>قيمة خصم الطلب</span>
                                <span id="sales-inv-sum-header-disc"><?= esc(format_amount(0)) ?></span>
                            </div>
                            <div class="row"><span>مجموع الخصم</span><span id="sales-inv-sum-disc"><?= esc(format_amount((float) ($order['discount_amount'] ?? 0))) ?></span></div>
                            <div class="row"><span>بدون ضريبة</span><span id="sales-inv-sum-sub"><?= esc(format_amount((float) ($order['subtotal'] ?? 0))) ?></span></div>
                            <div class="row"><span>الضريبة</span><span id="sales-inv-sum-tax"><?= esc(format_amount((float) ($order['tax_amount'] ?? 0))) ?></span></div>
                            <div class="row grand"><span>الإجمالي</span><span id="sales-inv-sum-grand"><?= esc(format_amount((float) ($order['total'] ?? 0))) ?></span></div>
                        </div>
                    </div>

                    <div class="co-doc-sumbar no-print" aria-live="polite">
                        <div class="co-doc-sumbar__item">
                            <span>مجموع الخصم</span>
                            <strong id="co-bar-disc"><?= esc(format_amount((float) ($order['discount_amount'] ?? 0))) ?></strong>
                        </div>
                        <div class="co-doc-sumbar__item">
                            <span>بدون ضريبة</span>
                            <strong id="co-bar-sub"><?= esc(format_amount((float) ($order['subtotal'] ?? 0))) ?></strong>
                        </div>
                        <div class="co-doc-sumbar__item">
                            <span>الضريبة</span>
                            <strong id="co-bar-tax"><?= esc(format_amount((float) ($order['tax_amount'] ?? 0))) ?></strong>
                        </div>
                        <div class="co-doc-sumbar__item co-doc-sumbar__item--grand">
                            <span>الإجمالي</span>
                            <strong id="co-bar-grand"><?= esc(format_amount((float) ($order['total'] ?? 0))) ?></strong>
                        </div>
                    </div>
                </div>
            </section>

            <!-- كشف حساب Oracle: رصيد + شيكات قيد التحصيل -->
            <section class="dashboard-ora-panel co-ora-ar-panel" id="co-ora-ar-panel" hidden>
                <h2 class="dashboard-ora-panel__title">كشف حساب العميل (Oracle) — المستحقات والشيكات</h2>
                <div class="dashboard-ora-panel__body">
                    <p class="co-ora-ar-status muted" id="co-ora-ar-status">اختر عميلاً ثم احفظ الطلب لعرض الرصيد والشيكات من Oracle.</p>
                    <div class="co-ora-ar-summary" id="co-ora-ar-summary" hidden>
                        <div class="co-ora-ar-kpis">
                            <div class="co-ora-ar-kpi">
                                <span>الحساب</span>
                                <strong id="co-ora-ar-account" dir="ltr">—</strong>
                            </div>
                            <div class="co-ora-ar-kpi co-ora-ar-kpi--debit">
                                <span>مجموع المدين</span>
                                <strong id="co-ora-ar-debit" dir="ltr">0</strong>
                            </div>
                            <div class="co-ora-ar-kpi co-ora-ar-kpi--credit">
                                <span>مجموع الدائن</span>
                                <strong id="co-ora-ar-credit" dir="ltr">0</strong>
                            </div>
                            <div class="co-ora-ar-kpi co-ora-ar-kpi--due">
                                <span>المبالغ المستحقة (الرصيد)</span>
                                <strong id="co-ora-ar-balance" dir="ltr">0</strong>
                            </div>
                            <div class="co-ora-ar-kpi">
                                <span>شيكات قيد التحصيل</span>
                                <strong id="co-ora-ar-chq-count" dir="ltr">0</strong>
                            </div>
                            <div class="co-ora-ar-kpi">
                                <span>إجمالي قيمة الشيكات</span>
                                <strong id="co-ora-ar-chq-total" dir="ltr">0</strong>
                            </div>
                        </div>
                        <p class="co-ora-ar-meta muted" id="co-ora-ar-meta"></p>
                        <div class="co-ora-ar-chq-wrap">
                            <h3 class="co-ora-ar-chq-title">الشيكات قيد التحصيل</h3>
                            <div class="co-ora-ar-table-wrap">
                                <table class="co-ora-ar-table">
                                    <thead>
                                    <tr>
                                        <th>رقم الشيك</th>
                                        <th>تاريخ الاستحقاق</th>
                                        <th>القيمة</th>
                                        <th>تاريخ القبض</th>
                                        <th>مرجع</th>
                                    </tr>
                                    </thead>
                                    <tbody id="co-ora-ar-chq-body">
                                    <tr><td colspan="5" class="muted">لا شيكات.</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <p class="co-ora-ar-actions">
                            <a class="btn btn-secondary btn-sm" id="co-ora-ar-full-link" href="#" target="_blank" rel="noopener">فتح الكشف التفصيلي من Oracle</a>
                            <button type="button" class="btn btn-ghost btn-sm" id="co-ora-ar-refresh">تحديث من Oracle</button>
                        </p>
                    </div>
                </div>
            </section>
        </form>
    </div>
</div>

<script type="application/json" id="co-initial-lines-json"><?= json_encode($orderLinesJson, JSON_UNESCAPED_UNICODE) ?></script>
<template id="sales-inv-line-template">
    <?php inv_invoice_line_table_row_template($taxRates, $unitPriceStep, $amountStep, false); ?>
</template>
<?php item_picker_modal_once(); ?>

<script src="<?= esc($jsDisc) ?>"></script>
<script src="<?= esc($jsCo) ?>"></script>
<script>
(function () {
  var form = document.getElementById('customer-order-form');
  var msg = document.getElementById('co-message');
  var csrf = <?= json_encode(csrf_token()) ?>;
  var entryBase = <?= json_encode($entryUrl) ?>;
  var listUrl = <?= json_encode($listUrl) ?>;
  var canApprove = <?= $canApprove ? 'true' : 'false' ?>;
  var canDelete = <?= $canDelete ? 'true' : 'false' ?>;
  var urls = {
    save: <?= json_encode(app_url('api/sales_customer_order_save.php')) ?>,
    approve: <?= json_encode(app_url('api/sales_customer_order_approve.php')) ?>,
    del: <?= json_encode(app_url('api/sales_customer_order_delete.php')) ?>,
    oraAr: <?= json_encode(app_url('api/oracle_customer_ar_summary.php')) ?>
  };

  /* ─── كشف حساب Oracle ─── */
  var oraPanel = document.getElementById('co-ora-ar-panel');
  var oraStatus = document.getElementById('co-ora-ar-status');
  var oraSummary = document.getElementById('co-ora-ar-summary');
  var oraLoadSeq = 0;

  function fmtAmt(n) {
    var x = parseFloat(n);
    if (!isFinite(x)) x = 0;
    try {
      return x.toLocaleString('en-US', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
    } catch (e) {
      return String(Math.round(x * 1000) / 1000);
    }
  }

  function fmtDate(iso) {
    iso = String(iso || '').trim();
    if (!iso) return '—';
    if (window.AppFormat && AppFormat.formatDateDmY) {
      var d = AppFormat.formatDateDmY(iso);
      if (d) return d;
    }
    var m = iso.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (m) return m[3] + '-' + m[2] + '-' + m[1];
    return iso;
  }

  function setOraStatus(text, kind) {
    if (!oraStatus) return;
    oraStatus.hidden = !text;
    oraStatus.textContent = text || '';
    oraStatus.classList.remove('is-error', 'is-ok');
    if (kind === 'error') oraStatus.classList.add('is-error');
    if (kind === 'ok') oraStatus.classList.add('is-ok');
  }

  function renderCheques(list) {
    var tbody = document.getElementById('co-ora-ar-chq-body');
    if (!tbody) return;
    tbody.innerHTML = '';
    if (!list || !list.length) {
      tbody.innerHTML = '<tr><td colspan="5" class="muted">لا شيكات قيد التحصيل لهذا العميل.</td></tr>';
      return;
    }
    list.forEach(function (ch) {
      var tr = document.createElement('tr');
      tr.innerHTML =
        '<td dir="ltr"></td><td dir="ltr"></td><td dir="ltr" class="col-money"></td><td dir="ltr"></td><td dir="ltr"></td>';
      tr.cells[0].textContent = ch.chq_no || '—';
      tr.cells[1].textContent = fmtDate(ch.chq_date);
      tr.cells[2].textContent = fmtAmt(ch.amount);
      tr.cells[3].textContent = fmtDate(ch.receipt_date);
      tr.cells[4].textContent = ch.receipt_ref || '—';
      tbody.appendChild(tr);
    });
  }

  function loadOracleAr(customerId, opts) {
    opts = opts || {};
    if (!oraPanel) return;
    customerId = parseInt(customerId, 10) || 0;
    if (!(customerId > 0)) {
      oraPanel.hidden = true;
      if (oraSummary) oraSummary.hidden = true;
      setOraStatus('اختر عميلاً لعرض المستحقات والشيكات من Oracle.', null);
      return;
    }
    oraPanel.hidden = false;
    if (oraSummary) oraSummary.hidden = true;
    setOraStatus(opts.afterSave ? 'تم الحفظ — جاري جلب الرصيد والشيكات من Oracle…' : 'جاري جلب كشف الحساب من Oracle…', null);

    var seq = ++oraLoadSeq;
    var url = urls.oraAr + (urls.oraAr.indexOf('?') >= 0 ? '&' : '?') + 'customer_id=' + encodeURIComponent(String(customerId));
    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (x) {
        if (seq !== oraLoadSeq) return;
        if (!x || !x.ok) {
          setOraStatus((x && x.message) ? x.message : 'تعذر جلب كشف الحساب من Oracle.', 'error');
          if (oraSummary) oraSummary.hidden = true;
          return;
        }
        setOraStatus('', null);
        if (oraSummary) oraSummary.hidden = false;
        var accEl = document.getElementById('co-ora-ar-account');
        var balEl = document.getElementById('co-ora-ar-balance');
        var debEl = document.getElementById('co-ora-ar-debit');
        var creEl = document.getElementById('co-ora-ar-credit');
        var cntEl = document.getElementById('co-ora-ar-chq-count');
        var totEl = document.getElementById('co-ora-ar-chq-total');
        var metaEl = document.getElementById('co-ora-ar-meta');
        var linkEl = document.getElementById('co-ora-ar-full-link');
        if (accEl) accEl.textContent = x.account || '—';
        if (debEl) debEl.textContent = fmtAmt(x.total_debit);
        if (creEl) creEl.textContent = fmtAmt(x.total_credit);
        if (balEl) balEl.textContent = fmtAmt(x.balance);
        if (cntEl) cntEl.textContent = String(x.cheque_count || 0);
        if (totEl) totEl.textContent = fmtAmt(x.cheque_total);
        if (metaEl) {
          metaEl.textContent =
            (x.name ? (x.name + ' · ') : '') +
            'الفترة: ' + fmtDate(x.from) + ' — ' + fmtDate(x.to) +
            ' · قراءة مباشرة من Oracle (GLVODMF + GLCHEQF)';
        }
        if (linkEl) {
          if (x.statement_url) {
            linkEl.href = x.statement_url;
            linkEl.hidden = false;
          } else {
            linkEl.hidden = true;
          }
        }
        renderCheques(x.cheques || []);
        if (opts.afterSave) {
          setMsg('تم الحفظ. تم تحديث مستحقات وشيكات العميل من Oracle.', 'ok');
          try {
            oraPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
          } catch (e) {}
        }
      })
      .catch(function () {
        if (seq !== oraLoadSeq) return;
        setOraStatus('فشل الاتصال أثناء جلب كشف الحساب من Oracle.', 'error');
        if (oraSummary) oraSummary.hidden = true;
      });
  }

  function setMsg(text, kind) {
    if (!msg) return;
    msg.textContent = text || '';
    msg.classList.remove('is-error', 'is-ok');
    if (kind === 'error') msg.classList.add('is-error');
    if (kind === 'ok') msg.classList.add('is-ok');
  }

  function syncBar() {
    var map = [
      ['sales-inv-sum-disc', 'co-bar-disc'],
      ['sales-inv-sum-sub', 'co-bar-sub'],
      ['sales-inv-sum-tax', 'co-bar-tax'],
      ['sales-inv-sum-grand', 'co-bar-grand']
    ];
    map.forEach(function (pair) {
      var src = document.getElementById(pair[0]);
      var dst = document.getElementById(pair[1]);
      if (src && dst) dst.textContent = src.textContent || '';
    });
  }

  var moTarget = document.querySelector('.sales-inv-totals');
  if (moTarget && window.MutationObserver) {
    new MutationObserver(syncBar).observe(moTarget, { childList: true, subtree: true, characterData: true });
  }
  setInterval(syncBar, 800);

  var whSel = document.getElementById('warehouse-id');
  if (whSel && form) {
    whSel.addEventListener('change', function () {
      form.setAttribute('data-warehouse-id', String(whSel.value || '0'));
    });
  }

  var linesApi = window.initCustomerOrderLines ? window.initCustomerOrderLines({}) : null;

  function post(url, data) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(data)
    }).then(function (r) { return r.json(); });
  }

  function orderDateIso() {
    var el = document.getElementById('order-date');
    var raw = el ? String(el.value || '').trim() : '';
    if (window.AppFormat && AppFormat.parseDateToIso) {
      var iso = AppFormat.parseDateToIso(raw);
      if (iso) return iso;
    }
    if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) return raw;
    return '';
  }

  function customerId() {
    var el = document.getElementById('co_customer');
    if (!el) return 0;
    return parseInt(el.value, 10) || 0;
  }

  function payload() {
    var lines = linesApi ? linesApi.collectLines() : [];
    return {
      id: parseInt((document.getElementById('order-id') || {}).value || '0', 10) || 0,
      order_date: orderDateIso(),
      customer_id: customerId(),
      warehouse_id: parseInt((document.getElementById('warehouse-id') || {}).value || '0', 10) || 0,
      sales_rep_id: parseInt((document.getElementById('co_sales_rep') || {}).value || '0', 10) || 0,
      notes: (document.getElementById('inv_notes') || {}).value || '',
      invoice_discount: linesApi ? linesApi.getHeaderDiscount() : '',
      lines: lines
    };
  }

  function validate(data) {
    if (!data.order_date) return 'أدخل تاريخ الطلب بشكل صحيح.';
    if (!(data.customer_id > 0)) return 'اختر العميل.';
    if (!(data.warehouse_id > 0)) return 'اختر المستودع.';
    if (!data.lines.length) return 'أدخل بنداً واحداً على الأقل.';
    return '';
  }

  function doSave(after) {
    var data = payload();
    var err = validate(data);
    if (err) {
      setMsg(err, 'error');
      return;
    }
    setMsg('جاري الحفظ…', null);
    post(urls.save, data).then(function (x) {
      if (!x.ok) {
        setMsg(x.message || 'تعذر الحفظ.', 'error');
        return;
      }
      setMsg(x.message || 'تم الحفظ.', 'ok');
      var newId = parseInt(x.order_id || (x.order && x.order.id) || 0, 10) || 0;
      var idEl = document.getElementById('order-id');
      if (idEl && newId > 0) idEl.value = String(newId);
      var noEl = document.getElementById('co_order_no');
      if (noEl && x.order && x.order.order_no) {
        noEl.value = String(x.order.order_no);
      }
      // عرض رصيد Oracle والشيكات في نهاية الطلب بعد الحفظ
      loadOracleAr(data.customer_id, { afterSave: true });
      if (typeof after === 'function') {
        after(newId, x);
        return;
      }
      if (newId > 0) {
        var target = entryBase + (entryBase.indexOf('?') >= 0 ? '&' : '?') + 'id=' + newId;
        // حدّث العنوان بدون فقدان اللوحة إن أمكن، وإلا أعد التحميل
        try {
          history.replaceState(null, '', target);
        } catch (e) {
          //
        }
        var delBtn = document.getElementById('co-delete');
        if (delBtn) delBtn.disabled = false;
      }
    });
  }

  function doApprove() {
    if (!canApprove) {
      setMsg('لا توجد صلاحية اعتماد.', 'error');
      return;
    }
    doSave(function (newId) {
      if (!(newId > 0)) {
        setMsg('تعذر الحفظ قبل الاعتماد.', 'error');
        return;
      }
      post(urls.approve, { id: newId }).then(function (x) {
        if (x.ok) {
          setMsg(x.message || 'تم الاعتماد.', 'ok');
          location.href = <?= json_encode(app_url('index.php?r=sales_customer_orders_approved')) ?>;
        } else {
          setMsg(x.message || 'تعذر الاعتماد.', 'error');
          location.href = entryBase + (entryBase.indexOf('?') >= 0 ? '&' : '?') + 'id=' + newId;
        }
      });
    });
  }

  function doDelete() {
    if (!canDelete) return;
    var id = parseInt((document.getElementById('order-id') || {}).value || '0', 10) || 0;
    if (!(id > 0)) {
      setMsg('احفظ الطلب أولاً قبل الحذف.', 'error');
      return;
    }
    if (!confirm('حذف هذا الطلب نهائياً؟')) return;
    post(urls.del, { id: id }).then(function (x) {
      if (x.ok) {
        setMsg(x.message || 'تم الحذف.', 'ok');
        location.href = listUrl;
      } else {
        setMsg(x.message || 'تعذر الحذف.', 'error');
      }
    });
  }

  var saveBtn = document.getElementById('co-save');
  var approveBtn = document.getElementById('co-approve');
  var deleteBtn = document.getElementById('co-delete');
  if (saveBtn) saveBtn.onclick = function () { doSave(); };
  if (approveBtn) approveBtn.onclick = doApprove;
  if (deleteBtn) deleteBtn.onclick = doDelete;

  window.CoEntryToolbar = {
    save: function () { doSave(); },
    approve: doApprove,
    delete: doDelete
  };

  document.addEventListener('master-toolbar', function (e) {
    var action = e.detail && e.detail.action;
    if (action !== 'save' && action !== 'approve' && action !== 'delete') return;
    e.preventDefault();
    e.stopImmediatePropagation();
    var api = window.CoEntryToolbar;
    if (!api) return;
    if (action === 'save') api.save();
    else if (action === 'approve') api.approve();
    else if (action === 'delete') api.delete();
  });

  // تحميل عند فتح طلب محفوظ / تغيير العميل
  var custEl = document.getElementById('co_customer');
  if (custEl) {
    custEl.addEventListener('change', function () {
      loadOracleAr(customerId(), {});
    });
  }
  var refreshBtn = document.getElementById('co-ora-ar-refresh');
  if (refreshBtn) {
    refreshBtn.addEventListener('click', function () {
      loadOracleAr(customerId(), {});
    });
  }
  // عند فتح مسودة محفوظة: اعرض الرصيد والشيكات مباشرة
  if (customerId() > 0) {
    loadOracleAr(customerId(), { afterSave: false });
  } else if (oraPanel) {
    oraPanel.hidden = false;
    setOraStatus('اختر عميلاً ثم احفظ الطلب — سيظهر الرصيد المستحق وشيكاته من Oracle هنا.', null);
  }
})();
</script>
