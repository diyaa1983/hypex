<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');
require_once app_path('includes/hr_payroll_slip_report.php');
require_once app_path('includes/document_header.php');
require_once app_path('includes/nav_helpers.php');
require_once app_path('includes/employee_picker.php');

$pdo = db();
hr_employee_ensure_schema($pdo);

$payYear = (int) ($_GET['year'] ?? date('Y'));
$payMonth = (int) ($_GET['month'] ?? (int) date('n'));
$employeeId = (int) ($_GET['employee_id'] ?? 0);
$showSlip = !empty($_GET['show']);

if ($payMonth < 1 || $payMonth > 12) {
    $payMonth = (int) date('n');
}

$pickerEmployees = hr_employee_picker_list($pdo);
$periodEmployees = [];
$slip = null;
$slipHtml = '';
$errorMsg = '';

if ($payYear >= 2000 && $payMonth >= 1 && $payMonth <= 12) {
    $periodEmployees = hr_payroll_slip_report_employees_for_period($pdo, $payYear, $payMonth);
}

if ($showSlip) {
    if ($employeeId < 1) {
        $errorMsg = 'اختر الموظف.';
    } else {
        $slip = hr_payroll_slip_report_build($pdo, $employeeId, $payYear, $payMonth);
        if ($slip === null) {
            $errorMsg = 'لا يوجد قيد راتب لهذا الموظف في الشهر المحدد — نفّذ الاحتساب من شاشة قيد الرواتب أولاً.';
        } else {
            $slipHtml = hr_payroll_slip_report_render_html($slip);
        }
    }
}

$monthNames = [
    1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل', 5 => 'مايو', 6 => 'يونيو',
    7 => 'يوليو', 8 => 'أغسطس', 9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر',
];

$cssPath = app_path('assets/css/hr-payroll-slip-report.css');
$cssUrl = app_url('assets/css/hr-payroll-slip-report.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$cssPathSalesOra = app_path('assets/css/hr-payroll-slip-report-sales-ora12.css');
$cssUrlSalesOra = app_url('assets/css/hr-payroll-slip-report-sales-ora12.css')
    . (is_file($cssPathSalesOra) ? '?v=' . (string) filemtime($cssPathSalesOra) : '');
$cssInvPath = app_path('assets/css/sales-invoice.css');
$cssInvUrl = app_url('assets/css/sales-invoice.css') . (is_file($cssInvPath) ? '?v=' . (string) filemtime($cssInvPath) : '');
$docHeaderCssUrl = document_print_stylesheet_url('assets/css/document-header.css');
$jsPath = app_path('assets/js/hr-payroll-slip-report.js');
$jsUrl = app_url('assets/js/hr-payroll-slip-report.js') . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : '');
$exitUrl = nav_exit_url('hr_payroll_slip_report');
$wmRootCss = document_print_watermark_root_css($pdo);
?>
<link rel="stylesheet" href="<?= esc($cssInvUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssUrlSalesOra) ?>">
<link rel="stylesheet" href="<?= esc($docHeaderCssUrl) ?>">
<?php if ($wmRootCss !== ''): ?><style><?= $wmRootCss ?></style><?php endif; ?>
<?php employee_picker_enqueue_assets(); ?>
<?php employee_picker_json_script($pickerEmployees, 'hr-pslip-picker-json'); ?>
<script src="<?= esc($jsUrl) ?>" defer></script>

<div class="dashboard-ora hr-pslip-ora12-screen hr-pslip-wrap hr-pslip-rpt-page"
     data-exit-url="<?= esc($exitUrl) ?>">

    <header class="dashboard-ora-screen-title" role="banner">
        <h1 class="dashboard-ora-screen-title__text">قسيمة الراتب</h1>
        <?php nav_render_screen_close('hr_payroll_slip_report'); ?>
    </header>

    <div class="dashboard-ora-workspace">
    <section class="dashboard-ora-panel hr-pslip-filter-panel no-print">
        <h2 class="dashboard-ora-panel__title">اختيار القسيمة</h2>
        <div class="dashboard-ora-panel__body dashboard-ora-panel__body--flush">
        <form method="get" action="<?= esc(app_url('index.php')) ?>" class="hr-pslip-rpt-filters">
            <input type="hidden" name="r" value="hr_payroll_slip_report">
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
                            <?= $m ?> — <?= esc($monthNames[$m] ?? (string) $m) ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </label>

            <div class="hr-pslip-rpt-field-emp">
                <?= employee_picker_field([
                    'id' => 'hr-pslip-employee-id',
                    'name' => 'employee_id',
                    'label' => 'الموظف',
                    'compact' => true,
                    'wrapper_class' => 'hr-pslip-picker-slot',
                    'json_id' => 'hr-pslip-picker-json',
                    'manual_bind' => true,
                    'value' => $employeeId,
                    'placeholder' => 'اضغط لاختيار الموظف',
                    'required' => true,
                ]) ?>
            </div>

            <button type="submit" class="btn btn-primary btn-sm">عرض القسيمة</button>
        </form>
        <?php if ($showSlip && $periodEmployees === [] && $errorMsg === ''): ?>
            <p class="muted hr-pslip-rpt-hint">لا توجد قيود رواتب لهذا الشهر.</p>
        <?php endif; ?>
        <?php if ($errorMsg !== ''): ?>
            <p class="alert alert-error hr-pslip-rpt-alert"><?= esc($errorMsg) ?></p>
        <?php endif; ?>
        </div>
    </section>

    <?php if ($slipHtml !== ''): ?>
        <div class="hr-pslip-rpt-preview-wrap">
            <?= $slipHtml ?>
        </div>
    <?php endif; ?>
    </div><!-- .dashboard-ora-workspace -->
</div>

<div id="hr-pslip-print-overlay" class="sales-inv-print-overlay no-print" hidden>
    <div class="sales-inv-print-overlay-panel">
        <div class="sales-inv-print-overlay-head">
            <h3 class="sales-inv-print-overlay-title">معاينة الطباعة — اضغط «طباعة» في الشريط العلوي</h3>
            <div class="sales-inv-print-overlay-actions">
                <button type="button" class="btn btn-secondary btn-sm" id="hr-pslip-print-close">إغلاق</button>
            </div>
        </div>
        <div
            class="sales-inv-print-preview-body"
            id="hr-pslip-print-preview"
        ></div>
    </div>
</div>
