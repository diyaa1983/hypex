<?php
declare(strict_types=1);

require_once app_path('includes/sal_customer_order.php');
require_once app_path('includes/inv_item_units.php');
require_once app_path('includes/sales_oracle12_ui.php');
require_once app_path('includes/list_pagination.php');
require_once app_path('includes/crm_sales_rep_schema.php');

$pdo = db();
sal_customer_order_ensure_schema($pdo);
inv_item_units_ensure_schema($pdo);
crm_sales_rep_ensure_schema($pdo);

$id = (int) ($_GET['id'] ?? 0);
$q = trim((string) ($_GET['q'] ?? ''));
$salesRepId = isset($_GET['sales_rep_id']) && $_GET['sales_rep_id'] !== '' ? (int) $_GET['sales_rep_id'] : 0;
$customerId = isset($_GET['customer_id']) && $_GET['customer_id'] !== '' ? (int) $_GET['customer_id'] : 0;
$filterRep = $salesRepId > 0 ? $salesRepId : null;
$filterCust = $customerId > 0 ? $customerId : null;

$pager = list_pager_from_request($pdo);
$total = sal_customer_order_list_count($pdo, $q, $filterRep, 'approved', $filterCust);
$pager = list_pager_with_total($pager, $total);
$rows = sal_customer_order_list_fetch(
    $pdo,
    $q,
    $filterRep,
    'approved',
    $filterCust,
    $pager['limit'],
    $pager['offset']
);

$order = $id ? sal_customer_order_fetch($pdo, $id) : null;
if ($order && (string) ($order['status'] ?? '') !== 'approved') {
    redirect(app_url('index.php?r=sales_customer_orders_approve&id=' . (int) $order['id']));
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
$pagerUrl = list_pager_base_url('sales_customer_orders_approved', $filterQuery);

$activeRoute = 'sales_customer_orders_approved';
$canUnapprove = sal_customer_order_user_can_unapprove();
$canOpenApprove = user_can('sales_customer_orders_approve');
sales_ora12_enqueue_assets();
?>
<div class="dashboard-ora sales-ora12-screen">
<?php sales_ora12_render_title_bar('الطلبات المعتمدة', '', $activeRoute); ?>
<?php sales_ora12_workspace_open(); ?>

<div class="sales-ora-panel card">
    <h2>الطلبات المعتمدة</h2>
    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="form-row" style="flex-wrap:wrap;gap:0.5rem;align-items:end;">
        <input type="hidden" name="r" value="sales_customer_orders_approved">
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
        <a class="btn btn-secondary" href="<?= esc(app_url('index.php?r=sales_customer_orders_approved')) ?>">إعادة تعيين</a>
        <?php if ($canOpenApprove): ?>
            <a class="btn btn-secondary" href="<?= esc(app_url('index.php?r=sales_customer_orders_approve')) ?>">اعتماد الطلبات</a>
        <?php endif; ?>
    </form>
</div>

<div class="sales-ora-panel card">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>الرقم</th>
                <th>التاريخ</th>
                <th>العميل</th>
                <th>المندوب</th>
                <th>المستودع</th>
                <th>البنود</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr class="<?= $id === (int) $r['id'] ? 'is-selected' : '' ?>">
                    <td><code><?= esc((string) $r['order_no']) ?></code></td>
                    <td><?= esc(format_date_dmY((string) $r['order_date'])) ?></td>
                    <td><?= esc((string) $r['customer_name']) ?></td>
                    <td><?= esc((string) ($r['sales_rep_name'] ?: '—')) ?></td>
                    <td><?= esc((string) $r['warehouse_name']) ?></td>
                    <td><?= (int) $r['line_count'] ?></td>
                    <td>
                        <a class="btn btn-sm" href="<?= esc(app_url('index.php?r=sales_customer_orders_approved&id=' . (int) $r['id'] . '&' . http_build_query($filterQuery))) ?>">عرض</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="7" class="muted" style="text-align:center;">لا توجد طلبات معتمدة.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php list_pager_render($pager, $pagerUrl); ?>
</div>

<?php if ($order):
    $cssInvPath = app_path('assets/css/sales-invoice.css');
    $cssInv = app_url('assets/css/sales-invoice.css') . (is_file($cssInvPath) ? '?v=' . (string) filemtime($cssInvPath) : '');
    $hdrDisc = trim((string) ($order['invoice_discount_input'] ?? ''));
    $headerDiscAmt = (float) ($order['discount_amount'] ?? 0);
    $showHeaderDisc = $hdrDisc !== '' && $headerDiscAmt > 0.0000001;
    ?>
<link rel="stylesheet" href="<?= esc($cssInv) ?>">
<div class="sales-ora-panel card sales-inv-wrap sales-inv-bold">
    <h3>رقم الطلب: <code><?= esc((string) $order['order_no']) ?></code> — <?= esc((string) $order['customer_name']) ?></h3>
    <p>
        الحالة: معتمد
        | المستودع: <?= esc((string) $order['warehouse_name']) ?>
        | المندوب: <?= esc((string) ($order['sales_rep_name'] ?: '—')) ?>
        | التاريخ: <?= esc(format_date_dmY((string) $order['order_date'])) ?>
        <?php if (!empty($order['approved_by_name'])): ?>
            | اعتمد بواسطة: <?= esc((string) $order['approved_by_name']) ?>
        <?php endif; ?>
        <?php if (!empty($order['approved_at'])): ?>
            | تاريخ الاعتماد: <?= esc(format_date_dmY(substr((string) $order['approved_at'], 0, 10))) ?>
        <?php endif; ?>
    </p>
    <?php if (!empty($order['notes'])): ?>
        <p>ملاحظات: <?= esc((string) $order['notes']) ?></p>
    <?php endif; ?>
    <div class="sales-inv-table-wrap">
        <table class="sales-inv-table">
            <thead>
            <?php
            require_once app_path('includes/inv_invoice_line_table.php');
            inv_invoice_line_table_head(false);
            ?>
            </thead>
            <tbody>
            <?php foreach ($order['lines'] as $i => $line):
                $qty = (float) ($line['qty'] ?? 0);
                $qtyExtra = (float) ($line['qty_extra'] ?? 0);
                $factor = (float) ($line['unit_factor'] ?? 1);
                if ($factor <= 0) {
                    $factor = 1.0;
                }
                $unitName = trim((string) ($line['unit_name'] ?? ''));
                $itemName = trim((string) ($line['item_name'] ?? ''));
                $sku = trim((string) ($line['sku'] ?? $line['barcode'] ?? ''));
                $packHint = $factor > 1.0000001
                    ? ('تعبئة × ' . rtrim(rtrim(number_format($factor, 6, '.', ''), '0'), '.'))
                    : '';
                $discLabel = trim((string) ($line['line_discount_input'] ?? ''));
                if ($discLabel === '' && (float) ($line['discount_amount'] ?? 0) > 0) {
                    $discLabel = format_amount((float) $line['discount_amount']);
                }
                ?>
                <tr>
                    <td class="sales-inv-col-seq"><?= $i + 1 ?></td>
                    <td class="sales-inv-col-sku"><code><?= esc($sku !== '' ? $sku : '—') ?></code></td>
                    <td class="sales-inv-col-item">
                        <?= esc($itemName) ?>
                        <?php if ($packHint !== ''): ?>
                            <span class="sales-inv-pack-hint" dir="ltr"><?= esc($packHint) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="sales-inv-col-unit"><?= esc($unitName !== '' ? $unitName : '—') ?></td>
                    <td class="sales-inv-col-qty" dir="ltr"><?= esc(rtrim(rtrim(number_format($qty, 6, '.', ''), '0'), '.')) ?></td>
                    <td class="sales-inv-col-qty-extra" dir="ltr"><?= esc($qtyExtra > 0 ? rtrim(rtrim(number_format($qtyExtra, 6, '.', ''), '0'), '.') : '—') ?></td>
                    <td class="sales-inv-col-price" dir="ltr"><?= esc(format_amount((float) ($line['unit_price'] ?? 0))) ?></td>
                    <td class="sales-inv-col-discount" dir="ltr"><?= esc($discLabel !== '' ? $discLabel : '—') ?></td>
                    <td class="sales-inv-col-money" dir="ltr"><?= esc(format_amount((float) ($line['line_total'] ?? 0))) ?></td>
                    <td class="sales-inv-col-money" dir="ltr"><?= esc(format_amount((float) ($line['tax_amount'] ?? 0))) ?></td>
                    <td class="sales-inv-col-tax" dir="ltr"><?= esc(rtrim(rtrim(number_format((float) ($line['tax_rate_percent'] ?? 0), 3, '.', ''), '0'), '.') . '%') ?></td>
                    <td class="sales-inv-col-total" dir="ltr"><?= esc(format_amount((float) ($line['line_gross'] ?? 0))) ?></td>
                    <td class="sales-inv-col-del"></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($order['lines'])): ?>
                <tr><td colspan="13" class="muted">لا توجد بنود.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="sales-inv-footer-grid">
        <div></div>
        <div class="sales-inv-totals">
            <?php if ($hdrDisc !== ''): ?>
                <div class="row sales-inv-totals-disc">
                    <span>خصم الطلب (كامل)</span>
                    <span dir="ltr"><?= esc($hdrDisc) ?></span>
                </div>
            <?php endif; ?>
            <?php if ($showHeaderDisc): ?>
                <div class="row sales-inv-totals-header-disc">
                    <span>قيمة خصم مستوى الطلب</span>
                    <span dir="ltr"><?= esc(format_amount($headerDiscAmt)) ?></span>
                </div>
            <?php endif; ?>
            <div class="row"><span>مجموع الخصم</span><span dir="ltr"><?= esc(format_amount((float) ($order['discount_amount'] ?? 0))) ?></span></div>
            <div class="row"><span>المجموع بدون ضريبة</span><span dir="ltr"><?= esc(format_amount((float) ($order['subtotal'] ?? 0))) ?></span></div>
            <div class="row"><span>مجموع الضريبة</span><span dir="ltr"><?= esc(format_amount((float) ($order['tax_amount'] ?? 0))) ?></span></div>
            <div class="row grand"><span>الإجمالي</span><span dir="ltr"><?= esc(format_amount((float) ($order['total'] ?? 0))) ?></span></div>
        </div>
    </div>
    <?php if ($canUnapprove): ?>
        <p id="co-message" class="muted"></p>
        <button type="button" id="co-unapprove" class="btn btn-warn no-print sr-only" aria-hidden="true">فك الاعتماد</button>
        <script>
        (function () {
          var msg = document.getElementById('co-message');
          var btn = document.getElementById('co-unapprove');
          var csrf = <?= json_encode(csrf_token()) ?>;
          var id = <?= (int) $order['id'] ?>;
          function doUnapprove() {
            if (!confirm('فك اعتماد هذا الطلب وإعادته للمسودات؟')) return;
            fetch(<?= json_encode(app_url('api/sales_customer_order_unapprove.php')) ?>, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
              body: JSON.stringify({ id: id })
            }).then(function (r) { return r.json(); }).then(function (x) {
              msg.textContent = x.message || (x.ok ? 'تم فك الاعتماد.' : 'تعذر التنفيذ.');
              if (x.ok) {
                location.href = <?= json_encode(app_url('index.php?r=sales_customer_orders_approve&id=' . (int) $order['id'])) ?>;
              }
            });
          }
          if (btn) btn.onclick = doUnapprove;
          window.CoUnapproveToolbar = { unapprove: doUnapprove };
        })();
        </script>
    <?php endif; ?>
</div>
<?php endif; ?>
<script>
document.addEventListener('master-toolbar', function (e) {
  if (!e.detail || e.detail.action !== 'unapprove') return;
  e.preventDefault();
  e.stopImmediatePropagation();
  var api = window.CoUnapproveToolbar;
  if (!api) {
    if (window.AppDialog && AppDialog.alert) {
      AppDialog.alert('افتح طلباً معتمداً أولاً.', { type: 'warning' });
    } else {
      alert('افتح طلباً معتمداً أولاً.');
    }
    return;
  }
  api.unapprove();
});
</script>
<?php sales_ora12_workspace_close(); ?>
</div>
