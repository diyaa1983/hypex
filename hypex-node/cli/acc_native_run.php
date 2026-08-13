<?php
declare(strict_types=1);

/**
 * CLI لتقارير وعمليات المحاسبة من hypex-node.
 * Usage: php acc_native_run.php <action> <userId>
 * stdin JSON → stdout JSON line
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "cli only\n");
    exit(1);
}

$action = strtolower(trim((string) ($argv[1] ?? '')));
$userId = (int) ($argv[2] ?? 0);

$raw = stream_get_contents(STDIN);
$payload = json_decode($raw !== false && $raw !== '' ? $raw : '{}', true);
if (!is_array($payload)) {
    $payload = [];
}

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

function cli_out(array $data, int $code = 0): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
    exit($code);
}

function cli_login(int $userId): void
{
    if ($userId < 1) {
        cli_out(['ok' => false, 'error' => 'user_id مطلوب.'], 1);
    }
    $u = null;
    foreach (
        [
            'SELECT id, username, COALESCE(full_name_ar, full_name, username) AS full_name FROM sys_user WHERE id = ? AND is_active = 1 LIMIT 1',
            'SELECT id, username, full_name_ar AS full_name FROM sys_user WHERE id = ? AND is_active = 1 LIMIT 1',
            'SELECT id, username, full_name FROM sys_user WHERE id = ? AND is_active = 1 LIMIT 1',
            'SELECT id, username, username AS full_name FROM sys_user WHERE id = ? AND is_active = 1 LIMIT 1',
        ] as $sql
    ) {
        try {
            $st = db()->prepare($sql);
            $st->execute([$userId]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            if ($u) {
                break;
            }
        } catch (Throwable $e) {
            continue;
        }
    }
    if (!$u) {
        cli_out(['ok' => false, 'error' => 'المستخدم غير موجود.'], 1);
    }
    $_SESSION['user'] = [
        'id' => (int) $u['id'],
        'username' => (string) ($u['username'] ?? ''),
        'name' => (string) ($u['full_name'] ?? $u['username'] ?? ''),
        'full_name' => (string) ($u['full_name'] ?? ''),
    ];
    $_SESSION['is_system_admin'] = user_is_system_admin((int) $u['id']);
    $_SESSION['permissions'] = load_user_permissions((int) $u['id']);
    $_SESSION['permissions_user_id'] = (int) $u['id'];
    $_SESSION['permissions_loaded_at'] = time();
    $_SESSION['app_context'] = 'desktop';
}

function p_iso(mixed $v, string $fallback = ''): string
{
    $s = parse_date_to_iso(trim((string) $v));
    if ($s !== null && $s !== '') {
        return $s;
    }
    return $fallback;
}

try {
    cli_login($userId);
    $pdo = db();

    require_once app_path('includes/acc_gl.php');
    require_once app_path('includes/acc_journal.php');
    acc_gl_ensure_schema($pdo);
    acc_journal_ensure_schema($pdo);

    $from = p_iso($payload['from'] ?? $payload['date_from'] ?? '', function_exists('app_default_date_from') ? app_default_date_from() : date('Y-01-01'));
    $to = p_iso($payload['to'] ?? $payload['date_to'] ?? '', function_exists('app_default_date_to') ? app_default_date_to() : date('Y-m-d'));
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }

    /* ─── look-ups ─── */
    if ($action === 'pickers') {
        $accounts = $pdo->query(
            'SELECT a.id, a.code, a.name_ar FROM acc_account a
             WHERE a.is_active = 1 AND (a.is_leaf = 1 OR NOT EXISTS (
               SELECT 1 FROM acc_account c WHERE c.parent_id = a.id AND c.is_active = 1
             )) ORDER BY a.code ASC LIMIT 5000'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $customers = $pdo->query(
            'SELECT id, code, name_ar FROM crm_customer WHERE is_active = 1 ORDER BY name_ar LIMIT 3000'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $suppliers = [];
        try {
            $suppliers = $pdo->query(
                'SELECT id, code, name_ar FROM crm_supplier WHERE is_active = 1 OR is_active IS NULL ORDER BY name_ar LIMIT 3000'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
        }
        $reps = [];
        try {
            $reps = $pdo->query(
                'SELECT id, code, name_ar FROM crm_sales_rep WHERE is_active = 1 ORDER BY name_ar LIMIT 500'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
        }
        cli_out([
            'ok' => true,
            'accounts' => $accounts,
            'customers' => $customers,
            'suppliers' => $suppliers,
            'reps' => $reps,
        ]);
    }

    /* ─── trial balance ─── */
    if ($action === 'trial_balance' || $action === 'trial_balance_detailed') {
        require_once app_path('includes/acc_report.php');
        $rows = acc_report_trial_balance_full($pdo, $from, $to);
        $totals = function_exists('acc_report_trial_balance_totals')
            ? acc_report_trial_balance_totals($rows)
            : ['closing_debit' => 0, 'closing_credit' => 0];
        cli_out([
            'ok' => true,
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'totals' => $totals,
        ]);
    }

    /* ─── GL / account statement ─── */
    if ($action === 'general_ledger' || $action === 'account_statement') {
        require_once app_path('includes/acc_report.php');
        $accountId = (int) ($payload['account_id'] ?? 0);
        if ($accountId < 1) {
            cli_out(['ok' => true, 'from' => $from, 'to' => $to, 'account' => null, 'pack' => null]);
        }
        $st = $pdo->prepare('SELECT id, code, name_ar, account_type FROM acc_account WHERE id = ? LIMIT 1');
        $st->execute([$accountId]);
        $account = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        $pack = $account ? acc_report_general_ledger_pack($pdo, $accountId, $from, $to) : null;
        cli_out([
            'ok' => true,
            'from' => $from,
            'to' => $to,
            'account' => $account,
            'pack' => $pack,
        ]);
    }

    /* ─── income / balance sheet ─── */
    if ($action === 'income_statement') {
        require_once app_path('includes/acc_report.php');
        $data = acc_report_income_statement($pdo, $from, $to);
        cli_out(['ok' => true, 'from' => $from, 'to' => $to, 'data' => $data]);
    }

    if ($action === 'balance_sheet') {
        require_once app_path('includes/acc_report.php');
        $asOf = p_iso($payload['as_of'] ?? $payload['to'] ?? $to, $to);
        $data = acc_report_balance_sheet($pdo, $asOf);
        cli_out(['ok' => true, 'as_of' => $asOf, 'data' => $data]);
    }

    /* ─── receivables / payables / aging / party ─── */
    if ($action === 'receivables') {
        require_once app_path('includes/sal_receivables_report.php');
        require_once app_path('includes/crm_sales_rep_schema.php');
        require_once app_path('includes/crm_customer_ledger.php');
        crm_sales_rep_ensure_schema($pdo);
        crm_ledger_ensure_schema($pdo);
        $filters = [
            'customer_id' => (int) ($payload['customer_id'] ?? 0),
            'sales_rep_id' => (int) ($payload['sales_rep_id'] ?? 0),
            'from' => $from,
            'to' => $to,
            'mode' => in_array(($payload['mode'] ?? 'detail'), ['summary', 'detail'], true)
                ? (string) $payload['mode']
                : 'detail',
        ];
        $built = sal_report_receivables_build($pdo, $filters);
        cli_out(['ok' => true, 'from' => $from, 'to' => $to, 'filters' => $filters, 'built' => $built]);
    }

    if ($action === 'receivables_aging') {
        require_once app_path('includes/sal_receivables_aging_report.php');
        require_once app_path('includes/crm_customer_ledger.php');
        crm_ledger_ensure_schema($pdo);
        $asOf = p_iso($payload['as_of'] ?? $to, $to);
        $filters = [
            'as_of' => $asOf,
            'customer_id' => (int) ($payload['customer_id'] ?? 0),
            'sales_rep_id' => (int) ($payload['sales_rep_id'] ?? 0),
            'mode' => in_array(($payload['mode'] ?? 'summary'), ['summary', 'detail'], true)
                ? (string) $payload['mode']
                : 'summary',
        ];
        $built = sal_report_receivables_aging_build($pdo, $filters);
        cli_out(['ok' => true, 'as_of' => $asOf, 'filters' => $filters, 'built' => $built]);
    }

    if ($action === 'payables') {
        require_once app_path('includes/crm_party_statement.php');
        // كشف ذمم الموردين: أرصدة + اختياريًا حركات
        $supplierId = (int) ($payload['supplier_id'] ?? 0);
        $mode = (string) ($payload['mode'] ?? 'summary');
        require_once app_path('includes/crm_supplier_ledger.php');
        if (function_exists('crm_supplier_ledger_ensure_schema')) {
            crm_supplier_ledger_ensure_schema($pdo);
        }
        $rows = [];
        if ($supplierId > 0) {
            $built = crm_party_statement_build($pdo, 'supplier', $supplierId, $from, $to);
            cli_out(['ok' => true, 'from' => $from, 'to' => $to, 'mode' => 'detail', 'built' => $built, 'rows' => []]);
        }
        try {
            $sql = 'SELECT s.id, s.code, s.name_ar,
                           COALESCE((
                             SELECT SUM(l.debit - l.credit) FROM crm_supplier_ledger l
                             WHERE l.supplier_id = s.id AND l.txn_date <= ?
                           ), 0) AS balance
                    FROM crm_supplier s
                    WHERE (s.is_active = 1 OR s.is_active IS NULL)
                    ORDER BY s.name_ar LIMIT 2000';
            $st = $pdo->prepare($sql);
            $st->execute([$to]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            // fallback empty
        }
        cli_out(['ok' => true, 'from' => $from, 'to' => $to, 'mode' => $mode, 'rows' => $rows]);
    }

    if ($action === 'party_statement') {
        require_once app_path('includes/crm_party_statement.php');
        require_once app_path('includes/crm_customer_ledger.php');
        require_once app_path('includes/crm_supplier_ledger.php');
        crm_ledger_ensure_schema($pdo);
        if (function_exists('crm_supplier_ledger_ensure_schema')) {
            crm_supplier_ledger_ensure_schema($pdo);
        }
        $partyType = strtolower(trim((string) ($payload['party_type'] ?? 'customer')));
        if ($partyType !== 'supplier') {
            $partyType = 'customer';
        }
        $partyId = (int) ($payload['party_id'] ?? 0);
        if ($partyId < 1) {
            cli_out(['ok' => true, 'from' => $from, 'to' => $to, 'built' => null]);
        }
        $built = crm_party_statement_build($pdo, $partyType, $partyId, $from, $to);
        cli_out(['ok' => true, 'from' => $from, 'to' => $to, 'built' => $built]);
    }

    /* ─── checks ─── */
    if ($action === 'checks_in') {
        require_once app_path('includes/fin_incoming_checks_report.php');
        $dateField = in_array(($payload['date_field'] ?? 'voucher'), ['voucher', 'due'], true)
            ? (string) $payload['date_field'] : 'voucher';
        $posted = in_array(($payload['posted'] ?? 'all'), ['all', 'posted', 'unposted'], true)
            ? (string) $payload['posted'] : 'all';
        $scope = (string) ($payload['check_scope'] ?? 'all');
        if (function_exists('fin_voucher_checks_report_normalize_scope')) {
            $scope = fin_voucher_checks_report_normalize_scope($scope);
        }
        $rows = fin_incoming_checks_report_fetch(
            $pdo,
            $from,
            $to,
            $dateField,
            $posted,
            (int) ($payload['customer_id'] ?? 0),
            trim((string) ($payload['check_no'] ?? '')),
            $scope
        );
        $sum = 0.0;
        foreach ($rows as $r) {
            $sum += (float) ($r['check_amount'] ?? $r['amount'] ?? 0);
        }
        cli_out(['ok' => true, 'from' => $from, 'to' => $to, 'rows' => $rows, 'sum' => $sum]);
    }

    if ($action === 'checks_out') {
        require_once app_path('includes/fin_outgoing_checks_report.php');
        $dateField = in_array(($payload['date_field'] ?? 'voucher'), ['voucher', 'due'], true)
            ? (string) $payload['date_field'] : 'voucher';
        $posted = in_array(($payload['posted'] ?? 'all'), ['all', 'posted', 'unposted'], true)
            ? (string) $payload['posted'] : 'all';
        $rows = fin_outgoing_checks_report_fetch(
            $pdo,
            $from,
            $to,
            $dateField,
            $posted,
            (int) ($payload['supplier_id'] ?? 0),
            trim((string) ($payload['check_no'] ?? ''))
        );
        $sum = 0.0;
        foreach ($rows as $r) {
            $sum += (float) ($r['check_amount'] ?? $r['amount'] ?? 0);
        }
        cli_out(['ok' => true, 'from' => $from, 'to' => $to, 'rows' => $rows, 'sum' => $sum]);
    }

    /* ─── oracle statement ─── */
    if ($action === 'oracle_statement') {
        require_once app_path('includes/oracle_statement.php');
        $accountNo = trim((string) ($payload['account_no'] ?? $payload['customer_code'] ?? ''));
        if ($accountNo === '') {
            cli_out(['ok' => true, 'from' => $from, 'to' => $to, 'rows' => [], 'message' => 'أدخل رقم حساب العميل.']);
        }
        try {
            $result = oracle_fetch_customer_statement($accountNo, $from, $to);
            if (is_array($result) && isset($result['ok'])) {
                cli_out(array_merge(['from' => $from, 'to' => $to], $result));
            }
            cli_out([
                'ok' => true,
                'from' => $from,
                'to' => $to,
                'rows' => is_array($result) ? $result : [],
            ]);
        } catch (Throwable $e) {
            cli_out(['ok' => false, 'error' => $e->getMessage() ?: 'تعذر الاتصال بـ Oracle.'], 1);
        }
    }

    /* ─── period close ─── */
    if ($action === 'periods_get' || $action === 'periods_save') {
        require_once app_path('includes/acc_period_lock.php');
        acc_period_ensure_schema($pdo);
        $year = (int) ($payload['year'] ?? date('Y'));
        if ($year < 2000 || $year > 2100) {
            $year = (int) date('Y');
        }
        if ($action === 'periods_save') {
            $lockedRaw = $payload['locked'] ?? [];
            if (!is_array($lockedRaw)) {
                $lockedRaw = [];
            }
            $locks = [];
            for ($m = 1; $m <= 12; $m++) {
                $locks[$m] = !empty($lockedRaw[$m]) || !empty($lockedRaw[(string) $m]) ? 1 : 0;
            }
            acc_period_save_year_locks($pdo, $year, $locks, $userId);
            cli_out([
                'ok' => true,
                'message' => 'تم حفظ حالة إغلاق الأشهر لعام ' . $year . '.',
                'year' => $year,
                'months' => array_values(acc_period_months_for_year($pdo, $year)),
            ]);
        }
        cli_out([
            'ok' => true,
            'year' => $year,
            'months' => array_values(acc_period_months_for_year($pdo, $year)),
            'cur_y' => (int) date('Y'),
            'cur_m' => (int) date('n'),
        ]);
    }

    /* ─── opening balance ─── */
    if (str_starts_with($action, 'opening_')) {
        require_once app_path('includes/acc_opening_balance.php');
        require_once app_path('includes/sql_migration.php');
        try {
            sql_migration_run_file($pdo, 'database/migrations/218_acc_opening_balance.sql');
        } catch (Throwable $e) {
        }
        $year = (int) ($payload['year'] ?? date('Y'));
        if ($year < 2000 || $year > 2100) {
            $year = (int) date('Y');
        }

        if ($action === 'opening_get') {
            $grid = acc_opening_balance_grid($pdo, $year);
            $status = acc_opening_balance_status($pdo, $year);
            $posted = acc_opening_balance_is_posted($pdo, $year);
            $parties = acc_opening_balance_parties($pdo, $year);
            cli_out([
                'ok' => true,
                'year' => $year,
                'grid' => $grid,
                'status' => $status,
                'is_posted' => $posted,
                'parties' => $parties,
            ]);
        }
        if ($action === 'opening_save') {
            $entryDate = (string) ($payload['entry_date'] ?? '');
            $amounts = $payload['amounts'] ?? [];
            if (!is_array($amounts)) {
                $amounts = [];
            }
            $parties = $payload['parties'] ?? [];
            if (!is_array($parties)) {
                $parties = [];
            }
            $result = acc_opening_balance_save_and_post($pdo, $year, $entryDate, $amounts, $userId, $parties);
            cli_out([
                'ok' => true,
                'message' => 'تم حفظ وترحيل الأرصدة الافتتاحية لسنة ' . $year . '.',
                'result' => $result,
            ]);
        }
        if ($action === 'opening_unpost') {
            acc_opening_balance_unpost_execute($pdo, $year, $userId);
            cli_out([
                'ok' => true,
                'message' => 'تم فك ترحيل الأرصدة الافتتاحية لسنة ' . $year . '.',
            ]);
        }
    }

    /* ─── year close ─── */
    if (str_starts_with($action, 'year_')) {
        require_once app_path('includes/acc_year_close.php');
        acc_year_close_ensure_schema($pdo);
        $year = (int) ($payload['year'] ?? date('Y'));
        if ($year < 2000 || $year > 2100) {
            $year = (int) date('Y');
        }
        if ($action === 'year_board') {
            cli_out([
                'ok' => true,
                'year' => $year,
                'board' => acc_year_close_status_board($pdo, $year),
                'preflight' => acc_year_close_preflight($pdo, $year),
            ]);
        }
        if ($action === 'year_register') {
            acc_year_close_register_open_year($pdo, $year, $userId);
            cli_out(['ok' => true, 'message' => 'تم تسجيل السنة المالية ' . $year . ' كمفتوحة.']);
        }
        if ($action === 'year_close') {
            $result = acc_year_close_execute($pdo, $year, $userId);
            cli_out([
                'ok' => true,
                'message' => 'تم إقفال السنة المالية ' . $year . '.',
                'result' => $result,
            ]);
        }
        if ($action === 'year_reopen') {
            $result = acc_year_close_reopen_execute($pdo, $year, $userId);
            cli_out([
                'ok' => true,
                'message' => 'تم فتح السنة المالية ' . $year . '.',
                'result' => $result,
            ]);
        }
    }

    /* ─── تقارير الضريبة ─── */
    if ($action === 'tax_declaration') {
        require_once app_path('includes/acc_report_tax_declaration.php');
        $decl = acc_report_tax_declaration($pdo, $from, $to);
        cli_out(['ok' => true, 'from' => $from, 'to' => $to, 'decl' => $decl]);
    }

    if ($action === 'vat_net') {
        require_once app_path('includes/acc_report_vat_jordan.php');
        require_once app_path('includes/acc_vat_trust_account.php');
        $v = acc_report_vat_jordan_summary($pdo, $from, $to);
        cli_out([
            'ok' => true,
            'from' => $from,
            'to' => $to,
            'summary' => $v,
            'title' => defined('ACC_VAT_TRUST_REPORT_TITLE')
                ? ACC_VAT_TRUST_REPORT_TITLE
                : 'أمانات ضريبة مبيعات',
        ]);
    }

    if ($action === 'vat_invoice_tax') {
        require_once app_path('includes/acc_vat_tax_report.php');
        $kind = acc_vat_tax_normalize_kind((string) ($payload['kind'] ?? 'sale'));
        $rows = acc_vat_report_invoice_tax_lines($pdo, $from, $to, $kind);
        $totals = acc_vat_report_invoice_tax_totals($rows, $kind);
        $sumTotal = 0.0;
        $sumTax = 0.0;
        foreach ($rows as $r) {
            $sumTotal += (float) ($r['total'] ?? 0);
            $sumTax += (float) ($r['tax_amount'] ?? 0);
        }
        cli_out([
            'ok' => true,
            'from' => $from,
            'to' => $to,
            'kind' => $kind,
            'rows' => $rows,
            'totals' => $totals,
            'sum_total' => round($sumTotal, 6),
            'sum_tax' => round($sumTax, 6),
        ]);
    }

    if ($action === 'vat_return_tax') {
        require_once app_path('includes/acc_vat_tax_report.php');
        $kind = acc_vat_tax_normalize_kind((string) ($payload['kind'] ?? 'sale'));
        $rows = acc_vat_report_return_tax_lines($pdo, $from, $to, $kind);
        $totals = acc_vat_report_return_tax_totals($rows, $kind);
        $sumTotal = 0.0;
        $sumTax = 0.0;
        foreach ($rows as $r) {
            $sumTotal += (float) ($r['total'] ?? 0);
            $sumTax += (float) ($r['tax_amount'] ?? 0);
        }
        cli_out([
            'ok' => true,
            'from' => $from,
            'to' => $to,
            'kind' => $kind,
            'rows' => $rows,
            'totals' => $totals,
            'sum_total' => round($sumTotal, 6),
            'sum_tax' => round($sumTax, 6),
        ]);
    }

    if ($action === 'tax_ar3_meta' || $action === 'tax_ar3') {
        require_once app_path('includes/hr_schema.php');
        require_once app_path('includes/hr_payroll_tax_ar3_report.php');
        if (function_exists('hr_employee_ensure_schema')) {
            hr_employee_ensure_schema($pdo);
        }
        $payYear = (int) ($payload['year'] ?? date('Y'));
        if ($payYear < 2000) {
            $payYear = (int) date('Y');
        }
        $employeeId = (int) ($payload['employee_id'] ?? 0);
        $employees = hr_payroll_tax_ar3_employees_for_year($pdo, $payYear);
        $postedYears = hr_payroll_tax_ar3_posted_years($pdo);
        $maxPosted = function_exists('hr_payroll_max_posted_period')
            ? hr_payroll_max_posted_period($pdo)
            : ['year' => (int) date('Y')];

        if ($action === 'tax_ar3_meta') {
            cli_out([
                'ok' => true,
                'year' => $payYear,
                'employees' => $employees,
                'posted_years' => $postedYears,
                'max_posted' => $maxPosted,
            ]);
        }

        if ($employeeId < 1) {
            cli_out(['ok' => false, 'error' => 'اختر الموظف.'], 1);
        }
        if (!hr_payroll_tax_ar3_year_has_posted($pdo, $payYear)) {
            cli_out([
                'ok' => true,
                'year' => $payYear,
                'employee_id' => $employeeId,
                'year_not_posted' => true,
                'report' => null,
                'message' => 'لا رواتب مرحّلة لهذه السنة.',
            ]);
        }
        $report = hr_payroll_tax_ar3_report_build($pdo, $payYear, $employeeId);
        if ($report === null) {
            cli_out(['ok' => false, 'error' => 'لا توجد رواتب مرحّلة لهذا الموظف في السنة المختارة.'], 1);
        }
        cli_out([
            'ok' => true,
            'year' => $payYear,
            'employee_id' => $employeeId,
            'year_not_posted' => false,
            'report' => $report,
        ]);
    }

    cli_out(['ok' => false, 'error' => 'إجراء غير معروف: ' . $action], 1);
} catch (Throwable $e) {
    $msg = trim($e->getMessage());
    if ($msg === '') {
        $msg = 'خطأ غير متوقع.';
    }
    cli_out(['ok' => false, 'error' => $msg], 1);
}
