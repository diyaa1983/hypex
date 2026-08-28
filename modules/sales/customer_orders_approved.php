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
    <?php
    $oraVnum = (int) ($order['oracle_v_num'] ?? 0);
    $oraVyear = (int) ($order['oracle_vyear'] ?? 0);
    ?>
    <div class="form-row no-print" style="margin:.75rem 0;gap:.5rem;flex-wrap:wrap;align-items:center">
        <?php if ($oraVnum > 0): ?>
            <span class="muted">مرحّل إلى Oracle: فاتورة <strong dir="ltr"><?= (int) $oraVnum ?> / <?= (int) $oraVyear ?></strong></span>
            <a class="btn btn-secondary" href="<?= esc(app_url('index.php?r=report_oracle_sales_invoice&invoice_no=' . $oraVnum . '&year=' . $oraVyear)) ?>">عرض فاتورة Oracle</a>
            <?php if (sal_customer_order_user_can_approve()): ?>
            <button type="button" id="co-oracle-unpost" class="btn btn-warn">حذف مسودة Oracle</button>
            <?php endif; ?>
        <?php elseif (sal_customer_order_user_can_approve()): ?>
            <button type="button" id="co-oracle" class="btn btn-primary">ترحيل إلى Oracle</button>
        <?php endif; ?>
        <span id="co-oracle-msg" class="muted"></span>
    </div>
    <?php if (sal_customer_order_user_can_approve() && $oraVnum < 1): ?>
    <div id="co-batch-modal" class="co-batch-modal" hidden aria-hidden="true">
        <div class="co-batch-panel" role="dialog" aria-labelledby="co-batch-title">
            <div class="co-batch-head">
                <h3 id="co-batch-title">توزيع التشغيلات — ترحيل إلى Oracle</h3>
                <p id="co-batch-sub" class="muted"></p>
            </div>
            <div class="co-batch-body-wrap">
                <table class="co-batch-table co-batch-table--alloc">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>المادة</th>
                        <th>المطلوب</th>
                        <th>الكمية من التشغيلة</th>
                        <th>التشغيلة</th>
                        <th>رصيد التشغيلة</th>
                    </tr>
                    </thead>
                    <tbody id="co-batch-rows"></tbody>
                </table>
            </div>
            <div class="co-batch-foot">
                <button type="button" id="co-batch-cancel" class="btn btn-secondary">إلغاء</button>
                <button type="button" id="co-batch-confirm" class="btn btn-primary">تأكيد وترحيل إلى Oracle</button>
            </div>
        </div>
    </div>
    <style>
    .co-batch-modal { position:fixed; inset:0; z-index:10050; background:rgba(15,23,42,.45); display:flex; align-items:center; justify-content:center; padding:1rem; }
    .co-batch-modal[hidden] { display:none !important; }
    .co-batch-panel { background:#fff; border-radius:10px; max-width:960px; width:100%; max-height:90vh; display:flex; flex-direction:column; box-shadow:0 12px 40px rgba(0,0,0,.18); }
    .co-batch-head { padding:1rem 1.25rem .5rem; border-bottom:1px solid #e8ecf1; }
    .co-batch-head h3 { margin:0 0 .35rem; font-size:1.1rem; }
    .co-batch-body-wrap { padding:.75rem 1.25rem; overflow:auto; flex:1; }
    .co-batch-table { width:100%; border-collapse:collapse; font-size:.92rem; }
    .co-batch-table th, .co-batch-table td { padding:.5rem .4rem; border-bottom:1px solid #eef1f5; text-align:right; vertical-align:middle; }
    .co-batch-col-need, .co-batch-col-take, .co-batch-col-bal, .co-batch-col-idx { text-align:center; direction:ltr; font-variant-numeric:tabular-nums; font-weight:700; }
    .co-batch-item-code { display:block; font-weight:700; direction:ltr; }
    .co-batch-item-name { display:block; color:#475569; font-size:.86rem; margin-top:.15rem; }
    .co-batch-item-cont { color:#64748b; font-size:.84rem; }
    .co-batch-foot { padding:.75rem 1.25rem; border-top:1px solid #e8ecf1; display:flex; gap:.5rem; justify-content:flex-end; }
    .co-batch-warn { color:#b45309; font-size:.85rem; margin-top:.25rem; font-weight:700; }
    .co-batch-row--nostock td { background:#fff7ed !important; }
    .co-batch-row--invalid td { background:#fef2f2 !important; }
    .co-batch-table select { width:100%; min-width:10rem; padding:.35rem .5rem; }
    </style>
    <?php endif; ?>
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
    <?php if ($canUnapprove || sal_customer_order_user_can_approve()): ?>
        <p id="co-message" class="muted"></p>
        <?php if ($canUnapprove): ?>
        <button type="button" id="co-unapprove" class="btn btn-warn no-print sr-only" aria-hidden="true">فك الاعتماد</button>
        <?php endif; ?>
        <script>
        (function () {
          var msg = document.getElementById('co-message');
          var oraMsg = document.getElementById('co-oracle-msg');
          var btn = document.getElementById('co-unapprove');
          var oraBtn = document.getElementById('co-oracle');
          var oraUnpostBtn = document.getElementById('co-oracle-unpost');
          var unpostApi = <?= json_encode(app_url('api/sales_customer_order_unpost_oracle.php')) ?>;
          var csrf = <?= json_encode(csrf_token()) ?>;
          var id = <?= (int) $order['id'] ?>;
          function doUnapprove() {
            if (!confirm('فك اعتماد هذا الطلب وإعادته للمسودات؟')) return;
            fetch(<?= json_encode(app_url('api/sales_customer_order_unapprove.php')) ?>, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
              body: JSON.stringify({ id: id })
            }).then(function (r) { return r.json(); }).then(function (x) {
              if (msg) msg.textContent = x.message || (x.ok ? 'تم فك الاعتماد.' : 'تعذر التنفيذ.');
              if (x.ok) {
                location.href = <?= json_encode(app_url('index.php?r=sales_customer_orders_approve&id=' . (int) $order['id'])) ?>;
              }
            });
          }
          var batchesApi = <?= json_encode(app_url('api/sales_customer_order_oracle_batches.php')) ?>;
          var postApi = <?= json_encode(app_url('api/sales_customer_order_post_oracle.php')) ?>;
          var batchModal = document.getElementById('co-batch-modal');
          var batchRows = document.getElementById('co-batch-rows');
          var batchSub = document.getElementById('co-batch-sub');
          var batchCancel = document.getElementById('co-batch-cancel');
          var batchConfirm = document.getElementById('co-batch-confirm');
          var pickerData = null;

          function fmtQty(n) {
            n = Number(n) || 0;
            return String(n).indexOf('.') >= 0 ? n.toFixed(3).replace(/\.?0+$/, '') : String(n);
          }

          function closeBatchModal() {
            if (batchModal) {
              batchModal.hidden = true;
              batchModal.setAttribute('aria-hidden', 'true');
            }
            pickerData = null;
          }

          function batchOptionLabel(b) {
            return (b.batch || '?') + ' — رصيد ' + fmtQty(b.qty) + (b.exp_date ? (' — ' + b.exp_date) : '');
          }

          function findBatchMeta(batches, batchId) {
            batchId = String(batchId || '').trim();
            for (var i = 0; i < (batches || []).length; i++) {
              if (String(batches[i].batch || '') === batchId) return batches[i];
            }
            return null;
          }

          function buildBatchSelect(ln, alloc, batches) {
            var sel = document.createElement('select');
            sel.className = 'co-batch-select';
            sel.style.width = '100%';
            sel.dataset.srl = String(ln.srl || '');
            sel.dataset.item = String(ln.item || '');
            sel.dataset.take = String(alloc.take || '');
            var opt0 = document.createElement('option');
            opt0.value = '';
            opt0.textContent = '— اختر التشغيلة —';
            sel.appendChild(opt0);
            (batches || []).forEach(function (b) {
              var o = document.createElement('option');
              o.value = b.batch || '';
              o.textContent = batchOptionLabel(b);
              sel.appendChild(o);
            });
            var cur = String(alloc.batch || '');
            if (cur) {
              sel.value = cur;
              if (sel.value !== cur) {
                var extra = document.createElement('option');
                extra.value = cur;
                extra.textContent = cur + ' (غير موجودة)';
                sel.appendChild(extra);
                sel.value = cur;
              }
            }
            return sel;
          }

          function validateBatchModal() {
            if (!batchRows || !batchConfirm) return true;
            var ok = true;
            var usageBySrl = {};
            batchRows.querySelectorAll('tr[data-alloc-row="1"]').forEach(function (tr) {
              var sel = tr.querySelector('select');
              if (!sel) return;
              var batch = (sel.value || '').trim();
              var take = Number(sel.dataset.take) || 0;
              var srl = String(sel.dataset.srl || '');
              if (!batch || !srl) return;
              if (!usageBySrl[srl]) usageBySrl[srl] = {};
              usageBySrl[srl][batch] = (usageBySrl[srl][batch] || 0) + take;
            });
            batchRows.querySelectorAll('tr[data-alloc-row="1"]').forEach(function (tr) {
              var sel = tr.querySelector('select');
              var balEl = tr.querySelector('.co-batch-col-bal');
              if (!sel) return;
              var batch = (sel.value || '').trim();
              var take = Number(sel.dataset.take) || 0;
              var srl = String(sel.dataset.srl || '');
              var batches = [];
              try { batches = JSON.parse(tr.dataset.batches || '[]'); } catch (e) { batches = []; }
              tr.classList.remove('co-batch-row--invalid');
              if (!batch) {
                ok = false;
                tr.classList.add('co-batch-row--invalid');
                if (balEl) balEl.textContent = '—';
                return;
              }
              var meta = findBatchMeta(batches, batch);
              var usedOnBatch = (usageBySrl[srl] && usageBySrl[srl][batch]) || take;
              if (balEl) balEl.textContent = meta ? fmtQty(meta.qty) : '—';
              if (!meta || Number(meta.qty) < usedOnBatch - 0.0001 || Number(meta.qty) < take - 0.0001) {
                ok = false;
                tr.classList.add('co-batch-row--invalid');
              }
            });
            batchConfirm.disabled = !ok;
            return ok;
          }

          function collectBatchAllocations() {
            var picks = [];
            if (!batchRows) return picks;
            batchRows.querySelectorAll('tr[data-alloc-row="1"]').forEach(function (tr) {
              var sel = tr.querySelector('select');
              if (!sel) return;
              var batch = (sel.value || '').trim();
              if (!batch) return;
              picks.push({
                srl: parseInt(sel.dataset.srl || '0', 10),
                item: sel.dataset.item || '',
                batch: batch,
                take: Number(sel.dataset.take) || 0
              });
            });
            return picks;
          }

          function openBatchModal(data) {
            pickerData = data;
            if (!batchRows || !batchModal) return;
            batchRows.innerHTML = '';
            if (batchSub) {
              batchSub.textContent = 'مستودع Oracle: ' + (data.store || '—')
                + (data.warehouse_name ? (' — ' + data.warehouse_name) : '')
                + ' · التشغيلات من STOCK (نفس Oracle) · توزيع تلقائي على أكثر من تشغيلة عند الحاجة';
            }
            var rowNo = 0;
            (data.lines || []).forEach(function (ln) {
              var allocs = Array.isArray(ln.allocations) ? ln.allocations : [];
              var batches = Array.isArray(ln.batches) ? ln.batches : [];
              if (!allocs.length) {
                rowNo += 1;
                var tr0 = document.createElement('tr');
                tr0.className = 'co-batch-row--nostock';
                tr0.innerHTML = '<td class="co-batch-col-idx">' + rowNo + '</td>'
                  + '<td><strong class="co-batch-item-code" dir="ltr">' + (ln.item || '') + '</strong>'
                  + '<span class="co-batch-item-name">' + (ln.name || '') + '</span></td>'
                  + '<td class="co-batch-col-need">' + fmtQty(ln.need) + '</td>'
                  + '<td class="co-batch-col-take">—</td>'
                  + '<td colspan="2"><div class="co-batch-warn">لا يكفي الرصيد أو لا توجد تشغيلات'
                  + (Number(ln.shortfall) > 0 ? (' (نقص ' + fmtQty(ln.shortfall) + ')') : '')
                  + '.</div></td>';
                batchRows.appendChild(tr0);
                return;
              }
              allocs.forEach(function (a, ai) {
                rowNo += 1;
                var tr = document.createElement('tr');
                tr.setAttribute('data-alloc-row', '1');
                tr.dataset.batches = JSON.stringify(batches);
                var tdIdx = document.createElement('td');
                tdIdx.className = 'co-batch-col-idx';
                tdIdx.textContent = String(rowNo);
                var tdName = document.createElement('td');
                if (ai === 0) {
                  tdName.innerHTML = '<strong class="co-batch-item-code" dir="ltr">' + (ln.item || '') + '</strong>'
                    + '<span class="co-batch-item-name">' + (ln.name || '') + '</span>';
                } else {
                  tdName.innerHTML = '<span class="co-batch-item-cont">↳ استمرار نفس المادة</span>';
                }
                var tdNeed = document.createElement('td');
                tdNeed.className = 'co-batch-col-need';
                tdNeed.textContent = ai === 0 ? fmtQty(ln.need) : '';
                var tdTake = document.createElement('td');
                tdTake.className = 'co-batch-col-take';
                tdTake.textContent = fmtQty(a.take);
                var tdBatch = document.createElement('td');
                tdBatch.className = 'co-batch-col-batch';
                var sel = buildBatchSelect(ln, a, batches);
                sel.addEventListener('change', validateBatchModal);
                tdBatch.appendChild(sel);
                var tdBal = document.createElement('td');
                tdBal.className = 'co-batch-col-bal';
                var meta = findBatchMeta(batches, a.batch);
                tdBal.textContent = meta ? fmtQty(meta.qty) : fmtQty(a.batch_qty);
                tr.appendChild(tdIdx);
                tr.appendChild(tdName);
                tr.appendChild(tdNeed);
                tr.appendChild(tdTake);
                tr.appendChild(tdBatch);
                tr.appendChild(tdBal);
                batchRows.appendChild(tr);
              });
              if (!ln.allocation_ok && Number(ln.shortfall) > 0) {
                var trW = document.createElement('tr');
                trW.className = 'co-batch-row--nostock';
                trW.innerHTML = '<td></td><td colspan="5"><div class="co-batch-warn">الرصيد التلقائي لا يكفي — نقص '
                  + fmtQty(ln.shortfall) + ' (عدّل التشغيلات يدوياً إن أمكن).</div></td>';
                batchRows.appendChild(trW);
              }
            });
            validateBatchModal();
            batchModal.hidden = false;
            batchModal.setAttribute('aria-hidden', 'false');
          }

          function postOracle(batchPicks) {
            if (oraMsg) oraMsg.textContent = 'جاري الترحيل…';
            fetch(postApi, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
              body: JSON.stringify({ id: id, batch_picks: batchPicks || [] })
            }).then(function (r) { return r.json(); }).then(function (x) {
              var text = x.message || x.error || (x.ok ? 'تم الترحيل.' : 'تعذر الترحيل.');
              if (oraMsg) oraMsg.textContent = text;
              if (x.ok) {
                location.reload();
                return;
              }
              var items = Array.isArray(x.items) ? x.items.filter(Boolean) : [];
              var stockIssues = Array.isArray(x.stock_issues) ? x.stock_issues : [];
              if (stockIssues.length || String(text).indexOf('رصيد Oracle') >= 0) {
                var body = stockIssues.length
                  ? stockIssues.map(function (iss) { return iss._line || iss.item || ''; }).join('\n\n')
                  : text;
                if (window.HypexUI && window.HypexUI.dialog) {
                  window.HypexUI.dialog({ title: 'تعذر الترحيل إلى Oracle', message: body, kind: 'error', buttons: [{ label: 'حسناً', value: true, primary: true }] });
                } else {
                  alert(body);
                }
                return;
              }
              var isUndef = x.code === 'item_undefined' || items.length > 0
                || String(text).indexOf('المادة غير معرفة على النظام') === 0;
              if (isUndef) {
                var bodyU = items.length ? items.join('\n') : String(text).replace(/^المادة غير معرفة على النظام\s*/, '').trim();
                if (window.HypexUI && window.HypexUI.dialog) {
                  window.HypexUI.dialog({ title: 'المادة غير معرفة على النظام', message: bodyU, kind: 'error', buttons: [{ label: 'حسناً', value: true, primary: true }] });
                } else {
                  alert(bodyU ? ('المادة غير معرفة على النظام\n' + bodyU) : 'المادة غير معرفة على النظام');
                }
              }
            }).catch(function () {
              if (oraMsg) oraMsg.textContent = 'تعذر الاتصال.';
            });
          }

          function doOracle() {
            if (oraMsg) oraMsg.textContent = 'جاري جلب التشغيلات من Oracle…';
            fetch(batchesApi, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
              body: JSON.stringify({ id: id })
            }).then(function (r) { return r.json(); }).then(function (x) {
              if (!x.ok) {
                if (oraMsg) oraMsg.textContent = x.message || 'تعذر جلب التشغيلات.';
                return;
              }
              if (oraMsg) oraMsg.textContent = '';
              openBatchModal(x);
            }).catch(function () {
              if (oraMsg) oraMsg.textContent = 'تعذر الاتصال.';
            });
          }

          if (batchCancel) batchCancel.onclick = closeBatchModal;
          if (batchConfirm) batchConfirm.onclick = function () {
            if (!pickerData || !pickerData.lines) return;
            if (!validateBatchModal()) {
              alert('تحقق من اختيار التشغيلة — يجب أن يكفي رصيد كل تشغيلة للكمية المخصصة.');
              return;
            }
            var picks = collectBatchAllocations();
            var expected = batchRows ? batchRows.querySelectorAll('tr[data-alloc-row="1"]').length : 0;
            if (picks.length < expected) {
              alert('اختر تشغيلة لكل سطر قبل الترحيل.');
              return;
            }
            if (!confirm('سيتم ترحيل الطلب إلى Oracle بالتشغيلات المعروضة (بعد أي تعديل).\nهل تريد التأكيد؟')) {
              return;
            }
            closeBatchModal();
            postOracle(picks);
          };
          if (batchModal) {
            batchModal.addEventListener('click', function (e) {
              if (e.target === batchModal) closeBatchModal();
            });
          }
          if (btn) btn.onclick = doUnapprove;
          if (oraBtn) oraBtn.onclick = doOracle;
          if (oraUnpostBtn) oraUnpostBtn.onclick = function () {
            if (!confirm('حذف مسودة Oracle من قاعدة البيانات؟\n(فقط إن لم تُحفظ الفاتورة في INV00024)')) return;
            if (oraMsg) oraMsg.textContent = 'جاري الحذف…';
            fetch(unpostApi, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
              body: JSON.stringify({ id: id })
            }).then(function (r) { return r.json(); }).then(function (x) {
              if (oraMsg) oraMsg.textContent = x.message || (x.ok ? 'تم الحذف.' : 'تعذر الحذف.');
              if (x.ok) location.reload();
            }).catch(function () {
              if (oraMsg) oraMsg.textContent = 'تعذر الاتصال.';
            });
          };
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
