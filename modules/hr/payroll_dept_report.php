<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');
require_once app_path('includes/hr_payroll_dept_report.php');
require_once app_path('includes/document_header.php');
require_once app_path('includes/nav_helpers.php');

$pdo = db();
hr_employee_ensure_schema($pdo);

$listUrl = app_url('index.php?r=hr_payroll_dept_report');
$payYear = (int) ($_GET['year'] ?? date('Y'));
$payMonth = (int) ($_GET['month'] ?? (int) date('n'));
$deptId = (int) ($_GET['department_id'] ?? 0);
$showReport = !empty($_GET['show']);

if ($payMonth < 1 || $payMonth > 12) {
    $payMonth = (int) date('n');
}

$departments = hr_payroll_dept_report_department_options($pdo);
$report = null;
$reportDate = date('Y-m-d');

if ($showReport) {
    $report = hr_payroll_dept_report_build($pdo, $payYear, $payMonth, $deptId);
}

$reportTitle = 'كشف الرواتب للأقسام';
$deptLabel = 'جميع الأقسام';
if ($deptId > 0) {
    foreach ($departments as $d) {
        if ((int) $d['id'] === $deptId) {
            $deptLabel = (string) $d['name_ar'];
            break;
        }
    }
}

$monthNames = [
    1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل', 5 => 'مايو', 6 => 'يونيو',
    7 => 'يوليو', 8 => 'أغسطس', 9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر',
];

$cssPath = app_path('assets/css/hr-payroll-dept-report.css');
$cssUrl = app_url('assets/css/hr-payroll-dept-report.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$cssPathSalesOra = app_path('assets/css/hr-payroll-dept-report-sales-ora12.css');
$cssUrlSalesOra = app_url('assets/css/hr-payroll-dept-report-sales-ora12.css')
    . (is_file($cssPathSalesOra) ? '?v=' . (string) filemtime($cssPathSalesOra) : '');
$cssInvPath = app_path('assets/css/sales-invoice.css');
$cssInvUrl = app_url('assets/css/sales-invoice.css') . (is_file($cssInvPath) ? '?v=' . (string) filemtime($cssInvPath) : '');
$docCssUrl = document_print_stylesheet_url('assets/css/document-header.css');
$reportSalesCssUrl = document_print_stylesheet_url('assets/css/report-sales.css');
$jsPath = app_path('assets/js/hr-payroll-dept-report.js');
$jsUrl = app_url('assets/js/hr-payroll-dept-report.js') . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : '');
$exitUrl = nav_exit_url('hr_payroll_dept_report');
$wmRootCss = document_print_watermark_root_css($pdo);
?>
<link rel="stylesheet" href="<?= esc($docCssUrl) ?>">
<link rel="stylesheet" href="<?= esc($reportSalesCssUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssInvUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssUrlSalesOra) ?>">
<?php if ($wmRootCss !== ''): ?><style><?= $wmRootCss ?></style><?php endif; ?>
<style><?= document_print_header_css() ?></style>
<script src="<?= esc($jsUrl) ?>" defer></script>

<div class="dashboard-ora hr-pr-dept-ora12-screen hr-pr-dept-wrap hr-pr-dept-rpt-page"
     data-exit-url="<?= esc($exitUrl) ?>">

    <header class="dashboard-ora-screen-title" role="banner">
        <h1 class="dashboard-ora-screen-title__text">كشف الرواتب للأقسام</h1>
        <?php nav_render_screen_close('hr_payroll_dept_report'); ?>
    </header>

    <div class="dashboard-ora-workspace">
    <section class="dashboard-ora-panel hr-pr-dept-filter-panel no-print">
        <h2 class="dashboard-ora-panel__title">اختيار الكشف</h2>
        <div class="dashboard-ora-panel__body dashboard-ora-panel__body--flush">
        <form method="get" action="<?= esc(app_url('index.php')) ?>" class="hr-pr-dept-rpt-filters">
        <input type="hidden" name="r" value="hr_payroll_dept_report">
        <input type="hidden" name="show" value="1">

        <label class="field">
            <span class="field-label">القسم</span>
            <select class="input" name="department_id">
                <option value="0" <?= $deptId === 0 ? 'selected' : '' ?>>جميع الأقسام</option>
                <?php foreach ($departments as $d): ?>
                    <option value="<?= (int) $d['id'] ?>" <?= $deptId === (int) $d['id'] ? 'selected' : '' ?>>
                        <?= esc((string) $d['name_ar']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="field">
            <span class="field-label">السنة</span>
            <input class="input" type="number" name="year" min="2000" max="2100" value="<?= (int) $payYear ?>" required>
        </label>

        <label class="field">
            <span class="field-label">الشهر</span>
            <select class="input" name="month" required>
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $payMonth === $m ? 'selected' : '' ?>>
                        <?= $m ?> — <?= esc($monthNames[$m] ?? (string) $m) ?>
                    </option>
                <?php endfor; ?>
            </select>
        </label>

        <button type="submit" class="btn btn-primary btn-sm">عرض الكشف</button>
        </form>
        </div>
    </section>

<?php if ($showReport && $report !== null): ?>
<div class="hr-pr-dept-rpt-doc report-sales-result report-sales-print-area doc-print-watermark-scope">
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
                <td><strong>القسم:</strong> <?= esc($deptLabel) ?></td>
            </tr>
        </table>
    </div>

    <?php if (!$report['departments']): ?>
        <p class="hr-pr-dept-rpt-empty muted">لا توجد قيود رواتب لهذا الشهر — نفّذ الاحتساب من شاشة قيد الرواتب أولاً.</p>
    <?php endif; ?>

    <?php foreach ($report['departments'] as $block): ?>
        <section class="hr-pr-dept-rpt-block">
            <h3 class="hr-pr-dept-rpt-dept-name">القسم: <?= esc((string) $block['dept_name']) ?></h3>
            <table class="hr-pr-dept-rpt-table">
                <thead>
                <tr>
                    <th>تسلسل</th>
                    <th>رقم الموظف</th>
                    <th>اسم الموظف</th>
                    <th>الراتب الإجمالي</th>
                    <th>صافي الراتب (يستلمه الموظف)</th>
                    <th>اقتطاع الضمان للموظف</th>
                    <th>اقتطاع الضمان للشركة</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($block['rows'] as $ln): ?>
                    <tr>
                        <td><?= (int) ($ln['seq'] ?? 0) ?></td>
                        <td dir="ltr"><?= esc((string) ($ln['emp_code'] ?? '—')) ?></td>
                        <td><?= esc((string) ($ln['name_ar'] ?? '')) ?></td>
                        <td dir="ltr" class="num"><?= esc(number_format((float) ($ln['gross'] ?? 0), 3)) ?></td>
                        <td dir="ltr" class="num"><?= esc(number_format((float) ($ln['net'] ?? 0), 3)) ?></td>
                        <td class="<?= hr_payroll_dept_report_ss_is_na($ln) ? 'hr-pr-dept-rpt-na' : 'num' ?>" dir="ltr">
                            <?= esc(hr_payroll_dept_report_ss_display($ln, 'emp')) ?>
                        </td>
                        <td class="<?= hr_payroll_dept_report_ss_is_na($ln) ? 'hr-pr-dept-rpt-na' : 'num' ?>" dir="ltr">
                            <?= esc(hr_payroll_dept_report_ss_display($ln, 'er')) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                <tr class="hr-pr-dept-rpt-sum">
                    <td colspan="3">المجموع</td>
                    <td dir="ltr" class="num"><?= esc(number_format((float) $block['totals']['gross'], 3)) ?></td>
                    <td dir="ltr" class="num"><?= esc(number_format((float) $block['totals']['net'], 3)) ?></td>
                    <td dir="ltr" class="num"><?= esc(number_format((float) $block['totals']['ss_emp'], 3)) ?></td>
                    <td dir="ltr" class="num"><?= esc(number_format((float) $block['totals']['ss_er'], 3)) ?></td>
                </tr>
                </tfoot>
            </table>
        </section>
    <?php endforeach; ?>

    <?php if ($report['departments']): ?>
        <?php $grand = $report['grand']; ?>
        <footer class="hr-pr-dept-rpt-grand">
            <h3 class="hr-pr-dept-rpt-grand-title">المجموع النهائي</h3>
            <table class="hr-pr-dept-rpt-grand-table">
                <tbody>
                <tr>
                    <th>إجمالي الرواتب (قبل الاقتطاعات)</th>
                    <td dir="ltr" class="num"><?= esc(number_format((float) $grand['gross'], 3)) ?></td>
                </tr>
                <tr>
                    <th>اقتطاع الضمان — حصة الموظف</th>
                    <td dir="ltr" class="num"><?= esc(number_format((float) $grand['ss_emp'], 3)) ?></td>
                </tr>
                <tr>
                    <th>اقتطاع الضمان — حصة الشركة</th>
                    <td dir="ltr" class="num"><?= esc(number_format((float) $grand['ss_er'], 3)) ?></td>
                </tr>
                <tr class="hr-pr-dept-rpt-grand-highlight">
                    <th>الضمان الاجتماعي المستحق للدفع</th>
                    <td dir="ltr" class="num"><?= esc(number_format((float) $grand['ss_payable'], 3)) ?></td>
                </tr>
                <tr class="hr-pr-dept-rpt-grand-highlight">
                    <th>صافي رواتب الموظفين (المبلغ المستلم)</th>
                    <td dir="ltr" class="num"><?= esc(number_format((float) $grand['net'], 3)) ?></td>
                </tr>
                <tr class="hr-pr-dept-rpt-grand-total">
                    <th>إجمالي مطلوب من الصندوق (رواتب + ضمان)</th>
                    <td dir="ltr" class="num"><?= esc(number_format((float) $grand['net'] + (float) $grand['ss_payable'], 3)) ?></td>
                </tr>
                </tbody>
            </table>
        </footer>
    <?php endif; ?>
</div>
<?php endif; ?>
    </div><!-- .dashboard-ora-workspace -->
</div>

<div id="hr-pr-dept-print-overlay" class="sales-inv-print-overlay no-print" hidden>
    <div class="sales-inv-print-overlay-panel">
        <div class="sales-inv-print-overlay-head">
            <h3 class="sales-inv-print-overlay-title">معاينة الطباعة — اضغط «طباعة» في الشريط العلوي</h3>
            <div class="sales-inv-print-overlay-actions">
                <button type="button" class="btn btn-secondary btn-sm" id="hr-pr-dept-print-close">إغلاق</button>
            </div>
        </div>
        <div
            class="sales-inv-print-preview-body report-sales-print-area hr-pr-dept-rpt-doc doc-print-watermark-scope"
            id="hr-pr-dept-print-preview"
        ></div>
    </div>
</div>
