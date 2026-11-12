<?php
declare(strict_types=1);

/**
 * مبيعات المندوب من Oracle: المواد والفئات خلال فترة.
 */

require_once app_path('includes/oracle_rep_item_sales.php');
require_once app_path('includes/document_header.php');
require_once app_path('includes/crm_sales_rep_schema.php');

$pdo = db();
crm_sales_rep_ensure_schema($pdo);

$routeKey = 'report_oracle_rep_item_sales';
$reportTitle = 'مبيعات المندوب حسب المادة والفئة (Oracle)';
$cfg = oracle_rep_net_sales_cfg();

$parseOracleRepNo = static function (string $code): int {
    $code = trim($code);
    if ($code !== '' && preg_match('/^[1-9]\d*$/', $code)) {
        return (int) $code;
    }
    if (preg_match('/(\d+)/', $code, $m)) {
        $n = (int) $m[1];
        if ($n > 0 && $n < 100000) {
            return $n;
        }
    }

    return 0;
};

$hypex = $pdo->query(
    'SELECT code, name_ar FROM crm_sales_rep WHERE is_active = 1 ORDER BY name_ar'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$hypexNameByMan = [];
foreach ($hypex as $r) {
    $manNo = $parseOracleRepNo((string) ($r['code'] ?? ''));
    if ($manNo < 1 || isset($hypexNameByMan[$manNo])) {
        continue;
    }
    $nm = trim((string) ($r['name_ar'] ?? ''));
    if ($nm !== '') {
        $hypexNameByMan[$manNo] = $nm;
    }
}

$oraReps = oracle_list_rep_net_sales_reps();
$reps = [];
$seen = [];
if (!empty($oraReps['ok']) && is_array($oraReps['rows'] ?? null)) {
    foreach ($oraReps['rows'] as $r) {
        $no = (int) ($r['id'] ?? 0);
        if ($no < 1 || isset($seen[$no])) {
            continue;
        }
        $seen[$no] = true;
        $reps[] = [
            'id' => $no,
            'code' => (string) $no,
            'name_ar' => $hypexNameByMan[$no] ?? (string) ($r['name_ar'] ?? ('مندوب ' . $no)),
        ];
    }
}
foreach ($hypexNameByMan as $manNo => $nm) {
    if (isset($seen[$manNo])) {
        continue;
    }
    $reps[] = ['id' => (int) $manNo, 'code' => (string) $manNo, 'name_ar' => $nm];
}
usort($reps, static fn (array $a, array $b): int => strcmp((string) $a['name_ar'], (string) $b['name_ar']));

$salesRepId = (int) ($_GET['sales_rep_id'] ?? 0);
$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
$store = (int) ($_GET['store'] ?? $cfg['default_store']);
if ($from === '') {
    $from = date('Y-m-01');
}
if ($to === '') {
    $to = function_exists('app_default_date_to') ? app_default_date_to() : date('Y-m-d');
}
if ($store < 1) {
    $store = (int) $cfg['default_store'];
}

$fmtAmt = static function (float $n): string {
    return number_format(round($n, 3), 3, '.', ',');
};
$fmtQty = static function (float $n): string {
    $s = number_format($n, 3, '.', ',');

    return rtrim(rtrim($s, '0'), '.');
};

$err = '';
$showResult = false;
$rows = [];
$totals = ['qty' => 0.0, 'bonus' => 0.0, 'amount' => 0.0, 'reps' => 0, 'items' => 0];
$repName = '';
$submitted = isset($_GET['run']) || isset($_GET['from']) || isset($_GET['to']);

if ($submitted) {
    $fromIso = function_exists('parse_date_to_iso') ? parse_date_to_iso($from) : null;
    $toIso = function_exists('parse_date_to_iso') ? parse_date_to_iso($to) : null;
    if ($fromIso === null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $fromIso = $from;
    }
    if ($toIso === null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $toIso = $to;
    }
    if ($fromIso === null && preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $from, $m)) {
        $fromIso = $m[3] . '-' . $m[2] . '-' . $m[1];
    }
    if ($toIso === null && preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $to, $m)) {
        $toIso = $m[3] . '-' . $m[2] . '-' . $m[1];
    }
    if ($fromIso === null || $toIso === null) {
        $err = 'تاريخ البداية والنهاية غير صالحين.';
    } elseif ($fromIso > $toIso) {
        $err = 'تاريخ البداية يجب أن يكون قبل أو يساوي تاريخ النهاية.';
    } else {
        $from = $fromIso;
        $to = $toIso;
        if ($salesRepId > 0) {
            $repName = $hypexNameByMan[$salesRepId] ?? ('مندوب ' . $salesRepId);
        }
        $result = oracle_fetch_rep_item_sales($from, $to, $store, $salesRepId > 0 ? $salesRepId : null);
        if (empty($result['ok'])) {
            $err = (string) ($result['message'] ?? 'تعذر الاستعلام من Oracle.');
        } else {
            $showResult = true;
            $rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
            $nameByMan = $hypexNameByMan;
            foreach ($reps as $rp) {
                $id = (int) ($rp['id'] ?? 0);
                $nm = trim((string) ($rp['name_ar'] ?? ''));
                if ($id > 0 && $nm !== '' && !isset($nameByMan[$id])) {
                    $nameByMan[$id] = $nm;
                }
            }
            foreach ($rows as &$row) {
                $no = (int) ($row['rep_no'] ?? 0);
                $current = trim((string) ($row['rep_name'] ?? ''));
                if ($no > 0 && isset($hypexNameByMan[$no])) {
                    $row['rep_name'] = $hypexNameByMan[$no];
                } elseif ($current === '' || preg_match('/^مندوب\s+\d+$/u', $current)) {
                    $row['rep_name'] = $nameByMan[$no] ?? ($no > 0 ? ('مندوب ' . $no) : $current);
                }
            }
            unset($row);
            $totals = is_array($result['totals'] ?? null) ? $result['totals'] : $totals;
            if ($rows === [] && ($result['message'] ?? '') !== '') {
                $err = (string) $result['message'];
            }
        }
    }
}

$repsJson = json_encode($reps, JSON_UNESCAPED_UNICODE) ?: '[]';
$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$repJsPath = app_path('assets/js/report-rep-picker.js');
$repJsUrl = app_url('assets/js/report-rep-picker.js') . (is_file($repJsPath) ? '?v=' . (string) filemtime($repJsPath) : '');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<style>
.ora-portrait-wrap { max-width: none; overflow-x: auto; }
.ora-portrait-table { width: 100%; table-layout: auto; border-collapse: collapse; }
.ora-portrait-table th.ora-vhead {
  writing-mode: horizontal-tb;
  white-space: nowrap;
  height: auto;
  vertical-align: middle;
  text-align: center;
  font-size: .82rem;
  padding: .55rem .45rem;
  position: sticky;
  top: 0;
  background: #f1f5f9;
  z-index: 2;
}
.ora-portrait-table td { vertical-align: middle; padding: .4rem .45rem; }
.ora-portrait-table td.ora-code,
.ora-portrait-table td[dir="ltr"] {
  white-space: nowrap;
  word-break: keep-all;
  font-variant-numeric: tabular-nums;
}
.ora-portrait-table td.ora-name { min-width: 14rem; white-space: normal; }
.ora-portrait-table th.ora-cat-col { white-space: normal; min-width: 6.5rem; text-align: center; }
.ora-portrait-table td.ora-cust-name { font-weight: 700; min-width: 12rem; white-space: normal; }
.ora-cust-block { margin: 0 0 1.35rem; }
.ora-cust-head {
  background: #f4f7fb;
  border: 1px solid #d5deea;
  border-bottom: 0;
  padding: .7rem .9rem;
}
.ora-cust-head .ora-rep { font-weight: 800; color: #1e3a5f; }
.ora-cust-head .ora-who { font-weight: 800; font-size: 1.02rem; margin-top: .25rem; }
.ora-cat-head { cursor: pointer; }
.ora-cat-head td { background: #e8eef8; font-weight: 800; }
.ora-arrow { display: inline-block; width: 1.1rem; }
.ora-arrow::before { content: "◂"; }
.ora-cat-head[data-open="1"] .ora-arrow::before { content: "▾"; }
.ora-cust-final td { background: #e7f4ea; font-weight: 800; }
.ora-rep-total, .ora-grand {
  margin: .35rem 0 1rem;
  font-weight: 800;
  padding: .45rem .7rem;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
}
.ora-grand { background: #eef2f7; }
@media print {
  @page { size: A4 portrait; margin: 8mm 6mm 10mm 6mm; }
  .ora-portrait-table th.ora-vhead { position: static; }
  .ora-portrait-table { font-size: 9pt; }
  tr.ora-item[hidden] { display: table-row !important; }
  .ora-arrow { display: none; }
}
</style>
<div class="card report-sales-page" data-report-title="<?= esc($reportTitle) ?>" data-report-route="<?= esc($routeKey) ?>">
    <?php if ($err !== ''): ?>
        <div class="alert alert-error no-print" style="margin-bottom:1rem;"><?= esc($err) ?></div>
    <?php endif; ?>
    <p class="muted no-print" style="margin:0 0 .5rem;font-size:.85rem;">
        المبيعات من Oracle حسب المندوب. الفئة من ربط المادة في النظام، وتظهر كعمود. المبلغ = الكمية × سعر الوحدة بعد خصم السطر، قبل خصم الفاتورة والضريبة.
    </p>
    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print">
        <input type="hidden" name="r" value="<?= esc($routeKey) ?>">
        <input type="hidden" name="run" value="1">
        <div class="form-row">
            <label class="field" style="flex:1 1 16rem;">
                <span class="field-label">المندوب</span>
                <div class="report-cust-pick" id="report-sales-rep-pick">
                    <input type="hidden" name="sales_rep_id" data-rep-id value="<?= $salesRepId > 0 ? (int) $salesRepId : '' ?>">
                    <input type="text" class="input report-cust-pick-inp" data-rep-search
                           placeholder="ابحث بالاسم أو رقم المندوب… (فارغ = الكل)" autocomplete="off" spellcheck="false">
                    <div class="report-cust-pick-list" data-rep-list hidden></div>
                </div>
            </label>
            <label class="field">
                <span class="field-label">من تاريخ *</span>
                <input class="input js-date-dmy" type="text" name="from" value="<?= esc(format_date_dmY($from)) ?>"
                       placeholder="يوم-شهر-سنة" dir="ltr" autocomplete="off" required>
            </label>
            <label class="field">
                <span class="field-label">إلى تاريخ *</span>
                <input class="input js-date-dmy" type="text" name="to" value="<?= esc(format_date_dmY($to)) ?>"
                       placeholder="يوم-شهر-سنة" dir="ltr" autocomplete="off" required>
            </label>
            <label class="field">
                <span class="field-label">المستودع</span>
                <input class="input" type="number" name="store" value="<?= (int) $store ?>" min="1" dir="ltr">
            </label>
        </div>
        <div style="margin-top:0.5rem;">
            <button class="btn btn-primary" type="submit">عرض التقرير</button>
        </div>
    </form>

    <?php if ($showResult): ?>
        <div class="report-sales-result report-sales-print-area">
            <?= document_print_header_html($reportTitle, $pdo) ?>
            <div class="doc-print-meta">
                <table>
                    <tr>
                        <td>
                            <strong>المندوب:</strong> <?= $repName !== '' ? esc($repName) : 'الكل' ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>من:</strong> <?= esc(format_date_dmY($from)) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>إلى:</strong> <?= esc(format_date_dmY($to)) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>المستودع:</strong> <?= (int) $store ?>
                        </td>
                    </tr>
                </table>
            </div>
            <?php
            // by-item = التصميم السابق. by-customer = الفئات أعمدة وأسماء العملاء صفوف.
            $oraLayout = 'by-customer';
            ?>
            <?php if ($rows === []): ?>
                <p class="muted">لا توجد مبيعات في الفترة المحددة.</p>
            <?php elseif ($oraLayout === 'by-item'): ?>
                <?php
                $n = count($rows);
                $i = 0;
                $oraGid = 0;
                while ($i < $n):
                    $repStart = $i;
                    $no = (int) ($rows[$i]['rep_no'] ?? 0);
                    while ($i < $n && (int) ($rows[$i]['rep_no'] ?? 0) === $no) {
                        $i++;
                    }
                    $repRows = array_slice($rows, $repStart, $i - $repStart);
                    $repLabel = trim((string) ($repRows[0]['rep_name'] ?? ''));
                    if ($repLabel === '' && $no > 0) {
                        $repLabel = 'مندوب ' . $no;
                    }
                    $customers = [];
                    $j = 0;
                    $repCount = count($repRows);
                    while ($j < $repCount) {
                        $acc = (string) ($repRows[$j]['customer_acc'] ?? '');
                        $slice = [];
                        while ($j < $repCount && (string) ($repRows[$j]['customer_acc'] ?? '') === $acc) {
                            $slice[] = $repRows[$j];
                            $j++;
                        }
                        $customers[] = $slice;
                    }
                    $repQty = 0.0;
                    $repAmt = 0.0;
                    foreach ($customers as $slice):
                        $custAcc = (string) ($slice[0]['customer_acc'] ?? '');
                        $custLabel = trim((string) ($slice[0]['customer_name'] ?? '')) ?: ($custAcc !== '' ? $custAcc : '—');
                        $cats = [];
                        $c = 0;
                        $sliceCount = count($slice);
                        while ($c < $sliceCount) {
                            $cat = trim((string) ($slice[$c]['cat_name'] ?? '')) ?: 'بدون فئة';
                            $lines = [];
                            $catQty = 0.0;
                            $catAmt = 0.0;
                            while ($c < $sliceCount && (trim((string) ($slice[$c]['cat_name'] ?? '')) ?: 'بدون فئة') === $cat) {
                                $catQty += (float) ($slice[$c]['qty'] ?? 0);
                                $catAmt += (float) ($slice[$c]['amount'] ?? 0);
                                $lines[] = $slice[$c];
                                $c++;
                            }
                            $cats[] = ['cat' => $cat, 'lines' => $lines, 'qty' => $catQty, 'amt' => $catAmt];
                        }
                        $custQty = 0.0;
                        $custAmt = 0.0;
                        foreach ($cats as $g) {
                            $custQty += $g['qty'];
                            $custAmt += $g['amt'];
                        }
                        $repQty += $custQty;
                        $repAmt += $custAmt;
                        ?>
                        <section class="ora-cust-block">
                            <header class="ora-cust-head">
                                <div class="ora-rep">المندوب: <?= esc($repLabel) ?></div>
                                <div class="ora-who">العميل: <?= esc($custLabel) ?></div>
                            </header>
                            <div class="report-sales-table-wrap ora-portrait-wrap">
                                <table class="report-sales-table ora-portrait-table" data-export-table>
                                    <thead>
                                        <tr>
                                            <th class="ora-vhead">فئة</th>
                                            <th class="ora-vhead">ايدي</th>
                                            <th class="ora-vhead">كود</th>
                                            <th class="ora-vhead">اسم المادة</th>
                                            <th class="ora-vhead">الكمية</th>
                                            <th class="ora-vhead">المبلغ</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($cats as $g):
                                            $oraGid++;
                                            $groupId = 'ora-g-' . $oraGid;
                                            ?>
                                            <tr class="ora-cat-head" data-ora-toggle="<?= esc($groupId) ?>" data-open="0">
                                                <td colspan="4"><span class="ora-arrow" aria-hidden="true"></span><?= esc($g['cat']) ?></td>
                                                <td dir="ltr"><?= esc($fmtQty($g['qty'])) ?></td>
                                                <td class="col-money" dir="ltr"><?= esc($fmtAmt($g['amt'])) ?></td>
                                            </tr>
                                            <?php foreach ($g['lines'] as $r):
                                                $idCode = trim((string) ($r['item_code'] ?? ''));
                                                $barcode = trim((string) ($r['item_barcode'] ?? ''));
                                                $sku = trim((string) ($r['item_sku'] ?? ''));
                                                $code = $barcode !== '' ? $barcode : ($sku !== '' && $sku !== $idCode ? $sku : '');
                                                ?>
                                                <tr class="ora-item" data-ora-lines="<?= esc($groupId) ?>" hidden>
                                                    <td><?= esc($g['cat']) ?></td>
                                                    <td class="ora-code" dir="ltr"><?= esc($idCode) ?></td>
                                                    <td class="ora-code" dir="ltr"><?= esc($code) ?></td>
                                                    <td class="ora-name"><?= esc((string) ($r['item_name'] ?? '')) ?></td>
                                                    <td dir="ltr"><?= esc($fmtQty((float) ($r['qty'] ?? 0))) ?></td>
                                                    <td class="col-money" dir="ltr"><?= esc($fmtAmt((float) ($r['amount'] ?? 0))) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endforeach; ?>
                                        <tr class="ora-cust-final">
                                            <td colspan="4">المجموع النهائي لمبيعات العميل</td>
                                            <td dir="ltr"><?= esc($fmtQty($custQty)) ?></td>
                                            <td class="col-money" dir="ltr"><?= esc($fmtAmt($custAmt)) ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    <?php endforeach; ?>
                    <p class="ora-rep-total">إجمالي المندوب <?= esc($repLabel) ?> — الكمية <?= esc($fmtQty($repQty)) ?> — المبلغ <?= esc($fmtAmt($repAmt)) ?></p>
                <?php endwhile; ?>
                <p class="ora-grand">الإجمالي — الكمية <?= esc($fmtQty((float) ($totals['qty'] ?? 0))) ?> — المبلغ <?= esc($fmtAmt((float) ($totals['amount'] ?? 0))) ?></p>
            <?php else: ?>
                <?php
                $n = count($rows);
                $i = 0;
                while ($i < $n):
                    $repStart = $i;
                    $no = (int) ($rows[$i]['rep_no'] ?? 0);
                    while ($i < $n && (int) ($rows[$i]['rep_no'] ?? 0) === $no) {
                        $i++;
                    }
                    $repRows = array_slice($rows, $repStart, $i - $repStart);
                    $repLabel = trim((string) ($repRows[0]['rep_name'] ?? ''));
                    if ($repLabel === '' && $no > 0) {
                        $repLabel = 'مندوب ' . $no;
                    }
                    $catOrder = [];
                    $custMap = [];
                    foreach ($repRows as $r) {
                        $cat = trim((string) ($r['cat_name'] ?? '')) ?: 'بدون فئة';
                        if (!in_array($cat, $catOrder, true)) {
                            $catOrder[] = $cat;
                        }
                        $acc = (string) ($r['customer_acc'] ?? '');
                        if (!isset($custMap[$acc])) {
                            $label = trim((string) ($r['customer_name'] ?? ''));
                            $custMap[$acc] = [
                                'label' => $label !== '' ? $label : ($acc !== '' ? $acc : '—'),
                                'cats' => [],
                            ];
                        }
                        $custMap[$acc]['cats'][$cat] = ($custMap[$acc]['cats'][$cat] ?? 0) + (float) ($r['amount'] ?? 0);
                    }
                    usort($catOrder, static function (string $a, string $b): int {
                        if ($a === 'بدون فئة') {
                            return 1;
                        }
                        if ($b === 'بدون فئة') {
                            return -1;
                        }
                        return strcmp($a, $b);
                    });
                    $colTotals = array_fill(0, count($catOrder), 0.0);
                    $repAmt = 0.0;
                    ?>
                    <section class="ora-cust-block">
                        <header class="ora-cust-head">
                            <div class="ora-rep">المندوب: <?= esc($repLabel) ?></div>
                        </header>
                        <div class="report-sales-table-wrap ora-portrait-wrap">
                            <table class="report-sales-table ora-portrait-table" data-export-table>
                                <thead>
                                    <tr>
                                        <th class="ora-vhead">العميل</th>
                                        <?php foreach ($catOrder as $cat): ?>
                                            <th class="ora-vhead ora-cat-col"><?= esc($cat) ?></th>
                                        <?php endforeach; ?>
                                        <th class="ora-vhead">المجموع</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($custMap as $slot):
                                        $rowAmt = 0.0;
                                        ?>
                                        <tr>
                                            <td class="ora-cust-name"><?= esc($slot['label']) ?></td>
                                            <?php foreach ($catOrder as $idx => $cat):
                                                $amt = (float) ($slot['cats'][$cat] ?? 0);
                                                $colTotals[$idx] += $amt;
                                                $rowAmt += $amt;
                                                ?>
                                                <td dir="ltr"><?= $amt != 0.0 ? esc($fmtAmt($amt)) : '' ?></td>
                                            <?php endforeach; ?>
                                            <td class="col-money" dir="ltr"><?= esc($fmtAmt($rowAmt)) ?></td>
                                        </tr>
                                        <?php $repAmt += $rowAmt; ?>
                                    <?php endforeach; ?>
                                    <tr class="ora-cust-final">
                                        <td>المجموع</td>
                                        <?php foreach ($colTotals as $amt): ?>
                                            <td dir="ltr"><?= esc($fmtAmt($amt)) ?></td>
                                        <?php endforeach; ?>
                                        <td class="col-money" dir="ltr"><?= esc($fmtAmt($repAmt)) ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                <?php endwhile; ?>
                <p class="ora-grand">الإجمالي — المبلغ <?= esc($fmtAmt((float) ($totals['amount'] ?? 0))) ?></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<script type="application/json" id="report-sales-reps-json"><?= $repsJson ?></script>
<script src="<?= esc($repJsUrl) ?>" defer></script>
<script src="<?= esc($exportJsUrl) ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var el = document.getElementById('report-sales-reps-json');
  var root = document.getElementById('report-sales-rep-pick');
  if (!el || !root || !window.ReportRepPicker) return;
  var reps = [];
  try { reps = JSON.parse(el.textContent || '[]'); } catch (e) {}
  var hidden = root.querySelector('[data-rep-id]');
  var initialId = hidden && hidden.value ? parseInt(hidden.value, 10) : 0;
  window.ReportRepPicker.init(root, reps, { initialId: initialId });
});
document.addEventListener('click', function (ev) {
  var head = ev.target.closest && ev.target.closest('[data-ora-toggle]');
  if (!head) return;
  var id = head.getAttribute('data-ora-toggle');
  var open = head.getAttribute('data-open') === '1';
  head.setAttribute('data-open', open ? '0' : '1');
  document.querySelectorAll('[data-ora-lines="' + id + '"]').forEach(function (tr) {
    tr.hidden = open;
  });
});
</script>
