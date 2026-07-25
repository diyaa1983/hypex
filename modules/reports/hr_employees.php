<?php
declare(strict_types=1);

require_once app_path('includes/hr_employees_report.php');
require_once app_path('includes/document_header.php');

$pdo = db();

$status = hr_employees_report_normalize_status(trim((string) ($_GET['status'] ?? 'all')));
$run = isset($_GET['run']) && (string) $_GET['run'] === '1';
$statusOptions = hr_employees_report_status_options();

$rows = [];
$showResult = false;
$totalSalary = 0.0;

if ($run) {
    $showResult = true;
    $rows = hr_employees_report_rows($pdo, $status);
    $totalSalary = hr_employees_report_total_salary($rows);
}

$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');

$reportTitle = hr_employees_report_title($status);

$pageDataAttrs = ' data-report-title="' . esc($reportTitle) . '"';
$pageDataAttrs .= ' data-report-route="report_hr_employees"';
if ($showResult) {
    $pageDataAttrs .= ' data-export-label="' . esc(hr_employees_report_status_label($status)) . '"';
}
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">

<div class="card report-sales-page report-hr-employees-page"<?= $pageDataAttrs ?>>

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print">
        <input type="hidden" name="r" value="report_hr_employees">
        <input type="hidden" name="run" value="1">
        <div class="form-row">
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

    <?php if ($showResult): ?>
        <div class="report-sales-result report-sales-print-area report-hr-employees-print">
            <?= document_print_header_html($reportTitle, $pdo) ?>

            <div class="doc-print-meta">
                <table>
                    <tr>
                        <td>
                            <strong>الحالة:</strong> <?= esc(hr_employees_report_status_label($status)) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>عدد الموظفين:</strong> <?= count($rows) ?>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="report-sales-table-wrap">
                <table class="report-sales-table report-hr-employees-table">
                    <colgroup>
                        <col class="col-seq">
                        <col class="col-inv-no">
                        <col class="col-customer-name">
                        <col class="col-date">
                        <col class="col-qty">
                        <?php if ($status === 'all'): ?>
                            <col class="col-status">
                        <?php endif; ?>
                    </colgroup>
                    <thead>
                    <tr>
                        <th class="col-seq">تسلسل</th>
                        <th class="col-inv-no">رقم الموظف</th>
                        <th class="col-customer-name">اسم الموظف</th>
                        <th class="col-date">تاريخ التعيين</th>
                        <th class="col-qty">الراتب</th>
                        <?php if ($status === 'all'): ?>
                            <th class="col-status">الحالة</th>
                        <?php endif; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="<?= $status === 'all' ? 6 : 5 ?>" class="muted" style="text-align:center;padding:1.25rem;">
                                لا يوجد موظفون مطابقون للفلتر.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td class="col-seq"><?= (int) ($r['seq'] ?? 0) ?></td>
                                <td class="col-inv-no" dir="ltr"><code><?= esc((string) ($r['emp_code'] ?? '—')) ?></code></td>
                                <td class="col-customer-name"><?= esc((string) ($r['name_ar'] ?? '')) ?></td>
                                <td class="col-date" dir="ltr"><?= esc((string) ($r['hire_date'] ?? '—')) ?></td>
                                <td class="col-qty num" dir="ltr"><?= esc(format_amount((float) ($r['salary'] ?? 0))) ?></td>
                                <?php if ($status === 'all'): ?>
                                    <td class="col-status"><?= esc((string) ($r['status_label'] ?? '')) ?></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                    <?php if ($rows): ?>
                        <tfoot>
                        <tr class="report-hr-employees-total">
                            <td colspan="4"><strong>المجموع</strong></td>
                            <td class="num" dir="ltr"><strong><?= esc(format_amount($totalSalary)) ?></strong></td>
                            <?php if ($status === 'all'): ?>
                                <td></td>
                            <?php endif; ?>
                        </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<div id="sales-inv-export-host" class="sales-inv-export-host" aria-hidden="true"></div>

<script src="<?= esc($exportJsUrl) ?>" defer></script>
