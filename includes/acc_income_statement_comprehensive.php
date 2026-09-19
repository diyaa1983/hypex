<?php
declare(strict_types=1);

require_once app_path('includes/acc_report.php');
require_once app_path('includes/acc_account_tree.php');
require_once app_path('includes/acc_gl.php');

/** @return array<int, string> account_id => rule_code */
function acc_pl_comp_posting_rules_by_account(PDO $pdo): array
{
    if (!acc_gl_has_posting_table($pdo)) {
        return [];
    }
    $map = [];
    try {
        $rows = $pdo->query(
            'SELECT account_id, rule_code FROM acc_posting_setting WHERE account_id IS NOT NULL'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $aid = (int) ($row['account_id'] ?? 0);
            if ($aid > 0) {
                $map[$aid] = (string) ($row['rule_code'] ?? '');
            }
        }
    } catch (Throwable $e) {
        return [];
    }

    return $map;
}

function acc_pl_comp_classify_account(array $acc, array $postingByAccount, bool $usesInventory = false): string
{
    $id = (int) ($acc['id'] ?? 0);
    $type = (string) ($acc['account_type'] ?? '');
    $digits = acc_account_code_digits((string) ($acc['code'] ?? ''));

    if ($id > 0 && isset($postingByAccount[$id])) {
        $rule = $postingByAccount[$id];
        if ($usesInventory && in_array($rule, ['purchases', 'purchase_returns'], true)) {
            return 'ignore';
        }
        return match ($rule) {
            'sales_revenue' => 'sales_revenue',
            'sales_returns' => 'sales_returns',
            'cogs' => 'cogs',
            'purchases' => 'purchases',
            'purchase_returns' => 'purchase_returns',
            'salaries_expense' => 'salaries',
            'misc_expense' => 'general_admin',
            default => acc_pl_comp_classify_by_code($type, $digits),
        };
    }

    return acc_pl_comp_classify_by_code($type, $digits, $usesInventory);
}

function acc_pl_comp_classify_by_code(string $accountType, string $digits, bool $usesInventory = false): string
{
    if ($accountType === 'revenue') {
        if ($digits !== '' && str_starts_with($digits, '42')) {
            return 'sales_returns';
        }
        if ($digits !== '' && str_starts_with($digits, '41')) {
            return 'sales_revenue';
        }

        return 'other_revenue';
    }

    if ($accountType === 'expense') {
        if ($usesInventory) {
            if ($digits !== '' && (str_starts_with($digits, '51') || str_starts_with($digits, '55'))) {
                return 'ignore';
            }
        }
        if ($digits !== '' && str_starts_with($digits, '54')) {
            return 'cogs';
        }
        if ($digits !== '' && str_starts_with($digits, '55')) {
            return 'purchase_returns';
        }
        if ($digits !== '' && str_starts_with($digits, '51')) {
            return 'purchases';
        }
        if ($digits === '6001') {
            return 'purchases';
        }
        if ($digits !== '' && str_starts_with($digits, '52')) {
            return 'salaries';
        }
        if ($digits !== '' && str_starts_with($digits, '53')) {
            return 'general_admin';
        }

        return 'other_operating';
    }

    return 'ignore';
}

/** @return array{id:int, code:string, name_ar:string, account_type:string, amount:float, signed:float, is_deduction:bool}|null */
function acc_pl_comp_account_row(
    PDO $pdo,
    array $acc,
    string $section,
    string $dateFrom,
    string $dateTo,
    int $lineNo,
    float $pctBase
): ?array {
    $accountId = (int) ($acc['id'] ?? 0);
    if ($accountId < 1) {
        return null;
    }

    $period = acc_report_account_sums($pdo, $accountId, $dateFrom, $dateTo, false);
    if (abs($period['sum_debit']) < 0.000001 && abs($period['sum_credit']) < 0.000001) {
        return null;
    }

    $type = (string) ($acc['account_type'] ?? '');
    $signed = 0.0;
    if ($type === 'revenue') {
        $signed = round((float) $period['sum_credit'] - (float) $period['sum_debit'], 6);
    } elseif ($type === 'expense') {
        $signed = round((float) $period['sum_debit'] - (float) $period['sum_credit'], 6);
    } else {
        return null;
    }

    if (abs($signed) < 0.000001) {
        return null;
    }

    $isDeduction = in_array($section, ['sales_returns', 'purchase_returns'], true)
        || ($section === 'sales_returns' && $signed < 0)
        || ($section === 'purchase_returns' && $signed < 0);

    $displayAmount = abs($signed);
    if ($section === 'sales_returns' || $section === 'purchase_returns') {
        $displayAmount = abs($signed);
        $isDeduction = true;
    }

    $pct = null;
    if ($pctBase > 0.000001 && !$isDeduction) {
        $pct = round(($displayAmount / $pctBase) * 100, 2);
    }

    return [
        'line_no' => $lineNo,
        'id' => $accountId,
        'code' => (string) ($acc['code'] ?? ''),
        'name_ar' => (string) ($acc['name_ar'] ?? ''),
        'account_type' => $type,
        'section' => $section,
        'amount' => $displayAmount,
        'signed' => $signed,
        'is_deduction' => $isDeduction,
        'pct' => $pct,
    ];
}

/** @param list<array<string, mixed>> $rows */
function acc_pl_comp_sum_signed(array $rows): float
{
    $total = 0.0;
    foreach ($rows as $row) {
        $total += (float) ($row['signed'] ?? 0);
    }

    return round($total, 6);
}

/** @param list<array<string, mixed>> $rows */
function acc_pl_comp_sum_display(array $rows, bool $deductionsOnly = false): float
{
    $total = 0.0;
    foreach ($rows as $row) {
        if ($deductionsOnly && empty($row['is_deduction'])) {
            continue;
        }
        $total += (float) ($row['amount'] ?? 0);
    }

    return round($total, 6);
}

/**
 * قائمة دخل شاملة متعددة المراحل.
 *
 * @return array<string, mixed>
 */
function acc_report_income_statement_comprehensive(PDO $pdo, string $dateFrom, string $dateTo): array
{
    $emptySections = [
        'sales_revenue' => [],
        'sales_returns' => [],
        'other_revenue' => [],
        'cogs' => [],
        'purchases' => [],
        'purchase_returns' => [],
        'salaries' => [],
        'general_admin' => [],
        'other_operating' => [],
    ];

    if (!acc_journal_has_tables($pdo)) {
        return acc_pl_comp_empty_result($dateFrom, $dateTo, $emptySections);
    }

    $postingByAccount = acc_pl_comp_posting_rules_by_account($pdo);
    require_once app_path('includes/acc_report_inventory.php');
    $usesInventory = acc_report_inventory_account_id($pdo) > 0;
    $st = $pdo->query(
        "SELECT id, code, name_ar, account_type
         FROM acc_account
         WHERE is_active = 1 AND is_leaf = 1 AND account_type IN ('revenue', 'expense')
         ORDER BY code ASC, id ASC"
    );
    $accounts = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $sections = $emptySections;
    $lineCounters = array_fill_keys(array_keys($emptySections), 0);

    foreach ($accounts as $acc) {
        $section = acc_pl_comp_classify_account($acc, $postingByAccount, $usesInventory);
        if ($section === 'ignore' || !isset($sections[$section])) {
            continue;
        }
        $lineCounters[$section]++;
        $row = acc_pl_comp_account_row(
            $pdo,
            $acc,
            $section,
            $dateFrom,
            $dateTo,
            $lineCounters[$section],
            0.0
        );
        if ($row !== null) {
            $sections[$section][] = $row;
        }
    }

    require_once app_path('includes/acc_report_inventory.php');
    $purchaseRowsRaw = $sections['purchases'];
    $purchaseReturnRowsRaw = $sections['purchase_returns'];
    acc_report_pl_append_inventory_purchase_rows($pdo, $dateFrom, $dateTo, $purchaseRowsRaw, $purchaseReturnRowsRaw);
    if ($purchaseRowsRaw !== $sections['purchases']) {
        $lineNo = 0;
        foreach ($purchaseRowsRaw as &$row) {
            $lineNo++;
            $row['line_no'] = $lineNo;
            $row['section'] = 'purchases';
            if (!empty($row['inventory_info'])) {
                $row['is_deduction'] = false;
            }
        }
        unset($row);
        $sections['purchases'] = $purchaseRowsRaw;
    }
    if ($purchaseReturnRowsRaw !== $sections['purchase_returns']) {
        $lineNo = 0;
        foreach ($purchaseReturnRowsRaw as &$row) {
            $lineNo++;
            $row['line_no'] = $lineNo;
            $row['section'] = 'purchase_returns';
            if (!empty($row['inventory_info'])) {
                $row['is_deduction'] = true;
            }
        }
        unset($row);
        $sections['purchase_returns'] = $purchaseReturnRowsRaw;
    }

    $usesInventory = acc_report_inventory_account_id($pdo) > 0;

    $grossSales = acc_pl_comp_sum_signed($sections['sales_revenue']);
    $salesReturnsSigned = acc_pl_comp_sum_signed($sections['sales_returns']);
    $otherRevenueSigned = acc_pl_comp_sum_signed($sections['other_revenue']);
    $netSales = round($grossSales + $salesReturnsSigned, 6);

    $cogsSigned = acc_pl_comp_sum_signed($sections['cogs']);
    $purchasesSigned = acc_pl_comp_sum_signed($sections['purchases']);
    $purchaseReturnsSigned = acc_pl_comp_sum_signed($sections['purchase_returns']);
    if ($usesInventory) {
        $totalCogs = round($cogsSigned + $purchaseReturnsSigned, 6);
        $purchasesDisplay = round(abs($purchasesSigned), 6);
    } else {
        $totalCogs = round($cogsSigned + $purchasesSigned + $purchaseReturnsSigned, 6);
        $purchasesDisplay = round(abs($purchasesSigned), 6);
    }

    $grossProfit = round($netSales + $otherRevenueSigned - $totalCogs, 6);

    $salariesSigned = acc_pl_comp_sum_signed($sections['salaries']);
    $generalSigned = acc_pl_comp_sum_signed($sections['general_admin']);
    $otherOpSigned = acc_pl_comp_sum_signed($sections['other_operating']);
    $totalOperating = round($salariesSigned + $generalSigned + $otherOpSigned, 6);

    $operatingIncome = round($grossProfit - $totalOperating, 6);
    $netIncome = $operatingIncome;
    $pctBase = abs($netSales) > 0.000001 ? abs($netSales) : (abs($grossSales) > 0.000001 ? abs($grossSales) : 1.0);

    foreach ($sections as $key => &$rows) {
        $lineNo = 0;
        foreach ($rows as &$row) {
            $lineNo++;
            $row['line_no'] = $lineNo;
            if (!empty($row['is_deduction'])) {
                $row['pct'] = null;
            } elseif ($pctBase > 0.000001) {
                $row['pct'] = round(((float) $row['amount'] / $pctBase) * 100, 2);
            }
        }
        unset($row);
    }
    unset($rows);

    return [
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'sections' => $sections,
        'totals' => [
            'gross_sales' => round($grossSales, 6),
            'sales_returns' => round(abs($salesReturnsSigned), 6),
            'net_sales' => $netSales,
            'other_revenue' => round($otherRevenueSigned, 6),
            'total_revenue' => round($netSales + $otherRevenueSigned, 6),
            'cogs' => round($cogsSigned, 6),
            'purchases' => $purchasesDisplay,
            'purchase_returns' => round(abs($purchaseReturnsSigned), 6),
            'total_cogs' => $totalCogs,
            'uses_inventory' => $usesInventory,
            'gross_profit' => $grossProfit,
            'salaries' => round($salariesSigned, 6),
            'general_admin' => round($generalSigned, 6),
            'other_operating' => round($otherOpSigned, 6),
            'total_operating' => $totalOperating,
            'operating_income' => $operatingIncome,
            'net_income' => $netIncome,
            'net_is_profit' => $netIncome >= 0,
            'pct_base' => $pctBase,
        ],
        'blocks' => acc_pl_comp_build_blocks($sections, [
            'gross_sales' => round($grossSales, 6),
            'sales_returns' => round(abs($salesReturnsSigned), 6),
            'net_sales' => $netSales,
            'other_revenue' => round($otherRevenueSigned, 6),
            'total_revenue' => round($netSales + $otherRevenueSigned, 6),
            'cogs' => round($cogsSigned, 6),
            'purchases' => $purchasesDisplay,
            'purchase_returns' => round(abs($purchaseReturnsSigned), 6),
            'total_cogs' => $totalCogs,
            'uses_inventory' => $usesInventory,
            'gross_profit' => $grossProfit,
            'salaries' => round($salariesSigned, 6),
            'general_admin' => round($generalSigned, 6),
            'other_operating' => round($otherOpSigned, 6),
            'total_operating' => $totalOperating,
            'operating_income' => $operatingIncome,
            'net_income' => $netIncome,
            'net_is_profit' => $netIncome >= 0,
            'pct_base' => $pctBase,
        ]),
        'summary_rows' => acc_pl_comp_summary_rows([
            'total_revenue' => round($netSales + $otherRevenueSigned, 6),
            'total_purchases' => $purchasesDisplay,
            'uses_inventory' => $usesInventory,
            'total_cogs' => $totalCogs,
            'gross_profit' => $grossProfit,
            'total_operating' => $totalOperating,
            'net_income' => $netIncome,
            'net_is_profit' => $netIncome >= 0,
            'pct_base' => $pctBase,
        ]),
    ];
}

/**
 * صفوف ملخص فقط — بدون تفصيل حسابات.
 *
 * @param array<string, float|bool> $totals
 * @return list<array<string, mixed>>
 */
function acc_pl_comp_summary_rows(array $totals): array
{
    $totalRevenue = (float) ($totals['total_revenue'] ?? 0);
    $totalPurchases = (float) ($totals['total_purchases'] ?? 0);
    $usesInventory = !empty($totals['uses_inventory']);
    $totalCogs = (float) ($totals['total_cogs'] ?? 0);
    $grossProfit = (float) ($totals['gross_profit'] ?? 0);
    $totalOperating = (float) ($totals['total_operating'] ?? 0);
    $netIncome = (float) ($totals['net_income'] ?? 0);
    $isProfit = !empty($totals['net_is_profit']);
    $pctBase = (float) ($totals['pct_base'] ?? 1.0);
    $pct = static function (float $amount) use ($pctBase): ?float {
        if ($pctBase < 0.000001) {
            return null;
        }

        return round((abs($amount) / $pctBase) * 100, 2);
    };

    $rows = [
        [
            'line_no' => 1,
            'label' => 'إجمالي الإيرادات',
            'amount' => $totalRevenue,
            'style' => 'normal',
            'deduction' => false,
        ],
    ];
    $lineNo = 1;

    if ($totalPurchases > 0.0005) {
        $lineNo++;
        $rows[] = [
            'line_no' => $lineNo,
            'label' => $usesInventory ? 'المشتريات (تُسجّل في المخزون)' : 'المشتريات',
            'amount' => $totalPurchases,
            'style' => 'normal',
            'deduction' => false,
            'info_only' => $usesInventory,
        ];
    }

    $lineNo++;
    $rows[] = [
        'line_no' => $lineNo,
        'label' => 'تكلفة المبيعات',
        'amount' => $totalCogs,
        'style' => 'normal',
        'deduction' => true,
    ];
    $lineNo++;
    $rows[] = [
        'line_no' => $lineNo,
        'label' => 'مجمل الربح',
        'amount' => $grossProfit,
        'style' => 'subtotal',
        'deduction' => false,
        'pct' => $pct($grossProfit),
    ];
    $lineNo++;
    $rows[] = [
        'line_no' => $lineNo,
        'label' => 'المصروفات التشغيلية',
        'amount' => $totalOperating,
        'style' => 'normal',
        'deduction' => true,
    ];
    $lineNo++;
    $rows[] = [
        'line_no' => $lineNo,
        'label' => $isProfit ? 'صافي الربح' : 'صافي الخسارة',
        'amount' => $netIncome,
        'style' => 'total',
        'deduction' => false,
        'is_profit' => $isProfit,
        'pct' => $pct($netIncome),
    ];

    return $rows;
}

/** @param array<string, list<array<string, mixed>>> $sections @param array<string, float|bool> $totals @return list<array<string, mixed>> */
function acc_pl_comp_build_blocks(array $sections, array $totals): array
{
    $pctBase = (float) ($totals['pct_base'] ?? 1.0);
    $pct = static function (float $amount) use ($pctBase, $totals): ?float {
        if ($pctBase < 0.000001) {
            return null;
        }

        return round(($amount / $pctBase) * 100, 2);
    };

    $blocks = [];

    $blocks[] = [
        'kind' => 'section',
        'title' => 'أولاً: الإيرادات',
        'section_keys' => ['sales_revenue'],
    ];
    if ($sections['sales_returns'] !== []) {
        $blocks[] = [
            'kind' => 'section',
            'title' => '(-) مردودات المبيعات',
            'section_keys' => ['sales_returns'],
            'deduction' => true,
        ];
    }
    $blocks[] = [
        'kind' => 'subtotal',
        'title' => 'صافي المبيعات',
        'amount' => (float) $totals['net_sales'],
        'pct' => $pct((float) $totals['net_sales']),
        'emphasis' => 'primary',
    ];
    if ($sections['other_revenue'] !== []) {
        $blocks[] = [
            'kind' => 'section',
            'title' => 'إيرادات أخرى',
            'section_keys' => ['other_revenue'],
        ];
        $blocks[] = [
            'kind' => 'subtotal',
            'title' => 'إجمالي الإيرادات',
            'amount' => (float) $totals['total_revenue'],
            'pct' => $pct((float) $totals['total_revenue']),
            'emphasis' => 'primary',
        ];
    }

    $blocks[] = [
        'kind' => 'section',
        'title' => 'ثانياً: تكلفة المبيعات',
        'section_keys' => ['cogs', 'purchases'],
    ];
    if ($sections['purchase_returns'] !== []) {
        $blocks[] = [
            'kind' => 'section',
            'title' => '(-) مردودات المشتريات',
            'section_keys' => ['purchase_returns'],
            'deduction' => true,
        ];
    }
    $blocks[] = [
        'kind' => 'subtotal',
        'title' => 'إجمالي تكلفة المبيعات',
        'amount' => (float) $totals['total_cogs'],
        'pct' => $pct((float) $totals['total_cogs']),
        'emphasis' => 'cost',
    ];
    $blocks[] = [
        'kind' => 'subtotal',
        'title' => 'مجمل الربح',
        'amount' => (float) $totals['gross_profit'],
        'pct' => $pct((float) $totals['gross_profit']),
        'emphasis' => 'highlight',
    ];

    $blocks[] = [
        'kind' => 'section',
        'title' => 'ثالثاً: المصروفات التشغيلية',
        'section_keys' => ['salaries', 'general_admin', 'other_operating'],
    ];
    $blocks[] = [
        'kind' => 'subtotal',
        'title' => 'إجمالي المصروفات التشغيلية',
        'amount' => (float) $totals['total_operating'],
        'pct' => $pct((float) $totals['total_operating']),
        'emphasis' => 'cost',
    ];
    $blocks[] = [
        'kind' => 'subtotal',
        'title' => 'الربح التشغيلي',
        'amount' => (float) $totals['operating_income'],
        'pct' => $pct((float) $totals['operating_income']),
        'emphasis' => 'highlight',
    ];

    return $blocks;
}

/** @param array<string, list<array<string, mixed>>> $sections */
function acc_pl_comp_empty_result(string $dateFrom, string $dateTo, array $sections): array
{
    $totals = [
        'gross_sales' => 0.0,
        'sales_returns' => 0.0,
        'net_sales' => 0.0,
        'other_revenue' => 0.0,
        'total_revenue' => 0.0,
        'cogs' => 0.0,
        'purchases' => 0.0,
        'purchase_returns' => 0.0,
        'total_cogs' => 0.0,
        'gross_profit' => 0.0,
        'salaries' => 0.0,
        'general_admin' => 0.0,
        'other_operating' => 0.0,
        'total_operating' => 0.0,
        'operating_income' => 0.0,
        'net_income' => 0.0,
        'net_is_profit' => true,
        'pct_base' => 1.0,
    ];

    return [
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'sections' => $sections,
        'totals' => $totals,
        'blocks' => acc_pl_comp_build_blocks($sections, $totals),
        'summary_rows' => acc_pl_comp_summary_rows($totals),
    ];
}
