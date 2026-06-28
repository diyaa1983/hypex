<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');
require_once app_path('includes/hr_payroll_posting.php');
require_once app_path('includes/acc_report_ref.php');
require_once app_path('includes/hr_oracle_ui.php');
require_once app_path('includes/hr_month_chip_strip.php');
require_once app_path('includes/hr_payroll_month_report.php');
require_once app_path('includes/document_header.php');

$pdo = db();
hr_employee_ensure_schema($pdo);
hr_salary_line_ensure_schema($pdo);
hr_ss_ensure_posting_rule($pdo);

$listUrl = app_url('index.php?r=hr_payroll_posting');
$indexUrl = app_url('index.php');
$slipBaseUrl = app_url('index.php?r=hr_payroll_slip');

$payYear = (int) ($_GET['year'] ?? $_POST['pay_year'] ?? date('Y'));
$monthPickerOptions = hr_payroll_month_picker_options($pdo, $payYear);
$payMonthRaw = (int) ($_GET['month'] ?? $_POST['pay_month'] ?? 0);
if ($payMonthRaw >= 1 && $payMonthRaw <= 12) {
    $payMonth = $payMonthRaw;
} else {
    $payMonth = hr_payroll_default_picker_month($pdo, $payYear);
}
$payMonth = hr_payroll_month_picker_resolve($payMonth, $monthPickerOptions);
$filterDeptId = (int) ($_GET['dept_id'] ?? $_POST['filter_dept_id'] ?? 0);
$filterEmpId = (int) ($_GET['employee_id'] ?? $_POST['filter_employee_id'] ?? 0);
$showEmployeeList = isset($_GET['show']) && (string) $_GET['show'] === '1';

/**
 * @return string query string (without leading ?)
 */
function hr_payroll_posting_query(int $year, int $month, int $deptId = 0, int $empId = 0, bool $showList = false): string
{
    $q = 'year=' . $year . '&month=' . $month;
    if ($empId > 0) {
        $q .= '&employee_id=' . $empId;
    } elseif ($deptId > 0) {
        $q .= '&dept_id=' . $deptId;
    }
    if ($showList) {
        $q .= '&show=1';
    }

    return $q;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        flash_set('error', 'جلسة غير صالحة، أعد المحاولة.');
        redirect($listUrl . '&' . hr_payroll_posting_query($payYear, $payMonth, $filterDeptId, $filterEmpId, true));
    }
    $act = (string) ($_POST['_action'] ?? '');
    $payYear = (int) ($_POST['pay_year'] ?? $payYear);
    $payMonth = (int) ($_POST['pay_month'] ?? $payMonth);
    $filterDeptId = (int) ($_POST['filter_dept_id'] ?? $filterDeptId);
    $filterEmpId = (int) ($_POST['filter_employee_id'] ?? $filterEmpId);

    try {
        if ($act === 'calculate') {
            $ids = is_array($_POST['employee_ids'] ?? null) ? $_POST['employee_ids'] : [];
            $n = hr_payroll_calculate($pdo, $payYear, $payMonth, array_map('intval', $ids));
            flash_set('success', 'تم احتساب رواتب ' . $n . ' موظفاً لشهر ' . hr_payroll_period_label($payYear, $payMonth) . '.');
        } elseif ($act === 'post') {
            $res = hr_payroll_post_month($pdo, $payYear, $payMonth);
            $msg = 'تم ترحيل ' . (int) $res['posted_count'] . ' قيد راتب.';
            if ((int) ($res['journal_id'] ?? 0) > 0) {
                $msg .= ' — إجمالي رواتب: '
                    . number_format((float) ($res['gross_total'] ?? 0), 3)
                    . ' | للصرف من الصندوق: '
                    . number_format((float) ($res['net_total'] ?? 0), 3);
                if ((float) ($res['payable_total'] ?? 0) > 0) {
                    $msg .= ' | ضمان مستحق: ' . number_format((float) $res['payable_total'], 3);
                }
            }
            flash_set('success', $msg);
        } elseif ($act === 'unpost') {
            if (!user_can_action('action_unpost_payroll')) {
                throw new RuntimeException('ليس لديك صلاحية فك ترحيل رواتب الشهر.');
            }
            $res = hr_payroll_unpost_month($pdo, $payYear, $payMonth);
            flash_set('success', 'تم فك الترحيل وإلغاء احتساب ' . (int) $res['deleted'] . ' قيداً.');
        } elseif ($act === 'cancel_calculate') {
            $ids = is_array($_POST['employee_ids'] ?? null) ? $_POST['employee_ids'] : [];
            $res = hr_payroll_cancel_calculate($pdo, $payYear, $payMonth, array_map('intval', $ids));
            flash_set(
                'success',
                'تم إلغاء احتساب ' . (int) $res['cancelled'] . ' موظفاً لشهر '
                . hr_payroll_period_label($payYear, $payMonth) . '.'
            );
        } else {
            throw new RuntimeException('إجراء غير معروف.');
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage() ?: 'تعذر إتمام العملية.');
    }
    redirect($listUrl . '&' . hr_payroll_posting_query($payYear, $payMonth, $filterDeptId, $filterEmpId, true));
}

$flash = flash_get();
hr_department_ensure_schema($pdo);
$departments = hr_department_active_list($pdo);
$filterEmployees = hr_payroll_filter_employee_options($pdo, $filterDeptId, $filterEmpId);
$payrollFilterEmployeesJson = json_encode(
    hr_payroll_active_employees_for_filter($pdo),
    JSON_UNESCAPED_UNICODE
);
$payrollDeptNamesJson = json_encode(
    array_column(
        array_map(static fn (array $d): array => [
            'id' => (int) ($d['id'] ?? 0),
            'name' => (string) ($d['name_ar'] ?? ''),
        ], $departments),
        'name',
        'id'
    ),
    JSON_UNESCAPED_UNICODE
);
$statusRows = [];
$summary = [
    'calculated' => 0,
    'posted' => 0,
    'gate_ok' => false,
    'gate_message' => '',
    'gate_alert_type' => '',
    'open_period' => null,
    'can_unpost' => false,
];
if ($showEmployeeList) {
    $statusRows = hr_payroll_month_status_rows($pdo, $payYear, $payMonth, $filterDeptId, $filterEmpId);
    $summary = hr_payroll_month_summary($pdo, $payYear, $payMonth, $filterDeptId, $filterEmpId);
} else {
    $summary = [
        'calculated' => 0,
        'posted' => 0,
        'gate_ok' => false,
        'gate_message' => '',
        'gate_alert_type' => '',
        'open_period' => hr_payroll_open_period($pdo),
        'can_unpost' => false,
    ];
}
if (!user_can_action('action_unpost_payroll')) {
    $summary['can_unpost'] = false;
}
$disburse = [
    'has_rows' => false,
    'gross' => 0.0,
    'employee_ss' => 0.0,
    'fund_salaries' => 0.0,
    'ss_payable_total' => 0.0,
    'fund_total' => 0.0,
];
$postedMonths = [];
$currentMonthPosted = false;
if ($showEmployeeList) {
    $disburse = hr_payroll_month_disbursement_totals($pdo, $payYear, $payMonth);
    $postedMonths = hr_payroll_posted_months_for_year($pdo, $payYear, $payMonth);
    foreach ($postedMonths as $pmRow) {
        if (!empty($pmRow['is_current'])) {
            $currentMonthPosted = true;
            break;
        }
    }
}
$filterLabel = '—';
if ($showEmployeeList) {
    $filterLabel = 'جميع الموظفين';
    if ($filterEmpId > 0) {
        $stEmpName = $pdo->prepare('SELECT name_ar FROM hr_employee WHERE id = ? LIMIT 1');
        $stEmpName->execute([$filterEmpId]);
        $empName = (string) ($stEmpName->fetchColumn() ?: '');
        $filterLabel = $empName !== '' ? $empName : 'موظف #' . $filterEmpId;
    } elseif ($filterDeptId > 0) {
        foreach ($departments as $d) {
            if ((int) ($d['id'] ?? 0) === $filterDeptId) {
                $filterLabel = 'قسم: ' . (string) ($d['name_ar'] ?? '');
                break;
            }
        }
    }
}
$access = $showEmployeeList
    ? hr_payroll_month_access($pdo, $payYear, $payMonth)
    : ['can_edit' => false, 'message' => '', 'alert_type' => '', 'open_period' => hr_payroll_open_period($pdo)];
$gate = ['ok' => $access['can_edit'], 'message' => $access['message']];
$maxPosted = hr_payroll_max_posted_period($pdo);
$gateAlert = (string) ($summary['gate_alert_type'] ?? $access['alert_type'] ?? '');
$gateMessage = (string) ($summary['gate_message'] ?? $access['message'] ?? '');

$monthNames = [
    1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل', 5 => 'مايو', 6 => 'يونيو',
    7 => 'يوليو', 8 => 'أغسطس', 9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر',
];

$cssPath = app_path('assets/css/hr-payroll-posting.css');
$cssUrl = app_url('assets/css/hr-payroll-posting.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$jsPath = app_path('assets/js/hr-payroll-posting.js');
$jsUrl = app_url('assets/js/hr-payroll-posting.js') . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : '');

$journalEntry = $showEmployeeList ? hr_payroll_month_journal_entry($pdo, $payYear, $payMonth) : null;
$movementNo = $journalEntry ? (string) ($journalEntry['entry_no'] ?? '') : sprintf('%04d-%02d', $payYear, $payMonth);
$movementDesc = 'رواتب ' . hr_payroll_period_label($payYear, $payMonth);
if ($journalEntry && (string) ($journalEntry['description_ar'] ?? '') !== '') {
    $movementDesc = (string) $journalEntry['description_ar'];
}
$movementDate = sprintf('%04d-%02d-01', $payYear, $payMonth);
if ($journalEntry && (string) ($journalEntry['entry_date'] ?? '') !== '') {
    $movementDate = (string) $journalEntry['entry_date'];
}
$monthStatus = hr_payroll_month_status_info($pdo, $payYear, $payMonth, $summary, count($statusRows));
$gridTotals = [
    'base' => 0.0,
    'perm_allow' => 0.0,
    'month_allow' => 0.0,
    'deductions' => 0.0,
    'ss' => 0.0,
    'tax' => 0.0,
    'net' => 0.0,
];
$showIncomeTaxCol = false;
foreach ($statusRows as $gr) {
    if (!empty($gr['has_setup'])) {
        $gridTotals['base'] += (float) ($gr['base_salary'] ?? 0);
        $gridTotals['perm_allow'] += (float) ($gr['permanent_allow_total'] ?? 0);
        $gridTotals['month_allow'] += (float) ($gr['monthly_allow_total'] ?? 0);
    }
    if ((string) ($gr['status'] ?? 'none') === 'none') {
        continue;
    }
    $gridTotals['deductions'] += (float) ($gr['deductions'] ?? 0);
    $gridTotals['ss'] += (float) ($gr['social_security_emp'] ?? 0);
    $gridTotals['tax'] += (float) ($gr['income_tax'] ?? 0);
    $gridTotals['net'] += (float) ($gr['net_salary'] ?? 0);
    if ((float) ($gr['income_tax'] ?? 0) > 0) {
        $showIncomeTaxCol = true;
    }
}
foreach ($gridTotals as $k => $v) {
    $gridTotals[$k] = round($v, 3);
}

$noSetupCount = 0;
foreach ($statusRows as $sr) {
    if (empty($sr['has_setup'])) {
        $noSetupCount++;
    }
}
$salariesUrl = app_url('index.php?r=hr_salaries');

$printAllRows = hr_payroll_month_status_rows($pdo, $payYear, $payMonth, $filterDeptId, $filterEmpId);
$printReportFiltered = hr_payroll_month_report_filter_rows($printAllRows);
$printReportRows = $printReportFiltered['rows'];
$printReportTotals = $printReportFiltered['totals'];
$printReportMovement = hr_payroll_month_report_movement($pdo, $payYear, $payMonth);
$printReportSummary = hr_payroll_month_summary($pdo, $payYear, $payMonth, $filterDeptId, $filterEmpId);
$printReportMonthStatus = hr_payroll_month_status_info(
    $pdo,
    $payYear,
    $payMonth,
    $printReportSummary,
    count($printAllRows)
);
$printReportTitle = hr_payroll_month_report_title();
$printReportDate = date('Y-m-d');
$hasPrintReport = $printReportRows !== [];
$printFilterLabel = 'جميع الموظفين';
if ($filterEmpId > 0) {
    $stEmpName = $pdo->prepare('SELECT name_ar FROM hr_employee WHERE id = ? LIMIT 1');
    $stEmpName->execute([$filterEmpId]);
    $empName = (string) ($stEmpName->fetchColumn() ?: '');
    $printFilterLabel = $empName !== '' ? $empName : 'موظف #' . $filterEmpId;
} elseif ($filterDeptId > 0) {
    foreach ($departments as $d) {
        if ((int) ($d['id'] ?? 0) === $filterDeptId) {
            $printFilterLabel = 'قسم: ' . (string) ($d['name_ar'] ?? '');
            break;
        }
    }
}
$monthReportCssPath = app_path('assets/css/hr-payroll-month-report.css');
$monthReportCssUrl = app_url('assets/css/hr-payroll-month-report.css')
    . (is_file($monthReportCssPath) ? '?v=' . (string) filemtime($monthReportCssPath) : '');
$slipReportCssPath = app_path('assets/css/hr-payroll-slip-report.css');
$slipReportCssUrl = app_url('assets/css/hr-payroll-slip-report.css')
    . (is_file($slipReportCssPath) ? '?v=' . (string) filemtime($slipReportCssPath) : '');
$salesInvCssPath = app_path('assets/css/sales-invoice.css');
$salesInvCssUrl = app_url('assets/css/sales-invoice.css')
    . (is_file($salesInvCssPath) ? '?v=' . (string) filemtime($salesInvCssPath) : '');

require app_path('modules/hr/payroll_posting_view.php');
