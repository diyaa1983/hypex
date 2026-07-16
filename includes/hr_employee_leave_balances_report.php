<?php
declare(strict_types=1);

require_once app_path('includes/hr_employee_leave_balance.php');
require_once app_path('includes/hr_leave_type.php');
require_once app_path('includes/hr_schema.php');

/** @return list<array{id:int, name_ar:string}> */
function hr_employee_leave_balances_report_department_options(PDO $pdo): array
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

/** @return list<array{id:int, leave_code:string, name_ar:string}> */
function hr_employee_leave_balances_report_leave_type_options(PDO $pdo): array
{
    return array_map(static function (array $r): array {
        return [
            'id' => (int) ($r['id'] ?? 0),
            'leave_code' => (string) ($r['leave_code'] ?? ''),
            'name_ar' => (string) ($r['name_ar'] ?? ''),
        ];
    }, hr_leave_type_list($pdo, true));
}

/** @return list<array{id:int, emp_code:string, name_ar:string, department_id:int, dept_name:string}> */
function hr_employee_leave_balances_report_employee_options(PDO $pdo): array
{
    $rows = hr_employee_leave_balances_report_fetch_employees($pdo, 0, 0);

    return array_map(static function (array $r): array {
        return [
            'id' => (int) ($r['id'] ?? 0),
            'emp_code' => (string) ($r['emp_code'] ?? ''),
            'name_ar' => (string) ($r['name_ar'] ?? ''),
            'department_id' => (int) ($r['department_id'] ?? 0),
            'dept_name' => trim((string) ($r['dept_name'] ?? '')) !== ''
                ? (string) $r['dept_name']
                : '—',
        ];
    }, $rows);
}

/**
 * @return list<array<string, mixed>>
 */
function hr_employee_leave_balances_report_fetch_employees(
    PDO $pdo,
    int $departmentId,
    int $employeeId = 0
): array {
    hr_employee_ensure_schema($pdo);
    hr_employee_ensure_link_columns($pdo);

    $sql = 'SELECT e.id, e.emp_code, e.name_ar, e.hire_date, e.department_id,
                   COALESCE(d.name_ar, e.department, \'\') AS dept_name,
                   e.is_active, e.resignation_date, e.is_resigned_posted
            FROM hr_employee e
            LEFT JOIN hr_department d ON d.id = e.department_id
            WHERE e.is_active = 1
              AND COALESCE(e.is_resigned_posted, 0) = 0
              AND (e.resignation_date IS NULL OR TRIM(e.resignation_date) = \'\')';
    $params = [];

    if ($departmentId > 0) {
        $sql .= ' AND e.department_id = ?';
        $params[] = $departmentId;
    }

    if ($employeeId > 0) {
        $sql .= ' AND e.id = ?';
        $params[] = $employeeId;
    }

    $sql .= ' ' . hr_employee_list_order_sql();

    try {
        if ($params === []) {
            $st = $pdo->query($sql);
        } else {
            $st = $pdo->prepare($sql);
            $st->execute($params);
        }
        $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        return [];
    }

    return $rows;
}

/**
 * @param list<array<string, mixed>> $balanceRows
 * @return list<array<string, mixed>>
 */
function hr_employee_leave_balances_report_map_balance_rows(array $balanceRows): array
{
    $rows = [];
    foreach ($balanceRows as $balRow) {
        $rows[] = [
            'leave_type_id' => (int) ($balRow['leave_type_id'] ?? 0),
            'leave_code' => (string) ($balRow['leave_code'] ?? ''),
            'type_name' => (string) ($balRow['type_name'] ?? ''),
            'prorate_label' => !empty($balRow['prorate_yearly']) ? 'نعم' : '',
            'annual_days' => (float) ($balRow['annual_days'] ?? 0),
            'opening_balance' => (float) ($balRow['opening_balance'] ?? 0),
            'entitled_balance' => (float) ($balRow['entitled_balance'] ?? 0),
            'taken_days' => (float) ($balRow['taken_days'] ?? 0),
            'remaining' => (float) ($balRow['remaining'] ?? 0),
        ];
    }

    return $rows;
}

/**
 * @return array{
 *   departments: list<array{
 *     dept_id:int,
 *     dept_name:string,
 *     employees:list<array{
 *       employee_id:int,
 *       emp_code:string,
 *       emp_name:string,
 *       rows:list<array<string,mixed>>
 *     }>,
 *     employee_count:int
 *   }>,
 *   employee_count:int,
 *   row_count:int
 * }
 */
function hr_employee_leave_balances_report_build(
    PDO $pdo,
    string $periodFrom,
    string $periodTo,
    int $departmentId,
    int $leaveTypeId,
    int $employeeId = 0
): array {
    hr_employee_leave_balance_ensure_schema($pdo);

    if ($periodFrom === '' || $periodTo === '' || $periodFrom > $periodTo) {
        $defaults = hr_employee_leave_balance_default_period();
        $periodFrom = $defaults['from'];
        $periodTo = $defaults['to'];
    }

    $employees = hr_employee_leave_balances_report_fetch_employees($pdo, $departmentId, $employeeId);
    $depts = [];
    $employeeCount = 0;
    $rowCount = 0;

    foreach ($employees as $emp) {
        $empId = (int) ($emp['id'] ?? 0);
        if ($empId < 1) {
            continue;
        }

        $balanceRows = hr_employee_leave_balance_rows_for_employee($pdo, $empId, $periodFrom, $periodTo);
        if ($leaveTypeId > 0) {
            $balanceRows = array_values(array_filter(
                $balanceRows,
                static fn (array $row): bool => (int) ($row['leave_type_id'] ?? 0) === $leaveTypeId
            ));
        }
        if ($balanceRows === []) {
            continue;
        }

        $mappedRows = hr_employee_leave_balances_report_map_balance_rows($balanceRows);

        $deptId = (int) ($emp['department_id'] ?? 0);
        $deptName = trim((string) ($emp['dept_name'] ?? ''));
        if ($deptName === '') {
            $deptName = 'بدون قسم';
        }
        $deptKey = $deptId . '|' . $deptName;

        if (!isset($depts[$deptKey])) {
            $depts[$deptKey] = [
                'dept_id' => $deptId,
                'dept_name' => $deptName,
                'employees' => [],
                'employee_count' => 0,
            ];
        }

        $depts[$deptKey]['employees'][] = [
            'employee_id' => $empId,
            'emp_code' => (string) ($emp['emp_code'] ?? ''),
            'emp_name' => (string) ($emp['name_ar'] ?? ''),
            'rows' => $mappedRows,
        ];
        $depts[$deptKey]['employee_count']++;
        $employeeCount++;
        $rowCount += count($mappedRows);
    }

    usort($depts, static function (array $a, array $b): int {
        $nameA = (string) ($a['dept_name'] ?? '');
        $nameB = (string) ($b['dept_name'] ?? '');
        if ($nameA === 'بدون قسم') {
            return 1;
        }
        if ($nameB === 'بدون قسم') {
            return -1;
        }

        return strcmp($nameA, $nameB);
    });

    foreach ($depts as &$dept) {
        $deptRows = [];
        foreach ($dept['employees'] as $emp) {
            foreach ($emp['rows'] as $row) {
                $deptRows[] = $row;
            }
        }
        $dept['totals'] = hr_employee_leave_balances_report_sum_rows($deptRows);
    }
    unset($dept);

    return [
        'departments' => array_values($depts),
        'employee_count' => $employeeCount,
        'row_count' => $rowCount,
        'period_from' => $periodFrom,
        'period_to' => $periodTo,
    ];
}

/** @return array<string, mixed>|null */
function hr_employee_leave_balance_print_build(
    PDO $pdo,
    int $employeeId,
    string $periodFrom,
    string $periodTo,
    int $leaveTypeId
): ?array {
    hr_employee_leave_balance_ensure_schema($pdo);
    if ($employeeId < 1) {
        return null;
    }

    if ($periodFrom === '' || $periodTo === '' || $periodFrom > $periodTo) {
        $defaults = hr_employee_leave_balance_default_period();
        $periodFrom = $defaults['from'];
        $periodTo = $defaults['to'];
    }

    hr_employee_ensure_schema($pdo);
    hr_employee_ensure_link_columns($pdo);

    $st = $pdo->prepare(
        'SELECT e.id, e.emp_code, e.name_ar, e.hire_date,
                COALESCE(d.name_ar, e.department, \'\') AS dept_name
         FROM hr_employee e
         LEFT JOIN hr_department d ON d.id = e.department_id
         WHERE e.id = ?
         LIMIT 1'
    );
    $st->execute([$employeeId]);
    $emp = $st->fetch(PDO::FETCH_ASSOC);
    if (!$emp) {
        return null;
    }

    $balanceRows = hr_employee_leave_balance_rows_for_employee($pdo, $employeeId, $periodFrom, $periodTo);
    if ($leaveTypeId > 0) {
        $balanceRows = array_values(array_filter(
            $balanceRows,
            static fn (array $row): bool => (int) ($row['leave_type_id'] ?? 0) === $leaveTypeId
        ));
    }

    $typeLabel = 'جميع الإجازات';
    if ($leaveTypeId > 0) {
        foreach (hr_leave_type_list($pdo, true) as $type) {
            if ((int) ($type['id'] ?? 0) === $leaveTypeId) {
                $typeLabel = (string) ($type['name_ar'] ?? $typeLabel);
                break;
            }
        }
    }

    $hireRaw = trim((string) ($emp['hire_date'] ?? ''));

    return [
        'employee_id' => $employeeId,
        'emp_code' => (string) ($emp['emp_code'] ?? ''),
        'emp_name' => (string) ($emp['name_ar'] ?? ''),
        'dept_name' => trim((string) ($emp['dept_name'] ?? '')) !== '' ? (string) $emp['dept_name'] : '—',
        'hire_date' => $hireRaw !== '' ? format_date_dmY($hireRaw) : '',
        'leave_type_label' => $typeLabel,
        'period_from' => $periodFrom,
        'period_to' => $periodTo,
        'rows' => hr_employee_leave_balances_report_map_balance_rows($balanceRows),
    ];
}

function hr_employee_leave_balance_print_url(
    int $employeeId,
    string $periodFrom,
    string $periodTo,
    int $leaveTypeId = 0
): string {
    $params = [
        'r' => 'hr_employee_leave_balance_print',
        'id' => (string) $employeeId,
        'date_from' => $periodFrom,
        'date_to' => $periodTo,
    ];
    if ($leaveTypeId > 0) {
        $params['leave_type_id'] = (string) $leaveTypeId;
    }

    return app_url('index.php?' . http_build_query($params));
}

function hr_employee_leave_balances_report_fmt(float $value): string
{
    return number_format($value, 2, '.', '');
}

/**
 * @param list<array<string, mixed>> $rows
 * @return array{annual_days:float,opening_balance:float,entitled_balance:float,taken_days:float,remaining:float}
 */
function hr_employee_leave_balances_report_sum_rows(array $rows): array
{
    $totals = [
        'annual_days' => 0.0,
        'opening_balance' => 0.0,
        'entitled_balance' => 0.0,
        'taken_days' => 0.0,
        'remaining' => 0.0,
    ];

    foreach ($rows as $row) {
        $totals['annual_days'] += (float) ($row['annual_days'] ?? 0);
        $totals['opening_balance'] += (float) ($row['opening_balance'] ?? 0);
        $totals['entitled_balance'] += (float) ($row['entitled_balance'] ?? 0);
        $totals['taken_days'] += (float) ($row['taken_days'] ?? 0);
        $totals['remaining'] += (float) ($row['remaining'] ?? 0);
    }

    return $totals;
}

/**
 * @param array<string, mixed> $deptBlock
 */
function hr_employee_leave_balances_report_render_dept_table(array $deptBlock): void
{
    ?>
    <div class="hr-ld-rpt-table-wrap">
        <table class="hr-ld-rpt-table report-sales-table hr-leave-bal-rpt-table hr-leave-bal-rpt-table--data">
            <colgroup>
                <col class="col-seq">
                <col class="hr-ld-rpt-col-emp-code">
                <col class="hr-ld-rpt-col-emp-name">
                <col class="hr-leave-bal-rpt-col-type">
                <col class="hr-leave-bal-rpt-col-num">
                <col class="hr-leave-bal-rpt-col-num">
                <col class="hr-leave-bal-rpt-col-num">
                <col class="hr-leave-bal-rpt-col-num">
                <col class="hr-leave-bal-rpt-col-num">
            </colgroup>
            <thead>
            <tr>
                <th class="col-seq">#</th>
                <th class="hr-ld-rpt-col-emp-code hr-leave-bal-rpt-col-emp-code">رقم الموظف</th>
                <th class="hr-ld-rpt-col-emp-name">اسم الموظف</th>
                <th class="hr-leave-bal-rpt-col-type">نوع الإجازة</th>
                <th class="hr-leave-bal-rpt-col-num">أيام الإعداد</th>
                <th class="hr-leave-bal-rpt-col-num">رصيد الإجازات</th>
                <th class="hr-leave-bal-rpt-col-num">الرصيد المستحق</th>
                <th class="hr-leave-bal-rpt-col-num">المأخوذ</th>
                <th class="hr-leave-bal-rpt-col-num">المتبقي</th>
            </tr>
            </thead>
            <tbody>
            <?php $seq = 0; ?>
            <?php foreach (($deptBlock['employees'] ?? []) as $empBlock): ?>
                <?php foreach (($empBlock['rows'] ?? []) as $row): ?>
                    <?php $seq++; ?>
                    <tr>
                        <td class="col-seq"><?= $seq ?></td>
                        <td class="hr-ld-rpt-col-emp-code hr-leave-bal-rpt-col-emp-code num" dir="ltr"><?= esc((string) ($empBlock['emp_code'] ?? '—')) ?></td>
                        <td class="hr-ld-rpt-col-emp-name"><?= esc((string) ($empBlock['emp_name'] ?? '—')) ?></td>
                        <td class="hr-leave-bal-rpt-col-type"><?= esc((string) ($row['type_name'] ?? '')) ?></td>
                        <td class="hr-leave-bal-rpt-col-num num" dir="ltr"><?= esc(hr_employee_leave_balances_report_fmt((float) ($row['annual_days'] ?? 0))) ?></td>
                        <td class="hr-leave-bal-rpt-col-num num" dir="ltr"><?= esc(hr_employee_leave_balances_report_fmt((float) ($row['opening_balance'] ?? 0))) ?></td>
                        <td class="hr-leave-bal-rpt-col-num num" dir="ltr"><?= esc(hr_employee_leave_balances_report_fmt((float) ($row['entitled_balance'] ?? 0))) ?></td>
                        <td class="hr-leave-bal-rpt-col-num num" dir="ltr"><?= esc(hr_employee_leave_balances_report_fmt((float) ($row['taken_days'] ?? 0))) ?></td>
                        <td class="hr-leave-bal-rpt-col-num num hr-leave-bal-rpt-col-remaining" dir="ltr"><?= esc(hr_employee_leave_balances_report_fmt((float) ($row['remaining'] ?? 0))) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            <?php if ($seq === 0): ?>
                <tr><td colspan="9" class="muted center">لا توجد بيانات.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/**
 * @param list<array<string, mixed>> $rows
 */
function hr_employee_leave_balances_report_render_table(
    array $rows,
    bool $showFooter = true,
    string $footerLabel = 'المجموع'
): void
{
    $totals = hr_employee_leave_balances_report_sum_rows($rows);
    ?>
    <table class="hr-ld-rpt-table report-sales-table hr-leave-bal-rpt-table hr-leave-bal-rpt-table--emp">
        <thead>
        <tr>
            <th class="hr-leave-bal-rpt-col-type">نوع الإجازة</th>
            <th class="hr-leave-bal-rpt-col-num">أيام الإعداد</th>
            <th class="hr-leave-bal-rpt-col-num">رصيد الإجازات</th>
            <th class="hr-leave-bal-rpt-col-num">الرصيد المستحق</th>
            <th class="hr-leave-bal-rpt-col-num">المأخوذ</th>
            <th class="hr-leave-bal-rpt-col-num">المتبقي</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($rows === []): ?>
            <tr><td colspan="6" class="muted center">لا توجد بيانات.</td></tr>
        <?php else: ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="hr-leave-bal-rpt-col-type"><?= esc((string) ($row['type_name'] ?? '')) ?></td>
                    <td class="hr-leave-bal-rpt-col-num num" dir="ltr"><?= esc(hr_employee_leave_balances_report_fmt((float) ($row['annual_days'] ?? 0))) ?></td>
                    <td class="hr-leave-bal-rpt-col-num num" dir="ltr"><?= esc(hr_employee_leave_balances_report_fmt((float) ($row['opening_balance'] ?? 0))) ?></td>
                    <td class="hr-leave-bal-rpt-col-num num" dir="ltr"><?= esc(hr_employee_leave_balances_report_fmt((float) ($row['entitled_balance'] ?? 0))) ?></td>
                    <td class="hr-leave-bal-rpt-col-num num" dir="ltr"><?= esc(hr_employee_leave_balances_report_fmt((float) ($row['taken_days'] ?? 0))) ?></td>
                    <td class="hr-leave-bal-rpt-col-num num hr-leave-bal-rpt-col-remaining" dir="ltr"><?= esc(hr_employee_leave_balances_report_fmt((float) ($row['remaining'] ?? 0))) ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
        <?php if ($showFooter && $rows !== []): ?>
            <tfoot>
            <tr class="hr-leave-bal-rpt-row--total">
                <td class="hr-leave-bal-rpt-col-type hr-leave-bal-rpt-total-label"><?= esc($footerLabel) ?></td>
                <td class="hr-leave-bal-rpt-col-num num" dir="ltr"><?= esc(hr_employee_leave_balances_report_fmt($totals['annual_days'])) ?></td>
                <td class="hr-leave-bal-rpt-col-num num" dir="ltr"><?= esc(hr_employee_leave_balances_report_fmt($totals['opening_balance'])) ?></td>
                <td class="hr-leave-bal-rpt-col-num num" dir="ltr"><?= esc(hr_employee_leave_balances_report_fmt($totals['entitled_balance'])) ?></td>
                <td class="hr-leave-bal-rpt-col-num num" dir="ltr"><?= esc(hr_employee_leave_balances_report_fmt($totals['taken_days'])) ?></td>
                <td class="hr-leave-bal-rpt-col-num num hr-leave-bal-rpt-col-remaining" dir="ltr"><?= esc(hr_employee_leave_balances_report_fmt($totals['remaining'])) ?></td>
            </tr>
            </tfoot>
        <?php endif; ?>
    </table>
    <?php
}

/**
 * ترويسة الفلاتر (شاشة أو طباعة مضغوطة).
 */
function hr_employee_leave_balances_report_render_meta(
    string $dateFrom,
    string $dateTo,
    string $reportDate,
    string $deptLabel,
    string $typeLabel,
    string $empLabel,
    int $employeeCount
): void {
    ?>
    <div class="doc-print-meta">
        <table>
            <tr>
                <td>
                    <strong>الفترة:</strong>
                    <span dir="ltr"><?= esc(format_date_dmY($dateFrom)) ?></span>
                    —
                    <span dir="ltr"><?= esc(format_date_dmY($dateTo)) ?></span>
                    &nbsp;&nbsp;|&nbsp;&nbsp;
                    <strong>تاريخ التقرير:</strong>
                    <span dir="ltr"><?= esc(format_date_dmY($reportDate)) ?></span>
                </td>
            </tr>
            <tr>
                <td>
                    <strong>القسم:</strong> <?= esc($deptLabel) ?>
                    &nbsp;&nbsp;|&nbsp;&nbsp;
                    <strong>الموظف:</strong> <?= esc($empLabel) ?>
                    &nbsp;&nbsp;|&nbsp;&nbsp;
                    <strong>نوع الإجازة:</strong> <?= esc($typeLabel) ?>
                    <?php if ($employeeCount > 0): ?>
                        &nbsp;&nbsp;|&nbsp;&nbsp;
                        <strong>عدد الموظفين:</strong> <?= $employeeCount ?>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
    </div>
    <?php
}
