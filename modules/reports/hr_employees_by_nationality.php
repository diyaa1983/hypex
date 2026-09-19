<?php
declare(strict_types=1);

require_once app_path('includes/hr_employees_by_nationality_report.php');
require_once app_path('includes/document_header.php');

$pdo = db();

$status = hr_employees_report_normalize_status(trim((string) ($_GET['status'] ?? 'all')));
$nationalityId = (int) ($_GET['nationality_id'] ?? 0);
$run = isset($_GET['run']) && (string) $_GET['run'] === '1';
$statusOptions = hr_employees_report_status_options();
$nationalities = hr_nationality_active_list($pdo);

$report = null;
$showResult = false;

if ($run) {
    $showResult = true;
    $report = hr_employees_by_nationality_report_build($pdo, $status, $nationalityId);
}

$natLabel = 'جميع الجنسيات';
if ($nationalityId > 0) {
    foreach ($nationalities as $n) {
        if ((int) ($n['id'] ?? 0) === $nationalityId) {
            $natLabel = (string) ($n['name_ar'] ?? '');
            break;
        }
    }
}

$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$cssNatPath = app_path('assets/css/hr-employees-by-nat-report.css');
$cssNatUrl = app_url('assets/css/hr-employees-by-nat-report.css')
    . (is_file($cssNatPath) ? '?v=' . (string) filemtime($cssNatPath) : '');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');

$reportTitle = hr_employees_by_nationality_report_title($status);

$pageDataAttrs = ' data-report-title="' . esc($reportTitle) . '"';
$pageDataAttrs .= ' data-report-route="report_hr_employees_by_nationality"';
if ($showResult) {
    $pageDataAttrs .= ' data-export-label="' . esc(hr_employees_report_status_label($status)) . '"';
}
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssNatUrl) ?>">

<div class="card report-sales-page hr-emp-nat-rpt-page"<?= $pageDataAttrs ?>>

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print">
        <input type="hidden" name="r" value="report_hr_employees_by_nationality">
        <input type="hidden" name="run" value="1">
        <div class="form-row">
            <label class="field" style="flex:1 1 16rem;">
                <span class="field-label">الجنسية</span>
                <select class="input" name="nationality_id">
                    <option value="0" <?= $nationalityId === 0 ? 'selected' : '' ?>>جميع الجنسيات</option>
                    <?php foreach ($nationalities as $n): ?>
                        <option value="<?= (int) ($n['id'] ?? 0) ?>"
                            <?= $nationalityId === (int) ($n['id'] ?? 0) ? 'selected' : '' ?>>
                            <?= esc((string) ($n['name_ar'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field" style="flex:1 1 16rem;">
                <span class="field-label">الحالة</span>
                <select class="input" name="status">
                    <?php foreach ($statusOptions as $value => $label): ?>
                        <option value="<?= esc($value) ?>" <?= $status === $value ? 'selected' : '' ?>>
                            <?= esc($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <div style="margin-top:0.5rem;">
            <button class="btn btn-primary" type="submit">عرض التقرير</button>
        </div>
    </form>

    <?php if ($showResult && $report !== null): ?>
        <div class="report-sales-result report-sales-print-area hr-emp-nat-rpt-doc">
            <?= document_print_header_html($reportTitle, $pdo) ?>

            <div class="doc-print-meta">
                <table>
                    <tr>
                        <td>
                            <strong>الجنسية:</strong> <?= esc($natLabel) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>الحالة:</strong> <?= esc(hr_employees_report_status_label($status)) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>عدد الموظفين:</strong> <?= (int) ($report['grand']['employee_count'] ?? 0) ?>
                        </td>
                    </tr>
                </table>
            </div>

            <?php if (($report['nationalities'] ?? []) === []): ?>
                <p class="hr-emp-nat-rpt-empty muted">لا يوجد موظفون مطابقون للفلتر.</p>
            <?php endif; ?>

            <?php foreach ($report['nationalities'] as $block): ?>
                <section class="hr-emp-nat-rpt-block">
                    <h3 class="hr-emp-nat-rpt-nat-name">
                        الجنسية: <?= esc((string) ($block['nat_name'] ?? '')) ?>
                        <span class="hr-emp-nat-rpt-nat-meta">
                            (<?= (int) ($block['employee_count'] ?? 0) ?> موظف)
                        </span>
                    </h3>
                    <div class="report-sales-table-wrap">
                        <table class="report-sales-table hr-emp-nat-rpt-table">
                            <thead>
                            <tr>
                                <th class="col-seq">تسلسل</th>
                                <th class="col-inv-no">رقم الموظف</th>
                                <th class="col-customer-name">اسم الموظف</th>
                                <th class="hr-emp-nat-rpt-col-job">المسمى الوظيفي</th>
                                <th class="col-date">تاريخ التعيين</th>
                                <th class="col-qty">الراتب</th>
                                <?php if ($status === 'all'): ?>
                                    <th class="col-status">الحالة</th>
                                <?php endif; ?>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($block['rows'] as $row): ?>
                                <tr>
                                    <td class="col-seq"><?= (int) ($row['seq'] ?? 0) ?></td>
                                    <td class="col-inv-no" dir="ltr"><code><?= esc((string) ($row['emp_code'] ?? '—')) ?></code></td>
                                    <td class="col-customer-name"><?= esc((string) ($row['name_ar'] ?? '')) ?></td>
                                    <td class="hr-emp-nat-rpt-col-job"><?= esc((string) ($row['job_title_name'] ?? '—')) ?></td>
                                    <td class="col-date" dir="ltr"><?= esc((string) ($row['hire_date'] ?? '—')) ?></td>
                                    <td class="col-qty num" dir="ltr"><?= esc(format_amount((float) ($row['salary'] ?? 0))) ?></td>
                                    <?php if ($status === 'all'): ?>
                                        <td class="col-status"><?= esc((string) ($row['status_label'] ?? '')) ?></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                            <tr class="hr-emp-nat-rpt-sum">
                                <td colspan="5"><strong>مجموع الجنسية</strong></td>
                                <td class="num" dir="ltr"><strong><?= esc(format_amount((float) ($block['total_salary'] ?? 0))) ?></strong></td>
                                <?php if ($status === 'all'): ?>
                                    <td></td>
                                <?php endif; ?>
                            </tr>
                            </tfoot>
                        </table>
                    </div>
                </section>
            <?php endforeach; ?>

            <?php if ($report['nationalities']): ?>
                <footer class="hr-emp-nat-rpt-grand">
                    <table class="hr-emp-nat-rpt-grand-table">
                        <tbody>
                        <tr>
                            <th>إجمالي عدد الموظفين</th>
                            <td><?= (int) ($report['grand']['employee_count'] ?? 0) ?></td>
                        </tr>
                        <tr>
                            <th>إجمالي الرواتب</th>
                            <td class="num" dir="ltr"><?= esc(format_amount((float) ($report['grand']['total_salary'] ?? 0))) ?></td>
                        </tr>
                        </tbody>
                    </table>
                </footer>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<div id="sales-inv-export-host" class="sales-inv-export-host" aria-hidden="true"></div>

<script src="<?= esc($exportJsUrl) ?>" defer></script>
