<?php
declare(strict_types=1);

require_once app_path('includes/hr_employee_leave_balances_report.php');
require_once app_path('includes/document_header.php');

$pdo = db();
hr_employee_ensure_schema($pdo);

$defaultPeriod = hr_employee_leave_balance_default_period();
$dateFrom = parse_date_to_iso(trim((string) ($_GET['date_from'] ?? ''))) ?? $defaultPeriod['from'];
$dateTo = parse_date_to_iso(trim((string) ($_GET['date_to'] ?? ''))) ?? $defaultPeriod['to'];
if ($dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$departmentId = (int) ($_GET['department_id'] ?? 0);
$leaveTypeId = (int) ($_GET['leave_type_id'] ?? 0);
$employeeId = (int) ($_GET['employee_id'] ?? 0);
$showReport = !empty($_GET['show']);

$departments = hr_employee_leave_balances_report_department_options($pdo);
$leaveTypes = hr_employee_leave_balances_report_leave_type_options($pdo);
$employees = hr_employee_leave_balances_report_employee_options($pdo);

$report = null;
if ($showReport) {
    $report = hr_employee_leave_balances_report_build(
        $pdo,
        $dateFrom,
        $dateTo,
        $departmentId,
        $leaveTypeId,
        $employeeId
    );
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

$typeLabel = 'جميع الإجازات';
if ($leaveTypeId > 0) {
    foreach ($leaveTypes as $t) {
        if ((int) $t['id'] === $leaveTypeId) {
            $typeLabel = (string) $t['name_ar'];
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

$reportTitle = 'تقرير أرصدة الإجازات لجميع الموظفين';
$reportDate = date('Y-m-d');
$exitUrl = nav_exit_url('report_hr_employee_leave_balances');

$cssPath = app_path('assets/css/hr-employee-leave-departure-report.css');
$cssUrl = app_url('assets/css/hr-employee-leave-departure-report.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$reportSalesCssUrl = document_print_stylesheet_url('assets/css/report-sales.css');
$docCssUrl = document_print_stylesheet_url('assets/css/document-header.css');
?>
<link rel="stylesheet" href="<?= esc($docCssUrl) ?>">
<link rel="stylesheet" href="<?= esc($reportSalesCssUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<style><?= document_print_header_css() ?></style>

<div class="card report-sales-page hr-ld-rpt-page hr-leave-bal-rpt-page"
     data-report-route="report_hr_employee_leave_balances"
     data-exit-url="<?= esc($exitUrl) ?>"
     <?= $departmentId === 0 && $employeeId === 0 ? 'data-print-mode="all-depts"' : '' ?>>

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print hr-ld-rpt-filters">
        <input type="hidden" name="r" value="report_hr_employee_leave_balances">
        <input type="hidden" name="show" value="1">
        <div class="form-row">
            <label class="field">
                <span class="field-label">القسم</span>
                <select class="input" name="department_id" id="hr-leave-bal-rpt-dept">
                    <option value="0" <?= $departmentId === 0 ? 'selected' : '' ?>>جميع الأقسام</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= (int) $d['id'] ?>" <?= $departmentId === (int) $d['id'] ? 'selected' : '' ?>>
                            <?= esc((string) $d['name_ar']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span class="field-label">نوع الإجازة</span>
                <select class="input" name="leave_type_id">
                    <option value="0" <?= $leaveTypeId === 0 ? 'selected' : '' ?>>جميع الإجازات</option>
                    <?php foreach ($leaveTypes as $t): ?>
                        <option value="<?= (int) $t['id'] ?>" <?= $leaveTypeId === (int) $t['id'] ? 'selected' : '' ?>>
                            <?= esc((string) $t['name_ar']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span class="field-label">الموظف</span>
                <select class="input" name="employee_id" id="hr-leave-bal-rpt-employee">
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
            <label class="field">
                <span class="field-label">من تاريخ</span>
                <input class="input js-date-dmy" type="text" name="date_from"
                       value="<?= esc(format_date_dmY($dateFrom)) ?>" dir="ltr" autocomplete="off" required>
            </label>
            <label class="field">
                <span class="field-label">إلى تاريخ</span>
                <input class="input js-date-dmy" type="text" name="date_to"
                       value="<?= esc(format_date_dmY($dateTo)) ?>" dir="ltr" autocomplete="off" required>
            </label>
        </div>
        <div style="margin-top:0.5rem;">
            <button class="btn btn-primary" type="submit">عرض التقرير</button>
        </div>
    </form>

    <?php if ($showReport && $report !== null): ?>
        <?php
        $employeeCount = (int) ($report['employee_count'] ?? 0);
        ?>
        <div class="report-sales-result report-sales-print-area hr-ld-rpt-doc">
            <?= document_print_header_html($reportTitle, $pdo) ?>
            <?php hr_employee_leave_balances_report_render_meta(
                $dateFrom,
                $dateTo,
                $reportDate,
                $deptLabel,
                $typeLabel,
                $empLabel,
                $employeeCount
            ); ?>

            <?php if (($report['departments'] ?? []) === []): ?>
                <p class="hr-ld-rpt-empty muted">لا توجد أرصدة إجازات مطابقة للفترة والفلاتر المحددة.</p>
            <?php else: ?>
                <div class="hr-ld-rpt-kpis no-print">
                    <div class="hr-ld-rpt-kpi">
                        <span class="hr-ld-rpt-kpi__label">عدد الأقسام</span>
                        <span class="hr-ld-rpt-kpi__value" dir="ltr"><?= count($report['departments']) ?></span>
                    </div>
                    <div class="hr-ld-rpt-kpi">
                        <span class="hr-ld-rpt-kpi__label">عدد الموظفين</span>
                        <span class="hr-ld-rpt-kpi__value" dir="ltr"><?= (int) ($report['employee_count'] ?? 0) ?></span>
                    </div>
                </div>

                <?php foreach ($report['departments'] as $deptBlock): ?>
                    <section class="hr-ld-rpt-dept-section hr-leave-bal-rpt-dept-section<?= $departmentId === 0 && $employeeId === 0 ? ' hr-leave-bal-rpt-dept-section--print-page' : '' ?>">
                        <h3 class="hr-ld-rpt-dept-heading">
                            القسم: <?= esc((string) ($deptBlock['dept_name'] ?? '—')) ?>
                            <span class="hr-ld-rpt-dept-meta">(<?= (int) ($deptBlock['employee_count'] ?? 0) ?> موظف)</span>
                        </h3>

                        <?php hr_employee_leave_balances_report_render_dept_table($deptBlock); ?>
                    </section>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script>
(function () {
  'use strict';
  var deptSel = document.getElementById('hr-leave-bal-rpt-dept');
  var empSel = document.getElementById('hr-leave-bal-rpt-employee');
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
