<?php
declare(strict_types=1);

require_once app_path('includes/hr_salary.php');
require_once app_path('includes/company_settings.php');
require_once app_path('includes/hr_employee_monthly_payroll.php');
require_once app_path('includes/hr_employee_overtime.php');
require_once app_path('includes/document_header.php');

/** @return array<int, array{id:int, emp_code:string, name_ar:string}> */
function hr_payroll_slip_report_employees_for_period(PDO $pdo, int $year, int $month): array
{
    if ($year < 2000 || $month < 1 || $month > 12) {
        return [];
    }

    hr_employee_ensure_schema($pdo);

    try {
        $st = $pdo->prepare(
            'SELECT DISTINCT e.id, e.emp_code, e.name_ar
             FROM hr_employee e
             INNER JOIN hr_salary s ON s.employee_id = e.id AND s.pay_year = ? AND s.pay_month = ?
             ORDER BY CASE WHEN e.emp_code REGEXP \'^[0-9]+$\' THEN CAST(e.emp_code AS UNSIGNED) ELSE 999999999 END ASC,
                      e.emp_code ASC, e.id ASC'
        );
        $st->execute([$year, $month]);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** @return array<string, mixed>|null */
function hr_payroll_slip_report_build_by_salary_id(PDO $pdo, int $salaryId): ?array
{
    if ($salaryId < 1) {
        return null;
    }

    try {
        $st = $pdo->prepare(
            'SELECT employee_id, pay_year, pay_month FROM hr_salary WHERE id = ? LIMIT 1'
        );
        $st->execute([$salaryId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $employeeId = (int) ($row['employee_id'] ?? 0);
        $year = (int) ($row['pay_year'] ?? 0);
        $month = (int) ($row['pay_month'] ?? 0);
        if ($employeeId < 1 || $year < 2000 || $month < 1 || $month > 12) {
            return null;
        }

        return hr_payroll_slip_report_build($pdo, $employeeId, $year, $month);
    } catch (Throwable $e) {
        return null;
    }
}

function hr_payroll_slip_report_salary_id(PDO $pdo, int $employeeId, int $year, int $month): int
{
    if ($employeeId < 1 || $year < 2000 || $month < 1 || $month > 12) {
        return 0;
    }

    try {
        $st = $pdo->prepare(
            'SELECT id FROM hr_salary WHERE employee_id = ? AND pay_year = ? AND pay_month = ? LIMIT 1'
        );
        $st->execute([$employeeId, $year, $month]);
        $id = (int) ($st->fetchColumn() ?: 0);

        return $id > 0 ? $id : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

/** @return array<string, mixed>|null */
function hr_payroll_slip_report_build(PDO $pdo, int $employeeId, int $year, int $month): ?array
{
    $salaryId = hr_payroll_slip_report_salary_id($pdo, $employeeId, $year, $month);
    if ($salaryId < 1) {
        return null;
    }

    $row = hr_salary_load_for_print($pdo, $salaryId);
    if (!$row) {
        return null;
    }

    $base = (float) ($row['base_salary'] ?? 0);
    $allowCol = (float) ($row['allowances'] ?? 0);
    $overtime = (float) ($row['overtime'] ?? 0);
    $bonus = (float) ($row['bonus'] ?? 0);
    $dedCol = (float) ($row['deductions'] ?? 0);
    $ssEmp = (float) ($row['social_security_emp'] ?? 0);
    $tax = (float) ($row['income_tax'] ?? 0);
    $allowanceLines = is_array($row['allowance_lines'] ?? null) ? (array) $row['allowance_lines'] : [];
    $allowFromLines = 0.0;
    foreach ($allowanceLines as $ln) {
        $allowFromLines += (float) ($ln['amount'] ?? 0);
    }
    $allowTotal = $allowanceLines !== [] ? round($allowFromLines, 3) : round($allowCol, 3);

    $monthlyAllowRows = hr_employee_monthly_payroll_lines_list($pdo, $employeeId, $year, $month, 'allowance');
    $monthlyCompIds = [];
    foreach ($monthlyAllowRows as $ln) {
        $cid = (int) ($ln['component_id'] ?? 0);
        if ($cid > 0) {
            $monthlyCompIds[$cid] = true;
        }
    }

    $allowDetailBaseFixed = [
        [
            'name' => 'الراتب الأساسي',
            'amount' => round($base, 3),
            'recurring' => true,
        ],
    ];
    $allowDetailMonthly = [];
    $allowLines = [];
    $permanentAllowTotal = 0.0;
    $monthlyAllowTotal = 0.0;
    $seenMonthlyCompIds = [];
    foreach ($allowanceLines as $ln) {
        $cid = (int) ($ln['component_id'] ?? 0);
        $amount = round((float) ($ln['amount'] ?? 0), 3);
        $name = trim((string) ($ln['name_ar'] ?? ''));
        $isMonthly = $cid > 0 && isset($monthlyCompIds[$cid]);
        $item = [
            'name' => $name !== '' ? $name : 'علاوة',
            'amount' => $amount,
            'recurring' => !$isMonthly,
        ];
        $allowLines[] = $item;
        if ($isMonthly) {
            $allowDetailMonthly[] = $item;
            $monthlyAllowTotal += $amount;
            $seenMonthlyCompIds[$cid] = true;
        } else {
            $allowDetailBaseFixed[] = $item;
            $permanentAllowTotal += $amount;
        }
    }

    foreach ($monthlyAllowRows as $ln) {
        $cid = (int) ($ln['component_id'] ?? 0);
        if ($cid > 0 && isset($seenMonthlyCompIds[$cid])) {
            continue;
        }
        $amount = round((float) ($ln['amount'] ?? 0), 3);
        $name = trim((string) ($ln['name_ar'] ?? ''));
        $item = [
            'name' => $name !== '' ? $name : 'علاوة شهرية',
            'amount' => $amount,
            'recurring' => false,
        ];
        $allowLines[] = $item;
        $allowDetailMonthly[] = $item;
        $monthlyAllowTotal += $amount;
    }

    $otItem = hr_payroll_overtime_allowance_line($pdo, $employeeId, $year, $month, $overtime);
    if ($otItem) {
        $item = [
            'name' => (string) ($otItem['name_ar'] ?? 'عمل اضافة لمرة واحدة'),
            'amount' => (float) ($otItem['amount'] ?? 0),
            'recurring' => false,
        ];
        $allowLines[] = $item;
        $allowDetailMonthly[] = $item;
        $monthlyAllowTotal += (float) $item['amount'];
        $overtime = (float) $item['amount'];
    }

    $splitAllowTotal = round($permanentAllowTotal + $monthlyAllowTotal, 3);
    $allowDiff = round($allowTotal - $splitAllowTotal, 3);
    if (abs($allowDiff) > 0.0005) {
        $permanentAllowTotal = round($permanentAllowTotal + $allowDiff, 3);
    }
    $baseWithPermanentAllow = round($base + $permanentAllowTotal, 3);
    $gross = round($base + $allowTotal + $overtime + $bonus, 3);
    $totalDed = round($dedCol + $ssEmp + $tax, 3);
    $net = (float) ($row['net_salary'] ?? 0);

    $bankBranch = trim((string) ($row['bank_name'] ?? ''));
    $bankMain = trim((string) ($row['salary_bank_name'] ?? ''));
    $bankDisplay = $bankMain;
    if ($bankBranch !== '' && $bankBranch !== $bankMain) {
        $bankDisplay = $bankMain !== '' ? $bankMain . ' — ' . $bankBranch : $bankBranch;
    } elseif ($bankDisplay === '') {
        $bankDisplay = $bankBranch;
    }

    $jobCode = trim((string) ($row['national_id'] ?? ''));
    if ($jobCode === '') {
        $jobCode = '—';
    }

    $hireIso = (string) ($row['hire_date'] ?? '');
    $hireDisplay = $hireIso !== '' ? format_date_dmY($hireIso) : '—';

    $workDays = (int) date('t', strtotime(sprintf('%04d-%02d-01', $year, $month)));

    company_settings_ensure_default_row($pdo);
    $company = [
        'name_ar' => 'الشركة',
        'name_en' => '',
        'address_ar' => '',
        'address_en' => '',
        'phone' => '',
        'fax' => '',
        'po_box' => '',
        'logo_url' => null,
    ];
    try {
        $stCo = $pdo->query(
            'SELECT company_name_ar, address_ar, phone, logo_path FROM sys_company_settings WHERE id = 1 LIMIT 1'
        );
        $co = $stCo ? $stCo->fetch(PDO::FETCH_ASSOC) : false;
        if ($co) {
            $company['name_ar'] = trim((string) ($co['company_name_ar'] ?? '')) ?: $company['name_ar'];
            $company['name_en'] = $company['name_ar'];
            $company['address_ar'] = trim((string) ($co['address_ar'] ?? ''));
            $company['address_en'] = $company['address_ar'];
            $company['phone'] = trim((string) ($co['phone'] ?? ''));
            $logoPath = trim((string) ($co['logo_path'] ?? ''));
            if ($logoPath !== '' && is_file(app_path($logoPath))) {
                $company['logo_url'] = app_url($logoPath);
            }
        }
    } catch (Throwable $e) {
        // ignored
    }

    $subjectSs = (int) ($row['subject_to_social_security'] ?? 0) === 1;
    if (!$subjectSs) {
        $ssEmp = 0.0;
    }

    $otherDedLines = [];
    foreach ($row['deduction_lines'] ?? [] as $ln) {
        $name = trim((string) ($ln['name_ar'] ?? ''));
        if ($name === '') {
            continue;
        }
        $otherDedLines[] = [
            'name' => $name,
            'amount' => (float) ($ln['amount'] ?? 0),
        ];
    }

    $summaryAllow = [
        ['label' => 'الراتب الأساسي والعلاوات الثابتة', 'amount' => $baseWithPermanentAllow],
    ];
    if ($monthlyAllowTotal > 0.0005) {
        $summaryAllow[] = ['label' => 'العلاوات الإضافية الشهرية', 'amount' => round($monthlyAllowTotal, 3)];
    }
    if ($bonus > 0.0005) {
        $summaryAllow[] = ['label' => 'مكافآت', 'amount' => round($bonus, 3)];
    }

    $summaryDeduct = [];
    foreach ($otherDedLines as $ln) {
        $summaryDeduct[] = [
            'label' => (string) ($ln['name'] ?? ''),
            'amount' => round((float) ($ln['amount'] ?? 0), 3),
        ];
    }
    if ($ssEmp > 0.0005) {
        $summaryDeduct[] = [
            'label' => (string) ($row['social_security_label'] ?? 'اقتطاع ضمان اجتماعي'),
            'amount' => round($ssEmp, 3),
        ];
        $otherDedLines[] = [
            'name' => (string) ($row['social_security_label'] ?? 'اقتطاع ضمان اجتماعي'),
            'amount' => round($ssEmp, 3),
        ];
    }
    if ($tax > 0.0005) {
        $summaryDeduct[] = [
            'label' => (string) ($row['income_tax_label'] ?? 'ضريبة دخل'),
            'amount' => round($tax, 3),
        ];
        $otherDedLines[] = [
            'name' => (string) ($row['income_tax_label'] ?? 'ضريبة دخل'),
            'amount' => round($tax, 3),
        ];
    }

    return [
        'salary_id' => $salaryId,
        'employee_id' => $employeeId,
        'pay_year' => $year,
        'pay_month' => $month,
        'period_label' => (string) ($row['period_label'] ?? hr_salary_period_label_ar($year, $month)),
        'is_posted' => (int) ($row['is_posted'] ?? 0) === 1,
        'company' => $company,
        'emp_code' => (string) ($row['emp_code'] ?? '—'),
        'emp_name' => (string) ($row['emp_name'] ?? ''),
        'job_title' => (string) ($row['job_title'] ?? '—'),
        'department' => (string) ($row['department'] ?? '—'),
        'hire_date' => $hireDisplay,
        'bank_display' => $bankDisplay !== '' ? $bankDisplay : '—',
        'bank_account' => trim((string) ($row['bank_account'] ?? '')) ?: '—',
        'social_security_no' => trim((string) ($row['social_security_no'] ?? '')) ?: '—',
        'job_code' => $jobCode,
        'work_days' => $workDays,
        'unpaid_days' => 0,
        'summary_allow' => $summaryAllow,
        'summary_deduct' => $summaryDeduct,
        'gross' => $gross,
        'total_ded' => $totalDed,
        'net' => $net,
        'allow_detail_base_fixed' => $allowDetailBaseFixed,
        'allow_detail_monthly' => $allowDetailMonthly,
        'allow_detail' => $allowLines,
        'deduct_detail' => $otherDedLines,
    ];
}

function hr_payroll_slip_report_fmt(float $amount): string
{
    return format_money($amount);
}

/** HTML قسيمة الراتب (للعرض والطباعة). */
function hr_payroll_slip_report_render_html(array $slip): string
{
    $periodLabel = (string) ($slip['period_label'] ?? '');

    ob_start();
    ?>
    <div class="hr-pslip-doc report-sales-print-area doc-print-watermark-scope">
        <?= document_print_header_html('قسيمة الراتب', null, 'لشهر ' . $periodLabel) ?>

        <hr class="hr-pslip-rule hr-pslip-rule--thick">

        <section class="hr-pslip-emp-grid">
            <div class="hr-pslip-emp-pairs">
                <div class="hr-pslip-emp-col hr-pslip-emp-col--primary">
                    <div class="hr-pslip-kv">
                        <span class="hr-pslip-k">رقم الموظف:</span>
                        <span class="hr-pslip-v" dir="ltr"><?= esc((string) ($slip['emp_code'] ?? '—')) ?></span>
                    </div>
                    <div class="hr-pslip-kv">
                        <span class="hr-pslip-k">اسم الموظف:</span>
                        <span class="hr-pslip-v"><?= esc((string) ($slip['emp_name'] ?? '')) ?></span>
                    </div>
                    <div class="hr-pslip-kv">
                        <span class="hr-pslip-k">الوظيفة:</span>
                        <span class="hr-pslip-v"><?= esc((string) ($slip['job_title'] ?? '—')) ?></span>
                    </div>
                    <div class="hr-pslip-kv">
                        <span class="hr-pslip-k">الدائرة:</span>
                        <span class="hr-pslip-v"><?= esc((string) ($slip['department'] ?? '—')) ?></span>
                    </div>
                    <div class="hr-pslip-kv">
                        <span class="hr-pslip-k">الرمز الوظيفي:</span>
                        <span class="hr-pslip-v" dir="ltr"><?= esc((string) ($slip['job_code'] ?? '—')) ?></span>
                    </div>
                </div>
                <div class="hr-pslip-emp-col hr-pslip-emp-col--secondary">
                    <div class="hr-pslip-kv">
                        <span class="hr-pslip-k">تاريخ المباشرة:</span>
                        <span class="hr-pslip-v" dir="ltr"><?= esc((string) ($slip['hire_date'] ?? '—')) ?></span>
                    </div>
                    <div class="hr-pslip-kv">
                        <span class="hr-pslip-k">البنك والفرع:</span>
                        <span class="hr-pslip-v"><?= esc((string) ($slip['bank_display'] ?? '—')) ?></span>
                    </div>
                    <div class="hr-pslip-kv">
                        <span class="hr-pslip-k">رقم الحساب:</span>
                        <span class="hr-pslip-v" dir="ltr"><?= esc((string) ($slip['bank_account'] ?? '—')) ?></span>
                    </div>
                    <div class="hr-pslip-kv">
                        <span class="hr-pslip-k">رقم الضمان:</span>
                        <span class="hr-pslip-v" dir="ltr"><?= esc((string) ($slip['social_security_no'] ?? '—')) ?></span>
                    </div>
                </div>
            </div>
        </section>

        <section class="hr-pslip-summary-wrap">
            <div class="hr-pslip-summary-cols">
                <div class="hr-pslip-summary-block">
                    <h3 class="hr-pslip-summary-h">الرواتب والعلاوات</h3>
                    <table class="hr-pslip-sum-table">
                        <thead>
                        <tr><th>البيان</th><th>المبلغ</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($slip['summary_allow'] ?? [] as $ln): ?>
                            <tr>
                                <td><?= esc((string) ($ln['label'] ?? '')) ?></td>
                                <td class="num" dir="ltr"><?= esc(hr_payroll_slip_report_fmt((float) ($ln['amount'] ?? 0))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                        <tr class="hr-pslip-sum-total">
                            <td>اجمالي الراتب</td>
                            <td class="num" dir="ltr"><?= esc(hr_payroll_slip_report_fmt((float) ($slip['gross'] ?? 0))) ?></td>
                        </tr>
                        </tfoot>
                    </table>
                    <p class="hr-pslip-workdays">
                        أيام العمل: <span dir="ltr"><?= (int) ($slip['work_days'] ?? 0) ?></span>
                        &nbsp;&nbsp;|&nbsp;&nbsp;
                        أيام بدون راتب: <span dir="ltr"><?= (int) ($slip['unpaid_days'] ?? 0) ?></span>
                    </p>
                </div>
                <div class="hr-pslip-summary-block">
                    <h3 class="hr-pslip-summary-h">الاقتطاعات</h3>
                    <table class="hr-pslip-sum-table">
                        <thead>
                        <tr><th>البيان</th><th>المبلغ</th></tr>
                        </thead>
                        <tbody>
                        <?php if (empty($slip['summary_deduct'])): ?>
                            <tr><td colspan="2" class="muted">—</td></tr>
                        <?php endif; ?>
                        <?php foreach ($slip['summary_deduct'] ?? [] as $ln): ?>
                            <tr>
                                <td><?= esc((string) ($ln['label'] ?? '')) ?></td>
                                <td class="num" dir="ltr"><?= esc(hr_payroll_slip_report_fmt((float) ($ln['amount'] ?? 0))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                        <tr class="hr-pslip-sum-total">
                            <td>إجمالي الاقتطاعات</td>
                            <td class="num" dir="ltr"><?= esc(hr_payroll_slip_report_fmt((float) ($slip['total_ded'] ?? 0))) ?></td>
                        </tr>
                        </tfoot>
                    </table>
                    <div class="hr-pslip-net-box">
                        <span class="hr-pslip-net-label">صافي الراتب</span>
                        <span class="hr-pslip-net-value" dir="ltr"><?= esc(hr_payroll_slip_report_fmt((float) ($slip['net'] ?? 0))) ?></span>
                    </div>
                </div>
            </div>
        </section>

        <hr class="hr-pslip-rule hr-pslip-rule--double">

        <section class="hr-pslip-detail-cols">
            <div class="hr-pslip-detail-block">
                <h4 class="hr-pslip-detail-h">تفاصيل الراتب الأساسي والعلاوات</h4>
                <ul class="hr-pslip-detail-list">
                    <?php if (empty($slip['allow_detail_base_fixed'])): ?>
                        <li class="muted">—</li>
                    <?php endif; ?>
                    <?php foreach ($slip['allow_detail_base_fixed'] ?? [] as $ln): ?>
                        <li class="hr-pslip-kv">
                            <span class="hr-pslip-k"><?= esc((string) ($ln['name'] ?? '')) ?></span>
                            <span class="hr-pslip-v" dir="ltr"><?= esc(hr_payroll_slip_report_fmt((float) ($ln['amount'] ?? 0))) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <h4 class="hr-pslip-detail-h">العلاوات الإضافية الشهرية</h4>
                <ul class="hr-pslip-detail-list">
                    <?php if (empty($slip['allow_detail_monthly'])): ?>
                        <li class="muted">—</li>
                    <?php endif; ?>
                    <?php foreach ($slip['allow_detail_monthly'] ?? [] as $ln): ?>
                        <li class="hr-pslip-kv">
                            <span class="hr-pslip-k"><?= esc((string) ($ln['name'] ?? '')) ?></span>
                            <span class="hr-pslip-v" dir="ltr"><?= esc(hr_payroll_slip_report_fmt((float) ($ln['amount'] ?? 0))) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="hr-pslip-detail-block">
                <h3 class="hr-pslip-detail-h">تفاصيل الاقتطاعات</h3>
                <ul class="hr-pslip-detail-list">
                    <?php if (empty($slip['deduct_detail'])): ?>
                        <li class="muted">—</li>
                    <?php endif; ?>
                    <?php foreach ($slip['deduct_detail'] ?? [] as $ln): ?>
                        <li class="hr-pslip-kv">
                            <span class="hr-pslip-k"><?= esc((string) ($ln['name'] ?? '')) ?></span>
                            <span class="hr-pslip-v" dir="ltr"><?= esc(hr_payroll_slip_report_fmt((float) ($ln['amount'] ?? 0))) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>
    </div>
    <?php
    return (string) ob_get_clean();
}

/**
 * @param list<array<string, mixed>> $slips
 * @param int $activeEmployeeId عند التمرير: إظهار هذه القسيمة فقط على الشاشة (الكل يُطبع)
 */
function hr_payroll_slip_report_render_pages(array $slips, int $activeEmployeeId = 0): string
{
    if ($slips === []) {
        return '';
    }

    $html = '';
    foreach ($slips as $slip) {
        $classes = 'hr-pslip-print-page';
        $eid = (int) ($slip['employee_id'] ?? 0);
        if ($activeEmployeeId > 0 && $eid === $activeEmployeeId) {
            $classes .= ' hr-pslip-print-page--active';
        }
        $html .= '<div class="' . $classes . '">' . hr_payroll_slip_report_render_html($slip) . '</div>';
    }

    return $html;
}
