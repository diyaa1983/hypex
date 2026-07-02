<?php
declare(strict_types=1);

require_once app_path('includes/hr_employee_departures_report.php');
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
$departureTypeId = (int) ($_GET['departure_type_id'] ?? 0);
$showReport = !empty($_GET['show']);

$departments = hr_employee_departures_report_department_options($pdo);
$departureTypes = hr_employee_departures_report_departure_type_options($pdo);

$report = null;
if ($showReport) {
    $report = hr_employee_departures_report_build($pdo, $dateFrom, $dateTo, $departmentId, $departureTypeId);
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

$typeLabel = 'جميع المغادرات';
if ($departureTypeId > 0) {
    foreach ($departureTypes as $t) {
        if ((int) $t['id'] === $departureTypeId) {
            $typeLabel = (string) $t['name_ar'];
            break;
        }
    }
}

$reportTitle = 'تقرير المغادرات بين تاريخين';
$reportDate = date('Y-m-d');
$exitUrl = nav_exit_url('report_hr_employee_departures');

$cssPath = app_path('assets/css/hr-employee-leave-departure-report.css');
$cssUrl = app_url('assets/css/hr-employee-leave-departure-report.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$reportSalesCssUrl = document_print_stylesheet_url('assets/css/report-sales.css');
$docCssUrl = document_print_stylesheet_url('assets/css/document-header.css');
?>
<link rel="stylesheet" href="<?= esc($docCssUrl) ?>">
<link rel="stylesheet" href="<?= esc($reportSalesCssUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<style><?= document_print_header_css() ?></style>

<div class="card report-sales-page hr-ld-rpt-page" data-report-route="report_hr_employee_departures" data-exit-url="<?= esc($exitUrl) ?>">

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print hr-ld-rpt-filters">
        <input type="hidden" name="r" value="report_hr_employee_departures">
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
                <span class="field-label">نوع المغادرة</span>
                <select class="input" name="departure_type_id">
                    <option value="0" <?= $departureTypeId === 0 ? 'selected' : '' ?>>جميع المغادرات</option>
                    <?php foreach ($departureTypes as $t): ?>
                        <option value="<?= (int) $t['id'] ?>" <?= $departureTypeId === (int) $t['id'] ? 'selected' : '' ?>>
                            <?= esc((string) $t['name_ar']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
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
        </div>
        <div style="margin-top:0.5rem;">
            <button class="btn btn-primary" type="submit">عرض التقرير</button>
        </div>
    </form>

    <?php if ($showReport && $report !== null): ?>
        <div class="report-sales-result report-sales-print-area hr-ld-rpt-doc">
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
                            <strong>نوع المغادرة:</strong> <?= esc($typeLabel) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>القسم:</strong> <?= esc($deptLabel) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>عدد السجلات:</strong> <?= (int) ($report['row_count'] ?? 0) ?>
                        </td>
                    </tr>
                </table>
            </div>

            <?php if (($report['departments'] ?? []) === []): ?>
                <p class="hr-ld-rpt-empty muted">لا توجد مغادرات مطابقة للفترة والفلاتر المحددة.</p>
            <?php else: ?>
                <div class="hr-ld-rpt-kpis no-print">
                    <div class="hr-ld-rpt-kpi">
                        <span class="hr-ld-rpt-kpi__label">عدد السجلات</span>
                        <span class="hr-ld-rpt-kpi__value" dir="ltr"><?= (int) ($report['row_count'] ?? 0) ?></span>
                    </div>
                    <div class="hr-ld-rpt-kpi">
                        <span class="hr-ld-rpt-kpi__label">إجمالي المدة</span>
                        <span class="hr-ld-rpt-kpi__value" dir="ltr"><?= esc((string) ($report['grand_total_duration_label'] ?? '00:00')) ?></span>
                    </div>
                </div>

                <?php foreach ($report['departments'] as $deptBlock): ?>
                    <section class="hr-ld-rpt-dept-section">
                        <h3 class="hr-ld-rpt-dept-heading">
                            القسم: <?= esc((string) ($deptBlock['dept_name'] ?? '—')) ?>
                            <span class="hr-ld-rpt-dept-meta">(<?= (int) ($deptBlock['row_count'] ?? 0) ?> سجل)</span>
                        </h3>

                        <div class="hr-ld-rpt-table-wrap">
                            <table class="hr-ld-rpt-table report-sales-table">
                                <thead>
                                <tr>
                                    <th class="col-seq">#</th>
                                    <th class="hr-ld-rpt-col-voucher">رقم السند</th>
                                    <th class="hr-ld-rpt-col-emp-code">رقم الموظف</th>
                                    <th class="hr-ld-rpt-col-emp-name">اسم الموظف</th>
                                    <th class="hr-ld-rpt-col-type">نوع المغادرة</th>
                                    <th class="col-date">تاريخ المغادرة</th>
                                    <th class="hr-ld-rpt-col-time">من وقت</th>
                                    <th class="hr-ld-rpt-col-time">إلى وقت</th>
                                    <th class="hr-ld-rpt-col-duration">المدة</th>
                                    <th class="hr-ld-rpt-col-status">الحالة</th>
                                    <th class="hr-ld-rpt-col-notes">ملاحظات</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php $deptSeq = 0; ?>
                                <?php foreach ($deptBlock['rows'] as $row): ?>
                                    <?php $deptSeq++; ?>
                                    <tr>
                                        <td class="col-seq"><?= $deptSeq ?></td>
                                        <td class="hr-ld-rpt-col-voucher" dir="ltr"><?= esc((string) ($row['voucher_no'] ?? '—')) ?></td>
                                        <td class="hr-ld-rpt-col-emp-code" dir="ltr"><?= esc((string) ($row['emp_code'] ?? '—')) ?></td>
                                        <td class="hr-ld-rpt-col-emp-name"><?= esc((string) ($row['emp_name'] ?? '—')) ?></td>
                                        <td class="hr-ld-rpt-col-type"><?= esc((string) ($row['type_name'] ?? '—')) ?></td>
                                        <td class="col-date" dir="ltr"><?= esc(format_date_dmY((string) ($row['departure_date'] ?? ''))) ?></td>
                                        <td class="hr-ld-rpt-col-time" dir="ltr"><?= esc((string) ($row['time_from'] ?? '—')) ?></td>
                                        <td class="hr-ld-rpt-col-time" dir="ltr"><?= esc((string) ($row['time_to'] ?? '—')) ?></td>
                                        <td class="hr-ld-rpt-col-duration num" dir="ltr"><?= esc((string) ($row['duration_label'] ?? '—')) ?></td>
                                        <td class="hr-ld-rpt-col-status"><?= esc((string) ($row['posted_label'] ?? '—')) ?></td>
                                        <td class="hr-ld-rpt-col-notes"><?= esc((string) (($row['notes'] ?? '') !== '' ? $row['notes'] : '—')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                <tr class="hr-ld-rpt-row--dept-total">
                                    <td colspan="8" class="hr-ld-rpt-subtotal-label">
                                        مجموع القسم: <?= esc((string) ($deptBlock['dept_name'] ?? '—')) ?>
                                    </td>
                                    <td class="hr-ld-rpt-col-duration num" dir="ltr"><?= esc((string) ($deptBlock['total_duration_label'] ?? '00:00')) ?></td>
                                    <td colspan="2"></td>
                                </tr>
                                </tfoot>
                            </table>
                        </div>
                    </section>
                <?php endforeach; ?>

                <footer class="hr-ld-rpt-grand-wrap">
                    <table class="hr-ld-rpt-grand-table">
                        <tbody>
                        <tr>
                            <th class="hr-ld-rpt-grand-label">المجموع الكلي للمغادرات</th>
                            <td class="num" dir="ltr"><?= esc((string) ($report['grand_total_duration_label'] ?? '00:00')) ?></td>
                        </tr>
                        </tbody>
                    </table>
                </footer>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
