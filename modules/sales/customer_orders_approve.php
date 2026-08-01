<?php
declare(strict_types=1);
require_once app_path('includes/sal_customer_order.php');
require_once app_path('includes/inv_item_units.php');
require_once app_path('includes/sales_oracle12_ui.php');
$pdo = db();
sal_customer_order_ensure_schema($pdo);
inv_item_units_ensure_schema($pdo);
$id = (int) ($_GET['id'] ?? 0);
$order = $id ? sal_customer_order_fetch($pdo, $id) : null;
$rows = sal_customer_order_list_fetch($pdo, '', null, (string) ($_GET['status'] ?? '') ?: null);
sales_ora12_enqueue_assets();
?>
<div class="dashboard-ora sales-ora12-screen"><div class="sales-ora12-workspace">
<div class="sales-ora-panel card"><h2>اعتماد طلبات الشراء</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>الرقم</th><th>العميل</th><th>التاريخ</th><th>الحالة</th><th></th></tr></thead><tbody><?php foreach ($rows as $r): ?><tr><td><?= esc((string) $r['order_no']) ?></td><td><?= esc((string) $r['customer_name']) ?></td><td><?= esc(format_date_dmY((string) $r['order_date'])) ?></td><td><?= esc(sal_customer_order_status_label((string) $r['status'])) ?></td><td><a class="btn btn-sm" href="<?= esc(app_url('index.php?r=sales_customer_orders_approve&id=' . (int) $r['id'])) ?>">فتح</a></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php if ($order): ?>
<div class="sales-ora-panel card">
    <h3><?= esc((string) $order['order_no']) ?> — <?= esc((string) $order['customer_name']) ?></h3>
    <p>المستودع: <?= esc((string) $order['warehouse_name']) ?> | المندوب: <?= esc((string) $order['sales_rep_name']) ?></p>
    <form id="customer-order-form">
        <input type="hidden" id="order-id" value="<?= (int) $order['id'] ?>">
        <input type="hidden" id="order-date" value="<?= esc((string) $order['order_date']) ?>">
        <input type="hidden" id="customer-id" value="<?= (int) $order['customer_id'] ?>">
        <input type="hidden" id="warehouse-id" value="<?= (int) $order['warehouse_id'] ?>">
        <table class="data-table">
            <thead><tr><th>الصنف</th><th>الوحدة</th><th>الكمية</th></tr></thead>
            <tbody>
            <?php foreach ($order['lines'] as $line):
                $itemUnits = inv_item_units_for_item($pdo, (int) $line['item_id']);
                $qtyInt = (int) round((float) ($line['qty'] ?? 0));
                $curUnitId = (int) ($line['unit_id'] ?? 0);
                ?>
                <tr data-item="<?= (int) $line['item_id'] ?>" data-item-name="<?= esc((string) $line['item_name']) ?>">
                    <td><?= esc((string) $line['item_name']) ?></td>
                    <td>
                        <?php if ($order['status'] === 'approved'): ?>
                            <?= esc((string) ($line['unit_name'] ?? '')) ?>
                            <input type="hidden" class="co-unit-id" value="<?= $curUnitId ?>">
                            <input type="hidden" class="co-unit-name" value="<?= esc((string) ($line['unit_name'] ?? '')) ?>">
                        <?php else: ?>
                            <select class="input co-unit">
                                <?php foreach ($itemUnits as $u): ?>
                                    <option value="<?= (int) $u['unit_id'] ?>"
                                            data-name="<?= esc((string) $u['name']) ?>"
                                            <?= $curUnitId === (int) $u['unit_id'] ? 'selected' : '' ?>>
                                        <?= esc((string) $u['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                                <?php if ($itemUnits === []): ?>
                                    <option value="<?= $curUnitId ?>" data-name="<?= esc((string) ($line['unit_name'] ?? '')) ?>" selected><?= esc((string) ($line['unit_name'] ?? '—')) ?></option>
                                <?php endif; ?>
                            </select>
                        <?php endif; ?>
                    </td>
                    <td>
                        <input class="input co-qty" type="number" step="1" min="1" inputmode="numeric"
                               value="<?= $qtyInt ?>" <?= $order['status'] === 'approved' ? 'disabled' : '' ?>>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p id="co-message" class="muted"></p>
        <?php if ($order['status'] === 'draft'): ?>
            <button type="button" id="co-save" class="btn btn-primary">حفظ التعديلات</button>
            <button type="button" id="co-approve" class="btn btn-success">اعتماد</button>
        <?php else: ?>
            <button type="button" id="co-unapprove" class="btn btn-warn">فك الاعتماد</button>
        <?php endif; ?>
    </form>
<script>
(function () {
  var id = +document.getElementById('order-id').value;
  var msg = document.getElementById('co-message');
  var csrf = <?= json_encode(csrf_token()) ?>;
  function post(url, data) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(data)
    }).then(function (r) { return r.json(); }).then(function (x) {
      msg.textContent = x.message || (x.ok ? 'تم التنفيذ.' : 'تعذر التنفيذ.');
      if (x.ok) location.reload();
    });
  }
  var save = document.getElementById('co-save');
  if (save) save.onclick = function () {
    var lines = [];
    document.querySelectorAll('tr[data-item]').forEach(function (r) {
      var unitSel = r.querySelector('.co-unit');
      var unitId = 0, unitName = '';
      if (unitSel && unitSel.selectedIndex >= 0) {
        unitId = +unitSel.value || 0;
        unitName = unitSel.options[unitSel.selectedIndex].getAttribute('data-name') || unitSel.options[unitSel.selectedIndex].textContent.trim();
      } else {
        unitId = +(r.querySelector('.co-unit-id') || {}).value || 0;
        unitName = (r.querySelector('.co-unit-name') || {}).value || '';
      }
      lines.push({
        item_id: +r.dataset.item,
        item_name: r.dataset.itemName || r.cells[0].textContent.trim(),
        unit_id: unitId,
        unit_name: unitName,
        qty: parseInt(r.querySelector('.co-qty').value, 10) || 0
      });
    });
    post(<?= json_encode(app_url('api/sales_customer_order_save.php')) ?>, {
      id: id,
      order_date: document.getElementById('order-date').value,
      customer_id: +document.getElementById('customer-id').value,
      warehouse_id: +document.getElementById('warehouse-id').value,
      lines: lines
    });
  };
  var a = document.getElementById('co-approve');
  if (a) a.onclick = function () { post(<?= json_encode(app_url('api/sales_customer_order_approve.php')) ?>, { id: id }); };
  var u = document.getElementById('co-unapprove');
  if (u) u.onclick = function () { post(<?= json_encode(app_url('api/sales_customer_order_unapprove.php')) ?>, { id: id }); };
})();
</script>
</div>
<?php endif; ?>
</div></div>
