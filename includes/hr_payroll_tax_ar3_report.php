<?php
declare(strict_types=1);

require_once app_path('includes/hr_payroll_posting.php');
require_once app_path('includes/hr_payroll_ss_report.php');
require_once app_path('includes/company_settings.php');
require_once app_path('includes/einvoice_settings.php');

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

/**
 * @return array{first:string, father:string, grandfather:string, family:string}
 */
function hr_payroll_tax_ar3_split_name(string $fullName): array
{
    return hr_employee_name_parts_split($fullName);
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
        'display' => number_format($rounded, 3),
    ];
}

/**
 * @return array<string, string>
 */
function hr_payroll_tax_ar3_employer_info(PDO $pdo): array
{
    $company = company_settings($pdo);
    $einv = einvoice_settings_get($pdo);

    $name = trim((string) ($einv['company_name'] ?? ''));
    if ($name === '') {
        $name = trim((string) ($company['company_name_ar'] ?? ''));
    }

    $vatNo = trim((string) ($einv['vat_no'] ?? ''));
    $gstNo = trim((string) ($einv['gst_no'] ?? ''));
    $taxNo = $vatNo !== '' ? $vatNo : $gstNo;

    return [
        'name' => $name,
        'tax_no' => $taxNo,
    ];
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
            'SELECT pay_month, base_salary, allowances, overtime, bonus, income_tax
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

    $monthlyGross = 0.0;
    $nonMonthlyGross = 0.0;
    $totalTax = 0.0;
    $monthsPaid = count($salaries);

    foreach ($salaries as $sal) {
        $monthlyGross += (float) ($sal['base_salary'] ?? 0) + (float) ($sal['allowances'] ?? 0);
        $nonMonthlyGross += (float) ($sal['overtime'] ?? 0) + (float) ($sal['bonus'] ?? 0);
        $totalTax += (float) ($sal['income_tax'] ?? 0);
    }

    $monthlyGross = round($monthlyGross, 3);
    $nonMonthlyGross = round($nonMonthlyGross, 3);
    $totalTax = round($totalTax, 3);

    $boardBonus = 0.0;
    $eosBefore2010 = 0.0;
    $eos2010To2014 = 0.0;
    $eosFrom2015 = 0.0;
    $otherAmounts = 0.0;

    $grossRows = [
        'monthly' => $monthlyGross,
        'non_monthly' => $nonMonthlyGross,
        'board' => $boardBonus,
        'eos_before_2010' => $eosBefore2010,
        'eos_2010_2014' => $eos2010To2014,
        'eos_from_2015' => $eosFrom2015,
        'other' => $otherAmounts,
    ];

    $totalGross = round(array_sum($grossRows), 3);

    $taxMonthly = 0.0;
    $taxNonMonthly = 0.0;
    $nationalContribution = 0.0;

    if ($totalTax > 0.000001 && $totalGross > 0.000001) {
        $taxMonthly = round($totalTax * ($monthlyGross / $totalGross), 3);
        $taxNonMonthly = round($totalTax - $taxMonthly, 3);
    } elseif ($totalTax > 0.000001) {
        $taxMonthly = $totalTax;
    }

    $taxRows = [
        'monthly' => $taxMonthly,
        'non_monthly' => $taxNonMonthly,
        'board' => 0.0,
        'eos_before_2010' => 0.0,
        'eos_2010_2014' => 0.0,
        'eos_from_2015' => 0.0,
        'other' => 0.0,
        'national_contribution' => $nationalContribution,
    ];

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
        trim((string) ($emp['address_district'] ?? '')),
        trim((string) ($emp['address_city'] ?? '')),
    ], static fn (string $v): bool => $v !== '');

    $nameParts = hr_employee_name_parts_from_row($emp);
    $employer = hr_payroll_tax_ar3_employer_info($pdo);

    $lineDefs = [
        ['key' => 'monthly', 'label' => 'الرواتب والاجور'],
        ['key' => 'non_monthly', 'label' => 'الرواتب والاجور غير الشهرية'],
        ['key' => 'board', 'label' => 'مكافآت اعضاء مجلس الادارة'],
        ['key' => 'eos_before_2010', 'label' => 'مكافأة نهاية الخدمة عن الخدمات ما قبل 1/1/2010'],
        ['key' => 'eos_2010_2014', 'label' => 'مكافأة نهاية الخدمة عن الخدمات 1/1/2010 حتى 31/12/2014'],
        ['key' => 'eos_from_2015', 'label' => 'مكافأة نهاية الخدمة عن الخدمات من 1/1/2015 حتى نهاية العمل'],
        ['key' => 'other', 'label' => 'اي مبالغ اخرى'],
    ];

    $lines = [];
    foreach ($lineDefs as $def) {
        $key = (string) $def['key'];
        $grossAmt = (float) ($grossRows[$key] ?? 0);
        $taxAmt = (float) ($taxRows[$key] ?? 0);
        $lines[] = [
            'label' => (string) $def['label'],
            'gross' => hr_payroll_tax_ar3_split_amount($grossAmt),
            'tax' => hr_payroll_tax_ar3_split_amount($taxAmt),
        ];
    }

    $lines[] = [
        'label' => 'المساهمة الوطنية',
        'gross' => hr_payroll_tax_ar3_split_amount(0.0),
        'tax' => hr_payroll_tax_ar3_split_amount($nationalContribution),
        'tax_only' => true,
    ];

    return [
        'year' => $year,
        'tax_period' => (string) $year,
        'months_paid' => $monthsPaid,
        'work_duration' => $monthsPaid . ' شهر',
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
        'lines' => $lines,
        'totals' => [
            'gross' => hr_payroll_tax_ar3_split_amount($totalGross),
            'tax' => hr_payroll_tax_ar3_split_amount($totalTax),
        ],
        'gross_raw' => $totalGross,
        'tax_raw' => $totalTax,
    ];
}
