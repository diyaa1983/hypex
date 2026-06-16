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

$empTableColCount = $showIncomeTaxCol ? 10 : 9;
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">

<div class="hr-pr-post-classic hr-pr-post-ora-screen<?= $showEmployeeList ? '' : ' hr-pr-post-classic--pending' ?>"
     data-list-url="<?= esc($listUrl) ?>"
     data-slip-base="<?= esc($slipBaseUrl) ?>"
     data-year="<?= (int) $payYear ?>"
     data-month="<?= (int) $payMonth ?>"
     data-list-shown="<?= $showEmployeeList ? '1' : '0' ?>"
     data-gate-ok="<?= $gate['ok'] ? '1' : '0' ?>"
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

    <form method="get" action="<?= esc($indexUrl) ?>" id="hr-pr-post-period-form" class="hr-pr-post-doc-form">
        <input type="hidden" name="r" value="hr_payroll_posting">
        <input type="hidden" name="show" value="1">
        <div class="hr-pr-post-panel hr-pr-post-doc-panel">
            <h2 class="hr-pr-post-panel-title">ادخل المعلومات</h2>
            <div class="hr-pr-post-panel-body">
        <div class="hr-pr-post-filters-panel">
            <div class="hr-pr-post-filters-stack">
                <label class="hr-pr-post-filter-line">
                    <span>العام</span>
                    <input class="input hr-pr-post-inline-input" type="number" name="year" min="2000" max="2100"
                           value="<?= (int) $payYear ?>" required>
                </label>
                <label class="hr-pr-post-filter-line">
                    <span>الشهر</span>
                    <div class="hr-pr-post-ora-lov">
                        <select class="input hr-pr-post-inline-input hr-pr-post-ora-lov-field" name="month" required>
                            <?php foreach ($monthPickerOptions as $opt):
                                $m = (int) ($opt['month'] ?? 0);
                                if ($m < 1 || $m > 12) {
                                    continue;
                                }
                                $monthLabel = sprintf('%02d', $m) . ' — ' . ($monthNames[$m] ?? (string) $m)
                                    . (string) ($opt['label_suffix'] ?? '');
                            ?>
                                <option value="<?= $m ?>" <?= $payMonth === $m ? 'selected' : '' ?>>
                                    <?= esc($monthLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="hr-pr-post-ora-lov-btn" tabindex="-1" aria-label="اختيار الشهر" title="اختيار الشهر"></button>
                    </div>
                </label>
                <label class="hr-pr-post-filter-line">
                    <span>القسم</span>
                    <div class="hr-pr-post-ora-lov">
                        <select class="input hr-pr-post-inline-input hr-pr-post-ora-lov-field" name="dept_id" id="hr-pr-post-filter-dept"
                                <?= $filterEmpId > 0 ? 'disabled' : '' ?>>
                            <option value="0" <?= $filterDeptId === 0 ? 'selected' : '' ?>>— جميع الأقسام —</option>
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
                </label>
                <label class="hr-pr-post-filter-line">
                    <span>الموظف</span>
                    <div class="hr-pr-post-ora-lov">
                        <select class="input hr-pr-post-inline-input hr-pr-post-ora-lov-field" name="employee_id" id="hr-pr-post-filter-emp">
                            <option value="0" <?= $filterEmpId === 0 ? 'selected' : '' ?>>— جميع الموظفين —</option>
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
                </label>
                <div class="hr-pr-post-filter-line hr-pr-post-filter-line--submit">
                    <span>عرض</span>
                    <button type="submit" class="btn btn-primary btn-sm">عرض</button>
                </div>
            </div>
        </div>
        <div class="hr-pr-post-doc-summary hr-pr-post-doc-summary--box">
            <?php if ($showEmployeeList): ?>
                <span class="hr-pr-post-status hr-pr-post-status--<?= esc((string) ($monthStatus['code'] ?? 'open')) ?>">
                    <?= esc((string) ($monthStatus['label'] ?? 'مفتوح')) ?>
                </span>
                <span class="hr-pr-post-doc-summary-sep">|</span>
                <span><?= esc($filterLabel) ?></span>
                <span class="hr-pr-post-doc-summary-sep">|</span>
                <span>محتسب: <strong dir="ltr"><?= (int) ($summary['calculated'] ?? 0) ?></strong></span>
                <span class="hr-pr-post-doc-summary-sep">|</span>
                <span>مرحّل: <strong dir="ltr"><?= (int) ($summary['posted'] ?? 0) ?></strong></span>
                <span class="hr-pr-post-doc-summary-sep">|</span>
                <span>في القائمة: <strong dir="ltr"><?= count($statusRows) ?></strong></span>
                <?php if ($maxPosted): ?>
                    <span class="hr-pr-post-doc-summary-sep">|</span>
                    <span class="muted">آخر مرحّل:
                        <strong dir="ltr"><?= sprintf('%02d', (int) $maxPosted['month']) ?></strong>
                        — <?= esc($monthNames[(int) $maxPosted['month']] ?? '') ?>
                    </span>
                <?php endif; ?>
            <?php else: ?>
                <span class="hr-pr-post-status hr-pr-post-status--<?= esc((string) ($monthStatus['code'] ?? 'open')) ?>">
                    <?= esc((string) ($monthStatus['label'] ?? 'مفتوح')) ?>
                </span>
                <span class="hr-pr-post-doc-summary-sep">|</span>
                <span class="muted">اختر «عرض» لتحميل جدول الموظفين.</span>
            <?php endif; ?>
        </div>
            </div>
        </div>

        <div class="hr-pr-post-grid-wrap hr-pr-post-doc-header">
            <table class="hr-pr-post-grid-table">
                <thead>
                <tr>
                    <th>رقم الحركة</th>
                    <th>وصف الحركة</th>
                    <th>تاريخ الحركة</th>
                    <th>الحالة</th>
                </tr>
                </thead>
                <tbody>
                <tr class="hr-pr-post-doc-fields">
                    <td dir="ltr"><code class="hr-pr-post-doc-code"><?= esc($movementNo) ?></code></td>
                    <td class="hr-pr-post-doc-desc"><?= esc($movementDesc) ?></td>
                    <td dir="ltr"><?= esc(format_date_dmY($movementDate)) ?></td>
                    <td>
                        <span class="hr-pr-post-status hr-pr-post-status--<?= esc((string) ($monthStatus['code'] ?? 'open')) ?>">
                            <?= esc((string) ($monthStatus['label'] ?? 'مفتوح')) ?>
                        </span>
                    </td>
                </tr>
                </tbody>
            </table>
        </div>
    </form>

    <?php if ($showEmployeeList && $gateMessage !== '' && ($gateAlert === 'warn' || $gateAlert === 'info')): ?>
        <div class="alert alert-<?= $gateAlert === 'warn' ? 'error' : 'info' ?> hr-pr-post-gate hr-ora-inline-msg"><?= esc($gateMessage) ?></div>
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
        <aside class="hr-pr-post-panel hr-pr-post-side-panel no-print" aria-label="إجراءات قيد الرواتب">
            <h2 class="hr-pr-post-panel-title">إجراءات</h2>
            <div class="hr-pr-post-panel-body">
            <div class="hr-pr-post-side-status hr-pr-post-side-status--<?= esc((string) ($monthStatus['code'] ?? 'open')) ?>">
                <span class="hr-pr-post-side-status-label">حالة الشهر</span>
                <strong><?= esc((string) ($monthStatus['label'] ?? 'مفتوح')) ?></strong>
            </div>
            <button type="button" class="btn btn-primary hr-pr-post-side-btn" data-side-action="payroll_calculate">
                احتساب الرواتب
            </button>
            <button type="button" class="btn btn-secondary hr-pr-post-side-btn" data-side-action="payroll_cancel_calc">
                إلغاء الاحتساب
            </button>
            <button type="button" class="btn btn-primary hr-pr-post-side-btn hr-pr-post-side-btn--post"
                    data-side-action="payroll_post">
                ترحيل
            </button>
            <button type="button" class="btn btn-secondary hr-pr-post-side-btn" data-side-action="payroll_unpost">
                فك الترحيل
            </button>
            <button type="button" class="btn btn-secondary hr-pr-post-side-btn" data-side-action="select_pending">
                إضافة الموظفين
            </button>
            <button type="button" class="btn btn-secondary hr-pr-post-side-btn" data-side-action="print_slip">
                قسيمة الراتب
            </button>
            </div>
        </aside>

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
                        <thead>
                        <tr>
                            <th class="hr-pr-post-emp-code-head">
                                <span class="hr-pr-post-emp-code-head-inner">
                                    <label class="hr-pr-post-chk-cell">
                                        <input type="checkbox" id="hr-pr-post-check-all" title="تحديد الكل (قابل للاحتساب)">
                                        <span class="sr-only">تحديد الكل</span>
                                    </label>
                                    <span>رقم الموظف</span>
                                </span>
                            </th>
                            <th>اسم الموظف</th>
                            <th>الراتب الأساسي</th>
                            <th>إجمالي العلاوات</th>
                            <th>العلاوات الشهرية</th>
                            <th>الاقتطاعات</th>
                            <th>ضمان الموظف</th>
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
                        <?php foreach ($statusRows as $r):
                            $eid = (int) $r['id'];
                            $status = (string) ($r['status'] ?? 'none');
                            $canSelect = !empty($r['has_setup']) && $status !== 'posted';
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
                                data-can-cancel="<?= $status === 'calculated' ? '1' : '0' ?>"
                                data-emp-name="<?= esc((string) ($r['name_ar'] ?? '')) ?>"
                                data-detail="<?= esc($detailJson) ?>"
                                tabindex="0">
                                <td class="hr-pr-post-emp-code-cell">
                                    <?php if ($canSelect): ?>
                                        <label class="hr-pr-post-chk-cell">
                                            <input type="checkbox" class="hr-pr-post-emp-chk" name="employee_ids[]" value="<?= $eid ?>">
                                            <span class="sr-only">تحديد</span>
                                        </label>
                                    <?php endif; ?>
                                    <span dir="ltr"><?= esc((string) ($r['emp_code'] ?? '—')) ?></span>
                                </td>
                                <td class="hr-pr-post-emp-name">
                                    <?= esc((string) ($r['name_ar'] ?? '')) ?>
                                    <?php if (empty($r['has_setup'])): ?>
                                        <span class="hr-pr-post-cell-note">بدون راتب معرّف</span>
                                    <?php elseif (empty($r['subject_to_social_security'])): ?>
                                        <span class="hr-pr-post-cell-note">غير خاضع للضمان</span>
                                    <?php endif; ?>
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
                            <td colspan="2"><strong>الإجمالي</strong></td>
                            <td dir="ltr" class="num"><strong><?= esc(number_format($gridTotals['base'], 3)) ?></strong></td>
                            <td dir="ltr" class="num"><strong><?= esc(number_format($gridTotals['perm_allow'], 3)) ?></strong></td>
                            <td dir="ltr" class="num"><strong><?= esc(number_format($gridTotals['month_allow'], 3)) ?></strong></td>
                            <td dir="ltr" class="num"><strong><?= esc(number_format($gridTotals['deductions'], 3)) ?></strong></td>
                            <td dir="ltr" class="num"><strong><?= esc(number_format($gridTotals['ss'], 3)) ?></strong></td>
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
                <button type="button" class="btn btn-primary hr-pr-post-footer-post" data-side-action="payroll_post">
                    ترحيل
                </button>
                <span class="hr-pr-post-footer-meta muted">
                    <?= esc(hr_payroll_period_label($payYear, $payMonth)) ?>
                    — صافي: <strong dir="ltr"><?= esc(number_format($gridTotals['net'], 3)) ?></strong>
                </span>
            </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($postedMonths !== []): ?>
    <details class="hr-pr-post-extra-panel no-print" open>
        <summary>الأشهر المرحّلة — <?= (int) $payYear ?></summary>
        <div class="hr-pr-post-grid-wrap hr-pr-post-posted-wrap">
            <table class="hr-pr-post-grid-table hr-pr-post-posted-table">
                <thead>
                <tr>
                    <th>الشهر</th>
                    <th>موظفون</th>
                    <th>إجمالي الرواتب</th>
                    <th>رقم القيد</th>
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
                    $journalUrl = $jid > 0 ? acc_report_journal_voucher_url($jid, $entryNo) : '';
                    $rowClass = 'hr-pr-post-posted-row';
                    if (!empty($pmRow['is_current'])) {
                        $rowClass .= ' hr-pr-post-posted-row--current';
                    }
                    if (!empty($pmRow['can_unpost'])) {
                        $rowClass .= ' hr-pr-post-posted-row--unpost';
                    }
                    ?>
                    <tr class="<?= esc($rowClass) ?>">
                        <td>
                            <a href="<?= esc($monthUrl) ?>"><?= esc(sprintf('%02d', $m) . ' — ' . ($monthNames[$m] ?? (string) $m)) ?></a>
                            <?php if (!empty($pmRow['can_unpost'])): ?>
                                <span class="hr-pr-post-meta-tag">آخر مرحّل</span>
                            <?php endif; ?>
                        </td>
                        <td dir="ltr" class="num"><?= (int) ($pmRow['emp_count'] ?? 0) ?></td>
                        <td dir="ltr" class="num"><?= esc(number_format((float) ($pmRow['gross_total'] ?? 0), 3)) ?></td>
                        <td dir="ltr"><?= $entryNo !== '' ? '<code>' . esc($entryNo) . '</code>' : '—' ?></td>
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
    </details>
    <?php endif; ?>

    <?php if (!empty($disburse['has_rows'])): ?>
    <details class="hr-pr-post-extra-panel no-print">
        <summary>ملخص ترحيل الرواتب والصرف النقدي</summary>
        <div class="hr-pr-post-disburse">
            <dl class="hr-pr-post-disburse-grid">
                <div>
                    <dt>إجمالي رواتب الموظفين</dt>
                    <dd><?= esc(number_format((float) $disburse['gross'], 3)) ?></dd>
                </div>
                <div>
                    <dt>حصة الموظفين — مُقتطعة من الراتب</dt>
                    <dd><?= esc(number_format((float) $disburse['employee_ss'], 3)) ?></dd>
                </div>
                <div>
                    <dt>رواتب مستحقة — صافي للموظفين</dt>
                    <dd class="hr-pr-post-disburse-highlight"><?= esc(number_format((float) $disburse['fund_salaries'], 3)) ?></dd>
                </div>
                <div>
                    <dt>ضمان اجتماعي مستحق (موظف + شركة)</dt>
                    <dd class="hr-pr-post-disburse-highlight"><?= esc(number_format((float) $disburse['ss_payable_total'], 3)) ?></dd>
                </div>
                <div>
                    <dt>إجمالي نقدي للصرف (رواتب + ضمان)</dt>
                    <dd class="hr-pr-post-disburse-total"><?= esc(number_format((float) $disburse['fund_total'], 3)) ?></dd>
                </div>
            </dl>
        </div>
    </details>
    <?php endif; ?>

    <?php else: ?>
        <div class="hr-pr-post-panel hr-pr-post-intro-panel">
            <p class="muted hr-pr-post-pending-hint">اختر السنة والشهر من «ادخل المعلومات» ثم اضغط «عرض» لإظهار جدول الموظفين.</p>
        </div>
    <?php endif; ?>

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

<script src="<?= esc($jsUrl) ?>" defer></script>
