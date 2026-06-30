<?php
declare(strict_types=1);
/** @var bool $showEmployeeList */
/** @var array<string, mixed>|null $flash */
/** @var string $listUrl */
/** @var string $indexUrl */
/** @var int $payYear */
/** @var int $payMonth */
/** @var list<array<string, mixed>> $monthPickerOptions */
/** @var array<int, string> $monthNames */
/** @var list<array<string, mixed>> $departments */
/** @var int $filterDeptId */
/** @var int $filterEmpId */
/** @var list<array<string, mixed>> $filterEmployees */
/** @var string $filterLabel */
/** @var array<string, mixed> $summary */
/** @var array<string, mixed>|null $maxPosted */
/** @var string $gateMessage */
/** @var string $gateAlert */
/** @var string $movementNo */
/** @var string $movementDesc */
/** @var string $movementDate */
/** @var array{code:string, label:string} $monthStatus */
/** @var list<array<string, mixed>> $statusRows */
/** @var array<string, float> $gridTotals */
/** @var bool $showIncomeTaxCol */
/** @var list<array<string, mixed>> $postedMonths */
/** @var array<string, mixed> $disburse */
/** @var array<string, bool|string> $gate */
/** @var string $cssUrl */
/** @var string $jsUrl */
/** @var string $payrollFilterEmployeesJson */
/** @var string $payrollDeptNamesJson */
/** @var bool $currentMonthPosted */
/** @var list<array<string, mixed>> $printReportRows */
/** @var array<string, float> $printReportTotals */
/** @var array{movement_no:string, movement_desc:string, movement_date:string} $printReportMovement */
/** @var array{code:string, label:string} $printReportMonthStatus */
/** @var string $printReportTitle */
/** @var string $printReportDate */
/** @var bool $hasPrintReport */
/** @var string $printFilterLabel */
/** @var int $employeeCount */
/** @var string $salesInvCssUrl */

/**
 * @param list<array<string, mixed>> $lines
 */
function hr_pr_post_render_drill_cell(float $amount, array $lines, string $drillType, bool $calculated = false): void
{
    $hasLines = $lines !== [];
    $showAmount = $amount > 0.0005 || ($calculated && $amount >= 0);
    if (!$showAmount) {
        echo '<td class="num muted">—</td>';

        return;
    }
    $cls = 'num' . ($hasLines ? ' hr-pr-post-drill' : '');
    echo '<td dir="ltr" class="' . esc($cls) . '"';
    if ($hasLines) {
        echo ' data-drill="' . esc($drillType) . '" role="button" tabindex="0" title="عرض التفاصيل"';
    }
    echo '>' . esc(number_format($amount, 3)) . '</td>';
}

$empTableColCount = $showIncomeTaxCol ? 12 : 11;
$mchipCssUrl = hr_month_chip_strip_css_url();
$mchipJsPath = app_path('assets/js/hr-month-chip-strip.js');
$mchipJsUrl = app_url('assets/js/hr-month-chip-strip.js')
    . (is_file($mchipJsPath) ? '?v=' . (string) filemtime($mchipJsPath) : '');
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($mchipCssUrl) ?>">
<link rel="stylesheet" href="<?= esc($monthReportCssUrl) ?>">
<link rel="stylesheet" href="<?= esc($salesInvCssUrl) ?>">
<link rel="stylesheet" href="<?= esc($slipReportCssUrl) ?>">
<style><?= document_print_header_css() ?></style>
<style><?= document_print_watermark_root_css($pdo) ?></style>
<style><?= document_print_watermark_css() ?></style>
<style>
.sales-inv-print-preview-body .hr-pslip-print-batch-head {
    margin: 0 0 1rem;
    padding: 0.55rem 0.75rem;
    background: #e2e8f0;
    border: 1px solid #94a3b8;
    border-radius: 6px;
    font-weight: 700;
    text-align: center;
}
.sales-inv-print-preview-body .hr-pslip-print-page + .hr-pslip-print-page {
    margin-top: 1.5rem;
    padding-top: 1.5rem;
    border-top: 2px dashed #cbd5e1;
}
</style>

<div class="hr-pr-post-classic hr-pr-post-ora-screen<?= $showEmployeeList ? '' : ' hr-pr-post-classic--pending' ?>"
     data-list-url="<?= esc($listUrl) ?>"
     data-slip-base="<?= esc($slipBaseUrl) ?>"
     data-year="<?= (int) $payYear ?>"
     data-month="<?= (int) $payMonth ?>"
     data-list-shown="<?= $showEmployeeList ? '1' : '0' ?>"
     data-print-ready="<?= $hasPrintReport ? '1' : '0' ?>"
     data-gate-ok="<?= $gate['ok'] ? '1' : '0' ?>"
     data-gate-message="<?= esc($gate['message'] ?? '') ?>"
     data-calculated="<?= (int) ($summary['calculated'] ?? 0) ?>"
     data-can-unpost="<?= !empty($summary['can_unpost']) ? '1' : '0' ?>"
     data-month-posted="<?= $currentMonthPosted ? '1' : '0' ?>"
     data-filter-employees="<?= esc($payrollFilterEmployeesJson ?: '[]') ?>"
     data-dept-names="<?= esc($payrollDeptNamesJson ?: '{}') ?>"
     data-filter-dept="<?= (int) $filterDeptId ?>"
     data-filter-emp="<?= (int) $filterEmpId ?>">

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> hr-pr-post-flash">
            <?= esc($flash['message']) ?>
        </div>
    <?php endif; ?>

    <?php hr_ora_render_title_bar('قيد الرواتب', 'hr_payroll_posting'); ?>

    <?php
    $payPeriodMonthName = (string) ($monthNames[$payMonth] ?? (string) $payMonth);
    $payrollJournalReturnUrl = $listUrl . '&' . hr_payroll_posting_query($payYear, $payMonth, $filterDeptId, $filterEmpId, $showEmployeeList);
    ?>
    <form method="get" action="<?= esc($indexUrl) ?>" id="hr-pr-post-period-form" class="hr-pr-post-doc-form">
        <input type="hidden" name="r" value="hr_payroll_posting">
        <input type="hidden" name="show" value="1">

        <section class="hr-pr-post-header-card no-print" aria-label="الفترة والفلتر">
            <div class="hr-pr-post-toolbar">
                <div class="hr-pr-post-toolbar-row hr-pr-post-toolbar-row--movement">
                    <div class="hr-pr-post-movement-section">
                        <div class="hr-pr-post-movement-item">
                            <span class="hr-pr-post-movement-label">رقم الحركة</span>
                            <code class="hr-pr-post-doc-code" dir="ltr"><?= esc($movementNo) ?></code>
                        </div>
                        <div class="hr-pr-post-movement-item hr-pr-post-movement-item--desc">
                            <span class="hr-pr-post-movement-label">وصف الحركة</span>
                            <span class="hr-pr-post-movement-value"><?= esc($movementDesc) ?></span>
                        </div>
                        <div class="hr-pr-post-movement-item">
                            <span class="hr-pr-post-movement-label">تاريخ الحركة</span>
                            <span class="hr-pr-post-movement-value" dir="ltr"><?= esc(format_date_dmY($movementDate)) ?></span>
                        </div>
                    </div>
                </div>

                <div class="hr-pr-post-toolbar-split">
                    <div class="hr-pr-post-controls-col">
                        <div class="hr-pr-post-controls-panel<?= $showEmployeeList ? ' hr-pr-post-controls-panel--with-kpi' : '' ?>">
                            <span class="hr-pr-post-toolbar-side-label" id="hr-pr-post-period-label">الشهر / السنة</span>
                            <div class="hr-pr-post-months-row">
                                <div class="hr-pr-post-months-inline" aria-labelledby="hr-pr-post-period-label">
                                    <?php hr_render_month_chip_strip($monthPickerOptions, [
                                        'year' => $payYear,
                                        'selected_month' => $payMonth,
                                        'year_input_id' => 'hr-pr-post-filter-year',
                                        'year_input_name' => 'year',
                                        'month_input_id' => 'hr-pr-post-filter-month',
                                        'month_input_name' => 'month',
                                        'compact' => true,
                                        'compact_layout' => 'inline',
                                    ]); ?>
                                </div>
                                <span class="hr-pr-post-months-meta">
                                    <span class="hr-pr-post-period-year" dir="ltr"><?= (int) $payYear ?></span>
                                    <span class="hr-pr-post-period-month"><?= esc($payPeriodMonthName) ?></span>
                                    <span class="hr-pr-post-status hr-pr-post-status--<?= esc((string) ($monthStatus['code'] ?? 'open')) ?> hr-pr-post-period-status">
                                        <?= esc((string) ($monthStatus['label'] ?? 'مفتوح')) ?>
                                    </span>
                                </span>
                            </div>
                            <div class="hr-pr-post-field-inline hr-pr-post-toolbar-dept">
                                <label for="hr-pr-post-filter-dept">القسم</label>
                                <div class="hr-pr-post-ora-lov hr-pr-post-filter-control">
                                    <select class="input hr-pr-post-inline-input hr-pr-post-ora-lov-field" name="dept_id" id="hr-pr-post-filter-dept"
                                            <?= $filterEmpId > 0 ? 'disabled' : '' ?>>
                                        <option value="0" <?= $filterDeptId === 0 ? 'selected' : '' ?>>جميع الأقسام</option>
                                        <?php foreach ($departments as $d): ?>
                                            <option value="<?= (int) $d['id'] ?>"
                                                    data-dept-name="<?= esc((string) $d['name_ar']) ?>"
                                                    <?= $filterDeptId === (int) $d['id'] ? 'selected' : '' ?>>
                                                <?= esc((string) $d['name_ar']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="hr-pr-post-ora-lov-btn" tabindex="-1" aria-label="اختيار القسم" title="اختيار القسم"></button>
                                </div>
                            </div>
                            <div class="hr-pr-post-toolbar-emp-row">
                                <div class="hr-pr-post-field-inline hr-pr-post-toolbar-emp">
                                    <label for="hr-pr-post-filter-emp">الموظف</label>
                                    <div class="hr-pr-post-ora-lov hr-pr-post-filter-control">
                                        <select class="input hr-pr-post-inline-input hr-pr-post-ora-lov-field" name="employee_id" id="hr-pr-post-filter-emp">
                                            <option value="0" <?= $filterEmpId === 0 ? 'selected' : '' ?>>جميع الموظفين</option>
                                            <?php foreach ($filterEmployees as $fe):
                                                $fid = (int) ($fe['id'] ?? 0);
                                                $fname = (string) ($fe['name_ar'] ?? '');
                                            ?>
                                                <option value="<?= $fid ?>" <?= $filterEmpId === $fid ? 'selected' : '' ?>>
                                                    <?= esc($fname !== '' ? $fname : '—') ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="hr-pr-post-ora-lov-btn" tabindex="-1" aria-label="اختيار الموظف" title="اختيار الموظف"></button>
                                    </div>
                                </div>
                                <div class="hr-pr-post-filter-actions hr-pr-post-toolbar-actions">
                                    <button type="submit" class="btn btn-primary btn-sm">عرض</button>
                                </div>
                            </div>
                            <?php if ($showEmployeeList): ?>
                            <div class="hr-pr-post-summary-strip hr-pr-post-toolbar-kpi no-print" aria-label="ملخص الشهر">
                                <div class="hr-pr-post-kpi">
                                    <span class="hr-pr-post-kpi-label">الموظفون</span>
                                    <strong class="hr-pr-post-kpi-value"><?= (int) $employeeCount ?></strong>
                                </div>
                                <div class="hr-pr-post-kpi">
                                    <span class="hr-pr-post-kpi-label">محتسب</span>
                                    <strong class="hr-pr-post-kpi-value hr-pr-post-kpi-value--calc"><?= (int) ($summary['calculated'] ?? 0) ?></strong>
                                </div>
                                <div class="hr-pr-post-kpi">
                                    <span class="hr-pr-post-kpi-label">مرحّل</span>
                                    <strong class="hr-pr-post-kpi-value hr-pr-post-kpi-value--posted"><?= (int) ($summary['posted'] ?? 0) ?></strong>
                                </div>
                                <div class="hr-pr-post-kpi">
                                    <span class="hr-pr-post-kpi-label">صافي الرواتب</span>
                                    <strong class="hr-pr-post-kpi-value" dir="ltr"><?= esc(number_format($gridTotals['net'], 3)) ?></strong>
                                </div>
                                <?php if (!empty($disburse['has_rows'])): ?>
                                <div class="hr-pr-post-kpi">
                                    <span class="hr-pr-post-kpi-label">للصرف</span>
                                    <strong class="hr-pr-post-kpi-value hr-pr-post-kpi-value--fund" dir="ltr"><?= esc(number_format((float) $disburse['fund_total'], 3)) ?></strong>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($postedMonths !== []): ?>
                    <div class="hr-pr-post-posted-col">
                        <div class="hr-pr-post-posted-inline hr-pr-post-toolbar-posted" aria-label="الأشهر المرحّلة">
                            <div class="hr-pr-post-posted-inline-title">الأشهر المرحّلة — <?= (int) $payYear ?></div>
                            <div class="hr-pr-post-grid-wrap hr-pr-post-posted-wrap">
                                <table class="hr-pr-post-grid-table hr-pr-post-posted-table">
                                    <thead>
                                    <tr>
                                        <th>رقم الشهر</th>
                                        <th>اسم الشهر</th>
                                        <th>الحالة</th>
                                        <th>موظفون</th>
                                        <th>إجمالي الرواتب</th>
                                        <th>تاريخ القيد</th>
                                        <th>إجراء</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($postedMonths as $pmRow):
                                        $m = (int) ($pmRow['month'] ?? 0);
                                        $monthUrl = $listUrl . '&' . hr_payroll_posting_query($payYear, $m, $filterDeptId, $filterEmpId, true);
                                        $jid = (int) ($pmRow['journal_id'] ?? 0);
                                        $entryNo = (string) ($pmRow['entry_no'] ?? '');
                                        $journalUrl = $jid > 0 ? acc_report_journal_voucher_url($jid, $entryNo, $payrollJournalReturnUrl) : '';
                                        $rowClass = 'hr-pr-post-posted-row';
                                        if (!empty($pmRow['is_current'])) {
                                            $rowClass .= ' hr-pr-post-posted-row--current';
                                        }
                                        if (!empty($pmRow['can_unpost'])) {
                                            $rowClass .= ' hr-pr-post-posted-row--unpost';
                                        }
                                        $monthName = (string) ($monthNames[$m] ?? (string) $m);
                                        if (!empty($pmRow['can_unpost'])) {
                                            $postedStatusLabel = 'آخر مرحّل';
                                            $postedStatusCode = 'last';
                                        } elseif (!empty($pmRow['is_current'])) {
                                            $postedStatusLabel = 'الشهر المعروض';
                                            $postedStatusCode = 'current';
                                        } else {
                                            $postedStatusLabel = 'مرحّل';
                                            $postedStatusCode = 'posted';
                                        }
                                    ?>
                                        <tr class="<?= esc($rowClass) ?>">
                                            <td dir="ltr" class="num hr-pr-post-posted-month-no">
                                                <a href="<?= esc($monthUrl) ?>"><?= esc(sprintf('%02d', $m)) ?></a>
                                            </td>
                                            <td class="hr-pr-post-posted-month-name">
                                                <a href="<?= esc($monthUrl) ?>"><?= esc($monthName) ?></a>
                                            </td>
                                            <td>
                                                <span class="hr-pr-post-posted-status hr-pr-post-posted-status--<?= esc($postedStatusCode) ?>">
                                                    <?= esc($postedStatusLabel) ?>
                                                </span>
                                            </td>
                                            <td dir="ltr" class="num"><?= (int) ($pmRow['emp_count'] ?? 0) ?></td>
                                            <td dir="ltr" class="num"><?= esc(number_format((float) ($pmRow['gross_total'] ?? 0), 3)) ?></td>
                                            <td dir="ltr"><?= ($pmRow['entry_date'] ?? '') !== '' ? esc(format_date_dmY((string) $pmRow['entry_date'])) : '—' ?></td>
                                            <td class="hr-pr-post-posted-actions">
                                                <a class="btn btn-secondary btn-sm" href="<?= esc($monthUrl) ?>">عرض</a>
                                                <?php if ($journalUrl !== ''): ?>
                                                    <a class="btn btn-secondary btn-sm" href="<?= esc($journalUrl) ?>">القيد</a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($filterDeptId > 0 || $filterEmpId > 0): ?>
            <div class="hr-pr-post-filter-active-row">
                <span class="hr-pr-post-field-label">الفلتر النشط</span>
                <span class="hr-pr-post-filter-active"><?= esc($filterLabel) ?></span>
            </div>
            <?php endif; ?>
        </section>
    </form>

    <?php if ($showEmployeeList && $gateMessage !== ''): ?>
        <div class="alert alert-<?= $gateAlert === 'info' ? 'info' : 'error' ?> hr-pr-post-gate hr-ora-inline-msg"><?= esc($gateMessage) ?></div>
    <?php endif; ?>

    <?php if ($showEmployeeList && $noSetupCount > 0): ?>
        <div class="alert alert-error hr-pr-post-gate hr-ora-inline-msg">
            <?= (int) $noSetupCount ?> موظفاً بدون راتب معرّف — لا يظهر مربع التحديد بجانبهم.
            عرّف <strong>الراتب الأساسي</strong> (أو علاوة) من
            <a href="<?= esc($salariesUrl) ?>"><strong>رواتب الموظفين</strong></a>
            ثم ارجع واضغط «عرض».
        </div>
    <?php endif; ?>

    <?php if ($showEmployeeList): ?>
    <div class="hr-pr-post-workspace">
        <div class="hr-pr-post-main">
            <div class="hr-pr-post-panel hr-pr-post-emp-panel">
                <h2 class="hr-pr-post-panel-title">جدول الموظفين</h2>
                <div class="hr-pr-post-panel-body">
            <form method="post" action="<?= esc($listUrl) ?>" id="hr-pr-post-action-form">
                <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                <input type="hidden" name="_action" id="hr-pr-post-action" value="">
                <input type="hidden" name="pay_year" value="<?= (int) $payYear ?>">
                <input type="hidden" name="pay_month" value="<?= (int) $payMonth ?>">
                <input type="hidden" name="filter_dept_id" id="hr-pr-post-filter-dept-hidden" value="<?= (int) $filterDeptId ?>">
                <input type="hidden" name="filter_employee_id" value="<?= (int) $filterEmpId ?>">

                <div class="hr-pr-post-grid-wrap hr-pr-post-emp-grid-wrap">
                    <table class="hr-pr-post-grid-table hr-pr-post-emp-table">
                        <colgroup>
                            <col class="hr-pr-post-col-seq">
                            <col class="hr-pr-post-col-code">
                            <col class="hr-pr-post-col-name">
                        </colgroup>
                        <thead>
                        <tr>
                            <th class="hr-pr-post-col-seq">تسلسل</th>
                            <th class="hr-pr-post-emp-code-head">
                                <span class="hr-pr-post-emp-code-head-inner">
                                    <label class="hr-pr-post-chk-cell">
                                        <input type="checkbox" id="hr-pr-post-check-all" title="تحديد الكل (للاحتساب أو القسائم)">
                                        <span class="sr-only">تحديد الكل</span>
                                    </label>
                                    <span>رقم الموظف</span>
                                </span>
                            </th>
                            <th class="hr-pr-post-emp-name">اسم الموظف</th>
                            <th>الراتب الأساسي</th>
                            <th>إجمالي العلاوات</th>
                            <th>العلاوات الشهرية</th>
                            <th>الاقتطاعات</th>
                            <th>ضمان الموظف</th>
                            <th>ضمان الشركة</th>
                            <?php if ($showIncomeTaxCol): ?>
                            <th>ضريبة الدخل</th>
                            <?php endif; ?>
                            <th>صافي الراتب</th>
                            <th>الحالة</th>
                        </tr>
                        </thead>
                        <tbody id="hr-pr-post-grid-body">
                        <?php if (!$statusRows): ?>
                            <tr class="hr-pr-post-row hr-pr-post-row--empty">
                                <td colspan="<?= $empTableColCount ?>" class="muted">
                                    لا يوجد موظفون مطابقون للفلتر — غيّر القسم أو الموظف.
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($statusRows as $idx => $r):
                            $eid = (int) $r['id'];
                            $status = (string) ($r['status'] ?? 'none');
                            $canSelect = !empty($r['has_setup']) && $status !== 'posted';
                            $canSlipPrint = ($status === 'calculated' || $status === 'posted')
                                && (int) ($r['salary_id'] ?? 0) > 0;
                            $showRowCheckbox = $canSelect || $canSlipPrint;
                            $empStatusCode = hr_payroll_employee_status_code($status, !empty($r['has_setup']));
                            $statusLabel = hr_payroll_employee_status_label($status, !empty($r['has_setup']));
                            $rowClass = 'hr-pr-post-row';
                            if ($status === 'posted') {
                                $rowClass .= ' hr-pr-post-row--posted';
                            } elseif ($status === 'calculated') {
                                $rowClass .= ' hr-pr-post-row--calculated';
                            } elseif ($status === 'none' && !empty($r['has_setup'])) {
                                $rowClass .= ' hr-pr-post-row--open';
                            } elseif (empty($r['has_setup'])) {
                                $rowClass .= ' hr-pr-post-row--no-setup';
                            }
                            $hasAmounts = $status !== 'none';
                            $detailJson = json_encode([
                                'perm' => $r['permanent_allow_lines'] ?? [],
                                'month' => $r['monthly_allow_lines'] ?? [],
                                'deduct' => $r['deduction_lines'] ?? [],
                            ], JSON_UNESCAPED_UNICODE);
                            if ($detailJson === false) {
                                $detailJson = '{}';
                            }
                        ?>
                            <tr class="<?= esc($rowClass) ?>"
                                data-id="<?= $eid ?>"
                                data-salary-id="<?= (int) ($r['salary_id'] ?? 0) ?>"
                                data-status="<?= esc($status) ?>"
                                data-can-select="<?= $canSelect ? '1' : '0' ?>"
                                data-can-slip="<?= $canSlipPrint ? '1' : '0' ?>"
                                data-can-cancel="<?= $status === 'calculated' ? '1' : '0' ?>"
                                data-emp-name="<?= esc((string) ($r['name_ar'] ?? '')) ?>"
                                data-detail="<?= esc($detailJson) ?>"
                                tabindex="0">
                                <td dir="ltr" class="num hr-pr-post-col-seq"><?= (int) $idx + 1 ?></td>
                                <td class="hr-pr-post-emp-code-cell">
                                    <?php if ($showRowCheckbox): ?>
                                        <label class="hr-pr-post-chk-cell">
                                            <input type="checkbox"
                                                   class="hr-pr-post-emp-chk"
                                                   <?php if ($canSelect): ?>name="employee_ids[]" value="<?= $eid ?>"<?php endif; ?>>
                                            <span class="sr-only">تحديد</span>
                                        </label>
                                    <?php endif; ?>
                                    <span dir="ltr"><?= esc((string) ($r['emp_code'] ?? '—')) ?></span>
                                </td>
                                <td class="hr-pr-post-emp-name" title="<?= esc((string) ($r['name_ar'] ?? '')) ?>">
                                    <?= esc((string) ($r['name_ar'] ?? '')) ?>
                                </td>
                                <td dir="ltr" class="num"><?= !empty($r['has_setup'])
                                    ? esc(number_format((float) ($r['base_salary'] ?? 0), 3)) : '—' ?></td>
                                <?php hr_pr_post_render_drill_cell(
                                    (float) ($r['permanent_allow_total'] ?? 0),
                                    is_array($r['permanent_allow_lines'] ?? null) ? $r['permanent_allow_lines'] : [],
                                    'perm',
                                    $hasAmounts
                                ); ?>
                                <?php hr_pr_post_render_drill_cell(
                                    (float) ($r['monthly_allow_total'] ?? 0),
                                    is_array($r['monthly_allow_lines'] ?? null) ? $r['monthly_allow_lines'] : [],
                                    'month',
                                    $hasAmounts
                                ); ?>
                                <?php hr_pr_post_render_drill_cell(
                                    (float) ($r['deductions'] ?? 0),
                                    is_array($r['deduction_lines'] ?? null) ? $r['deduction_lines'] : [],
                                    'deduct',
                                    $hasAmounts
                                ); ?>
                                <td dir="ltr" class="num"><?= $hasAmounts && (float) ($r['social_security_emp'] ?? 0) > 0
                                    ? esc(number_format((float) $r['social_security_emp'], 3)) : '—' ?></td>
                                <td dir="ltr" class="num"><?= $hasAmounts && (float) ($r['social_security_er'] ?? 0) > 0
                                    ? esc(number_format((float) $r['social_security_er'], 3)) : '—' ?></td>
                                <?php if ($showIncomeTaxCol): ?>
                                <td dir="ltr" class="num"><?= $hasAmounts && (float) ($r['income_tax'] ?? 0) > 0
                                    ? esc(number_format((float) $r['income_tax'], 3)) : '—' ?></td>
                                <?php endif; ?>
                                <td dir="ltr" class="num hr-pr-post-col-net"><?= $hasAmounts
                                    ? esc(number_format((float) ($r['net_salary'] ?? 0), 3)) : '—' ?></td>
                                <td>
                                    <?php if ($empStatusCode !== 'none'): ?>
                                        <span class="hr-pr-post-status hr-pr-post-status--<?= esc($empStatusCode) ?>">
                                            <?= esc($statusLabel) ?>
                                        </span>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <?php if ($statusRows): ?>
                        <tfoot>
                        <tr class="hr-pr-post-totals-row">
                            <td colspan="3"><strong>الإجمالي</strong></td>
                            <td dir="ltr" class="num"><strong><?= esc(number_format($gridTotals['base'], 3)) ?></strong></td>
                            <td dir="ltr" class="num"><strong><?= esc(number_format($gridTotals['perm_allow'], 3)) ?></strong></td>
                            <td dir="ltr" class="num"><strong><?= esc(number_format($gridTotals['month_allow'], 3)) ?></strong></td>
                            <td dir="ltr" class="num"><strong><?= esc(number_format($gridTotals['deductions'], 3)) ?></strong></td>
                            <td dir="ltr" class="num"><strong><?= esc(number_format($gridTotals['ss'], 3)) ?></strong></td>
                            <td dir="ltr" class="num"><strong><?= esc(number_format($gridTotals['ss_er'], 3)) ?></strong></td>
                            <?php if ($showIncomeTaxCol): ?>
                            <td dir="ltr" class="num"><strong><?= esc(number_format($gridTotals['tax'], 3)) ?></strong></td>
                            <?php endif; ?>
                            <td dir="ltr" class="num"><strong><?= esc(number_format($gridTotals['net'], 3)) ?></strong></td>
                            <td></td>
                        </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </form>

            <div class="dashboard-ora-toolbar hr-pr-post-footer-bar no-print">
                <button type="button" class="btn btn-secondary btn-sm" data-side-action="print_selected" title="طباعة الموظفين المحدّدين بمربع التحديد">طباعة</button>
                <button type="button" class="btn btn-primary hr-pr-post-footer-post" data-side-action="payroll_post">
                    ترحيل
                </button>
                <span class="hr-pr-post-footer-meta muted">
                    <?= esc(hr_payroll_period_label($payYear, $payMonth)) ?>
                    — صافي: <strong dir="ltr"><?= esc(number_format($gridTotals['net'], 3)) ?></strong>
                </span>
            </div>

            <?php if (!empty($disburse['has_rows'])): ?>
            <div class="hr-pr-post-disburse-strip no-print" aria-label="ملخص الصرف النقدي">
                <div class="hr-pr-post-disburse-strip-title">ملخص الصرف النقدي</div>
                <dl class="hr-pr-post-disburse-grid hr-pr-post-disburse-grid--inline">
                    <div>
                        <dt>إجمالي رواتب الموظفين</dt>
                        <dd dir="ltr"><?= esc(number_format((float) $disburse['gross'], 3)) ?></dd>
                    </div>
                    <div>
                        <dt>حصة الموظفين — مُقتطعة من الراتب</dt>
                        <dd dir="ltr"><?= esc(number_format((float) $disburse['employee_ss'], 3)) ?></dd>
                    </div>
                    <div>
                        <dt>رواتب مستحقة — صافي للموظفين</dt>
                        <dd class="hr-pr-post-disburse-highlight" dir="ltr"><?= esc(number_format((float) $disburse['fund_salaries'], 3)) ?></dd>
                    </div>
                    <div>
                        <dt>أمانات ضمان اجتماعي (موظف + شركة)</dt>
                        <dd class="hr-pr-post-disburse-highlight" dir="ltr"><?= esc(number_format((float) $disburse['ss_payable_total'], 3)) ?></dd>
                    </div>
                    <div class="hr-pr-post-disburse-total-row">
                        <dt>إجمالي نقدي للصرف</dt>
                        <dd class="hr-pr-post-disburse-total" dir="ltr"><?= esc(number_format((float) $disburse['fund_total'], 3)) ?></dd>
                    </div>
                </dl>
            </div>
            <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php else: ?>
        <div class="hr-pr-post-panel hr-pr-post-intro-panel">
            <p class="muted hr-pr-post-pending-hint">اختر السنة والشهر من شريط الفترة أعلاه ثم اضغط «عرض» لإظهار جدول الموظفين.</p>
        </div>
    <?php endif; ?>

    <div class="hr-pr-post-report-print-host no-print" aria-hidden="true">
        <?php hr_payroll_month_report_render_doc(
            $pdo,
            $payYear,
            $payMonth,
            $printReportRows,
            $printReportTotals,
            $printReportMonthStatus,
            $printReportMovement['movement_no'],
            $printReportMovement['movement_desc'],
            $printReportMovement['movement_date'],
            $printReportTitle,
            $printReportDate,
            $printFilterLabel
        ); ?>
    </div>

    <div id="hr-pr-post-detail-modal" class="hr-pr-post-detail-modal" hidden aria-hidden="true">
        <div class="hr-pr-post-detail-backdrop" data-detail-close="1"></div>
        <div class="hr-pr-post-detail-dialog" role="dialog" aria-modal="true" aria-labelledby="hr-pr-post-detail-title">
            <div class="hr-pr-post-detail-head">
                <strong id="hr-pr-post-detail-title">تفاصيل</strong>
                <button type="button" class="hr-pr-post-detail-close" id="hr-pr-post-detail-close" aria-label="إغلاق">×</button>
            </div>
            <div class="hr-pr-post-detail-body">
                <table class="hr-pr-post-detail-table">
                    <thead>
                    <tr>
                        <th>رقم</th>
                        <th>البند</th>
                        <th id="hr-pr-post-detail-src-head" hidden>المصدر</th>
                        <th>المبلغ</th>
                    </tr>
                    </thead>
                    <tbody id="hr-pr-post-detail-tbody"></tbody>
                </table>
                <p class="hr-pr-post-detail-empty muted" id="hr-pr-post-detail-empty" hidden>لا توجد بنود.</p>
            </div>
        </div>
    </div>
</div>

<div id="hr-pr-post-busy" class="hr-pr-post-busy no-print" hidden aria-live="polite" aria-busy="true">
    <div class="hr-pr-post-busy-panel" role="status">
        <div class="hr-pr-post-busy-spinner" aria-hidden="true"></div>
        <p class="hr-pr-post-busy-msg" id="hr-pr-post-busy-msg">جاري التنفيذ...</p>
        <p class="hr-pr-post-busy-hint">يرجى الانتظار — لا تغلق المتصفح حتى انتهاء العملية</p>
    </div>
</div>

<div id="hr-pr-post-slip-print-overlay" class="sales-inv-print-overlay no-print" hidden>
    <div class="sales-inv-print-overlay-panel">
        <div class="sales-inv-print-overlay-head">
            <h3 class="sales-inv-print-overlay-title">معاينة قسائم الراتب — اضغط «قسيمة الراتب» أو «طباعة» في الشريط العلوي</h3>
            <div class="sales-inv-print-overlay-actions">
                <button type="button" class="btn btn-secondary btn-sm" id="hr-pr-post-slip-print-close">إغلاق</button>
            </div>
        </div>
        <div class="sales-inv-print-preview-body" id="hr-pr-post-slip-print-preview"></div>
    </div>
</div>

<script src="<?= esc($mchipJsUrl) ?>" defer></script>
<script src="<?= esc($jsUrl) ?>" defer></script>
