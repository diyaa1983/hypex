<?php

declare(strict_types=1);



require_once app_path('includes/acc_gl.php');

require_once app_path('includes/hr_employee_advance_gl.php');

require_once app_path('includes/hr_employee_advance.php');



/** @return list<string> */

function fin_payment_party_types(): array

{

    return ['supplier', 'customer', 'employee', 'account'];

}



function fin_payment_normalize_party_type(string $partyType): string

{

    return in_array($partyType, fin_payment_party_types(), true) ? $partyType : 'supplier';

}



function fin_payment_party_type_label(string $partyType): string

{

    return match ($partyType) {

        'customer' => 'عميل',

        'employee' => 'موظف',

        'account' => 'حساب آخر',

        default => 'مورد',

    };

}



function fin_payment_normalize_employee_pay_kind(string $kind): string

{

    return $kind === 'other' ? 'other' : 'advance';

}



/** @return list<array{id:int, code:string, name_ar:string, label:string}> */

function fin_payment_employee_other_offset_accounts(PDO $pdo): array

{

    if (!acc_gl_is_ready($pdo)) {

        return [];

    }



    require_once app_path('includes/hr_payroll_gl.php');



    $settings = acc_gl_load_settings($pdo);

    $rules = [

        'salaries_payable' => 'رواتب مستحقة',

        'hr_social_insurance_payable' => 'ضمان اجتماعي مستحق',

        HR_PAYROLL_DEDUCTIONS_RULE_CODE => 'خصومات واقتطاعات موظفين',

    ];



    return fin_payment_accounts_from_posting_rules($pdo, $settings, $rules);

}



/** @return list<array{id:int, code:string, name_ar:string, label:string}> */

function fin_payment_employee_advance_payable_account(PDO $pdo): array

{

    if (!acc_gl_is_ready($pdo)) {

        return [];

    }



    hr_employee_advance_gl_ensure_rule($pdo);

    $settings = acc_gl_load_settings($pdo);



    return fin_payment_accounts_from_posting_rules($pdo, $settings, [

        HR_EMPLOYEE_ADVANCE_PAYABLE_RULE => 'سلف موظفين مستحقة الصرف',

    ]);

}



/**

 * @param array<string, array<string, mixed>> $settings

 * @param array<string, string> $rules

 * @return list<array{id:int, code:string, name_ar:string, label:string}>

 */

function fin_payment_accounts_from_posting_rules(PDO $pdo, array $settings, array $rules): array

{

    $out = [];

    $seen = [];

    foreach ($rules as $ruleCode => $label) {

        $aid = (int) ($settings[$ruleCode]['account_id'] ?? 0);

        if ($aid < 1 || isset($seen[$aid])) {

            continue;

        }

        $st = $pdo->prepare(

            'SELECT id, code, name_ar FROM acc_account WHERE id = ? AND is_active = 1 AND is_leaf = 1 LIMIT 1'

        );

        $st->execute([$aid]);

        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {

            continue;

        }

        $seen[$aid] = true;

        $out[] = [

            'id' => (int) $row['id'],

            'code' => (string) ($row['code'] ?? ''),

            'name_ar' => (string) ($row['name_ar'] ?? ''),

            'label' => $label,

        ];

    }



    return $out;

}



/** @deprecated use fin_payment_employee_other_offset_accounts */

function fin_payment_employee_offset_accounts(PDO $pdo): array

{

    return array_merge(

        fin_payment_employee_advance_payable_account($pdo),

        fin_payment_employee_other_offset_accounts($pdo)

    );

}



function fin_payment_employee_advance_payable_account_id(PDO $pdo): int

{

    $rows = fin_payment_employee_advance_payable_account($pdo);



    return (int) ($rows[0]['id'] ?? hr_employee_advance_payable_account_id($pdo));

}



/** @return list<array{id:int, code:string, name_ar:string, account_type:string}> */

function fin_payment_other_offset_accounts(PDO $pdo): array

{

    require_once app_path('includes/acc_journal.php');

    if (!acc_journal_has_tables($pdo)) {

        return [];

    }



    return $pdo->query(

        "SELECT id, code, name_ar, account_type

         FROM acc_account

         WHERE is_active = 1 AND is_leaf = 1

           AND account_type IN ('liability','expense')

         ORDER BY account_type ASC, code ASC, id ASC"

    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

}



function fin_payment_offset_account_allowed(PDO $pdo, string $partyType, int $accountId, string $employeePayKind = 'advance'): bool

{

    if ($accountId < 1) {

        return false;

    }

    if ($partyType === 'employee') {

        foreach (fin_payment_employee_offset_accounts($pdo) as $row) {

            if ((int) ($row['id'] ?? 0) === $accountId) {

                return true;

            }

        }



        return false;

    }

    if ($partyType === 'account') {

        foreach (fin_payment_other_offset_accounts($pdo) as $row) {

            if ((int) ($row['id'] ?? 0) === $accountId) {

                return true;

            }

        }



        return false;

    }



    return false;

}



function fin_payment_validate_employee_party(PDO $pdo, int $employeeId): ?string

{

    if ($employeeId < 1) {

        return 'اختر الموظف.';

    }

    require_once app_path('includes/hr_schema.php');

    hr_employee_ensure_schema($pdo);

    try {
        $st = $pdo->prepare('SELECT id FROM hr_employee WHERE id = ? AND is_active = 1 LIMIT 1');
        $st->execute([$employeeId]);
    } catch (Throwable $e) {
        return 'جدول الموظفين غير مهيأ.';
    }

    return $st->fetchColumn() ? null : 'الموظف غير موجود أو غير نشط.';

}



function fin_payment_employee_name(PDO $pdo, int $employeeId): string

{

    if ($employeeId < 1) {

        return '';

    }

    try {

        $st = $pdo->prepare(

            'SELECT name_ar, emp_code FROM hr_employee WHERE id = ? LIMIT 1'

        );

        $st->execute([$employeeId]);

        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {

            return '';

        }

        $name = trim((string) ($row['name_ar'] ?? ''));

        $code = trim((string) ($row['emp_code'] ?? ''));



        return $name !== '' ? ($code !== '' ? $name . ' (' . $code . ')' : $name) : $code;

    } catch (Throwable $e) {

        return '';

    }

}



function fin_payment_account_label(PDO $pdo, int $accountId): string

{

    if ($accountId < 1) {

        return '';

    }

    require_once app_path('includes/acc_account_tree.php');

    $row = acc_account_get($pdo, $accountId);

    if (!$row) {

        return '';

    }



    return trim((string) ($row['code'] ?? '')) . ' — ' . trim((string) ($row['name_ar'] ?? ''));

}



function fin_payment_employee_advances_pending(PDO $pdo, int $employeeId): array

{

    return hr_employee_advances_pending_disbursement($pdo, $employeeId);

}

function fin_payment_employee_advances_pending_all(PDO $pdo): array
{
    require_once app_path('includes/hr_employee_advance.php');

    return hr_employee_advances_pending_disbursement_all($pdo);
}

function fin_payment_disburse_advance_url(int $advanceId, int $cashAccountId = 0): string
{
    if ($advanceId < 1) {
        return app_url('index.php?r=cash_payment');
    }

    $url = app_url('index.php?r=cash_payment&disburse_advance=' . $advanceId);
    if ($cashAccountId > 0) {
        $url .= '&cash_account_id=' . $cashAccountId;
    }

    return $url;
}

/** @param list<array{id:int, code:string, name_ar:string, group_key?:string, group_label?:string}> $cashBankAccounts */
function fin_payment_cash_bank_account_valid(array $cashBankAccounts, int $accountId): bool
{
    if ($accountId < 1) {
        return false;
    }
    foreach ($cashBankAccounts as $acc) {
        if ((int) ($acc['id'] ?? 0) === $accountId) {
            return true;
        }
    }

    return false;
}

/** @return array<string, mixed>|null */
function fin_payment_disburse_advance_bootstrap(PDO $pdo, int $advanceId, int $cashAccountId = 0): ?array
{
    if ($advanceId < 1) {
        return null;
    }
    require_once app_path('includes/hr_employee_advance.php');
    hr_employee_advance_ensure_post_columns($pdo);

    $adv = hr_employee_advance_load($pdo, $advanceId);
    if (!$adv) {
        return null;
    }
    if ((int) ($adv['is_posted'] ?? 0) !== 1) {
        return null;
    }
    $status = trim((string) ($adv['status'] ?? 'active'));
    if ($status === 'cancelled') {
        return null;
    }
    if (hr_employee_advance_disbursement_columns_ready($pdo)) {
        $voucherId = (int) ($adv['disbursement_voucher_id'] ?? 0);
        if ($voucherId > 0) {
            return null;
        }
    }

    $employeeId = (int) ($adv['employee_id'] ?? 0);
    if ($employeeId < 1) {
        return null;
    }

    require_once app_path('includes/fin_voucher.php');
    if ($cashAccountId > 0) {
        $cashBank = fin_voucher_load_cash_bank_accounts($pdo);
        if (!fin_payment_cash_bank_account_valid($cashBank, $cashAccountId)) {
            $cashAccountId = 0;
        }
    }

    $payable = fin_payment_employee_advance_payable_account($pdo);
    $payableId = (int) ($payable[0]['id'] ?? 0);
    $payableLabel = $payableId > 0
        ? trim((string) ($payable[0]['code'] ?? '')) . ' — ' . trim((string) ($payable[0]['label'] ?? ''))
        : '';

    return [
        'advance_id' => $advanceId,
        'employee_id' => $employeeId,
        'emp_code' => trim((string) ($adv['emp_code'] ?? '')),
        'emp_name' => trim((string) ($adv['name_ar'] ?? '')),
        'advance_code' => trim((string) ($adv['advance_code'] ?? '')),
        'advance_type_label' => hr_employee_advance_type_label((string) ($adv['advance_type'] ?? '')),
        'amount' => round((float) ($adv['total_amount'] ?? 0), 3),
        'payable_account_id' => $payableId,
        'payable_account_label' => $payableLabel,
        'cash_account_id' => $cashAccountId > 0 ? $cashAccountId : 0,
        'lock_hr_fields' => true,
    ];
}

function fin_payment_advance_gl_memo(PDO $pdo, int $advanceId, int $employeeId = 0): string
{
    if ($advanceId < 1) {
        return '';
    }
    require_once app_path('includes/hr_employee_advance.php');
    $adv = hr_employee_advance_load($pdo, $advanceId);
    if (!$adv) {
        return '';
    }
    $code = trim((string) ($adv['advance_code'] ?? ''));
    if ($code === '') {
        $code = (string) $advanceId;
    }
    $empName = trim((string) ($adv['name_ar'] ?? ''));
    if ($empName === '' && $employeeId > 0) {
        $empName = fin_payment_employee_name($pdo, $employeeId);
    }
    $typeLabel = hr_employee_advance_type_label((string) ($adv['advance_type'] ?? ''));

    return 'سلفة رقم ' . $code
        . ($empName !== '' ? ' باسم الموظف ' . $empName : '')
        . ($typeLabel !== '' ? ' (' . $typeLabel . ')' : '');
}



function fin_payment_save_apply_hr_advance_id(PDO $pdo, int $voucherId, int $hrAdvanceId): void

{

    if ($voucherId < 1) {

        return;

    }

    require_once app_path('includes/fin_voucher_schema.php');

    if (!fin_voucher_has_column($pdo, 'hr_advance_id')) {

        return;

    }

    $pdo->prepare('UPDATE fin_voucher SET hr_advance_id = ? WHERE id = ?')->execute([

        $hrAdvanceId > 0 ? $hrAdvanceId : null,

        $voucherId,

    ]);

    require_once app_path('includes/hr_employee_advance.php');
    if ($hrAdvanceId > 0) {
        hr_employee_advance_assign_voucher($pdo, $hrAdvanceId, $voucherId);
    } else {
        hr_employee_advance_clear_disbursement_by_voucher($pdo, $voucherId);
    }

}

function fin_payment_salaries_payable_account_id(PDO $pdo): int
{
    if (!acc_gl_is_ready($pdo)) {
        return 0;
    }
    require_once app_path('includes/hr_payroll_gl.php');
    $settings = acc_gl_load_settings($pdo);

    return (int) ($settings['salaries_payable']['account_id'] ?? 0);
}

function fin_payment_employee_salaries_pending(PDO $pdo, int $employeeId): array
{
    require_once app_path('includes/hr_salary.php');

    return hr_salaries_pending_disbursement($pdo, $employeeId);
}

function fin_payment_save_apply_hr_salary_id(PDO $pdo, int $voucherId, int $hrSalaryId): void
{
    if ($voucherId < 1) {
        return;
    }
    require_once app_path('includes/fin_voucher_schema.php');
    if (!fin_voucher_has_column($pdo, 'hr_salary_id')) {
        return;
    }
    $pdo->prepare('UPDATE fin_voucher SET hr_salary_id = ? WHERE id = ?')->execute([
        $hrSalaryId > 0 ? $hrSalaryId : null,
        $voucherId,
    ]);
    require_once app_path('includes/hr_salary.php');
    if ($hrSalaryId > 0) {
        hr_salary_assign_voucher($pdo, $hrSalaryId, $voucherId);
    } else {
        hr_salary_clear_disbursement_by_voucher($pdo, $voucherId);
    }
}

function fin_payment_save_apply_employee_hr_links(
    PDO $pdo,
    int $voucherId,
    string $employeePayKind,
    int $hrAdvanceId,
    int $hrSalaryId
): void {
    if ($employeePayKind === 'advance' && $hrAdvanceId > 0) {
        fin_payment_save_apply_hr_advance_id($pdo, $voucherId, $hrAdvanceId);
        fin_payment_save_apply_hr_salary_id($pdo, $voucherId, 0);
        return;
    }
    if ($employeePayKind === 'other' && $hrSalaryId > 0) {
        fin_payment_save_apply_hr_salary_id($pdo, $voucherId, $hrSalaryId);
        fin_payment_save_apply_hr_advance_id($pdo, $voucherId, 0);
        return;
    }
    fin_payment_save_apply_hr_advance_id($pdo, $voucherId, 0);
    fin_payment_save_apply_hr_salary_id($pdo, $voucherId, 0);
}

