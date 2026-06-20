<?php
declare(strict_types=1);

require_once app_path('includes/fin_voucher_schema.php');
require_once app_path('includes/fin_voucher_browse.php');

/** @return array<string, mixed>|null */
function fin_voucher_fetch_by_id(PDO $pdo, int $id, string $type): ?array
{
    $row = fin_voucher_load($pdo, $id, $type);
    if (!$row) {
        return null;
    }

    return fin_voucher_enrich_row($pdo, $row);
}

/** @return array<string, mixed>|null */
function fin_voucher_fetch_by_no(PDO $pdo, string $voucherNo, string $type): ?array
{
    $voucherNo = trim($voucherNo);
    if ($voucherNo === '') {
        return null;
    }
    $st = $pdo->prepare('SELECT id FROM fin_voucher WHERE voucher_no = ? AND voucher_type = ? LIMIT 1');
    $st->execute([$voucherNo, $type]);
    $id = $st->fetchColumn();

    return $id !== false ? fin_voucher_fetch_by_id($pdo, (int) $id, $type) : null;
}

/** @param array<string, mixed> $row */
function fin_voucher_enrich_row(PDO $pdo, array $row): array
{
    $id = (int) ($row['id'] ?? 0);
    $type = (string) ($row['voucher_type'] ?? '');
    $partyType = (string) ($row['party_type'] ?? 'other');
    $partyId = (int) ($row['party_id'] ?? 0);

    $row['is_posted'] = fin_voucher_is_posted($pdo, $id);
    $row['prev_id'] = fin_voucher_nav_neighbor_id($pdo, $id, $type, 'prev') ?? 0;
    $row['next_id'] = fin_voucher_nav_neighbor_id($pdo, $id, $type, 'next') ?? 0;
    $row['customer_name'] = '';
    $row['supplier_name'] = '';
    $row['party_name'] = '';
    $row['sales_rep_name'] = '';

    if ($partyType === 'customer' && $partyId > 0) {
        $st = $pdo->prepare(
            'SELECT c.name_ar, c.code, r.name_ar AS sales_rep_name
             FROM crm_customer c
             LEFT JOIN crm_sales_rep r ON r.id = c.sales_rep_id AND r.is_active = 1
             WHERE c.id = ? LIMIT 1'
        );
        $st->execute([$partyId]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if ($c) {
            $row['customer_name'] = (string) ($c['name_ar'] ?? '');
            $row['party_name'] = $row['customer_name'];
            $row['sales_rep_name'] = (string) ($c['sales_rep_name'] ?? '');
        }
    } elseif ($partyType === 'supplier' && $partyId > 0) {
        $st = $pdo->prepare('SELECT name_ar, code FROM crm_supplier WHERE id = ? LIMIT 1');
        $st->execute([$partyId]);
        $s = $st->fetch(PDO::FETCH_ASSOC);
        if ($s) {
            $row['supplier_name'] = (string) ($s['name_ar'] ?? '');
            $row['party_name'] = $row['supplier_name'];
        }
    } elseif ($partyType === 'employee' && $partyId > 0) {
        require_once app_path('includes/fin_payment_parties.php');
        $row['employee_name'] = fin_payment_employee_name($pdo, $partyId);
        $row['party_name'] = $row['employee_name'];
        $row['employee_id'] = $partyId;
    } elseif ($partyType === 'account') {
        require_once app_path('includes/fin_payment_parties.php');
        $offsetId = (int) ($row['offset_account_id'] ?? $partyId);
        $row['offset_account_id'] = $offsetId;
        $row['party_name'] = fin_payment_account_label($pdo, $offsetId);
    }

    $offsetId = (int) ($row['offset_account_id'] ?? 0);
    if ($offsetId > 0) {
        require_once app_path('includes/fin_payment_parties.php');
        $row['offset_account_label'] = fin_payment_account_label($pdo, $offsetId);
    } else {
        $row['offset_account_label'] = '';
    }
    $row['employee_id'] = $partyType === 'employee' ? $partyId : 0;

    $hrAdvanceId = (int) ($row['hr_advance_id'] ?? 0);
    $hrSalaryId = (int) ($row['hr_salary_id'] ?? 0);
    $row['hr_advance_id'] = $hrAdvanceId;
    $row['hr_salary_id'] = $hrSalaryId;
    if ($partyType === 'employee' && $hrSalaryId > 0) {
        $row['employee_pay_kind'] = 'other';
    } elseif ($partyType === 'employee' && $hrAdvanceId > 0) {
        $row['employee_pay_kind'] = 'advance';
    } else {
        $row['employee_pay_kind'] = 'other';
    }
    if ($hrAdvanceId > 0) {
        require_once app_path('includes/hr_employee_advance.php');
        $adv = hr_employee_advance_load($pdo, $hrAdvanceId);
        if ($adv) {
            $row['hr_advance_code'] = (string) ($adv['advance_code'] ?? '');
            $row['hr_advance_amount'] = round((float) ($adv['total_amount'] ?? 0), 3);
            $row['hr_advance_period'] = (string) ($adv['start_date'] ?? '');
        }
    }
    if ($hrSalaryId > 0) {
        require_once app_path('includes/hr_salary.php');
        try {
            $st = $pdo->prepare(
                'SELECT pay_year, pay_month, net_salary FROM hr_salary WHERE id = ? LIMIT 1'
            );
            $st->execute([$hrSalaryId]);
            $sal = $st->fetch(PDO::FETCH_ASSOC);
            if ($sal) {
                $row['hr_salary_period'] = hr_salary_period_label_ar(
                    (int) ($sal['pay_year'] ?? 0),
                    (int) ($sal['pay_month'] ?? 0)
                );
                $row['hr_salary_amount'] = round((float) ($sal['net_salary'] ?? 0), 3);
            }
        } catch (Throwable $e) {
            // ignored
        }
    }

    $row['pay_method'] = fin_voucher_normalize_pay_method((string) ($row['pay_method'] ?? 'cash'));

    return $row;
}
