<?php
declare(strict_types=1);

require_once app_path('includes/pur_invoice_schema.php');
require_once app_path('includes/crm_supplier_ledger.php');
require_once app_path('includes/crm_party_statement.php');
require_once app_path('includes/company_settings.php');

function pur_report_payables_format_pct(float $part, float $whole): string
{
    $eps = crm_party_statement_amount_epsilon();
    if ($whole <= $eps) {
        return '—';
    }

    return format_amount(round(($part / $whole) * 100, 2)) . '%';
}

/**
 * @param array{supplier_id?:int,from?:string,to?:string,mode?:string} $filters
 * @return array{
 *   mode:string,
 *   detail_rows:list<array<string,mixed>>,
 *   detail_groups:list<array<string,mixed>>,
 *   summary_rows:list<array<string,mixed>>,
 *   totals:array{
 *     purchases_total:float,
 *     payments_total:float,
 *     balance_due:float,
 *     payment_pct:string,
 *     balance_pct:string,
 *     invoice_count:int,
 *     supplier_count:int
 *   }
 * }
 */
function pur_report_payables_build(PDO $pdo, array $filters): array
{
    $supplierId = max(0, (int) ($filters['supplier_id'] ?? 0));
    $from = trim((string) ($filters['from'] ?? ''));
    $to = trim((string) ($filters['to'] ?? ''));
    $mode = strtolower(trim((string) ($filters['mode'] ?? 'detail')));
    if ($mode !== 'summary') {
        $mode = 'detail';
    }

    $empty = [
        'mode' => $mode,
        'detail_rows' => [],
        'detail_groups' => [],
        'summary_rows' => [],
        'totals' => [
            'purchases_total' => 0.0,
            'payments_total' => 0.0,
            'balance_due' => 0.0,
            'payment_pct' => '—',
            'balance_pct' => '—',
            'invoice_count' => 0,
            'supplier_count' => 0,
        ],
    ];

    if ($from === '' || $to === '') {
        return $empty;
    }

    pur_invoice_ensure_schema($pdo);
    crm_supplier_ledger_ensure_schema($pdo);

    $suppliers = pur_report_payables_supplier_ids($pdo, $supplierId, $to);
    if ($suppliers === []) {
        return $empty;
    }

    $summaryRows = [];
    $grandPurchases = 0.0;
    $grandPayments = 0.0;
    $grandBalance = 0.0;
    $invoiceCount = 0;

    foreach ($suppliers as $sup) {
        $sid = (int) ($sup['id'] ?? 0);
        if ($sid < 1) {
            continue;
        }
        $balanceDue = crm_supplier_ledger_balance_as_of($pdo, $sid, $to);
        if (!pur_report_payables_has_balance_due($balanceDue)) {
            continue;
        }

        $purchasesTotal = pur_report_payables_period_purchases($pdo, $sid, $from, $to);
        $paymentsTotal = pur_report_payables_period_payments($pdo, $sid, $from, $to);
        $supName = (string) ($sup['name_ar'] ?? '');

        $summaryRows[] = [
            'supplier_id' => $sid,
            'supplier_name' => $supName,
            'supplier_code' => (string) ($sup['code'] ?? ''),
            'supplier_display' => $supName,
            'purchases_total' => $purchasesTotal,
            'payments_total' => $paymentsTotal,
            'payment_pct' => pur_report_payables_format_pct($paymentsTotal, $purchasesTotal),
            'balance_pct' => pur_report_payables_format_pct($balanceDue, $purchasesTotal),
            'balance_due' => $balanceDue,
        ];
        $grandPurchases += $purchasesTotal;
        $grandPayments += $paymentsTotal;
        $grandBalance += $balanceDue;
    }

    $detailRows = [];
    $detailGroups = [];
    if ($mode === 'detail') {
        $detailBuilt = pur_report_payables_build_detail_from_ledger($pdo, $summaryRows, $from, $to);
        $detailGroups = $detailBuilt['groups'];
        $detailRows = $detailBuilt['rows'];
        $invoiceCount = $detailBuilt['invoice_count'];
    } else {
        foreach ($summaryRows as $sr) {
            $invoiceCount += pur_report_payables_credit_invoice_count(
                $pdo,
                (int) $sr['supplier_id'],
                $from,
                $to
            );
        }
    }

    return [
        'mode' => $mode,
        'detail_rows' => $detailRows,
        'detail_groups' => $detailGroups,
        'summary_rows' => $summaryRows,
        'totals' => [
            'purchases_total' => $grandPurchases,
            'payments_total' => $grandPayments,
            'balance_due' => $grandBalance,
            'payment_pct' => pur_report_payables_format_pct($grandPayments, $grandPurchases),
            'balance_pct' => pur_report_payables_format_pct($grandBalance, $grandPurchases),
            'invoice_count' => $invoiceCount,
            'supplier_count' => count($summaryRows),
        ],
    ];
}

/**
 * @param list<array<string,mixed>> $summaryRows
 * @return array{groups:list<array<string,mixed>>,rows:list<array<string,mixed>>,invoice_count:int}
 */
function pur_report_payables_build_detail_from_ledger(
    PDO $pdo,
    array $summaryRows,
    string $from,
    string $to
): array {
    $groups = [];
    $flatRows = [];
    $invoiceCount = 0;
    $eps = crm_party_statement_amount_epsilon();

    foreach ($summaryRows as $sr) {
        $sid = (int) ($sr['supplier_id'] ?? 0);
        if ($sid < 1) {
            continue;
        }

        $supName = (string) ($sr['supplier_name'] ?? '');
        $supCode = (string) ($sr['supplier_code'] ?? '');
        $supDisplay = (string) ($sr['supplier_display'] ?? $supName);
        $stmt = crm_party_statement_build($pdo, 'supplier', $sid, $from, $to);

        $hasOpening = $from !== ''
            && (abs((float) $stmt['opening_balance']) >= $eps
                || (float) $stmt['opening_debit'] > $eps
                || (float) $stmt['opening_credit'] > $eps);
        $hasMovements = ($stmt['rows'] ?? []) !== [];

        if (!$hasOpening && !$hasMovements) {
            continue;
        }

        $groupRows = [];

        if ($hasOpening) {
            $groupRows[] = [
                'row_type' => 'opening',
                'date' => $from,
                'description' => 'رصيد افتتاحي',
                'ref_no' => '—',
                'debit' => (float) $stmt['opening_debit'],
                'credit' => (float) $stmt['opening_credit'],
                'balance' => (float) $stmt['opening_balance'],
            ];
        }

        foreach ($stmt['rows'] as $row) {
            if (($row['txn_type'] ?? '') === 'purchase_invoice') {
                $invoiceCount++;
            }
            $groupRows[] = [
                'row_type' => 'ledger',
                'date' => (string) ($row['date'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'ref_no' => (string) ($row['ref_no'] ?? ''),
                'debit' => (float) ($row['debit'] ?? 0),
                'credit' => (float) ($row['credit'] ?? 0),
                'balance' => (float) ($row['balance'] ?? 0),
            ];
        }

        $groupRows[] = [
            'row_type' => 'supplier_subtotal',
            'debit' => (float) $stmt['total_debit'],
            'credit' => (float) $stmt['total_credit'],
            'balance' => (float) $stmt['closing_balance'],
            'invoice_count' => pur_report_payables_credit_invoice_count($pdo, $sid, $from, $to),
        ];

        $groups[] = [
            'supplier_id' => $sid,
            'supplier_name' => $supName,
            'supplier_code' => $supCode,
            'supplier_display' => $supDisplay,
            'rows' => $groupRows,
            'metrics' => [
                'purchases_total' => (float) ($sr['purchases_total'] ?? 0),
                'payments_total' => (float) ($sr['payments_total'] ?? 0),
                'payment_pct' => (string) ($sr['payment_pct'] ?? pur_report_payables_format_pct(
                    (float) ($sr['payments_total'] ?? 0),
                    (float) ($sr['purchases_total'] ?? 0)
                )),
                'balance_due' => (float) ($sr['balance_due'] ?? 0),
                'balance_pct' => (string) ($sr['balance_pct'] ?? pur_report_payables_format_pct(
                    (float) ($sr['balance_due'] ?? 0),
                    (float) ($sr['purchases_total'] ?? 0)
                )),
            ],
        ];

        foreach ($groupRows as $gr) {
            $flatRows[] = array_merge($gr, [
                'supplier_id' => $sid,
                'supplier_name' => $supName,
                'supplier_code' => $supCode,
                'supplier_display' => $supDisplay,
            ]);
        }
    }

    return ['groups' => $groups, 'rows' => $flatRows, 'invoice_count' => $invoiceCount];
}

/** @return list<array{id:int,name_ar:string,code:string}> */
function pur_report_payables_supplier_ids(PDO $pdo, int $supplierId, string $to): array
{
    $sql = 'SELECT DISTINCT s.id, s.name_ar, s.code
            FROM crm_supplier s
            WHERE s.is_active = 1';
    $params = [];

    if ($supplierId > 0) {
        $sql .= ' AND s.id = ?';
        $params[] = $supplierId;
    }

    $eps = crm_party_statement_amount_epsilon();
    $sql .= ' AND (
            SELECT COALESCE(SUM(l.credit), 0) - COALESCE(SUM(l.debit), 0)
            FROM crm_supplier_ledger l
            WHERE l.supplier_id = s.id AND l.txn_date <= ?
        ) > ?
        ORDER BY s.name_ar ASC';

    $params[] = $to;
    $params[] = $eps;

    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function pur_report_payables_has_balance_due(float $balanceDue): bool
{
    return $balanceDue > crm_party_statement_amount_epsilon();
}

function pur_report_payables_period_purchases(PDO $pdo, int $supplierId, string $from, string $to): float
{
    $sql = 'SELECT COALESCE(SUM(i.total), 0)
            FROM pur_invoice i
            WHERE i.supplier_id = ?
              AND i.status = \'confirmed\'
              AND i.invoice_date >= ? AND i.invoice_date <= ?';
    $st = $pdo->prepare($sql);
    $st->execute([$supplierId, $from, $to]);

    return (float) $st->fetchColumn();
}

/** مجموع المدفوعات للمورد في الفترة (سندات صرف ودفعات على الذمة). */
function pur_report_payables_period_payments(PDO $pdo, int $supplierId, string $from, string $to): float
{
    if ($supplierId < 1 || $from === '' || $to === '') {
        return 0.0;
    }

    crm_supplier_ledger_ensure_schema($pdo);

    $st = $pdo->prepare(
        "SELECT COALESCE(SUM(l.debit), 0)
         FROM crm_supplier_ledger l
         WHERE l.supplier_id = ?
           AND l.txn_date >= ? AND l.txn_date <= ?
           AND l.txn_type IN ('cash_payment', 'payment_voucher', 'check')"
    );
    $st->execute([$supplierId, $from, $to]);
    $ledgerTotal = (float) $st->fetchColumn();

    if (!crm_party_statement_fin_voucher_has_table($pdo)) {
        return $ledgerTotal;
    }

    require_once app_path('includes/fin_voucher_schema.php');
    $postedOnly = fin_voucher_has_column($pdo, 'is_posted') ? ' AND v.is_posted = 1' : '';
    $ledgerExclude = crm_supplier_ledger_has_table($pdo)
        ? " AND NOT EXISTS (
            SELECT 1 FROM crm_supplier_ledger l
            WHERE l.supplier_id = v.party_id
              AND l.txn_type = 'cash_payment'
              AND l.ref_id = v.id
        )"
        : '';

    $stV = $pdo->prepare(
        "SELECT COALESCE(SUM(v.amount), 0)
         FROM fin_voucher v
         WHERE v.party_type = 'supplier'
           AND v.party_id = ?
           AND v.voucher_type = 'payment'
           AND v.voucher_date >= ? AND v.voucher_date <= ?{$postedOnly}{$ledgerExclude}"
    );
    $stV->execute([$supplierId, $from, $to]);

    return $ledgerTotal + (float) $stV->fetchColumn();
}

function pur_report_payables_credit_invoice_count(PDO $pdo, int $supplierId, string $from, string $to): int
{
    if (!pur_invoice_has_payment_type($pdo)) {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM pur_invoice i
             WHERE i.supplier_id = ? AND i.status = \'confirmed\'
               AND i.invoice_date >= ? AND i.invoice_date <= ?'
        );
        $st->execute([$supplierId, $from, $to]);

        return (int) $st->fetchColumn();
    }

    $st = $pdo->prepare(
        'SELECT COUNT(*)
         FROM pur_invoice i
         WHERE i.supplier_id = ?
           AND i.status = \'confirmed\'
           AND i.payment_type = \'credit\'
           AND i.invoice_date >= ? AND i.invoice_date <= ?'
    );
    $st->execute([$supplierId, $from, $to]);

    return (int) $st->fetchColumn();
}
