<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');
require_once app_path('includes/hr_payroll_posting.php');
require_once app_path('includes/document_header.php');
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
    foreach ($allRows as $r) {
        if ((string) ($r['status'] ?? 'none') === 'none') {
            continue;
        }
        $rows[] = $r;
        $totals['base'] += (float) ($r['base_salary'] ?? 0);
        $totals['perm_allow'] += (float) ($r['permanent_allow_total'] ?? 0);
        $totals['month_allow'] += (float) ($r['monthly_allow_total'] ?? 0);
        $totals['deductions'] += (float) ($r['deductions'] ?? 0);
        $totals['ss'] += (float) ($r['social_security_emp'] ?? 0);
        $totals['tax'] += (float) ($r['income_tax'] ?? 0);
        $totals['net'] += (float) ($r['net_salary'] ?? 0);
    }
    foreach ($totals as $k => $v) {
        $totals[$k] = round($v, 3);
    }

    $summary = hr_payroll_month_summary($pdo, $payYear, $payMonth, 0, 0);
    $monthStatus = hr_payroll_month_status_info($pdo, $payYear, $payMonth, $summary, count($allRows));
    $journal = hr_payroll_month_journal_entry($pdo, $payYear, $payMonth);
    if ($journal) {
        $movementNo = (string) ($journal['entry_no'] ?? $movementNo);
        $movementDate = (string) ($journal['entry_date'] ?? $movementDate);
        if ((string) ($journal['description_ar'] ?? '') !== '') {
            $movementDesc = (string) $journal['description_ar'];
        }
    }
}

$reportTitle = 'تقرير قيود الرواتب حسب الشهر';
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
    <div class="hr-pr-month-rpt-doc report-sales-print-area doc-print-watermark-scope">
        <?= document_print_watermark_html($pdo) ?>
        <?= document_print_header_html($reportTitle, $pdo) ?>

        <div class="doc-print-meta">
            <table>
                <tr>
                    <td><strong>الفترة:</strong> <?= esc($periodLabel) ?></td>
                    <td><strong>تاريخ التقرير:</strong> <span dir="ltr"><?= esc(format_date_dmY($reportDate)) ?></span></td>
                    <td><strong>الحالة:</strong> <?= esc((string) ($monthStatus['label'] ?? '—')) ?></td>
                </tr>
            </table>
        </div>

        <table class="hr-pr-month-rpt-move">
            <tr>
                <th>رقم الحركة</th>
                <td dir="ltr"><?= esc($movementNo) ?></td>
                <th>وصف الحركة</th>
                <td><?= esc($movementDesc) ?></td>
                <th>تاريخ الحركة</th>
                <td dir="ltr"><?= esc(format_date_dmY($movementDate)) ?></td>
            </tr>
        </table>

        <?php if (!$rows): ?>
            <p class="hr-pr-month-rpt-empty muted">لا توجد قيود رواتب محتسبة أو مرحّلة لهذا الشهر.</p>
        <?php else: ?>
        <table class="hr-pr-month-rpt-table">
            <thead>
            <tr>
                <th>تسلسل</th>
                <th>رقم الموظف</th>
                <th>اسم الموظف</th>
                <th>الراتب الأساسي</th>
                <th>إجمالي العلاوات</th>
                <th>العلاوات الشهرية</th>
                <th>الاقتطاعات</th>
                <th>ضمان الموظف</th>
                <th>ضريبة الدخل</th>
                <th>صافي الراتب</th>
                <th>الحالة</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $idx => $r): ?>
                <?php
                $status = (string) ($r['status'] ?? 'none');
                $statusLabel = hr_payroll_employee_status_label($status, !empty($r['has_setup']));
                ?>
                <tr>
                    <td dir="ltr"><?= (int) $idx + 1 ?></td>
                    <td dir="ltr"><?= esc((string) ($r['emp_code'] ?? '—')) ?></td>
                    <td><?= esc((string) ($r['name_ar'] ?? '')) ?></td>
                    <td dir="ltr" class="num"><?= esc(number_format((float) ($r['base_salary'] ?? 0), 3)) ?></td>
                    <td dir="ltr" class="num"><?= esc(number_format((float) ($r['permanent_allow_total'] ?? 0), 3)) ?></td>
                    <td dir="ltr" class="num"><?= esc(number_format((float) ($r['monthly_allow_total'] ?? 0), 3)) ?></td>
                    <td dir="ltr" class="num"><?= esc(number_format((float) ($r['deductions'] ?? 0), 3)) ?></td>
                    <td dir="ltr" class="num"><?= esc(number_format((float) ($r['social_security_emp'] ?? 0), 3)) ?></td>
                    <td dir="ltr" class="num"><?= esc(number_format((float) ($r['income_tax'] ?? 0), 3)) ?></td>
                    <td dir="ltr" class="num"><?= esc(number_format((float) ($r['net_salary'] ?? 0), 3)) ?></td>
                    <td><?= esc($statusLabel) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
            <tr>
                <td colspan="3">الإجمالي</td>
                <td dir="ltr" class="num"><?= esc(number_format($totals['base'], 3)) ?></td>
                <td dir="ltr" class="num"><?= esc(number_format($totals['perm_allow'], 3)) ?></td>
                <td dir="ltr" class="num"><?= esc(number_format($totals['month_allow'], 3)) ?></td>
                <td dir="ltr" class="num"><?= esc(number_format($totals['deductions'], 3)) ?></td>
                <td dir="ltr" class="num"><?= esc(number_format($totals['ss'], 3)) ?></td>
                <td dir="ltr" class="num"><?= esc(number_format($totals['tax'], 3)) ?></td>
                <td dir="ltr" class="num"><?= esc(number_format($totals['net'], 3)) ?></td>
                <td></td>
            </tr>
            </tfoot>
        </table>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
