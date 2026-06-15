<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');
require_once app_path('includes/hr_payroll_ss_report.php');
require_once app_path('includes/document_header.php');

$pdo = db();
hr_employee_ensure_schema($pdo);

$listUrl = app_url('index.php?r=hr_payroll_ss_report');
$postedYears = hr_payroll_ss_report_posted_years($pdo);
$maxPosted = hr_payroll_max_posted_period($pdo);

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
if ($postedMonthOptions !== []) {
    $postedMonthIds = array_column($postedMonthOptions, 'month');
    if (!in_array($payMonth, $postedMonthIds, true)) {
        $payMonth = (int) $postedMonthOptions[array_key_last($postedMonthOptions)]['month'];
    }
}

$report = null;
$reportDate = date('Y-m-d');
$monthNotPosted = false;

if ($showReport) {
    if (!hr_payroll_ss_report_month_is_posted($pdo, $payYear, $payMonth)) {
        $monthNotPosted = true;
    } else {
        $report = hr_payroll_ss_report_build($pdo, $payYear, $payMonth);
    }
}

$reportTitle = 'كشف الضمان الاجتماعي';
$periodLabel = hr_payroll_period_label($payYear, $payMonth);
$exportLabel = 'ss-report-' . $payYear . '-' . str_pad((string) $payMonth, 2, '0', STR_PAD_LEFT);

$cssPath = app_path('assets/css/hr-payroll-ss-report.css');
$cssUrl = app_url('assets/css/hr-payroll-ss-report.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
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
    class="card report-sales-page hr-pr-ss-rpt-page"
    data-report-title="<?= esc($reportTitle) ?>"
    data-report-route="hr_payroll_ss_report"
    data-export-label="<?= esc($exportLabel) ?>"
    data-from-dmy="<?= esc(format_date_dmY($reportDate)) ?>"
>
    <div class="hr-pr-ss-rpt-filters no-print">
        <form method="get" action="<?= esc(app_url('index.php')) ?>" class="hr-pr-ss-rpt-filters-form">
            <input type="hidden" name="r" value="hr_payroll_ss_report">
            <input type="hidden" name="show" value="1">

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

            <label class="field">
                <span class="field-label">الشهر (مرحّل فقط)</span>
                <select class="input" name="month" required<?= $postedMonthOptions === [] ? ' disabled' : '' ?>>
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

            <button type="submit" class="btn btn-primary btn-sm"<?= $postedMonthOptions === [] ? ' disabled' : '' ?>>
                عرض الكشف
            </button>
        </form>
    </div>

    <?php if ($postedYears === []): ?>
        <p class="alert alert-error no-print">لا توجد رواتب مرحّلة بعد — رحّل شهراً من شاشة «قيد الرواتب» أولاً.</p>
    <?php elseif ($showReport && $monthNotPosted): ?>
        <p class="alert alert-error no-print">الشهر المختار غير مرحّل. اختر شهراً من قائمة الأشهر المرحّلة فقط.</p>
    <?php elseif ($showReport && $report !== null): ?>
        <div class="hr-pr-ss-rpt-doc report-sales-result report-sales-print-area doc-print-watermark-scope">
            <?= document_print_watermark_html($pdo) ?>
            <?= document_print_header_html($reportTitle, $pdo) ?>

            <div class="doc-print-meta">
                <table>
                    <tr>
                        <td>
                            <strong>الفترة:</strong> <?= esc((string) $report['period_label']) ?>
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
                <p class="hr-pr-ss-rpt-empty muted">لا توجد بيانات لهذا الشهر المرحّل.</p>
            <?php else: ?>
                <table class="hr-pr-ss-rpt-table report-sales-table">
                    <thead>
                    <tr>
                        <th class="col-seq">ت</th>
                        <th>رقم الموظف</th>
                        <th>اسم الموظف</th>
                        <th>رقم الضمان الاجتماعي</th>
                        <th>الرقم الوطني</th>
                        <th class="col-money">الراتب الأساسي</th>
                        <th class="col-money">اقتطاع الضمان</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($report['rows'] as $ln): ?>
                        <tr>
                            <td class="col-seq"><?= (int) ($ln['seq'] ?? 0) ?></td>
                            <td dir="ltr"><?= esc((string) ($ln['emp_code'] ?? '—')) ?></td>
                            <td><?= esc((string) ($ln['name_ar'] ?? '')) ?></td>
                            <td dir="ltr"><?= esc((string) ($ln['social_security_no'] ?? '') !== '' ? (string) $ln['social_security_no'] : '—') ?></td>
                            <td dir="ltr"><?= esc((string) ($ln['national_id'] ?? '') !== '' ? (string) $ln['national_id'] : '—') ?></td>
                            <td dir="ltr" class="col-money num"><?= esc(number_format((float) ($ln['gross'] ?? 0), 3)) ?></td>
                            <td class="col-money <?= empty($ln['subject_to_ss']) ? 'hr-pr-ss-rpt-na' : 'num' ?>" dir="ltr">
                                <?= esc(hr_payroll_ss_report_ss_display($ln)) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                    <tr class="hr-pr-ss-rpt-sum">
                        <td colspan="5">المجموع</td>
                        <td dir="ltr" class="col-money num"><?= esc(number_format((float) $report['totals']['gross'], 3)) ?></td>
                        <td dir="ltr" class="col-money num"><?= esc(number_format((float) $report['totals']['ss_emp'], 3)) ?></td>
                    </tr>
                    </tfoot>
                </table>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
