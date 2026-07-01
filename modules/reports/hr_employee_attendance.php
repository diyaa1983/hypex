<?php
declare(strict_types=1);

require_once app_path('includes/hr_employee_attendance_report.php');
require_once app_path('includes/document_header.php');
require_once app_path('includes/nav_helpers.php');

$pdo = db();
hr_attendance_ensure_schema($pdo);

$dateFrom = parse_date_to_iso(trim((string) ($_GET['date_from'] ?? ''))) ?? app_default_date_from();
$dateTo = parse_date_to_iso(trim((string) ($_GET['date_to'] ?? ''))) ?? app_default_date_to();
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

$periodFromDmY = format_date_dmY($dateFrom);
$periodToDmY = format_date_dmY($dateTo);
?>
<link rel="stylesheet" href="<?= esc($docCssUrl) ?>">
<link rel="stylesheet" href="<?= esc($reportSalesCssUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<style><?= document_print_header_css() ?></style>

<div class="card report-sales-page hr-att-rpt-page" data-exit-url="<?= esc($exitUrl) ?>">

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print hr-att-rpt-filters">
        <input type="hidden" name="r" value="report_hr_employee_attendance">
        <input type="hidden" name="show" value="1">
        <div class="form-row">
            <label class="field">
                <span class="field-label">من تاريخ</span>
                <input class="input js-date-dmy" type="text" name="date_from"
                       value="<?= esc($periodFromDmY) ?>" dir="ltr" autocomplete="off" required>
            </label>
            <label class="field">
                <span class="field-label">إلى تاريخ</span>
                <input class="input js-date-dmy" type="text" name="date_to"
                       value="<?= esc($periodToDmY) ?>" dir="ltr" autocomplete="off" required>
            </label>
            <label class="field">
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
            <label class="field">
                <span class="field-label">الموظف</span>
                <select class="input" name="employee_id" id="hr-att-rpt-employee">
                    <option value="0" <?= $employeeId === 0 ? 'selected' : '' ?>>جميع الموظفين</option>
                    <?php foreach ($employees as $e): ?>
                        <option value="<?= (int) $e['id'] ?>"
                                data-dept-id="<?= (int) ($e['department_id'] ?? 0) ?>"
                            <?= $employeeId === (int) $e['id'] ? 'selected' : '' ?>>
                            <?= esc(trim((string) ($e['emp_code'] ?? '') . ' — ' . (string) ($e['name_ar'] ?? ''))) ?>
                            (<?= esc((string) ($e['dept_name'] ?? '')) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <p class="hr-att-rpt-filter-hint muted no-print">
            الشفت الافتراضي: <strong><?= esc((string) ($shiftSettings['label'] ?? 'A (07:00-15:00)')) ?></strong>
            — عطلة نهاية الأسبوع: الجمعة والسبت.
            لعرض تفصيل يومي كامل يُفضّل اختيار موظف واحد.
        </p>
        <div style="margin-top:0.5rem;">
            <button class="btn btn-primary" type="submit">عرض التقرير</button>
        </div>
    </form>

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

                        <div class="hr-att-rpt-table-wrap">
                            <table class="hr-att-rpt-movement-table">
                                <thead>
                                <tr>
                                    <th class="col-idx"></th>
                                    <th>اليوم</th>
                                    <th>التاريخ</th>
                                    <th>الشفت</th>
                                    <th>بداية الشفت</th>
                                    <th>نهاية الشفت</th>
                                    <th>التأخير الصباحي</th>
                                    <th>التأخير المسائي</th>
                                    <th>بداية المغادرة</th>
                                    <th>نهاية المغادرة</th>
                                    <th>دخول</th>
                                    <th>خروج</th>
                                    <th>العمل الإضافي</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($empBlock['days'] as $dayRow): ?>
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
                                        <td><?= esc((string) ($dayRow['day_name'] ?? '')) ?></td>
                                        <td dir="ltr"><?= esc((string) ($dayRow['work_date'] ?? '')) ?></td>
                                        <td class="col-shift <?= !empty($dayRow['is_status_row']) ? 'is-red' : '' ?>">
                                            <?= esc((string) ($dayRow['shift_label'] ?? '')) ?>
                                        </td>
                                        <td dir="ltr"><?= esc(hr_attendance_report_format_time_cell($dayRow['shift_start'] ?? null)) ?></td>
                                        <td dir="ltr"><?= esc(hr_attendance_report_format_time_cell($dayRow['shift_end'] ?? null)) ?></td>
                                        <td dir="ltr"><?= esc(hr_attendance_report_format_time_cell($dayRow['morning_delay'] ?? null)) ?></td>
                                        <td dir="ltr"><?= esc(hr_attendance_report_format_time_cell($dayRow['evening_delay'] ?? null)) ?></td>
                                        <td dir="ltr"><?= esc(hr_attendance_report_format_time_cell($dayRow['leave_start'] ?? null)) ?></td>
                                        <td dir="ltr"><?= esc(hr_attendance_report_format_time_cell($dayRow['leave_end'] ?? null)) ?></td>
                                        <td dir="ltr"><?= esc(hr_attendance_report_format_time_cell($dayRow['entry_time'] ?? null)) ?></td>
                                        <td dir="ltr"><?= esc(hr_attendance_report_format_time_cell($dayRow['exit_time'] ?? null)) ?></td>
                                        <td dir="ltr"><?= esc(hr_attendance_report_format_time_cell($dayRow['overtime'] ?? null)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script>
(function () {
  'use strict';
  var deptSel = document.getElementById('hr-att-rpt-dept');
  var empSel = document.getElementById('hr-att-rpt-employee');
  if (!deptSel || !empSel) return;

  var allOpts = Array.prototype.slice.call(empSel.options).map(function (opt) {
    return {
      value: opt.value,
      text: opt.textContent,
      deptId: opt.getAttribute('data-dept-id') || '0',
      selected: opt.selected
    };
  });

  function rebuildEmployeeOptions() {
    var deptId = parseInt(deptSel.value || '0', 10) || 0;
    var prev = empSel.value;
    empSel.innerHTML = '';
    allOpts.forEach(function (item) {
      if (parseInt(item.value || '0', 10) === 0) {
        var allOpt = document.createElement('option');
        allOpt.value = '0';
        allOpt.textContent = item.text;
        empSel.appendChild(allOpt);
        return;
      }
      var optDept = parseInt(item.deptId || '0', 10) || 0;
      if (deptId < 1 || optDept === deptId) {
        var opt = document.createElement('option');
        opt.value = item.value;
        opt.textContent = item.text;
        opt.setAttribute('data-dept-id', item.deptId);
        empSel.appendChild(opt);
      }
    });
    var stillThere = Array.prototype.some.call(empSel.options, function (opt) {
      return opt.value === prev;
    });
    empSel.value = stillThere ? prev : '0';
  }

  deptSel.addEventListener('change', rebuildEmployeeOptions);
  rebuildEmployeeOptions();
})();
</script>
