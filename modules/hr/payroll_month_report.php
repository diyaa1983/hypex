<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');
require_once app_path('includes/hr_payroll_month_report.php');
require_once app_path('includes/nav_helpers.php');

$pdo = db();
hr_employee_ensure_schema($pdo);

$payYear = (int) ($_GET['year'] ?? date('Y'));
$payMonth = (int) ($_GET['month'] ?? (int) date('n'));
$showReport = !empty($_GET['show']);
if ($payMonth < 1 || $payMonth > 12) {
    $payMonth = (int) date('n');
}

$monthNames = [
    1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل', 5 => 'مايو', 6 => 'يونيو',
    7 => 'يوليو', 8 => 'أغسطس', 9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر',
];

$rows = [];
$totals = [
    'base' => 0.0,
    'perm_allow' => 0.0,
    'month_allow' => 0.0,
    'deductions' => 0.0,
    'ss' => 0.0,
    'tax' => 0.0,
    'net' => 0.0,
];
$movementNo = sprintf('%04d-%02d', $payYear, $payMonth);
$movementDesc = 'رواتب ' . hr_payroll_period_label($payYear, $payMonth);
$movementDate = sprintf('%04d-%02d-01', $payYear, $payMonth);
$monthStatus = ['code' => 'open', 'label' => 'مفتوح'];

if ($showReport) {
    $allRows = hr_payroll_month_status_rows($pdo, $payYear, $payMonth, 0, 0);
    $filtered = hr_payroll_month_report_filter_rows($allRows);
    $rows = $filtered['rows'];
    $totals = $filtered['totals'];

    $summary = hr_payroll_month_summary($pdo, $payYear, $payMonth, 0, 0);
    $monthStatus = hr_payroll_month_status_info($pdo, $payYear, $payMonth, $summary, count($allRows));
    $movement = hr_payroll_month_report_movement($pdo, $payYear, $payMonth);
    $movementNo = $movement['movement_no'];
    $movementDesc = $movement['movement_desc'];
    $movementDate = $movement['movement_date'];
}

$reportTitle = hr_payroll_month_report_title();
$reportDate = date('Y-m-d');
$periodLabel = hr_payroll_period_label($payYear, $payMonth);
$cssPath = app_path('assets/css/hr-payroll-month-report.css');
$cssUrl = app_url('assets/css/hr-payroll-month-report.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$docCssUrl = document_print_stylesheet_url('assets/css/document-header.css');
$reportSalesCssUrl = document_print_stylesheet_url('assets/css/report-sales.css');
$exitUrl = nav_exit_url('hr_payroll_month_report');
$wmRootCss = document_print_watermark_root_css($pdo);
?>
<link rel="stylesheet" href="<?= esc($docCssUrl) ?>">
<link rel="stylesheet" href="<?= esc($reportSalesCssUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<?php if ($wmRootCss !== ''): ?><style><?= $wmRootCss ?></style><?php endif; ?>

<div class="dashboard-ora hr-pr-month-rpt-page" data-exit-url="<?= esc($exitUrl) ?>">
    <header class="dashboard-ora-screen-title no-print" role="banner">
        <h1 class="dashboard-ora-screen-title__text"><?= esc($reportTitle) ?></h1>
        <?php nav_render_screen_close('hr_payroll_month_report'); ?>
    </header>

    <section class="dashboard-ora-panel hr-pr-month-rpt-filter no-print">
        <h2 class="dashboard-ora-panel__title">اختيار الشهر</h2>
        <div class="dashboard-ora-panel__body">
            <form method="get" action="<?= esc(app_url('index.php')) ?>" class="hr-pr-month-rpt-filters">
                <input type="hidden" name="r" value="hr_payroll_month_report">
                <input type="hidden" name="show" value="1">
                <label class="field">
                    <span class="field-label">السنة</span>
                    <input class="input" type="number" name="year" min="2000" max="2100" value="<?= (int) $payYear ?>" required>
                </label>
                <label class="field">
                    <span class="field-label">الشهر</span>
                    <select class="input" name="month" required>
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= $payMonth === $m ? 'selected' : '' ?>>
                                <?= sprintf('%02d', $m) ?> - <?= esc($monthNames[$m] ?? (string) $m) ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </label>
                <button type="submit" class="btn btn-primary btn-sm">عرض التقرير</button>
                <?php if ($showReport && $rows): ?>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="window.print()">طباعة</button>
                <?php endif; ?>
            </form>
        </div>
    </section>

    <?php if ($showReport): ?>
        <?php hr_payroll_month_report_render_doc(
            $pdo,
            $payYear,
            $payMonth,
            $rows,
            $totals,
            $monthStatus,
            $movementNo,
            $movementDesc,
            $movementDate,
            $reportTitle,
            $reportDate
        ); ?>
    <?php endif; ?>
</div>
