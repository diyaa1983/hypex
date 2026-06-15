<?php
declare(strict_types=1);

require_once app_path('includes/hr_salary.php');
require_once app_path('includes/company_settings.php');

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
    $gross = round($base + $allowCol + $overtime + $bonus, 3);
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

    $allowLines = [];
    foreach ($row['allowance_lines'] ?? [] as $ln) {
        $name = trim((string) ($ln['name_ar'] ?? ''));
        if ($name === '') {
            continue;
        }
        $allowLines[] = [
            'name' => $name,
            'amount' => (float) ($ln['amount'] ?? 0),
            'recurring' => true,
        ];
    }
    if (!$allowLines && $base > 0.0005) {
        $allowLines[] = [
            'name' => 'الراتب الأساسي',
            'amount' => round($base, 3),
            'recurring' => true,
        ];
    }
    if ($allowCol > 0.0005) {
        $allowLines[] = [
            'name' => 'علاوات',
            'amount' => round($allowCol, 3),
            'recurring' => true,
        ];
    }
    if ($overtime > 0.0005) {
        $allowLines[] = [
            'name' => 'عمل إضافي',
            'amount' => round($overtime, 3),
            'recurring' => false,
        ];
    }
    if ($bonus > 0.0005) {
        $allowLines[] = [
            'name' => 'مكافآت',
            'amount' => round($bonus, 3),
            'recurring' => false,
        ];
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
        ['label' => 'الراتب الأساسي والعلاوات', 'amount' => round($base, 3)],
    ];
    if ($allowCol > 0.0005) {
        $summaryAllow[] = ['label' => 'العلاوات الإضافية', 'amount' => round($allowCol, 3)];
    }
    if ($overtime > 0.0005) {
        $summaryAllow[] = ['label' => 'عمل إضافي', 'amount' => round($overtime, 3)];
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
    $co = $slip['company'] ?? [];
    $logoUrl = $co['logo_url'] ?? null;
    $period = esc((string) ($slip['period_label'] ?? ''));

    ob_start();
    ?>
    <div class="hr-pslip-doc report-sales-print-area doc-print-watermark-scope">
        <header class="hr-pslip-header">
            <div class="hr-pslip-header-cols">
                <div class="hr-pslip-header-en" dir="ltr">
                    <div class="hr-pslip-co-en"><?= esc((string) ($co['name_en'] ?? '')) ?></div>
                    <?php if (!empty($co['address_en'])): ?>
                        <div><?= esc((string) $co['address_en']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($co['phone'])): ?>
                        <div>Tel: <?= esc((string) $co['phone']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="hr-pslip-header-logo">
                    <?php if ($logoUrl): ?>
                        <img src="<?= esc((string) $logoUrl) ?>" alt="">
                    <?php endif; ?>
                </div>
                <div class="hr-pslip-header-ar">
                    <div class="hr-pslip-co-ar"><?= esc((string) ($co['name_ar'] ?? '')) ?></div>
                    <?php if (!empty($co['address_ar'])): ?>
                        <div><?= esc((string) $co['address_ar']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($co['phone'])): ?>
                        <div>هاتف: <?= esc((string) $co['phone']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <h1 class="hr-pslip-title">قسيمة الراتب</h1>
            <p class="hr-pslip-period-line">
                لشهر <strong><?= $period ?></strong>
            </p>
        </header>

        <hr class="hr-pslip-rule hr-pslip-rule--thick">

        <section class="hr-pslip-emp-grid">
            <table class="hr-pslip-emp-table">
                <tr>
                    <th>رقم الموظف:</th>
                    <td dir="ltr"><?= esc((string) ($slip['emp_code'] ?? '—')) ?></td>
                </tr>
                <tr>
                    <th>اسم الموظف:</th>
                    <td><?= esc((string) ($slip['emp_name'] ?? '')) ?></td>
                </tr>
                <tr>
                    <th>الوظيفة:</th>
                    <td><?= esc((string) ($slip['job_title'] ?? '—')) ?></td>
                </tr>
                <tr>
                    <th>الدائرة:</th>
                    <td><?= esc((string) ($slip['department'] ?? '—')) ?></td>
                </tr>
            </table>
            <table class="hr-pslip-emp-table">
                <tr>
                    <th>تاريخ المباشرة:</th>
                    <td dir="ltr"><?= esc((string) ($slip['hire_date'] ?? '—')) ?></td>
                </tr>
                <tr>
                    <th>البنك والفرع:</th>
                    <td><?= esc((string) ($slip['bank_display'] ?? '—')) ?></td>
                </tr>
                <tr>
                    <th>رقم الحساب:</th>
                    <td dir="ltr"><?= esc((string) ($slip['bank_account'] ?? '—')) ?></td>
                </tr>
                <tr>
                    <th>رقم الضمان:</th>
                    <td dir="ltr"><?= esc((string) ($slip['social_security_no'] ?? '—')) ?></td>
                </tr>
                <tr>
                    <th>الرمز الوظيفي:</th>
                    <td dir="ltr"><?= esc((string) ($slip['job_code'] ?? '—')) ?></td>
                </tr>
            </table>
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
                                <td dir="ltr" class="num"><?= esc(hr_payroll_slip_report_fmt((float) ($ln['amount'] ?? 0))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                        <tr class="hr-pslip-sum-total">
                            <td>اجمالي الراتب</td>
                            <td dir="ltr" class="num"><?= esc(hr_payroll_slip_report_fmt((float) ($slip['gross'] ?? 0))) ?></td>
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
                                <td dir="ltr" class="num"><?= esc(hr_payroll_slip_report_fmt((float) ($ln['amount'] ?? 0))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                        <tr class="hr-pslip-sum-total">
                            <td>إجمالي الاقتطاعات</td>
                            <td dir="ltr" class="num"><?= esc(hr_payroll_slip_report_fmt((float) ($slip['total_ded'] ?? 0))) ?></td>
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
                <h3 class="hr-pslip-detail-h">تفاصيل العلاوات</h3>
                <ul class="hr-pslip-detail-list">
                    <?php if (empty($slip['allow_detail'])): ?>
                        <li class="muted">—</li>
                    <?php endif; ?>
                    <?php foreach ($slip['allow_detail'] ?? [] as $ln): ?>
                        <li>
                            <span class="hr-pslip-detail-name"><?= esc((string) ($ln['name'] ?? '')) ?><?= !empty($ln['recurring']) ? ' - متكرر' : '' ?></span>
                            <span class="hr-pslip-detail-amt" dir="ltr"><?= esc(hr_payroll_slip_report_fmt((float) ($ln['amount'] ?? 0))) ?></span>
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
                        <li>
                            <span class="hr-pslip-detail-name"><?= esc((string) ($ln['name'] ?? '')) ?></span>
                            <span class="hr-pslip-detail-amt" dir="ltr"><?= esc(hr_payroll_slip_report_fmt((float) ($ln['amount'] ?? 0))) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>
    </div>
    <?php
    return (string) ob_get_clean();
}
