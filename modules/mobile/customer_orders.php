<?php
declare(strict_types=1);
require_once app_path('includes/sal_customer_order.php');
$pdo = db();
sal_customer_order_ensure_schema($pdo);
$listUrl = app_url('api/mobile_customer_order_list.php');
?>
<div class="m-ora12"><div class="m-ora12-workspace"><section class="m-ora12-panel">
    <h2 class="m-ora12-panel__title">طلبات شراء العملاء</h2>
    <div class="m-ora12-panel__body">
        <p class="muted">أنشئ أو عدّل الطلب من تطبيق المندوب. تظهر هنا الكمية والحالة والمندوب.</p>
        <div class="form-row" style="gap:0.5rem;margin-bottom:0.75rem;flex-wrap:wrap;">
            <select id="co-status" class="m-input">
                <option value="">كل الحالات</option>
                <option value="draft">مسودة</option>
                <option value="approved">معتمد</option>
            </select>
            <button type="button" id="co-reload" class="btn btn-secondary btn-sm">تحديث</button>
        </div>
        <div id="customer-orders-list">جاري التحميل…</div>
    </div>
</section></div></div>
<script>
(function () {
  var listUrl = <?= json_encode($listUrl) ?>;
  var el = document.getElementById('customer-orders-list');
  var statusEl = document.getElementById('co-status');
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }
  function load() {
    el.textContent = 'جاري التحميل…';
    var st = statusEl ? statusEl.value : '';
    var url = listUrl + (st ? ('?status=' + encodeURIComponent(st)) : '');
    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (x) {
        if (!x.ok) { el.textContent = 'تعذر تحميل الطلبات.'; return; }
        var orders = x.orders || [];
        if (!orders.length) { el.textContent = 'لا توجد طلبات.'; return; }
        el.innerHTML = '<div class="table-wrap"><table class="data-table"><thead><tr>' +
          '<th>الرقم</th><th>العميل</th><th>المندوب</th><th>البنود</th><th>الكمية</th><th>الحالة</th>' +
          '</tr></thead><tbody>' +
          orders.map(function (o) {
            return '<tr><td><code>' + esc(o.order_no) + '</code></td>' +
              '<td>' + esc(o.customer_name) + '</td>' +
              '<td>' + esc(o.sales_rep_name || '—') + '</td>' +
              '<td>' + esc(o.line_count || 0) + '</td>' +
              '<td dir="ltr">' + esc(o.total_qty || 0) + '</td>' +
              '<td>' + (o.status === 'approved' ? 'معتمد' : 'مسودة') + '</td></tr>';
          }).join('') +
          '</tbody></table></div>';
      })
      .catch(function () { el.textContent = 'تعذر تحميل الطلبات.'; });
  }
  if (statusEl) statusEl.addEventListener('change', load);
  var btn = document.getElementById('co-reload');
  if (btn) btn.addEventListener('click', load);
  load();
})();
</script>
