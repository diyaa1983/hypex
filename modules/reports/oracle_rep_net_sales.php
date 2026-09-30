<?php
declare(strict_types=1);

/**
 * تقرير صافي فواتير مبيعات المندوب من Oracle (قراءة فقط)
 * واجهة بنفس أسلوب تقارير المبيعات + قائمة مندوب ذكية.
 */

require_once app_path('includes/oracle_rep_net_sales.php');
require_once app_path('includes/document_header.php');
require_once app_path('includes/crm_sales_rep_schema.php');

$pdo = db();
crm_sales_rep_ensure_schema($pdo);

$routeKey = 'report_oracle_rep_net_sales';
$reportTitle = 'صافي فواتير مبيعات المندوب (Oracle)';
$cfg = oracle_rep_net_sales_cfg();

/** استخراج رقم مندوب Oracle من رمز المندوب في Hypex. */
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

$reps = $pdo->query(
    'SELECT id, code, name_ar FROM crm_sales_rep WHERE is_active = 1 ORDER BY name_ar'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$salesRepId = (int) ($_GET['sales_rep_id'] ?? 0);
$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
$store = (int) ($_GET['store'] ?? $cfg['default_store']);

if ($from === '') {
    $from = date('Y') . '-01-01';
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

$result = null;
$err = '';
$showResult = false;
$rows = [];
$totals = ['inv_cnt' => 0, 'net' => 0.0, 'cost' => 0.0, 'profit' => 0.0, 'profit_pct' => 0.0];
$repName = '';
$repOracleNo = null;

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
            $st = $pdo->prepare(
                'SELECT id, code, name_ar FROM crm_sales_rep WHERE id = ? AND is_active = 1 LIMIT 1'
            );
            $st->execute([$salesRepId]);
            $rep = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$rep) {
                $err = 'المندوب غير موجود.';
            } else {
                $repName = (string) ($rep['name_ar'] ?? '');
                $repOracleNo = $parseOracleRepNo((string) ($rep['code'] ?? ''));
                if ($repOracleNo < 1) {
                    $err = 'رمز المندوب في النظام لا يطابق رقم مندوب Oracle. راجع رمز المندوب.';
                }
            }
        }

        if ($err === '') {
            $repFrom = $repOracleNo !== null && $repOracleNo > 0 ? $repOracleNo : null;
            $repTo = $repFrom;
            $result = oracle_fetch_rep_net_sales($from, $to, $store, $repFrom, $repTo);
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
}

$repsJson = json_encode($reps, JSON_UNESCAPED_UNICODE);
if ($repsJson === false) {
    $repsJson = '[]';
}

$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$invCssPath = app_path('assets/css/sales-invoice.css');
$invCssUrl = app_url('assets/css/sales-invoice.css') . (is_file($invCssPath) ? '?v=' . (string) filemtime($invCssPath) : '');
$repJsPath = app_path('assets/js/report-rep-picker.js');
$repJsUrl = app_url('assets/js/report-rep-picker.js') . (is_file($repJsPath) ? '?v=' . (string) filemtime($repJsPath) : '');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');

$pageDataAttrs = ' data-report-title="' . esc($reportTitle) . '"';
$pageDataAttrs .= ' data-report-route="' . esc($routeKey) . '"';
if ($showResult) {
    $pageDataAttrs .= ' data-from-dmy="' . esc(format_date_dmY($from)) . '"';
    $pageDataAttrs .= ' data-to-dmy="' . esc(format_date_dmY($to)) . '"';
    $exportLabel = $repName !== '' ? $repName : ('مستودع ' . (int) $store);
    $pageDataAttrs .= ' data-export-label="' . esc($exportLabel) . '"';
}
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($invCssUrl) ?>">

<div class="card report-sales-page"<?= $pageDataAttrs ?>>

    <?php if ($err !== ''): ?>
        <div class="alert alert-error no-print" style="margin-bottom:1rem;"><?= esc($err) ?></div>
    <?php endif; ?>

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print">
        <input type="hidden" name="r" value="<?= esc($routeKey) ?>">
        <input type="hidden" name="run" value="1">
        <div class="form-row">
            <label class="field" style="flex:1 1 16rem;">
                <span class="field-label">المندوب</span>
                <div class="report-cust-pick" id="report-sales-rep-pick">
                    <input type="hidden" name="sales_rep_id" data-rep-id value="<?= $salesRepId > 0 ? (int) $salesRepId : '' ?>">
                    <input type="text" class="input report-cust-pick-inp" data-rep-search
                           placeholder="ابحث باسم المندوب أو الرمز… (فارغ = الكل)" autocomplete="off" spellcheck="false"
                           aria-label="بحث عن مندوب">
                    <div class="report-cust-pick-list" data-rep-list hidden></div>
                </div>
            </label>
            <label class="field">
                <span class="field-label">من تاريخ *</span>
                <input class="input js-date-dmy" type="text" name="from" value="<?= esc(format_date_dmY($from)) ?>"
                       placeholder="يوم-شهر-سنة" dir="ltr" autocomplete="off" inputmode="numeric" required>
            </label>
            <label class="field">
                <span class="field-label">إلى تاريخ *</span>
                <input class="input js-date-dmy" type="text" name="to" value="<?= esc(format_date_dmY($to)) ?>"
                       placeholder="يوم-شهر-سنة" dir="ltr" autocomplete="off" inputmode="numeric" required>
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
                            <strong>المندوب:</strong>
                            <?= $repName !== '' ? esc($repName) : 'الكل' ?>
                            <?php if ($repOracleNo !== null && $repOracleNo > 0): ?>
                                <span class="muted">(Oracle #<?= (int) $repOracleNo ?>)</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <strong>من تاريخ:</strong> <?= esc(format_date_dmY($from)) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>إلى تاريخ:</strong> <?= esc(format_date_dmY($to)) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>المستودع:</strong> <?= (int) $store ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>المصدر:</strong> Oracle · MAS.DAILY (TYPE=<?= (int) $cfg['sale_type'] ?>)</td>
                    </tr>
                </table>
            </div>

            <div class="report-sales-table-wrap">
                <table class="report-sales-table" data-export-table>
                    <thead>
                        <tr>
                            <th>رقم المندوب</th>
                            <th>اسم المندوب</th>
                            <th>عدد الفواتير</th>
                            <th>الصافي</th>
                            <th>التكلفة</th>
                            <th>الربح</th>
                            <th>الربح %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($rows === []): ?>
                            <tr>
                                <td colspan="7" class="muted" style="text-align:center;padding:1.25rem;">
                                    لا توجد بيانات في الفترة المحددة.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td class="col-seq" dir="ltr"><?= (int) ($r['rep_no'] ?? 0) ?></td>
                                    <td><?= esc((string) ($r['rep_name'] ?? '')) ?></td>
                                    <td dir="ltr"><?= (int) ($r['inv_cnt'] ?? 0) ?></td>
                                    <td class="col-money" dir="ltr"><?= esc($fmtAmt((float) ($r['net'] ?? 0))) ?></td>
                                    <td class="col-money" dir="ltr"><?= esc($fmtAmt((float) ($r['cost'] ?? 0))) ?></td>
                                    <td class="col-money" dir="ltr"><?= esc($fmtAmt((float) ($r['profit'] ?? 0))) ?></td>
                                    <td class="col-money" dir="ltr"><?= esc($fmtAmt((float) ($r['profit_pct'] ?? 0))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if ($rows !== []): ?>
                        <tfoot>
                            <tr>
                                <td colspan="2">الإجمالي</td>
                                <td dir="ltr"><?= (int) ($totals['inv_cnt'] ?? 0) ?></td>
                                <td class="col-money" dir="ltr"><?= esc($fmtAmt((float) ($totals['net'] ?? 0))) ?></td>
                                <td class="col-money" dir="ltr"><?= esc($fmtAmt((float) ($totals['cost'] ?? 0))) ?></td>
                                <td class="col-money" dir="ltr"><?= esc($fmtAmt((float) ($totals['profit'] ?? 0))) ?></td>
                                <td class="col-money" dir="ltr"><?= esc($fmtAmt((float) ($totals['profit_pct'] ?? 0))) ?></td>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<div id="sales-inv-export-host" class="sales-inv-export-host" aria-hidden="true"></div>

<script type="application/json" id="report-sales-reps-json"><?= $repsJson ?></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" defer crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="<?= esc($repJsUrl) ?>" defer></script>
<script src="<?= esc($exportJsUrl) ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var el = document.getElementById('report-sales-reps-json');
  var root = document.getElementById('report-sales-rep-pick');
  if (!el || !root || !window.ReportRepPicker) return;
  var reps = [];
  try {
    reps = JSON.parse(el.textContent || '[]');
  } catch (e) {}
  var hidden = root.querySelector('[data-rep-id]');
  var initialId = hidden && hidden.value ? parseInt(hidden.value, 10) : 0;
  window.ReportRepPicker.init(root, reps, { initialId: initialId });
});
</script>
