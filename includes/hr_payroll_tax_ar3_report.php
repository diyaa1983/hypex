<?php
declare(strict_types=1);

require_once app_path('includes/hr_payroll_posting.php');
require_once app_path('includes/hr_payroll_ss_report.php');
require_once app_path('includes/arabic_tafqit.php');

/** @return list<int> */
function hr_payroll_tax_ar3_posted_years(PDO $pdo): array
{
    return hr_payroll_ss_report_posted_years($pdo);
}

function hr_payroll_tax_ar3_year_has_posted(PDO $pdo, int $year): bool
{
    if ($year < 2000) {
        return false;
    }
    try {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM hr_salary WHERE pay_year = ? AND is_posted = 1 LIMIT 1'
        );
        $st->execute([$year]);

        return (int) $st->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/** @return list<array{id:int, emp_code:string, name_ar:string}> */
function hr_payroll_tax_ar3_employees_for_year(PDO $pdo, int $year): array
{
    if ($year < 2000) {
        return [];
    }

    hr_employee_ensure_schema($pdo);

    try {
        $st = $pdo->prepare(
            'SELECT DISTINCT e.id, e.emp_code, e.name_ar
             FROM hr_employee e
             INNER JOIN hr_salary s ON s.employee_id = e.id
             WHERE s.pay_year = ? AND s.is_posted = 1
             ORDER BY CASE WHEN e.emp_code REGEXP \'^[0-9]+$\' THEN CAST(e.emp_code AS UNSIGNED) ELSE 999999999 END ASC,
                      e.emp_code ASC, e.id ASC'
        );
        $st->execute([$year]);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** @return array{dinar:int, fils:int, display:string} */
function hr_payroll_tax_ar3_split_amount(float $amount): array
{
    $rounded = round(max(0.0, $amount), 3);
    $dinar = (int) floor($rounded + 0.0000001);
    $fils = (int) round(($rounded - $dinar) * 1000);
    if ($fils >= 1000) {
        $dinar++;
        $fils = 0;
    }

    return [
        'dinar' => $dinar,
        'fils' => $fils,
        'display' => number_format($rounded, 3, '.', ','),
    ];
}

/**
 * @return array{name:string, address:string, phone:string, tax_no:string}
 */
function hr_payroll_tax_ar3_employer_info(PDO $pdo): array
{
    $name = '';
    $address = '';
    $phone = '';
    $taxNo = '';

    try {
        $row = $pdo->query(
            'SELECT company_name_ar, address_ar, phone FROM sys_company_settings WHERE id = 1 LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $name = trim((string) ($row['company_name_ar'] ?? ''));
            if ($name === 'اسم الشركة') {
                $name = '';
            }
            $address = trim((string) ($row['address_ar'] ?? ''));
            $phone = trim((string) ($row['phone'] ?? ''));
        }
    } catch (Throwable $e) {
        // ignore
    }

    try {
        require_once app_path('includes/einvoice_settings.php');
        $einv = einvoice_settings_get($pdo);
        $taxNo = trim((string) ($einv['vat_no'] ?? ''));
        if ($taxNo === '') {
            $taxNo = trim((string) ($einv['gst_no'] ?? ''));
        }
        if ($name === '') {
            $name = trim((string) ($einv['company_name'] ?? ''));
        }
    } catch (Throwable $e) {
        // ignore
    }

    return [
        'name' => $name,
        'address' => $address,
        'phone' => $phone,
        'tax_no' => $taxNo,
    ];
}

/** @return list<string> */
function hr_payroll_tax_ar3_qp177_row_labels(): array
{
    return [
        'الرواتب والأجور',
        'الرواتب والأجور غير الشهرية',
        'مكافآت أعضاء مجلس الإدارة',
        'مكافأة نهاية الخدمة عن الخدمات ما قبل 2010/1/1',
        'مكافأة نهاية الخدمة عن الخدمات من 2010/1/1 حتى 2014/12/31',
        'مكافأة نهاية الخدمة عن الخدمات من 2015/1/1 حتى نهاية العمل',
        'أي مبالغ أخرى',
        'المساهمة الوطنية',
    ];
}

/**
 * @param list<float> $wageAmounts
 * @param list<float> $taxAmounts
 * @return list<array{label:string, wage:?array, tax:?array}>
 */
function hr_payroll_tax_ar3_qp177_financial_rows(array $wageAmounts, array $taxAmounts): array
{
    $labels = hr_payroll_tax_ar3_qp177_row_labels();
    $rows = [];
    foreach ($labels as $i => $label) {
        $wageRaw = (float) ($wageAmounts[$i] ?? 0);
        $taxRaw = (float) ($taxAmounts[$i] ?? 0);
        $rows[] = [
            'label' => $label,
            'wage' => $wageRaw > 0.0005 ? hr_payroll_tax_ar3_split_amount($wageRaw) : null,
            'tax' => $taxRaw > 0.0005 ? hr_payroll_tax_ar3_split_amount($taxRaw) : null,
        ];
    }

    return $rows;
}

/** @param list<float> $amounts */
function hr_payroll_tax_ar3_sum_split(array $amounts): array
{
    $total = 0.0;
    foreach ($amounts as $amount) {
        $total += (float) $amount;
    }

    return hr_payroll_tax_ar3_split_amount(round($total, 3));
}

function hr_payroll_tax_ar3_work_days(string $workStart, string $workEnd): int
{
    $startTs = strtotime($workStart);
    $endTs = strtotime($workEnd);
    if ($startTs === false || $endTs === false || $endTs < $startTs) {
        return 0;
    }

    return (int) floor(($endTs - $startTs) / 86400) + 1;
}

function hr_payroll_tax_ar3_work_duration_label(int $monthsPaid, int $workDays): string
{
    if ($monthsPaid > 0) {
        if ($monthsPaid === 1) {
            return 'شهر واحد';
        }
        if ($monthsPaid === 2) {
            return 'شهران';
        }
        if ($monthsPaid >= 3 && $monthsPaid <= 10) {
            return $monthsPaid . ' أشهر';
        }

        return $monthsPaid . ' شهر';
    }
    if ($workDays > 0) {
        return $workDays . ' يوم';
    }

    return '';
}

/**
 * @return array<string, mixed>|null
 */
function hr_payroll_tax_ar3_report_build(PDO $pdo, int $year, int $employeeId): ?array
{
    if ($year < 2000 || $employeeId < 1) {
        return null;
    }

    hr_employee_ensure_schema($pdo);

    if (!hr_payroll_tax_ar3_year_has_posted($pdo, $year)) {
        return null;
    }

    try {
        $stEmp = $pdo->prepare(
            'SELECT id, emp_code, name_ar, name_first, name_father, name_grandfather, name_family,
                    national_id, phone, hire_date, resignation_date,
                    address_ar, address_city, address_district, subject_to_income_tax
             FROM hr_employee WHERE id = ? LIMIT 1'
        );
        $stEmp->execute([$employeeId]);
        $emp = $stEmp->fetch(PDO::FETCH_ASSOC);
        if (!$emp) {
            return null;
        }

        $stSal = $pdo->prepare(
            'SELECT pay_month, base_salary, allowances, overtime, bonus, income_tax, social_security_emp
             FROM hr_salary
             WHERE employee_id = ? AND pay_year = ? AND is_posted = 1
             ORDER BY pay_month ASC'
        );
        $stSal->execute([$employeeId, $year]);
        $salaries = $stSal->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return null;
    }

    if ($salaries === []) {
        return null;
    }

    $monthlyWages = 0.0;
    $nonMonthlyWages = 0.0;
    $totalTax = 0.0;
    $monthsPaid = count($salaries);

    foreach ($salaries as $sal) {
        $monthlyWages += (float) ($sal['base_salary'] ?? 0) + (float) ($sal['allowances'] ?? 0);
        $nonMonthlyWages += (float) ($sal['overtime'] ?? 0) + (float) ($sal['bonus'] ?? 0);
        $totalTax += (float) ($sal['income_tax'] ?? 0);
    }

    $monthlyWages = round($monthlyWages, 3);
    $nonMonthlyWages = round($nonMonthlyWages, 3);
    $totalTax = round($totalTax, 3);

    $wageAmounts = [
        $monthlyWages,
        $nonMonthlyWages,
        0.0,
        0.0,
        0.0,
        0.0,
        0.0,
        0.0,
    ];
    $taxAmounts = [
        $totalTax,
        0.0,
        0.0,
        0.0,
        0.0,
        0.0,
        0.0,
        0.0,
    ];

    $financialRows = hr_payroll_tax_ar3_qp177_financial_rows($wageAmounts, $taxAmounts);
    $wageTotal = hr_payroll_tax_ar3_sum_split($wageAmounts);
    $taxTotal = hr_payroll_tax_ar3_sum_split($taxAmounts);

    $yearStart = sprintf('%04d-01-01', $year);
    $yearEnd = sprintf('%04d-12-31', $year);

    $hireIso = trim((string) ($emp['hire_date'] ?? ''));
    $resignIso = trim((string) ($emp['resignation_date'] ?? ''));

    $workStart = $hireIso !== '' && $hireIso > $yearStart ? $hireIso : $yearStart;
    if ($hireIso !== '' && $hireIso > $yearEnd) {
        $workStart = $hireIso;
    }

    $workEnd = $yearEnd;
    if ($resignIso !== '' && $resignIso >= $yearStart && $resignIso <= $yearEnd) {
        $workEnd = $resignIso;
    } elseif ($resignIso !== '' && $resignIso < $yearStart) {
        $workEnd = $yearStart;
    }

    $addressParts = array_filter([
        trim((string) ($emp['address_ar'] ?? '')),
        trim((string) ($emp['address_city'] ?? '')),
        trim((string) ($emp['address_district'] ?? '')),
    ], static fn (string $v): bool => $v !== '');

    $nameParts = hr_employee_name_parts_from_row($emp);
    $employer = hr_payroll_tax_ar3_employer_info($pdo);
    $workDays = hr_payroll_tax_ar3_work_days($workStart, $workEnd);
    $wageTotalRaw = round($monthlyWages + $nonMonthlyWages, 3);

    return [
        'year' => $year,
        'tax_period' => (string) $year,
        'months_paid' => $monthsPaid,
        'work_duration' => (string) $monthsPaid,
        'work_duration_label' => hr_payroll_tax_ar3_work_duration_label($monthsPaid, $workDays),
        'work_days' => $workDays,
        'work_start' => $workStart,
        'work_start_dmy' => format_date_dmY($workStart),
        'work_end' => $workEnd,
        'work_end_dmy' => format_date_dmY($workEnd),
        'appointment_dmy' => $hireIso !== '' ? format_date_dmY($hireIso) : '',
        'termination_dmy' => $resignIso !== '' ? format_date_dmY($resignIso) : '',
        'employee' => [
            'id' => (int) ($emp['id'] ?? 0),
            'emp_code' => (string) ($emp['emp_code'] ?? ''),
            'name_ar' => (string) ($emp['name_ar'] ?? ''),
            'name_parts' => $nameParts,
            'national_id' => trim((string) ($emp['national_id'] ?? '')),
            'tax_no' => '',
            'po_box' => '',
            'postal_code' => '',
            'address' => implode(' — ', $addressParts),
            'phone' => trim((string) ($emp['phone'] ?? '')),
            'subject_to_income_tax' => (int) ($emp['subject_to_income_tax'] ?? 1) === 1,
        ],
        'employer' => $employer,
        'financial_rows' => $financialRows,
        'wage_total' => $wageTotal,
        'tax_total' => $taxTotal,
        'wage_total_raw' => $wageTotalRaw,
        'tax_total_raw' => $totalTax,
    ];
}
