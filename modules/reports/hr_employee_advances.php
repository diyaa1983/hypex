<?php
declare(strict_types=1);

require_once app_path('includes/hr_employee_advances_report.php');
require_once app_path('includes/document_header.php');
require_once app_path('includes/nav_helpers.php');
require_once app_path('includes/acc_period_lock.php');

$pdo = db();
hr_employee_ensure_schema($pdo);

$monthNames = acc_period_month_names_ar();
$filterYear = (int) ($_GET['year'] ?? (int) date('Y'));
$filterMonth = (int) ($_GET['month'] ?? 0);
$departmentId = (int) ($_GET['department_id'] ?? 0);
$employeeId = (int) ($_GET['employee_id'] ?? 0);
$showReport = !empty($_GET['show']);

if ($filterYear < 2000 || $filterYear > 2100) {
    $filterYear = (int) date('Y');
}
if ($filterMonth < 0 || $filterMonth > 12) {
    $filterMonth = 0;
}

$departments = hr_employee_advances_report_department_options($pdo);
$employees = hr_employee_advances_report_employee_options($pdo);

$report = null;
$err = '';

if ($showReport) {
    $report = hr_employee_advances_report_build($pdo, $filterYear, $filterMonth, $departmentId, $employeeId);
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

$periodLabel = $filterMonth >= 1 && $filterMonth <= 12
    ? hr_employee_advances_report_month_label($filterYear, $filterMonth)
    : ('جميع أشهر ' . (string) $filterYear);

$reportTitle = 'تقرير سلف الموظفين';
$reportDate = date('Y-m-d');
$exitUrl = nav_exit_url('report_hr_employee_advances');

$cssPath = app_path('assets/css/hr-employee-advances-report.css');
$cssUrl = app_url('assets/css/hr-employee-advances-report.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$reportSalesCssUrl = document_print_stylesheet_url('assets/css/report-sales.css');
$docCssUrl = document_print_stylesheet_url('assets/css/document-header.css');
?>
<link rel="stylesheet" href="<?= esc($docCssUrl) ?>">
<link rel="stylesheet" href="<?= esc($reportSalesCssUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<style><?= document_print_header_css() ?></style>

<div class="card report-sales-page hr-adv-rpt-page" data-exit-url="<?= esc($exitUrl) ?>">

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print hr-adv-rpt-filters">
        <input type="hidden" name="r" value="report_hr_employee_advances">
        <input type="hidden" name="show" value="1">
        <div class="form-row">
            <label class="field">
                <span class="field-label">السنة</span>
                <input class="input hr-adv-rpt-year" type="number" name="year" min="2000" max="2100"
                       value="<?= $filterYear ?>" dir="ltr" required>
            </label>
            <label class="field">
                <span class="field-label">الشهر</span>
                <select class="input" name="month" id="hr-adv-rpt-month">
                    <option value="0" <?= $filterMonth === 0 ? 'selected' : '' ?>>جميع الأشهر</option>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $filterMonth === $m ? 'selected' : '' ?>>
                            <?= sprintf('%02d', $m) ?> — <?= esc($monthNames[$m] ?? (string) $m) ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </label>
            <label class="field">
                <span class="field-label">القسم</span>
                <select class="input" name="department_id" id="hr-adv-rpt-dept">
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
                <select class="input" name="employee_id" id="hr-adv-rpt-employee">
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
        <div style="margin-top:0.5rem;">
            <button class="btn btn-primary" type="submit">عرض التقرير</button>
        </div>
    </form>

    <?php if ($err !== ''): ?>
        <div class="alert alert-error no-print"><?= esc($err) ?></div>
    <?php endif; ?>

    <?php if ($showReport && $err === '' && $report !== null): ?>
        <div class="report-sales-result report-sales-print-area hr-adv-rpt-doc">
            <?= document_print_header_html($reportTitle, $pdo) ?>

            <div class="doc-print-meta">
                <table>
                    <tr>
                        <td>
                            <strong>الفترة:</strong>
                            <span dir="ltr"><?= esc($periodLabel) ?></span>
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
                            <strong>عدد السلف:</strong> <?= (int) ($report['advance_count'] ?? 0) ?>
                        </td>
                    </tr>
                </table>
            </div>

            <?php if (($report['months'] ?? []) === []): ?>
                <p class="hr-adv-rpt-empty muted">لا توجد سلف مطابقة للفترة والفلاتر المحددة.</p>
            <?php endif; ?>

            <?php foreach ($report['months'] as $monthBlock): ?>
                <section class="hr-adv-rpt-month-block">
                    <h2 class="hr-adv-rpt-month-title">
                        شهر: <span dir="ltr"><?= esc((string) ($monthBlock['month_label'] ?? '')) ?></span>
                        <span class="hr-adv-rpt-month-meta">
                            (<?= (int) ($monthBlock['advance_count'] ?? 0) ?> سلفة)
                        </span>
                    </h2>

                    <?php foreach ($monthBlock['departments'] as $deptBlock): ?>
                        <section class="hr-adv-rpt-dept-block">
                            <h3 class="hr-adv-rpt-dept-title">القسم: <?= esc((string) $deptBlock['dept_name']) ?></h3>

                            <table class="hr-adv-rpt-table report-sales-table">
                                <colgroup>
                                    <col class="col-seq">
                                    <col class="hr-adv-rpt-col-emp-code">
                                    <col class="hr-adv-rpt-col-emp-name">
                                    <col class="hr-adv-rpt-col-code">
                                    <col class="hr-adv-rpt-col-type">
                                    <col class="col-date">
                                    <col class="col-money">
                                    <col class="hr-adv-rpt-col-status">
                                    <col class="hr-adv-rpt-col-disb">
                                    <col class="hr-adv-rpt-col-notes">
                                </colgroup>
                                <thead>
                                <tr>
                                    <th class="col-seq">#</th>
                                    <th class="hr-adv-rpt-col-emp-code">رقم الموظف</th>
                                    <th class="hr-adv-rpt-col-emp-name">اسم الموظف</th>
                                    <th class="hr-adv-rpt-col-code">رقم السلفة</th>
                                    <th class="hr-adv-rpt-col-type">نوع السلفة</th>
                                    <th class="col-date">تاريخ السلفة</th>
                                    <th class="col-money">المبلغ</th>
                                    <th class="hr-adv-rpt-col-status">ترحيل الشؤون</th>
                                    <th class="hr-adv-rpt-col-disb">صرف المحاسبة</th>
                                    <th class="hr-adv-rpt-col-notes">ملاحظات</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php
                                $deptSeq = 0;
                                foreach ($deptBlock['employees'] as $empBlock):
                                    $empLabelRow = trim((string) ($empBlock['emp_code'] ?? '') . ' — ' . (string) ($empBlock['emp_name'] ?? ''));
                                    foreach ($empBlock['advances'] as $adv):
                                        $deptSeq++;
                                ?>
                                    <tr>
                                        <td class="col-seq"><?= $deptSeq ?></td>
                                        <td class="hr-adv-rpt-col-emp-code" dir="ltr"><?= esc((string) ($empBlock['emp_code'] ?? '—')) ?></td>
                                        <td class="hr-adv-rpt-col-emp-name"><?= esc((string) ($empBlock['emp_name'] ?? '—')) ?></td>
                                        <td class="hr-adv-rpt-col-code" dir="ltr"><?= esc((string) ($adv['advance_code'] ?? '')) ?></td>
                                        <td class="hr-adv-rpt-col-type"><?= esc((string) ($adv['advance_type_label'] ?? '')) ?></td>
                                        <td class="col-date" dir="ltr"><?= esc((string) ($adv['advance_date_display'] ?? '')) ?></td>
                                        <td class="col-money num" dir="ltr"><?= esc(format_money((float) ($adv['amount'] ?? 0))) ?></td>
                                        <td class="hr-adv-rpt-col-status"><?= esc((string) ($adv['hr_status_label'] ?? '')) ?></td>
                                        <td class="hr-adv-rpt-col-disb"><?= esc((string) ($adv['disbursement_label'] ?? '')) ?></td>
                                        <td class="hr-adv-rpt-col-notes"><?= esc((string) (($adv['notes'] ?? '') !== '' ? $adv['notes'] : '—')) ?></td>
                                    </tr>
                                <?php
                                    endforeach;
                                ?>
                                    <tr class="hr-adv-rpt-emp-sum">
                                        <td colspan="6"><strong>مجموع الموظف: <?= esc($empLabelRow !== '' ? $empLabelRow : '—') ?></strong></td>
                                        <td class="col-money num" dir="ltr"><strong><?= esc(format_money((float) ($empBlock['total'] ?? 0))) ?></strong></td>
                                        <td colspan="3"></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>

                            <table class="hr-adv-rpt-dept-sum-table">
                                <tbody>
                                <tr>
                                    <th>مجموع القسم: <?= esc((string) $deptBlock['dept_name']) ?></th>
                                    <td dir="ltr" class="num"><?= esc(format_money((float) ($deptBlock['total'] ?? 0))) ?></td>
                                </tr>
                                </tbody>
                            </table>
                        </section>
                    <?php endforeach; ?>

                    <table class="hr-adv-rpt-month-sum-table">
                        <tbody>
                        <tr>
                            <th>مجموع شهر <span dir="ltr"><?= esc((string) ($monthBlock['month_label'] ?? '')) ?></span></th>
                            <td dir="ltr" class="num"><?= esc(format_money((float) ($monthBlock['total'] ?? 0))) ?></td>
                        </tr>
                        </tbody>
                    </table>
                </section>
            <?php endforeach; ?>

            <?php if (($report['months'] ?? []) !== []): ?>
                <footer class="hr-adv-rpt-grand">
                    <table class="hr-adv-rpt-grand-table">
                        <tbody>
                        <tr>
                            <th>المجموع الكلي للسلف</th>
                            <td dir="ltr" class="num"><?= esc(format_money((float) ($report['grand_total'] ?? 0))) ?></td>
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
  var deptSel = document.getElementById('hr-adv-rpt-dept');
  var empSel = document.getElementById('hr-adv-rpt-employee');
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
