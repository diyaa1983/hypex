<?php
declare(strict_types=1);

require_permission('report_sales_rep_visits');

require_once app_path('includes/sal_rep_visit.php');

$pdo = db();
sal_rep_visit_ensure_schema($pdo);

$today = date('Y-m-d');
$from = parse_date_to_iso(trim((string) ($_GET['from'] ?? ''))) ?? $today;
$to = parse_date_to_iso(trim((string) ($_GET['to'] ?? ''))) ?? $today;
$salesRepIds = [];
$rawRep = $_GET['sales_rep_id'] ?? [];
if (!is_array($rawRep)) {
    $rawRep = [$rawRep];
}
foreach ($rawRep as $ridRaw) {
    foreach (explode(',', (string) $ridRaw) as $part) {
        $rid = (int) trim($part);
        if ($rid > 0) {
            $salesRepIds[$rid] = $rid;
        }
    }
}
$salesRepIds = array_values($salesRepIds);
$salesRepId = count($salesRepIds) === 1 ? $salesRepIds[0] : 0;
$customerIds = [];
$rawCust = $_GET['customer_id'] ?? [];
if (!is_array($rawCust)) {
    $rawCust = [$rawCust];
}
foreach ($rawCust as $cidRaw) {
    foreach (explode(',', (string) $cidRaw) as $part) {
        $cid = (int) trim($part);
        if ($cid > 0) {
            $customerIds[$cid] = $cid;
        }
    }
}
$customerIds = array_values($customerIds);
$customerId = count($customerIds) === 1 ? $customerIds[0] : 0;
$method = strtoupper(trim((string) ($_GET['method'] ?? '')));
$status = trim((string) ($_GET['status'] ?? ''));

$reps = [];
$customers = [];
try {
    $reps = $pdo->query(
        "SELECT id, code, name_ar FROM crm_sales_rep WHERE is_active = 1 ORDER BY name_ar LIMIT 300"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $stCust = $pdo->prepare(
        'SELECT DISTINCT c.id, c.name_ar, COALESCE(c.code, \'\') AS code
         FROM sal_rep_route_line l
         INNER JOIN sal_rep_route r ON r.id = l.route_id
         INNER JOIN crm_customer c ON c.id = l.customer_id
         WHERE l.visit_checkin_at IS NOT NULL
           AND r.route_date >= ? AND r.route_date <= ?
         ORDER BY c.name_ar
         LIMIT 800'
    );
    $stCust->execute([$from, $to]);
    $customers = $stCust->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $reps = [];
    $customers = [];
}

$rowsForPicker = sal_rep_visit_report_rows($pdo, [
    'from' => $from,
    'to' => $to,
    'sales_rep_ids' => $salesRepIds,
    'method' => $method,
    'status' => $status,
    'limit' => 1500,
]);
$rows = $customerIds !== []
    ? sal_rep_visit_report_rows($pdo, [
        'from' => $from,
        'to' => $to,
        'sales_rep_ids' => $salesRepIds,
        'customer_ids' => $customerIds,
        'method' => $method,
        'status' => $status,
        'limit' => 1500,
    ])
    : $rowsForPicker;

$uniqueRepIds = [];
foreach ($rows as $r) {
    $rid = (int) ($r['sales_rep_id'] ?? 0);
    if ($rid > 0) {
        $uniqueRepIds[$rid] = true;
    }
}
$groupByRep = count($salesRepIds) !== 1 && count($uniqueRepIds) > 1;
$colCount = $groupByRep ? 12 : 13;
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
    $labelSpan = max(1, $colCount - 4);

    return '<tr class="report-visits-totals-row">'
        . '<td colspan="' . $labelSpan . '" style="text-align:start"><strong>' . esc($label) . '</strong></td>'
        . '<td class="col-duration"><strong>' . esc((string) ($totals['duration_label'] ?? '—')) . '</strong></td>'
        . '<td class="col-method"></td>'
        . '<td class="col-checkout-method"></td>'
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
    $custId = (int) ($r['customer_id'] ?? 0);
    $html .= '<td class="col-customer si-col-customer" data-customer-id="' . $custId . '"'
        . ' data-customer-name="' . esc((string) ($r['customer_name'] ?? '')) . '"'
        . ' data-customer-code="' . esc((string) ($r['customer_code'] ?? '')) . '">'
        . sal_rep_visit_customer_name_only($r) . '</td>';
    $html .= '<td class="col-scope">' . esc($scope) . '</td>';
    $html .= '<td class="col-reason" title="' . esc($reason) . '">' . esc($reason) . '</td>';
    $html .= '<td class="col-location">' . sal_rep_visit_location_inline($r) . '</td>';
    $html .= '<td class="col-checkin" dir="ltr">' . sal_rep_visit_timing_checkin_cell($r) . '</td>';
    $html .= '<td class="col-checkout" dir="ltr">' . sal_rep_visit_timing_checkout_cell($r) . '</td>';
    $html .= '<td class="col-duration">' . sal_rep_visit_timing_duration_cell($r) . '</td>';
    $html .= '<td class="col-method">' . sal_rep_visit_checkin_method_only_label($r) . '</td>';
    $html .= '<td class="col-checkout-method">' . sal_rep_visit_checkout_method_only_label($r) . '</td>';
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
.report-sales-page.report-visits-page .col-checkout-method,
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
        <p class="muted">تسجيلات دخول/خروج المندوب — وقت الدخول · وقت الخروج · المدة · نوع الدخول · نوع الخروج · المبيعات</p>
        <p class="muted report-visits-count" id="hx-visits-live-count">
            <strong>عدد الزيارات:</strong> <?= count($rows) ?>
        </p>
        <form method="get" class="report-filters hx-visits-filters" action="<?= esc(app_url('index.php')) ?>">
            <input type="hidden" name="r" value="report_sales_rep_visits">
            <label class="hx-visits-f hx-visits-f--from">
                <span class="hx-visits-f-label">من تاريخ <span class="hx-date-weekday" id="hx-weekday-from"><?= esc(sal_rep_visit_weekday_ar($from)) ?></span></span>
                <span class="hx-date-field">
                    <input type="date" class="input" name="from" id="hx-visits-from" value="<?= esc($from) ?>" dir="ltr">
                </span>
            </label>
            <label class="hx-visits-f hx-visits-f--to">
                <span class="hx-visits-f-label">إلى تاريخ <span class="hx-date-weekday" id="hx-weekday-to"><?= esc(sal_rep_visit_weekday_ar($to)) ?></span></span>
                <span class="hx-date-field">
                    <input type="date" class="input" name="to" id="hx-visits-to" value="<?= esc($to) ?>" dir="ltr">
                </span>
            </label>
            <label class="hx-visits-f hx-visits-f--method">
                <span class="hx-visits-f-label">النوع</span>
                <select class="input" name="method">
                    <option value="" <?= $method === '' ? 'selected' : '' ?>>— الكل —</option>
                    <option value="GPS" <?= $method === 'GPS' ? 'selected' : '' ?>>GPS</option>
                    <option value="MANUAL" <?= $method === 'MANUAL' ? 'selected' : '' ?>>يدوي</option>
                </select>
            </label>
            <?php
            $selectedRepCount = count($salesRepIds);
            $selectedRepSet = array_fill_keys($salesRepIds, true);
            if ($selectedRepCount < 1) {
                $repToggleLabel = '— الكل —';
            } elseif ($selectedRepCount === 1) {
                $repToggleLabel = 'مندوب واحد';
                foreach ($reps as $rep) {
                    if ((int) ($rep['id'] ?? 0) === $salesRepIds[0]) {
                        $repToggleLabel = (string) ($rep['name_ar'] ?? 'مندوب واحد');
                        break;
                    }
                }
            } else {
                $repToggleLabel = $selectedRepCount . ' مندوبون محددون';
            }
            $repsForJs = [];
            foreach ($reps as $rep) {
                $repsForJs[] = [
                    'id' => (int) ($rep['id'] ?? 0),
                    'name' => (string) ($rep['name_ar'] ?? ''),
                    'code' => (string) ($rep['code'] ?? ''),
                ];
            }
            $repsJson = json_encode($repsForJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
            if ($repsJson === false) {
                $repsJson = '[]';
            }
            ?>
            <div class="hx-visits-f hx-visits-f--rep">
                <span class="hx-visits-f-label">المندوب</span>
                <div class="hx-smart-cust" id="hx-visits-smart-rep">
                    <div id="hx-visits-rep-hidden">
                        <?php foreach ($salesRepIds as $rid): ?>
                            <input type="hidden" name="sales_rep_id" value="<?= (int) $rid ?>">
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="input hx-smart-cust-toggle" id="hx-visits-rep-toggle"
                        aria-haspopup="listbox" aria-expanded="false" aria-controls="hx-visits-rep-panel">
                        <span class="hx-smart-cust-toggle-text" id="hx-visits-rep-label"><?= esc($repToggleLabel) ?></span>
                        <span class="hx-smart-cust-caret" aria-hidden="true"></span>
                    </button>
                    <div class="hx-smart-cust-panel" id="hx-visits-rep-panel" hidden>
                        <input class="input hx-smart-cust-search" type="search" id="hx-visits-rep-q"
                            placeholder="ابحث عن مندوب…" autocomplete="off">
                        <label class="hx-cust-check-item hx-cust-check-item--all">
                            <input type="checkbox" id="hx-rep-all" <?= $selectedRepCount < 1 ? 'checked' : '' ?>>
                            <span>تحديد الكل</span>
                        </label>
                        <div class="hx-smart-cust-list" id="hx-visits-rep-list" role="group">
                            <?php if ($reps === []): ?>
                                <div class="hx-smart-cust-empty">لا يوجد مندوبون.</div>
                            <?php else: ?>
                                <?php foreach ($reps as $rep): ?>
                                    <?php $rid = (int) ($rep['id'] ?? 0); ?>
                                    <label class="hx-cust-check-item">
                                        <input type="checkbox" class="hx-rep-one" value="<?= $rid ?>" <?= isset($selectedRepSet[$rid]) ? 'checked' : '' ?>>
                                        <span><?= esc((string) ($rep['name_ar'] ?? '')) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <script type="application/json" id="hx-visits-reps-json"><?= $repsJson ?></script>
                </div>
            </div>
            <?php
            $customerMap = [];
            foreach ($customers as $cust) {
                $cid = (int) ($cust['id'] ?? 0);
                if ($cid < 1 || isset($customerMap[$cid])) {
                    continue;
                }
                $customerMap[$cid] = [
                    'id' => $cid,
                    'name' => trim((string) ($cust['name_ar'] ?? $cust['name'] ?? '')),
                    'code' => trim((string) ($cust['code'] ?? '')),
                ];
            }
            foreach ($rowsForPicker as $r) {
                $cid = (int) ($r['customer_id'] ?? 0);
                if ($cid < 1 || isset($customerMap[$cid])) {
                    continue;
                }
                $customerMap[$cid] = [
                    'id' => $cid,
                    'name' => trim((string) ($r['customer_name'] ?? '')),
                    'code' => trim((string) ($r['customer_code'] ?? '')),
                ];
            }
            $customersForJs = array_values($customerMap);
            usort($customersForJs, static function (array $a, array $b): int {
                return strcmp((string) $a['name'], (string) $b['name']);
            });
            $selectedCount = count($customerIds);
            $selectedSet = array_fill_keys($customerIds, true);
            if ($selectedCount < 1) {
                $customerToggleLabel = '— الكل —';
            } elseif ($selectedCount === 1 && isset($customerMap[$customerIds[0]])) {
                $sc = $customerMap[$customerIds[0]];
                $customerToggleLabel = (($sc['code'] ?? '') !== '' ? $sc['code'] . ' — ' : '') . (string) ($sc['name'] ?? '');
            } elseif ($selectedCount === 1) {
                $customerToggleLabel = 'عميل واحد';
            } else {
                $customerToggleLabel = $selectedCount . ' عملاء محددون';
            }
            $customersJson = json_encode($customersForJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
            if ($customersJson === false) {
                $customersJson = '[]';
            }
            ?>
            <div class="hx-visits-f hx-visits-f--customer">
                <span class="hx-visits-f-label">العميل</span>
                <div class="hx-smart-cust" id="hx-visits-smart-cust">
                    <div id="hx-visits-customer-hidden">
                        <?php foreach ($customerIds as $cid): ?>
                            <input type="hidden" name="customer_id" value="<?= (int) $cid ?>">
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="input hx-smart-cust-toggle" id="hx-visits-customer-toggle"
                        aria-haspopup="listbox" aria-expanded="false" aria-controls="hx-visits-cust-panel">
                        <span class="hx-smart-cust-toggle-text" id="hx-visits-customer-label"><?= esc($customerToggleLabel) ?></span>
                        <span class="hx-smart-cust-caret" aria-hidden="true"></span>
                    </button>
                    <div class="hx-smart-cust-panel" id="hx-visits-cust-panel" hidden>
                        <input class="input hx-smart-cust-search" type="search" id="hx-visits-customer-q"
                            placeholder="ابحث بالاسم أو الرمز…" autocomplete="off">
                        <label class="hx-cust-check-item hx-cust-check-item--all">
                            <input type="checkbox" id="hx-cust-all" <?= $selectedCount < 1 ? 'checked' : '' ?>>
                            <span>تحديد الكل</span>
                        </label>
                        <div class="hx-smart-cust-list" id="hx-visits-cust-list" role="group">
                            <?php if ($customersForJs === []): ?>
                                <div class="hx-smart-cust-empty">لا يوجد عملاء في الفترة.</div>
                            <?php else: ?>
                                <?php foreach ($customersForJs as $cust): ?>
                                    <?php
                                    $cid = (int) ($cust['id'] ?? 0);
                                    $clabel = trim(
                                        ((string) ($cust['code'] ?? '') !== '' ? (string) $cust['code'] . ' — ' : '')
                                        . (string) ($cust['name'] ?? '')
                                    );
                                    if ($clabel === '') {
                                        $clabel = '#' . $cid;
                                    }
                                    ?>
                                    <label class="hx-cust-check-item">
                                        <input type="checkbox" class="hx-cust-one" value="<?= $cid ?>" <?= isset($selectedSet[$cid]) ? 'checked' : '' ?>>
                                        <span><?= esc($clabel) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <script type="application/json" id="hx-visits-customers-json"><?= $customersJson ?></script>
                </div>
            </div>
            <label class="hx-visits-f hx-visits-f--status">
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

          var custToggle = document.getElementById('hx-visits-customer-toggle');
          var custLabelEl = document.getElementById('hx-visits-customer-label');
          var custPanel = document.getElementById('hx-visits-cust-panel');
          var custList = document.getElementById('hx-visits-cust-list');
          var custAllCb = document.getElementById('hx-cust-all');
          var custHidden = document.getElementById('hx-visits-customer-hidden');
          var custSearch = document.getElementById('hx-visits-customer-q');
          var custSearchTimer = null;
          var custFetchToken = 0;
          var custAll = [];
          try {
            var raw = document.getElementById('hx-visits-customers-json');
            custAll = raw ? JSON.parse(raw.textContent || '[]') : [];
          } catch (e) { custAll = []; }
          if (!Array.isArray(custAll)) custAll = [];
          function custNameById(id) {
            for (var i = 0; i < custAll.length; i++) {
              if (Number(custAll[i].id) === Number(id)) {
                var c = custAll[i];
                return (c.code ? (c.code + ' — ') : '') + (c.name || ('#' + c.id));
              }
            }
            return 'عميل';
          }
          function isOpen() { return custPanel && !custPanel.hidden; }
          function filterSurface() {
            var wrap = document.getElementById('hx-visits-smart-cust') || document.getElementById('hx-visits-smart-rep');
            return wrap ? wrap.closest('.si-surface, .report-filters, header, .report-sales-page') : null;
          }
          function syncSurfaceOpenClass() {
            var surf = filterSurface();
            if (!surf) return;
            var anyOpen = (custPanel && !custPanel.hidden) || (repPanel && !repPanel.hidden);
            if (anyOpen) surf.classList.add('is-cust-open');
            else surf.classList.remove('is-cust-open');
          }
          function filterCheckItems(listEl, needle, oneClass) {
            if (!listEl) return;
            var q = String(needle || '').trim().toLowerCase();
            var visible = 0;
            Array.prototype.forEach.call(listEl.querySelectorAll('.hx-cust-check-item'), function (lab) {
              if (lab.classList.contains('hx-cust-check-item--all')) return;
              var inp = lab.querySelector('.' + oneClass);
              if (!inp) return;
              var text = (lab.textContent || '').toLowerCase();
              var show = !q || text.indexOf(q) >= 0 || String(inp.value).indexOf(q) >= 0;
              if (show) { lab.classList.remove('is-hidden'); visible++; }
              else lab.classList.add('is-hidden');
            });
            var empty = listEl.querySelector('.hx-smart-cust-empty');
            if (!visible && q) {
              if (!empty) {
                empty = document.createElement('div');
                empty.className = 'hx-smart-cust-empty';
                listEl.appendChild(empty);
              }
              empty.textContent = 'لا توجد نتائج مطابقة.';
              empty.hidden = false;
              empty.removeAttribute('hidden');
            } else if (empty) {
              empty.hidden = true;
              empty.setAttribute('hidden', '');
            }
          }
          function ensureCustCheck(c) {
            if (!custList || !c || !c.id) return;
            var id = String(c.id);
            if (custList.querySelector('.hx-cust-one[value="' + id + '"]')) return;
            var label = (c.code ? (c.code + ' — ') : '') + (c.name || ('#' + c.id));
            var lab = document.createElement('label');
            lab.className = 'hx-cust-check-item';
            lab.innerHTML = '<input type="checkbox" class="hx-cust-one" value="' + id + '"><span></span>';
            lab.querySelector('span').textContent = label;
            custList.appendChild(lab);
            custAll.push({ id: Number(c.id), name: c.name || '', code: c.code || '' });
          }
          function applyCustSearch(q) {
            filterCheckItems(custList, q, 'hx-cust-one');
            var needle = String(q || '').trim();
            if (needle.length < 2) return;
            var token = ++custFetchToken;
            fetch('/api/lookup/customers?q=' + encodeURIComponent(needle), { credentials: 'same-origin' })
              .then(function (r) { return r.json(); })
              .then(function (data) {
                if (token !== custFetchToken) return;
                if (!data || !data.ok) return;
                (data.rows || []).forEach(function (c) {
                  ensureCustCheck({
                    id: Number(c.id) || 0,
                    name: String(c.name_ar || c.name || ''),
                    code: String(c.code || '')
                  });
                });
                filterCheckItems(custList, needle, 'hx-cust-one');
              })
              .catch(function () {});
          }
          function closePanel() {
            if (!custPanel) return;
            custPanel.hidden = true;
            custPanel.setAttribute('hidden', '');
            if (custToggle) custToggle.setAttribute('aria-expanded', 'false');
            if (custSearch) custSearch.value = '';
            filterCheckItems(custList, '', 'hx-cust-one');
            syncSurfaceOpenClass();
          }
          function openPanel() {
            if (!custPanel) return;
            closeRepPanel();
            ensureChecksFromTable();
            custPanel.hidden = false;
            custPanel.removeAttribute('hidden');
            if (custToggle) custToggle.setAttribute('aria-expanded', 'true');
            syncSurfaceOpenClass();
            if (custSearch) setTimeout(function () { custSearch.focus(); custSearch.select(); }, 0);
          }
          function ensureChecksFromTable() {
            if (!custList) return;
            var existing = {};
            Array.prototype.forEach.call(custList.querySelectorAll('.hx-cust-one'), function (el) {
              existing[String(el.value)] = true;
            });
            Array.prototype.forEach.call(document.querySelectorAll('.si-col-customer[data-customer-id], .col-customer[data-customer-id]'), function (td) {
              var id = Number(td.getAttribute('data-customer-id') || 0) || 0;
              if (!id || existing[String(id)]) return;
              existing[String(id)] = true;
              var name = td.getAttribute('data-customer-name') || (td.textContent || '').trim();
              var code = td.getAttribute('data-customer-code') || '';
              var label = (code ? (code + ' — ') : '') + name;
              var lab = document.createElement('label');
              lab.className = 'hx-cust-check-item';
              lab.innerHTML = '<input type="checkbox" class="hx-cust-one" value="' + id + '"><span></span>';
              lab.querySelector('span').textContent = label || ('#' + id);
              custList.appendChild(lab);
              custAll.push({ id: id, name: name, code: code });
            });
            var empty = custList.querySelector('.hx-smart-cust-empty');
            if (empty && custList.querySelector('.hx-cust-one')) empty.remove();
          }
          function selectedOnes() {
            if (!custList) return [];
            return Array.prototype.slice.call(custList.querySelectorAll('.hx-cust-one:checked'))
              .map(function (el) { return Number(el.value) || 0; })
              .filter(function (id) { return id > 0; });
          }
          function syncHiddenAndLabel() {
            var ids = selectedOnes();
            if (custAllCb && ids.length < 1) custAllCb.checked = true;
            if (custAllCb && ids.length > 0) custAllCb.checked = false;
            var allOn = !!(custAllCb && custAllCb.checked);
            if (custHidden) {
              custHidden.innerHTML = '';
              if (!allOn) {
                ids.forEach(function (id) {
                  var inp = document.createElement('input');
                  inp.type = 'hidden';
                  inp.name = 'customer_id';
                  inp.value = String(id);
                  custHidden.appendChild(inp);
                });
              }
            }
            if (custLabelEl) {
              if (allOn || ids.length < 1) custLabelEl.textContent = '— الكل —';
              else if (ids.length === 1) custLabelEl.textContent = custNameById(ids[0]);
              else custLabelEl.textContent = ids.length + ' عملاء محددون';
            }
          }
          if (custToggle && custPanel) {
            custToggle.addEventListener('click', function (e) {
              e.preventDefault();
              if (isOpen()) closePanel();
              else openPanel();
            });
            if (custAllCb) {
              custAllCb.addEventListener('change', function () {
                if (custAllCb.checked && custList) {
                  Array.prototype.forEach.call(custList.querySelectorAll('.hx-cust-one'), function (el) {
                    el.checked = false;
                  });
                }
                syncHiddenAndLabel();
              });
            }
            if (custList) {
              custList.addEventListener('change', function (e) {
                if (!e.target || !e.target.classList || !e.target.classList.contains('hx-cust-one')) return;
                if (e.target.checked && custAllCb) custAllCb.checked = false;
                syncHiddenAndLabel();
              });
            }
            if (custSearch) {
              custSearch.addEventListener('input', function () {
                clearTimeout(custSearchTimer);
                var v = custSearch.value;
                custSearchTimer = setTimeout(function () { applyCustSearch(v); }, 140);
              });
              custSearch.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                  e.preventDefault();
                  closePanel();
                  if (custToggle) custToggle.focus();
                }
              });
            }
            syncHiddenAndLabel();
          }

          var repToggle = document.getElementById('hx-visits-rep-toggle');
          var repLabelEl = document.getElementById('hx-visits-rep-label');
          var repPanel = document.getElementById('hx-visits-rep-panel');
          var repList = document.getElementById('hx-visits-rep-list');
          var repAllCb = document.getElementById('hx-rep-all');
          var repHidden = document.getElementById('hx-visits-rep-hidden');
          var repSearch = document.getElementById('hx-visits-rep-q');
          var repSearchTimer = null;
          var repAll = [];
          try {
            var rawRep = document.getElementById('hx-visits-reps-json');
            repAll = rawRep ? JSON.parse(rawRep.textContent || '[]') : [];
          } catch (e2) { repAll = []; }
          if (!Array.isArray(repAll)) repAll = [];
          function repNameById(id) {
            for (var i = 0; i < repAll.length; i++) {
              if (Number(repAll[i].id) === Number(id)) return repAll[i].name || ('#' + id);
            }
            return 'مندوب';
          }
          function isRepOpen() { return repPanel && !repPanel.hidden; }
          function closeRepPanel() {
            if (!repPanel) return;
            repPanel.hidden = true;
            repPanel.setAttribute('hidden', '');
            if (repToggle) repToggle.setAttribute('aria-expanded', 'false');
            if (repSearch) repSearch.value = '';
            filterCheckItems(repList, '', 'hx-rep-one');
            syncSurfaceOpenClass();
          }
          function openRepPanel() {
            if (!repPanel) return;
            closePanel();
            repPanel.hidden = false;
            repPanel.removeAttribute('hidden');
            if (repToggle) repToggle.setAttribute('aria-expanded', 'true');
            syncSurfaceOpenClass();
            if (repSearch) setTimeout(function () { repSearch.focus(); repSearch.select(); }, 0);
          }
          function selectedReps() {
            if (!repList) return [];
            return Array.prototype.slice.call(repList.querySelectorAll('.hx-rep-one:checked'))
              .map(function (el) { return Number(el.value) || 0; })
              .filter(function (id) { return id > 0; });
          }
          function syncRepHiddenAndLabel() {
            var ids = selectedReps();
            if (repAllCb && ids.length < 1) repAllCb.checked = true;
            if (repAllCb && ids.length > 0) repAllCb.checked = false;
            var allOn = !!(repAllCb && repAllCb.checked);
            if (repHidden) {
              repHidden.innerHTML = '';
              if (!allOn) {
                ids.forEach(function (id) {
                  var inp = document.createElement('input');
                  inp.type = 'hidden';
                  inp.name = 'sales_rep_id';
                  inp.value = String(id);
                  repHidden.appendChild(inp);
                });
              }
            }
            if (repLabelEl) {
              if (allOn || ids.length < 1) repLabelEl.textContent = '— الكل —';
              else if (ids.length === 1) repLabelEl.textContent = repNameById(ids[0]);
              else repLabelEl.textContent = ids.length + ' مندوبون محددون';
            }
          }
          if (repToggle && repPanel) {
            repToggle.addEventListener('click', function (e) {
              e.preventDefault();
              if (isRepOpen()) closeRepPanel();
              else openRepPanel();
            });
            if (repAllCb) {
              repAllCb.addEventListener('change', function () {
                if (repAllCb.checked && repList) {
                  Array.prototype.forEach.call(repList.querySelectorAll('.hx-rep-one'), function (el) {
                    el.checked = false;
                  });
                }
                syncRepHiddenAndLabel();
              });
            }
            if (repList) {
              repList.addEventListener('change', function (e) {
                if (!e.target || !e.target.classList || !e.target.classList.contains('hx-rep-one')) return;
                if (e.target.checked && repAllCb) repAllCb.checked = false;
                syncRepHiddenAndLabel();
              });
            }
            if (repSearch) {
              repSearch.addEventListener('input', function () {
                clearTimeout(repSearchTimer);
                var v = repSearch.value;
                repSearchTimer = setTimeout(function () { filterCheckItems(repList, v, 'hx-rep-one'); }, 120);
              });
              repSearch.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                  e.preventDefault();
                  closeRepPanel();
                  if (repToggle) repToggle.focus();
                }
              });
            }
            syncRepHiddenAndLabel();
          }

          document.addEventListener('click', function (e) {
            var custWrap = document.getElementById('hx-visits-smart-cust');
            var repWrap = document.getElementById('hx-visits-smart-rep');
            if (custWrap && !custWrap.contains(e.target)) closePanel();
            if (repWrap && !repWrap.contains(e.target)) closeRepPanel();
          });
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
                <th>الوقت الفعال</th>
                <th>نوع الدخول</th>
                <th>نوع الخروج</th>
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
            html += '<td class="col-checkout-method">' + esc(r.checkout_method_label || '—') + '</td>';
            html += '<td class="col-sales" dir="ltr">' + money(r.order_total) + '</td>';
            html += '</tr>';
            return html;
          }
          function totalsRow(label, t, cols) {
            var labelSpan = Math.max(1, cols - 4);
            return '<tr class="report-visits-totals-row"><td colspan="' + labelSpan + '" style="text-align:start"><strong>'
              + esc(label) + '</strong></td><td class="col-duration"><strong>' + esc(t.duration_label || '—')
              + '</strong></td><td class="col-method"></td><td class="col-checkout-method"></td><td class="col-sales" dir="ltr"><strong>' + money(t.sales_total) + '</strong></td></tr>';
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
                var colCount = groupByRep ? 12 : 13;
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
