<?php
declare(strict_types=1);

/**
 * تقرير المبيعات حسب المنطقة (منطقة العميل في crm_customer.region_id).
 */

require_once app_path('includes/sal_sales_by_region_report.php');
require_once app_path('includes/document_header.php');
require_once app_path('includes/crm_region.php');

$pdo = db();
crm_region_ensure_schema($pdo);

$regions = crm_region_load_active($pdo);

$regionIdRaw = $_GET['region_id'] ?? null;
$regionId = $regionIdRaw === null || $regionIdRaw === '' ? -1 : (int) $regionIdRaw;
// -1 = لم يُعرض بعد | 0 = بدون منطقة (تفصيل) | >0 = منطقة | special: all = ملخص
$mode = trim((string) ($_GET['mode'] ?? 'summary'));
if ($mode !== 'detail' && $mode !== 'summary') {
    $mode = 'summary';
}

$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
if ($from === '') {
    $from = app_default_date_from();
}
if ($to === '') {
    $to = app_default_date_to();
}

$summaryRows = [];
$detailRows = [];
$regionName = '';
$sumSub = 0.0;
$sumTotal = 0.0;
$sumInv = 0;
$sumCust = 0;
$showResult = false;
$err = '';
$submitted = isset($_GET['from']) || isset($_GET['to']) || isset($_GET['region_id']) || isset($_GET['mode']);

if ($submitted || isset($_GET['run'])) {
    $fromIso = parse_date_to_iso($from);
    $toIso = parse_date_to_iso($to);
    if ($fromIso === null || $toIso === null) {
        $err = 'تاريخ البداية والنهاية غير صالحين (يوم-شهر-سنة).';
    } elseif ($fromIso > $toIso) {
        $err = 'تاريخ البداية يجب أن يكون قبل أو يساوي تاريخ النهاية.';
    } else {
        $from = $fromIso;
        $to = $toIso;

        if ($mode === 'summary' || $regionId < 0) {
            $mode = 'summary';
            $showResult = true;
            $summaryRows = sal_report_sales_by_region_summary($pdo, $from, $to);
            foreach ($summaryRows as $r) {
                $sumSub += (float) ($r['subtotal'] ?? 0);
                $sumTotal += (float) ($r['total'] ?? 0);
                $sumInv += (int) ($r['invoice_count'] ?? 0);
                $sumCust += (int) ($r['customer_count'] ?? 0);
            }
            $regionName = 'كل المناطق';
        } else {
            $mode = 'detail';
            if ($regionId > 0) {
                $st = $pdo->prepare('SELECT name_ar FROM crm_region WHERE id = ? LIMIT 1');
                $st->execute([$regionId]);
                $rg = $st->fetch(PDO::FETCH_ASSOC);
                if (!$rg) {
                    $err = 'المنطقة غير موجودة.';
                } else {
                    $regionName = (string) ($rg['name_ar'] ?? '');
                    $showResult = true;
                    $detailRows = sal_report_sales_by_region_detail($pdo, $regionId, $from, $to);
                }
            } else {
                $regionName = 'بدون منطقة';
                $showResult = true;
                $detailRows = sal_report_sales_by_region_detail($pdo, 0, $from, $to);
            }
            if ($showResult) {
                foreach ($detailRows as $r) {
                    $sumSub += (float) ($r['subtotal'] ?? 0);
                    $sumTotal += (float) ($r['total'] ?? 0);
                }
            }
        }
    }
}

$reportTitle = 'تقرير المبيعات حسب المنطقة';
$routeKey = 'report_sales_by_region';

$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$invCssPath = app_path('assets/css/sales-invoice.css');
$invCssUrl = app_url('assets/css/sales-invoice.css') . (is_file($invCssPath) ? '?v=' . (string) filemtime($invCssPath) : '');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js')
    . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');

$pageDataAttrs = ' data-report-title="' . esc($reportTitle) . '"';
$pageDataAttrs .= ' data-report-route="' . esc($routeKey) . '"';
if ($showResult) {
    $pageDataAttrs .= ' data-export-label="' . esc($regionName) . '"';
    $pageDataAttrs .= ' data-from-dmy="' . esc(format_date_dmY($from)) . '"';
    $pageDataAttrs .= ' data-to-dmy="' . esc(format_date_dmY($to)) . '"';
}
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($invCssUrl) ?>">

<div class="card report-sales-page report-sales-by-region-page"<?= $pageDataAttrs ?>>

    <?php if ($err !== ''): ?>
        <div class="alert alert-error no-print" style="margin-bottom:1rem;"><?= esc($err) ?></div>
    <?php endif; ?>

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print">
        <input type="hidden" name="r" value="<?= esc($routeKey) ?>">
        <input type="hidden" name="run" value="1">

        <div class="form-row">
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
            <label class="field" style="flex:1 1 14rem;">
                <span class="field-label">العرض</span>
                <select class="input" name="mode" id="region-report-mode">
                    <option value="summary"<?= $mode === 'summary' ? ' selected' : '' ?>>ملخص حسب المناطق</option>
                    <option value="detail"<?= $mode === 'detail' ? ' selected' : '' ?>>تفصيل منطقة</option>
                </select>
            </label>
            <label class="field" style="flex:1 1 14rem;" id="region-pick-wrap">
                <span class="field-label">المنطقة</span>
                <select class="input" name="region_id" id="region-report-id">
                    <option value="-1"<?= $mode === 'summary' ? ' selected' : '' ?>>— كل المناطق (ملخص) —</option>
                    <option value="0"<?= $mode === 'detail' && $regionId === 0 ? ' selected' : '' ?>>بدون منطقة</option>
                    <?php foreach ($regions as $rg):
                        $rid = (int) $rg['id'];
                        ?>
                        <option value="<?= $rid ?>"<?= $mode === 'detail' && $regionId === $rid ? ' selected' : '' ?>>
                            <?= esc((string) $rg['name_ar']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <div style="margin-top:0.65rem;">
            <button class="btn btn-primary" type="submit">عرض التقرير</button>
            <a class="btn btn-ghost" href="<?= esc(app_url('index.php?r=customer_regions')) ?>">إدارة المناطق</a>
        </div>
        <p class="muted" style="margin:0.5rem 0 0;font-size:0.88rem;">
            المبيعات حسب <strong>منطقة العميل</strong> المرتبطة به (ليست موقع GPS). اربط العملاء من شاشة تعديل العميل.
        </p>
    </form>

    <?php if ($showResult && $mode === 'summary'): ?>
        <div class="report-sales-result report-sales-print-area">
            <?= document_print_header_html($reportTitle, $pdo) ?>
            <div class="doc-print-meta">
                <table>
                    <tr>
                        <td>
                            <strong>من تاريخ:</strong> <?= esc(format_date_dmY($from)) ?>
                            &nbsp;|&nbsp;
                            <strong>إلى تاريخ:</strong> <?= esc(format_date_dmY($to)) ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>العرض:</strong> ملخص حسب المناطق</td>
                    </tr>
                </table>
            </div>
            <div class="report-sales-table-wrap">
                <table class="report-sales-table report-sales-table--by-region">
                    <thead>
                    <tr>
                        <th class="col-seq">تسلسل</th>
                        <th>المنطقة</th>
                        <th class="col-money">عدد العملاء</th>
                        <th class="col-money">عدد الفواتير</th>
                        <th class="col-money">الإجمالي شامل</th>
                        <th class="col-money">غير شامل</th>
                        <th class="no-print">تفصيل</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$summaryRows): ?>
                        <tr>
                            <td colspan="7" class="muted" style="text-align:center;padding:1.25rem;">
                                لا توجد فواتير مبيعات مؤكدة في هذه الفترة.
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php
                    $seq = 0;
                    foreach ($summaryRows as $r):
                        $seq++;
                        $rid = (int) $r['region_id'];
                        $detailUrl = app_url(
                            'index.php?r=report_sales_by_region&run=1&mode=detail'
                            . '&region_id=' . $rid
                            . '&from=' . rawurlencode(format_date_dmY($from))
                            . '&to=' . rawurlencode(format_date_dmY($to))
                        );
                        ?>
                        <tr>
                            <td class="col-seq"><?= $seq ?></td>
                            <td><?= esc((string) $r['region_name']) ?></td>
                            <td class="col-money" dir="ltr"><?= (int) $r['customer_count'] ?></td>
                            <td class="col-money" dir="ltr"><?= (int) $r['invoice_count'] ?></td>
                            <td class="col-money"><?= esc(format_money((float) $r['total'])) ?></td>
                            <td class="col-money"><?= esc(format_money((float) $r['subtotal'])) ?></td>
                            <td class="no-print">
                                <a class="btn btn-ghost btn-sm" href="<?= esc($detailUrl) ?>">تفصيل</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php if ($summaryRows): ?>
                        <tfoot>
                        <tr>
                            <td colspan="2"><strong>الإجمالي</strong></td>
                            <td class="col-money" dir="ltr"><?= (int) $sumCust ?></td>
                            <td class="col-money" dir="ltr"><?= (int) $sumInv ?></td>
                            <td class="col-money"><strong><?= esc(format_money($sumTotal)) ?></strong></td>
                            <td class="col-money"><strong><?= esc(format_money($sumSub)) ?></strong></td>
                            <td class="no-print"></td>
                        </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    <?php elseif ($showResult && $mode === 'detail'): ?>
        <div class="report-sales-result report-sales-print-area">
            <?= document_print_header_html($reportTitle, $pdo) ?>
            <div class="doc-print-meta">
                <table>
                    <tr>
                        <td><strong>المنطقة:</strong> <?= esc($regionName) ?></td>
                    </tr>
                    <tr>
                        <td>
                            <strong>من تاريخ:</strong> <?= esc(format_date_dmY($from)) ?>
                            &nbsp;|&nbsp;
                            <strong>إلى تاريخ:</strong> <?= esc(format_date_dmY($to)) ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>عدد الفواتير:</strong> <?= count($detailRows) ?></td>
                    </tr>
                </table>
            </div>
            <div class="report-sales-table-wrap">
                <table class="report-sales-table report-sales-table--by-region-detail">
                    <thead>
                    <tr>
                        <th class="col-seq">تسلسل</th>
                        <th>العميل</th>
                        <th>تاريخ الفاتورة</th>
                        <th>المندوب</th>
                        <th class="col-pay">النوع</th>
                        <th class="col-money">شامل</th>
                        <th class="col-money">غير شامل</th>
                        <th class="col-posted">الترحيل</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$detailRows): ?>
                        <tr>
                            <td colspan="8" class="muted" style="text-align:center;padding:1.25rem;">
                                لا توجد فواتير لهذه المنطقة في الفترة المحددة.
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php
                    $seq = 0;
                    foreach ($detailRows as $r):
                        $seq++;
                        ?>
                        <tr>
                            <td class="col-seq"><?= $seq ?></td>
                            <td>
                                <span class="report-sales-party-name"><?= esc((string) $r['customer_name']) ?></span>
                                <?php if ((string) ($r['customer_code'] ?? '') !== ''): ?>
                                    <br><code class="muted" style="font-size:0.8rem;"><?= esc((string) $r['customer_code']) ?></code>
                                <?php endif; ?>
                            </td>
                            <td class="col-date" dir="ltr"><?= esc(format_date_dmY((string) ($r['invoice_date'] ?? ''))) ?></td>
                            <td><?= esc((string) ($r['sales_rep_name'] ?? '') !== '' ? (string) $r['sales_rep_name'] : '—') ?></td>
                            <td class="col-pay"><?= esc((string) $r['payment_label']) ?></td>
                            <td class="col-money"><?= esc(format_money((float) $r['total'])) ?></td>
                            <td class="col-money"><?= esc(format_money((float) $r['subtotal'])) ?></td>
                            <td class="col-posted"><?= esc((string) $r['posted_label']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php if ($detailRows): ?>
                        <tfoot>
                        <tr>
                            <td colspan="5"><strong>الإجمالي</strong></td>
                            <td class="col-money"><strong><?= esc(format_money($sumTotal)) ?></strong></td>
                            <td class="col-money"><strong><?= esc(format_money($sumSub)) ?></strong></td>
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
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" defer crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="<?= esc($exportJsUrl) ?>" defer></script>
<script>
(function () {
  var modeEl = document.getElementById('region-report-mode');
  var idEl = document.getElementById('region-report-id');
  if (!modeEl || !idEl) return;
  function sync() {
    // عند الملخص نثبت القيمة على -1 في العرض فقط عند الإرسال إذا أردنا
  }
  modeEl.addEventListener('change', function () {
    if (modeEl.value === 'summary') {
      idEl.value = '-1';
    } else if (idEl.value === '-1') {
      idEl.value = '0';
    }
  });
  idEl.addEventListener('change', function () {
    if (idEl.value === '-1') {
      modeEl.value = 'summary';
    } else {
      modeEl.value = 'detail';
    }
  });
})();
</script>
