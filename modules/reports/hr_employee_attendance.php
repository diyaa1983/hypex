<?php
declare(strict_types=1);

require_once app_path('includes/hr_employee_attendance_report.php');
require_once app_path('includes/document_header.php');
require_once app_path('includes/nav_helpers.php');
require_once app_path('includes/employee_picker.php');

$pdo = db();
hr_attendance_ensure_schema($pdo);

$dateFrom = parse_date_to_iso(trim((string) ($_GET['date_from'] ?? ''))) ?? app_default_month_from();
$dateTo = parse_date_to_iso(trim((string) ($_GET['date_to'] ?? ''))) ?? app_default_month_to();
if ($dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$departmentId = (int) ($_GET['department_id'] ?? 0);
$employeeId = (int) ($_GET['employee_id'] ?? 0);
$showReport = !empty($_GET['show']);

$departments = hr_employee_attendance_report_department_options($pdo);
$employees = hr_employee_attendance_report_employee_options($pdo);

$report = null;
$shiftSettings = hr_attendance_report_shift_settings($pdo);
if ($showReport) {
    $report = hr_employee_attendance_report_build($pdo, $dateFrom, $dateTo, $departmentId, $employeeId);
}

$deptLabel = 'جميع الأقسام';
if ($departmentId > 0) {
    foreach ($departments as $d) {
        if ((int) $d['id'] === $departmentId) {
            $deptLabel = (string) $d['name_ar'];
            break;
        }
    }
}

$empLabel = 'جميع الموظفين';
$empCodeDisplay = '';
if ($employeeId > 0) {
    foreach ($employees as $e) {
        if ((int) $e['id'] === $employeeId) {
            $empCodeDisplay = trim((string) ($e['emp_code'] ?? ''));
            $empLabel = $empCodeDisplay !== ''
                ? $empCodeDisplay . ' — ' . (string) $e['name_ar']
                : (string) $e['name_ar'];
            break;
        }
    }
}

$reportTitle = 'حركة دوام الموظفين خلال فترة';
$exitUrl = nav_exit_url('report_hr_employee_attendance');

$cssPath = app_path('assets/css/hr-employee-attendance-report.css');
$cssUrl = app_url('assets/css/hr-employee-attendance-report.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$reportSalesCssUrl = document_print_stylesheet_url('assets/css/report-sales.css');
$docCssUrl = document_print_stylesheet_url('assets/css/document-header.css');
$jsPath = app_path('assets/js/hr-employee-attendance-report.js');
$jsUrl = app_url('assets/js/hr-employee-attendance-report.js') . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : '');

$periodFromDmY = format_date_dmY($dateFrom);
$periodToDmY = format_date_dmY($dateTo);
?>
<link rel="stylesheet" href="<?= esc($docCssUrl) ?>">
<link rel="stylesheet" href="<?= esc($reportSalesCssUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<?php employee_picker_enqueue_assets(); ?>
<script type="application/json" id="hr-att-rpt-picker-json"><?= hr_employee_attendance_report_picker_json($employees) ?></script>
<script src="<?= esc($jsUrl) ?>" defer></script>
<style><?= document_print_header_css() ?></style>
<style>
@media print {
  @page {
    size: A4 portrait;
    margin: 8mm 7mm 10mm 7mm;
  }
  .hr-att-rpt-doc {
    padding: 0 1.5mm !important;
    box-sizing: border-box !important;
  }
  .hr-att-rpt-table-wrap {
    width: auto !important;
    max-width: 100% !important;
    margin: 0 1.5mm !important;
    box-sizing: border-box !important;
  }
  .hr-att-rpt-movement-table {
    width: 100% !important;
    max-width: 100% !important;
    table-layout: fixed !important;
    border-collapse: collapse !important;
    border: 1px solid #000 !important;
    font-size: 8pt !important;
  }
  .hr-att-rpt-movement-table th,
  .hr-att-rpt-movement-table td {
    font-size: 8pt !important;
    border: 1px solid #000 !important;
  }
}
</style>

<div class="card report-sales-page hr-att-rpt-page hr-att-rpt-print-portrait" data-report-route="report_hr_employee_attendance" data-exit-url="<?= esc($exitUrl) ?>">

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters report-sales-filters--inline no-print hr-att-rpt-filters">
        <input type="hidden" name="r" value="report_hr_employee_attendance">
        <input type="hidden" name="show" value="1">
        <div class="report-sales-filters-row">
            <label class="field report-sales-filter-field report-sales-filter-field--date">
                <span class="field-label">من تاريخ</span>
                <input class="input js-date-dmy" type="text" name="date_from" id="hr-att-rpt-date-from"
                       value="<?= esc($periodFromDmY) ?>" dir="ltr" autocomplete="off" required>
            </label>
            <label class="field report-sales-filter-field report-sales-filter-field--date">
                <span class="field-label">إلى تاريخ</span>
                <input class="input js-date-dmy" type="text" name="date_to" id="hr-att-rpt-date-to"
                       value="<?= esc($periodToDmY) ?>" dir="ltr" autocomplete="off" required>
            </label>
            <label class="field report-sales-filter-field report-sales-filter-field--rep">
                <span class="field-label">القسم</span>
                <select class="input" name="department_id" id="hr-att-rpt-dept">
                    <option value="0" <?= $departmentId === 0 ? 'selected' : '' ?>>جميع الأقسام</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= (int) $d['id'] ?>" <?= $departmentId === (int) $d['id'] ? 'selected' : '' ?>>
                            <?= esc((string) $d['name_ar']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?= employee_picker_field([
                'id' => 'hr-att-rpt-employee-id',
                'name' => 'employee_id',
                'label' => 'الموظف',
                'value' => $employeeId,
                'compact' => true,
                'allow_all' => true,
                'all_label' => 'جميع الموظفين',
                'placeholder' => 'جميع الموظفين — أو اضغط للبحث',
                'manual_bind' => true,
                'wrapper_class' => 'field report-sales-filter-field report-sales-filter-field--customer hr-att-rpt-picker-slot',
            ]) ?>
            <div class="field report-sales-filter-field report-sales-filter-field--submit">
                <span class="field-label" aria-hidden="true">&nbsp;</span>
                <button class="btn btn-primary" type="submit" id="hr-att-rpt-submit">عرض التقرير</button>
            </div>
        </div>
        <p class="hr-att-rpt-filter-hint muted no-print">
            الشفت الافتراضي: <strong><?= esc((string) ($shiftSettings['label'] ?? 'A (07:00-15:00)')) ?></strong>
            — عطلة نهاية الأسبوع: الجمعة والسبت.
            لعرض تفصيل يومي كامل يُفضّل اختيار موظف واحد.
        </p>
    </form>

    <div id="hr-att-rpt-loading" class="hr-att-rpt-loading no-print" hidden aria-live="polite" aria-busy="true">
        <div class="hr-att-rpt-loading-panel" role="status">
            <div class="hr-att-rpt-loading-spinner" aria-hidden="true"></div>
            <p class="hr-att-rpt-loading-msg">جاري تحميل بيانات التقرير...</p>
            <p class="hr-att-rpt-loading-progress-value" id="hr-att-rpt-loading-pct" dir="ltr">1</p>
            <p class="hr-att-rpt-loading-hint">قد يستغرق ذلك بعض الوقت عند اختيار فترة طويلة أو جميع الموظفين</p>
            <button type="button" class="btn btn-secondary btn-sm hr-att-rpt-loading-cancel" id="hr-att-rpt-loading-cancel">إلغاء</button>
        </div>
    </div>

    <div id="hr-att-rpt-results">
    <?php if ($showReport && $report !== null): ?>
        <div class="report-sales-result report-sales-print-area hr-att-rpt-doc">
            <?= document_print_header_html($reportTitle, $pdo) ?>

            <div class="hr-att-rpt-period-title">
                <span dir="ltr"><?= esc($periodFromDmY) ?></span>
                الى
                <span dir="ltr"><?= esc($periodToDmY) ?></span>
            </div>

            <?php if (($report['employees'] ?? []) === []): ?>
                <p class="hr-att-rpt-empty muted">لا توجد بصمات مربوطة بموظفين في الفترة والفلاتر المحددة.</p>
            <?php else: ?>
                <?php foreach ($report['employees'] as $empBlock): ?>
                    <section class="hr-att-rpt-employee-sheet">
                        <div class="hr-att-rpt-employee-meta">
                            <div><strong>القسم:</strong> <?= esc((string) ($empBlock['dept_name'] ?? '—')) ?></div>
                            <div>
                                <strong>الموظف:</strong>
                                <?php if (trim((string) ($empBlock['emp_code'] ?? '')) !== ''): ?>
                                    <span dir="ltr"><?= esc((string) $empBlock['emp_code']) ?></span> —
                                <?php endif; ?>
                                <?= esc((string) ($empBlock['emp_name'] ?? '—')) ?>
                            </div>
                        </div>

                        <?php $monthBlocks = hr_employee_attendance_report_month_blocks($empBlock['days'] ?? []); ?>
                        <?php foreach ($monthBlocks as $monthBlock): ?>
                        <div class="hr-att-rpt-month-block">
                            <h4 class="hr-att-rpt-month-title">
                                شهر: <span dir="ltr"><?= esc((string) ($monthBlock['month_label'] ?? '')) ?></span>
                            </h4>

                        <div class="hr-att-rpt-table-wrap">
                            <table class="hr-att-rpt-movement-table" dir="rtl">
                                <thead>
                                <tr>
                                    <th class="col-idx"></th>
                                    <th class="col-day">اليوم</th>
                                    <th class="col-date">التاريخ</th>
                                    <th class="col-shift">الشفت</th>
                                    <th class="col-time">بداية الشفت</th>
                                    <th class="col-time">نهاية الشفت</th>
                                    <th class="col-time">التأخير الصباحي</th>
                                    <th class="col-time">التأخير المسائي</th>
                                    <th class="col-time">بداية المغادرة</th>
                                    <th class="col-time">نهاية المغادرة</th>
                                    <th class="col-time">دخول</th>
                                    <th class="col-time">خروج</th>
                                    <th class="col-time">العمل الإضافي</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($monthBlock['days'] as $dayRow): ?>
                                    <?php
                                    $rowClass = [];
                                    if (!empty($dayRow['is_weekend'])) {
                                        $rowClass[] = 'is-weekend';
                                    }
                                    if (!empty($dayRow['is_holiday'])) {
                                        $rowClass[] = 'is-holiday';
                                    }
                                    if (!empty($dayRow['is_status_row'])) {
                                        $rowClass[] = 'is-status';
                                    }
                                    ?>
                                    <tr class="<?= esc(implode(' ', $rowClass)) ?>">
                                        <td class="col-idx num" dir="ltr"><?= (int) ($dayRow['day_index'] ?? 0) ?></td>
                                        <td class="col-day"><?= esc((string) ($dayRow['day_name'] ?? '')) ?></td>
                                        <td class="col-date" dir="ltr"><?= esc((string) ($dayRow['work_date'] ?? '')) ?></td>
                                        <td class="col-shift <?= !empty($dayRow['is_status_row']) ? 'is-red' : '' ?>">
                                            <?= esc((string) ($dayRow['shift_label'] ?? '')) ?>
                                        </td>
                                        <td class="col-time" dir="ltr"><?= esc(hr_attendance_report_format_time_cell($dayRow['shift_start'] ?? null)) ?></td>
                                        <td class="col-time" dir="ltr"><?= esc(hr_attendance_report_format_time_cell($dayRow['shift_end'] ?? null)) ?></td>
                                        <td class="col-time" dir="ltr"><?= esc(hr_attendance_report_format_time_cell($dayRow['morning_delay'] ?? null)) ?></td>
                                        <td class="col-time" dir="ltr"><?= esc(hr_attendance_report_format_time_cell($dayRow['evening_delay'] ?? null)) ?></td>
                                        <td class="col-time" dir="ltr"><?= esc(hr_attendance_report_format_time_cell($dayRow['leave_start'] ?? null)) ?></td>
                                        <td class="col-time" dir="ltr"><?= esc(hr_attendance_report_format_time_cell($dayRow['leave_end'] ?? null)) ?></td>
                                        <td class="col-time" dir="ltr"><?= esc(hr_attendance_report_format_time_cell($dayRow['entry_time'] ?? null)) ?></td>
                                        <td class="col-time" dir="ltr"><?= esc(hr_attendance_report_format_time_cell($dayRow['exit_time'] ?? null)) ?></td>
                                        <td class="col-time" dir="ltr"><?= esc(hr_attendance_report_format_time_cell($dayRow['overtime'] ?? null)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        </div>
                        <?php endforeach; ?>
                    </section>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if (($report['employees'] ?? []) !== []): ?>
                <?php $printUserLabel = document_print_user_label(); ?>
                <?php if ($printUserLabel !== ''): ?>
                    <footer class="hr-att-rpt-static-footer doc-print-only" aria-hidden="true">
                        <div class="hr-att-rpt-static-footer-line" aria-hidden="true"></div>
                        <div class="hr-att-rpt-static-footer-text">طبع بواسطة: <?= esc($printUserLabel) ?></div>
                    </footer>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    </div>
</div>
