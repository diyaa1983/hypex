<?php
declare(strict_types=1);

require_once app_path('includes/acc_gl.php');
require_once app_path('includes/acc_report.php');
require_once app_path('includes/inv_warehouse_financial_report.php');

/** معرّف حساب المشتريات المباشرة (بدون مخزون) من الربط. */
function acc_report_purchases_account_id(PDO $pdo): int
{
    $settings = acc_gl_load_settings($pdo);

    return (int) ($settings['purchases']['account_id'] ?? 0);
}

/**
 * تفصيل عرض المشتريات في ميزان المراجعة (حساب مخزون أو حساب مشتريات).
 *
 * @return array{
 *   account_id: int,
 *   gross: float,
 *   return_amount: float,
 *   net: float,
 *   gross_label: string,
 *   return_label: string,
 *   net_label: string,
 *   kind: string
 * }|null
 */
function acc_report_tb_purchases_period_detail(PDO $pdo, string $dateFrom, string $dateTo): ?array
{
    require_once app_path('includes/acc_report_vat_jordan.php');

    $invId = acc_report_inventory_account_id($pdo);
    if ($invId > 0) {
        $inv = acc_report_inventory_period_detail($pdo, $dateFrom, $dateTo, $invId);
        $gross = (float) ($inv['purchases_debit'] ?? 0);
        $returns = (float) ($inv['purchase_return_credit'] ?? 0);
        if ($gross > 0.0005 || $returns > 0.0005) {
            return [
                'account_id' => $invId,
                'gross' => round($gross, 6),
                'return_amount' => round($returns, 6),
                'net' => round($gross - $returns, 6),
                'gross_label' => 'مشتريات — فواتير شراء',
                'return_label' => 'مردودات مشتريات',
                'net_label' => 'صافي مشتريات الفترة (قبل تكلفة المبيعات)',
                'kind' => 'inventory',
            ];
        }
    }

    $purchId = acc_report_purchases_account_id($pdo);
    if ($purchId > 0 && $purchId !== $invId) {
        $byRef = acc_report_vat_by_ref_type($pdo, $purchId, $dateFrom, $dateTo);
        $gross = round((float) ($byRef['purchase_invoice']['debit'] ?? 0), 6);
        $returns = round((float) ($byRef['purchase_return']['credit'] ?? 0), 6);
        if ($gross > 0.0005 || $returns > 0.0005) {
            return [
                'account_id' => $purchId,
                'gross' => $gross,
                'return_amount' => $returns,
                'net' => round($gross - $returns, 6),
                'gross_label' => 'مشتريات — فواتير شراء',
                'return_label' => 'مردودات مشتريات',
                'net_label' => 'صافي مشتريات الفترة',
                'kind' => 'expense',
            ];
        }
    }

    return null;
}

/**
 * @return list<array{label:string, amount:float, side:string, emphasis?:bool, prefix?:string}>
 */
function acc_report_tb_inventory_detail_rows(PDO $pdo, int $accountId, string $dateFrom, string $dateTo): array
{
    $invId = acc_report_inventory_account_id($pdo);
    if ($accountId !== $invId || $invId < 1) {
        return [];
    }

    $inv = acc_report_inventory_period_detail($pdo, $dateFrom, $dateTo, $invId);
    $lines = [];

    if ((float) ($inv['purchases_debit'] ?? 0) > 0.0005) {
        $lines[] = [
            'label' => 'مشتريات — فواتير شراء',
            'amount' => (float) $inv['purchases_debit'],
            'side' => 'مدين',
        ];
    }
    if ((float) ($inv['purchase_return_credit'] ?? 0) > 0.0005) {
        $lines[] = [
            'label' => 'مردودات مشتريات',
            'amount' => (float) $inv['purchase_return_credit'],
            'side' => 'دائن',
            'prefix' => '− ',
        ];
    }
    if ((float) ($inv['sale_return_debit'] ?? 0) > 0.0005) {
        $lines[] = [
            'label' => 'مردود مبيعات (إرجاع للمخزون)',
            'amount' => (float) $inv['sale_return_debit'],
            'side' => 'مدين',
        ];
    }
    if ((float) ($inv['sale_cogs_credit'] ?? 0) > 0.0005) {
        $lines[] = [
            'label' => 'تكلفة البضاعة المباعة',
            'amount' => (float) $inv['sale_cogs_credit'],
            'side' => 'دائن',
            'prefix' => '− ',
        ];
    }
    if ((float) ($inv['reconcile_debit'] ?? 0) > 0.0005) {
        $lines[] = [
            'label' => 'تسوية مخزون (مدين)',
            'amount' => (float) $inv['reconcile_debit'],
            'side' => 'مدين',
        ];
    }
    if ((float) ($inv['reconcile_credit'] ?? 0) > 0.0005) {
        $lines[] = [
            'label' => 'تسوية مخزون (دائن)',
            'amount' => (float) $inv['reconcile_credit'],
            'side' => 'دائن',
            'prefix' => '− ',
        ];
    }
    if ((float) ($inv['other_debit'] ?? 0) > 0.0005) {
        $lines[] = [
            'label' => 'حركات أخرى (مدين)',
            'amount' => (float) $inv['other_debit'],
            'side' => 'مدين',
        ];
    }
    if ((float) ($inv['other_credit'] ?? 0) > 0.0005) {
        $lines[] = [
            'label' => 'حركات أخرى (دائن)',
            'amount' => (float) $inv['other_credit'],
            'side' => 'دائن',
            'prefix' => '− ',
        ];
    }

    if (!$lines) {
        return [];
    }

    $netPurch = round((float) $inv['purchases_debit'] - (float) $inv['purchase_return_credit'], 6);
    if (abs($netPurch) > 0.0005) {
        $lines[] = [
            'label' => 'صافي مشتريات الفترة',
            'amount' => abs($netPurch),
            'side' => $netPurch > 0 ? 'صافي مدين' : 'صافي دائن',
            'emphasis' => true,
        ];
    }

    $period = acc_report_account_sums($pdo, $accountId, $dateFrom, $dateTo, false);
    $net = round((float) ($period['balance'] ?? 0), 6);
    if (abs($net) > 0.0005) {
        $lines[] = [
            'label' => 'صافي حركة الفترة (المخزون)',
            'amount' => abs($net),
            'side' => $net > 0 ? 'ختامي مدين' : 'ختامي دائن',
            'emphasis' => true,
        ];
    }

    return $lines;
}

/** @return array{uses_inventory:bool, purchases:float, purchase_returns:float, inventory_account_id:int, inventory_code:string, inventory_name_ar:string} */
function acc_report_pl_inventory_purchase_amounts(PDO $pdo, string $dateFrom, string $dateTo): array
{
    $empty = [
        'uses_inventory' => false,
        'purchases' => 0.0,
        'purchase_returns' => 0.0,
        'inventory_account_id' => 0,
        'inventory_code' => '',
        'inventory_name_ar' => '',
    ];
    $invId = acc_report_inventory_account_id($pdo);
    if ($invId < 1) {
        return $empty;
    }

    $st = $pdo->prepare('SELECT code, name_ar FROM acc_account WHERE id = ? LIMIT 1');
    $st->execute([$invId]);
    $acc = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    $inv = acc_report_inventory_period_detail($pdo, $dateFrom, $dateTo, $invId);

    return [
        'uses_inventory' => true,
        'purchases' => round((float) ($inv['purchases_debit'] ?? 0), 6),
        'purchase_returns' => round((float) ($inv['purchase_return_credit'] ?? 0), 6),
        'inventory_account_id' => $invId,
        'inventory_code' => (string) ($acc['code'] ?? ''),
        'inventory_name_ar' => (string) ($acc['name_ar'] ?? 'المخزون'),
    ];
}

/**
 * @param list<array<string, mixed>> $purchaseRows
 * @param list<array<string, mixed>> $purchaseReturnRows
 */
function acc_report_pl_append_inventory_purchase_rows(
    PDO $pdo,
    string $dateFrom,
    string $dateTo,
    array &$purchaseRows,
    array &$purchaseReturnRows
): void {
    $info = acc_report_pl_inventory_purchase_amounts($pdo, $dateFrom, $dateTo);
    if (!$info['uses_inventory']) {
        return;
    }

    $existingPurch = 0.0;
    foreach ($purchaseRows as $row) {
        $existingPurch += abs((float) ($row['amount'] ?? 0));
    }
    if ($existingPurch < 0.0005 && $info['purchases'] > 0.0005) {
        $amt = (float) $info['purchases'];
        $purchaseRows[] = [
            'id' => (int) $info['inventory_account_id'],
            'code' => (string) $info['inventory_code'],
            'name_ar' => 'مشتريات — فواتير شراء (المخزون)',
            'account_type' => 'expense',
            'section' => 'purchases',
            'amount' => $amt,
            'signed' => $amt,
            'is_deduction' => false,
            'is_synthetic' => true,
            'inventory_info' => true,
        ];
    }

    $existingRet = 0.0;
    foreach ($purchaseReturnRows as $row) {
        $existingRet += abs((float) ($row['amount'] ?? 0));
    }
    if ($existingRet < 0.0005 && $info['purchase_returns'] > 0.0005) {
        $amt = (float) $info['purchase_returns'];
        $purchaseReturnRows[] = [
            'id' => (int) $info['inventory_account_id'],
            'code' => (string) $info['inventory_code'],
            'name_ar' => 'مردودات مشتريات (المخزون)',
            'account_type' => 'expense',
            'section' => 'purchase_returns',
            'amount' => -$amt,
            'signed' => -$amt,
            'is_deduction' => true,
            'is_synthetic' => true,
            'inventory_info' => true,
        ];
    }
}

/** @param list<array<string, mixed>> $rows */
function acc_report_pl_sum_row_amounts(array $rows): float
{
    $total = 0.0;
    foreach ($rows as $row) {
        $total += (float) ($row['amount'] ?? 0);
    }

    return round($total, 6);
}

/** معرّف حساب المخزون من الربط المحاسبي. */
function acc_report_inventory_account_id(PDO $pdo): int
{
    $settings = acc_gl_load_settings($pdo);

    return (int) ($settings['inventory']['account_id'] ?? 0);
}

/**
 * تفصيل حركة حساب المخزون حسب نوع المستند (للفترة).
 *
 * @return array{
 *   purchases_debit: float,
 *   sale_return_debit: float,
 *   purchase_return_credit: float,
 *   reconcile_debit: float,
 *   other_debit: float,
 *   sale_cogs_credit: float,
 *   reconcile_credit: float,
 *   other_credit: float,
 *   period_debit: float,
 *   period_credit: float,
 *   closing_balance: float,
 *   closing_debit: float,
 *   purchases_subtotal_report: float,
 *   warehouse_value: float,
 *   warehouse_gap: float
 * }
 */
function acc_report_inventory_period_detail(
    PDO $pdo,
    string $dateFrom,
    string $dateTo,
    ?int $inventoryAccountId = null
): array {
    $accountId = $inventoryAccountId ?? acc_report_inventory_account_id($pdo);
    $empty = [
        'purchases_debit' => 0.0,
        'sale_return_debit' => 0.0,
        'purchase_return_credit' => 0.0,
        'reconcile_debit' => 0.0,
        'other_debit' => 0.0,
        'sale_cogs_credit' => 0.0,
        'reconcile_credit' => 0.0,
        'other_credit' => 0.0,
        'period_debit' => 0.0,
        'period_credit' => 0.0,
        'closing_balance' => 0.0,
        'closing_debit' => 0.0,
        'purchases_subtotal_report' => 0.0,
        'warehouse_value' => 0.0,
        'warehouse_gap' => 0.0,
    ];
    if ($accountId < 1 || !acc_journal_has_tables($pdo)) {
        return $empty;
    }

    $st = $pdo->prepare(
        'SELECT e.ref_type,
                COALESCE(SUM(l.debit), 0) AS sum_debit,
                COALESCE(SUM(l.credit), 0) AS sum_credit
         FROM acc_journal_line l
         INNER JOIN acc_journal_entry e ON e.id = l.journal_id
         WHERE l.account_id = ?
           AND e.status = \'posted\'
           AND e.entry_date >= ?
           AND e.entry_date <= ?
         GROUP BY e.ref_type'
    );
    $st->execute([$accountId, $dateFrom, $dateTo]);
    $byRef = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $byRef[(string) ($row['ref_type'] ?? '')] = [
            'debit' => (float) ($row['sum_debit'] ?? 0),
            'credit' => (float) ($row['sum_credit'] ?? 0),
        ];
    }

    $purchasesDebit = (float) ($byRef['purchase_invoice']['debit'] ?? 0);
    $saleReturnDebit = (float) ($byRef['sale_return']['debit'] ?? 0);
    $purchaseReturnCredit = (float) ($byRef['purchase_return']['credit'] ?? 0);
    $reconcileDebit = (float) ($byRef['inventory_reconcile']['debit'] ?? 0);
    $saleCogsCredit = (float) ($byRef['sale_invoice']['credit'] ?? 0);
    $reconcileCredit = (float) ($byRef['inventory_reconcile']['credit'] ?? 0);

    $period = acc_report_account_sums($pdo, $accountId, $dateFrom, $dateTo, false);
    $opening = acc_report_account_sums($pdo, $accountId, $dateFrom, null, true);
    $closingBal = round($opening['balance'] + $period['balance'], 6);
    $closeSplit = acc_report_split_balance($closingBal);

    $otherDebit = max(0, $period['sum_debit'] - $purchasesDebit - $saleReturnDebit - $reconcileDebit);
    $otherCredit = max(
        0,
        $period['sum_credit'] - $saleCogsCredit - $purchaseReturnCredit - $reconcileCredit
    );

    $warehouse = inv_warehouse_financial_grand_total($pdo, $dateTo);

    return [
        'purchases_debit' => round($purchasesDebit, 6),
        'sale_return_debit' => round($saleReturnDebit, 6),
        'purchase_return_credit' => round($purchaseReturnCredit, 6),
        'reconcile_debit' => round($reconcileDebit, 6),
        'other_debit' => round($otherDebit, 6),
        'sale_cogs_credit' => round($saleCogsCredit, 6),
        'reconcile_credit' => round($reconcileCredit, 6),
        'other_credit' => round($otherCredit, 6),
        'period_debit' => round($period['sum_debit'], 6),
        'period_credit' => round($period['sum_credit'], 6),
        'closing_balance' => $closingBal,
        'closing_debit' => $closeSplit['debit'],
        'purchases_subtotal_report' => acc_report_purchases_subtotal_sum($pdo, $dateFrom, $dateTo),
        'warehouse_value' => round($warehouse, 6),
        'warehouse_gap' => round($closingBal - $warehouse, 6),
    ];
}

/** مجموع subtotal فواتير الشراء المرحّلة (مطابق لتقرير المشتريات غير شامل). */
function acc_report_purchases_subtotal_sum(PDO $pdo, string $dateFrom, string $dateTo): float
{
    require_once app_path('includes/pur_invoice_schema.php');
    pur_invoice_ensure_schema($pdo);

    try {
        $st = $pdo->prepare(
            "SELECT COALESCE(SUM(i.subtotal), 0)
             FROM pur_invoice i
             WHERE i.status = 'confirmed'
               AND i.invoice_date >= ?
               AND i.invoice_date <= ?
               AND EXISTS (
                   SELECT 1 FROM crm_supplier_ledger l
                   WHERE l.txn_type = 'purchase_invoice' AND l.ref_id = i.id
               )"
        );
        $st->execute([$dateFrom, $dateTo]);

        return round(max(0, (float) $st->fetchColumn()), 6);
    } catch (Throwable $e) {
        return 0.0;
    }
}
