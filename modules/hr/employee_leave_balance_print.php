<?php
declare(strict_types=1);

require_once app_path('includes/hr_employee_leave_balances_report.php');
require_once app_path('includes/document_header.php');

$pdo = db();
hr_employee_ensure_schema($pdo);

$employeeId = (int) ($_GET['id'] ?? 0);
$defaultPeriod = hr_employee_leave_balance_default_period();
$dateFrom = parse_date_to_iso(trim((string) ($_GET['date_from'] ?? ''))) ?? $defaultPeriod['from'];
$dateTo = parse_date_to_iso(trim((string) ($_GET['date_to'] ?? ''))) ?? $defaultPeriod['to'];
if ($dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}
$leaveTypeId = (int) ($_GET['leave_type_id'] ?? 0);

$data = $employeeId > 0
    ? hr_employee_leave_balance_print_build($pdo, $employeeId, $dateFrom, $dateTo, $leaveTypeId)
    : null;

$cssPath = app_path('assets/css/hr-employee-leave-departure-report.css');
$cssUrl = app_url('assets/css/hr-employee-leave-departure-report.css');
if (is_file($cssPath)) {
    $cssUrl .= '?v=' . (string) filemtime($cssPath);
}
$docHeaderCssUrl = document_print_stylesheet_url('assets/css/document-header.css');

$reportTitle = 'رصيد إجازات الموظف';
$printedAt = date('Y-m-d H:i');
$empLabel = $data !== null
    ? trim((string) ($data['emp_code'] ?? '') . ' — ' . (string) ($data['emp_name'] ?? ''))
    : '';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($reportTitle) ?><?= $empLabel !== '' ? ' — ' . esc($empLabel) : '' ?></title>
    <link rel="stylesheet" href="<?= esc($docHeaderCssUrl) ?>">
    <link rel="stylesheet" href="<?= esc($cssUrl) ?>">
    <style><?= document_print_header_css() ?></style>
</head>
<body class="hr-leave-bal-print-body">
<main class="hr-leave-bal-print-doc">
    <?php if ($data === null): ?>
        <p>لا يوجد موظف محدد أو البيانات غير متوفرة.</p>
    <?php else: ?>
        <?= document_print_header_html($reportTitle, $pdo) ?>

        <div class="doc-print-meta">
            <table>
                <tr>
                    <td>
                        <strong>الموظف:</strong> <?= esc($empLabel) ?>
                        &nbsp;&nbsp;|&nbsp;&nbsp;
                        <strong>القسم:</strong> <?= esc((string) ($data['dept_name'] ?? '—')) ?>
                    </td>
                </tr>
                <tr>
                    <td>
                        <strong>الفترة:</strong>
                        <span dir="ltr"><?= esc(format_date_dmY($dateFrom)) ?></span>
                        —
                        <span dir="ltr"><?= esc(format_date_dmY($dateTo)) ?></span>
                        &nbsp;&nbsp;|&nbsp;&nbsp;
                        <strong>نوع الإجازة:</strong> <?= esc((string) ($data['leave_type_label'] ?? 'جميع الإجازات')) ?>
                        <?php if ((string) ($data['hire_date'] ?? '') !== ''): ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>تاريخ التعيين:</strong>
                            <span dir="ltr"><?= esc((string) $data['hire_date']) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </div>

        <div class="hr-ld-rpt-table-wrap hr-leave-bal-print-table-wrap">
            <?php hr_employee_leave_balances_report_render_table($data['rows'] ?? []); ?>
        </div>

        <footer class="hr-leave-bal-print-foot">
            <span>تاريخ الطباعة: <strong dir="ltr"><?= esc($printedAt) ?></strong></span>
        </footer>
    <?php endif; ?>
</main>

<div class="hr-leave-bal-print-actions no-print">
    <button type="button" class="btn btn-primary" onclick="window.print()">🖨 طباعة</button>
    <button type="button" class="btn btn-secondary" onclick="window.close()">إغلاق</button>
</div>
</body>
</html>
