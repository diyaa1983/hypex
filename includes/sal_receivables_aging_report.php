<?php
declare(strict_types=1);

require_once app_path('includes/sal_receivables_report.php');

function sal_receivables_aging_bucket_key(int $days): string
{
    if ($days <= 30) {
        return 'd0_30';
    }
    if ($days <= 60) {
        return 'd31_60';
    }
    if ($days <= 90) {
        return 'd61_90';
    }

    return 'd90_plus';
}

/** @return array<string, string> */
function sal_receivables_aging_bucket_labels(): array
{
    return [
        'd0_30' => '0 – 30 يوم',
        'd31_60' => '31 – 60 يوم',
        'd61_90' => '61 – 90 يوم',
        'd90_plus' => 'أكثر من 90 يوم',
    ];
}

function sal_receivables_aging_empty_buckets(): array
{
    return [
        'd0_30' => 0.0,
        'd31_60' => 0.0,
        'd61_90' => 0.0,
        'd90_plus' => 0.0,
        'total' => 0.0,
    ];
}

function sal_receivables_aging_txn_label(string $txnType): string
{
    return match (strtolower(trim($txnType))) {
        'sale_invoice' => 'فاتورة بيع',
        'sale_return' => 'مرتجع بيع',
        'cash_receipt', 'receipt_voucher' => 'سند قبض',
        'cash_payment', 'payment_voucher' => 'سند صرف',
        'debit_note' => 'إشعار مدين',
        'credit_note' => 'إشعار دائن',
        default => 'حركة',
    };
}

/**
 * @param list<array{date:string,remaining:float,ref_no:string,description:string,txn_type:string}> $openItems
 */
function sal_receivables_aging_apply_credit(float $amount, array &$openItems): void
{
    $eps = crm_party_statement_amount_epsilon();
    $left = $amount;
    if ($left <= $eps) {
        return;
    }

    foreach ($openItems as &$item) {
        if ($left <= $eps) {
            break;
        }
        if ($item['remaining'] <= $eps) {
            continue;
        }
        $apply = min($left, $item['remaining']);
        $item['remaining'] -= $apply;
        $left -= $apply;
    }
    unset($item);
}

/**
 * @param list<array{date:string,remaining:float,ref_no:string,description:string,txn_type:string}> $openItems
 * @return array{d0_30:float,d31_60:float,d61_90:float,d90_plus:float,total:float,detail_lines:list<array<string,mixed>>}
 */
function sal_receivables_aging_buckets_from_open(array $openItems, string $asOf): array
{
    $buckets = sal_receivables_aging_empty_buckets();
    $detailLines = [];
    $eps = crm_party_statement_amount_epsilon();
    $asOfDt = new DateTimeImmutable($asOf);

    foreach ($openItems as $item) {
        $remaining = round((float) ($item['remaining'] ?? 0), 6);
        if ($remaining <= $eps) {
            continue;
        }

        $txnDate = trim((string) ($item['date'] ?? ''));
        if ($txnDate === '') {
            continue;
        }

        try {
            $start = new DateTimeImmutable($txnDate);
        } catch (Throwable $e) {
            continue;
        }

        $days = max(0, (int) $start->diff($asOfDt)->days);
        $key = sal_receivables_aging_bucket_key($days);
        $buckets[$key] += $remaining;
        $buckets['total'] += $remaining;

        $detailLines[] = [
            'date' => $txnDate,
            'ref_no' => (string) ($item['ref_no'] ?? ''),
            'description' => (string) ($item['description'] ?? ''),
            'days' => $days,
            'bucket' => $key,
            'bucket_label' => sal_receivables_aging_bucket_labels()[$key] ?? $key,
            'amount' => $remaining,
        ];
    }

    return [
        'd0_30' => $buckets['d0_30'],
        'd31_60' => $buckets['d31_60'],
        'd61_90' => $buckets['d61_90'],
        'd90_plus' => $buckets['d90_plus'],
        'total' => $buckets['total'],
        'detail_lines' => $detailLines,
    ];
}

/**
 * @return array{d0_30:float,d31_60:float,d61_90:float,d90_plus:float,total:float,detail_lines:list<array<string,mixed>>}
 */
function sal_receivables_aging_customer_buckets(PDO $pdo, int $customerId, string $asOf): array
{
    $empty = sal_receivables_aging_empty_buckets();
    $empty['detail_lines'] = [];

    if ($customerId < 1 || $asOf === '') {
        return $empty;
    }

    crm_ledger_ensure_schema($pdo);
    $rows = crm_ledger_fetch_customer($pdo, $customerId, null, $asOf);
    if ($rows === []) {
        return $empty;
    }

    $eps = crm_party_statement_amount_epsilon();
    /** @var list<array{date:string,remaining:float,ref_no:string,description:string,txn_type:string}> $openItems */
    $openItems = [];

    foreach ($rows as $row) {
        $display = crm_party_statement_row_display_amounts(
            'customer',
            (float) ($row['debit'] ?? 0),
            (float) ($row['credit'] ?? 0),
            (string) ($row['txn_type'] ?? ''),
            (string) ($row['payment_type'] ?? '')
        );
        if ($display['skip']) {
            continue;
        }

        $txnDate = (string) ($row['txn_date'] ?? '');
        $refNo = trim((string) ($row['ref_no'] ?? ''));
        $memo = trim((string) ($row['memo'] ?? ''));
        $txnType = (string) ($row['txn_type'] ?? '');
        $desc = $memo !== '' ? $memo : sal_receivables_aging_txn_label($txnType);

        if ($display['debit'] > $eps) {
            $openItems[] = [
                'date' => $txnDate,
                'remaining' => $display['debit'],
                'ref_no' => $refNo,
                'description' => $desc,
                'txn_type' => $txnType,
            ];
        }

        if ($display['credit'] > $eps) {
            sal_receivables_aging_apply_credit($display['credit'], $openItems);
        }
    }

    return sal_receivables_aging_buckets_from_open($openItems, $asOf);
}

/**
 * @param array{customer_id?:int,sales_rep_id?:int,as_of?:string,mode?:string} $filters
 * @return array{
 *   mode:string,
 *   as_of:string,
 *   summary_rows:list<array<string,mixed>>,
 *   detail_groups:list<array<string,mixed>>,
 *   totals:array{d0_30:float,d31_60:float,d61_90:float,d90_plus:float,total:float},
 *   customer_count:int
 * }
 */
function sal_report_receivables_aging_build(PDO $pdo, array $filters): array
{
    $customerId = max(0, (int) ($filters['customer_id'] ?? 0));
    $salesRepId = max(0, (int) ($filters['sales_rep_id'] ?? 0));
    $asOf = trim((string) ($filters['as_of'] ?? ''));
    $mode = strtolower(trim((string) ($filters['mode'] ?? 'summary')));
    if ($mode !== 'detail') {
        $mode = 'summary';
    }

    $emptyTotals = sal_receivables_aging_empty_buckets();
    unset($emptyTotals['total']);
    $empty = [
        'mode' => $mode,
        'as_of' => $asOf,
        'summary_rows' => [],
        'detail_groups' => [],
        'totals' => $emptyTotals,
        'customer_count' => 0,
    ];

    if ($asOf === '') {
        return $empty;
    }

    sal_invoice_ensure_schema($pdo);
    crm_ledger_ensure_schema($pdo);

    $hasRep = sal_invoice_column_exists($pdo, 'sal_invoice', 'sales_rep_id');
    $customers = sal_report_receivables_customer_ids($pdo, $customerId, $salesRepId, $asOf, $asOf, $hasRep);

    $summaryRows = [];
    $detailGroups = [];
    $totals = sal_receivables_aging_empty_buckets();
    unset($totals['total']);

    foreach ($customers as $cust) {
        $cid = (int) ($cust['id'] ?? 0);
        if ($cid < 1) {
            continue;
        }

        $balanceDue = crm_ledger_customer_balance_as_of($pdo, $cid, $asOf);
        if (!sal_report_receivables_has_balance_due($balanceDue)) {
            continue;
        }

        $aged = sal_receivables_aging_customer_buckets($pdo, $cid, $asOf);
        if ($aged['total'] <= crm_party_statement_amount_epsilon()) {
            continue;
        }

        $repLabel = sal_report_receivables_rep_label($cust);
        $custName = (string) ($cust['name_ar'] ?? '');

        $row = [
            'customer_id' => $cid,
            'customer_name' => $custName,
            'customer_code' => (string) ($cust['code'] ?? ''),
            'sales_rep_name' => $repLabel,
            'customer_display' => sal_report_receivables_customer_with_rep($custName, $repLabel),
            'd0_30' => $aged['d0_30'],
            'd31_60' => $aged['d31_60'],
            'd61_90' => $aged['d61_90'],
            'd90_plus' => $aged['d90_plus'],
            'total' => $aged['total'],
            'ledger_balance' => $balanceDue,
        ];
        $summaryRows[] = $row;

        if ($mode === 'detail') {
            $detailGroups[] = [
                'customer_id' => $cid,
                'customer_name' => $custName,
                'sales_rep_name' => $repLabel,
                'customer_display' => $row['customer_display'],
                'lines' => $aged['detail_lines'],
                'd0_30' => $aged['d0_30'],
                'd31_60' => $aged['d31_60'],
                'd61_90' => $aged['d61_90'],
                'd90_plus' => $aged['d90_plus'],
                'total' => $aged['total'],
            ];
        }

        $totals['d0_30'] += $aged['d0_30'];
        $totals['d31_60'] += $aged['d31_60'];
        $totals['d61_90'] += $aged['d61_90'];
        $totals['d90_plus'] += $aged['d90_plus'];
    }

    $totals['total'] = $totals['d0_30'] + $totals['d31_60'] + $totals['d61_90'] + $totals['d90_plus'];

    return [
        'mode' => $mode,
        'as_of' => $asOf,
        'summary_rows' => $summaryRows,
        'detail_groups' => $detailGroups,
        'totals' => $totals,
        'customer_count' => count($summaryRows),
    ];
}
