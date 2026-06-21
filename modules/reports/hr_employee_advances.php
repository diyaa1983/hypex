<?php
declare(strict_types=1);

require_once app_path('includes/hr_employee_advances_report.php');
require_once app_path('includes/document_header.php');
require_once app_path('includes/nav_helpers.php');

$pdo = db();
hr_employee_ensure_schema($pdo);

$fromRaw = trim((string) ($_GET['from'] ?? ''));
$toRaw = trim((string) ($_GET['to'] ?? ''));
$departmentId = (int) ($_GET['department_id'] ?? 0);
$employeeId = (int) ($_GET['employee_id'] ?? 0);
$showReport = !empty($_GET['show']);

if ($fromRaw === '') {
    $fromRaw = date('Y') . '-01-01';
}
if ($toRaw === '') {
    $toRaw = date('Y-m-d');
}

$departments = hr_employee_advances_report_department_options($pdo);
$employees = hr_employee_advances_report_employee_options($pdo);

$report = null;
$err = '';
$fromIso = '';
$toIso = '';
$fromDisplay = '';
$toDisplay = '';

if ($showReport) {
    $fromIso = parse_date_to_iso($fromRaw) ?? '';
    $toIso = parse_date_to_iso($toRaw) ?? '';
    if ($fromIso === '' || $toIso === '') {
        $err = 'تاريخ البداية والنهاية غير صالحين (يوم-شهر-سنة).';
    } elseif ($fromIso > $toIso) {
        $err = 'تاريخ البداية يجب أن يكون قبل أو يساوي تاريخ النهاية.';
    } else {
        $fromDisplay = format_date_dmY($fromIso);
        $toDisplay = format_date_dmY($toIso);
        $report = hr_employee_advances_report_build($pdo, $fromIso, $toIso, $departmentId, $employeeId);
    }
} else {
    $fromIso = parse_date_to_iso($fromRaw) ?? $fromRaw;
    $toIso = parse_date_to_iso($toRaw) ?? $toRaw;
    $fromDisplay = format_date_dmY($fromIso);
    $toDisplay = format_date_dmY($toIso);
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
                <span class="field-label">من تاريخ</span>
                <input class="input js-date-dmy" type="text" name="from" value="<?= esc($fromDisplay) ?>"
                       placeholder="يوم-شهر-سنة" dir="ltr" required>
            </label>
            <label class="field">
                <span class="field-label">إلى تاريخ</span>
                <input class="input js-date-dmy" type="text" name="to" value="<?= esc($toDisplay) ?>"
                       placeholder="يوم-شهر-سنة" dir="ltr" required>
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
                            <span dir="ltr"><?= esc($fromDisplay) ?></span>
                            —
                            <span dir="ltr"><?= esc($toDisplay) ?></span>
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

            <?php if (($report['departments'] ?? []) === []): ?>
                <p class="hr-adv-rpt-empty muted">لا توجد سلف مطابقة للفترة والفلاتر المحددة.</p>
            <?php endif; ?>

            <?php foreach ($report['departments'] as $deptBlock): ?>
                <section class="hr-adv-rpt-dept-block">
                    <h3 class="hr-adv-rpt-dept-title">القسم: <?= esc((string) $deptBlock['dept_name']) ?></h3>

                    <?php foreach ($deptBlock['employees'] as $empBlock): ?>
                        <div class="hr-adv-rpt-emp-block">
                            <h4 class="hr-adv-rpt-emp-title">
                                <?= esc(trim((string) ($empBlock['emp_code'] ?? '') . ' — ' . (string) ($empBlock['emp_name'] ?? ''))) ?>
                            </h4>
                            <table class="hr-adv-rpt-table report-sales-table">
                                <thead>
                                <tr>
                                    <th>ت</th>
                                    <th>رقم السلفة</th>
                                    <th>نوع السلفة</th>
                                    <th>تاريخ السلفة</th>
                                    <th>المبلغ</th>
                                    <th>ترحيل الشؤون</th>
                                    <th>صرف المحاسبة</th>
                                    <th>ملاحظات</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($empBlock['advances'] as $adv): ?>
                                    <tr>
                                        <td><?= (int) ($adv['seq'] ?? 0) ?></td>
                                        <td dir="ltr"><?= esc((string) ($adv['advance_code'] ?? '')) ?></td>
                                        <td><?= esc((string) ($adv['advance_type_label'] ?? '')) ?></td>
                                        <td dir="ltr"><?= esc((string) ($adv['advance_date_display'] ?? '')) ?></td>
                                        <td dir="ltr" class="num"><?= esc(format_money((float) ($adv['amount'] ?? 0))) ?></td>
                                        <td><?= esc((string) ($adv['hr_status_label'] ?? '')) ?></td>
                                        <td><?= esc((string) ($adv['disbursement_label'] ?? '')) ?></td>
                                        <td><?= esc((string) (($adv['notes'] ?? '') !== '' ? $adv['notes'] : '—')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                <tr class="hr-adv-rpt-emp-sum">
                                    <td colspan="4"><strong>مجموع الموظف</strong></td>
                                    <td dir="ltr" class="num"><strong><?= esc(format_money((float) ($empBlock['total'] ?? 0))) ?></strong></td>
                                    <td colspan="3"></td>
                                </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php endforeach; ?>

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

            <?php if (($report['departments'] ?? []) !== []): ?>
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
  var allOpts = Array.prototype.slice.call(empSel.options);
  function filterEmployees() {
    var deptId = parseInt(deptSel.value || '0', 10) || 0;
    empSel.innerHTML = '';
    allOpts.forEach(function (opt) {
      if (parseInt(opt.value || '0', 10) === 0) {
        empSel.appendChild(opt.cloneNode(true));
        return;
      }
      var optDept = parseInt(opt.getAttribute('data-dept-id') || '0', 10) || 0;
      if (deptId < 1 || optDept === deptId) {
        empSel.appendChild(opt.cloneNode(true));
      }
    });
  }
  deptSel.addEventListener('change', filterEmployees);
})();
</script>
