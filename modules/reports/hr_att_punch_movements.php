<?php
declare(strict_types=1);

require_once app_path('includes/hr_att_punch_report.php');
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
if ($showReport) {
    $report = hr_att_punch_report_build($pdo, $dateFrom, $dateTo, $departmentId, $employeeId);
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
if ($employeeId > 0) {
    foreach ($employees as $e) {
        if ((int) $e['id'] === $employeeId) {
            $empLabel = trim((string) ($e['emp_code'] ?? '')) !== ''
                ? (string) $e['emp_code'] . ' — ' . (string) $e['name_ar']
                : (string) $e['name_ar'];
            break;
        }
    }
}

$reportTitle = 'تقرير حركات البصمات';
$exitUrl = nav_exit_url('report_hr_att_punch_movements');

$cssPath = app_path('assets/css/hr-att-punch-report.css');
$cssUrl = app_url('assets/css/hr-att-punch-report.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$reportSalesCssUrl = document_print_stylesheet_url('assets/css/report-sales.css');
$docCssUrl = document_print_stylesheet_url('assets/css/document-header.css');

$periodFromDmY = format_date_dmY($dateFrom);
$periodToDmY = format_date_dmY($dateTo);
?>
<link rel="stylesheet" href="<?= esc($docCssUrl) ?>">
<link rel="stylesheet" href="<?= esc($reportSalesCssUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<?php employee_picker_enqueue_assets(); ?>
<script type="application/json" id="hr-att-punch-rpt-picker-json"><?= hr_employee_attendance_report_picker_json($employees) ?></script>
<style><?= document_print_header_css() ?></style>

<div class="card report-sales-page hr-att-punch-rpt-page" data-exit-url="<?= esc($exitUrl) ?>">

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters report-sales-filters--inline no-print hr-att-punch-rpt-filters">
        <input type="hidden" name="r" value="report_hr_att_punch_movements">
        <input type="hidden" name="show" value="1">
        <div class="report-sales-filters-row">
            <label class="field report-sales-filter-field report-sales-filter-field--date">
                <span class="field-label">من تاريخ</span>
                <input class="input js-date-dmy" type="text" name="date_from" value="<?= esc($periodFromDmY) ?>" dir="ltr" autocomplete="off" required>
            </label>
            <label class="field report-sales-filter-field report-sales-filter-field--date">
                <span class="field-label">إلى تاريخ</span>
                <input class="input js-date-dmy" type="text" name="date_to" value="<?= esc($periodToDmY) ?>" dir="ltr" autocomplete="off" required>
            </label>
            <label class="field report-sales-filter-field report-sales-filter-field--rep">
                <span class="field-label">القسم</span>
                <select class="input" name="department_id">
                    <option value="0" <?= $departmentId === 0 ? 'selected' : '' ?>>جميع الأقسام</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= (int) $d['id'] ?>" <?= $departmentId === (int) $d['id'] ? 'selected' : '' ?>>
                            <?= esc((string) $d['name_ar']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?= employee_picker_field([
                'id' => 'hr-att-punch-rpt-employee-id',
                'name' => 'employee_id',
                'label' => 'الموظف',
                'value' => $employeeId,
                'compact' => true,
                'allow_all' => true,
                'all_label' => 'جميع الموظفين',
                'placeholder' => 'جميع الموظفين',
                'manual_bind' => true,
                'wrapper_class' => 'field report-sales-filter-field report-sales-filter-field--customer',
            ]) ?>
            <div class="field report-sales-filter-field report-sales-filter-field--submit">
                <span class="field-label" aria-hidden="true">&nbsp;</span>
                <button class="btn btn-primary" type="submit">عرض التقرير</button>
            </div>
        </div>
        <p class="muted no-print hr-att-punch-rpt-hint">
            يعرض <strong>كل</strong> حركات البصمات المزامنة — بغض النظر عن الشفت أو جدول الدوام.
            يشمل المربوط وغير المربوط بموظف النظام.
        </p>
    </form>

    <?php if ($showReport && $report !== null): ?>
        <div class="report-sales-result report-sales-print-area hr-att-punch-rpt-doc">
            <?= document_print_header_html($reportTitle, $pdo) ?>

            <div class="hr-att-punch-rpt-meta">
                <div>الفترة: <span dir="ltr"><?= esc($periodFromDmY) ?></span> — <span dir="ltr"><?= esc($periodToDmY) ?></span></div>
                <div>القسم: <?= esc($deptLabel) ?> | الموظف: <?= esc($empLabel) ?></div>
                <div>
                    إجمالي الحركات: <strong><?= (int) $report['total'] ?></strong>
                    (مربوط: <?= (int) $report['linked'] ?>، غير مربوط: <?= (int) $report['unlinked'] ?>)
                </div>
                <?php if (!empty($report['truncated'])): ?>
                    <div class="hr-att-punch-rpt-trunc muted">
                        عُرض أول <?= (int) $report['limit'] ?> سجل — ضيّق الفترة أو اختر موظفاً لعرض الكل.
                    </div>
                <?php endif; ?>
            </div>

            <?php if (($report['rows'] ?? []) === []): ?>
                <p class="muted hr-att-punch-rpt-empty">لا توجد حركات بصمة في الفترة المحددة.</p>
            <?php else: ?>
                <div class="hr-att-punch-rpt-table-wrap">
                    <table class="hr-att-punch-rpt-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>التاريخ</th>
                                <th>الوقت</th>
                                <th>رقم الموظف</th>
                                <th>اسم الموظف / البصمة</th>
                                <th>رقم ZKT</th>
                                <th>رقم البصمة</th>
                                <th>النوع</th>
                                <th>التحقق</th>
                                <th>الجهاز</th>
                                <th>القسم</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $n = 0; foreach ($report['rows'] as $row): $n++; ?>
                                <?php $dt = hr_att_punch_report_format_datetime($row['punch_time'] ?? null); ?>
                                <tr class="<?= (int) ($row['employee_id'] ?? 0) < 1 ? 'is-unlinked' : '' ?>">
                                    <td><?= $n ?></td>
                                    <td dir="ltr"><?= esc($dt['date']) ?></td>
                                    <td dir="ltr"><?= esc($dt['time']) ?></td>
                                    <td dir="ltr"><?= esc(trim((string) ($row['emp_code'] ?? '')) ?: (string) ($row['badge_number'] ?? '—')) ?></td>
                                    <td><?= esc(hr_att_punch_report_employee_label($row)) ?></td>
                                    <td dir="ltr"><?= (int) ($row['zk_user_id'] ?? 0) ?></td>
                                    <td dir="ltr"><?= esc((string) ($row['badge_number'] ?? '—')) ?></td>
                                    <td><?= esc(hr_attendance_punch_type_label($row['punch_type'] ?? null)) ?></td>
                                    <td><?= esc(hr_attendance_verify_label(isset($row['verify_code']) ? (int) $row['verify_code'] : null)) ?></td>
                                    <td dir="ltr"><?= esc((string) ($row['sensor_id'] ?? '—')) ?></td>
                                    <td><?= esc(trim((string) ($row['dept_name'] ?? '')) ?: '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="report-sales-actions no-print">
            <button type="button" class="btn btn-secondary" onclick="window.print()">طباعة</button>
        </div>
    <?php endif; ?>

    <?php nav_render_screen_close('report_hr_att_punch_movements'); ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var jsonEl = document.getElementById('hr-att-punch-rpt-picker-json');
    if (jsonEl && typeof initEmployeePicker === 'function') {
        initEmployeePicker('hr-att-punch-rpt-employee-id', JSON.parse(jsonEl.textContent || '[]'));
    }
});
</script>
