<?php

declare(strict_types=1);



require_once app_path('includes/hr_salary.php');

require_once app_path('includes/hr_employee_salary.php');

require_once app_path('includes/hr_social_security_payroll.php');
require_once app_path('includes/hr_employee_advance.php');

require_once app_path('includes/hr_employee_monthly_payroll.php');
require_once app_path('includes/hr_employee_overtime.php');

require_once app_path('includes/hr_payroll_gl.php');
require_once app_path('includes/hr_income_tax.php');

require_once app_path('includes/acc_gl.php');



const HR_PAYROLL_GL_REF_TYPE = 'hr_payroll_month';



/** @return array{year:int, month:int}|null */

function hr_payroll_max_posted_period(PDO $pdo): ?array

{

    try {

        $row = $pdo->query(

            'SELECT pay_year, pay_month

             FROM hr_salary

             WHERE is_posted = 1

             ORDER BY pay_year DESC, pay_month DESC

             LIMIT 1'

        )->fetch(PDO::FETCH_ASSOC);

        if (!$row) {

            return null;

        }

        return [

            'year' => (int) ($row['pay_year'] ?? 0),

            'month' => (int) ($row['pay_month'] ?? 0),

        ];

    } catch (Throwable $e) {

        return null;

    }

}

/** @return array{id:int, entry_no:string, entry_date:string, description_ar:string, status:string}|null */
function hr_payroll_month_journal_entry(PDO $pdo, int $year, int $month): ?array
{
    if (!acc_gl_journal_has_ref_columns($pdo)) {
        return null;
    }
    $refId = hr_payroll_month_ref_id($year, $month);
    try {
        $st = $pdo->prepare(
            "SELECT id, entry_no, entry_date, description_ar, status
             FROM acc_journal_entry
             WHERE ref_type = ? AND ref_id = ? AND source = 'auto'
             LIMIT 1"
        );
        $st->execute([HR_PAYROLL_GL_REF_TYPE, $refId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * @return list<array{
 *   month:int,
 *   emp_count:int,
 *   gross_total:float,
 *   net_total:float,
 *   journal_id:int,
 *   entry_no:string,
 *   entry_date:string,
 *   can_unpost:bool,
 *   is_current:bool
 * }>
 */
function hr_payroll_posted_months_for_year(PDO $pdo, int $year, int $currentMonth = 0): array
{
    try {
        $st = $pdo->prepare(
            'SELECT pay_month,
                    COUNT(*) AS emp_count,
                    COALESCE(SUM(base_salary + allowances + overtime + bonus), 0) AS gross_total,
                    COALESCE(SUM(net_salary), 0) AS net_total
             FROM hr_salary
             WHERE pay_year = ? AND is_posted = 1
             GROUP BY pay_month
             ORDER BY pay_month ASC'
        );
        $st->execute([$year]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $month = (int) ($row['pay_month'] ?? 0);
        if ($month < 1 || $month > 12) {
            continue;
        }
        $journal = hr_payroll_month_journal_entry($pdo, $year, $month);
        $canUnpost = hr_payroll_can_unpost_month($pdo, $year, $month);
        $out[] = [
            'month' => $month,
            'emp_count' => (int) ($row['emp_count'] ?? 0),
            'gross_total' => round((float) ($row['gross_total'] ?? 0), 3),
            'net_total' => round((float) ($row['net_total'] ?? 0), 3),
            'journal_id' => (int) ($journal['id'] ?? 0),
            'entry_no' => (string) ($journal['entry_no'] ?? ''),
            'entry_date' => (string) ($journal['entry_date'] ?? ''),
            'can_unpost' => $canUnpost,
            'is_current' => $currentMonth > 0 && $currentMonth === $month,
        ];
    }

    return $out;
}

/**
 * كل أشهر السنة (1–12) مع حالة كل شهر في القائمة.
 *
 * @return list<array{month:int, status:string, label_suffix:string}>
 */
function hr_payroll_month_picker_options(PDO $pdo, int $year): array
{
    $byMonth = [];
    try {
        $st = $pdo->prepare(
            'SELECT pay_month,
                    SUM(CASE WHEN is_posted = 1 THEN 1 ELSE 0 END) AS posted_cnt,
                    SUM(CASE WHEN is_posted = 0 THEN 1 ELSE 0 END) AS draft_cnt
             FROM hr_salary
             WHERE pay_year = ?
             GROUP BY pay_month'
        );
        $st->execute([$year]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $m = (int) ($row['pay_month'] ?? 0);
            if ($m >= 1 && $m <= 12) {
                $byMonth[$m] = $row;
            }
        }
    } catch (Throwable $e) {
        $byMonth = [];
    }

    $open = hr_payroll_open_period($pdo);
    $openYear = $open ? (int) ($open['year'] ?? 0) : 0;
    $openMonth = $open ? (int) ($open['month'] ?? 0) : 0;

    $out = [];
    for ($m = 1; $m <= 12; $m++) {
        $suffix = '';
        $status = 'empty';
        if (isset($byMonth[$m])) {
            $postedCnt = (int) ($byMonth[$m]['posted_cnt'] ?? 0);
            $draftCnt = (int) ($byMonth[$m]['draft_cnt'] ?? 0);
            if ($postedCnt > 0 && $draftCnt === 0) {
                $status = 'posted';
                $suffix = ' — مرحّل';
            } elseif ($postedCnt > 0) {
                $status = 'mixed';
                $suffix = ' — مرحّل/محتسب';
            } else {
                $status = 'calculated';
                $suffix = ' — محتسب';
            }
        } elseif ($openYear === $year && $openMonth === $m) {
            $status = 'open';
            $suffix = ' — مفتوح';
        }
        $out[] = ['month' => $m, 'status' => $status, 'label_suffix' => $suffix];
    }

    return $out;
}

/** @param list<array{month:int, status?:string}> $options */
function hr_payroll_month_picker_resolve(int $month, array $options): int
{
    if ($month >= 1 && $month <= 12) {
        return $month;
    }
    foreach ($options as $opt) {
        if (($opt['status'] ?? '') === 'open') {
            return (int) ($opt['month'] ?? 1);
        }
    }
    if ($options !== []) {
        return (int) ($options[0]['month'] ?? 1);
    }

    return max(1, min(12, $month));
}

/** الشهر الافتراضي للعرض عند عدم تحديد شهر في الرابط. */
function hr_payroll_default_picker_month(PDO $pdo, int $year): int
{
    $open = hr_payroll_open_period($pdo);
    if ($open && (int) ($open['year'] ?? 0) === $year) {
        $m = (int) ($open['month'] ?? 0);
        if ($m >= 1 && $m <= 12) {
            return $m;
        }
    }
    $max = hr_payroll_max_posted_period($pdo);
    if ($max && (int) ($max['year'] ?? 0) === $year) {
        $maxMonth = (int) ($max['month'] ?? 0);
        if ($maxMonth >= 1 && $maxMonth < 12) {
            return $maxMonth + 1;
        }
    }

    return 1;
}

/** هل يوجد راتب مرحّل لهذا الشهر؟ */
function hr_payroll_month_has_posted(PDO $pdo, int $year, int $month): bool
{
    try {
        $st = $pdo->prepare(
            'SELECT 1 FROM hr_salary WHERE pay_year = ? AND pay_month = ? AND is_posted = 1 LIMIT 1'
        );
        $st->execute([$year, $month]);

        return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * فك الترحيل مسموح فقط لآخر شهر مرحّل زمنياً (لا يوجد شهر لاحق مغلق).
 */
function hr_payroll_can_unpost_month(PDO $pdo, int $year, int $month): bool
{
    hr_payroll_validate_period($year, $month);
    if (!hr_payroll_month_has_posted($pdo, $year, $month)) {
        return false;
    }
    $max = hr_payroll_max_posted_period($pdo);
    if ($max === null) {
        return false;
    }

    return (int) $max['year'] === $year && (int) $max['month'] === $month;
}

/** تسمية حالة موظف في جدول قيد الرواتب. */
function hr_payroll_employee_status_label(string $status, bool $hasSetup): string
{
    return match ($status) {
        'posted' => 'مرحّل',
        'calculated' => 'محتسب',
        'none' => $hasSetup ? 'مفتوح' : '—',
        default => '—',
    };
}

function hr_payroll_employee_status_code(string $status, bool $hasSetup): string
{
    return match ($status) {
        'posted' => 'posted',
        'calculated' => 'calculated',
        'none' => $hasSetup ? 'open' : 'none',
        default => 'none',
    };
}

/**
 * حالة شهر الرواتب: مفتوح / محتسب / مرحّل.
 *
 * @return array{code:string, label:string}
 */
function hr_payroll_month_status_info(
    PDO $pdo,
    int $year,
    int $month,
    array $summary = [],
    int $rowCount = 0
): array {
    $posted = (int) ($summary['posted'] ?? 0);
    $calculated = (int) ($summary['calculated'] ?? 0);

    if ($rowCount > 0 && $posted >= $rowCount) {
        return ['code' => 'posted', 'label' => 'مرحّل'];
    }
    if ($rowCount > 0 && $posted > 0) {
        return ['code' => 'mixed', 'label' => 'مرحّل/محتسب'];
    }
    if ($calculated > 0) {
        return ['code' => 'calculated', 'label' => 'محتسب'];
    }

    try {
        $st = $pdo->prepare(
            'SELECT COALESCE(SUM(CASE WHEN is_posted = 1 THEN 1 ELSE 0 END), 0) AS posted_cnt,
                    COALESCE(SUM(CASE WHEN is_posted = 0 THEN 1 ELSE 0 END), 0) AS draft_cnt
             FROM hr_salary WHERE pay_year = ? AND pay_month = ?'
        );
        $st->execute([$year, $month]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $postedCnt = (int) ($row['posted_cnt'] ?? 0);
        $draftCnt = (int) ($row['draft_cnt'] ?? 0);
        if ($postedCnt > 0 && $draftCnt === 0) {
            return ['code' => 'posted', 'label' => 'مرحّل'];
        }
        if ($postedCnt > 0) {
            return ['code' => 'mixed', 'label' => 'مرحّل/محتسب'];
        }
        if ($draftCnt > 0) {
            return ['code' => 'calculated', 'label' => 'محتسب'];
        }
    } catch (Throwable $e) {
        // ignored
    }

    return ['code' => 'open', 'label' => 'مفتوح'];
}


function hr_payroll_period_label(int $year, int $month): string
{
    return hr_salary_period_label_ar($year, $month);
}



function hr_payroll_month_ref_id(int $year, int $month): int

{

    return $year * 100 + $month;

}

/** @deprecated قيد منفصل — يُحذف عند الترحيل/فك الترحيل بعد دمج القيود */
const HR_PAYROLL_LEGACY_EMPLOYER_SS_REF_TYPE = 'hr_payroll_employer_ss';

function hr_payroll_remove_legacy_employer_ss_journal(PDO $pdo, int $refId): void
{
    if ($refId < 1 || !acc_gl_journal_has_ref_columns($pdo)) {
        return;
    }
    if (!acc_gl_ref_exists($pdo, HR_PAYROLL_LEGACY_EMPLOYER_SS_REF_TYPE, $refId)) {
        return;
    }
    $un = acc_gl_unpost_ref($pdo, HR_PAYROLL_LEGACY_EMPLOYER_SS_REF_TYPE, $refId);
    if (!$un['ok']) {
        throw new RuntimeException((string) ($un['error'] ?? 'تعذر إزالة قيد حصة شركة الضمان القديم.'));
    }
}



function hr_payroll_validate_period(int $year, int $month): void

{

    if ($year < 2000 || $year > 2100) {

        throw new RuntimeException('السنة غير صحيحة.');

    }

    if ($month < 1 || $month > 12) {

        throw new RuntimeException('الشهر غير صحيح.');

    }

}



/**

 * @return array{ok:bool, message:string, expected:?array{year:int, month:int}}

 */

function hr_payroll_period_key(int $year, int $month): int
{
    return $year * 12 + $month;
}

/** شهر مرحّل ومغلق (لا يتجاوز آخر شهر مرحّل). */
function hr_payroll_month_is_closed(PDO $pdo, int $year, int $month): bool
{
    $max = hr_payroll_max_posted_period($pdo);
    if ($max === null) {
        return false;
    }

    return hr_payroll_period_key($year, $month) <= hr_payroll_period_key($max['year'], $max['month']);
}

/** @return array{year:int, month:int} */
function hr_payroll_prev_period(int $year, int $month): array
{
    if ($month <= 1) {
        return ['year' => $year - 1, 'month' => 12];
    }

    return ['year' => $year, 'month' => $month - 1];
}

/** هل الشهر السابق (تقويمياً) مرحّل بالكامل؟ */
function hr_payroll_prev_month_satisfied(PDO $pdo, int $year, int $month): bool
{
    try {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM hr_salary
             WHERE pay_year < ? OR (pay_year = ? AND pay_month < ?)'
        );
        $st->execute([$year, $year, $month]);
        if ((int) $st->fetchColumn() === 0) {
            return true;
        }
    } catch (Throwable $e) {
        return true;
    }

    $prev = hr_payroll_prev_period($year, $month);
    $py = (int) $prev['year'];
    $pm = (int) $prev['month'];
    try {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM hr_salary WHERE pay_year = ? AND pay_month = ? AND is_posted = 0'
        );
        $st->execute([$py, $pm]);
        if ((int) $st->fetchColumn() > 0) {
            return false;
        }
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM hr_salary WHERE pay_year = ? AND pay_month = ? AND is_posted = 1'
        );
        $st->execute([$py, $pm]);

        return (int) $st->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/** الشهر التالي المفتوح للعمل بعد آخر شهر مرحّل (أو أقدم شهر فيه مسودات). */
function hr_payroll_open_period(PDO $pdo): ?array
{
    $max = hr_payroll_max_posted_period($pdo);
    if ($max !== null) {
        $dt = DateTime::createFromFormat('Y-n-j', $max['year'] . '-' . $max['month'] . '-1');
        if (!$dt) {
            return null;
        }
        $dt->modify('+1 month');

        return ['year' => (int) $dt->format('Y'), 'month' => (int) $dt->format('n')];
    }

    try {
        $row = $pdo->query(
            'SELECT pay_year, pay_month FROM hr_salary WHERE is_posted = 0
             ORDER BY pay_year ASC, pay_month ASC LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return [
                'year' => (int) ($row['pay_year'] ?? 0),
                'month' => (int) ($row['pay_month'] ?? 0),
            ];
        }
    } catch (Throwable $e) {
        // ignored
    }

    return null;
}

/**
 * صلاحيات العرض والتعديل لشهر محدد.
 *
 * @return array{
 *   can_view:bool,
 *   can_edit:bool,
 *   message:string,
 *   alert_type:string,
 *   open_period:?array{year:int,month:int}
 * }
 */
function hr_payroll_month_access(PDO $pdo, int $year, int $month): array
{
    hr_payroll_validate_period($year, $month);

    $selKey = hr_payroll_period_key($year, $month);
    $max = hr_payroll_max_posted_period($pdo);
    $maxKey = $max ? hr_payroll_period_key($max['year'], $max['month']) : 0;
    $open = hr_payroll_open_period($pdo);
    $openKey = $open ? hr_payroll_period_key($open['year'], $open['month']) : null;

    $base = [
        'can_view' => true,
        'can_edit' => false,
        'message' => '',
        'alert_type' => '',
        'open_period' => $open,
    ];

    if ($max && $selKey < $maxKey) {
        return array_merge($base, [
            'can_edit' => false,
            'message' => 'شهر مغلق (مرحّل). للعرض فقط — لا يمكن فك ترحيله طالما يوجد شهر لاحق مرحّل.',
            'alert_type' => 'info',
        ]);
    }

    if ($openKey !== null && $selKey > $openKey) {
        return array_merge($base, [
            'message' => 'لا يمكن العمل على ' . hr_payroll_period_label($year, $month)
                . ' قبل إتمام شهر ' . hr_payroll_period_label($open['year'], $open['month']) . '.',
            'alert_type' => 'warn',
        ]);
    }

    if (!hr_payroll_prev_month_satisfied($pdo, $year, $month)) {
        $prev = hr_payroll_prev_period($year, $month);

        return array_merge($base, [
            'message' => 'يجب ترحيل رواتب شهر '
                . hr_payroll_period_label($prev['year'], $prev['month'])
                . ' قبل العمل على ' . hr_payroll_period_label($year, $month) . '.',
            'alert_type' => 'warn',
        ]);
    }

    if ($openKey !== null && $selKey !== $openKey) {
        return array_merge($base, [
            'message' => 'لا يمكن احتساب أو ترحيل ' . hr_payroll_period_label($year, $month)
                . ' لأن شهر ' . hr_payroll_period_label($open['year'], $open['month'])
                . ' هو الشهر المفتوح حالياً وفيه قيود محتسبة غير مرحّلة.',
            'alert_type' => 'warn',
        ]);
    }

    return array_merge($base, [
        'can_edit' => true,
        'message' => '',
        'alert_type' => '',
    ]);
}

function hr_payroll_assert_can_edit(PDO $pdo, int $year, int $month): void
{
    $access = hr_payroll_month_access($pdo, $year, $month);
    if (!$access['can_edit']) {
        throw new RuntimeException(
            $access['message'] !== '' ? $access['message'] : 'لا يمكن التعديل على هذا الشهر.'
        );
    }
}

/** @return array{ok:bool, message:string, expected:?array{year:int, month:int}} */
function hr_payroll_can_open_month(PDO $pdo, int $year, int $month): array
{
    $access = hr_payroll_month_access($pdo, $year, $month);

    return [
        'ok' => $access['can_edit'],
        'message' => $access['message'],
        'expected' => $access['open_period'],
    ];
}



/**

 * @return array{base:float, allowances:float, deductions:float, gross:float, lines:array<int, array{component_id:int, amount:float}>}

 */

function hr_payroll_snapshot_from_employee(PDO $pdo, int $employeeId): array

{

    if ($employeeId < 1) {

        throw new RuntimeException('موظف غير صالح.');

    }



    hr_employee_ensure_schema($pdo);

    $st = $pdo->prepare('SELECT base_salary FROM hr_employee WHERE id = ? LIMIT 1');

    $st->execute([$employeeId]);

    $base = (float) ($st->fetchColumn() ?: 0);

    if ($base <= 0) {

        throw new RuntimeException('لم يُعرَّف راتب للموظف — من شاشة رواتب الموظفين.');

    }



    $linesMap = hr_employee_salary_lines_load($pdo, $employeeId);

    $lines = [];

    foreach ($linesMap as $line) {

        $lines[] = [

            'component_id' => (int) ($line['component_id'] ?? 0),

            'amount' => (float) ($line['amount'] ?? 0),

        ];

    }

    $totals = hr_employee_salary_totals($base, $linesMap);



    return [

        'base' => $base,

        'allowances' => (float) ($totals['allowances'] ?? 0),

        'deductions' => (float) ($totals['deductions'] ?? 0),

        'gross' => (float) ($totals['gross'] ?? 0),

        'lines' => $lines,

    ];

}



/**

 * @return array<string, mixed>|null

 */

function hr_payroll_salary_row(PDO $pdo, int $employeeId, int $year, int $month): ?array

{

    if ($employeeId < 1) {

        return null;

    }

    try {

        $st = $pdo->prepare(

            'SELECT * FROM hr_salary

             WHERE employee_id = ? AND pay_year = ? AND pay_month = ? LIMIT 1'

        );

        $st->execute([$employeeId, $year, $month]);

        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;

    } catch (Throwable $e) {

        return null;

    }

}



/**
 * شرط ربط الموظف بالقسم (department_id أو اسم القسم النصي).
 *
 * @return array{sql:string, params:array<int, mixed>}
 */
function hr_payroll_employee_department_clause(PDO $pdo, int $departmentId): array
{
    if ($departmentId < 1) {
        return ['sql' => '', 'params' => []];
    }
    hr_department_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT name_ar FROM hr_department WHERE id = ? LIMIT 1');
    $st->execute([$departmentId]);
    $deptName = trim((string) ($st->fetchColumn() ?: ''));
    if ($deptName === '') {
        return ['sql' => ' AND department_id = ?', 'params' => [$departmentId]];
    }

    return [
        'sql' => ' AND (department_id = ? OR (IFNULL(department_id, 0) = 0 AND TRIM(IFNULL(department, \'\')) = ?))',
        'params' => [$departmentId, $deptName],
    ];
}

/** @return array<int, array{id:int, name_ar:string, department_id:int, department:string}> */
function hr_payroll_active_employees_for_filter(PDO $pdo): array
{
    hr_employee_ensure_schema($pdo);
    hr_employee_ensure_link_columns($pdo);
    $rows = $pdo->query(
        'SELECT id, name_ar, department_id, department FROM hr_employee
         WHERE is_active = 1 ' . hr_employee_list_order_sql()
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(static function (array $r): array {
        return [
            'id' => (int) ($r['id'] ?? 0),
            'name_ar' => (string) ($r['name_ar'] ?? ''),
            'department_id' => (int) ($r['department_id'] ?? 0),
            'department' => trim((string) ($r['department'] ?? '')),
        ];
    }, $rows);
}

/** @return array<int, array{id:int, name_ar:string, is_active:int}> */
function hr_payroll_filter_employee_options(PDO $pdo, int $departmentId, int $employeeId): array
{
    hr_employee_ensure_schema($pdo);
    hr_employee_ensure_link_columns($pdo);

    if ($employeeId > 0) {
        $all = hr_employee_picker_list($pdo);
        $found = false;
        foreach ($all as $row) {
            if ((int) ($row['id'] ?? 0) === $employeeId) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $st = $pdo->prepare(
                'SELECT id, emp_code, name_ar, is_active FROM hr_employee WHERE id = ? LIMIT 1'
            );
            $st->execute([$employeeId]);
            $one = $st->fetch(PDO::FETCH_ASSOC);
            if ($one) {
                $all[] = $one;
            }
        }
        return $all;
    }

    $where = 'WHERE is_active = 1';
    $params = [];
    if ($departmentId > 0) {
        $clause = hr_payroll_employee_department_clause($pdo, $departmentId);
        $where .= $clause['sql'];
        $params = $clause['params'];
    }

    $st = $pdo->prepare(
        'SELECT id, emp_code, name_ar, is_active FROM hr_employee ' . $where . ' ' . hr_employee_list_order_sql()
    );
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return list<array<string, mixed>>
 */
function hr_payroll_employee_permanent_deduction_lines(PDO $pdo, int $employeeId): array
{
    if ($employeeId < 1) {
        return [];
    }

    hr_employee_salary_line_ensure_schema($pdo);
    try {
        $st = $pdo->prepare(
            'SELECT l.amount, c.comp_code, c.name_ar, c.is_percent
             FROM hr_employee_salary_line l
             JOIN hr_payroll_component c ON c.id = l.component_id
             WHERE l.employee_id = ? AND c.comp_type = \'deduction\'
             ORDER BY CAST(c.comp_code AS UNSIGNED) ASC, c.id ASC'
        );
        $st->execute([$employeeId]);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @param array<string, mixed> $line
 */
function hr_payroll_breakdown_line_item(array $line, float $baseSalary): array
{
    $amount = (float) ($line['amount'] ?? 0);
    $isPercent = (int) ($line['is_percent'] ?? 0) === 1;
    $display = $isPercent && $baseSalary > 0
        ? hr_employee_monthly_payroll_format_amount_display($line, $baseSalary)
        : number_format($amount, 3);

    return [
        'code' => (string) ($line['comp_code'] ?? ''),
        'name' => (string) ($line['name_ar'] ?? $line['label'] ?? ''),
        'amount' => round($amount, 3),
        'display' => $display,
    ];
}

/**
 * @return array{
 *   base_salary:float,
 *   permanent_allow_total:float,
 *   monthly_allow_total:float,
 *   permanent_allow_lines:list<array<string,mixed>>,
 *   monthly_allow_lines:list<array<string,mixed>>,
 *   deduction_lines:list<array<string,mixed>>
 * }
 */
function hr_payroll_employee_row_breakdown(
    PDO $pdo,
    int $employeeId,
    int $year,
    int $month,
    float $baseSalary,
    int $salaryId = 0
): array {
    $permAllowLines = [];
    $permAllowTotal = 0.0;
    foreach (hr_employee_salary_allowance_lines_list($pdo, $employeeId) as $line) {
        $item = hr_payroll_breakdown_line_item($line, $baseSalary);
        $permAllowLines[] = $item;
        $permAllowTotal += (float) $item['amount'];
    }

    $monthAllowLines = [];
    $monthAllowTotal = 0.0;
    foreach (hr_employee_monthly_payroll_lines_list($pdo, $employeeId, $year, $month, 'allowance') as $line) {
        $item = hr_payroll_breakdown_line_item($line, $baseSalary);
        $monthAllowLines[] = $item;
        $monthAllowTotal += (float) $item['amount'];
    }

    $salaryOvertimeAmount = null;
    if ($salaryId > 0) {
        try {
            $stOt = $pdo->prepare('SELECT overtime FROM hr_salary WHERE id = ? LIMIT 1');
            $stOt->execute([$salaryId]);
            $salaryOvertimeAmount = (float) ($stOt->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            $salaryOvertimeAmount = null;
        }
    }
    $overtimeLine = hr_payroll_overtime_allowance_line($pdo, $employeeId, $year, $month, $salaryOvertimeAmount);
    $overtimeTotal = 0.0;
    if ($overtimeLine) {
        $monthAllowLines[] = $overtimeLine;
        $overtimeTotal = (float) $overtimeLine['amount'];
    }

    $deductionLines = [];
    $deductionTotal = 0.0;
    foreach (hr_payroll_employee_permanent_deduction_lines($pdo, $employeeId) as $line) {
        $item = hr_payroll_breakdown_line_item($line, $baseSalary);
        $item['source'] = 'دائم';
        $deductionLines[] = $item;
        $deductionTotal += (float) $item['amount'];
    }
    foreach (hr_employee_monthly_payroll_lines_list($pdo, $employeeId, $year, $month, 'deduction') as $line) {
        $item = hr_payroll_breakdown_line_item($line, $baseSalary);
        $item['source'] = 'شهري';
        $deductionLines[] = $item;
        $deductionTotal += (float) $item['amount'];
    }
    $adv = hr_employee_advance_deductions_for_month($pdo, $employeeId, $year, $month, $salaryId);
    foreach ($adv['lines'] ?? [] as $line) {
        $amt = round((float) ($line['amount'] ?? 0), 3);
        if ($amt <= 0) {
            continue;
        }
        $deductionLines[] = [
            'code' => '',
            'name' => (string) ($line['label'] ?? 'سلفة'),
            'amount' => $amt,
            'display' => number_format($amt, 3),
            'source' => 'سلفة',
        ];
        $deductionTotal += $amt;
    }

    return [
        'base_salary' => round($baseSalary, 3),
        'permanent_allow_total' => round($permAllowTotal, 3),
        'monthly_allow_total' => round($monthAllowTotal + $overtimeTotal, 3),
        'monthly_allow_core' => round($monthAllowTotal, 3),
        'overtime_total' => round($overtimeTotal, 3),
        'permanent_allow_lines' => $permAllowLines,
        'monthly_allow_lines' => $monthAllowLines,
        'deduction_lines' => $deductionLines,
        'deductions_preview' => round($deductionTotal, 3),
    ];
}

/** @return array<int, array<string, mixed>> */

function hr_payroll_month_status_rows(
    PDO $pdo,
    int $year,
    int $month,
    int $departmentId = 0,
    int $employeeId = 0
): array {

    hr_employee_ensure_schema($pdo);

    hr_employee_salary_line_ensure_schema($pdo);



    hr_employee_ensure_link_columns($pdo);
    hr_department_ensure_schema($pdo);
    hr_job_title_ensure_schema($pdo);

    $where = 'WHERE e.is_active = 1';
    $params = [];
    if ($employeeId > 0) {
        $where .= ' AND e.id = ?';
        $params[] = $employeeId;
    } elseif ($departmentId > 0) {
        $clause = hr_payroll_employee_department_clause($pdo, $departmentId);
        $clauseSql = str_replace(
            ['department_id', 'IFNULL(department', 'TRIM(IFNULL(department'],
            ['e.department_id', 'IFNULL(e.department', 'TRIM(IFNULL(e.department'],
            $clause['sql']
        );
        $where .= $clauseSql;
        $params = array_merge($params, $clause['params']);
    }

    $stEmp = $pdo->prepare(
        'SELECT e.id, e.emp_code, e.name_ar, e.base_salary, e.is_active,
                e.subject_to_social_security, e.department_id, e.job_title_id,
                e.job_title, e.department, e.social_security_no, e.hire_date,
                d.name_ar AS dept_name, jt.name_ar AS job_title_name
         FROM hr_employee e
         LEFT JOIN hr_department d ON d.id = e.department_id
         LEFT JOIN hr_job_title jt ON jt.id = e.job_title_id
         ' . $where . " ORDER BY CASE WHEN e.emp_code REGEXP '^[0-9]+$' THEN CAST(e.emp_code AS UNSIGNED) ELSE 999999999 END ASC,
                         e.emp_code ASC, e.name_ar ASC, e.id ASC"
    );
    $stEmp->execute($params);
    $emps = $stEmp->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $salaryMap = [];

    try {
        $st = $pdo->prepare(
            'SELECT id, employee_id, base_salary, allowances, overtime, bonus,
                    deductions, net_salary, social_security_emp, income_tax, is_posted
             FROM hr_salary WHERE pay_year = ? AND pay_month = ?'
        );
        $st->execute([$year, $month]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $s) {
            $salaryMap[(int) $s['employee_id']] = $s;
        }
    } catch (Throwable $e) {
        // ignored
    }

    $out = [];

    foreach ($emps as $e) {
        $eid = (int) ($e['id'] ?? 0);
        $base = (float) ($e['base_salary'] ?? 0);
        $lines = hr_employee_salary_allowance_lines_only(hr_employee_salary_lines_load($pdo, $eid));
        $hasSetup = $base > 0 || $lines !== [];
        $sal = $salaryMap[$eid] ?? null;
        $status = 'none';
        if ($sal) {
            $status = (int) ($sal['is_posted'] ?? 0) === 1 ? 'posted' : 'calculated';
        }

        $salaryId = $sal ? (int) ($sal['id'] ?? 0) : 0;
        $breakdown = hr_payroll_employee_row_breakdown($pdo, $eid, $year, $month, $base, $salaryId);
        $currentBase = (float) ($breakdown['base_salary'] ?? $base);
        $currentAllowTotal = (float) ($breakdown['permanent_allow_total'] ?? 0)
            + (float) ($breakdown['monthly_allow_core'] ?? $breakdown['monthly_allow_total'] ?? 0);
        $overtimeAmt = (float) ($breakdown['overtime_total'] ?? 0);
        $previewDeductions = (float) ($breakdown['deductions_preview'] ?? 0);

        $subjectSs = (int) ($e['subject_to_social_security'] ?? 0) === 1;
        $ssEmp = 0.0;
        $incomeTax = 0.0;
        $deductions = 0.0;
        $net = 0.0;
        if ($sal) {
            $deductions = (float) ($sal['deductions'] ?? 0);
            $net = (float) ($sal['net_salary'] ?? 0);
            if ($subjectSs) {
                $ssEmp = (float) ($sal['social_security_emp'] ?? 0);
            }
            $incomeTax = (float) ($sal['income_tax'] ?? 0);
            if ((int) ($sal['is_posted'] ?? 0) !== 1 && abs($previewDeductions - $deductions) > 0.0005) {
                $deductions = $previewDeductions;
                if ($overtimeAmt <= 0.0005) {
                    $overtimeAmt = (float) ($sal['overtime'] ?? 0);
                }
                $net = hr_salary_calc_net(
                    $currentBase,
                    $currentAllowTotal,
                    $deductions,
                    $overtimeAmt,
                    (float) ($sal['bonus'] ?? 0),
                    $ssEmp,
                    $incomeTax
                );
            }
        } elseif ($hasSetup) {
            $deductions = $previewDeductions;
        }

        $out[] = [
            'id' => $eid,
            'emp_code' => (string) ($e['emp_code'] ?? ''),
            'name_ar' => (string) ($e['name_ar'] ?? ''),
            'has_setup' => $hasSetup,
            'subject_to_social_security' => $subjectSs ? 1 : 0,
            'status' => $status,
            'salary_id' => $salaryId,
            'base_salary' => $currentBase,
            'permanent_allow_total' => (float) ($breakdown['permanent_allow_total'] ?? 0),
            'monthly_allow_total' => (float) ($breakdown['monthly_allow_total'] ?? 0),
            'deductions' => $deductions,
            'net_salary' => $net,
            'social_security_emp' => $ssEmp,
            'income_tax' => $incomeTax,
            'permanent_allow_lines' => $breakdown['permanent_allow_lines'] ?? [],
            'monthly_allow_lines' => $breakdown['monthly_allow_lines'] ?? [],
            'deduction_lines' => $breakdown['deduction_lines'] ?? [],
        ];
    }

    return $out;
}



/**

 * @param list<int> $employeeIds

 */

function hr_payroll_calculate(PDO $pdo, int $year, int $month, array $employeeIds): int

{

    hr_payroll_validate_period($year, $month);

    hr_payroll_assert_can_edit($pdo, $year, $month);

    $employeeIds = array_values(array_unique(array_filter(array_map('intval', $employeeIds), static fn(int $id): bool => $id > 0)));

    if (!$employeeIds) {

        throw new RuntimeException('اختر موظفاً واحداً على الأقل.');

    }



    $payDate = sprintf('%04d-%02d-01', $year, $month);

    $count = 0;



    foreach ($employeeIds as $empId) {

        $existing = hr_payroll_salary_row($pdo, $empId, $year, $month);

        if ($existing && (int) ($existing['is_posted'] ?? 0) === 1) {

            throw new RuntimeException('موظف لديه قيد مرحّل لهذا الشهر — لا يمكن إعادة الاحتساب.');

        }



        $snap = hr_payroll_snapshot_for_month($pdo, $empId, $year, $month);

        $existingSalaryId = $existing ? (int) $existing['id'] : 0;
        $advDed = hr_employee_advance_deductions_for_month($pdo, $empId, $year, $month, $existingSalaryId);
        $deductionsTotal = round((float) $snap['deductions'] + (float) ($advDed['total'] ?? 0), 3);

        $overtimeAmt = hr_employee_overtime_amount_for_employee($pdo, $empId, $year, $month);

        $ss = null;
        $ssEmp = 0.0;
        if (hr_employee_subject_to_social_security($pdo, $empId)) {
            $ss = hr_ss_calc_for_employee($pdo, $empId, hr_employee_ss_gross_base($pdo, $empId));
            $ssEmp = $ss ? (float) $ss['employee_deduct'] : 0.0;
        }

        $salaryIdForExclude = $existing ? (int) $existing['id'] : 0;
        $itCalc = hr_income_tax_calc_for_employee(
            $pdo,
            $empId,
            $year,
            $month,
            $snap['base'],
            $snap['allowances'],
            $deductionsTotal,
            $overtimeAmt,
            0,
            $ssEmp,
            $salaryIdForExclude
        );
        $incomeTax = (float) ($itCalc['income_tax'] ?? 0);

        $net = hr_salary_calc_net(
            $snap['base'],
            $snap['allowances'],
            $deductionsTotal,
            $overtimeAmt,
            0,
            $ssEmp,
            $incomeTax
        );



        if ($existing) {

            $salaryId = (int) $existing['id'];

            $st = $pdo->prepare(

                'UPDATE hr_salary SET base_salary = ?, allowances = ?, deductions = ?,

                 overtime = ?, social_security_emp = ?, income_tax = ?, net_salary = ?, pay_date = ?, is_posted = 0

                 WHERE id = ?'

            );

            $st->execute([

                $snap['base'], $snap['allowances'], $deductionsTotal, $overtimeAmt,

                $ssEmp, $incomeTax, $net, $payDate, $salaryId,

            ]);

        } else {

            $st = $pdo->prepare(

                'INSERT INTO hr_salary (employee_id, pay_year, pay_month, base_salary, allowances, deductions,

                 overtime, social_security_emp, income_tax, net_salary, pay_date, is_posted)

                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)'

            );

            $st->execute([

                $empId, $year, $month, $snap['base'], $snap['allowances'], $deductionsTotal, $overtimeAmt,

                $ssEmp, $incomeTax, $net, $payDate,

            ]);

            $salaryId = (int) $pdo->lastInsertId();

        }



        hr_salary_save_lines($pdo, $salaryId, $snap['lines']);
        hr_employee_advance_apply_to_salary($pdo, $salaryId, $advDed['lines'] ?? []);

        hr_payroll_sync_social_security_row($pdo, $empId, $year, $month, $ss, $payDate);

        $count++;

    }



    return $count;

}



/**

 * @param array<string, mixed>|null $ss

 */

function hr_payroll_sync_social_security_row(

    PDO $pdo,

    int $employeeId,

    int $year,

    int $month,

    ?array $ss,

    string $payDate

): void {

    if (!$ss || (float) ($ss['employee_deduct'] ?? 0) <= 0 && (float) ($ss['employer_amount'] ?? 0) <= 0) {

        try {

            $pdo->prepare(

                'DELETE FROM hr_social_security WHERE employee_id = ? AND pay_year = ? AND pay_month = ?'

            )->execute([$employeeId, $year, $month]);

        } catch (Throwable $e) {

            // ignored

        }

        return;

    }



    $gross = (float) ($ss['gross'] ?? 0);

    $empShare = (float) ($ss['employee_deduct'] ?? 0);

    $erShare = (float) ($ss['employer_amount'] ?? 0);

    $total = round($empShare + $erShare, 3);



    try {

        $st = $pdo->prepare(

            'SELECT id FROM hr_social_security WHERE employee_id = ? AND pay_year = ? AND pay_month = ? LIMIT 1'

        );

        $st->execute([$employeeId, $year, $month]);

        $id = (int) ($st->fetchColumn() ?: 0);

        if ($id > 0) {

            $pdo->prepare(

                'UPDATE hr_social_security SET base_amount = ?, employee_share = ?, employer_share = ?,

                 total_share = ?, pay_date = ?, is_posted = 0 WHERE id = ?'

            )->execute([$gross, $empShare, $erShare, $total, $payDate, $id]);

        } else {

            $pdo->prepare(

                'INSERT INTO hr_social_security (employee_id, pay_year, pay_month, base_amount, employee_share,

                 employer_share, total_share, pay_date, is_posted)

                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)'

            )->execute([$employeeId, $year, $month, $gross, $empShare, $erShare, $total, $payDate]);

        }

    } catch (Throwable $e) {

        // ignored

    }

}



/**

 * @return array{
 *   posted_count:int,
 *   gross_total:float,
 *   net_total:float,
 *   employer_total:float,
 *   employee_total:float,
 *   payable_total:float,
 *   journal_id:int
 * }

 */

function hr_payroll_post_month(PDO $pdo, int $year, int $month): array

{

    hr_payroll_validate_period($year, $month);

    hr_payroll_assert_can_edit($pdo, $year, $month);

    $st = $pdo->prepare(

        'SELECT id, employee_id, social_security_emp FROM hr_salary

         WHERE pay_year = ? AND pay_month = ? AND is_posted = 0'

    );

    $st->execute([$year, $month]);

    $drafts = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (!$drafts) {

        throw new RuntimeException('لا توجد قيود رواتب محتسبة غير مرحّلة لهذا الشهر.');

    }



    $ssResolved = hr_payroll_resolve_ss_totals($pdo, $year, $month, $drafts);
    $employerTotal = (float) ($ssResolved['employer_ss'] ?? 0);
    $employeeTotal = (float) ($ssResolved['employee_ss'] ?? 0);
    $payableTotal = (float) ($ssResolved['payable_ss'] ?? 0);

    $salaryTotals = hr_payroll_month_gl_totals($pdo, $year, $month, true);
    $glTotals = array_merge($salaryTotals, [
        'employer_ss' => $employerTotal,
    ]);

    $ready = hr_payroll_posting_ready($pdo, $glTotals);
    if (!$ready['ready']) {
        throw new RuntimeException($ready['message']);
    }



    $refId = hr_payroll_month_ref_id($year, $month);

    $entryDate = sprintf('%04d-%02d-%02d', $year, $month, (int) date('t', mktime(0, 0, 0, $month, 1, $year)));

    $journalId = 0;



    $pdo->beginTransaction();

    try {

        $glLines = hr_payroll_posting_gl_lines($glTotals);
        $periodLabel = hr_payroll_period_label($year, $month);

        if ($glLines && !acc_gl_ref_exists($pdo, HR_PAYROLL_GL_REF_TYPE, $refId)) {

            $journalId = acc_gl_post_entry(

                $pdo,

                HR_PAYROLL_GL_REF_TYPE,

                $refId,

                $entryDate,

                'ترحيل رواتب — ' . $periodLabel,

                $glLines

            );

        } elseif (acc_gl_ref_exists($pdo, HR_PAYROLL_GL_REF_TYPE, $refId)) {

            $stJ = $pdo->prepare(

                "SELECT id FROM acc_journal_entry WHERE ref_type = ? AND ref_id = ? AND source = 'auto' LIMIT 1"

            );

            $stJ->execute([HR_PAYROLL_GL_REF_TYPE, $refId]);

            $journalId = (int) ($stJ->fetchColumn() ?: 0);

        }

        // إزالة قيد قديم منفصل لحصة الشركة (قبل دمج القيود)
        hr_payroll_remove_legacy_employer_ss_journal($pdo, $refId);



        $pdo->prepare(

            'UPDATE hr_salary SET is_posted = 1 WHERE pay_year = ? AND pay_month = ? AND is_posted = 0'

        )->execute([$year, $month]);



        try {

            $pdo->prepare(

                'UPDATE hr_social_security SET is_posted = 1 WHERE pay_year = ? AND pay_month = ?'

            )->execute([$year, $month]);

        } catch (Throwable $e) {

            // ignored

        }



        $pdo->commit();

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {

            $pdo->rollBack();

        }

        throw $e;

    }



    return [

        'posted_count' => count($drafts),

        'gross_total' => (float) ($salaryTotals['gross'] ?? 0),

        'net_total' => (float) ($salaryTotals['net'] ?? 0),

        'employer_total' => $employerTotal,

        'employee_total' => $employeeTotal,

        'payable_total' => $payableTotal,

        'journal_id' => $journalId,

    ];

}



/**
 * إلغاء احتساب رواتب محتسبة غير مرحّلة لموظفين محددين.
 *
 * @param list<int> $employeeIds
 * @return array{cancelled:int}
 */
function hr_payroll_cancel_calculate(PDO $pdo, int $year, int $month, array $employeeIds): array
{
    hr_payroll_validate_period($year, $month);
    hr_payroll_assert_can_edit($pdo, $year, $month);

    $employeeIds = array_values(array_unique(array_filter(
        array_map('intval', $employeeIds),
        static fn(int $id): bool => $id > 0
    )));
    if (!$employeeIds) {
        throw new RuntimeException('اختر موظفاً واحداً على الأقل لإلغاء الاحتساب.');
    }

    $advanceIds = [];
    $cancelled = 0;

    $pdo->beginTransaction();
    try {
        foreach ($employeeIds as $empId) {
            $existing = hr_payroll_salary_row($pdo, $empId, $year, $month);
            if (!$existing) {
                continue;
            }
            if ((int) ($existing['is_posted'] ?? 0) === 1) {
                throw new RuntimeException('لا يمكن إلغاء احتساب قيد مرحّل.');
            }

            $salaryId = (int) ($existing['id'] ?? 0);
            if ($salaryId < 1) {
                continue;
            }

            try {
                hr_employee_advance_ensure_schema($pdo);
                $stAdv = $pdo->prepare(
                    'SELECT DISTINCT advance_id FROM hr_salary_advance_deduction WHERE salary_id = ?'
                );
                $stAdv->execute([$salaryId]);
                foreach ($stAdv->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $aid = (int) ($row['advance_id'] ?? 0);
                    if ($aid > 0 && !in_array($aid, $advanceIds, true)) {
                        $advanceIds[] = $aid;
                    }
                }
            } catch (Throwable $e) {
                // ignored
            }

            $stDel = $pdo->prepare('DELETE FROM hr_salary WHERE id = ? AND is_posted = 0');
            $stDel->execute([$salaryId]);
            if ($stDel->rowCount() < 1) {
                continue;
            }

            $cancelled++;
            try {
                $pdo->prepare(
                    'DELETE FROM hr_social_security WHERE employee_id = ? AND pay_year = ? AND pay_month = ?'
                )->execute([$empId, $year, $month]);
            } catch (Throwable $e) {
                // ignored
            }
        }

        if ($cancelled === 0) {
            throw new RuntimeException('لا توجد قيود محتسبة (غير مرحّلة) للموظفين المحددين.');
        }

        hr_employee_advance_resync_after_salary_month_deleted($pdo, $advanceIds);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return ['cancelled' => $cancelled];
}

/**

 * @return array{deleted:int}

 */

function hr_payroll_unpost_month(PDO $pdo, int $year, int $month): array

{

    hr_payroll_validate_period($year, $month);

    require_once app_path('includes/hr_salary.php');
    if (hr_salary_month_has_disbursement($pdo, $year, $month)) {
        throw new RuntimeException(
            'لا يمكن فك ترحيل '
            . hr_payroll_period_label($year, $month)
            . ': وُجد صرف رواتب من المحاسبة لموظفين في هذا الشهر.'
        );
    }

    $max = hr_payroll_max_posted_period($pdo);

    if (!hr_payroll_can_unpost_month($pdo, $year, $month)) {
        if ($max === null) {
            throw new RuntimeException('لا يوجد شهر مرحّل لفك الترحيل.');
        }
        if (hr_payroll_period_key($year, $month) < hr_payroll_period_key($max['year'], $max['month'])) {
            throw new RuntimeException(
                'لا يمكن فك ترحيل '
                . hr_payroll_period_label($year, $month)
                . ' لوجود شهر لاحق مرحّل ومغلق ('
                . hr_payroll_period_label($max['year'], $max['month'])
                . '). يُفك الترحيل من آخر شهر مرحّل فقط.'
            );
        }
        throw new RuntimeException(
            'يمكن فك ترحيل آخر شهر مرحّل فقط ('
            . hr_payroll_period_label($max['year'], $max['month'])
            . ').'
        );
    }

    $refId = hr_payroll_month_ref_id($year, $month);

    $pdo->beginTransaction();

    try {

        if (acc_gl_journal_has_ref_columns($pdo) && acc_gl_ref_exists($pdo, HR_PAYROLL_GL_REF_TYPE, $refId)) {

            $un = acc_gl_unpost_ref($pdo, HR_PAYROLL_GL_REF_TYPE, $refId);

            if (!$un['ok']) {

                throw new RuntimeException((string) ($un['error'] ?? 'تعذر فك الترحيل المحاسبي.'));

            }

        }

        hr_payroll_remove_legacy_employer_ss_journal($pdo, $refId);

        $advanceIdsToResync = hr_employee_advance_ids_for_salary_month($pdo, $year, $month);

        $stDel = $pdo->prepare('DELETE FROM hr_salary WHERE pay_year = ? AND pay_month = ?');

        $stDel->execute([$year, $month]);

        $deleted = $stDel->rowCount();

        hr_employee_advance_resync_after_salary_month_deleted($pdo, $advanceIdsToResync);

        try {

            $pdo->prepare('DELETE FROM hr_social_security WHERE pay_year = ? AND pay_month = ?')

                ->execute([$year, $month]);

        } catch (Throwable $e) {

            // ignored

        }



        $pdo->commit();

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {

            $pdo->rollBack();

        }

        throw $e;

    }



    return ['deleted' => $deleted];

}



/** ملخص شهر للواجهة. */

function hr_payroll_month_summary(
    PDO $pdo,
    int $year,
    int $month,
    int $departmentId = 0,
    int $employeeId = 0
): array {

    $rows = hr_payroll_month_status_rows($pdo, $year, $month, $departmentId, $employeeId);

    $calc = 0;

    $posted = 0;

    foreach ($rows as $r) {

        if (($r['status'] ?? '') === 'calculated') {

            $calc++;

        } elseif (($r['status'] ?? '') === 'posted') {

            $posted++;

        }

    }



    $access = hr_payroll_month_access($pdo, $year, $month);

    $canUnpost = hr_payroll_can_unpost_month($pdo, $year, $month);



    return [

        'calculated' => $calc,

        'posted' => $posted,

        'gate_ok' => $access['can_edit'],

        'gate_message' => $access['message'],

        'gate_alert_type' => $access['alert_type'],

        'open_period' => $access['open_period'],

        'can_unpost' => $canUnpost,

    ];

}

