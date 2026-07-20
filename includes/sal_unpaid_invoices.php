<?php
declare(strict_types=1);

/**
 * فواتير البيع التي ما زال عليها رصيد مستحق (بعد تطبيق التحصيلات FIFO على دفتر العميل).
 *
 * @return list<array{
 *   invoice_id:int,
 *   invoice_no:string,
 *   invoice_date:string,
 *   customer_id:int,
 *   customer_code:string,
 *   customer_name:string,
 *   original:float,
 *   remaining:float
 * }>
 */
function sal_unpaid_invoices_list(PDO $pdo): array
{
    require_once app_path('includes/crm_customer_ledger.php');
    crm_ledger_ensure_schema($pdo);

    try {
        $st = $pdo->query(
            'SELECT l.customer_id, l.txn_type, l.ref_id, l.ref_no, l.txn_date, l.debit, l.credit,
                    c.code AS customer_code, c.name_ar AS customer_name
             FROM crm_customer_ledger l
             INNER JOIN crm_customer c ON c.id = l.customer_id
             ORDER BY l.customer_id ASC, l.txn_date ASC, l.id ASC'
        );
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $eps = 0.0005;
    $unpaid = [];
    $currentCustomer = 0;
    /** @var list<array{remaining:float, invoice_id:int, invoice_no:string, invoice_date:string, original:float, customer_id:int, customer_code:string, customer_name:string}> $open */
    $open = [];

    $flushCustomer = static function () use (&$open, &$unpaid, $eps): void {
        foreach ($open as $item) {
            $remaining = (float) ($item['remaining'] ?? 0);
            $invoiceId = (int) ($item['invoice_id'] ?? 0);
            if ($invoiceId > 0 && $remaining > $eps) {
                $unpaid[] = [
                    'invoice_id' => $invoiceId,
                    'invoice_no' => (string) ($item['invoice_no'] ?? ''),
                    'invoice_date' => (string) ($item['invoice_date'] ?? ''),
                    'customer_id' => (int) ($item['customer_id'] ?? 0),
                    'customer_code' => (string) ($item['customer_code'] ?? ''),
                    'customer_name' => (string) ($item['customer_name'] ?? ''),
                    'original' => (float) ($item['original'] ?? 0),
                    'remaining' => round($remaining, 3),
                ];
            }
        }
        $open = [];
    };

    foreach ($rows as $row) {
        $cid = (int) ($row['customer_id'] ?? 0);
        if ($cid < 1) {
            continue;
        }
        if ($cid !== $currentCustomer) {
            $flushCustomer();
            $currentCustomer = $cid;
        }

        $debit = (float) ($row['debit'] ?? 0);
        $credit = (float) ($row['credit'] ?? 0);
        $txnType = (string) ($row['txn_type'] ?? '');

        if ($debit > $eps) {
            $isInvoice = $txnType === 'sale_invoice';
            $open[] = [
                'remaining' => $debit,
                'original' => $debit,
                'invoice_id' => $isInvoice ? (int) ($row['ref_id'] ?? 0) : 0,
                'invoice_no' => $isInvoice ? trim((string) ($row['ref_no'] ?? '')) : '',
                'invoice_date' => (string) ($row['txn_date'] ?? ''),
                'customer_id' => $cid,
                'customer_code' => (string) ($row['customer_code'] ?? ''),
                'customer_name' => (string) ($row['customer_name'] ?? ''),
            ];
        }

        if ($credit > $eps) {
            $left = $credit;
            foreach ($open as &$item) {
                if ($left <= $eps) {
                    break;
                }
                $apply = min($left, (float) $item['remaining']);
                $item['remaining'] -= $apply;
                $left -= $apply;
            }
            unset($item);
        }
    }
    $flushCustomer();

    usort($unpaid, static function (array $a, array $b): int {
        $da = (string) ($a['invoice_date'] ?? '');
        $db = (string) ($b['invoice_date'] ?? '');
        if ($da !== $db) {
            return $db <=> $da;
        }

        return ((int) ($b['invoice_id'] ?? 0)) <=> ((int) ($a['invoice_id'] ?? 0));
    });

    return $unpaid;
}

function sal_unpaid_invoices_count(PDO $pdo): int
{
    return count(sal_unpaid_invoices_list($pdo));
}
