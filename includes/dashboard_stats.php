<?php
declare(strict_types=1);

function dashboard_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    try {
        $st = $pdo->prepare(
            'SELECT 1 FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
        );
        $st->execute([$table]);
        $cache[$table] = (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        $cache[$table] = false;
    }

    return $cache[$table];
}

function dashboard_column_exists(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    try {
        $st = $pdo->prepare(
            'SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
        );
        $st->execute([$table, $column]);
        $cache[$key] = (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        $cache[$key] = false;
    }

    return $cache[$key];
}

/** @return array{value:mixed, label:string}|null */
function dashboard_scalar(PDO $pdo, string $sql, array $params = []): ?array
{
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_NUM);
        if (!$row) {
            return null;
        }

        return ['value' => $row[0], 'label' => (string) ($row[1] ?? '')];
    } catch (Throwable $e) {
        return null;
    }
}

function dashboard_count(PDO $pdo, string $table, string $where = '1=1'): int
{
    if (!dashboard_table_exists($pdo, $table)) {
        return 0;
    }
    try {
        return (int) $pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '` WHERE ' . $where)->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function dashboard_sum(PDO $pdo, string $table, string $column, string $where = '1=1'): float
{
    if (!dashboard_table_exists($pdo, $table)) {
        return 0.0;
    }
    try {
        $col = '`' . str_replace('`', '``', $column) . '`';
        $tbl = '`' . str_replace('`', '``', $table) . '`';

        return (float) $pdo->query("SELECT COALESCE(SUM({$col}), 0) FROM {$tbl} WHERE {$where}")->fetchColumn();
    } catch (Throwable $e) {
        return 0.0;
    }
}

/**
 * @return list<array{label:string, value:string, hint?:string, tone?:string}>
 */
function dashboard_metric(string $label, int|float|string $value, string $format = 'int', ?string $hint = null, string $tone = ''): array
{
    if ($format === 'money') {
        $display = format_money($value);
    } elseif ($format === 'int') {
        $display = number_format((int) $value);
    } else {
        $display = (string) $value;
    }

    $m = ['label' => $label, 'value' => $display, 'tone' => $tone];
    if ($hint !== null && $hint !== '') {
        $m['hint'] = $hint;
    }

    return $m;
}

/**
 * مربع واحد يجمع أرصدة كل حسابات البنوك في الشجرة.
 *
 * @return array{type:string, label:string, value:string, hint?:string, tone?:string, banks:list<array{label:string, code:string, value:string, url?:string, tone?:string}>}|null
 */
function dashboard_collect_bank_balances_metric(PDO $pdo, string $dateFrom, string $dateTo): ?array
{
    require_once app_path('includes/fin_voucher.php');
    require_once app_path('includes/acc_report.php');
    require_once app_path('includes/acc_gl.php');

    if (!acc_gl_has_posting_table($pdo) || !acc_journal_has_tables($pdo)) {
        return null;
    }

    $bankRows = [];
    foreach (fin_voucher_load_cash_bank_accounts($pdo) as $acc) {
        if ((string) ($acc['group_key'] ?? '') !== 'bank') {
            continue;
        }
        $id = (int) ($acc['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        $bankRows[$id] = $acc;
    }

    $settings = acc_gl_load_settings($pdo);
    $mappedBankId = (int) ($settings['bank']['account_id'] ?? 0);
    if ($mappedBankId > 0 && !isset($bankRows[$mappedBankId]) && acc_gl_is_valid_leaf_account($pdo, $mappedBankId)) {
        try {
            $st = $pdo->prepare('SELECT id, code, name_ar FROM acc_account WHERE id = ? AND is_active = 1 LIMIT 1');
            $st->execute([$mappedBankId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $bankRows[$mappedBankId] = [
                    'id' => $mappedBankId,
                    'code' => (string) ($row['code'] ?? ''),
                    'name_ar' => (string) ($row['name_ar'] ?? ''),
                    'group_key' => 'bank',
                ];
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    if ($bankRows === []) {
        return null;
    }

    $details = [];
    $banks = [];
    $total = 0.0;
    foreach ($bankRows as $id => $acc) {
        $sums = acc_report_account_sums($pdo, (int) $id);
        $bal = (float) ($sums['balance'] ?? 0);
        $total += $bal;
        $name = trim((string) ($acc['name_ar'] ?? ''));
        $code = trim((string) ($acc['code'] ?? ''));
        $bankTone = $bal < -0.0005 ? 'warn' : 'primary';
        $bankItem = [
            'label' => $name !== '' ? $name : ($code !== '' ? $code : 'حساب بنك'),
            'code' => $code,
            'value' => format_money($bal),
            'balance' => $bal,
            'tone' => $bankTone,
        ];
        $url = dashboard_sensitive_account_url($pdo, (int) $id, $dateFrom, $dateTo);
        if ($url !== '') {
            $bankItem['url'] = $url;
        }
        $details[] = $bankItem;
        if (abs($bal) <= 0.0005) {
            continue;
        }
        $banks[] = $bankItem;
    }

    usort($details, static function (array $a, array $b): int {
        $cmp = abs((float) ($b['balance'] ?? 0)) <=> abs((float) ($a['balance'] ?? 0));
        if ($cmp !== 0) {
            return $cmp;
        }

        return strcmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
    });
    foreach ($details as &$detail) {
        unset($detail['balance']);
    }
    unset($detail);

    $count = count($bankRows);
    $activeCount = count($banks);
    if ($activeCount === 1) {
        $hint = ((string) ($banks[0]['code'] ?? '') !== '' ? (string) $banks[0]['code'] . ' · ' : '') . 'من الدفتر';
    } elseif ($activeCount > 1) {
        $hint = $activeCount . ' بنك برصيد · من الدفتر';
    } elseif ($count === 1) {
        $hint = 'من الدفتر';
    } else {
        $hint = $count . ' حسابات · من الدفتر';
    }

    $tone = 'primary';
    if ($total < -0.0005) {
        $tone = 'warn';
    }

    return [
        'label' => 'البنوك',
        'value' => format_money($total),
        'hint' => $hint,
        'tone' => $tone,
        'details' => $details,
    ];
}

/** رصيد مستحق (دائن) لحساب مربوط بقاعدة ترحيل — من القيود المرحّلة. */
function dashboard_posting_rule_liability_balance(PDO $pdo, string $ruleCode): ?float
{
    require_once app_path('includes/acc_gl.php');
    require_once app_path('includes/acc_report.php');
    if (!acc_gl_has_posting_table($pdo) || !acc_journal_has_tables($pdo)) {
        return null;
    }

    $settings = acc_gl_load_settings($pdo);
    $accountId = (int) ($settings[$ruleCode]['account_id'] ?? 0);
    if ($accountId < 1) {
        return null;
    }

    $sums = acc_report_account_sums($pdo, $accountId);
    $payable = round(-(float) ($sums['balance'] ?? 0), 6);
    if (abs($payable) < 0.000001) {
        return 0.0;
    }

    return $payable;
}

/**
 * @return list<array{label:string, value:string, hint?:string, tone?:string, url?:string}>
 */
function dashboard_collect_liabilities(PDO $pdo, string $dateFrom, string $dateTo): array
{
    require_once app_path('includes/acc_gl.php');
    if (!acc_gl_has_posting_table($pdo)) {
        return [];
    }

    $items = [];
    require_once app_path('includes/hr_social_security_payroll.php');
    require_once app_path('includes/hr_income_tax.php');

    $payrollBal = dashboard_posting_rule_liability_balance($pdo, 'salaries_payable');
    if ($payrollBal !== null) {
        $m = dashboard_metric(
            'مستحقات الرواتب',
            max(0, $payrollBal),
            'money',
            'رصيد حساب الرواتب المستحقة — من الدفتر',
            $payrollBal > 0.0005 ? 'warn' : 'success'
        );
        $m['url'] = app_url('index.php?r=hr_payroll_posting');
        $items[] = $m;
    }

    $ssBal = dashboard_posting_rule_liability_balance($pdo, HR_SS_PAYABLE_RULE_CODE);
    if ($ssBal !== null) {
        $m = dashboard_metric(
            'مستحقات الضمان الاجتماعي',
            max(0, $ssBal),
            'money',
            'مجموع حصة الموظف والشركة — من الدفتر',
            $ssBal > 0.0005 ? 'warn' : 'success'
        );
        $m['url'] = app_url('index.php?r=hr_payroll_ss_report');
        $items[] = $m;
    }

    $taxBal = dashboard_posting_rule_liability_balance($pdo, HR_INCOME_TAX_RULE_CODE);
    if ($taxBal !== null) {
        $m = dashboard_metric(
            'مستحقات الضريبة',
            max(0, $taxBal),
            'money',
            'اقتطاع ضريبة الدخل من الرواتب — من الدفتر',
            $taxBal > 0.0005 ? 'warn' : 'success'
        );
        $m['url'] = app_url('index.php?r=hr_income_tax_settings');
        $items[] = $m;
    }

    require_once app_path('includes/acc_vat_trust_account.php');
    require_once app_path('includes/acc_report_vat_jordan.php');
    $vat = acc_report_vat_jordan_summary($pdo, $dateFrom, $dateTo);
    if ((int) ($vat['trust_account_id'] ?? 0) > 0) {
        $closing = (float) ($vat['gl_closing_balance'] ?? 0);
        $periodHint = 'من ' . format_date_dmY($dateFrom) . ' إلى ' . format_date_dmY($dateTo);
        $m = dashboard_metric(
            ACC_VAT_TRUST_REPORT_TITLE,
            $closing,
            'money',
            $periodHint . ' — رصيد ختامي',
            $closing < -0.0005 ? 'warn' : ($closing > 0.0005 ? 'success' : 'primary')
        );
        $m['url'] = app_url(
            'index.php?r=report_vat_net_payable'
            . '&date_from=' . rawurlencode(format_date_dmY($dateFrom))
            . '&date_to=' . rawurlencode(format_date_dmY($dateTo))
        );
        $items[] = $m;
    }

    return $items;
}

function dashboard_sensitive_account_url(PDO $pdo, int $accountId, string $dateFrom, string $dateTo): string
{
    if ($accountId < 1) {
        return '';
    }
    if (user_can('report_account_statement')) {
        require_once app_path('includes/acc_report_ref.php');

        return acc_report_account_statement_url($accountId, $dateFrom, $dateTo);
    }
    if (user_can('report_general_ledger')) {
        return app_url(
            'index.php?r=report_general_ledger'
            . '&account_id=' . $accountId
            . '&date_from=' . rawurlencode(format_date_dmY($dateFrom))
            . '&date_to=' . rawurlencode(format_date_dmY($dateTo))
        );
    }
    if (user_can('chart_of_accounts')) {
        return app_url('index.php?r=chart_of_accounts');
    }
    if (user_can('account_mapping')) {
        return app_url('index.php?r=account_mapping');
    }

    return '';
}

/** @return array{label:string, value:string, hint?:string, tone?:string, url?:string, click_filter?:string}|null */
function dashboard_sensitive_account_metric(
    PDO $pdo,
    string $ruleCode,
    string $dateFrom,
    string $dateTo,
    array $opts = []
): ?array {
    require_once app_path('includes/acc_gl.php');
    require_once app_path('includes/acc_report.php');

    if (!acc_gl_has_posting_table($pdo) || !acc_journal_has_tables($pdo)) {
        return null;
    }

    $resolver = (string) ($opts['resolver'] ?? $ruleCode);
    if ($resolver === 'cash') {
        $accountId = acc_gl_cash_box_account_id($pdo);
    } elseif ($resolver === 'checks_fund') {
        $accountId = acc_gl_checks_fund_account_id($pdo);
    } else {
        $settings = acc_gl_load_settings($pdo);
        $accountId = (int) ($settings[$ruleCode]['account_id'] ?? 0);
    }

    if ($accountId < 1) {
        return null;
    }

    try {
        $st = $pdo->prepare('SELECT code, name_ar FROM acc_account WHERE id = ? LIMIT 1');
        $st->execute([$accountId]);
        $acc = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $acc = false;
    }
    if (!$acc) {
        return null;
    }

    $sums = acc_report_account_sums($pdo, $accountId);
    $rawBal = (float) ($sums['balance'] ?? 0);
    $isLiability = !empty($opts['liability']);
    $displayBal = $isLiability ? max(0.0, -$rawBal) : $rawBal;

    $settings = acc_gl_load_settings($pdo);
    $label = trim((string) ($opts['label'] ?? ''));
    if ($label === '') {
        $label = (string) ($settings[$ruleCode]['label_ar'] ?? $ruleCode);
    }

    $code = trim((string) ($acc['code'] ?? ''));
    $name = trim((string) ($acc['name_ar'] ?? ''));
    $shortHint = $code !== '' ? $code . ' · من الدفتر' : 'من الدفتر';
    $hintExtra = trim((string) ($opts['hint_extra'] ?? ''));
    if ($hintExtra !== '') {
        $shortHint .= ' · ' . $hintExtra;
    }

    $tone = (string) ($opts['tone'] ?? 'primary');
    if ($isLiability) {
        if ($displayBal > 0.0005) {
            $tone = 'warn';
        } elseif ($displayBal <= 0.0005) {
            $tone = 'success';
        }
    } elseif ($displayBal < -0.0005) {
        $tone = 'warn';
    }

    $m = dashboard_metric($label, $displayBal, 'money', $shortHint, $tone);
    $url = dashboard_sensitive_account_url($pdo, $accountId, $dateFrom, $dateTo);
    if ($url !== '') {
        $m['url'] = $url;
    }
    $clickFilter = trim((string) ($opts['click_filter'] ?? ''));
    if ($clickFilter !== '') {
        $m['click_filter'] = $clickFilter;
    }

    $detailLabel = $name !== '' ? $name : ($code !== '' ? $code : $label);
    $m['details'] = [[
        'label' => $detailLabel,
        'code' => $code,
        'value' => format_money($displayBal),
        'tone' => $tone,
        'url' => $url,
    ]];

    return $m;
}

/**
 * @param array{total:int, overdue:int, today:int, soon:int, total_amount:float} $checkSummary
 * @return list<array{label:string, value:string, hint?:string, tone?:string, url?:string, click_filter?:string}>
 */
function dashboard_collect_sensitive_accounts(PDO $pdo, string $dateFrom, string $dateTo, array $checkSummary = []): array
{
    require_once app_path('includes/acc_gl.php');
    if (!acc_gl_has_posting_table($pdo)) {
        return [];
    }

    $items = [];

    $cash = dashboard_sensitive_account_metric($pdo, 'cash', $dateFrom, $dateTo, [
        'label' => 'الصندوق الرئيسي',
        'resolver' => 'cash',
        'tone' => 'primary',
    ]);
    if ($cash !== null) {
        $items[] = $cash;
    }

    $checksHint = '';
    if ((int) ($checkSummary['total'] ?? 0) > 0) {
        $checksHint = number_format((int) $checkSummary['total']) . ' شيك قيد التحصيل';
        if ((int) ($checkSummary['overdue'] ?? 0) > 0) {
            $checksHint .= ' · ' . (int) $checkSummary['overdue'] . ' متأخر';
        }
    }
    $checksOpts = [
        'label' => 'صندوق الشيكات',
        'resolver' => 'checks_fund',
        'tone' => (($checkSummary['overdue'] ?? 0) > 0 || ($checkSummary['today'] ?? 0) > 0) ? 'warn' : 'primary',
    ];
    if ($checksHint !== '') {
        $checksOpts['hint_extra'] = $checksHint;
    }
    if ((int) ($checkSummary['total'] ?? 0) > 0 && dashboard_widget_can('dashboard_panel_checks')) {
        $checksOpts['click_filter'] = 'all';
    }
    $checksFund = dashboard_sensitive_account_metric($pdo, 'checks_fund', $dateFrom, $dateTo, $checksOpts);
    if ($checksFund !== null) {
        $items[] = $checksFund;
    }

    $banksGroup = dashboard_collect_bank_balances_metric($pdo, $dateFrom, $dateTo);
    if ($banksGroup !== null) {
        $items[] = $banksGroup;
    } else {
        $bank = dashboard_sensitive_account_metric($pdo, 'bank', $dateFrom, $dateTo, [
            'code' => 'bank',
            'label' => 'البنك',
            'tone' => 'primary',
        ]);
        if ($bank !== null) {
            $items[] = $bank;
        }
    }

    foreach (
        [
            ['code' => 'ar_customers', 'label' => 'ذمم العملاء', 'tone' => 'primary'],
            ['code' => 'ap_suppliers', 'label' => 'ذمم الموردين', 'tone' => 'warn', 'liability' => true],
            ['code' => 'inventory', 'label' => 'المخزون', 'tone' => 'success'],
        ] as $rule
    ) {
        $metric = dashboard_sensitive_account_metric($pdo, (string) $rule['code'], $dateFrom, $dateTo, $rule);
        if ($metric !== null) {
            $items[] = $metric;
        }
    }

    return $items;
}

/**
 * @return array{
 *   hero: array<string, string>,
 *   highlights: list<array{label:string, value:string, hint?:string, tone?:string}>,
 *   sections: list<array{title:string, icon:string, metrics:list<array>, links?:list<array{label:string, url:string}>>}>,
 *   sensitive_accounts: list<array{label:string, value:string, hint?:string, tone?:string, url?:string, click_filter?:string}>,
 *   liabilities: list<array{label:string, value:string, hint?:string, tone?:string, url?:string}>,
 *   check_alerts: list<array<string, mixed>>,
 *   check_alerts_summary: array{total:int, overdue:int, today:int, soon:int, total_amount:float}
 * }
 */
function dashboard_collect(PDO $pdo): array
{
    require_once app_path('includes/company_settings.php');
    require_once app_path('includes/date_defaults.php');
    require_once app_path('includes/dashboard_permissions.php');
    $settings = company_settings($pdo);
    $user = current_user();
    $monthStart = date('Y-m-01');
    $today = date('Y-m-d');
    $vatDateFrom = app_default_date_from();

    $highlights = [];
    $sections = [];
    $checkAlerts = [];
    $checkSummary = ['total' => 0, 'overdue' => 0, 'today' => 0, 'soon' => 0, 'total_amount' => 0.0];
    if (dashboard_widget_can('dashboard_panel_checks')) {
        require_once app_path('includes/fin_voucher_checks.php');
        $checkAlerts = fin_voucher_checks_pending_collection($pdo, $today);
        $checkSummary['total_amount'] = fin_voucher_checks_fund_gl_balance($pdo);
        foreach ($checkAlerts as $chk) {
            $checkSummary['total']++;
            $u = (string) ($chk['urgency'] ?? '');
            if ($u === 'overdue') {
                $checkSummary['overdue']++;
            } elseif ($u === 'today') {
                $checkSummary['today']++;
            } elseif ($u === 'soon') {
                $checkSummary['soon']++;
            }
        }
    }

    if (dashboard_widget_can('dashboard_kpi_sales')) {
        require_once app_path('includes/sal_period_sales.php');
        $salesTotal = dashboard_sum($pdo, 'sal_invoice', 'total', "status = 'confirmed'");
        $salesMonth = dashboard_sum($pdo, 'sal_invoice', 'total', "status = 'confirmed' AND invoice_date >= '{$monthStart}'");
        $netSalesTotal = sal_period_net_sales_total($pdo, app_default_date_from(), $today);
        $netSalesMonth = sal_period_net_sales_total($pdo, $monthStart, $today);
        $highlights[] = dashboard_metric('إجمالي المبيعات', $salesTotal, 'money', null, 'primary');
        $highlights[] = dashboard_metric('مبيعات هذا الشهر', $salesMonth, 'money', null, 'success');
        $highlights[] = dashboard_metric(
            'صافي المبيعات',
            $netSalesTotal,
            'money',
            'من ' . format_date_dmY(app_default_date_from()) . ' — فواتير مرحّلة − مرتجعات',
            'primary'
        );
        $highlights[] = dashboard_metric(
            'صافي مبيعات الشهر',
            $netSalesMonth,
            'money',
            'فواتير مرحّلة − مرتجعات',
            'success'
        );
    }

    if (dashboard_widget_can('dashboard_kpi_purchases')) {
        $purTotal = dashboard_sum($pdo, 'pur_invoice', 'total', "status = 'confirmed'");
        if (count($highlights) < 6) {
            $highlights[] = dashboard_metric('إجمالي المشتريات', $purTotal, 'money', null, 'primary');
        }

    }

    if (dashboard_widget_can('dashboard_kpi_cashflow')) {
        $receipts = dashboard_sum($pdo, 'fin_voucher', 'amount', "voucher_type = 'receipt'");
        $payments = dashboard_sum($pdo, 'fin_voucher', 'amount', "voucher_type = 'payment'");

        $highlights[] = dashboard_metric('إجمالي المقبوضات', $receipts, 'money', null, 'success');
        $highlights[] = dashboard_metric('إجمالي الصرفيات', $payments, 'money', null, 'warn');
    }

    if (dashboard_widget_can('dashboard_kpi_receivables') && dashboard_table_exists($pdo, 'crm_customer_ledger')) {
        try {
            $row = $pdo->query(
                'SELECT COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) AS bal FROM crm_customer_ledger'
            )->fetch(PDO::FETCH_ASSOC);
            $custBal = (float) ($row['bal'] ?? 0);
            $highlights[] = dashboard_metric(
                'ذمم العملاء',
                $custBal,
                'money',
                'موجب = ذمة على العملاء',
                $custBal >= 0 ? 'primary' : 'warn'
            );
        } catch (Throwable $e) {
            // ignore
        }
    }
    if (dashboard_widget_can('dashboard_kpi_payables') && dashboard_table_exists($pdo, 'crm_supplier_ledger')) {
        try {
            $row = $pdo->query(
                'SELECT COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) AS bal FROM crm_supplier_ledger'
            )->fetch(PDO::FETCH_ASSOC);
            $supBal = (float) ($row['bal'] ?? 0);
            $highlights[] = dashboard_metric(
                'ذمم الموردين (صافي)',
                $supBal,
                'money',
                'موجب = ذمة على الشركة للموردين',
                $supBal >= 0 ? 'warn' : 'success'
            );
        } catch (Throwable $e) {
            // ignore
        }
    }

    /** @var list<array{no:string, date:string, party:string, total:string, url:string}> $recentSales */
    $recentSales = [];
    if (dashboard_table_exists($pdo, 'sal_invoice') && dashboard_widget_can('dashboard_panel_recent_sales')) {
        try {
            $st = $pdo->query(
                "SELECT i.id, i.invoice_no, i.invoice_date, i.total, c.name_ar
                 FROM sal_invoice i
                 INNER JOIN crm_customer c ON c.id = i.customer_id
                 WHERE i.status <> 'cancelled'
                 ORDER BY i.id DESC LIMIT 5"
            );
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $recentSales[] = [
                    'no' => (string) $r['invoice_no'],
                    'date' => format_date_dmY((string) $r['invoice_date']),
                    'party' => (string) $r['name_ar'],
                    'total' => format_money((float) $r['total']),
                    'url' => app_url('index.php?r=sales_invoices&id=' . (int) $r['id']),
                ];
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    $liabilities = [];
    $sensitiveAccounts = [];
    if (dashboard_widget_can('dashboard_panel_liabilities')) {
        $liabilities = dashboard_collect_liabilities($pdo, $vatDateFrom, $today);
    }
    if (dashboard_widget_can('dashboard_panel_treasury')) {
        $sensitiveAccounts = dashboard_collect_sensitive_accounts($pdo, $vatDateFrom, $today, $checkSummary);
    }

    require_once app_path('includes/dashboard_accounts.php');
    if (dashboard_accounts_ensure_schema($pdo)) {
        $panels = dashboard_accounts_collect_panels($pdo, $vatDateFrom, $today, $checkSummary);
        if (dashboard_widget_can('dashboard_panel_treasury')) {
            $sensitiveAccounts = $panels['treasury'];
        }
        if (dashboard_widget_can('dashboard_panel_liabilities')) {
            $liabilities = $panels['liabilities'];
        }
    }

    return [
        'hero' => [
            'company' => (string) ($settings['company_name_ar'] ?? 'الشركة'),
            'user' => (string) ($user['full_name_ar'] ?? $user['username'] ?? ''),
            'date' => format_date_dmY($today),
            'weekday' => [
                'Sunday' => 'الأحد', 'Monday' => 'الإثنين', 'Tuesday' => 'الثلاثاء',
                'Wednesday' => 'الأربعاء', 'Thursday' => 'الخميس', 'Friday' => 'الجمعة', 'Saturday' => 'السبت',
            ][date('l')] ?? '',
        ],
        'highlights' => array_slice($highlights, 0, 10),
        'sensitive_accounts' => $sensitiveAccounts,
        'liabilities' => $liabilities,
        'sections' => $sections,
        'recent_sales' => $recentSales,
        'check_alerts' => $checkAlerts,
        'check_alerts_summary' => $checkSummary,
    ];
}
