<?php
declare(strict_types=1);

require_once app_path('includes/sal_sales_by_rep_report.php');
require_once app_path('includes/document_header.php');
require_once app_path('includes/crm_sales_rep_schema.php');

$pdo = db();
crm_sales_rep_ensure_schema($pdo);

$reps = $pdo->query(
    'SELECT id, code, name_ar FROM crm_sales_rep WHERE is_active = 1 ORDER BY name_ar'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$salesRepId = (int) ($_GET['sales_rep_id'] ?? 0);
$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));

if ($from === '') {
    $from = app_default_date_from();
}
if ($to === '') {
    $to = app_default_date_to();
}

$rows = [];
$repName = '';
$sumSub = 0.0;
$sumTotal = 0.0;
$showResult = false;
$err = '';

if (isset($_GET['sales_rep_id']) && $_GET['sales_rep_id'] !== '') {
    if ($salesRepId < 1) {
        $err = 'اختر المندوب من القائمة.';
    } else {
        $fromIso = parse_date_to_iso($from);
        $toIso = parse_date_to_iso($to);
        if ($fromIso === null || $toIso === null) {
            $err = 'تاريخ البداية والنهاية غير صالحين (يوم-شهر-سنة).';
        } elseif ($fromIso > $toIso) {
            $err = 'تاريخ البداية يجب أن يكون قبل أو يساوي تاريخ النهاية.';
        } else {
            $from = $fromIso;
            $to = $toIso;

            $st = $pdo->prepare('SELECT name_ar FROM crm_sales_rep WHERE id = ? AND is_active = 1 LIMIT 1');
            $st->execute([$salesRepId]);
            $rep = $st->fetch(PDO::FETCH_ASSOC);

            if (!$rep) {
                $err = 'المندوب غير موجود.';
            } else {
                $showResult = true;
                $repName = (string) ($rep['name_ar'] ?? '');
                $rows = sal_report_sales_by_rep($pdo, $salesRepId, $from, $to);
                foreach ($rows as $r) {
                    $sumSub += (float) ($r['subtotal'] ?? 0);
                    $sumTotal += (float) ($r['total'] ?? 0);
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
$repJsPath = app_path('assets/js/report-rep-picker.js');
$repJsUrl = app_url('assets/js/report-rep-picker.js') . (is_file($repJsPath) ? '?v=' . (string) filemtime($repJsPath) : '');
$invCssPath = app_path('assets/css/sales-invoice.css');
$invCssUrl = app_url('assets/css/sales-invoice.css') . (is_file($invCssPath) ? '?v=' . (string) filemtime($invCssPath) : '');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');

$reportTitle = 'تقرير المبيعات حسب المندوب';

$pageDataAttrs = ' data-report-title="' . esc($reportTitle) . '"';
$pageDataAttrs .= ' data-report-route="report_sales_by_rep"';
if ($showResult) {
    $pageDataAttrs .= ' data-export-label="' . esc($repName) . '"';
    $pageDataAttrs .= ' data-from-dmy="' . esc(format_date_dmY($from)) . '"';
    $pageDataAttrs .= ' data-to-dmy="' . esc(format_date_dmY($to)) . '"';
}
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($invCssUrl) ?>">

<div class="card report-sales-page"<?= $pageDataAttrs ?>>

    <?php if ($err !== ''): ?>
        <div class="alert alert-error no-print" style="margin-bottom:1rem;"><?= esc($err) ?></div>
    <?php endif; ?>

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print">
        <input type="hidden" name="r" value="report_sales_by_rep">
        <div class="form-row">
            <label class="field" style="flex:1 1 16rem;">
                <span class="field-label">المندوب *</span>
                <div class="report-cust-pick" id="report-sales-rep-pick">
                    <input type="hidden" name="sales_rep_id" data-rep-id value="<?= $salesRepId > 0 ? (int) $salesRepId : '' ?>">
                    <input type="text" class="input report-cust-pick-inp" data-rep-search
                           placeholder="ابحث باسم المندوب أو الرمز…" autocomplete="off" spellcheck="false"
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
                        <td><strong>المندوب:</strong> <?= esc($repName) ?></td>
                    </tr>
                    <tr>
                        <td>
                            <strong>من تاريخ:</strong> <?= esc(format_date_dmY($from)) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>إلى تاريخ:</strong> <?= esc(format_date_dmY($to)) ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>عدد الفواتير:</strong> <?= count($rows) ?></td>
                    </tr>
                </table>
            </div>

            <div class="report-sales-table-wrap">
                <table class="report-sales-table report-sales-table--by-rep">
                    <colgroup>
                        <col class="col-seq">
                        <col class="col-customer">
                        <col class="col-date">
                        <col class="col-pay">
                        <col class="col-money">
                        <col class="col-money">
                        <col class="col-posted">
                    </colgroup>
                    <thead>
                    <tr>
                        <th class="col-seq">تسلسل</th>
                        <th class="col-customer">اسم العميل</th>
                        <th class="col-date">تاريخ الفاتورة</th>
                        <th class="col-pay">نوع الفاتورة</th>
                        <th class="col-money">قيمة الفاتورة شامل</th>
                        <th class="col-money">قيمة الفاتورة غير شامل</th>
                        <th class="col-posted">الترحيل</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="7" class="muted" style="text-align:center;padding:1.25rem;">
                                لا توجد فواتير مبيعات لهذا المندوب في الفترة المحددة.
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php
                    $seq = 0;
                    foreach ($rows as $r):
                        $seq += 1;
                        $invUrl = app_url('index.php?r=sales_invoices&id=' . (int) $r['id']);
                        ?>
                        <tr>
                            <td class="col-seq"><?= $seq ?></td>
                            <td class="col-customer"><span class="report-sales-party-name"><?= esc((string) $r['customer_name']) ?></span></td>
                            <td class="col-date"><?= esc(format_date_dmY((string) ($r['invoice_date'] ?? ''))) ?></td>
                            <td class="col-pay"><?= esc((string) $r['payment_label']) ?></td>
                            <td class="col-money"><?= esc(format_money((float) $r['total'])) ?></td>
                            <td class="col-money"><?= esc(format_money((float) $r['subtotal'])) ?></td>
                            <td class="col-posted"><?= esc((string) $r['posted_label']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php if ($rows): ?>
                    <tfoot>
                    <tr>
                        <td colspan="4">الإجمالي</td>
                        <td class="col-money"><?= esc(format_money($sumTotal)) ?></td>
                        <td class="col-money"><?= esc(format_money($sumSub)) ?></td>
                        <td></td>
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
