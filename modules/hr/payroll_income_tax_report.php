<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');
require_once app_path('includes/hr_payroll_income_tax_report.php');
require_once app_path('includes/document_header.php');

$pdo = db();
hr_employee_ensure_schema($pdo);

$postedYears = hr_payroll_ss_report_posted_years($pdo);
$maxPosted = hr_payroll_max_posted_period($pdo);

$mode = trim((string) ($_GET['mode'] ?? 'monthly'));
if (!in_array($mode, ['monthly', 'annual'], true)) {
    $mode = 'monthly';
}

$payYear = (int) ($_GET['year'] ?? ($maxPosted['year'] ?? (int) date('Y')));
$payMonth = (int) ($_GET['month'] ?? ($maxPosted['month'] ?? (int) date('n')));
$showReport = !empty($_GET['show']);

if ($payYear < 2000) {
    $payYear = (int) date('Y');
}
if ($payMonth < 1 || $payMonth > 12) {
    $payMonth = (int) date('n');
}

$postedMonthOptions = hr_payroll_ss_report_posted_month_options($pdo, $payYear);
if ($mode === 'monthly' && $postedMonthOptions !== []) {
    $postedMonthIds = array_column($postedMonthOptions, 'month');
    if (!in_array($payMonth, $postedMonthIds, true)) {
        $payMonth = (int) $postedMonthOptions[array_key_last($postedMonthOptions)]['month'];
    }
}

$report = null;
$reportDate = date('Y-m-d');
$periodNotPosted = false;

if ($showReport) {
    if ($mode === 'annual') {
        if (!hr_payroll_income_tax_report_year_is_posted($pdo, $payYear)) {
            $periodNotPosted = true;
        } else {
            $report = hr_payroll_income_tax_report_build_annual($pdo, $payYear);
        }
    } elseif (!hr_payroll_ss_report_month_is_posted($pdo, $payYear, $payMonth)) {
        $periodNotPosted = true;
    } else {
        $report = hr_payroll_income_tax_report_build_monthly($pdo, $payYear, $payMonth);
    }
}

$reportTitle = $mode === 'annual' ? 'كشف ضريبة الدخل السنوي' : 'كشف ضريبة الدخل الشهري';
$exportLabel = 'income-tax-report-' . $mode . '-' . $payYear
    . ($mode === 'monthly' ? '-' . str_pad((string) $payMonth, 2, '0', STR_PAD_LEFT) : '');

$cssPath = app_path('assets/css/hr-payroll-income-tax-report.css');
$cssUrl = app_url('assets/css/hr-payroll-income-tax-report.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$cssInvPath = app_path('assets/css/sales-invoice.css');
$cssInvUrl = app_url('assets/css/sales-invoice.css') . (is_file($cssInvPath) ? '?v=' . (string) filemtime($cssInvPath) : '');
$docCssUrl = document_print_stylesheet_url('assets/css/document-header.css');
$reportSalesCssUrl = document_print_stylesheet_url('assets/css/report-sales.css');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');
$wmRootCss = document_print_watermark_root_css($pdo);
?>
<link rel="stylesheet" href="<?= esc($docCssUrl) ?>">
<link rel="stylesheet" href="<?= esc($reportSalesCssUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssInvUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<?php if ($wmRootCss !== ''): ?><style><?= $wmRootCss ?></style><?php endif; ?>
<style><?= document_print_header_css() ?></style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" defer crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="<?= esc($exportJsUrl) ?>" defer></script>

<div
    class="card report-sales-page hr-pr-it-rpt-page"
    data-report-title="<?= esc($reportTitle) ?>"
    data-report-route="hr_payroll_income_tax_report"
    data-export-label="<?= esc($exportLabel) ?>"
    data-from-dmy="<?= esc(format_date_dmY($reportDate)) ?>"
>
    <div class="hr-pr-it-rpt-filters no-print">
        <form method="get" action="<?= esc(app_url('index.php')) ?>" class="hr-pr-it-rpt-filters-form">
            <input type="hidden" name="r" value="hr_payroll_income_tax_report">
            <input type="hidden" name="show" value="1">

            <label class="field">
                <span class="field-label">نوع الكشف</span>
                <select class="input" name="mode" id="hr-pr-it-rpt-mode">
                    <option value="monthly" <?= $mode === 'monthly' ? 'selected' : '' ?>>شهري</option>
                    <option value="annual" <?= $mode === 'annual' ? 'selected' : '' ?>>سنوي</option>
                </select>
            </label>

            <label class="field">
                <span class="field-label">السنة</span>
                <select class="input" name="year" required>
                    <?php if ($postedYears === []): ?>
                        <option value="<?= (int) $payYear ?>"><?= (int) $payYear ?></option>
                    <?php else: ?>
                        <?php foreach ($postedYears as $y): ?>
                            <option value="<?= (int) $y ?>" <?= $payYear === (int) $y ? 'selected' : '' ?>>
                                <?= (int) $y ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </label>

            <label class="field hr-pr-it-rpt-month-field"<?= $mode === 'annual' ? ' hidden' : '' ?>>
                <span class="field-label">الشهر (مرحّل فقط)</span>
                <select class="input" name="month" id="hr-pr-it-rpt-month" required<?= $postedMonthOptions === [] ? ' disabled' : '' ?>>
                    <?php if ($postedMonthOptions === []): ?>
                        <option value="">— لا توجد أشهر مرحّلة —</option>
                    <?php else: ?>
                        <?php foreach ($postedMonthOptions as $opt): ?>
                            <option value="<?= (int) $opt['month'] ?>" <?= $payMonth === (int) $opt['month'] ? 'selected' : '' ?>>
                                <?= esc((string) $opt['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </label>

            <button type="submit" class="btn btn-primary btn-sm"<?= $postedYears === [] || ($mode === 'monthly' && $postedMonthOptions === []) ? ' disabled' : '' ?>>
                عرض الكشف
            </button>
        </form>
    </div>

    <?php if ($postedYears === []): ?>
        <p class="alert alert-error no-print">لا توجد رواتب مرحّلة بعد — رحّل شهراً من شاشة «قيد الرواتب» أولاً.</p>
    <?php elseif ($showReport && $periodNotPosted): ?>
        <p class="alert alert-error no-print">
            <?= $mode === 'annual'
                ? 'السنة المختارة لا تحتوي رواتب مرحّلة.'
                : 'الشهر المختار غير مرحّل. اختر شهراً من قائمة الأشهر المرحّلة فقط.' ?>
        </p>
    <?php elseif ($showReport && $report !== null): ?>
        <div class="hr-pr-it-rpt-doc report-sales-result report-sales-print-area doc-print-watermark-scope">
            <?= document_print_watermark_html($pdo) ?>
            <?= document_print_header_html($reportTitle, $pdo) ?>

            <div class="doc-print-meta">
                <table>
                    <tr>
                        <td>
                            <strong>الفترة:</strong> <?= esc((string) $report['period_label']) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>نوع الكشف:</strong> <?= $mode === 'annual' ? 'سنوي' : 'شهري' ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>تاريخ التقرير:</strong> <span dir="ltr"><?= esc(format_date_dmY($reportDate)) ?></span>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>عدد الموظفين:</strong> <?= (int) $report['emp_count'] ?></td>
                    </tr>
                </table>
            </div>

            <?php if (!$report['rows']): ?>
                <p class="hr-pr-it-rpt-empty muted">لا توجد بيانات للفترة المحددة.</p>
            <?php else: ?>
                <table class="hr-pr-it-rpt-table report-sales-table">
                    <colgroup>
                        <col class="col-seq">
                        <col class="col-emp-code">
                        <col class="col-emp-name">
                        <col class="col-national-id">
                        <col class="col-marital">
                        <?php if ($mode === 'annual'): ?>
                            <col class="col-count">
                        <?php endif; ?>
                        <col class="col-money">
                        <col class="col-money">
                    </colgroup>
                    <thead>
                    <tr>
                        <th class="col-seq" dir="ltr">#</th>
                        <th class="col-emp-code">رقم الموظف</th>
                        <th class="col-emp-name">اسم الموظف</th>
                        <th class="col-national-id">الرقم الوطني</th>
                        <th class="col-marital">الحالة الاجتماعية</th>
                        <?php if ($mode === 'annual'): ?>
                            <th class="col-count">عدد الأشهر</th>
                        <?php endif; ?>
                        <th class="col-money">الوعاء الخاضع للضريبة</th>
                        <th class="col-money">ضريبة الدخل</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($report['rows'] as $ln): ?>
                        <tr>
                            <td class="col-seq"><?= (int) ($ln['seq'] ?? 0) ?></td>
                            <td class="col-emp-code" dir="ltr"><?= esc((string) ($ln['emp_code'] ?? '—')) ?></td>
                            <td class="col-emp-name"><span class="hr-pr-it-rpt-emp-name"><?= esc((string) ($ln['name_ar'] ?? '')) ?></span></td>
                            <td class="col-national-id" dir="ltr"><?= esc((string) ($ln['national_id'] ?? '') !== '' ? (string) $ln['national_id'] : '—') ?></td>
                            <td class="col-marital"><?= esc((string) ($ln['marital_label'] ?? '—')) ?></td>
                            <?php if ($mode === 'annual'): ?>
                                <td class="col-count num" dir="ltr"><?= (int) ($ln['month_count'] ?? 0) ?></td>
                            <?php endif; ?>
                            <td class="col-money <?= empty($ln['subject_to_tax']) ? 'hr-pr-it-rpt-na' : 'num' ?>" dir="ltr">
                                <?= esc(hr_payroll_income_tax_report_taxable_display($ln)) ?>
                            </td>
                            <td class="col-money <?= empty($ln['subject_to_tax']) ? 'hr-pr-it-rpt-na' : 'num' ?>" dir="ltr">
                                <?= esc(hr_payroll_income_tax_report_tax_display($ln)) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                    <tr class="hr-pr-it-rpt-sum">
                        <td colspan="<?= $mode === 'annual' ? 6 : 5 ?>">المجموع</td>
                        <td dir="ltr" class="col-money num"><?= esc(number_format((float) $report['totals']['taxable_base'], 3)) ?></td>
                        <td dir="ltr" class="col-money num"><?= esc(number_format((float) $report['totals']['income_tax'], 3)) ?></td>
                    </tr>
                    </tfoot>
                </table>

                <?php if ($mode === 'annual' && !empty($report['monthly_summary'])): ?>
                    <h4 class="hr-pr-it-rpt-subheading">ملخص الاقتطاع الشهري</h4>
                    <table class="hr-pr-it-rpt-table hr-pr-it-rpt-table--summary report-sales-table">
                        <thead>
                        <tr>
                            <th>الشهر</th>
                            <th>عدد الموظفين</th>
                            <th class="col-money">الوعاء الخاضع للضريبة</th>
                            <th class="col-money">ضريبة الدخل</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($report['monthly_summary'] as $ms): ?>
                            <tr>
                                <td><?= esc((string) ($ms['label'] ?? '')) ?></td>
                                <td class="num" dir="ltr"><?= (int) ($ms['emp_count'] ?? 0) ?></td>
                                <td class="col-money num" dir="ltr"><?= esc(number_format((float) ($ms['taxable_base'] ?? 0), 3)) ?></td>
                                <td class="col-money num" dir="ltr"><?= esc(number_format((float) ($ms['income_tax'] ?? 0), 3)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                        <tr class="hr-pr-it-rpt-sum">
                            <td>المجموع السنوي</td>
                            <td class="num" dir="ltr"><?= (int) $report['emp_count'] ?></td>
                            <td dir="ltr" class="col-money num"><?= esc(number_format((float) $report['totals']['taxable_base'], 3)) ?></td>
                            <td dir="ltr" class="col-money num"><?= esc(number_format((float) $report['totals']['income_tax'], 3)) ?></td>
                        </tr>
                        </tfoot>
                    </table>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script>
(function () {
  'use strict';
  var modeSel = document.getElementById('hr-pr-it-rpt-mode');
  var monthField = document.querySelector('.hr-pr-it-rpt-month-field');
  var monthSel = document.getElementById('hr-pr-it-rpt-month');
  if (!modeSel || !monthField || !monthSel) return;

  function syncMonthField() {
    var annual = modeSel.value === 'annual';
    monthField.hidden = annual;
    monthSel.disabled = annual || monthSel.options.length < 1;
    monthSel.required = !annual;
  }

  modeSel.addEventListener('change', syncMonthField);
  syncMonthField();
})();
</script>
