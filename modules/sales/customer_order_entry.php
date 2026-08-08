<?php
declare(strict_types=1);

/**
 * إنشاء / تعديل مسودة طلب شراء عميل (ديسكتوب).
 */
require_once app_path('includes/sal_customer_order.php');
require_once app_path('includes/inv_item_units.php');
require_once app_path('includes/inv_warehouse_items.php');
require_once app_path('includes/sales_oracle12_ui.php');
require_once app_path('includes/crm_sales_rep_schema.php');
require_once app_path('includes/item_picker.php');
require_once app_path('includes/customer_picker.php');
require_once app_path('includes/warehouse_access.php');

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
// إذا فُتح الرابط من الشاشة الأخرى استخدم r الحالي
if (isset($_GET['r']) && (string) $_GET['r'] === 'sales_customer_order_entry_approve') {
    $entryUrl = app_url('index.php?r=sales_customer_order_entry_approve');
    $newUrl = $entryUrl;
}

$cssInvPath = app_path('assets/css/sales-invoice.css');
$cssInv = app_url('assets/css/sales-invoice.css') . (is_file($cssInvPath) ? '?v=' . (string) filemtime($cssInvPath) : '');
$jsDiscPath = app_path('assets/js/inv-invoice-discount.js');
$jsDisc = app_url('assets/js/inv-invoice-discount.js') . (is_readable($jsDiscPath) ? '?v=' . (string) filemtime($jsDiscPath) : '1');
$jsCoPath = app_path('assets/js/customer-order-lines.js');
$jsCo = app_url('assets/js/customer-order-lines.js') . (is_readable($jsCoPath) ? '?v=' . (string) filemtime($jsCoPath) : '1');

$activeRoute = (string) ($GLOBALS['activeRoute'] ?? 'sales_customer_order_entry');
if (!in_array($activeRoute, ['sales_customer_order_entry', 'sales_customer_order_entry_approve'], true)) {
    $activeRoute = 'sales_customer_order_entry';
}
sales_ora12_enqueue_assets();
customer_picker_json_script($customers, 'co-entry-customers-json');
?>
<link rel="stylesheet" href="<?= esc($cssInv) ?>">
<div class="dashboard-ora sales-ora12-screen sales-inv-wrap sales-inv-main sales-inv-bold" data-exit-guard="custom">
<?php
sales_ora12_render_title_bar(
    $order ? ('تعديل طلب شراء عميل') : 'طلب شراء عميل جديد',
    $orderNo !== '' ? $orderNo : '',
    $activeRoute
);
?>
<?php sales_ora12_workspace_open(); ?>

<div class="dashboard-ora-toolbar sales-inv-toolbar no-print" style="margin-bottom:0.75rem;display:flex;gap:0.5rem;flex-wrap:wrap;">
    <a class="btn btn-primary btn-sm" href="<?= esc($newUrl) ?>">+ طلب جديد</a>
    <a class="btn btn-secondary btn-sm" href="<?= esc($listUrl) ?>">قائمة الطلبات</a>
    <?php if (user_can('sales_customer_orders_approve')): ?>
        <a class="btn btn-secondary btn-sm" href="<?= esc(app_url('index.php?r=sales_customer_orders_approve')) ?>">اعتماد الطلبات</a>
    <?php endif; ?>
</div>

<form id="customer-order-form" class="master-page-form"
      data-decimals="<?= (int) $dp ?>"
      data-unit-price-decimals="<?= (int) $unitPriceDp ?>"
      data-default-tax-rate="<?= esc((string) $defaultTax) ?>"
      data-warehouse-id="<?= (int) $warehouseId ?>"
      data-api-items="<?= esc(app_url('api/items_search.php')) ?>"
      data-invoice-discount="<?= esc($headerDisc) ?>">
    <input type="hidden" id="order-id" value="<?= (int) ($order['id'] ?? 0) ?>">

    <section class="dashboard-ora-panel no-print">
        <h2 class="dashboard-ora-panel__title">بيانات الطلب</h2>
        <div class="dashboard-ora-panel__body">
            <header class="sales-inv-doc-header sales-inv-meta-panel">
                <div class="sales-inv-meta-row">
                    <div class="sales-inv-meta-item sales-inv-meta-no">
                        <label for="co_order_no">رقم الطلب</label>
                        <input class="input input-compact" type="text" id="co_order_no"
                               value="<?= esc($orderNo) ?>" placeholder="يُولَّد عند الحفظ" readonly>
                    </div>
                    <div class="sales-inv-meta-item sales-inv-meta-date">
                        <label for="order-date">التاريخ</label>
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
                    <div class="sales-inv-meta-item sales-inv-meta-rep">
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
        <h2 class="dashboard-ora-panel__title no-print">بنود الطلب</h2>
        <div class="dashboard-ora-panel__body dashboard-ora-panel__body--flush">
            <div class="form-row no-print" style="margin:0.5rem 0.75rem;gap:0.5rem;">
                <button type="button" id="co-add-item" class="btn btn-secondary btn-sm">إضافة مادة</button>
            </div>
            <div class="sales-inv-table-wrap" id="sales-inv-table-wrap">
                <table class="sales-inv-table">
                    <thead>
                    <?php
                    require_once app_path('includes/inv_invoice_line_table.php');
                    inv_invoice_line_table_head(false);
                    ?>
                    </thead>
                    <tbody id="co-lines-body"></tbody>
                </table>
            </div>
            <div class="sales-inv-footer-grid">
                <div class="sales-inv-notes sales-inv-field">
                    <label for="inv_notes">ملاحظات</label>
                    <textarea class="input" name="notes" id="inv_notes" rows="3" placeholder="اختياري"><?= esc($notes) ?></textarea>
                </div>
                <div class="sales-inv-totals">
                    <div class="row sales-inv-totals-disc">
                        <label for="inv-invoice-discount">خصم الطلب (كامل) <span class="sales-inv-disc-hint">10 أو 10% أو مبلغ</span></label>
                        <input type="text" class="input input-compact input-num" name="invoice_discount" id="inv-invoice-discount"
                               value="<?= esc($headerDisc) ?>" title="خصم على مستوى الطلب كامل" autocomplete="off">
                    </div>
                    <div class="row sales-inv-totals-header-disc" id="sales-inv-header-disc-row" hidden>
                        <span>قيمة خصم مستوى الطلب</span>
                        <span id="sales-inv-sum-header-disc"><?= esc(format_amount(0)) ?></span>
                    </div>
                    <div class="row"><span>مجموع الخصم</span><span id="sales-inv-sum-disc"><?= esc(format_amount((float) ($order['discount_amount'] ?? 0))) ?></span></div>
                    <div class="row"><span>المجموع بدون ضريبة</span><span id="sales-inv-sum-sub"><?= esc(format_amount((float) ($order['subtotal'] ?? 0))) ?></span></div>
                    <div class="row"><span>مجموع الضريبة</span><span id="sales-inv-sum-tax"><?= esc(format_amount((float) ($order['tax_amount'] ?? 0))) ?></span></div>
                    <div class="row grand"><span>الإجمالي</span><span id="sales-inv-sum-grand"><?= esc(format_amount((float) ($order['total'] ?? 0))) ?></span></div>
                </div>
            </div>
            <p id="co-message" class="muted" style="padding:0 0.75rem 0.75rem;"></p>
            <div class="form-row no-print sr-only" aria-hidden="true">
                <button type="button" id="co-save" class="btn btn-primary">حفظ</button>
                <?php if ($canApprove): ?>
                    <button type="button" id="co-approve" class="btn btn-success">اعتماد</button>
                <?php endif; ?>
                <?php if ($canDelete && $id > 0): ?>
                    <button type="button" id="co-delete" class="btn btn-secondary">حذف</button>
                <?php endif; ?>
            </div>
        </div>
    </section>
</form>

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
    del: <?= json_encode(app_url('api/sales_customer_order_delete.php')) ?>
  };

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
      msg.textContent = err;
      return;
    }
    msg.textContent = 'جاري الحفظ…';
    post(urls.save, data).then(function (x) {
      msg.textContent = x.message || (x.ok ? 'تم الحفظ.' : 'تعذر الحفظ.');
      if (!x.ok) return;
      var newId = parseInt(x.order_id || (x.order && x.order.id) || 0, 10) || 0;
      if (typeof after === 'function') {
        after(newId, x);
        return;
      }
      if (newId > 0) {
        location.href = entryBase + (entryBase.indexOf('?') >= 0 ? '&' : '?') + 'id=' + newId;
      } else {
        location.reload();
      }
    });
  }

  function doApprove() {
    if (!canApprove) {
      msg.textContent = 'لا توجد صلاحية اعتماد.';
      return;
    }
    doSave(function (newId) {
      if (!(newId > 0)) {
        msg.textContent = 'تعذر الحفظ قبل الاعتماد.';
        return;
      }
      post(urls.approve, { id: newId }).then(function (x) {
        msg.textContent = x.message || (x.ok ? 'تم الاعتماد.' : 'تعذر الاعتماد.');
        if (x.ok) {
          location.href = <?= json_encode(app_url('index.php?r=sales_customer_orders_approved')) ?>;
        } else {
          location.href = entryBase + (entryBase.indexOf('?') >= 0 ? '&' : '?') + 'id=' + newId;
        }
      });
    });
  }

  function doDelete() {
    if (!canDelete) return;
    var id = parseInt((document.getElementById('order-id') || {}).value || '0', 10) || 0;
    if (!(id > 0)) {
      msg.textContent = 'احفظ الطلب أولاً قبل الحذف.';
      return;
    }
    if (!confirm('حذف هذا الطلب نهائياً؟')) return;
    post(urls.del, { id: id }).then(function (x) {
      msg.textContent = x.message || (x.ok ? 'تم الحذف.' : 'تعذر الحذف.');
      if (x.ok) location.href = listUrl;
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
})();
</script>
<?php sales_ora12_workspace_close(); ?>
</div>
