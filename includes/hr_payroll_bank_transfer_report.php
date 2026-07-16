<?php
declare(strict_types=1);

require_once app_path('includes/hr_payroll_posting.php');
require_once app_path('includes/hr_payroll_ss_report.php');
require_once app_path('includes/hr_schema.php');

/** @return list<array{id:int, bank_code:string, name_ar:string}> */
function hr_payroll_bank_transfer_report_bank_options(PDO $pdo): array
{
    hr_salary_bank_ensure_schema($pdo);
    try {
        $rows = $pdo->query(
            'SELECT id, bank_code, name_ar FROM hr_salary_bank
             ORDER BY name_ar ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $r): array {
            return [
                'id' => (int) ($r['id'] ?? 0),
                'bank_code' => (string) ($r['bank_code'] ?? ''),
                'name_ar' => (string) ($r['name_ar'] ?? ''),
            ];
        }, $rows);
    } catch (Throwable $e) {
        return [];
    }
}

function hr_payroll_bank_transfer_report_bank_label(int $bankId, array $banks): string
{
    if ($bankId === 0) {
        return 'جميع البنوك';
    }
    if ($bankId === -1) {
        return 'بدون بنك';
    }
    foreach ($banks as $b) {
        if ((int) ($b['id'] ?? 0) === $bankId) {
            $code = trim((string) ($b['bank_code'] ?? ''));
            $name = (string) ($b['name_ar'] ?? '');

            return $code !== '' ? $code . ' — ' . $name : $name;
        }
    }

    return '—';
}

/**
 * @return array{
 *   banks: list<array{
 *     bank_id:int,
 *     bank_code:string,
 *     bank_name:string,
 *     rows:list<array{seq:int, emp_code:string, name_ar:string, bank_account:string, net:float}>,
 *     totals: array{net:float, count:int}
 *   }>,
 *   grand: array{net:float, count:int},
 *   period_label: string,
 *   emp_count: int
 * }
 */
function hr_payroll_bank_transfer_report_build(
    PDO $pdo,
    int $year,
    int $month,
    int $bankId = 0,
    bool $requireAccount = false
): array {
    hr_payroll_validate_period($year, $month);
    hr_employee_ensure_schema($pdo);
    hr_salary_bank_ensure_schema($pdo);

    $periodLabel = hr_payroll_period_label($year, $month);
    $empty = [
        'banks' => [],
        'grand' => ['net' => 0.0, 'count' => 0],
        'period_label' => $periodLabel,
        'emp_count' => 0,
    ];

    if (!hr_payroll_ss_report_month_is_posted($pdo, $year, $month)) {
        return $empty;
    }

    $sql = 'SELECT s.net_salary,
                   e.emp_code, e.name_ar, e.bank_account, e.salary_bank_id, e.bank_name,
                   COALESCE(b.bank_code, \'\') AS bank_code,
                   COALESCE(b.name_ar, NULLIF(TRIM(e.bank_name), \'\'), ?) AS bank_name_resolved,
                   COALESCE(b.id, 0) AS bank_id_resolved
            FROM hr_salary s
            INNER JOIN hr_employee e ON e.id = s.employee_id
            LEFT JOIN hr_salary_bank b ON b.id = e.salary_bank_id
            WHERE s.pay_year = ? AND s.pay_month = ? AND s.is_posted = 1
              AND s.net_salary > 0';
    $params = ['— بدون بنك —', $year, $month];

    if ($bankId > 0) {
        $sql .= ' AND e.salary_bank_id = ?';
        $params[] = $bankId;
    } elseif ($bankId === -1) {
        $sql .= ' AND (e.salary_bank_id IS NULL OR e.salary_bank_id < 1)
                  AND (e.bank_name IS NULL OR TRIM(e.bank_name) = \'\')';
    }

    if ($requireAccount) {
        $sql .= ' AND e.bank_account IS NOT NULL AND TRIM(e.bank_account) <> \'\'';
    }

    $sql .= ' ORDER BY bank_name_resolved ASC, e.emp_code ASC, e.name_ar ASC, e.id ASC';

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

    $groups = [];
    foreach ($raw as $r) {
        $resolvedBankId = (int) ($r['bank_id_resolved'] ?? 0);
        $bankName = trim((string) ($r['bank_name_resolved'] ?? '— بدون بنك —'));
        $groupKey = $resolvedBankId > 0 ? 'id:' . $resolvedBankId : 'name:' . $bankName;

        if (!isset($groups[$groupKey])) {
            $groups[$groupKey] = [
                'bank_id' => $resolvedBankId,
                'bank_code' => trim((string) ($r['bank_code'] ?? '')),
                'bank_name' => $bankName,
                'rows' => [],
                'totals' => ['net' => 0.0, 'count' => 0],
            ];
        }

        $net = round((float) ($r['net_salary'] ?? 0), 3);
        $groups[$groupKey]['rows'][] = [
            'emp_code' => (string) ($r['emp_code'] ?? ''),
            'name_ar' => (string) ($r['name_ar'] ?? ''),
            'bank_account' => trim((string) ($r['bank_account'] ?? '')),
            'net' => $net,
        ];
        $groups[$groupKey]['totals']['net'] += $net;
        $groups[$groupKey]['totals']['count']++;
    }

    $banks = [];
    $grand = ['net' => 0.0, 'count' => 0];
    foreach ($groups as $g) {
        $g['totals']['net'] = round((float) $g['totals']['net'], 3);
        $seq = 1;
        foreach ($g['rows'] as &$row) {
            $row['seq'] = $seq++;
        }
        unset($row);
        $banks[] = $g;
        $grand['net'] += $g['totals']['net'];
        $grand['count'] += $g['totals']['count'];
    }

    $grand['net'] = round((float) $grand['net'], 3);

    return [
        'banks' => $banks,
        'grand' => $grand,
        'period_label' => $periodLabel,
        'emp_count' => (int) $grand['count'],
    ];
}

function hr_payroll_bank_transfer_report_account_display(string $account): string
{
    return $account !== '' ? $account : '—';
}
