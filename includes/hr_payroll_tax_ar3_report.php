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
        'display' => number_format($rounded, 3, '.', ','),
    ];
}

/**
 * بيانات صاحب العمل (الشركة) من شاشة الإعدادات العامة فقط.
 * الحقول الفارغة تُترك فارغة — لا قيم افتراضية ولا بدائل من إعدادات أخرى.
 *
 * @return array{name:string, address:string, phone:string, tax_no:string}
 */
function hr_payroll_tax_ar3_employer_info(PDO $pdo): array
{
    $name = '';
    $address = '';
    $phone = '';

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

    return [
        'name' => $name,
        'address' => $address,
        'phone' => $phone,
        'tax_no' => '',
    ];
}

/** @return list<array{label:string, amount:float}> */
function hr_payroll_tax_ar3_official_allowance_lines(float $baseTotal, float $allowTotal, float $otBonusTotal): array
{
    return [
        ['label' => 'الراتب الأساسي', 'amount' => round($baseTotal, 3)],
        ['label' => 'علاوة شخصية', 'amount' => round($allowTotal, 3)],
        ['label' => 'علاوة فنية', 'amount' => 0.0],
        ['label' => 'علاوة وحدة / إختصاص', 'amount' => 0.0],
        ['label' => 'علاوة عائلية', 'amount' => 0.0],
        ['label' => 'علاوة ضيافة', 'amount' => 0.0],
        ['label' => 'علاوة تمثيل', 'amount' => 0.0],
        ['label' => 'علاوة سفر', 'amount' => 0.0],
        ['label' => 'العمل الإضافي والمكافآت والعمولات', 'amount' => round($otBonusTotal, 3)],
        ['label' => 'بدل السكن والمأكل والمنامة', 'amount' => 0.0],
    ];
}

/** @return list<array{label:string, amount:float}> */
function hr_payroll_tax_ar3_official_deduction_lines(float $incomeTax, float $socialSecurity): array
{
    return [
        ['label' => 'ضريبة الدخل', 'amount' => round($incomeTax, 3)],
        ['label' => 'ضريبة الخدمات الإجتماعية', 'amount' => 0.0],
        ['label' => 'ضمان إجتماعي', 'amount' => round($socialSecurity, 3)],
        ['label' => 'عائدات تقاعدية', 'amount' => 0.0],
        ['label' => 'صندوق توفير وإدخار', 'amount' => 0.0],
    ];
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

    $baseTotal = 0.0;
    $allowTotal = 0.0;
    $otBonusTotal = 0.0;
    $totalTax = 0.0;
    $totalSs = 0.0;
    $monthsPaid = count($salaries);

    foreach ($salaries as $sal) {
        $baseTotal += (float) ($sal['base_salary'] ?? 0);
        $allowTotal += (float) ($sal['allowances'] ?? 0);
        $otBonusTotal += (float) ($sal['overtime'] ?? 0) + (float) ($sal['bonus'] ?? 0);
        $totalTax += (float) ($sal['income_tax'] ?? 0);
        $totalSs += (float) ($sal['social_security_emp'] ?? 0);
    }

    $baseTotal = round($baseTotal, 3);
    $allowTotal = round($allowTotal, 3);
    $otBonusTotal = round($otBonusTotal, 3);
    $totalTax = round($totalTax, 3);
    $totalSs = round($totalSs, 3);

    $allowanceLinesRaw = hr_payroll_tax_ar3_official_allowance_lines($baseTotal, $allowTotal, $otBonusTotal);
    $deductionLinesRaw = hr_payroll_tax_ar3_official_deduction_lines($totalTax, $totalSs);

    $allowanceTotal = 0.0;
    foreach ($allowanceLinesRaw as $ln) {
        $allowanceTotal += (float) ($ln['amount'] ?? 0);
    }
    $allowanceTotal = round($allowanceTotal, 3);

    $deductionTotal = 0.0;
    foreach ($deductionLinesRaw as $ln) {
        $deductionTotal += (float) ($ln['amount'] ?? 0);
    }
    $deductionTotal = round($deductionTotal, 3);

    $allowanceLines = [];
    foreach ($allowanceLinesRaw as $ln) {
        $allowanceLines[] = [
            'label' => (string) ($ln['label'] ?? ''),
            'amount' => hr_payroll_tax_ar3_split_amount((float) ($ln['amount'] ?? 0)),
        ];
    }

    $deductionLines = [];
    foreach ($deductionLinesRaw as $ln) {
        $deductionLines[] = [
            'label' => (string) ($ln['label'] ?? ''),
            'amount' => hr_payroll_tax_ar3_split_amount((float) ($ln['amount'] ?? 0)),
        ];
    }

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
        trim((string) ($emp['address_district'] ?? '')),
        trim((string) ($emp['address_city'] ?? '')),
        trim((string) ($emp['address_ar'] ?? '')),
    ], static fn (string $v): bool => $v !== '');

    $nameParts = hr_employee_name_parts_from_row($emp);
    $employer = hr_payroll_tax_ar3_employer_info($pdo);
    $workDays = hr_payroll_tax_ar3_work_days($workStart, $workEnd);

    return [
        'year' => $year,
        'tax_period' => (string) $year,
        'months_paid' => $monthsPaid,
        'work_duration' => $workDays > 0 ? ($workDays . ' يوم') : ($monthsPaid . ' شهر'),
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
            'file_no' => trim((string) ($emp['emp_code'] ?? '')),
            'po_box' => '',
            'postal_code' => '',
            'address' => implode(' — ', $addressParts),
            'governorate' => trim((string) ($emp['address_district'] ?? '')),
            'city' => trim((string) ($emp['address_city'] ?? '')),
            'street' => trim((string) ($emp['address_ar'] ?? '')),
            'phone' => trim((string) ($emp['phone'] ?? '')),
            'subject_to_income_tax' => (int) ($emp['subject_to_income_tax'] ?? 1) === 1,
        ],
        'employer' => $employer,
        'allowance_lines' => $allowanceLines,
        'deduction_lines' => $deductionLines,
        'allowance_total' => hr_payroll_tax_ar3_split_amount($allowanceTotal),
        'deduction_total' => hr_payroll_tax_ar3_split_amount($deductionTotal),
        'allowance_total_words' => arabic_tafqit_amount($allowanceTotal, $pdo),
        'deduction_total_words' => arabic_tafqit_amount($deductionTotal, $pdo),
        'allowance_total_raw' => $allowanceTotal,
        'deduction_total_raw' => $deductionTotal,
        'gross_raw' => $allowanceTotal,
        'tax_raw' => $totalTax,
    ];
}
