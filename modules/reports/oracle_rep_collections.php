<?php
declare(strict_types=1);

/**
 * كشف تحصيلات المندوب خلال فترة — Oracle (قراءة فقط)
 */

require_once app_path('includes/oracle_rep_collections.php');
require_once app_path('includes/document_header.php');

$routeKey = 'report_oracle_rep_collections';
$reportTitle = 'كشف تحصيلات المندوب خلال فترة (Oracle)';

$oraReps = oracle_list_rep_net_sales_reps();
$reps = [];
$seen = [];
$oraNameByMan = [];
if (!empty($oraReps['ok']) && is_array($oraReps['rows'] ?? null)) {
    foreach ($oraReps['rows'] as $r) {
        $no = (int) ($r['id'] ?? 0);
        if ($no < 1 || isset($seen[$no])) {
            continue;
        }
        $seen[$no] = true;
        $nm = trim((string) ($r['name_ar'] ?? ''));
        if ($nm === '') {
            $nm = 'مندوب ' . $no;
        }
        $oraNameByMan[$no] = $nm;
        $reps[] = [
            'id' => $no,
            'code' => (string) $no,
            'name_ar' => $nm,
        ];
    }
}
usort(
    $reps,
    static fn (array $a, array $b): int => strcmp((string) $a['name_ar'], (string) $b['name_ar'])
);

$salesRepId = (int) ($_GET['sales_rep_id'] ?? 0);
$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
if ($from === '') {
    $from = date('Y') . '-' . date('m') . '-01';
}
if ($to === '') {
    $to = function_exists('app_default_date_to') ? app_default_date_to() : date('Y-m-d');
}

$fmtAmt = static function (float $n): string {
    return number_format(round($n, 3), 3, '.', ',');
};
$fmtDmy = static function (string $iso): string {
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $m)) {
        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }

    return $iso;
};

$result = null;
$err = '';
$showResult = false;
$rows = [];
$totals = ['count' => 0, 'amount' => 0.0];
$repName = '';
$submitted = isset($_GET['run']) || isset($_GET['sales_rep_id']) || isset($_GET['from']) || isset($_GET['to']);

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
            $repName = $oraNameByMan[$salesRepId] ?? ('مندوب ' . $salesRepId);
        }
        $repFrom = $salesRepId > 0 ? $salesRepId : null;
        $result = oracle_fetch_rep_collections($from, $to, $repFrom, $repFrom, 1);
        if (empty($result['ok'])) {
            $err = (string) ($result['message'] ?? 'تعذر الاستعلام من Oracle.');
        } else {
            $showResult = true;
            $rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
            $totals = is_array($result['totals'] ?? null) ? $result['totals'] : $totals;
            if ($rows === [] && ($result['message'] ?? '') !== '') {
                $err = (string) $result['message'];
            }
        }
    }
}

$repsJson = json_encode($reps, JSON_UNESCAPED_UNICODE);
if ($repsJson === false) {
    $repsJson = '[]';
}

$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$repJsPath = app_path('assets/js/report-rep-picker.js');
$repJsUrl = app_url('assets/js/report-rep-picker.js') . (is_file($repJsPath) ? '?v=' . (string) filemtime($repJsPath) : '');
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">

<div class="card report-sales-page" data-report-route="<?= esc($routeKey) ?>" data-report-title="<?= esc($reportTitle) ?>">
    <?php if ($err !== ''): ?>
        <div class="alert alert-error no-print" style="margin-bottom:1rem;"><?= esc($err) ?></div>
    <?php endif; ?>

    <p class="muted no-print" style="margin:0 0 .5rem;font-size:.85rem">
        تحصيلات المندوب من Oracle (سندات القبض النقدي والشيكات) — مثل تقرير Forms.
    </p>

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print">
        <input type="hidden" name="r" value="<?= esc($routeKey) ?>">
        <input type="hidden" name="run" value="1">
        <div class="form-row">
            <label class="field" style="flex:1 1 16rem;">
                <span class="field-label">المندوب (Oracle)</span>
                <div class="report-cust-pick" id="report-sales-rep-pick">
                    <input type="hidden" name="sales_rep_id" data-rep-id value="<?= $salesRepId > 0 ? (int) $salesRepId : '' ?>">
                    <input type="text" class="input report-cust-pick-inp" data-rep-search
                           placeholder="ابحث بالاسم أو رقم المندوب… (فارغ = الكل)" autocomplete="off" spellcheck="false"
                           aria-label="بحث عن مندوب">
                    <div class="report-cust-pick-list" data-rep-list hidden></div>
                </div>
            </label>
            <label class="field">
                <span class="field-label">من تاريخ *</span>
                <input class="input js-date-dmy" type="text" name="from" value="<?= esc($fmtDmy($from)) ?>"
                       placeholder="يوم-شهر-سنة" dir="ltr" autocomplete="off" inputmode="numeric" required>
            </label>
            <label class="field">
                <span class="field-label">إلى تاريخ *</span>
                <input class="input js-date-dmy" type="text" name="to" value="<?= esc($fmtDmy($to)) ?>"
                       placeholder="يوم-شهر-سنة" dir="ltr" autocomplete="off" inputmode="numeric" required>
            </label>
        </div>
        <div style="margin-top:0.5rem;">
            <button class="btn btn-primary" type="submit">عرض التقرير</button>
        </div>
    </form>

    <?php if ($showResult): ?>
        <div class="report-sales-result report-sales-print-area">
            <?= document_print_header_html($reportTitle, db()) ?>
            <div class="doc-print-meta">
                <table>
                    <tr>
                        <td>
                            <strong>المندوب:</strong>
                            <?= $repName !== '' ? esc($repName) : 'الكل' ?>
                            <?php if ($salesRepId > 0): ?>
                                <span class="muted">(Oracle #<?= (int) $salesRepId ?>)</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <strong>من تاريخ:</strong> <?= esc($fmtDmy($from)) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>إلى تاريخ:</strong> <?= esc($fmtDmy($to)) ?>
                        </td>
                    </tr>
                </table>
            </div>

            <?php
            $byRep = [];
            foreach ($rows as $r) {
                $k = (int) ($r['rep_no'] ?? 0);
                if (!isset($byRep[$k])) {
                    $byRep[$k] = [
                        'rep_no' => $k,
                        'rep_name' => (string) ($r['rep_name'] ?? ''),
                        'comp_num' => (int) ($r['comp_num'] ?? 1),
                        'rows' => [],
                        'amount' => 0.0,
                    ];
                }
                $byRep[$k]['rows'][] = $r;
                $byRep[$k]['amount'] += (float) ($r['amount'] ?? 0);
            }
            ?>

            <?php if ($rows === []): ?>
                <p class="muted" style="text-align:center;padding:1.25rem;">لا توجد تحصيلات في الفترة المحددة.</p>
            <?php else: ?>
                <?php foreach ($byRep as $block): ?>
                    <h3 style="margin:1rem 0 .4rem;font-size:.95rem">
                        رقم المندوب: <span dir="ltr"><?= (int) $block['rep_no'] ?></span>
                        · <?= esc((string) $block['rep_name']) ?>
                        · شركة: <span dir="ltr"><?= (int) $block['comp_num'] ?></span>
                    </h3>
                    <div class="report-sales-table-wrap">
                        <table class="report-sales-table" data-export-table>
                            <thead>
                                <tr>
                                    <th>رقم القبض</th>
                                    <th>نوع القبض</th>
                                    <th>العميل</th>
                                    <th>تاريخ القبض</th>
                                    <th>رقم الشيك</th>
                                    <th>تاريخ حق الشيك</th>
                                    <th>القيمة</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($block['rows'] as $r): ?>
                                    <tr>
                                        <td class="col-seq" dir="ltr"><?= (int) ($r['rcv_no'] ?? 0) ?></td>
                                        <td><?= esc((string) ($r['rcv_type'] ?? '')) ?></td>
                                        <td><?= esc((string) ($r['customer_name'] ?? '')) ?></td>
                                        <td dir="ltr"><?= esc($fmtDmy((string) ($r['rcv_date'] ?? ''))) ?></td>
                                        <td dir="ltr"><?= esc((string) ($r['chq_no'] ?? '')) ?></td>
                                        <td dir="ltr"><?= esc(($r['chq_due'] ?? '') !== '' ? $fmtDmy((string) $r['chq_due']) : '') ?></td>
                                        <td class="col-money" dir="ltr"><?= esc($fmtAmt((float) ($r['amount'] ?? 0))) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr>
                                    <td colspan="6" style="font-weight:800;text-align:left">مجموع المندوب</td>
                                    <td class="col-money" dir="ltr" style="font-weight:800"><?= esc($fmtAmt((float) $block['amount'])) ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
                <p style="margin:.75rem 0 0;font-weight:800">
                    الإجمالي (<?= (int) ($totals['count'] ?? count($rows)) ?> حركة):
                    <span dir="ltr"><?= esc($fmtAmt((float) ($totals['amount'] ?? 0))) ?></span>
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script type="application/json" id="report-sales-reps-json"><?= $repsJson ?></script>
<script src="<?= esc($repJsUrl) ?>"></script>
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
</script>
