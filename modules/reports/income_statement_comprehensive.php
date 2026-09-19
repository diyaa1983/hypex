<?php
declare(strict_types=1);

require_once app_path('includes/acc_income_statement_comprehensive.php');
require_once app_path('includes/document_header.php');

$pdo = db();
acc_gl_ensure_schema($pdo);

$dateFrom = parse_date_to_iso(trim((string) ($_GET['date_from'] ?? ''))) ?? app_default_date_from();
$dateTo = parse_date_to_iso(trim((string) ($_GET['date_to'] ?? ''))) ?? app_default_date_to();

if ($dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$report = acc_report_income_statement_comprehensive($pdo, $dateFrom, $dateTo);
$totals = $report['totals'];
$summaryRows = $report['summary_rows'] ?? [];
$hasActivity = abs((float) ($totals['total_revenue'] ?? 0)) > 0.000001
    || abs((float) ($totals['total_cogs'] ?? 0)) > 0.000001
    || abs((float) ($totals['total_operating'] ?? 0)) > 0.000001;

$reportTitle = 'تقرير الأرباح والخسائر';

$salesCssPath = app_path('assets/css/report-sales.css');
$salesCssUrl = app_url('assets/css/report-sales.css') . (is_file($salesCssPath) ? '?v=' . (string) filemtime($salesCssPath) : '');
$accCssPath = app_path('assets/css/report-acc.css');
$accCssUrl = app_url('assets/css/report-acc.css') . (is_file($accCssPath) ? '?v=' . (string) filemtime($accCssPath) : '');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');

$detailPlUrl = app_url(
    'index.php?r=report_income_statement&date_from=' . rawurlencode(format_date_dmY($dateFrom))
    . '&date_to=' . rawurlencode(format_date_dmY($dateTo))
);
?>
<link rel="stylesheet" href="<?= esc($salesCssUrl) ?>">
<link rel="stylesheet" href="<?= esc($accCssUrl) ?>">
<style><?= document_print_header_css() ?></style>

<div class="card report-sales-page party-stmt-page report-pl-comprehensive-page report-pl-summary-page"
     data-report-title="<?= esc($reportTitle) ?>"
     data-report-route="report_income_statement_comprehensive"
     data-export-label="ملخص"
     data-from-dmy="<?= esc(format_date_dmY($dateFrom)) ?>"
     data-to-dmy="<?= esc(format_date_dmY($dateTo)) ?>">

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print" id="pl-comp-filter-form">
        <input type="hidden" name="r" value="report_income_statement_comprehensive">
        <div class="form-row">
            <label class="field">
                <span class="field-label">من تاريخ</span>
                <input class="input js-date-dmy" type="text" name="date_from" value="<?= esc(format_date_dmY($dateFrom)) ?>" dir="ltr" autocomplete="off">
            </label>
            <label class="field">
                <span class="field-label">إلى تاريخ</span>
                <input class="input js-date-dmy" type="text" name="date_to" value="<?= esc(format_date_dmY($dateTo)) ?>" dir="ltr" autocomplete="off">
            </label>
        </div>
        <div style="margin-top:0.5rem;display:flex;flex-wrap:wrap;gap:0.5rem;align-items:center;">
            <button type="submit" class="btn btn-primary btn-sm">عرض التقرير</button>
            <button type="button" class="btn btn-secondary btn-sm no-print" id="pl-comp-print-btn">طباعة</button>
            <a class="btn btn-secondary btn-sm" href="<?= esc($detailPlUrl) ?>">قائمة الدخل التفصيلية</a>
        </div>
    </form>

    <div class="report-sales-result report-sales-print-area">
        <?= document_print_header_html($reportTitle, $pdo) ?>

        <div class="party-stmt-report-head">
            <p class="party-stmt-report-dates">
                <span>من: <?= esc(format_date_dmY($dateFrom)) ?></span>
                <span class="party-stmt-report-dates-sep">|</span>
                <span>إلى: <?= esc(format_date_dmY($dateTo)) ?></span>
            </p>
        </div>

        <?php
        $netRow = $summaryRows !== [] ? $summaryRows[count($summaryRows) - 1] : null;
        $isProfit = !empty($totals['net_is_profit']);
        ?>
        <div class="pl-summary-hero<?= $isProfit ? ' pl-summary-hero--profit' : ' pl-summary-hero--loss' ?>">
            <span class="pl-summary-hero__label"><?= $isProfit ? 'صافي الربح' : 'صافي الخسارة' ?></span>
            <strong class="pl-summary-hero__amount"><?= esc(format_money((float) ($totals['net_income'] ?? 0))) ?></strong>
        </div>

        <div class="report-sales-table-wrap">
            <?php if (!$hasActivity): ?>
            <p class="muted pl-summary-empty">لا حركات على الإيرادات أو المصروفات في هذه الفترة.</p>
            <?php else: ?>
            <table class="data-table report-sales-table pl-comp-table pl-summary-table">
                <thead>
                <tr>
                    <th class="col-seq">م</th>
                    <th class="col-desc">البيان</th>
                    <th class="col-money">المبلغ</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($summaryRows as $row):
                    $style = (string) ($row['style'] ?? 'normal');
                    $isDeduction = !empty($row['deduction']);
                    $rowClass = 'pl-summary-row';
                    if ($style === 'subtotal') {
                        $rowClass .= ' pl-summary-row--subtotal';
                    } elseif ($style === 'total') {
                        $rowClass .= ' pl-summary-row--total';
                        if (empty($row['is_profit'])) {
                            $rowClass .= ' pl-loss';
                        }
                    }
                    $amt = (float) ($row['amount'] ?? 0);
                    ?>
                    <tr class="<?= esc($rowClass) ?>">
                        <td class="col-seq"><?= (int) ($row['line_no'] ?? 0) ?></td>
                        <td class="col-desc"><?= esc((string) ($row['label'] ?? '')) ?></td>
                        <td class="col-money">
                            <?php if ($isDeduction && abs($amt) > 0.000001): ?>
                                <span class="pl-deduction-amount">(<?= esc(format_money(abs($amt))) ?>)</span>
                            <?php else: ?>
                                <?= esc(format_money($amt)) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<div id="sales-inv-export-host" class="sales-inv-export-host" aria-hidden="true"></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" defer crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="<?= esc($exportJsUrl) ?>"></script>
<script>
(function () {
    var printBtn = document.getElementById('pl-comp-print-btn');
    if (printBtn) {
        printBtn.addEventListener('click', function () {
            var tb = document.querySelector('#master-toolbar [data-master-action="print"]');
            if (tb) {
                tb.click();
            } else {
                window.print();
            }
        });
    }
})();
</script>
