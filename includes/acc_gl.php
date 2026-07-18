<?php
declare(strict_types=1);

require_once app_path('includes/acc_journal.php');

function acc_gl_has_posting_table(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $pdo->query('SELECT rule_code FROM acc_posting_setting LIMIT 1');
        $ok = true;
    } catch (Throwable $e) {
        $ok = false;
    }

    return $ok;
}

function acc_gl_journal_has_ref_columns(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $st = $pdo->prepare(
            'SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
        );
        $st->execute(['acc_journal_entry', 'ref_type']);
        $ok = (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        $ok = false;
    }

    return $ok;
}

function acc_gl_try_add_journal_columns(PDO $pdo): void
{
    if (acc_gl_journal_has_ref_columns($pdo)) {
        return;
    }
    $alters = [
        'ALTER TABLE acc_journal_entry ADD COLUMN ref_type VARCHAR(40) NULL',
        'ALTER TABLE acc_journal_entry ADD COLUMN ref_id INT UNSIGNED NULL',
        "ALTER TABLE acc_journal_entry ADD COLUMN source ENUM('manual','auto') NOT NULL DEFAULT 'manual'",
    ];
    foreach ($alters as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            // عمود موجود
        }
    }
}

function acc_gl_ensure_schema(PDO $pdo): bool
{
    static $done = false;
    if ($done) {
        return acc_gl_has_posting_table($pdo) && acc_gl_journal_has_ref_columns($pdo);
    }

    if (!acc_journal_ensure_schema($pdo)) {
        return false;
    }
    if (!acc_gl_has_posting_table($pdo) || !acc_gl_journal_has_ref_columns($pdo)) {
        require_once app_path('includes/sql_migration.php');
        try {
            sql_migration_run_file($pdo, 'database/migrations/032_acc_gl_posting.sql');
        } catch (Throwable $e) {
            // قد تفشل أعمدة مكررة
        }
        acc_gl_try_add_journal_columns($pdo);
        if (!acc_gl_has_posting_table($pdo)) {
            try {
                $pdo->exec(
                    'CREATE TABLE IF NOT EXISTS acc_posting_setting (
                      rule_code VARCHAR(40) NOT NULL PRIMARY KEY,
                      label_ar VARCHAR(200) NOT NULL,
                      hint_ar VARCHAR(500) NULL,
                      account_id INT UNSIGNED NULL,
                      sort_order INT NOT NULL DEFAULT 0
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
                );
            } catch (Throwable $e) {
            }
        }
    }

    if (acc_gl_has_posting_table($pdo)) {
        require_once app_path('includes/hr_social_security_payroll.php');
        hr_ss_ensure_posting_rule($pdo);
        require_once app_path('includes/hr_payroll_gl.php');
        hr_payroll_gl_ensure_posting_rules($pdo);
        require_once app_path('includes/acc_coa_bootstrap.php');
        acc_coa_bootstrap_ensure_posting_rules($pdo);
        acc_coa_ensure_outgoing_deferred_checks_account($pdo);
    }

    $done = acc_gl_has_posting_table($pdo) && acc_gl_journal_has_ref_columns($pdo);

    return $done;
}

/** @return array<string, array{label_ar:string, hint_ar:?string, account_id:?int, sort_order:int}> */
function acc_gl_load_settings(PDO $pdo): array
{
    if (!acc_gl_has_posting_table($pdo)) {
        return [];
    }
    $rows = $pdo->query(
        'SELECT rule_code, label_ar, hint_ar, account_id, sort_order
         FROM acc_posting_setting ORDER BY sort_order ASC, rule_code ASC'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $map = [];
    foreach ($rows as $row) {
        $code = (string) $row['rule_code'];
        $map[$code] = [
            'label_ar' => (string) $row['label_ar'],
            'hint_ar' => $row['hint_ar'] !== null ? (string) $row['hint_ar'] : null,
            'account_id' => $row['account_id'] !== null ? (int) $row['account_id'] : null,
            'sort_order' => (int) $row['sort_order'],
        ];
    }

    // تصحيح تلقائي للحالات القديمة: عند ربط القاعدة بحساب قصير (مثل 12/13/15/22/112)
    // نعيدها إلى الحساب الهرمي المعتمد لمنع الرجوع بعد أي تنظيف/ترقيم.
    $preferredCodes = [
        'ar_customers' => '1001005',
        'ap_suppliers' => '2002',
        'inventory' => '1001007',
        'vat_input' => '3001002',
        'vat_output' => '3001002',
        'bank' => '1001003004',
        'cash' => '1001002001',
        'hr_income_tax' => '2007',
    ];
    $stById = $pdo->prepare('SELECT id, code, is_active, is_leaf FROM acc_account WHERE id = ? LIMIT 1');
    $stByCode = $pdo->prepare('SELECT id FROM acc_account WHERE code = ? AND is_active = 1 AND is_leaf = 1 LIMIT 1');
    $stUpd = $pdo->prepare('UPDATE acc_posting_setting SET account_id = ? WHERE rule_code = ?');
    foreach ($preferredCodes as $rule => $preferredCode) {
        $currentId = (int) ($map[$rule]['account_id'] ?? 0);
        $needsFix = $currentId < 1;
        if ($currentId > 0) {
            $stById->execute([$currentId]);
            $acc = $stById->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$acc) {
                $needsFix = true;
            } else {
                $digits = preg_replace('/\D/', '', (string) ($acc['code'] ?? '')) ?? '';
                $needsFix = (int) ($acc['is_active'] ?? 0) !== 1
                    || (int) ($acc['is_leaf'] ?? 0) !== 1
                    || strlen($digits) <= 2;
            }
        }
        if (!$needsFix) {
            continue;
        }
        $stByCode->execute([$preferredCode]);
        $newId = (int) $stByCode->fetchColumn();
        if ($newId > 0) {
            $stUpd->execute([$newId, $rule]);
            if (isset($map[$rule])) {
                $map[$rule]['account_id'] = $newId;
            }
        }
    }

    return $map;
}

/** قواعد ترحيل داخلية — لا تظهر في شاشة «ربط الحسابات». */
function acc_gl_hidden_posting_rules(): array
{
    return [
        'hr_social_insurance_employee',
        'hr_social_insurance_employer',
        'salaries_expense',
        'hr_payroll_accrual',
    ];
}

function acc_gl_account_id(array $settings, string $ruleCode): int
{
    $id = (int) ($settings[$ruleCode]['account_id'] ?? 0);
    if ($id < 1) {
        $label = (string) ($settings[$ruleCode]['label_ar'] ?? $ruleCode);
        throw new RuntimeException('لم يُربط حساب: ' . $label . ' — من شاشة «ربط الحسابات المحاسبية».');
    }

    return $id;
}

function acc_gl_is_ready(PDO $pdo): bool
{
    if (!acc_gl_ensure_schema($pdo)) {
        return false;
    }
    $required = ['ar_customers', 'ap_suppliers', 'cash', 'sales_revenue'];
    $settings = acc_gl_load_settings($pdo);
    foreach ($required as $code) {
        if ((int) ($settings[$code]['account_id'] ?? 0) < 1) {
            return false;
        }
    }
    $hasInventory = (int) ($settings['inventory']['account_id'] ?? 0) > 0;
    $hasPurchases = (int) ($settings['purchases']['account_id'] ?? 0) > 0;
    if (!$hasInventory && !$hasPurchases) {
        return false;
    }

    return true;
}

function acc_gl_ref_exists(PDO $pdo, string $refType, int $refId): bool
{
    if ($refId < 1 || !acc_gl_journal_has_ref_columns($pdo)) {
        return false;
    }
    $st = $pdo->prepare(
        "SELECT id FROM acc_journal_entry WHERE ref_type = ? AND ref_id = ? AND source = 'auto' LIMIT 1"
    );
    $st->execute([$refType, $refId]);

    return (bool) $st->fetchColumn();
}

/** حذف قيد تلقائي مع تنظيف دفتر الذمم المرتبط بأسطر سند القيد. */
function acc_gl_delete_auto_journal(PDO $pdo, int $journalId): void
{
    if ($journalId < 1) {
        return;
    }

    require_once app_path('includes/crm_customer_ledger.php');
    require_once app_path('includes/crm_supplier_ledger.php');
    crm_ledger_delete_journal_voucher_by_journal($pdo, $journalId);
    crm_supplier_ledger_delete_journal_voucher_by_journal($pdo, $journalId);

    require_once app_path('includes/acc_journal_party.php');
    acc_journal_party_ledger_sync($pdo, $journalId, false);

    $pdo->prepare('DELETE FROM acc_journal_line WHERE journal_id = ?')->execute([$journalId]);
    $pdo->prepare('DELETE FROM acc_journal_entry WHERE id = ?')->execute([$journalId]);
}

/**
 * حذف قيد الترحيل التلقائي المرتبط بمستند (عكس الترحيل المحاسبي).
 *
 * @return array{ok:bool, skipped:bool, error:?string}
 */
function acc_gl_unpost_ref(PDO $pdo, string $refType, int $refId): array
{
    $out = ['ok' => true, 'skipped' => true, 'error' => null];
    if ($refId < 1 || !acc_gl_journal_has_ref_columns($pdo)) {
        return $out;
    }

    try {
        $st = $pdo->prepare(
            "SELECT id FROM acc_journal_entry WHERE ref_type = ? AND ref_id = ? AND source = 'auto' LIMIT 1"
        );
        $st->execute([$refType, $refId]);
        $journalId = (int) $st->fetchColumn();
        if ($journalId < 1) {
            return $out;
        }

        acc_gl_delete_auto_journal($pdo, $journalId);
        $out['skipped'] = false;
    } catch (Throwable $e) {
        $out['ok'] = false;
        $out['error'] = 'تعذر إلغاء الترحيل المحاسبي.';
    }

    return $out;
}

function acc_gl_next_auto_entry_no(PDO $pdo, string $refType, int $refId): string
{
    $prefix = 'GL-' . strtoupper(preg_replace('/[^a-z0-9_]/', '', $refType) ?? 'X') . '-';

    return $prefix . $refId;
}

function acc_gl_money_text(float $amount): string
{
    $amount = round(max(0, $amount), 6);
    if (function_exists('format_money')) {
        return format_money($amount) . ' دينار';
    }

    return number_format($amount, 2, '.', ',') . ' دينار';
}

function acc_gl_party_name(PDO $pdo, string $partyType, int $partyId): string
{
    if ($partyId < 1) {
        return '';
    }
    if ($partyType === 'customer') {
        $st = $pdo->prepare('SELECT name_ar FROM crm_customer WHERE id = ? LIMIT 1');
    } elseif ($partyType === 'supplier') {
        $st = $pdo->prepare('SELECT name_ar FROM crm_supplier WHERE id = ? LIMIT 1');
    } elseif ($partyType === 'employee') {
        require_once app_path('includes/fin_payment_parties.php');

        return fin_payment_employee_name($pdo, $partyId);
    } elseif ($partyType === 'account') {
        require_once app_path('includes/fin_payment_parties.php');

        return fin_payment_account_label($pdo, $partyId);
    } else {
        return '';
    }
    $st->execute([$partyId]);
    $name = $st->fetchColumn();

    return $name !== false ? trim((string) $name) : '';
}

/** تسمية طريقة الدفع في بيان القيد: نقد / شيك / بنك / آجل. */
function acc_gl_pay_method_label(string $method): string
{
    $method = strtolower(trim($method));
    if ($method === 'credit') {
        return 'آجل';
    }
    require_once app_path('includes/fin_voucher.php');

    return fin_voucher_pay_method_label($method);
}

/** @return array{memo:string}|null */
function acc_gl_payment_hr_advance_context(PDO $pdo, array $row): ?array
{
    if ((string) ($row['party_type'] ?? '') !== 'employee') {
        return null;
    }
    require_once app_path('includes/fin_voucher_schema.php');
    if (!fin_voucher_has_column($pdo, 'hr_advance_id')) {
        return null;
    }
    $advanceId = (int) ($row['hr_advance_id'] ?? 0);
    if ($advanceId < 1) {
        return null;
    }
    require_once app_path('includes/fin_payment_parties.php');
    $memo = fin_payment_advance_gl_memo($pdo, $advanceId, (int) ($row['party_id'] ?? 0));
    if ($memo === '') {
        return null;
    }

    return ['memo' => $memo];
}

/**
 * @return array{rule?:string, account_id?:int, debit:float, credit:float, memo:string}
 */
function acc_gl_payment_employee_debit_line(PDO $pdo, array $row, float $amount, string $memo): array
{
    $line = ['debit' => $amount, 'credit' => 0.0, 'memo' => $memo];
    $offsetId = (int) ($row['offset_account_id'] ?? 0);
    if ($offsetId > 0) {
        $line['account_id'] = $offsetId;

        return $line;
    }
    require_once app_path('includes/fin_voucher_schema.php');
    $advanceId = 0;
    if (fin_voucher_has_column($pdo, 'hr_advance_id')) {
        $advanceId = (int) ($row['hr_advance_id'] ?? 0);
    }
    if ($advanceId > 0) {
        $line['rule'] = 'hr_employee_advance_payable';

        return $line;
    }
    $settings = acc_gl_load_settings($pdo);
    if (acc_gl_account_id($settings, 'salaries_payable') > 0) {
        $line['rule'] = 'salaries_payable';

        return $line;
    }
    $line['rule'] = 'misc_expense';

    return $line;
}

function acc_gl_voucher_description(PDO $pdo, string $kind, array $row, float $amount): string
{
    $voucherNo = trim((string) ($row['voucher_no'] ?? ''));
    $payMethod = (string) ($row['pay_method'] ?? 'cash');
    $isCheck = $payMethod === 'check';
    $checkNo = trim((string) ($row['check_no'] ?? ''));
    $partyType = (string) ($row['party_type'] ?? 'other');
    $partyId = (int) ($row['party_id'] ?? 0);
    $partyName = acc_gl_party_name($pdo, $partyType, $partyId);

    $parts = [];
    $parts[] = $kind === 'receipt' ? 'سند قبض' : 'سند صرف';
    if ($voucherNo !== '') {
        $parts[] = $voucherNo;
    }
    $parts[] = acc_gl_pay_method_label($payMethod);
    $parts[] = acc_gl_money_text($amount);
    if ($partyName !== '') {
        if ($kind === 'receipt') {
            $parts[] = 'من ' . $partyName;
        } elseif ($partyType === 'supplier') {
            $parts[] = 'إلى المورد ' . $partyName;
        } elseif ($partyType === 'customer') {
            $parts[] = 'إلى العميل ' . $partyName;
        } elseif ($partyType === 'employee') {
            $parts[] = 'إلى الموظف ' . $partyName;
        } elseif ($partyType === 'account') {
            $parts[] = 'إلى حساب ' . $partyName;
        } else {
            $parts[] = 'إلى ' . $partyName;
        }
    }
    if ($isCheck && $checkNo !== '') {
        $parts[] = 'رقم الشيك ' . $checkNo;
    }

    if ($kind === 'payment') {
        $advCtx = acc_gl_payment_hr_advance_context($pdo, $row);
        if ($advCtx !== null && ($advCtx['memo'] ?? '') !== '') {
            $advParts = ['صرف سلفة', $advCtx['memo'], acc_gl_pay_method_label($payMethod), acc_gl_money_text($amount)];
            if ($voucherNo !== '') {
                array_splice($advParts, 1, 0, ['سند ' . $voucherNo]);
            }
            if ($isCheck && $checkNo !== '') {
                $advParts[] = 'رقم الشيك ' . $checkNo;
            }

            return trim(implode(' — ', array_filter($advParts, static fn($v): bool => trim((string) $v) !== '')));
        }
    }

    return trim(implode(' - ', array_filter($parts, static fn($v): bool => trim((string) $v) !== '')));
}

/**
 * @param list<array{rule?:string, account_id?:int, debit:float, credit:float, memo?:string}> $lines
 */
function acc_gl_post_entry(
    PDO $pdo,
    string $refType,
    int $refId,
    string $entryDate,
    string $description,
    array $lines
): int {
    if (!acc_gl_ensure_schema($pdo)) {
        throw new RuntimeException('نظام الربط المحاسبي غير مهيأ.');
    }
    if ($refId < 1) {
        throw new RuntimeException('مرجع الترحيل غير صالح.');
    }
    if (acc_gl_ref_exists($pdo, $refType, $refId)) {
        $st = $pdo->prepare(
            "SELECT id FROM acc_journal_entry WHERE ref_type = ? AND ref_id = ? AND source = 'auto' LIMIT 1"
        );
        $st->execute([$refType, $refId]);

        return (int) $st->fetchColumn();
    }

    $settings = acc_gl_load_settings($pdo);
    $resolved = [];
    foreach ($lines as $ln) {
        $debit = round(max(0, (float) ($ln['debit'] ?? 0)), 6);
        $credit = round(max(0, (float) ($ln['credit'] ?? 0)), 6);
        if ($debit <= 0 && $credit <= 0) {
            continue;
        }
        $accountId = (int) ($ln['account_id'] ?? 0);
        if ($accountId < 1 && !empty($ln['rule'])) {
            $accountId = acc_gl_account_id($settings, (string) $ln['rule']);
        }
        if ($accountId < 1) {
            continue;
        }
        $resolved[] = [
            'account_id' => $accountId,
            'debit' => $debit,
            'credit' => $credit,
            'memo' => trim((string) ($ln['memo'] ?? '')),
        ];
    }

    $normalized = acc_journal_normalize_lines($resolved);
    $entryNo = acc_gl_next_auto_entry_no($pdo, $refType, $refId);
    $uid = (int) (current_user()['id'] ?? 0) ?: null;

    $noSt = $pdo->prepare('SELECT id, ref_type, ref_id FROM acc_journal_entry WHERE entry_no = ? LIMIT 1');
    $noSt->execute([$entryNo]);
    $noRow = $noSt->fetch(PDO::FETCH_ASSOC);
    if ($noRow) {
        $sameRef = (string) ($noRow['ref_type'] ?? '') === $refType
            && (int) ($noRow['ref_id'] ?? 0) === $refId;
        if ($sameRef) {
            return (int) ($noRow['id'] ?? 0);
        }

        throw new RuntimeException(
            'رقم القيد «' . $entryNo . '» مستخدم لقيد آخر — احذف القيد العالق من «قيود اليومية» أو تواصل مع الدعم.'
        );
    }

    $pdo->prepare(
        "INSERT INTO acc_journal_entry (entry_no, entry_date, description_ar, status, ref_type, ref_id, source, created_by)
         VALUES (?,?,?,'posted',?,?,'auto',?)"
    )->execute([
        $entryNo,
        $entryDate,
        $description !== '' ? $description : null,
        $refType,
        $refId,
        $uid,
    ]);
    $journalId = (int) $pdo->lastInsertId();
    acc_journal_replace_lines($pdo, $journalId, $normalized['lines']);

    return $journalId;
}

/**
 * @return array{ok:bool, skipped:bool, error:?string}
 */
function acc_gl_wrap_post(callable $fn): array
{
    $out = ['ok' => true, 'skipped' => true, 'error' => null];
    try {
        $fn();
        $out['skipped'] = false;
    } catch (RuntimeException $e) {
        $out['ok'] = false;
        $out['error'] = $e->getMessage();
    } catch (Throwable $e) {
        $out['ok'] = false;
        $out['error'] = 'تعذر الترحيل المحاسبي.';
    }

    return $out;
}

function acc_gl_cash_rule_for_voucher(array $row): string
{
    $payMethod = (string) ($row['pay_method'] ?? 'cash');
    if ($payMethod === 'check') {
        return 'bank';
    }
    if ($payMethod === 'bank') {
        return 'bank';
    }

    return 'cash';
}

/** التحقق من حساب نهائي نشط في الشجرة. */
function acc_gl_is_valid_leaf_account(PDO $pdo, int $accountId): bool
{
    if ($accountId < 1) {
        return false;
    }
    $st = $pdo->prepare(
        'SELECT id FROM acc_account WHERE id = ? AND is_active = 1 AND is_leaf = 1 LIMIT 1'
    );
    $st->execute([$accountId]);

    return $st->fetchColumn() !== false;
}

/**
 * سطر القيد لحساب الصندوق/البنك: يفضّل الحساب المختار في السند، وإلا قاعدة الربط (cash/bank).
 *
 * @return array{rule?:string, account_id?:int, debit:float, credit:float, memo?:string}
 */
function acc_gl_cash_line_for_voucher(PDO $pdo, array $row, float $debit, float $credit, string $memo = ''): array
{
    $cashAccountId = (int) ($row['cash_account_id'] ?? 0);
    if ($cashAccountId > 0 && acc_gl_is_valid_leaf_account($pdo, $cashAccountId)) {
        return [
            'account_id' => $cashAccountId,
            'debit' => $debit,
            'credit' => $credit,
            'memo' => $memo,
        ];
    }

    $settings = acc_gl_load_settings($pdo);
    $cashRule = acc_gl_cash_rule_for_voucher($row);
    if ($cashRule === 'bank' && (int) ($settings['bank']['account_id'] ?? 0) < 1) {
        $cashRule = 'cash';
    }

    return [
        'rule' => $cashRule,
        'debit' => $debit,
        'credit' => $credit,
        'memo' => $memo,
    ];
}

/** حساب «صندوق الشيكات» لسندات قبض الشيك (أولاً من ربط الحسابات، ثم Bootstrap). */
function acc_gl_checks_fund_account_id(PDO $pdo): int
{
    $settings = acc_gl_load_settings($pdo);
    $mapped = (int) ($settings['checks_fund']['account_id'] ?? 0);
    if ($mapped > 0 && acc_gl_is_valid_leaf_account($pdo, $mapped)) {
        return $mapped;
    }

    require_once app_path('includes/acc_coa_bootstrap.php');
    $ensured = acc_coa_ensure_checks_fund_account($pdo);
    $accId = (int) ($ensured['account_id'] ?? 0);
    if ($accId > 0) {
        try {
            $st = $pdo->prepare('UPDATE acc_posting_setting SET account_id = ? WHERE rule_code = ?');
            $st->execute([$accId, 'checks_fund']);
        } catch (Throwable $e) {
            // إذا لم يكن السجل موجوداً سيتم إنشاؤه عند bootstrap التالي
        }
    }

    return $accId;
}

/**
 * حساب «الشيكات الآجلة» الصادرة — دائن عند ترحيل سند صرف شيك، ومدين عند الصرف من البنك.
 */
function acc_gl_outgoing_deferred_checks_account_id(PDO $pdo): int
{
    $settings = acc_gl_load_settings($pdo);
    $mapped = (int) ($settings['outgoing_deferred_checks']['account_id'] ?? 0);
    if ($mapped > 0 && acc_gl_is_valid_leaf_account($pdo, $mapped)) {
        return $mapped;
    }

    require_once app_path('includes/acc_coa_bootstrap.php');
    $ensured = acc_coa_ensure_outgoing_deferred_checks_account($pdo);
    $accId = (int) ($ensured['account_id'] ?? 0);
    if ($accId > 0) {
        try {
            $st = $pdo->prepare('UPDATE acc_posting_setting SET account_id = ? WHERE rule_code = ?');
            $st->execute([$accId, 'outgoing_deferred_checks']);
        } catch (Throwable $e) {
            // ignore
        }
    }

    return $accId;
}

/** هل قيد سند الصرف دائن على حساب الشيكات الآجلة (التدفق الجديد)؟ */
function acc_gl_cash_payment_credits_outgoing_deferred(PDO $pdo, int $voucherId): bool
{
    if ($voucherId < 1 || !acc_gl_journal_has_ref_columns($pdo)) {
        return false;
    }
    $deferredId = acc_gl_outgoing_deferred_checks_account_id($pdo);
    if ($deferredId < 1) {
        return false;
    }
    try {
        $st = $pdo->prepare(
            "SELECT 1
             FROM acc_journal_entry e
             INNER JOIN acc_journal_line l ON l.journal_id = e.id
             WHERE e.ref_type = 'cash_payment' AND e.ref_id = ? AND e.status = 'posted'
               AND l.account_id = ? AND l.credit > 0.000001
             LIMIT 1"
        );
        $st->execute([$voucherId, $deferredId]);

        return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * يعيد معرّف حساب «الصندوق» المعتمد من إعدادات الترحيل (acc_posting_setting.rule_code='cash').
 * وفي حال غيابه يبحث عن حساب بالكود 111 ثم يحاول الاستدلال باسم «صندوق/نقد».
 */
function acc_gl_cash_box_account_id(PDO $pdo): int
{
    $settings = acc_gl_load_settings($pdo);
    $mapped = (int) ($settings['cash']['account_id'] ?? 0);
    if ($mapped > 0 && acc_gl_is_valid_leaf_account($pdo, $mapped)) {
        return $mapped;
    }

    require_once app_path('includes/acc_coa_bootstrap.php');
    $id = acc_coa_find_global_cash_box_id($pdo);
    if ($id > 0) {
        try {
            $u = $pdo->prepare('UPDATE acc_posting_setting SET account_id = ? WHERE rule_code = ?');
            $u->execute([$id, 'cash']);
        } catch (Throwable $e) {
            // ignore
        }

        return $id;
    }

    return 0;
}

/**
 * حساب إيداع سند القبض «بنك» — دائماً 1001003004 (وليس بنك صفوة أو ربط bank العام).
 */
function acc_gl_receipt_bank_deposit_account_id(PDO $pdo): int
{
    require_once app_path('includes/acc_account_reassign.php');

    static $cachedId = null;
    if ($cachedId !== null) {
        return $cachedId;
    }

    $byCode = acc_account_get_by_code($pdo, '1001003004');
    if ($byCode && (int) ($byCode['is_leaf'] ?? 0) === 1 && (int) ($byCode['is_active'] ?? 0) === 1) {
        $cachedId = (int) ($byCode['id'] ?? 0);

        return $cachedId;
    }

    try {
        $st = $pdo->query(
            "SELECT id FROM acc_account
             WHERE is_active = 1 AND is_leaf = 1
               AND (code = '1001003004' OR REPLACE(code, '.', '') = '1001003004')
             ORDER BY id ASC
             LIMIT 1"
        );
        $id = $st ? (int) $st->fetchColumn() : 0;
        if ($id > 0) {
            $cachedId = $id;

            return $cachedId;
        }
    } catch (Throwable $e) {
        // ignore
    }

    $cachedId = 0;

    return 0;
}

/**
 * حساب البنك العام من الربط (قد يكون بنك صفوة u.a.) — لا يُستخدم لسند قبض «بنك».
 */
function acc_gl_bank_account_id(PDO $pdo): int
{
    require_once app_path('includes/acc_account_reassign.php');

    $depositId = acc_gl_receipt_bank_deposit_account_id($pdo);
    if ($depositId > 0) {
        return $depositId;
    }

    $settings = acc_gl_load_settings($pdo);
    $mapped = (int) ($settings['bank']['account_id'] ?? 0);
    if ($mapped > 0 && acc_gl_is_valid_leaf_account($pdo, $mapped)) {
        return $mapped;
    }

    $byCode = acc_account_get_by_code($pdo, '1001003004');
    if ($byCode && (int) ($byCode['is_leaf'] ?? 0) === 1 && (int) ($byCode['is_active'] ?? 0) === 1) {
        $id = (int) ($byCode['id'] ?? 0);
        if ($id > 0) {
            try {
                $u = $pdo->prepare('UPDATE acc_posting_setting SET account_id = ? WHERE rule_code = ?');
                $u->execute([$id, 'bank']);
            } catch (Throwable $e) {
                // ignore
            }

            return $id;
        }
    }

    require_once app_path('includes/acc_coa_bootstrap.php');
    $id = acc_coa_find_global_bank_id($pdo);
    if ($id > 0) {
        return $id;
    }

    return 0;
}

/**
 * سطر الصندوق لسند القبض:
 * - الشيك → صندوق الشيكات دائماً.
 * - البنك → حساب 1001003004 دائماً (لا يُستخدم بنك صفوة).
 * - النقد → الصندوق الرئيسي دائماً (حساب القاعدة cash) بصرف النظر عن cash_account_id المحفوظ.
 *
 * @return array{rule?:string, account_id?:int, debit:float, credit:float, memo?:string}
 */
function acc_gl_cash_line_for_receipt(PDO $pdo, array $row, float $debit, float $credit, string $memo = ''): array
{
    $payMethod = (string) ($row['pay_method'] ?? 'cash');
    if ($payMethod === 'check') {
        $checksId = acc_gl_checks_fund_account_id($pdo);
        if ($checksId > 0 && acc_gl_is_valid_leaf_account($pdo, $checksId)) {
            return [
                'account_id' => $checksId,
                'debit' => $debit,
                'credit' => $credit,
                'memo' => $memo,
            ];
        }

        return [
            'rule' => 'bank',
            'debit' => $debit,
            'credit' => $credit,
            'memo' => $memo,
        ];
    }

    if ($payMethod === 'bank') {
        $bankId = acc_gl_receipt_bank_deposit_account_id($pdo);
        if ($bankId > 0 && acc_gl_is_valid_leaf_account($pdo, $bankId)) {
            return [
                'account_id' => $bankId,
                'debit' => $debit,
                'credit' => $credit,
                'memo' => $memo,
            ];
        }

        throw new RuntimeException(
            'حساب إيداع البنك (1001003004) غير موجود أو غير نشط — أنشئ الحساب في الشجرة ثم أعد الترحيل.'
        );
    }

    $cashBoxId = acc_gl_cash_box_account_id($pdo);
    if ($cashBoxId > 0 && acc_gl_is_valid_leaf_account($pdo, $cashBoxId)) {
        return [
            'account_id' => $cashBoxId,
            'debit' => $debit,
            'credit' => $credit,
            'memo' => $memo,
        ];
    }

    return [
        'rule' => 'cash',
        'debit' => $debit,
        'credit' => $credit,
        'memo' => $memo,
    ];
}

/** @return array{ok:bool, skipped:bool, error:?string} */
function acc_gl_post_sale_invoice(PDO $pdo, int $invoiceId): array
{
    if ($invoiceId < 1 || acc_gl_ref_exists($pdo, 'sale_invoice', $invoiceId)) {
        return ['ok' => true, 'skipped' => true, 'error' => null];
    }
    if (!acc_gl_is_ready($pdo)) {
        return ['ok' => true, 'skipped' => true, 'error' => null];
    }

    return acc_gl_wrap_post(static function () use ($pdo, $invoiceId): void {
        $st = $pdo->prepare(
            'SELECT i.invoice_no, i.invoice_date, i.payment_type, i.subtotal, i.tax_amount, i.total,
                    COALESCE(c.name_ar, "") AS customer_name
             FROM sal_invoice i
             LEFT JOIN crm_customer c ON c.id = i.customer_id
             WHERE i.id = ? LIMIT 1'
        );
        $st->execute([$invoiceId]);
        $inv = $st->fetch(PDO::FETCH_ASSOC);
        if (!$inv) {
            throw new RuntimeException('فاتورة البيع غير موجودة.');
        }
        $sub = round((float) $inv['subtotal'], 6);
        $tax = round((float) $inv['tax_amount'], 6);
        $total = round((float) $inv['total'], 6);
        if ($total <= 0) {
            return;
        }
        $pay = (string) ($inv['payment_type'] ?? '') === 'cash' ? 'cash' : 'credit';
        $lines = [];
        if ($pay === 'credit') {
            $lines[] = ['rule' => 'ar_customers', 'debit' => $total, 'credit' => 0];
        } else {
            $lines[] = ['rule' => 'cash', 'debit' => $total, 'credit' => 0];
        }
        if ($sub > 0) {
            $lines[] = ['rule' => 'sales_revenue', 'debit' => 0, 'credit' => $sub];
        }
        $settings = acc_gl_load_settings($pdo);
        if ($tax > 0) {
            if ((int) ($settings['vat_output']['account_id'] ?? 0) > 0) {
                $lines[] = ['rule' => 'vat_output', 'debit' => 0, 'credit' => $tax];
            } elseif ($sub > 0) {
                $lines[count($lines) - 1]['credit'] = round($lines[count($lines) - 1]['credit'] + $tax, 6);
            } else {
                $lines[] = ['rule' => 'sales_revenue', 'debit' => 0, 'credit' => $tax];
            }
        }
        require_once app_path('includes/acc_gl_inventory_cost.php');
        $cogsCost = acc_gl_sale_invoice_inventory_cost($pdo, $invoiceId);
        foreach (acc_gl_cogs_lines($settings, $cogsCost, false) as $cogsLn) {
            $lines[] = $cogsLn;
        }
        acc_gl_post_entry(
            $pdo,
            'sale_invoice',
            $invoiceId,
            (string) $inv['invoice_date'],
            'ترحيل فاتورة بيع ' . (string) $inv['invoice_no']
            . ' - ' . acc_gl_pay_method_label($pay)
            . ((string) ($inv['customer_name'] ?? '') !== '' ? ' - العميل: ' . (string) $inv['customer_name'] : '')
            . ' - ' . acc_gl_money_text($total),
            $lines
        );
    });
}

/** @return array{ok:bool, skipped:bool, error:?string} */
function acc_gl_post_purchase_invoice(PDO $pdo, int $invoiceId): array
{
    if ($invoiceId < 1 || acc_gl_ref_exists($pdo, 'purchase_invoice', $invoiceId)) {
        return ['ok' => true, 'skipped' => true, 'error' => null];
    }
    if (!acc_gl_is_ready($pdo)) {
        return ['ok' => true, 'skipped' => true, 'error' => null];
    }

    return acc_gl_wrap_post(static function () use ($pdo, $invoiceId): void {
        $st = $pdo->prepare(
            'SELECT i.invoice_no, i.invoice_date, i.payment_type, i.subtotal, i.tax_amount, i.total,
                    COALESCE(s.name_ar, "") AS supplier_name
             FROM pur_invoice i
             LEFT JOIN crm_supplier s ON s.id = i.supplier_id
             WHERE i.id = ? LIMIT 1'
        );
        $st->execute([$invoiceId]);
        $inv = $st->fetch(PDO::FETCH_ASSOC);
        if (!$inv) {
            throw new RuntimeException('فاتورة الشراء غير موجودة.');
        }
        $sub = round((float) $inv['subtotal'], 6);
        $tax = round((float) $inv['tax_amount'], 6);
        $total = round((float) $inv['total'], 6);
        if ($total <= 0) {
            return;
        }
        $settings = acc_gl_load_settings($pdo);
        $useInventory = (int) ($settings['inventory']['account_id'] ?? 0) > 0;
        $expenseRule = $useInventory ? 'inventory' : 'purchases';
        $pay = (string) ($inv['payment_type'] ?? '') === 'cash' ? 'cash' : 'credit';
        $lines = [];
        if ($sub > 0) {
            $lines[] = ['rule' => $expenseRule, 'debit' => $sub, 'credit' => 0];
        }
        if ($tax > 0 && (int) ($settings['vat_input']['account_id'] ?? 0) > 0) {
            $lines[] = ['rule' => 'vat_input', 'debit' => $tax, 'credit' => 0];
        } elseif ($tax > 0 && isset($lines[0])) {
            $lines[0]['debit'] = round($lines[0]['debit'] + $tax, 6);
        } elseif ($tax > 0) {
            $lines[] = ['rule' => $expenseRule, 'debit' => $tax, 'credit' => 0];
        }
        if ($pay === 'credit') {
            $lines[] = ['rule' => 'ap_suppliers', 'debit' => 0, 'credit' => $total];
        } else {
            $lines[] = ['rule' => 'cash', 'debit' => 0, 'credit' => $total];
        }
        acc_gl_post_entry(
            $pdo,
            'purchase_invoice',
            $invoiceId,
            (string) $inv['invoice_date'],
            'ترحيل فاتورة شراء ' . (string) $inv['invoice_no']
            . ' - ' . acc_gl_pay_method_label($pay)
            . ((string) ($inv['supplier_name'] ?? '') !== '' ? ' - المورد: ' . (string) $inv['supplier_name'] : '')
            . ' - ' . acc_gl_money_text($total),
            $lines
        );
    });
}

/** @return array{ok:bool, skipped:bool, error:?string} */
function acc_gl_post_sale_return(PDO $pdo, int $returnId): array
{
    if ($returnId < 1 || acc_gl_ref_exists($pdo, 'sale_return', $returnId)) {
        return ['ok' => true, 'skipped' => true, 'error' => null];
    }
    if (!acc_gl_is_ready($pdo)) {
        return ['ok' => true, 'skipped' => true, 'error' => null];
    }

    return acc_gl_wrap_post(static function () use ($pdo, $returnId): void {
        $st = $pdo->prepare(
            'SELECT r.return_no, r.return_date, r.subtotal, r.tax_amount, r.total,
                    i.payment_type, i.invoice_no,
                    COALESCE(c.name_ar, "") AS customer_name
             FROM sal_return r
             INNER JOIN sal_invoice i ON i.id = r.invoice_id
             LEFT JOIN crm_customer c ON c.id = i.customer_id
             WHERE r.id = ? LIMIT 1'
        );
        $st->execute([$returnId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return;
        }
        $sub = round((float) ($row['subtotal'] ?? $row['total'] ?? 0), 6);
        $tax = round((float) ($row['tax_amount'] ?? 0), 6);
        $total = round((float) $row['total'], 6);
        if ($total <= 0) {
            return;
        }
        $settings = acc_gl_load_settings($pdo);
        $revRule = (int) ($settings['sales_returns']['account_id'] ?? 0) > 0 ? 'sales_returns' : 'sales_revenue';
        $pay = (string) ($row['payment_type'] ?? 'credit') === 'cash' ? 'cash' : 'credit';
        $lines = [];
        if ($sub > 0) {
            $lines[] = ['rule' => $revRule, 'debit' => $sub, 'credit' => 0];
        }
        if ($tax > 0 && (int) ($settings['vat_output']['account_id'] ?? 0) > 0) {
            $lines[] = ['rule' => 'vat_output', 'debit' => $tax, 'credit' => 0];
        } elseif ($tax > 0 && isset($lines[0])) {
            $lines[0]['debit'] = round($lines[0]['debit'] + $tax, 6);
        } elseif ($tax > 0) {
            $lines[] = ['rule' => $revRule, 'debit' => $tax, 'credit' => 0];
        }
        if ($pay === 'credit') {
            $lines[] = ['rule' => 'ar_customers', 'debit' => 0, 'credit' => $total];
        } else {
            $lines[] = ['rule' => 'cash', 'debit' => 0, 'credit' => $total];
        }
        require_once app_path('includes/acc_gl_inventory_cost.php');
        $cogsCost = acc_gl_sale_return_inventory_cost($pdo, $returnId);
        foreach (acc_gl_cogs_lines($settings, $cogsCost, true) as $cogsLn) {
            $lines[] = $cogsLn;
        }
        acc_gl_post_entry(
            $pdo,
            'sale_return',
            $returnId,
            (string) $row['return_date'],
            'ترحيل مردود مبيعات ' . (string) $row['return_no']
            . ' - ' . acc_gl_pay_method_label($pay)
            . ((string) ($row['customer_name'] ?? '') !== '' ? ' - العميل: ' . (string) $row['customer_name'] : '')
            . ' - ' . acc_gl_money_text($total),
            $lines
        );
    });
}

/** @return array{ok:bool, skipped:bool, error:?string} */
function acc_gl_post_purchase_return(PDO $pdo, int $returnId): array
{
    if ($returnId < 1 || acc_gl_ref_exists($pdo, 'purchase_return', $returnId)) {
        return ['ok' => true, 'skipped' => true, 'error' => null];
    }
    if (!acc_gl_is_ready($pdo)) {
        return ['ok' => true, 'skipped' => true, 'error' => null];
    }

    return acc_gl_wrap_post(static function () use ($pdo, $returnId): void {
        $sql = 'SELECT r.return_no, r.return_date, r.subtotal, r.tax_amount, r.total,
                       COALESCE(i.payment_type, \'credit\') AS payment_type,
                       COALESCE(s.name_ar, \'\') AS supplier_name
                FROM pur_return r
                INNER JOIN pur_invoice i ON i.id = r.invoice_id
                LEFT JOIN crm_supplier s ON s.id = r.supplier_id
                WHERE r.id = ? LIMIT 1';
        try {
            $pdo->query('SELECT payment_type FROM pur_invoice LIMIT 1');
        } catch (Throwable $e) {
            $sql = 'SELECT r.return_no, r.return_date, r.subtotal, r.tax_amount, r.total,
                           \'credit\' AS payment_type,
                           COALESCE(s.name_ar, \'\') AS supplier_name
                    FROM pur_return r
                    LEFT JOIN crm_supplier s ON s.id = r.supplier_id
                    WHERE r.id = ? LIMIT 1';
        }
        $st = $pdo->prepare($sql);
        $st->execute([$returnId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return;
        }
        $sub = round((float) ($row['subtotal'] ?? $row['total'] ?? 0), 6);
        $tax = round((float) ($row['tax_amount'] ?? 0), 6);
        $total = round((float) $row['total'], 6);
        if ($total <= 0) {
            return;
        }
        $settings = acc_gl_load_settings($pdo);
        $useInventory = (int) ($settings['inventory']['account_id'] ?? 0) > 0;
        // عند تفعيل حساب المخزون: عكس فاتورة الشراء (دائن المخزون) ليظهر في كشف حساب المخزون.
        $expRule = $useInventory
            ? 'inventory'
            : ((int) ($settings['purchase_returns']['account_id'] ?? 0) > 0
                ? 'purchase_returns'
                : 'purchases');
        $pay = (string) ($row['payment_type'] ?? '') === 'cash' ? 'cash' : 'credit';
        $lines = [];
        $creditBase = $sub > 0 ? $sub : ($tax > 0 ? 0 : $total);
        if ($creditBase > 0) {
            $lines[] = ['rule' => $expRule, 'debit' => 0, 'credit' => $creditBase];
        }
        if ($tax > 0 && (int) ($settings['vat_input']['account_id'] ?? 0) > 0) {
            $lines[] = ['rule' => 'vat_input', 'debit' => 0, 'credit' => $tax];
        } elseif ($tax > 0 && isset($lines[0])) {
            $lines[0]['credit'] = round($lines[0]['credit'] + $tax, 6);
        } elseif ($tax > 0) {
            $lines[] = ['rule' => $expRule, 'debit' => 0, 'credit' => $tax];
        }
        if ($pay === 'credit') {
            $lines[] = ['rule' => 'ap_suppliers', 'debit' => $total, 'credit' => 0];
        } else {
            $lines[] = ['rule' => 'cash', 'debit' => $total, 'credit' => 0];
        }
        acc_gl_post_entry(
            $pdo,
            'purchase_return',
            $returnId,
            (string) $row['return_date'],
            'ترحيل مردود مشتريات ' . (string) $row['return_no']
            . ' - ' . acc_gl_pay_method_label($pay)
            . ((string) ($row['supplier_name'] ?? '') !== '' ? ' - المورد: ' . (string) $row['supplier_name'] : '')
            . ' - ' . acc_gl_money_text($total),
            $lines
        );
    });
}

/** @return array{ok:bool, skipped:bool, error:?string} */
function acc_gl_post_cash_receipt(PDO $pdo, int $voucherId): array
{
    if ($voucherId < 1 || acc_gl_ref_exists($pdo, 'cash_receipt', $voucherId)) {
        return ['ok' => true, 'skipped' => true, 'error' => null];
    }
    if (!acc_gl_is_ready($pdo)) {
        return ['ok' => true, 'skipped' => true, 'error' => null];
    }

    return acc_gl_wrap_post(static function () use ($pdo, $voucherId): void {
        require_once app_path('includes/fin_voucher.php');
        $row = fin_voucher_load($pdo, $voucherId, 'receipt');
        if (!$row || (string) ($row['party_type'] ?? '') !== 'customer') {
            return;
        }
        $amount = (float) ($row['amount'] ?? 0);
        if ($amount <= 0 && (string) ($row['pay_method'] ?? '') === 'check') {
            $amount = (float) ($row['check_amount'] ?? 0);
        }
        if ($amount <= 0) {
            return;
        }
        $partyName = acc_gl_party_name($pdo, 'customer', (int) ($row['party_id'] ?? 0));
        $partyMemo = $partyName !== '' ? 'العميل: ' . $partyName : '';
        acc_gl_post_entry(
            $pdo,
            'cash_receipt',
            $voucherId,
            (string) $row['voucher_date'],
            acc_gl_voucher_description($pdo, 'receipt', $row, $amount),
            [
                acc_gl_cash_line_for_receipt($pdo, $row, $amount, 0.0, $partyMemo),
                ['rule' => 'ar_customers', 'debit' => 0, 'credit' => $amount, 'memo' => $partyMemo],
            ]
        );
    });
}

/** @return array{ok:bool, skipped:bool, error:?string} */
function acc_gl_post_cash_payment(PDO $pdo, int $voucherId): array
{
    if ($voucherId < 1 || acc_gl_ref_exists($pdo, 'cash_payment', $voucherId)) {
        return ['ok' => true, 'skipped' => true, 'error' => null];
    }
    if (!acc_gl_is_ready($pdo)) {
        return ['ok' => true, 'skipped' => true, 'error' => null];
    }

    return acc_gl_wrap_post(static function () use ($pdo, $voucherId): void {
        require_once app_path('includes/fin_voucher.php');
        $row = fin_voucher_load($pdo, $voucherId, 'payment');
        if (!$row) {
            return;
        }
        $amount = (float) ($row['amount'] ?? 0);
        if ($amount <= 0 && (string) ($row['pay_method'] ?? '') === 'check') {
            $amount = (float) ($row['check_amount'] ?? 0);
        }
        if ($amount <= 0) {
            return;
        }
        $payMethod = (string) ($row['pay_method'] ?? 'cash');
        $party = (string) ($row['party_type'] ?? '');
        $partyName = acc_gl_party_name($pdo, $party, (int) ($row['party_id'] ?? 0));
        $partyMemo = '';
        if ($partyName !== '') {
            $partyMemo = $party === 'supplier' ? 'المورد: ' . $partyName : ($party === 'customer' ? 'العميل: ' . $partyName : $partyName);
        }
        $advCtx = acc_gl_payment_hr_advance_context($pdo, $row);
        if ($advCtx !== null && ($advCtx['memo'] ?? '') !== '') {
            $partyMemo = (string) $advCtx['memo'];
        }

        // شيك صادر: مدين الطرف / دائن الشيكات الآجلة — البنك عند «صرف» فقط.
        if ($payMethod === 'check') {
            $deferredId = acc_gl_outgoing_deferred_checks_account_id($pdo);
            if ($deferredId < 1 || !acc_gl_is_valid_leaf_account($pdo, $deferredId)) {
                throw new RuntimeException('اربط حساب «الشيكات الآجلة (صادرة)» من شاشة ربط الحسابات.');
            }
            $creditLine = [
                'account_id' => $deferredId,
                'debit' => 0.0,
                'credit' => $amount,
                'memo' => $partyMemo,
            ];
        } else {
            $creditLine = acc_gl_cash_line_for_voucher($pdo, $row, 0.0, $amount, $partyMemo);
        }

        $lines = [$creditLine];
        if ($party === 'supplier') {
            array_unshift($lines, ['rule' => 'ap_suppliers', 'debit' => $amount, 'credit' => 0, 'memo' => $partyMemo]);
        } elseif ($party === 'customer') {
            array_unshift($lines, ['rule' => 'ar_customers', 'debit' => 0, 'credit' => $amount, 'memo' => $partyMemo]);
        } elseif ($party === 'employee') {
            array_unshift($lines, acc_gl_payment_employee_debit_line($pdo, $row, $amount, $partyMemo));
        } elseif ($party === 'account') {
            $offsetId = (int) ($row['offset_account_id'] ?? 0);
            if ($offsetId < 1) {
                throw new RuntimeException('حساب الصرف المُدين غير محدد.');
            }
            array_unshift($lines, [
                'account_id' => $offsetId,
                'debit' => $amount,
                'credit' => 0,
                'memo' => $partyMemo,
            ]);
        } else {
            array_unshift($lines, ['rule' => 'misc_expense', 'debit' => $amount, 'credit' => 0, 'memo' => $partyMemo]);
        }
        acc_gl_post_entry(
            $pdo,
            'cash_payment',
            $voucherId,
            (string) $row['voucher_date'],
            acc_gl_voucher_description($pdo, 'payment', $row, $amount),
            $lines
        );
    });
}

/** @return array{ok:bool, skipped:bool, error:?string} */
function acc_gl_post_debit_note(PDO $pdo, int $noteId): array
{
    if ($noteId < 1 || acc_gl_ref_exists($pdo, 'debit_note', $noteId)) {
        return ['ok' => true, 'skipped' => true, 'error' => null];
    }
    if (!acc_gl_is_ready($pdo)) {
        return ['ok' => true, 'skipped' => true, 'error' => null];
    }

    return acc_gl_wrap_post(static function () use ($pdo, $noteId): void {
        require_once app_path('includes/fin_debit_note.php');
        $note = fin_debit_note_fetch($pdo, $noteId);
        if (!$note) {
            return;
        }
        $total = round((float) ($note['total'] ?? 0), 6);
        if ($total <= 0) {
            return;
        }
        $party = (string) ($note['party_type'] ?? '');
        $partyName = acc_gl_party_name($pdo, $party, (int) ($note['party_id'] ?? 0));
        $lines = [];
        if ($party === 'customer') {
            $lines[] = ['rule' => 'ar_customers', 'debit' => 0, 'credit' => $total];
            $lines[] = ['rule' => 'sales_returns', 'debit' => $total, 'credit' => 0];
        } else {
            $lines[] = ['rule' => 'ap_suppliers', 'debit' => 0, 'credit' => $total];
            $lines[] = ['rule' => 'purchases', 'debit' => $total, 'credit' => 0];
        }
        $settings = acc_gl_load_settings($pdo);
        if ($party === 'customer' && (int) ($settings['sales_returns']['account_id'] ?? 0) < 1) {
            $lines[1]['rule'] = 'sales_revenue';
        }
        acc_gl_post_entry(
            $pdo,
            'debit_note',
            $noteId,
            (string) $note['note_date'],
            'إشعار مدين ' . (string) $note['note_no']
            . ($partyName !== '' ? ' - ' . ($party === 'supplier' ? 'المورد: ' : 'العميل: ') . $partyName : '')
            . ' - ' . acc_gl_money_text($total),
            $lines
        );
    });
}

/** @return array{ok:bool, skipped:bool, error:?string} */
function acc_gl_post_credit_note(PDO $pdo, int $noteId): array
{
    if ($noteId < 1 || acc_gl_ref_exists($pdo, 'credit_note', $noteId)) {
        return ['ok' => true, 'skipped' => true, 'error' => null];
    }
    if (!acc_gl_is_ready($pdo)) {
        return ['ok' => true, 'skipped' => true, 'error' => null];
    }

    return acc_gl_wrap_post(static function () use ($pdo, $noteId): void {
        require_once app_path('includes/fin_credit_note.php');
        $note = fin_credit_note_fetch($pdo, $noteId);
        if (!$note) {
            return;
        }
        $total = round((float) ($note['total'] ?? 0), 6);
        if ($total <= 0) {
            return;
        }
        $party = (string) ($note['party_type'] ?? '');
        $partyName = acc_gl_party_name($pdo, $party, (int) ($note['party_id'] ?? 0));
        $lines = [];
        if ($party === 'customer') {
            $lines[] = ['rule' => 'ar_customers', 'debit' => $total, 'credit' => 0];
            $lines[] = ['rule' => 'sales_revenue', 'debit' => 0, 'credit' => $total];
        } else {
            $lines[] = ['rule' => 'ap_suppliers', 'debit' => $total, 'credit' => 0];
            $lines[] = ['rule' => 'purchases', 'debit' => 0, 'credit' => $total];
        }
        acc_gl_post_entry(
            $pdo,
            'credit_note',
            $noteId,
            (string) $note['note_date'],
            'إشعار دائن ' . (string) $note['note_no']
            . ($partyName !== '' ? ' - ' . ($party === 'supplier' ? 'المورد: ' : 'العميل: ') . $partyName : '')
            . ' - ' . acc_gl_money_text($total),
            $lines
        );
    });
}

/** دمج رسالة خطأ الترحيل المحاسبي في نتيجة الترحيل. */
function acc_gl_merge_post_result(array $base, array $gl): array
{
    if ($gl['skipped'] || $gl['ok']) {
        return $base;
    }
    if (!$base['ok']) {
        return $base;
    }
    $base['ok'] = false;
    $base['error'] = ($base['error'] ?? '') !== ''
        ? $base['error'] . ' | ' . ($gl['error'] ?? 'خطأ محاسبي')
        : ($gl['error'] ?? 'خطأ في الترحيل المحاسبي');

    return $base;
}

/**
 * إن اكتمل الترحيل التشغيلي (مخزون + ذمم) لا يُعدّ فشل القيد المحاسبي فشلاً كاملاً للمستند.
 *
 * @param array{ok:bool,skipped:bool,error:?string} $gl
 * @return array{ok:bool,skipped:bool,error:?string,warning:?string}
 */
function acc_gl_soften_if_operational_posted(array $gl, bool $operationalComplete): array
{
    if ($gl['skipped'] || $gl['ok'] || !$operationalComplete) {
        return [
            'ok' => (bool) ($gl['ok'] ?? false),
            'skipped' => (bool) ($gl['skipped'] ?? false),
            'error' => $gl['error'] ?? null,
            'warning' => null,
        ];
    }

    return [
        'ok' => true,
        'skipped' => false,
        'error' => null,
        'warning' => $gl['error'] ?? 'تعذر إنشاء القيد المحاسبي التلقائي (المخزون والذمم مُرحّلان).',
    ];
}
