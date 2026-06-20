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

/**
 * @return array{
 *   hero: array<string, string>,
 *   highlights: list<array{label:string, value:string, hint?:string, tone?:string}>,
 *   sections: list<array{title:string, icon:string, metrics:list<array>, links?:list<array{label:string, url:string}>>}>,
 *   liabilities: list<array{label:string, value:string, hint?:string, tone?:string, url?:string}>,
 *   check_alerts: list<array<string, mixed>>,
 *   check_alerts_summary: array{total:int, overdue:int, today:int, soon:int, total_amount:float}
 * }
 */
function dashboard_collect(PDO $pdo): array
{
    require_once app_path('includes/company_settings.php');
    require_once app_path('includes/date_defaults.php');
    $settings = company_settings($pdo);
    $user = current_user();
    $monthStart = date('Y-m-01');
    $today = date('Y-m-d');
    $vatDateFrom = app_default_date_from();

    $highlights = [];
    $sections = [];
    $checkAlerts = [];
    $checkSummary = ['total' => 0, 'overdue' => 0, 'today' => 0, 'soon' => 0, 'total_amount' => 0.0];
    if (user_can('cash_receipt') || user_can('cash_receipts_list')) {
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

    if (user_can('sales_invoices') || user_can('sales_invoices_list')) {
        $salesTotal = dashboard_sum($pdo, 'sal_invoice', 'total', "status = 'confirmed'");
        $salesMonth = dashboard_sum($pdo, 'sal_invoice', 'total', "status = 'confirmed' AND invoice_date >= '{$monthStart}'");
        $highlights[] = dashboard_metric('إجمالي المبيعات', $salesTotal, 'money', null, 'primary');
        $highlights[] = dashboard_metric('مبيعات هذا الشهر', $salesMonth, 'money', null, 'success');

    }

    if (user_can('purchase_invoices') || user_can('purchase_invoices_list')) {
        $purTotal = dashboard_sum($pdo, 'pur_invoice', 'total', "status = 'confirmed'");
        if (count($highlights) < 6) {
            $highlights[] = dashboard_metric('إجمالي المشتريات', $purTotal, 'money', null, 'primary');
        }

    }

    if (user_can('cash_receipt') || user_can('cash_payment')) {
        $receipts = dashboard_sum($pdo, 'fin_voucher', 'amount', "voucher_type = 'receipt'");
        $payments = dashboard_sum($pdo, 'fin_voucher', 'amount', "voucher_type = 'payment'");

        $highlights[] = dashboard_metric('إجمالي المقبوضات', $receipts, 'money', null, 'success');
        $highlights[] = dashboard_metric('إجمالي الصرفيات', $payments, 'money', null, 'warn');

        if (user_can('cash_receipt') || user_can('cash_receipts_list')) {
            $checksFundHint = (int) $checkSummary['total'] > 0
                ? number_format((int) $checkSummary['total']) . ' شيك · رصيد الاستاذ العام'
                : 'رصيد حساب صندوق الشيكات في الاستاذ العام';
            if ((int) $checkSummary['overdue'] > 0) {
                $checksFundHint .= ' · ' . (int) $checkSummary['overdue'] . ' متأخر';
            }
            $checksFundTone = ($checkSummary['overdue'] > 0 || $checkSummary['today'] > 0) ? 'warn' : 'primary';
            $checksFundKpi = dashboard_metric(
                'صندوق الشيكات',
                (float) $checkSummary['total_amount'],
                'money',
                $checksFundHint,
                $checksFundTone
            );
            if ((int) $checkSummary['total'] > 0) {
                $checksFundKpi['click_filter'] = 'all';
            }
            $highlights[] = $checksFundKpi;
        }

    }

    if (dashboard_table_exists($pdo, 'crm_customer_ledger')) {
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
    if (dashboard_table_exists($pdo, 'crm_supplier_ledger')) {
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
    if (dashboard_table_exists($pdo, 'sal_invoice') && user_can('sales_invoices')) {
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

    $liabilities = dashboard_collect_liabilities($pdo, $vatDateFrom, $today);

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
        'highlights' => array_slice($highlights, 0, 8),
        'liabilities' => $liabilities,
        'sections' => $sections,
        'recent_sales' => $recentSales,
        'check_alerts' => $checkAlerts,
        'check_alerts_summary' => $checkSummary,
    ];
}
