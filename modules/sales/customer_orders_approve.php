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
?>
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
<div class="sales-ora-panel card">
    <h3><?= esc((string) $order['order_no']) ?> — <?= esc((string) $order['customer_name']) ?></h3>
    <p>
        المستودع: <?= esc((string) $order['warehouse_name']) ?>
        | المندوب: <?= esc((string) ($order['sales_rep_name'] ?: '—')) ?>
        | التاريخ: <?= esc(format_date_dmY((string) $order['order_date'])) ?>
    </p>
    <form id="customer-order-form">
        <input type="hidden" id="order-id" value="<?= (int) $order['id'] ?>">
        <input type="hidden" id="order-date" value="<?= esc((string) $order['order_date']) ?>">
        <input type="hidden" id="customer-id" value="<?= (int) $order['customer_id'] ?>">
        <input type="hidden" id="warehouse-id" value="<?= (int) $order['warehouse_id'] ?>">
        <div class="form-row" style="margin-bottom:0.75rem;gap:0.5rem;align-items:center;">
            <button type="button" id="co-add-item" class="btn btn-secondary btn-sm">إضافة مادة</button>
        </div>
        <table class="data-table" id="co-lines-table">
            <thead>
            <tr>
                <th>الصنف</th>
                <th>الوحدة / التعبئة</th>
                <th>الكمية</th>
                <th>العدد</th>
                <th></th>
            </tr>
            </thead>
            <tbody id="co-lines-body">
            <?php foreach ($order['lines'] as $line):
                $itemUnits = inv_item_units_for_item($pdo, (int) $line['item_id']);
                $qtyInt = (int) round((float) ($line['qty'] ?? 0));
                $curUnitId = (int) ($line['unit_id'] ?? 0);
                $curFactor = (float) ($line['unit_factor'] ?? 0);
                if ($curFactor <= 0) {
                    foreach ($itemUnits as $u) {
                        if ((int) $u['unit_id'] === $curUnitId) {
                            $curFactor = (float) $u['factor'];
                            break;
                        }
                    }
                }
                if ($curFactor <= 0) {
                    $curFactor = 1.0;
                }
                $qtyBaseDisp = $qtyInt > 0 ? rtrim(rtrim(number_format($qtyInt * $curFactor, 6, '.', ''), '0'), '.') : '';
                ?>
                <tr data-item="<?= (int) $line['item_id'] ?>" data-item-name="<?= esc((string) $line['item_name']) ?>">
                    <td><?= esc((string) $line['item_name']) ?></td>
                    <td>
                        <select class="input co-unit">
                            <?php foreach ($itemUnits as $u): ?>
                                <option value="<?= (int) $u['unit_id'] ?>"
                                        data-name="<?= esc((string) $u['name']) ?>"
                                        data-factor="<?= esc((string) ((float) $u['factor'])) ?>"
                                        <?= $curUnitId === (int) $u['unit_id'] ? 'selected' : '' ?>>
                                    <?= esc((string) $u['name']) ?><?= (float) $u['factor'] > 1 ? ' × ' . rtrim(rtrim(number_format((float) $u['factor'], 6, '.', ''), '0'), '.') : '' ?>
                                </option>
                            <?php endforeach; ?>
                            <?php if ($itemUnits === []): ?>
                                <option value="<?= $curUnitId ?>" data-name="<?= esc((string) ($line['unit_name'] ?? '')) ?>" data-factor="<?= esc((string) $curFactor) ?>" selected><?= esc((string) ($line['unit_name'] ?? '—')) ?></option>
                            <?php endif; ?>
                        </select>
                    </td>
                    <td>
                        <input class="input co-qty" type="number" step="1" min="1" inputmode="numeric" value="<?= $qtyInt ?>">
                    </td>
                    <td>
                        <input class="input co-qty-base" type="text" readonly tabindex="-1" dir="ltr" value="<?= esc($qtyBaseDisp) ?>" title="العدد = الكمية × التعبئة">
                    </td>
                    <td>
                        <button type="button" class="btn btn-secondary btn-sm co-remove-line">حذف</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p id="co-message" class="muted"></p>
        <div class="form-row no-print sr-only" aria-hidden="true">
            <button type="button" id="co-save" class="btn btn-primary">حفظ التعديلات</button>
            <button type="button" id="co-approve" class="btn btn-success">اعتماد</button>
            <button type="button" id="co-delete" class="btn btn-secondary">حذف الطلب</button>
        </div>
    </form>
<script>
(function () {
  var id = +document.getElementById('order-id').value;
  var msg = document.getElementById('co-message');
  var csrf = <?= json_encode(csrf_token()) ?>;
  var warehouseId = <?= (int) $warehouseIdForPicker ?>;
  var tbody = document.getElementById('co-lines-body');
  var urls = {
    save: <?= json_encode(app_url('api/sales_customer_order_save.php')) ?>,
    approve: <?= json_encode(app_url('api/sales_customer_order_approve.php')) ?>,
    del: <?= json_encode(app_url('api/sales_customer_order_delete.php')) ?>,
    list: <?= json_encode(app_url('index.php?r=sales_customer_orders_approve&' . http_build_query($filterQuery))) ?>,
    items: <?= json_encode(app_url('api/items_search.php')) ?>
  };

  function buildItemsUrl(q, listAll) {
    var parts = [];
    if (listAll || !q) parts.push('list=1');
    else parts.push('q=' + encodeURIComponent(q));
    if (warehouseId > 0) parts.push('warehouse_id=' + encodeURIComponent(String(warehouseId)));
    return urls.items + (urls.items.indexOf('?') >= 0 ? '&' : '?') + parts.join('&');
  }

  function post(url, data) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(data)
    }).then(function (r) { return r.json(); });
  }

  function syncCoQtyBase(r) {
    var qtyEl = r.querySelector('.co-qty');
    var baseEl = r.querySelector('.co-qty-base');
    var unitSel = r.querySelector('.co-unit');
    var factor = 1;
    if (unitSel && unitSel.selectedIndex >= 0) {
      factor = parseFloat(unitSel.options[unitSel.selectedIndex].getAttribute('data-factor') || '1') || 1;
    }
    var qty = parseInt(qtyEl ? qtyEl.value : 0, 10) || 0;
    if (baseEl) baseEl.value = qty > 0 ? String(Math.round(qty * factor * 1e6) / 1e6) : '';
  }

  function bindRow(r) {
    var unitSel = r.querySelector('.co-unit');
    var qtyEl = r.querySelector('.co-qty');
    var rem = r.querySelector('.co-remove-line');
    if (unitSel) unitSel.addEventListener('change', function () { syncCoQtyBase(r); });
    if (qtyEl) qtyEl.addEventListener('input', function () { syncCoQtyBase(r); });
    if (rem) rem.addEventListener('click', function () { r.remove(); });
    syncCoQtyBase(r);
  }

  function collectLines() {
    var lines = [];
    tbody.querySelectorAll('tr[data-item]').forEach(function (r) {
      var unitSel = r.querySelector('.co-unit');
      var unitId = 0, unitName = '', unitFactor = 1;
      if (unitSel && unitSel.selectedIndex >= 0) {
        unitId = +unitSel.value || 0;
        unitName = unitSel.options[unitSel.selectedIndex].getAttribute('data-name')
          || unitSel.options[unitSel.selectedIndex].textContent.trim();
        unitName = String(unitName).replace(/\s*×\s*[\d.]+$/, '').trim();
        unitFactor = parseFloat(unitSel.options[unitSel.selectedIndex].getAttribute('data-factor') || '1') || 1;
      }
      var qty = parseInt(r.querySelector('.co-qty').value, 10) || 0;
      lines.push({
        item_id: +r.dataset.item,
        item_name: r.dataset.itemName || r.cells[0].textContent.trim(),
        unit_id: unitId,
        unit_name: unitName,
        unit_factor: unitFactor,
        qty: qty,
        qty_base: qty * unitFactor
      });
    });
    return lines;
  }

  function formatFactor(f) {
    f = parseFloat(f) || 1;
    if (Math.abs(f - Math.round(f)) < 1e-9) return String(Math.round(f));
    return String(Math.round(f * 1e6) / 1e6);
  }

  function addLineFromItem(it) {
    if (!it || !(+it.id > 0)) return;
    var itemId = +it.id;
    var existing = tbody.querySelector('tr[data-item="' + itemId + '"]');
    if (existing) {
      var q = existing.querySelector('.co-qty');
      if (q) q.value = String((parseInt(q.value, 10) || 0) + 1);
      syncCoQtyBase(existing);
      return;
    }
    var units = Array.isArray(it.units) ? it.units : [];
    var pick = null;
    units.forEach(function (u) {
      if (!pick && (u.is_default || u.is_default_issue)) pick = u;
    });
    if (!pick) {
      units.forEach(function (u) {
        if (!pick && !u.is_base && (parseFloat(u.factor) || 1) > 1) pick = u;
      });
    }
    if (!pick && units.length) pick = units[0];
    var unitId = pick ? (parseInt(pick.unit_id != null ? pick.unit_id : pick.id, 10) || 0) : (parseInt(it.unit_id, 10) || 0);
    var unitName = pick ? String(pick.name || pick.unit_name || 'قطعة') : String(it.unit_name || 'قطعة');
    var factor = pick ? (parseFloat(pick.factor != null ? pick.factor : pick.factor_to_base) || 1) : 1;

    var tr = document.createElement('tr');
    tr.setAttribute('data-item', String(itemId));
    tr.setAttribute('data-item-name', String(it.name_ar || it.name || ''));
    var opts = '';
    if (units.length) {
      units.forEach(function (u) {
        var uid = parseInt(u.unit_id != null ? u.unit_id : u.id, 10) || 0;
        var un = String(u.name || u.unit_name || 'قطعة');
        var uf = parseFloat(u.factor != null ? u.factor : u.factor_to_base) || 1;
        var lab = uf > 1.0000001 ? (un + ' × ' + formatFactor(uf)) : un;
        opts += '<option value="' + uid + '" data-name="' + un.replace(/"/g, '&quot;') + '" data-factor="' + uf + '"' +
          (uid === unitId ? ' selected' : '') + '>' + lab + '</option>';
      });
    } else {
      opts = '<option value="' + unitId + '" data-name="' + unitName.replace(/"/g, '&quot;') + '" data-factor="' + factor + '" selected>' +
        (factor > 1 ? unitName + ' × ' + formatFactor(factor) : unitName) + '</option>';
    }
    tr.innerHTML =
      '<td></td>' +
      '<td><select class="input co-unit">' + opts + '</select></td>' +
      '<td><input class="input co-qty" type="number" step="1" min="1" inputmode="numeric" value="1"></td>' +
      '<td><input class="input co-qty-base" type="text" readonly tabindex="-1" dir="ltr" value=""></td>' +
      '<td><button type="button" class="btn btn-secondary btn-sm co-remove-line">حذف</button></td>';
    tr.cells[0].textContent = String(it.name_ar || it.name || 'مادة');
    tbody.appendChild(tr);
    bindRow(tr);
  }

  tbody.querySelectorAll('tr[data-item]').forEach(bindRow);

  var addBtn = document.getElementById('co-add-item');
  if (addBtn) {
    addBtn.onclick = function () {
      if (!window.ItemPickerModal) {
        msg.textContent = 'تعذر فتح اختيار المواد.';
        return;
      }
      ItemPickerModal.open({
        singleSelect: true,
        screenCenter: true,
        buildItemsUrl: buildItemsUrl,
        getWarehouseId: function () { return warehouseId; },
        emptyMessage: warehouseId > 0 ? 'لا توجد مواد في هذا المستودع' : 'لا توجد مواد مطابقة',
        onSelect: function (it) { addLineFromItem(it); },
        onConfirm: function (items) {
          (items || []).forEach(addLineFromItem);
        }
      });
    };
  }

  function doSave() {
    var lines = collectLines();
    if (!lines.length) {
      msg.textContent = 'أدخل بنداً واحداً على الأقل.';
      return;
    }
    post(urls.save, {
      id: id,
      order_date: document.getElementById('order-date').value,
      customer_id: +document.getElementById('customer-id').value,
      warehouse_id: +document.getElementById('warehouse-id').value,
      lines: lines
    }).then(function (x) {
      msg.textContent = x.message || (x.ok ? 'تم الحفظ.' : 'تعذر الحفظ.');
      if (x.ok) location.reload();
    });
  }

  function doApprove() {
    var lines = collectLines();
    if (!lines.length) {
      msg.textContent = 'احفظ بنداً واحداً على الأقل قبل الاعتماد.';
      return;
    }
    post(urls.save, {
      id: id,
      order_date: document.getElementById('order-date').value,
      customer_id: +document.getElementById('customer-id').value,
      warehouse_id: +document.getElementById('warehouse-id').value,
      lines: lines
    }).then(function (x) {
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
