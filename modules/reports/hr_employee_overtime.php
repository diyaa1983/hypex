<?php
declare(strict_types=1);

require_once app_path('includes/hr_employee_overtime_report.php');
require_once app_path('includes/document_header.php');
require_once app_path('includes/nav_helpers.php');

$pdo = db();
hr_employee_ensure_schema($pdo);

$dateFrom = parse_date_to_iso(trim((string) ($_GET['date_from'] ?? ''))) ?? app_default_date_from();
$dateTo = parse_date_to_iso(trim((string) ($_GET['date_to'] ?? ''))) ?? app_default_date_to();
if ($dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$departmentId = (int) ($_GET['department_id'] ?? 0);
$employeeId = (int) ($_GET['employee_id'] ?? 0);
$multiplierFilter = hr_employee_overtime_report_multiplier_filter_value((string) ($_GET['multiplier'] ?? ''));
$showReport = !empty($_GET['show']);

$config = hr_overtime_load_config($pdo);
$multiplierOptions = hr_overtime_multiplier_options($config);

$departments = hr_employee_overtime_report_department_options($pdo);
$employees = hr_employee_overtime_report_employee_options($pdo);

$report = null;
if ($showReport) {
    $report = hr_employee_overtime_report_build(
        $pdo,
        $dateFrom,
        $dateTo,
        $departmentId,
        $employeeId,
        $multiplierFilter
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

$multiplierLabel = 'جميع المضاعفات';
if ($multiplierFilter > 0.0005) {
    $multiplierLabel = hr_overtime_multiplier_report_short($multiplierFilter);
}

$reportTitle = 'تقرير العمل الإضافي';
$reportDate = date('Y-m-d');
$exitUrl = nav_exit_url('report_hr_employee_overtime');

$cssPath = app_path('assets/css/hr-employee-overtime-report.css');
$cssUrl = app_url('assets/css/hr-employee-overtime-report.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$reportSalesCssUrl = document_print_stylesheet_url('assets/css/report-sales.css');
$docCssUrl = document_print_stylesheet_url('assets/css/document-header.css');
?>
<link rel="stylesheet" href="<?= esc($docCssUrl) ?>">
<link rel="stylesheet" href="<?= esc($reportSalesCssUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<style><?= document_print_header_css() ?></style>

<div class="card report-sales-page hr-ot-rpt-page" data-exit-url="<?= esc($exitUrl) ?>">

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print hr-ot-rpt-filters">
        <input type="hidden" name="r" value="report_hr_employee_overtime">
        <input type="hidden" name="show" value="1">
        <div class="form-row">
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
            <label class="field">
                <span class="field-label">القسم</span>
                <select class="input" name="department_id" id="hr-ot-rpt-dept">
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
                <select class="input" name="employee_id" id="hr-ot-rpt-employee">
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
                <span class="field-label">احتساب الساعة</span>
                <select class="input" name="multiplier">
                    <option value="0" <?= $multiplierFilter <= 0.0005 ? 'selected' : '' ?>>جميع المضاعفات</option>
                    <?php foreach ($multiplierOptions as $opt): ?>
                        <?php $mVal = (float) ($opt['value'] ?? 0); ?>
                        <option value="<?= esc((string) $mVal) ?>"
                            <?= hr_overtime_multiplier_matches($multiplierFilter, $mVal) && $multiplierFilter > 0.0005 ? 'selected' : '' ?>>
                            <?= esc((string) ($opt['label'] ?? hr_overtime_multiplier_display($mVal))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <div style="margin-top:0.5rem;">
            <button class="btn btn-primary" type="submit">عرض التقرير</button>
        </div>
    </form>

    <?php if ($showReport && $report !== null): ?>
        <div class="report-sales-result report-sales-print-area hr-ot-rpt-doc">
            <?= document_print_header_html($reportTitle, $pdo) ?>

            <div class="doc-print-meta">
                <table>
                    <tr>
                        <td>
                            <strong>الفترة:</strong>
                            <span dir="ltr"><?= esc(format_date_dmY($dateFrom)) ?></span>
                            —
                            <span dir="ltr"><?= esc(format_date_dmY($dateTo)) ?></span>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>تاريخ التقرير:</strong>
                            <span dir="ltr"><?= esc(format_date_dmY($reportDate)) ?></span>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <strong>القسم:</strong> <?= esc($deptLabel) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>الموظف:</strong> <?= esc($empLabel) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>احتساب الساعة:</strong> <?= esc($multiplierLabel) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>عدد السجلات:</strong> <?= (int) ($report['row_count'] ?? 0) ?>
                        </td>
                    </tr>
                </table>
            </div>

            <?php if (($report['months'] ?? []) === []): ?>
                <p class="hr-ot-rpt-empty muted">لا توجد سجلات عمل إضافي مطابقة للفترة والفلاتر المحددة.</p>
            <?php else: ?>
                <?php
                $deptBlocks = hr_employee_overtime_report_departments($report);
                $multiMonth = count($report['months'] ?? []) > 1;
                $labelColspan = $multiMonth ? 4 : 3;
                ?>
                <div class="hr-ot-rpt-kpis no-print">
                    <div class="hr-ot-rpt-kpi">
                        <span class="hr-ot-rpt-kpi__label">عدد السجلات</span>
                        <span class="hr-ot-rpt-kpi__value" dir="ltr"><?= (int) ($report['row_count'] ?? 0) ?></span>
                    </div>
                    <div class="hr-ot-rpt-kpi">
                        <span class="hr-ot-rpt-kpi__label">إجمالي الساعات</span>
                        <span class="hr-ot-rpt-kpi__value" dir="ltr"><?= esc(number_format((float) ($report['grand_total_hours'] ?? 0), 3, '.', '')) ?></span>
                    </div>
                    <div class="hr-ot-rpt-kpi">
                        <span class="hr-ot-rpt-kpi__label">إجمالي المبلغ</span>
                        <span class="hr-ot-rpt-kpi__value" dir="ltr"><?= esc(format_money((float) ($report['grand_total_amount'] ?? 0))) ?></span>
                    </div>
                </div>

                <?php foreach ($deptBlocks as $deptBlock): ?>
                    <section class="hr-ot-rpt-dept-section">
                        <h3 class="hr-ot-rpt-dept-heading">
                            القسم: <?= esc((string) ($deptBlock['dept_name'] ?? '—')) ?>
                            <span class="hr-ot-rpt-dept-meta">(<?= (int) ($deptBlock['row_count'] ?? 0) ?> سجل)</span>
                        </h3>

                        <div class="hr-ot-rpt-table-wrap">
                            <table class="hr-ot-rpt-table report-sales-table">
                                <thead>
                                <tr>
                                    <th class="col-seq">#</th>
                                    <?php if ($multiMonth): ?>
                                        <th class="hr-ot-rpt-col-month">الشهر</th>
                                    <?php endif; ?>
                                    <th class="hr-ot-rpt-col-emp-code">رقم الموظف</th>
                                    <th class="hr-ot-rpt-col-emp-name">اسم الموظف</th>
                                    <th class="hr-ot-rpt-col-hours">الساعات</th>
                                    <th class="hr-ot-rpt-col-mult">المضاعف</th>
                                    <th class="hr-ot-rpt-col-money">المبلغ</th>
                                    <th class="hr-ot-rpt-col-notes hr-ot-rpt-col-notes-head">ملاحظات</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php $deptSeq = 0; ?>
                                <?php foreach ($deptBlock['rows'] as $row): ?>
                                    <?php $deptSeq++; ?>
                                    <tr>
                                        <td class="col-seq"><?= $deptSeq ?></td>
                                        <?php if ($multiMonth): ?>
                                            <td class="hr-ot-rpt-col-month" dir="ltr"><?= esc((string) ($row['month_label'] ?? '')) ?></td>
                                        <?php endif; ?>
                                        <td class="hr-ot-rpt-col-emp-code" dir="ltr"><?= esc((string) ($row['emp_code'] ?? '—')) ?></td>
                                        <td class="hr-ot-rpt-col-emp-name"><?= esc((string) ($row['emp_name'] ?? '—')) ?></td>
                                        <td class="hr-ot-rpt-col-hours num" dir="ltr"><?= esc(number_format((float) ($row['overtime_hours'] ?? 0), 3, '.', '')) ?></td>
                                        <td class="hr-ot-rpt-col-mult" dir="ltr"><?= esc((string) ($row['multiplier_label'] ?? '—')) ?></td>
                                        <td class="hr-ot-rpt-col-money num" dir="ltr"><?= esc(format_money((float) ($row['overtime_amount'] ?? 0))) ?></td>
                                        <td class="hr-ot-rpt-col-notes"><?= esc((string) (($row['notes'] ?? '') !== '' ? $row['notes'] : '—')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                <tr class="hr-ot-rpt-row--dept-total">
                                    <td colspan="<?= $labelColspan ?>" class="hr-ot-rpt-subtotal-label">
                                        مجموع القسم: <?= esc((string) ($deptBlock['dept_name'] ?? '—')) ?>
                                    </td>
                                    <td class="hr-ot-rpt-col-hours num" dir="ltr"><?= esc(number_format((float) ($deptBlock['total_hours'] ?? 0), 3, '.', '')) ?></td>
                                    <td></td>
                                    <td class="hr-ot-rpt-col-money num" dir="ltr"><?= esc(format_money((float) ($deptBlock['total_amount'] ?? 0))) ?></td>
                                    <td class="hr-ot-rpt-col-notes"></td>
                                </tr>
                                </tfoot>
                            </table>
                        </div>
                    </section>
                <?php endforeach; ?>

                <footer class="hr-ot-rpt-grand-wrap">
                    <table class="hr-ot-rpt-grand-table">
                        <tbody>
                        <tr>
                            <th class="hr-ot-rpt-grand-label">المجموع الكلي للعمل الإضافي</th>
                            <td class="num" dir="ltr"><?= esc(number_format((float) ($report['grand_total_hours'] ?? 0), 3, '.', '')) ?> ساعة</td>
                            <td class="num" dir="ltr"><?= esc(format_money((float) ($report['grand_total_amount'] ?? 0))) ?></td>
                        </tr>
                        </tbody>
                    </table>
                </footer>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script>
(function () {
  'use strict';
  var deptSel = document.getElementById('hr-ot-rpt-dept');
  var empSel = document.getElementById('hr-ot-rpt-employee');
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
