<?php
declare(strict_types=1);

require_once app_path('includes/sal_invoice_schema.php');
require_once app_path('includes/crm_customer_ledger.php');
require_once app_path('includes/crm_party_statement.php');
require_once app_path('includes/company_settings.php');
require_once app_path('includes/sal_period_sales.php');

/** تسمية المندوب في كشف الذمم: عميل بلا مندوب مربوط → «غير معروف». */
function sal_report_receivables_rep_label(array $customerRow): string
{
    $repId = (int) ($customerRow['sales_rep_id'] ?? 0);
    $name = trim((string) ($customerRow['sales_rep_name'] ?? ''));

    if ($repId < 1 || $name === '') {
        return 'غير معروف';
    }

    return $name;
}

/** عنوان عميل مع المندوب (للعرض التفصيلي والإجمالي). */
function sal_report_receivables_customer_with_rep(string $customerName, string $repLabel): string
{
    $customerName = trim($customerName);
    $repLabel = trim($repLabel) !== '' ? trim($repLabel) : 'غير معروف';

    if ($customerName === '') {
        return 'المندوب: ' . $repLabel;
    }

    return $customerName . ' — المندوب: ' . $repLabel;
}

/** نسبة مئوية للعرض (جزء ÷ كل × 100). */
function sal_report_receivables_format_pct(float $part, float $whole): string
{
    $eps = crm_party_statement_amount_epsilon();
    if ($whole <= $eps) {
        return '—';
    }

    return format_amount(round(($part / $whole) * 100, 2)) . '%';
}

/**
 * @param array{customer_id?:int,sales_rep_id?:int,from?:string,to?:string,mode?:string} $filters
 * @return array{
 *   mode:string,
 *   detail_rows:list<array<string,mixed>>,
 *   detail_groups:list<array{customer_id:int,customer_name:string,customer_code:string,rows:list<array<string,mixed>>}>,
 *   summary_rows:list<array<string,mixed>>,
 *   totals:array{
 *     sales_total:float,
 *     sales_total_all:float,
 *     collections_total:float,
 *     balance_due:float,
 *     collection_pct:string,
 *     balance_pct:string,
 *     invoice_count:int,
 *     customer_count:int
 *   }
 * }
 */
function sal_report_receivables_build(PDO $pdo, array $filters): array
{
    $customerId = max(0, (int) ($filters['customer_id'] ?? 0));
    $salesRepId = max(0, (int) ($filters['sales_rep_id'] ?? 0));
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
            'sales_total' => 0.0,
            'sales_total_all' => 0.0,
            'collections_total' => 0.0,
            'balance_due' => 0.0,
            'collection_pct' => '—',
            'balance_pct' => '—',
            'invoice_count' => 0,
            'customer_count' => 0,
        ],
    ];

    if ($from === '' || $to === '') {
        return $empty;
    }

    sal_invoice_ensure_schema($pdo);
    crm_ledger_ensure_schema($pdo);

    $hasRep = sal_invoice_column_exists($pdo, 'sal_invoice', 'sales_rep_id');
    $customers = sal_report_receivables_customer_ids($pdo, $customerId, $salesRepId, $from, $to, $hasRep);

    if ($customers === []) {
        return $empty;
    }

    $summaryRows = [];
    $grandSales = 0.0;
    $grandCollections = 0.0;
    $grandBalance = 0.0;
    $invoiceCount = 0;

    foreach ($customers as $cust) {
        $cid = (int) ($cust['id'] ?? 0);
        if ($cid < 1) {
            continue;
        }
        $balanceDue = crm_ledger_customer_balance_as_of($pdo, $cid, $to);
        if (!sal_report_receivables_has_balance_due($balanceDue)) {
            continue;
        }

        $salesTotal = sal_report_receivables_period_sales($pdo, $cid, $from, $to, $salesRepId, $hasRep);
        $collectionsTotal = sal_report_receivables_period_collections($pdo, $cid, $from, $to);

        $repLabel = sal_report_receivables_rep_label($cust);
        $custName = (string) ($cust['name_ar'] ?? '');
        $summaryRows[] = [
            'customer_id' => $cid,
            'customer_name' => $custName,
            'customer_code' => (string) ($cust['code'] ?? ''),
            'sales_rep_id' => (int) ($cust['sales_rep_id'] ?? 0),
            'sales_rep_name' => $repLabel,
            'customer_display' => sal_report_receivables_customer_with_rep($custName, $repLabel),
            'sales_total' => $salesTotal,
            'collections_total' => $collectionsTotal,
            'collection_pct' => sal_report_receivables_format_pct($collectionsTotal, $salesTotal),
            'balance_pct' => sal_report_receivables_format_pct($balanceDue, $salesTotal),
            'balance_due' => $balanceDue,
        ];
        $grandSales += $salesTotal;
        $grandCollections += $collectionsTotal;
        $grandBalance += $balanceDue;
    }

    $detailRows = [];
    $detailGroups = [];
    if ($mode === 'detail') {
        $detailBuilt = sal_report_receivables_build_detail_from_ledger(
            $pdo,
            $summaryRows,
            $from,
            $to,
            $salesRepId,
            $hasRep
        );
        $detailGroups = $detailBuilt['groups'];
        $detailRows = $detailBuilt['rows'];
        $invoiceCount = $detailBuilt['invoice_count'];
    } else {
        foreach ($summaryRows as $sr) {
            $invoiceCount += sal_report_receivables_credit_invoice_count(
                $pdo,
                (int) $sr['customer_id'],
                $from,
                $to,
                $salesRepId,
                $hasRep
            );
        }
    }

    $salesTotalAll = sal_period_net_sales_total($pdo, $from, $to, $customerId, $salesRepId);

    return [
        'mode' => $mode,
        'detail_rows' => $detailRows,
        'detail_groups' => $detailGroups,
        'summary_rows' => $summaryRows,
        'totals' => [
            'sales_total' => $grandSales,
            'sales_total_all' => $salesTotalAll,
            'collections_total' => $grandCollections,
            'balance_due' => $grandBalance,
            'collection_pct' => sal_report_receivables_format_pct($grandCollections, $grandSales),
            'balance_pct' => sal_report_receivables_format_pct($grandBalance, $grandSales),
            'invoice_count' => $invoiceCount,
            'customer_count' => count($summaryRows),
        ],
    ];
}

/**
 * التفصيلي: حركات دفتر العميل في الفترة (فواتير، دفعات، مرتجعات) كما في كشف الحساب.
 *
 * @param list<array<string,mixed>> $summaryRows
 * @return array{
 *   groups:list<array{
 *     customer_id:int,
 *     customer_name:string,
 *     customer_code:string,
 *     rows:list<array<string,mixed>>,
 *     metrics:array<string,mixed>
 *   }>,
 *   rows:list<array<string,mixed>>,
 *   invoice_count:int
 * }
 */
function sal_report_receivables_build_detail_from_ledger(
    PDO $pdo,
    array $summaryRows,
    string $from,
    string $to,
    int $salesRepId,
    bool $hasRep
): array {
    $groups = [];
    $flatRows = [];
    $invoiceCount = 0;
    $eps = crm_party_statement_amount_epsilon();

    foreach ($summaryRows as $sr) {
        $cid = (int) ($sr['customer_id'] ?? 0);
        if ($cid < 1) {
            continue;
        }

        $custName = (string) ($sr['customer_name'] ?? '');
        $custCode = (string) ($sr['customer_code'] ?? '');
        $repLabel = (string) ($sr['sales_rep_name'] ?? 'غير معروف');
        $custDisplay = (string) ($sr['customer_display'] ?? sal_report_receivables_customer_with_rep($custName, $repLabel));
        $stmt = crm_party_statement_build($pdo, 'customer', $cid, $from, $to);
        $stmt = sal_report_receivables_filter_statement_by_rep(
            $pdo,
            $stmt,
            $from,
            $to,
            $cid,
            $salesRepId,
            $hasRep
        );

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
            if (($row['txn_type'] ?? '') === 'sale_invoice') {
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
            'row_type' => 'customer_subtotal',
            'debit' => (float) $stmt['total_debit'],
            'credit' => (float) $stmt['total_credit'],
            'balance' => (float) $stmt['closing_balance'],
            'invoice_count' => sal_report_receivables_credit_invoice_count(
                $pdo,
                $cid,
                $from,
                $to,
                $salesRepId,
                $hasRep
            ),
        ];

        $groups[] = [
            'customer_id' => $cid,
            'customer_name' => $custName,
            'customer_code' => $custCode,
            'sales_rep_name' => $repLabel,
            'customer_display' => $custDisplay,
            'rows' => $groupRows,
            'metrics' => [
                'sales_total' => (float) ($sr['sales_total'] ?? 0),
                'collections_total' => (float) ($sr['collections_total'] ?? 0),
                'collection_pct' => (string) ($sr['collection_pct'] ?? sal_report_receivables_format_pct(
                    (float) ($sr['collections_total'] ?? 0),
                    (float) ($sr['sales_total'] ?? 0)
                )),
                'balance_due' => (float) ($sr['balance_due'] ?? 0),
                'balance_pct' => (string) ($sr['balance_pct'] ?? sal_report_receivables_format_pct(
                    (float) ($sr['balance_due'] ?? 0),
                    (float) ($sr['sales_total'] ?? 0)
                )),
            ],
        ];

        foreach ($groupRows as $gr) {
            $flatRows[] = array_merge($gr, [
                'customer_id' => $cid,
                'customer_name' => $custName,
                'customer_code' => $custCode,
                'sales_rep_name' => $repLabel,
                'customer_display' => $custDisplay,
            ]);
        }
    }

    return ['groups' => $groups, 'rows' => $flatRows, 'invoice_count' => $invoiceCount];
}

/**
 * عند فلتر المندوب: إخفاء فواتير البيع غير التابعة له مع إعادة حساب الأرصدة.
 *
 * @param array<string,mixed> $stmt
 * @return array<string,mixed>
 */
function sal_report_receivables_filter_statement_by_rep(
    PDO $pdo,
    array $stmt,
    string $from,
    string $to,
    int $customerId,
    int $salesRepId,
    bool $hasRep
): array {
    if ($salesRepId < 1 || !$hasRep || $customerId < 1) {
        return $stmt;
    }

    sal_invoice_ensure_schema($pdo);
    $st = $pdo->prepare(
        'SELECT ref_id FROM crm_customer_ledger
         WHERE customer_id = ? AND txn_type = \'sale_invoice\' AND txn_date >= ? AND txn_date <= ?'
    );
    $st->execute([$customerId, $from, $to]);
    $ledgerInvIds = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $lr) {
        $ledgerInvIds[(int) ($lr['ref_id'] ?? 0)] = true;
    }

    $repInvIds = [];
    if ($ledgerInvIds !== []) {
        $ids = array_keys($ledgerInvIds);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $q = $pdo->prepare(
            "SELECT id FROM sal_invoice
             WHERE id IN ({$ph}) AND customer_id = ? AND sales_rep_id = ?"
        );
        $params = array_merge($ids, [$customerId, $salesRepId]);
        $q->execute($params);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $repInvIds[(int) ($r['id'] ?? 0)] = true;
        }
    }

    $filtered = [];
    foreach ($stmt['rows'] as $row) {
        $txn = (string) ($row['txn_type'] ?? '');
        if ($txn === 'sale_invoice') {
            $refId = (int) ($row['ref_id'] ?? 0);
            if ($refId > 0 && !isset($repInvIds[$refId])) {
                continue;
            }
        }
        $filtered[] = $row;
    }

    if ($filtered === $stmt['rows']) {
        return $stmt;
    }

    $opening = (float) ($stmt['opening_balance'] ?? 0.0);
    $periodDebit = 0.0;
    $periodCredit = 0.0;
    $rebuilt = [];
    foreach ($filtered as $row) {
        $debit = (float) ($row['debit'] ?? 0);
        $credit = (float) ($row['credit'] ?? 0);
        $periodDebit += $debit;
        $periodCredit += $credit;
        $running = crm_party_statement_balance_from_totals('customer', $opening, $periodDebit, $periodCredit);
        $rebuilt[] = array_merge($row, ['balance' => $running]);
    }

    $stmt['rows'] = $rebuilt;
    $stmt['total_debit'] = $periodDebit;
    $stmt['total_credit'] = $periodCredit;
    $stmt['closing_balance'] = crm_party_statement_balance_from_totals(
        'customer',
        $opening,
        $periodDebit,
        $periodCredit
    );

    return $stmt;
}

/**
 * @return list<array{id:int,name_ar:string,code:string,sales_rep_id:int,sales_rep_name:string}>
 */
function sal_report_receivables_customer_ids(
    PDO $pdo,
    int $customerId,
    int $salesRepId,
    string $from,
    string $to,
    bool $hasRep
): array {
    $hasCustRep = sal_invoice_column_exists($pdo, 'crm_customer', 'sales_rep_id');
    $repJoin = $hasCustRep
        ? ' LEFT JOIN crm_sales_rep sr ON sr.id = c.sales_rep_id '
        : '';
    $repCols = $hasCustRep
        ? ', c.sales_rep_id, COALESCE(sr.name_ar, \'\') AS sales_rep_name '
        : ', NULL AS sales_rep_id, \'\' AS sales_rep_name ';

    $sql = "SELECT DISTINCT c.id, c.name_ar, c.code{$repCols}
            FROM crm_customer c{$repJoin}
            WHERE c.is_active = 1";
    $params = [];

    if ($customerId > 0) {
        $sql .= ' AND c.id = ?';
        $params[] = $customerId;
    }

    if ($salesRepId > 0 && $hasRep) {
        $sql .= ' AND EXISTS (
            SELECT 1 FROM sal_invoice i
            WHERE i.customer_id = c.id AND i.sales_rep_id = ?
        )';
        $params[] = $salesRepId;
    }

    $eps = crm_party_statement_amount_epsilon();
    $sql .= ' AND (
            SELECT COALESCE(SUM(l.debit), 0) - COALESCE(SUM(l.credit), 0)
            FROM crm_customer_ledger l
            WHERE l.customer_id = c.id AND l.txn_date <= ?
        ) > ?
        ORDER BY c.name_ar ASC';

    $params[] = $to;
    $params[] = $eps;

    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function sal_report_receivables_has_balance_due(float $balanceDue): bool
{
    return $balanceDue > crm_party_statement_amount_epsilon();
}

/** مبيعات الفترة للعميل — نفس دالة تقرير المبيعات (فواتير مرحّلة − مرتجعات مرحّلة). */
function sal_report_receivables_period_sales(
    PDO $pdo,
    int $customerId,
    string $from,
    string $to,
    int $salesRepId,
    bool $hasRep
): float {
    if ($customerId < 1 || $from === '' || $to === '') {
        return 0.0;
    }

    return sal_period_net_sales_total($pdo, $from, $to, $customerId, $salesRepId);
}

/** مجموع التحصيل في الفترة (سندات قبض ودفعات على حساب العميل). */
function sal_report_receivables_period_collections(
    PDO $pdo,
    int $customerId,
    string $from,
    string $to
): float {
    if ($customerId < 1 || $from === '' || $to === '') {
        return 0.0;
    }

    crm_ledger_ensure_schema($pdo);

    $st = $pdo->prepare(
        "SELECT COALESCE(SUM(l.credit), 0)
         FROM crm_customer_ledger l
         WHERE l.customer_id = ?
           AND l.txn_date >= ? AND l.txn_date <= ?
           AND l.txn_type IN ('cash_receipt', 'receipt_voucher', 'cheque', 'check')"
    );
    $st->execute([$customerId, $from, $to]);
    $ledgerTotal = (float) $st->fetchColumn();

    if (!crm_party_statement_fin_voucher_has_table($pdo)) {
        return $ledgerTotal;
    }

    require_once app_path('includes/fin_voucher_schema.php');
    $postedOnly = fin_voucher_has_column($pdo, 'is_posted') ? ' AND v.is_posted = 1' : '';
    $ledgerExclude = crm_ledger_has_table($pdo)
        ? " AND NOT EXISTS (
            SELECT 1 FROM crm_customer_ledger l
            WHERE l.customer_id = v.party_id
              AND l.txn_type IN ('cash_receipt','receipt_voucher')
              AND l.ref_id = v.id
        )"
        : '';

    $stV = $pdo->prepare(
        "SELECT COALESCE(SUM(v.amount), 0)
         FROM fin_voucher v
         WHERE v.party_type = 'customer'
           AND v.party_id = ?
           AND v.voucher_type = 'receipt'
           AND v.voucher_date >= ? AND v.voucher_date <= ?{$postedOnly}{$ledgerExclude}"
    );
    $stV->execute([$customerId, $from, $to]);

    return $ledgerTotal + (float) $stV->fetchColumn();
}

function sal_report_receivables_credit_invoice_count(
    PDO $pdo,
    int $customerId,
    string $from,
    string $to,
    int $salesRepId,
    bool $hasRep
): int {
    $sql = 'SELECT COUNT(*)
            FROM sal_invoice i
            WHERE i.customer_id = ?
              AND i.status = \'confirmed\'
              AND i.payment_type = \'credit\'
              AND i.invoice_date >= ? AND i.invoice_date <= ?';
    $params = [$customerId, $from, $to];

    if ($salesRepId > 0 && $hasRep) {
        $sql .= ' AND i.sales_rep_id = ?';
        $params[] = $salesRepId;
    }

    $st = $pdo->prepare($sql);
    $st->execute($params);

    return (int) $st->fetchColumn();
}
