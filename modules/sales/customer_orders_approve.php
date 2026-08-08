<?php
declare(strict_types=1);

require_once app_path('includes/sal_customer_order.php');
require_once app_path('includes/inv_item_units.php');
require_once app_path('includes/sales_oracle12_ui.php');
require_once app_path('includes/list_pagination.php');
require_once app_path('includes/crm_sales_rep_schema.php');
require_once app_path('includes/item_picker.php');

$pdo = db();
sal_customer_order_ensure_schema($pdo);
inv_item_units_ensure_schema($pdo);
crm_sales_rep_ensure_schema($pdo);
item_picker_enqueue_assets();

$id = (int) ($_GET['id'] ?? 0);
$q = trim((string) ($_GET['q'] ?? ''));
$salesRepId = isset($_GET['sales_rep_id']) && $_GET['sales_rep_id'] !== '' ? (int) $_GET['sales_rep_id'] : 0;
$customerId = isset($_GET['customer_id']) && $_GET['customer_id'] !== '' ? (int) $_GET['customer_id'] : 0;
$filterRep = $salesRepId > 0 ? $salesRepId : null;
$filterCust = $customerId > 0 ? $customerId : null;

$pager = list_pager_from_request($pdo);
$total = sal_customer_order_list_count($pdo, $q, $filterRep, 'draft', $filterCust);
$pager = list_pager_with_total($pager, $total);
$rows = sal_customer_order_list_fetch(
    $pdo,
    $q,
    $filterRep,
    'draft',
    $filterCust,
    $pager['limit'],
    $pager['offset']
);

$order = $id ? sal_customer_order_fetch($pdo, $id) : null;
if ($order && (string) ($order['status'] ?? '') !== 'draft') {
    // الطلب المعتمد يُعرض في شاشة المعتمدة
    redirect(app_url('index.php?r=sales_customer_orders_approved&id=' . (int) $order['id']));
}

$salesReps = $pdo->query(
    'SELECT id, name_ar FROM crm_sales_rep WHERE is_active = 1 ORDER BY name_ar'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$customers = $pdo->query(
    'SELECT id, code, name_ar FROM crm_customer WHERE is_active = 1 ORDER BY name_ar'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$filterQuery = array_filter([
    'q' => $q !== '' ? $q : null,
    'sales_rep_id' => $salesRepId > 0 ? $salesRepId : null,
    'customer_id' => $customerId > 0 ? $customerId : null,
], static fn ($v) => $v !== null && $v !== '');
$pagerUrl = list_pager_base_url('sales_customer_orders_approve', $filterQuery);

$activeRoute = 'sales_customer_orders_approve';
sales_ora12_enqueue_assets();
$warehouseIdForPicker = $order ? (int) ($order['warehouse_id'] ?? 0) : 0;

$settings = company_settings($pdo);
$dp = company_decimal_places($pdo);
$unitPriceDp = company_invoice_unit_price_decimal_places($pdo);
$unitPriceStep = company_invoice_unit_price_decimal_step($pdo);
$amountStep = company_decimal_step($dp);
$defaultTax = (float) ($settings['tax_rate_percent'] ?? 15);
$taxRates = [];
try {
    $taxRates = $pdo->query('SELECT id, name_ar, rate_percent FROM sys_tax_rate WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll();
} catch (Throwable $e) {
    $taxRates = [];
}
if (!$taxRates) {
    $taxRates = [['id' => 0, 'name_ar' => 'افتراضي (' . $defaultTax . '%)', 'rate_percent' => $defaultTax]];
}

// وحدات البنود للتحميل
$orderLinesJson = [];
if ($order) {
    require_once app_path('includes/inv_item_units.php');
    foreach ($order['lines'] as $line) {
        $itemUnits = inv_item_units_for_item($pdo, (int) $line['item_id']);
        $line['units'] = $itemUnits;
        $orderLinesJson[] = $line;
    }
}

$cssInvPath = app_path('assets/css/sales-invoice.css');
$cssInv = app_url('assets/css/sales-invoice.css') . (is_file($cssInvPath) ? '?v=' . (string) filemtime($cssInvPath) : '');
$jsDiscPath = app_path('assets/js/inv-invoice-discount.js');
$jsDisc = app_url('assets/js/inv-invoice-discount.js') . (is_readable($jsDiscPath) ? '?v=' . (string) filemtime($jsDiscPath) : '1');
$jsCoPath = app_path('assets/js/customer-order-lines.js');
$jsCo = app_url('assets/js/customer-order-lines.js') . (is_readable($jsCoPath) ? '?v=' . (string) filemtime($jsCoPath) : '1');
?>
<link rel="stylesheet" href="<?= esc($cssInv) ?>">
<div class="dashboard-ora sales-ora12-screen">
<?php sales_ora12_render_title_bar('اعتماد طلبات الشراء', '', $activeRoute); ?>
<?php sales_ora12_workspace_open(); ?>

<div class="sales-ora-panel card">
    <h2>طلبات بانتظار الاعتماد</h2>
    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="form-row" style="flex-wrap:wrap;gap:0.5rem;align-items:end;">
        <input type="hidden" name="r" value="sales_customer_orders_approve">
        <?php if ($id > 0): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
        <div class="field">
            <label>بحث</label>
            <input class="input" name="q" value="<?= esc($q) ?>" placeholder="رقم الطلب أو العميل أو المندوب">
        </div>
        <div class="field">
            <label>المندوب</label>
            <select class="input" name="sales_rep_id">
                <option value="">جميع المندوبين</option>
                <?php foreach ($salesReps as $rep): ?>
                    <option value="<?= (int) $rep['id'] ?>" <?= $salesRepId === (int) $rep['id'] ? 'selected' : '' ?>>
                        <?= esc((string) $rep['name_ar']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>العميل / الشركة</label>
            <select class="input" name="customer_id">
                <option value="">جميع العملاء</option>
                <?php foreach ($customers as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= $customerId === (int) $c['id'] ? 'selected' : '' ?>>
                        <?= esc((string) $c['name_ar']) ?><?= trim((string) ($c['code'] ?? '')) !== '' ? ' (' . esc((string) $c['code']) . ')' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <a class="btn btn-primary" type="button" href="<?= esc(app_url(
            user_can('sales_customer_orders')
                ? 'index.php?r=sales_customer_order_entry'
                : 'index.php?r=sales_customer_order_entry_approve'
        )) ?>">+ طلب جديد</a>
        <button class="btn btn-primary" type="submit">تصفية</button>
        <a class="btn btn-secondary" href="<?= esc(app_url('index.php?r=sales_customer_orders_approve')) ?>">إعادة تعيين</a>
        <a class="btn btn-secondary" href="<?= esc(app_url('index.php?r=sales_customer_orders_approved')) ?>">الطلبات المعتمدة</a>
    </form>
</div>

<div class="sales-ora-panel card">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>الرقم</th>
                <th>العميل</th>
                <th>المندوب</th>
                <th>التاريخ</th>
                <th>البنود</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr class="<?= $id === (int) $r['id'] ? 'is-selected' : '' ?>">
                    <td><code><?= esc((string) $r['order_no']) ?></code></td>
                    <td><?= esc((string) $r['customer_name']) ?></td>
                    <td><?= esc((string) ($r['sales_rep_name'] ?: '—')) ?></td>
                    <td><?= esc(format_date_dmY((string) $r['order_date'])) ?></td>
                    <td><?= (int) $r['line_count'] ?></td>
                    <td>
                        <a class="btn btn-sm" href="<?= esc(app_url('index.php?r=sales_customer_orders_approve&id=' . (int) $r['id'] . '&' . http_build_query($filterQuery))) ?>">فتح</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="6" class="muted" style="text-align:center;">لا توجد طلبات بانتظار الاعتماد.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php list_pager_render($pager, $pagerUrl); ?>
</div>

<?php if ($order): ?>
<div class="sales-ora-panel card sales-inv-wrap sales-inv-bold">
    <h3>رقم الطلب: <code><?= esc((string) $order['order_no']) ?></code> — <?= esc((string) $order['customer_name']) ?></h3>
    <p>
        المستودع: <?= esc((string) $order['warehouse_name']) ?>
        | المندوب: <?= esc((string) ($order['sales_rep_name'] ?: '—')) ?>
        | التاريخ: <?= esc(format_date_dmY((string) $order['order_date'])) ?>
    </p>
    <form id="customer-order-form" class="sales-inv-main"
          data-decimals="<?= (int) $dp ?>"
          data-unit-price-decimals="<?= (int) $unitPriceDp ?>"
          data-default-tax-rate="<?= esc((string) $defaultTax) ?>"
          data-warehouse-id="<?= (int) $warehouseIdForPicker ?>"
          data-api-items="<?= esc(app_url('api/items_search.php')) ?>"
          data-invoice-discount="<?= esc((string) ($order['invoice_discount_input'] ?? '')) ?>">
        <input type="hidden" id="order-id" value="<?= (int) $order['id'] ?>">
        <input type="hidden" id="order-date" value="<?= esc((string) $order['order_date']) ?>">
        <input type="hidden" id="customer-id" value="<?= (int) $order['customer_id'] ?>">
        <input type="hidden" id="warehouse-id" value="<?= (int) $order['warehouse_id'] ?>">
        <div class="form-row no-print" style="margin-bottom:0.75rem;gap:0.5rem;align-items:center;">
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
                <textarea class="input" name="notes" id="inv_notes" rows="3" placeholder="اختياري"><?= esc((string) ($order['notes'] ?? '')) ?></textarea>
            </div>
            <div class="sales-inv-totals">
                <div class="row sales-inv-totals-disc">
                    <label for="inv-invoice-discount">خصم الطلب (كامل) <span class="sales-inv-disc-hint">10 أو 10% أو مبلغ</span></label>
                    <input type="text" class="input input-compact input-num" name="invoice_discount" id="inv-invoice-discount"
                           value="<?= esc((string) ($order['invoice_discount_input'] ?? '')) ?>"
                           title="خصم على مستوى الطلب كامل" autocomplete="off">
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
        <p id="co-message" class="muted"></p>
        <div class="form-row no-print sr-only" aria-hidden="true">
            <button type="button" id="co-save" class="btn btn-primary">حفظ التعديلات</button>
            <button type="button" id="co-approve" class="btn btn-success">اعتماد</button>
            <button type="button" id="co-delete" class="btn btn-secondary">حذف الطلب</button>
        </div>
    </form>
    <script type="application/json" id="co-initial-lines-json"><?= json_encode($orderLinesJson, JSON_UNESCAPED_UNICODE) ?></script>
    <template id="sales-inv-line-template">
        <?php
        inv_invoice_line_table_row_template($taxRates, $unitPriceStep, $amountStep, false);
        ?>
    </template>
<script src="<?= esc($jsDisc) ?>"></script>
<script src="<?= esc($jsCo) ?>"></script>
<script>
(function () {
  var id = +document.getElementById('order-id').value;
  var msg = document.getElementById('co-message');
  var csrf = <?= json_encode(csrf_token()) ?>;
  var urls = {
    save: <?= json_encode(app_url('api/sales_customer_order_save.php')) ?>,
    approve: <?= json_encode(app_url('api/sales_customer_order_approve.php')) ?>,
    del: <?= json_encode(app_url('api/sales_customer_order_delete.php')) ?>,
    list: <?= json_encode(app_url('index.php?r=sales_customer_orders_approve&' . http_build_query($filterQuery))) ?>
  };
  var linesApi = window.initCustomerOrderLines ? window.initCustomerOrderLines({}) : null;

  function post(url, data) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(data)
    }).then(function (r) { return r.json(); });
  }

  function payload() {
    var lines = linesApi ? linesApi.collectLines() : [];
    return {
      id: id,
      order_date: document.getElementById('order-date').value,
      customer_id: +document.getElementById('customer-id').value,
      warehouse_id: +document.getElementById('warehouse-id').value,
      notes: (document.getElementById('inv_notes') || {}).value || '',
      invoice_discount: linesApi ? linesApi.getHeaderDiscount() : '',
      lines: lines
    };
  }

  function doSave() {
    var data = payload();
    if (!data.lines.length) {
      msg.textContent = 'أدخل بنداً واحداً على الأقل.';
      return;
    }
    post(urls.save, data).then(function (x) {
      msg.textContent = x.message || (x.ok ? 'تم الحفظ.' : 'تعذر الحفظ.');
      if (x.ok) location.reload();
    });
  }

  function doApprove() {
    var data = payload();
    if (!data.lines.length) {
      msg.textContent = 'احفظ بنداً واحداً على الأقل قبل الاعتماد.';
      return;
    }
    post(urls.save, data).then(function (x) {
      if (!x.ok) {
        msg.textContent = x.message || 'تعذر الحفظ قبل الاعتماد.';
        return null;
      }
      return post(urls.approve, { id: id });
    }).then(function (x) {
      if (!x) return;
      msg.textContent = x.message || (x.ok ? 'تم الاعتماد.' : 'تعذر الاعتماد.');
      if (x.ok) location.href = <?= json_encode(app_url('index.php?r=sales_customer_orders_approved')) ?>;
    });
  }

  function doDelete() {
    if (!confirm('حذف هذا الطلب نهائياً؟')) return;
    post(urls.del, { id: id }).then(function (x) {
      msg.textContent = x.message || (x.ok ? 'تم الحذف.' : 'تعذر الحذف.');
      if (x.ok) location.href = urls.list;
    });
  }

  var saveBtn = document.getElementById('co-save');
  var approveBtn = document.getElementById('co-approve');
  var deleteBtn = document.getElementById('co-delete');
  if (saveBtn) saveBtn.onclick = doSave;
  if (approveBtn) approveBtn.onclick = doApprove;
  if (deleteBtn) deleteBtn.onclick = doDelete;

  window.CoApproveToolbar = { save: doSave, approve: doApprove, delete: doDelete };
})();
</script>
</div>
<?php item_picker_modal_once(); ?>
<?php endif; ?>
<script>
document.addEventListener('master-toolbar', function (e) {
  var action = e.detail && e.detail.action;
  if (action !== 'save' && action !== 'approve' && action !== 'delete') return;
  e.preventDefault();
  e.stopImmediatePropagation();
  var api = window.CoApproveToolbar;
  if (!api) {
    if (window.AppDialog && AppDialog.alert) {
      AppDialog.alert('افتح طلباً من القائمة أولاً.', { type: 'warning' });
    } else {
      alert('افتح طلباً من القائمة أولاً.');
    }
    return;
  }
  if (action === 'save') {
    api.save();
    return;
  }
  if (action === 'approve') {
    <?php if (!sal_customer_order_user_can_approve()): ?>
    if (window.AppDialog && AppDialog.alert) AppDialog.alert('لا توجد صلاحية اعتماد الطلب.', { type: 'warning' });
    else alert('لا توجد صلاحية اعتماد الطلب.');
    return;
    <?php endif; ?>
    api.approve();
    return;
  }
  if (action === 'delete') {
    <?php if (!sal_customer_order_user_can_delete_managed()): ?>
    if (window.AppDialog && AppDialog.alert) AppDialog.alert('لا توجد صلاحية حذف الطلب.', { type: 'warning' });
    else alert('لا توجد صلاحية حذف الطلب.');
    return;
    <?php endif; ?>
    api.delete();
  }
});
</script>
<?php sales_ora12_workspace_close(); ?>
</div>
