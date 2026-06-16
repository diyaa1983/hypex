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
$slipEmployeeId = (int) ($slip['employee_id'] ?? 0);
$slipYear = (int) ($slip['pay_year'] ?? 0);
$slipMonth = (int) ($slip['pay_month'] ?? 0);

$prevSlipUrl = '';
$nextSlipUrl = '';
if ($slipEmployeeId > 0 && $slipYear >= 2000 && $slipMonth >= 1 && $slipMonth <= 12) {
    $periodEmployees = hr_payroll_slip_report_employees_for_period($pdo, $slipYear, $slipMonth);
    $currentIndex = -1;
    foreach ($periodEmployees as $idx => $emp) {
        if ((int) ($emp['id'] ?? 0) === $slipEmployeeId) {
            $currentIndex = (int) $idx;
            break;
        }
    }
    if ($currentIndex > 0) {
        $prevEmpId = (int) ($periodEmployees[$currentIndex - 1]['id'] ?? 0);
        if ($prevEmpId > 0) {
            $prevSlipUrl = app_url(
                'index.php?r=hr_payroll_slip&employee_id=' . $prevEmpId
                . '&year=' . $slipYear
                . '&month=' . $slipMonth
            );
        }
    }
    if ($currentIndex >= 0 && $currentIndex < count($periodEmployees) - 1) {
        $nextEmpId = (int) ($periodEmployees[$currentIndex + 1]['id'] ?? 0);
        if ($nextEmpId > 0) {
            $nextSlipUrl = app_url(
                'index.php?r=hr_payroll_slip&employee_id=' . $nextEmpId
                . '&year=' . $slipYear
                . '&month=' . $slipMonth
            );
        }
    }
}

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
        .hr-pslip-print-actions .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            line-height: 1.2;
        }
        .hr-pslip-print-actions .btn.is-disabled {
            opacity: 0.45;
            cursor: not-allowed;
            pointer-events: auto;
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

<div class="hr-pslip-print-actions no-print"
     data-prev-url="<?= esc($prevSlipUrl) ?>"
     data-next-url="<?= esc($nextSlipUrl) ?>">
    <a class="btn btn-secondary<?= $prevSlipUrl === '' ? ' is-disabled' : '' ?>"
       href="<?= esc($prevSlipUrl !== '' ? $prevSlipUrl : '#') ?>"
       aria-disabled="<?= $prevSlipUrl === '' ? 'true' : 'false' ?>">السابق</a>
    <button type="button" class="btn btn-primary" onclick="window.print()">🖨 طباعة</button>
    <a class="btn btn-secondary<?= $nextSlipUrl === '' ? ' is-disabled' : '' ?>"
       href="<?= esc($nextSlipUrl !== '' ? $nextSlipUrl : '#') ?>"
       aria-disabled="<?= $nextSlipUrl === '' ? 'true' : 'false' ?>">التالي</a>
    <button type="button" class="btn btn-secondary" onclick="window.close()">إغلاق</button>
</div>
<script>
(function () {
    var actions = document.querySelector('.hr-pslip-print-actions');
    if (!actions) return;
    actions.addEventListener('click', function (e) {
        var disabledLink = e.target.closest('a.is-disabled');
        if (disabledLink) {
            e.preventDefault();
        }
    });
    document.addEventListener('keydown', function (e) {
        if (!e.altKey) return;
        var url = '';
        if (e.key === 'ArrowRight') {
            url = actions.getAttribute('data-prev-url') || '';
        } else if (e.key === 'ArrowLeft') {
            url = actions.getAttribute('data-next-url') || '';
        }
        if (url) {
            e.preventDefault();
            window.location.href = url;
        }
    });
})();
</script>
</body>
</html>
