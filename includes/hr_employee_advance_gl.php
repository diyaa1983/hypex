<?php
declare(strict_types=1);

require_once app_path('includes/acc_gl.php');

const HR_EMPLOYEE_ADVANCE_GL_REF_TYPE = 'hr_employee_advance';
const HR_EMPLOYEE_ADVANCE_RECEIVABLE_RULE = 'hr_employee_advance_receivable';
const HR_EMPLOYEE_ADVANCE_PAYABLE_RULE = 'hr_employee_advance_payable';

function hr_employee_advance_gl_ensure_rule(PDO $pdo): void
{
    if (!acc_gl_has_posting_table($pdo)) {
        return;
    }

    try {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file_once($pdo, 'database/migrations/154_hr_employee_advance_posting.sql');
        sql_migration_run_file_once($pdo, 'database/migrations/164_hr_advance_disbursement.sql');
        sql_migration_run_file_once($pdo, 'database/migrations/165_hr_advance_disbursement_fix.sql');
    } catch (Throwable $e) {
        // ignored
    }
}

function hr_employee_advance_payable_account_id(PDO $pdo): int
{
    hr_employee_advance_gl_ensure_rule($pdo);
    if (!acc_gl_is_ready($pdo)) {
        return 0;
    }
    $settings = acc_gl_load_settings($pdo);

    return (int) ($settings[HR_EMPLOYEE_ADVANCE_PAYABLE_RULE]['account_id'] ?? 0);
}

/**
 * @param array<string, mixed> $advance
 * @return array{ok:bool, skipped:bool, error:?string}
 */
function hr_employee_advance_gl_post(PDO $pdo, int $advanceId, array $advance): array
{
    if ($advanceId < 1) {
        return ['ok' => false, 'skipped' => true, 'error' => 'مرجع السلفة غير صالح.'];
    }
    if (acc_gl_ref_exists($pdo, HR_EMPLOYEE_ADVANCE_GL_REF_TYPE, $advanceId)) {
        return ['ok' => true, 'skipped' => true, 'error' => null];
    }
    if (!acc_gl_is_ready($pdo)) {
        return ['ok' => true, 'skipped' => true, 'error' => null];
    }

    return acc_gl_wrap_post(static function () use ($pdo, $advanceId, $advance): void {
        $amount = round((float) ($advance['total_amount'] ?? 0), 3);
        if ($amount <= 0.0005) {
            throw new RuntimeException('مبلغ السلفة غير صالح للترحيل.');
        }

        $entryDate = (string) ($advance['start_date'] ?? '');
        if ($entryDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate)) {
            $entryDate = date('Y-m-d');
        }

        $code = trim((string) ($advance['advance_code'] ?? ''));
        if ($code === '') {
            $code = (string) $advanceId;
        }
        $empCode = trim((string) ($advance['emp_code'] ?? ''));
        $empName = trim((string) ($advance['name_ar'] ?? ''));
        $empLabel = $empName !== '' ? $empName : ($empCode !== '' ? $empCode : 'موظف');

        acc_gl_post_entry(
            $pdo,
            HR_EMPLOYEE_ADVANCE_GL_REF_TYPE,
            $advanceId,
            $entryDate,
            'ترحيل سلفة باسم ' . $empLabel . ' — رقم ' . $code . ' — ' . acc_gl_money_text($amount),
            [
                [
                    'rule' => HR_EMPLOYEE_ADVANCE_RECEIVABLE_RULE,
                    'debit' => $amount,
                    'credit' => 0,
                    'memo' => 'ذمة سلفة على الموظف',
                ],
                [
                    'rule' => HR_EMPLOYEE_ADVANCE_PAYABLE_RULE,
                    'debit' => 0,
                    'credit' => $amount,
                    'memo' => 'سلفة معتمدة — مستحقة الصرف من المحاسبة',
                ],
            ]
        );
    });
}

/** @return array{ok:bool, skipped:bool, error:?string} */
function hr_employee_advance_gl_unpost(PDO $pdo, int $advanceId): array
{
    if ($advanceId < 1) {
        return ['ok' => false, 'skipped' => true, 'error' => 'مرجع السلفة غير صالح.'];
    }

    return acc_gl_unpost_ref($pdo, HR_EMPLOYEE_ADVANCE_GL_REF_TYPE, $advanceId);
}

function hr_payroll_month_advance_deduction_total(PDO $pdo, int $year, int $month, bool $unpostedOnly = true): float
{
    if ($year < 2000 || $month < 1 || $month > 12) {
        return 0.0;
    }

    try {
        $sql = 'SELECT COALESCE(SUM(sad.amount), 0)
                FROM hr_salary_advance_deduction sad
                INNER JOIN hr_salary s ON s.id = sad.salary_id
                INNER JOIN hr_employee_advance a ON a.id = sad.advance_id
                WHERE s.pay_year = ? AND s.pay_month = ?';
        if (hr_employee_advance_post_columns_ready($pdo)) {
            $sql .= ' AND COALESCE(a.is_posted, 0) = 1';
        }
        if ($unpostedOnly) {
            $sql .= ' AND s.is_posted = 0';
        }
        $st = $pdo->prepare($sql);
        $st->execute([$year, $month]);

        return round((float) $st->fetchColumn(), 3);
    } catch (Throwable $e) {
        return 0.0;
    }
}
