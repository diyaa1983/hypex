<?php
declare(strict_types=1);

require_permission('report_sales_rep_visits');

require_once app_path('includes/sal_rep_visit.php');

$pdo = db();
sal_rep_visit_ensure_schema($pdo);

$today = date('Y-m-d');
$from = parse_date_to_iso(trim((string) ($_GET['from'] ?? ''))) ?? $today;
$to = parse_date_to_iso(trim((string) ($_GET['to'] ?? ''))) ?? $today;
$salesRepId = (int) ($_GET['sales_rep_id'] ?? 0);
$customerId = (int) ($_GET['customer_id'] ?? 0);
$method = strtoupper(trim((string) ($_GET['method'] ?? '')));
$status = trim((string) ($_GET['status'] ?? ''));

$reps = [];
$customers = [];
try {
    $reps = $pdo->query(
        "SELECT id, code, name_ar FROM crm_sales_rep WHERE is_active = 1 ORDER BY name_ar LIMIT 300"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $stCust = $pdo->prepare(
        'SELECT DISTINCT c.id, c.name_ar
         FROM sal_rep_route_line l
         INNER JOIN sal_rep_route r ON r.id = l.route_id
         INNER JOIN crm_customer c ON c.id = l.customer_id
         WHERE l.visit_checkin_at IS NOT NULL
           AND r.route_date >= ? AND r.route_date <= ?
         ORDER BY c.name_ar
         LIMIT 500'
    );
    $stCust->execute([$from, $to]);
    $customers = $stCust->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $reps = [];
    $customers = [];
}

$rows = sal_rep_visit_report_rows($pdo, [
    'from' => $from,
    'to' => $to,
    'sales_rep_id' => $salesRepId,
    'customer_id' => $customerId,
    'method' => $method,
    'status' => $status,
    'limit' => 1500,
]);

$uniqueRepIds = [];
foreach ($rows as $r) {
    $rid = (int) ($r['sales_rep_id'] ?? 0);
    if ($rid > 0) {
        $uniqueRepIds[$rid] = true;
    }
}
$groupByRep = $salesRepId < 1 && count($uniqueRepIds) > 1;
$colCount = $groupByRep ? 11 : 12;
$grandTotals = sal_rep_visit_report_totals($rows);
$livePoll = $from <= date('Y-m-d') && $to >= date('Y-m-d');

$grouped = [];
if ($groupByRep) {
    foreach ($rows as $r) {
        $rid = (int) ($r['sales_rep_id'] ?? 0);
        $key = $rid > 0 ? (string) $rid : '0';
        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'name' => (string) ($r['sales_rep_name'] ?? 'مندوب'),
                'rows' => [],
            ];
        }
        $grouped[$key]['rows'][] = $r;
    }
}

function sal_rep_visit_report_money(float $n): string
{
    return number_format($n, 2, '.', ',');
}

function sal_rep_visit_report_totals_row_html(string $label, array $totals, int $colCount): string
{
    $labelSpan = max(1, $colCount - 3);

    return '<tr class="report-visits-totals-row">'
        . '<td colspan="' . $labelSpan . '" style="text-align:start"><strong>' . esc($label) . '</strong></td>'
        . '<td class="col-duration"><strong>' . esc((string) ($totals['duration_label'] ?? '—')) . '</strong></td>'
        . '<td class="col-method"></td>'
        . '<td class="col-sales" dir="ltr"><strong>' . esc(sal_rep_visit_report_money((float) ($totals['sales_total'] ?? 0))) . '</strong></td>'
        . '</tr>';
}

function sal_rep_visit_report_data_row_html(array $r, int $seq, bool $includeRep): string
{
    $reason = (string) (($r['no_order_reasons'] ?? '') !== '' ? $r['no_order_reasons'] : '—');
    $rowClass = sal_rep_visit_report_row_class($r);
    $scope = !empty($r['in_plan']) ? 'داخل الجولة' : 'خارج الجولة';
    $sales = sal_rep_visit_report_money((float) ($r['order_total'] ?? 0));

    $html = '<tr class="' . esc($rowClass) . '">';
    $html .= '<td dir="ltr">' . $seq . '</td>';
    $html .= '<td>' . esc(sal_rep_visit_date_with_weekday((string) ($r['route_date'] ?? ''))) . '</td>';
    if ($includeRep) {
        $html .= '<td>' . esc((string) ($r['sales_rep_name'] ?? '')) . '</td>';
    }
    $html .= '<td class="col-customer">' . sal_rep_visit_customer_name_only($r) . '</td>';
    $html .= '<td class="col-scope">' . esc($scope) . '</td>';
    $html .= '<td class="col-reason" title="' . esc($reason) . '">' . esc($reason) . '</td>';
    $html .= '<td class="col-location">' . sal_rep_visit_location_inline($r) . '</td>';
    $html .= '<td class="col-checkin" dir="ltr">' . sal_rep_visit_timing_checkin_cell($r) . '</td>';
    $html .= '<td class="col-checkout" dir="ltr">' . sal_rep_visit_timing_checkout_cell($r) . '</td>';
    $html .= '<td class="col-duration">' . sal_rep_visit_timing_duration_cell($r) . '</td>';
    $html .= '<td class="col-method">' . sal_rep_visit_checkin_method_only_label($r) . '</td>';
    $html .= '<td class="col-sales" dir="ltr">' . esc($sales) . '</td>';
    $html .= '</tr>';

    return $html;
}

$apiUrl = app_url('api/report_sales_rep_visits.php');
$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$repCssPath = app_path('hypex-node/public/css/report-rep-reports.css');
$repCssUrl = app_url('hypex-node/public/css/report-rep-reports.css') . (is_file($repCssPath) ? '?v=' . (string) filemtime($repCssPath) : '');
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($repCssUrl) ?>">
<style>
.report-sales-page.report-visits-page .report-sales-table-wrap {
  overflow-x: auto !important;
}
.report-sales-page.report-visits-page .report-sales-table {
  width: 100% !important;
  min-width: 1100px !important;
  table-layout: auto !important;
  border-collapse: collapse !important;
  font-size: 0.78rem;
}
.report-sales-page.report-visits-page .report-sales-table th,
.report-sales-page.report-visits-page .report-sales-table td {
  white-space: nowrap !important;
  overflow: hidden !important;
  text-overflow: ellipsis !important;
  max-width: 12rem;
  padding: 0.38rem 0.45rem !important;
  text-align: center;
  vertical-align: middle;
  line-height: 1.3;
  border: 1px solid #cbd5e1 !important;
}
.report-sales-page.report-visits-page .col-customer,
.report-sales-page.report-visits-page .col-reason,
.report-sales-page.report-visits-page .col-location {
  text-align: start !important;
}
.report-sales-page.report-visits-page .col-checkin,
.report-sales-page.report-visits-page .col-checkout,
.report-sales-page.report-visits-page .col-duration,
.report-sales-page.report-visits-page .col-method,
.report-sales-page.report-visits-page .col-sales,
.report-sales-page.report-visits-page .col-scope {
  max-width: none !important;
  overflow: visible !important;
  text-overflow: clip !important;
}
.report-sales-page.report-visits-page .si-ts-compact {
  display: inline-block;
  white-space: nowrap !important;
  direction: ltr;
  unicode-bidi: isolate;
  font-variant-numeric: tabular-nums;
}
.report-sales-page.report-visits-page .report-visits-group td {
  background: #e2e8f0;
  text-align: start !important;
  font-weight: 700;
  max-width: none !important;
  overflow: visible !important;
}
.report-sales-page.report-visits-page tr.report-visits-no-order td {
  background: #fef2f2 !important;
}
.report-sales-page.report-visits-page tr.report-visits-totals-row td {
  background: #f1f5f9 !important;
  border-top: 2px solid #94a3b8 !important;
}
@media print {
  @page { size: A4 landscape; margin: 6mm 5mm; }
  .report-sales-page.report-visits-page .report-sales-header { display: none !important; }
  .report-sales-page.report-visits-page .report-sales-table {
    min-width: 0 !important;
    width: 100% !important;
    font-size: 7.5pt !important;
  }
  .report-sales-page.report-visits-page .report-sales-table th,
  .report-sales-page.report-visits-page .report-sales-table td {
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    padding: 0.12rem 0.22rem !important;
    font-size: 7.5pt !important;
    border: 1px solid #94a3b8 !important;
  }
}
</style>
<div class="card report-sales-page report-visits-page">
    <header class="report-sales-header no-print">
        <h2>تقرير زيارات العملاء</h2>
        <p class="muted">تسجيلات دخول/خروج المندوب — وقت الدخول · وقت الخروج · المدة · نوع الدخول · المبيعات</p>
        <p class="muted report-visits-count" id="hx-visits-live-count">
            <strong>عدد الزيارات:</strong> <?= count($rows) ?>
        </p>
        <form method="get" class="report-filters hx-visits-filters" action="<?= esc(app_url('index.php')) ?>">
            <input type="hidden" name="r" value="report_sales_rep_visits">
            <label class="hx-visits-f--date">
                <span class="hx-visits-f-label">من تاريخ</span>
                <span class="hx-date-field">
                    <input type="date" class="input" name="from" id="hx-visits-from" value="<?= esc($from) ?>" dir="ltr">
                    <span class="hx-date-weekday" id="hx-weekday-from"><?= esc(sal_rep_visit_weekday_ar($from)) ?></span>
                </span>
            </label>
            <label class="hx-visits-f--date">
                <span class="hx-visits-f-label">إلى تاريخ</span>
                <span class="hx-date-field">
                    <input type="date" class="input" name="to" id="hx-visits-to" value="<?= esc($to) ?>" dir="ltr">
                    <span class="hx-date-weekday" id="hx-weekday-to"><?= esc(sal_rep_visit_weekday_ar($to)) ?></span>
                </span>
            </label>
            <label>
                <span class="hx-visits-f-label">المندوب</span>
                <select class="input" name="sales_rep_id">
                    <option value="0">— الكل —</option>
                    <?php foreach ($reps as $rep): ?>
                        <option value="<?= (int) $rep['id'] ?>" <?= $salesRepId === (int) $rep['id'] ? 'selected' : '' ?>>
                            <?= esc((string) ($rep['name_ar'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="hx-visits-f--customer">
                <span class="hx-visits-f-label">العميل</span>
                <select class="input" name="customer_id">
                    <option value="0">— الكل —</option>
                    <?php foreach ($customers as $cust): ?>
                        <option value="<?= (int) $cust['id'] ?>" <?= $customerId === (int) $cust['id'] ? 'selected' : '' ?>>
                            <?= esc((string) ($cust['name_ar'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span class="hx-visits-f-label">النوع</span>
                <select class="input" name="method">
                    <option value="" <?= $method === '' ? 'selected' : '' ?>>— الكل —</option>
                    <option value="GPS" <?= $method === 'GPS' ? 'selected' : '' ?>>GPS</option>
                    <option value="MANUAL" <?= $method === 'MANUAL' ? 'selected' : '' ?>>يدوي</option>
                </select>
            </label>
            <label>
                <span class="hx-visits-f-label">الحالة</span>
                <select class="input" name="status">
                    <option value="" <?= $status === '' ? 'selected' : '' ?>>— الكل —</option>
                    <option value="open" <?= $status === 'open' ? 'selected' : '' ?>>داخل الزيارة</option>
                    <option value="closed" <?= $status === 'closed' ? 'selected' : '' ?>>مكتملة</option>
                    <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>بانتظار موافقة</option>
                </select>
            </label>
            <div class="hx-visits-f-actions">
                <button type="submit" class="btn btn-primary">عرض</button>
                <button type="button" class="btn btn-secondary no-print" onclick="window.print()">🖨 طباعة</button>
            </div>
        </form>
        <script>
        (function () {
          var days = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
          function weekday(iso) {
            if (!/^\d{4}-\d{2}-\d{2}$/.test(iso || '')) return '';
            var d = new Date(iso + 'T12:00:00');
            return Number.isNaN(d.getTime()) ? '' : (days[d.getDay()] || '');
          }
          function bind(id, outId) {
            var el = document.getElementById(id);
            var out = document.getElementById(outId);
            if (!el || !out) return;
            var sync = function () { out.textContent = weekday(el.value); };
            el.addEventListener('change', sync);
            el.addEventListener('input', sync);
          }
          bind('hx-visits-from', 'hx-weekday-from');
          bind('hx-visits-to', 'hx-weekday-to');
        })();
        </script>
    </header>

    <div class="report-sales-table-wrap">
        <table class="report-sales-table" dir="rtl" id="hx-visits-table">
            <thead>
            <tr>
                <th>#</th>
                <th>التاريخ</th>
                <?php if (!$groupByRep): ?><th>المندوب</th><?php endif; ?>
                <th>العميل</th>
                <th>النطاق</th>
                <th>سبب عدم الطلب</th>
                <th>الموقع</th>
                <th>وقت الدخول</th>
                <th>وقت الخروج</th>
                <th>مجموع الساعات</th>
                <th>نوع الدخول</th>
                <th>المبيعات</th>
            </tr>
            </thead>
            <tbody id="hx-visits-tbody">
            <?php if ($rows === []): ?>
                <tr><td colspan="<?= (int) $colCount ?>" class="muted">لا تسجيلات زيارة في الفترة المحددة.</td></tr>
            <?php elseif ($groupByRep): ?>
                <?php $seq = 0; ?>
                <?php foreach ($grouped as $g): ?>
                    <tr class="report-visits-group">
                        <td colspan="<?= (int) $colCount ?>">
                            المندوب: <?= esc((string) $g['name']) ?>
                            <span class="muted">(<?= count($g['rows']) ?> زيارة)</span>
                        </td>
                    </tr>
                    <?php foreach ($g['rows'] as $r): ?>
                        <?php $seq++; ?>
                        <?= sal_rep_visit_report_data_row_html($r, $seq, false) ?>
                    <?php endforeach; ?>
                    <?= sal_rep_visit_report_totals_row_html('مجموع المندوب', sal_rep_visit_report_totals($g['rows']), $colCount) ?>
                <?php endforeach; ?>
                <?= sal_rep_visit_report_totals_row_html('الإجمالي النهائي', $grandTotals, $colCount) ?>
            <?php else: ?>
                <?php foreach ($rows as $i => $r): ?>
                    <?= sal_rep_visit_report_data_row_html($r, $i + 1, true) ?>
                <?php endforeach; ?>
                <?= sal_rep_visit_report_totals_row_html('الإجمالي', $grandTotals, $colCount) ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($livePoll): ?>
        <p class="muted no-print" style="margin:.5rem 0;font-size:.82rem">تحديث تلقائي كل 30 ثانية</p>
        <script>
        (function () {
          var apiUrl = <?= json_encode($apiUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
          var q = new URLSearchParams(window.location.search);
          q.delete('r');
          function esc(s) {
            return String(s == null ? '' : s)
              .replace(/&/g, '&amp;')
              .replace(/</g, '&lt;')
              .replace(/>/g, '&gt;')
              .replace(/"/g, '&quot;');
          }
          function money(n) {
            return Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
          }
          function buildRow(r, seq, includeRep) {
            var reason = esc(r.no_order_reasons || '—');
            var cls = r.row_class ? ' class="' + esc(r.row_class) + '"' : '';
            var html = '<tr' + cls + '>';
            html += '<td dir="ltr">' + seq + '</td>';
            html += '<td>' + esc(r.route_date_label || r.route_date || '') + '</td>';
            if (includeRep) html += '<td>' + esc(r.sales_rep_name || '') + '</td>';
            html += '<td class="col-customer">' + esc(r.customer_name || '—') + '</td>';
            html += '<td class="col-scope">' + esc(r.plan_scope_label || (r.in_plan ? 'داخل الجولة' : 'خارج الجولة')) + '</td>';
            html += '<td class="col-reason" title="' + reason + '">' + reason + '</td>';
            html += '<td class="col-location">' + esc(r.location || '—') + '</td>';
            html += '<td class="col-checkin" dir="ltr">' + esc(r.checkin_time || '—') + '</td>';
            html += '<td class="col-checkout" dir="ltr">' + esc(r.checkout_time || '—') + '</td>';
            html += '<td class="col-duration">' + esc(r.duration_label || '—') + '</td>';
            html += '<td class="col-method">' + esc(r.checkin_method_label || '—') + '</td>';
            html += '<td class="col-sales" dir="ltr">' + money(r.order_total) + '</td>';
            html += '</tr>';
            return html;
          }
          function totalsRow(label, t, cols) {
            var labelSpan = Math.max(1, cols - 3);
            return '<tr class="report-visits-totals-row"><td colspan="' + labelSpan + '" style="text-align:start"><strong>'
              + esc(label) + '</strong></td><td class="col-duration"><strong>' + esc(t.duration_label || '—')
              + '</strong></td><td class="col-method"></td><td class="col-sales" dir="ltr"><strong>' + money(t.sales_total) + '</strong></td></tr>';
          }
          function poll() {
            fetch(apiUrl + '?' + q.toString(), { credentials: 'same-origin' })
              .then(function (res) { return res.json(); })
              .then(function (d) {
                if (!d || !d.ok) return;
                var rows = d.rows || [];
                var elCount = document.getElementById('hx-visits-live-count');
                if (elCount) elCount.innerHTML = '<strong>عدد الزيارات:</strong> ' + rows.length;
                var tbody = document.getElementById('hx-visits-tbody');
                if (!tbody || !rows.length) return;
                var repIds = {};
                rows.forEach(function (r) {
                  var id = Number(r.sales_rep_id || 0);
                  if (id > 0) repIds[id] = true;
                });
                var groupByRep = !q.get('sales_rep_id') && Object.keys(repIds).length > 1;
                var colCount = groupByRep ? 11 : 12;
                var html = '';
                if (!rows.length) {
                  html = '<tr><td colspan="' + colCount + '" class="muted">لا تسجيلات زيارة في الفترة المحددة.</td></tr>';
                } else if (groupByRep) {
                  var groups = {};
                  rows.forEach(function (r) {
                    var key = String(Number(r.sales_rep_id || 0) || 0);
                    if (!groups[key]) groups[key] = { name: r.sales_rep_name || 'مندوب', rows: [] };
                    groups[key].rows.push(r);
                  });
                  var seq = 0;
                  Object.keys(groups).forEach(function (key) {
                    var g = groups[key];
                    html += '<tr class="report-visits-group"><td colspan="' + colCount + '">المندوب: '
                      + esc(g.name) + ' <span class="muted">(' + g.rows.length + ' زيارة)</span></td></tr>';
                    g.rows.forEach(function (r) {
                      seq += 1;
                      html += buildRow(r, seq, false);
                    });
                    var gTotals = { duration_label: '—', sales_total: 0 };
                    g.rows.forEach(function (r) { gTotals.sales_total += Number(r.order_total || 0); });
                    html += totalsRow('مجموع المندوب', gTotals, colCount);
                  });
                  html += totalsRow('الإجمالي النهائي', d.totals || {}, colCount);
                } else {
                  rows.forEach(function (r, i) { html += buildRow(r, i + 1, true); });
                  html += totalsRow('الإجمالي', d.totals || {}, colCount);
                }
                tbody.innerHTML = html;
              })
              .catch(function () {});
          }
          setInterval(poll, 30000);
        })();
        </script>
    <?php endif; ?>
</div>
