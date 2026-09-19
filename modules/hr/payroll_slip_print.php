<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');
require_once app_path('includes/hr_payroll_slip_report.php');
require_once app_path('includes/document_header.php');

$pdo = db();
hr_employee_ensure_schema($pdo);

/**
 * @return mixed
 */
function hr_payroll_slip_print_request(string $key, mixed $default = null): mixed
{
    if (array_key_exists($key, $_POST)) {
        return $_POST[$key];
    }
    if (array_key_exists($key, $_GET)) {
        return $_GET[$key];
    }

    return $default;
}

/**
 * @return list<int>
 */
function hr_payroll_slip_print_int_list(mixed $raw): array
{
    $out = [];
    if (!is_array($raw)) {
        if ($raw === null || $raw === '') {
            return [];
        }
        $raw = [$raw];
    }
    foreach ($raw as $value) {
        $id = (int) $value;
        if ($id > 0) {
            $out[] = $id;
        }
    }

    return array_values(array_unique($out));
}

/**
 * @return list<int>
 */
function hr_payroll_slip_print_parse_id_csv(string $csv): array
{
    $out = [];
    if ($csv === '') {
        return [];
    }
    foreach (explode(',', $csv) as $part) {
        $id = (int) trim($part);
        if ($id > 0) {
            $out[] = $id;
        }
    }

    return array_values(array_unique($out));
}

/**
 * @return list<int>
 */
function hr_payroll_slip_print_resolve_batch_employees(
    bool $batchFlag,
    string $employeeIdsCsv,
    array $navIds,
    mixed $employeeIdRaw
): array {
    if ($batchFlag) {
        $ids = hr_payroll_slip_print_parse_id_csv($employeeIdsCsv);
        if ($ids !== []) {
            return $ids;
        }
        if ($navIds !== []) {
            return $navIds;
        }

        return hr_payroll_slip_print_int_list($employeeIdRaw);
    }

    $ids = hr_payroll_slip_print_parse_id_csv($employeeIdsCsv);
    if ($ids !== []) {
        return $ids;
    }

    return hr_payroll_slip_print_int_list($employeeIdRaw);
}

function hr_payroll_slip_print_build_url(int $employeeId, int $year, int $month, array $navIds = []): string
{
    $url = 'index.php?r=hr_payroll_slip'
        . '&employee_id=' . $employeeId
        . '&year=' . $year
        . '&month=' . $month;
    if (count($navIds) > 1) {
        $url .= '&nav=' . rawurlencode(implode(',', $navIds));
    }

    return app_url($url);
}

$payYear = (int) hr_payroll_slip_print_request('pay_year', hr_payroll_slip_print_request('year', 0));
$payMonth = (int) hr_payroll_slip_print_request('pay_month', hr_payroll_slip_print_request('month', 0));

$navIds = [];
$navRaw = trim((string) hr_payroll_slip_print_request('nav', ''));
if ($navRaw !== '') {
    foreach (explode(',', $navRaw) as $part) {
        $id = (int) trim($part);
        if ($id > 0) {
            $navIds[] = $id;
        }
    }
    $navIds = array_values(array_unique($navIds));
}

$employeeIdRaw = hr_payroll_slip_print_request('employee_id');
$employeeIdsCsv = trim((string) hr_payroll_slip_print_request('employee_ids', ''));
$batchFlag = (string) hr_payroll_slip_print_request('batch', '') === '1';
$batchEmployees = hr_payroll_slip_print_resolve_batch_employees(
    $batchFlag,
    $employeeIdsCsv,
    $navIds,
    $employeeIdRaw
);
$salaryIdsCsv = trim((string) hr_payroll_slip_print_request('salary_ids', ''));
$batchSalaryIds = hr_payroll_slip_print_parse_id_csv($salaryIdsCsv);
$isBatchPrint = $batchFlag
    || count($batchEmployees) > 1
    || count($batchSalaryIds) > 1;
$displayEmployeeId = 0;
if (count($batchEmployees) === 1 && !$isBatchPrint) {
    $displayEmployeeId = $batchEmployees[0];
} elseif (!is_array($employeeIdRaw) && $employeeIdRaw !== null && $employeeIdRaw !== '') {
    $displayEmployeeId = (int) $employeeIdRaw;
}
$salaryIds = hr_payroll_slip_print_int_list(hr_payroll_slip_print_request('id'));
$idsRaw = trim((string) hr_payroll_slip_print_request('ids', ''));
if ($idsRaw !== '') {
    foreach (explode(',', $idsRaw) as $part) {
        $id = (int) trim($part);
        if ($id > 0) {
            $salaryIds[] = $id;
        }
    }
    $salaryIds = array_values(array_unique($salaryIds));
}

$idRaw = hr_payroll_slip_print_request('id');
$salaryId = $salaryIds !== []
    ? $salaryIds[0]
    : (is_array($idRaw) ? 0 : (int) hr_payroll_slip_print_request('salary_id', hr_payroll_slip_print_request('id', 0)));

$slips = [];
if ($batchFlag && $payYear >= 2000 && $payMonth >= 1 && $payMonth <= 12) {
    if ($batchEmployees !== []) {
        foreach ($batchEmployees as $eid) {
            $slip = hr_payroll_slip_report_build($pdo, $eid, $payYear, $payMonth);
            if ($slip !== null) {
                $slips[] = $slip;
            }
        }
    } elseif ($batchSalaryIds !== []) {
        foreach ($batchSalaryIds as $sid) {
            $slip = hr_payroll_slip_report_build_by_salary_id($pdo, $sid);
            if ($slip !== null) {
                $slips[] = $slip;
            }
        }
    }
} elseif ($isBatchPrint && $batchEmployees !== [] && $payYear >= 2000 && $payMonth >= 1 && $payMonth <= 12) {
    foreach ($batchEmployees as $eid) {
        $slip = hr_payroll_slip_report_build($pdo, $eid, $payYear, $payMonth);
        if ($slip !== null) {
            $slips[] = $slip;
        }
    }
} elseif ($navIds !== [] && count($navIds) > 1 && $payYear >= 2000 && $payMonth >= 1 && $payMonth <= 12) {
    foreach ($navIds as $eid) {
        $slip = hr_payroll_slip_report_build($pdo, (int) $eid, $payYear, $payMonth);
        if ($slip !== null) {
            $slips[] = $slip;
        }
    }
} elseif ($displayEmployeeId > 0 && $payYear >= 2000 && $payMonth >= 1 && $payMonth <= 12) {
    $slip = hr_payroll_slip_report_build($pdo, $displayEmployeeId, $payYear, $payMonth);
    if ($slip !== null) {
        $slips[] = $slip;
    }
} elseif ($salaryIds !== []) {
    foreach ($salaryIds as $sid) {
        $slip = hr_payroll_slip_report_build_by_salary_id($pdo, $sid);
        if ($slip !== null) {
            $slips[] = $slip;
        }
    }
} else {
    $slip = null;
    if ($salaryId > 0) {
        $slip = hr_payroll_slip_report_build_by_salary_id($pdo, $salaryId);
    }
    if ($slip !== null) {
        $slips[] = $slip;
    }
}

if ($slips === []) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>قسيمة الراتب</title></head>';
    echo '<body><p>قيد الراتب غير موجود.</p></body></html>';
    return;
}

$isBatch = count($slips) > 1;
$isSelectionBatch = $isBatch
    || ($batchFlag && $batchEmployees !== []);
$slip = $slips[0];
$empName = (string) ($slip['emp_name'] ?? '');
$slipEmployeeId = (int) ($slip['employee_id'] ?? 0);
$slipYear = (int) ($slip['pay_year'] ?? 0);
$slipMonth = (int) ($slip['pay_month'] ?? 0);

$navOrder = [];
if ($batchFlag && $batchEmployees !== []) {
    $navOrder = $batchEmployees;
} elseif ($slipEmployeeId > 0 && $slipYear >= 2000 && $slipMonth >= 1 && $slipMonth <= 12) {
    $navOrder = $navIds;
    if ($navOrder === []) {
        $navOrder = array_values(array_filter(array_map(
            static fn (array $emp): int => (int) ($emp['id'] ?? 0),
            hr_payroll_slip_report_employees_for_period($pdo, $slipYear, $slipMonth)
        ), static fn (int $id): bool => $id > 0));
    }
}

$printSlips = $slips;
$activeEmployeeId = 0;

$printSlipCount = count($printSlips);
if ($printSlipCount > 1) {
    $isSelectionBatch = true;
    $activeEmployeeId = 0;
}
$slipHtml = hr_payroll_slip_report_render_pages($printSlips, $activeEmployeeId);

$prevSlipUrl = '';
$nextSlipUrl = '';
$navPosition = '';
if (!$isSelectionBatch && $slipEmployeeId > 0 && $slipYear >= 2000 && $slipMonth >= 1 && $slipMonth <= 12) {
    $currentIndex = array_search($slipEmployeeId, $navOrder, true);
    if ($currentIndex !== false) {
        if ($currentIndex > 0) {
            $prevSlipUrl = hr_payroll_slip_print_build_url(
                (int) $navOrder[$currentIndex - 1],
                $slipYear,
                $slipMonth,
                $navOrder
            );
        }
        if ($currentIndex < count($navOrder) - 1) {
            $nextSlipUrl = hr_payroll_slip_print_build_url(
                (int) $navOrder[$currentIndex + 1],
                $slipYear,
                $slipMonth,
                $navOrder
            );
        }
        if (count($navOrder) > 1) {
            $navPosition = ((int) $currentIndex + 1) . ' / ' . count($navOrder);
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
$bodyClass = 'hr-pslip-print-body doc-print-standalone'
    . ($hasWatermark ? ' has-doc-watermark' : '');
$pageTitle = $isSelectionBatch
    ? 'قسائم الراتب — ' . $printSlipCount . ' موظف'
    : 'قسيمة الراتب — ' . $empName;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($pageTitle) ?></title>
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
        .hr-pslip-print-page + .hr-pslip-print-page {
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 2px dashed #cbd5e1;
        }
        .hr-pslip-print-batch-head {
            max-width: 920px;
            margin: 0 auto 1rem;
            padding: 0.55rem 0.75rem;
            background: #e2e8f0;
            border: 1px solid #94a3b8;
            border-radius: 6px;
            font-weight: 700;
            text-align: center;
        }
        .hr-pslip-print-page {
            min-height: 70vh;
        }
        body.hr-pslip-print-nav-multi .hr-pslip-print-page--active + .hr-pslip-print-page {
            margin-top: 0;
            padding-top: 0;
            border-top: 0;
        }
        .hr-pslip-print-actions {
            max-width: 920px;
            margin: 1rem auto 0;
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            justify-content: center;
            align-items: center;
        }
        .hr-pslip-print-nav-pos {
            min-width: 4.5rem;
            text-align: center;
            font-weight: 700;
            color: #334155;
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
            @page {
                size: A4 portrait;
                margin: 8mm 10mm;
            }

            body.hr-pslip-print-body {
                margin: 0;
                padding: 0;
                background: #fff;
            }

            .hr-pslip-print-wrap {
                max-width: none;
                margin: 0;
            }

            body.hr-pslip-print-nav-multi .hr-pslip-print-page {
                display: block !important;
            }

            .hr-pslip-print-page {
                display: block !important;
                min-height: 0;
                page-break-inside: avoid;
                break-inside: avoid-page;
                page-break-after: always;
                break-after: page;
            }

            .hr-pslip-print-page:last-child {
                page-break-after: auto;
                break-after: auto;
            }

            .hr-pslip-print-page + .hr-pslip-print-page {
                margin-top: 0;
                padding-top: 0;
                border-top: 0;
                page-break-before: always;
                break-before: page;
            }

            .hr-pslip-doc {
                border: none;
                padding: 0;
                box-shadow: none;
            }
        }
    </style>
</head>
<body class="<?= esc($bodyClass) ?>">
<?php if ($isSelectionBatch): ?>
<div class="hr-pslip-print-batch-head no-print">
    قسائم الراتب — <?= (int) $printSlipCount ?> موظف (كل قسيمة في صفحة منفصلة عند الطباعة)
</div>
<?php endif; ?>
<div class="hr-pslip-print-wrap">
    <?= $slipHtml ?>
</div>

<?php if ($isSelectionBatch): ?>
<div class="hr-pslip-print-actions no-print">
    <button type="button" class="btn btn-primary" onclick="window.print()">🖨 طباعة <?= (int) $printSlipCount ?> قسيمة</button>
    <button type="button" class="btn btn-secondary" onclick="window.close()">إغلاق</button>
</div>
<?php else: ?>
<div class="hr-pslip-print-actions no-print"
     data-prev-url="<?= esc($prevSlipUrl) ?>"
     data-next-url="<?= esc($nextSlipUrl) ?>">
    <a class="btn btn-secondary<?= $prevSlipUrl === '' ? ' is-disabled' : '' ?>"
       href="<?= esc($prevSlipUrl !== '' ? $prevSlipUrl : '#') ?>"
       aria-disabled="<?= $prevSlipUrl === '' ? 'true' : 'false' ?>">السابق</a>
    <button type="button" class="btn btn-primary" onclick="window.print()">🖨 طباعة<?= $printSlipCount > 1 ? ' ' . (int) $printSlipCount . ' قسيمة' : '' ?></button>
    <?php if ($navPosition !== ''): ?>
        <span class="hr-pslip-print-nav-pos"><?= esc($navPosition) ?></span>
    <?php endif; ?>
    <a class="btn btn-secondary<?= $nextSlipUrl === '' ? ' is-disabled' : '' ?>"
       href="<?= esc($nextSlipUrl !== '' ? $nextSlipUrl : '#') ?>"
       aria-disabled="<?= $nextSlipUrl === '' ? 'true' : 'false' ?>">التالي</a>
    <button type="button" class="btn btn-secondary" onclick="window.close()">إغلاق</button>
</div>
<?php endif; ?>
<?php if (!$isSelectionBatch): ?>
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
<?php endif; ?>
</body>
</html>
