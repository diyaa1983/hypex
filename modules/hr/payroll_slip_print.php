<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');
require_once app_path('includes/hr_payroll_slip_report.php');
require_once app_path('includes/document_header.php');

$pdo = db();
hr_employee_ensure_schema($pdo);

$salaryId = (int) ($_GET['id'] ?? ($_GET['salary_id'] ?? 0));
$employeeId = (int) ($_GET['employee_id'] ?? 0);
$payYear = (int) ($_GET['year'] ?? 0);
$payMonth = (int) ($_GET['month'] ?? 0);

$slip = null;
if ($salaryId > 0) {
    $slip = hr_payroll_slip_report_build_by_salary_id($pdo, $salaryId);
} elseif ($employeeId > 0 && $payYear >= 2000 && $payMonth >= 1 && $payMonth <= 12) {
    $slip = hr_payroll_slip_report_build($pdo, $employeeId, $payYear, $payMonth);
}

if ($slip === null) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>قسيمة الراتب</title></head>';
    echo '<body><p>قيد الراتب غير موجود.</p></body></html>';
    return;
}

$slipHtml = hr_payroll_slip_report_render_html($slip);
$empName = (string) ($slip['emp_name'] ?? '');

$cssPath = app_path('assets/css/hr-payroll-slip-report.css');
$cssUrl = app_url('assets/css/hr-payroll-slip-report.css');
if (is_file($cssPath)) {
    $cssUrl .= '?v=' . (string) filemtime($cssPath);
}

$cssInvPath = app_path('assets/css/sales-invoice.css');
$cssInvUrl = app_url('assets/css/sales-invoice.css');
if (is_file($cssInvPath)) {
    $cssInvUrl .= '?v=' . (string) filemtime($cssInvPath);
}
$docHeaderCssUrl = document_print_stylesheet_url('assets/css/document-header.css');

$wmRootCss = document_print_watermark_root_css($pdo);
$hasWatermark = document_print_watermark_logo_url($pdo) !== null;
$bodyClass = 'hr-pslip-print-body' . ($hasWatermark ? ' has-doc-watermark' : '');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>قسيمة الراتب — <?= esc($empName) ?></title>
    <link rel="stylesheet" href="<?= esc($cssInvUrl) ?>">
    <link rel="stylesheet" href="<?= esc($cssUrl) ?>">
    <link rel="stylesheet" href="<?= esc($docHeaderCssUrl) ?>">
    <?php if ($wmRootCss !== ''): ?>
        <style><?= $wmRootCss ?></style>
    <?php endif; ?>
    <style>
        <?= document_print_watermark_css() ?>
        body.hr-pslip-print-body {
            margin: 0;
            padding: 1rem 1.25rem 1.5rem;
            background: #f1f5f9;
        }
        .hr-pslip-print-wrap {
            max-width: 920px;
            margin: 0 auto;
        }
        .hr-pslip-print-actions {
            max-width: 920px;
            margin: 1rem auto 0;
            display: flex;
            gap: 0.5rem;
            justify-content: center;
        }
        @media print {
            body.hr-pslip-print-body {
                margin: 0;
                padding: 0.35rem 0.5rem 0;
                background: #fff;
            }
            .hr-pslip-doc {
                border: none;
                padding: 0;
            }
        }
    </style>
</head>
<body class="<?= esc($bodyClass) ?>">
<div class="hr-pslip-print-wrap">
    <?= $slipHtml ?>
</div>

<div class="hr-pslip-print-actions no-print">
    <button type="button" class="btn btn-primary" onclick="window.print()">🖨 طباعة</button>
    <button type="button" class="btn btn-secondary" onclick="window.close()">إغلاق</button>
</div>
</body>
</html>
