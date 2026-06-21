<?php
declare(strict_types=1);

require_once app_path('includes/hr_employee_advance.php');
require_once app_path('includes/hr_schema.php');

function hr_employee_advances_report_department_options(PDO $pdo): array
{
    hr_department_ensure_schema($pdo);
    try {
        $rows = $pdo->query(
            'SELECT id, name_ar FROM hr_department WHERE COALESCE(is_active, 1) = 1 ORDER BY name_ar ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $r): array {
            return [
                'id' => (int) ($r['id'] ?? 0),
                'name_ar' => (string) ($r['name_ar'] ?? ''),
            ];
        }, $rows);
    } catch (Throwable $e) {
        return [];
    }
}

/** @return list<array{id:int, emp_code:string, name_ar:string, department_id:int, dept_name:string}> */
function hr_employee_advances_report_employee_options(PDO $pdo): array
{
    hr_employee_ensure_schema($pdo);
    hr_department_ensure_schema($pdo);
    try {
        $st = $pdo->query(
            'SELECT e.id, e.emp_code, e.name_ar, e.department_id,
                    COALESCE(d.name_ar, NULLIF(TRIM(e.department), \'\'), \'—\') AS dept_name
             FROM hr_employee e
             LEFT JOIN hr_department d ON d.id = e.department_id
             ORDER BY e.name_ar ASC, e.id ASC'
        );

        return array_map(static function (array $r): array {
            return [
                'id' => (int) ($r['id'] ?? 0),
                'emp_code' => (string) ($r['emp_code'] ?? ''),
                'name_ar' => (string) ($r['name_ar'] ?? ''),
                'department_id' => (int) ($r['department_id'] ?? 0),
                'dept_name' => (string) ($r['dept_name'] ?? ''),
            ];
        }, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    } catch (Throwable $e) {
        return [];
    }
}

function hr_employee_advances_report_hr_status_label(array $row): string
{
    if ((string) ($row['status'] ?? '') === 'cancelled') {
        return 'ملغاة';
    }

    return (int) ($row['is_posted'] ?? 0) === 1 ? 'مرحّلة من الشؤون' : 'غير مرحّلة';
}

function hr_employee_advances_report_disbursement_label(array $row, bool $disbColsReady): string
{
    if ((string) ($row['status'] ?? '') === 'cancelled') {
        return '—';
    }
    if ((int) ($row['is_posted'] ?? 0) !== 1) {
        return '—';
    }
    if ($disbColsReady) {
        $disbursed = (int) ($row['is_disbursed'] ?? 0) === 1
            || (int) ($row['disbursement_voucher_id'] ?? 0) > 0;
        if ($disbursed) {
            return 'تم الصرف من المحاسبة';
        }
    }

    return 'بانتظار الصرف';
}

/**
 * @return array{
 *   departments: list<array{
 *     dept_id:int,
 *     dept_name:string,
 *     employees: list<array{
 *       employee_id:int,
 *       emp_code:string,
 *       emp_name:string,
 *       advances: list<array<string, mixed>>,
 *       total: float
 *     }>,
 *     total: float
 *   }>,
 *   grand_total: float,
 *   advance_count: int
 * }
 */
function hr_employee_advances_report_build(
    PDO $pdo,
    string $fromIso,
    string $toIso,
    int $departmentId = 0,
    int $employeeId = 0
): array {
    hr_employee_advance_ensure_schema($pdo);
    hr_employee_advance_ensure_post_columns($pdo);
    hr_employee_ensure_schema($pdo);
    hr_department_ensure_schema($pdo);

    $empty = [
        'departments' => [],
        'grand_total' => 0.0,
        'advance_count' => 0,
    ];

    if ($fromIso === '' || $toIso === '') {
        return $empty;
    }

    $disbCols = hr_employee_advance_disbursement_columns_ready($pdo);
    $disbSelect = $disbCols
        ? ', a.is_disbursed, a.disbursement_voucher_id, a.disbursed_at'
        : '';

    $sql = 'SELECT a.id, a.advance_code, a.advance_type, a.total_amount, a.start_date, a.end_date,
                   a.notes, a.status, a.is_posted, a.posted_at'
        . $disbSelect
        . ', e.id AS employee_id, e.emp_code, e.name_ar AS emp_name, e.department_id,
                   COALESCE(d.name_ar, NULLIF(TRIM(e.department), \'\'), ?) AS dept_name,
                   COALESCE(d.id, 0) AS dept_id_sort
            FROM hr_employee_advance a
            INNER JOIN hr_employee e ON e.id = a.employee_id
            LEFT JOIN hr_department d ON d.id = e.department_id
            WHERE a.start_date IS NOT NULL
              AND a.start_date >= ?
              AND a.start_date <= ?';
    $params = ['— بدون قسم —', $fromIso, $toIso];

    if ($departmentId > 0) {
        $sql .= ' AND e.department_id = ?';
        $params[] = $departmentId;
    }
    if ($employeeId > 0) {
        $sql .= ' AND e.id = ?';
        $params[] = $employeeId;
    }

    $sql .= ' ORDER BY dept_name ASC, e.name_ar ASC, e.id ASC, a.start_date ASC, CAST(a.advance_code AS UNSIGNED) ASC, a.id ASC';

    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $raw = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return $empty;
    }

    if ($raw === []) {
        return $empty;
    }

    $deptGroups = [];
    foreach ($raw as $row) {
        $deptKey = (string) ($row['dept_name'] ?? '— بدون قسم —');
        $deptIdSort = (int) ($row['dept_id_sort'] ?? 0);
        $empId = (int) ($row['employee_id'] ?? 0);
        $amount = round((float) ($row['total_amount'] ?? 0), 3);

        if (!isset($deptGroups[$deptKey])) {
            $deptGroups[$deptKey] = [
                'dept_id' => $deptIdSort,
                'dept_name' => $deptKey,
                'employees' => [],
                'total' => 0.0,
            ];
        }
        if (!isset($deptGroups[$deptKey]['employees'][$empId])) {
            $deptGroups[$deptKey]['employees'][$empId] = [
                'employee_id' => $empId,
                'emp_code' => (string) ($row['emp_code'] ?? ''),
                'emp_name' => (string) ($row['emp_name'] ?? ''),
                'advances' => [],
                'total' => 0.0,
            ];
        }

        $startIso = (string) ($row['start_date'] ?? '');
        $endIso = (string) ($row['end_date'] ?? '');
        $periodLabel = $startIso !== '' ? format_date_dmY($startIso) : '—';
        if ($endIso !== '' && $endIso !== $startIso) {
            $periodLabel .= ' → ' . format_date_dmY($endIso);
        }

        $deptGroups[$deptKey]['employees'][$empId]['advances'][] = [
            'advance_code' => trim((string) ($row['advance_code'] ?? '')) !== ''
                ? (string) $row['advance_code']
                : (string) (int) ($row['id'] ?? 0),
            'advance_type_label' => hr_employee_advance_type_label((string) ($row['advance_type'] ?? '')),
            'advance_date' => $startIso,
            'advance_date_display' => $startIso !== '' ? format_date_dmY($startIso) : '—',
            'period_label' => $periodLabel,
            'amount' => $amount,
            'hr_status_label' => hr_employee_advances_report_hr_status_label($row),
            'disbursement_label' => hr_employee_advances_report_disbursement_label($row, $disbCols),
            'notes' => trim((string) ($row['notes'] ?? '')),
        ];
        $deptGroups[$deptKey]['employees'][$empId]['total'] += $amount;
        $deptGroups[$deptKey]['total'] += $amount;
    }

    $departments = [];
    $grandTotal = 0.0;
    $advanceCount = 0;
    foreach ($deptGroups as $g) {
        $employees = [];
        foreach ($g['employees'] as $emp) {
            $emp['total'] = round((float) $emp['total'], 3);
            $seq = 1;
            foreach ($emp['advances'] as &$adv) {
                $adv['seq'] = $seq++;
                $advanceCount++;
            }
            unset($adv);
            $employees[] = $emp;
        }
        $g['employees'] = $employees;
        $g['total'] = round((float) $g['total'], 3);
        $departments[] = $g;
        $grandTotal += $g['total'];
    }

    return [
        'departments' => $departments,
        'grand_total' => round($grandTotal, 3),
        'advance_count' => $advanceCount,
    ];
}
