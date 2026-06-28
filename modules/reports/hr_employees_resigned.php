<?php
declare(strict_types=1);

require_once app_path('includes/hr_employees_resigned_report.php');
require_once app_path('includes/document_header.php');

$pdo = db();

$departmentId = (int) ($_GET['department_id'] ?? 0);
$run = isset($_GET['run']) && (string) $_GET['run'] === '1';
$departments = hr_department_active_list($pdo);

$rows = [];
$showResult = false;

if ($run) {
    $showResult = true;
    $rows = hr_employees_resigned_report_rows($pdo, $departmentId);
}

$deptLabel = 'جميع الأقسام';
if ($departmentId > 0) {
    foreach ($departments as $d) {
        if ((int) ($d['id'] ?? 0) === $departmentId) {
            $deptLabel = (string) ($d['name_ar'] ?? '');
            break;
        }
    }
}

$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');

$reportTitle = hr_employees_resigned_report_title();

$pageDataAttrs = ' data-report-title="' . esc($reportTitle) . '"';
$pageDataAttrs .= ' data-report-route="report_hr_employees_resigned"';
if ($showResult) {
    $pageDataAttrs .= ' data-export-label="' . esc($deptLabel) . '"';
}
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">

<div class="card report-sales-page report-hr-employees-resigned-page"<?= $pageDataAttrs ?>>

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print">
        <input type="hidden" name="r" value="report_hr_employees_resigned">
        <input type="hidden" name="run" value="1">
        <div class="form-row">
            <label class="field" style="flex:1 1 16rem;">
                <span class="field-label">القسم</span>
                <select class="input" name="department_id">
                    <option value="0" <?= $departmentId === 0 ? 'selected' : '' ?>>جميع الأقسام</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= (int) ($d['id'] ?? 0) ?>"
                            <?= $departmentId === (int) ($d['id'] ?? 0) ? 'selected' : '' ?>>
                            <?= esc((string) ($d['name_ar'] ?? '')) ?>
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
        <div class="report-sales-result report-sales-print-area report-hr-employees-resigned-print">
            <?= document_print_header_html($reportTitle, $pdo) ?>

            <div class="doc-print-meta">
                <table>
                    <tr>
                        <td>
                            <strong>القسم:</strong> <?= esc($deptLabel) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>عدد الموظفين المستقيلين:</strong> <?= count($rows) ?>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="report-sales-table-wrap">
                <table class="report-sales-table report-hr-employees-resigned-table">
                    <thead>
                    <tr>
                        <th class="col-seq">تسلسل</th>
                        <th class="col-inv-no">رقم الموظف</th>
                        <th class="col-customer-name">اسم الموظف</th>
                        <th class="col-dept">القسم</th>
                        <th class="col-job">المسمى الوظيفي</th>
                        <th class="col-date">تاريخ التعيين</th>
                        <th class="col-date">تاريخ الاستقالة</th>
                        <th class="col-status">ترحيل الاستقالة</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="8" class="muted" style="text-align:center;padding:1.25rem;">
                                لا يوجد موظفون مستقيلون مطابقون للفلتر.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td class="col-seq"><?= (int) ($r['seq'] ?? 0) ?></td>
                                <td class="col-inv-no" dir="ltr"><code><?= esc((string) ($r['emp_code'] ?? '—')) ?></code></td>
                                <td class="col-customer-name"><?= esc((string) ($r['name_ar'] ?? '')) ?></td>
                                <td class="col-dept"><?= esc((string) ($r['dept_name'] ?? '—')) ?></td>
                                <td class="col-job"><?= esc((string) ($r['job_title_name'] ?? '—')) ?></td>
                                <td class="col-date" dir="ltr"><?= esc((string) ($r['hire_date'] ?? '—')) ?></td>
                                <td class="col-date" dir="ltr"><?= esc((string) ($r['resignation_date'] ?? '—')) ?></td>
                                <td class="col-status"><?= esc((string) ($r['posted_label'] ?? '—')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                    <?php if ($rows): ?>
                        <tfoot>
                        <tr class="report-hr-employees-resigned-total">
                            <td colspan="7"><strong>الإجمالي</strong></td>
                            <td><strong><?= count($rows) ?></strong></td>
                        </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<div id="sales-inv-export-host" class="sales-inv-export-host" aria-hidden="true"></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" defer crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="<?= esc($exportJsUrl) ?>" defer></script>
