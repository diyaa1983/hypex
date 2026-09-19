<?php
declare(strict_types=1);

/**
 * فواتير الشراء التي ما زال عليها رصيد مستحق للمورد.
 * نفس منطق FIFO: لكل مورد تُجمع التزامات الفواتير (الدائن الصافي)
 * ثم تُخصم المدفوعات/المرتجعات من الأقدم إلى الأحدث.
 *
 * @return list<array{
 *   invoice_id:int,
 *   invoice_no:string,
 *   invoice_date:string,
 *   supplier_id:int,
 *   supplier_code:string,
 *   supplier_name:string,
 *   original:float,
 *   remaining:float
 * }>
 */
function pur_unpaid_invoices_list(PDO $pdo): array
{
    require_once app_path('includes/crm_supplier_ledger.php');
    if (!crm_supplier_ledger_ensure_schema($pdo)) {
        return [];
    }

    try {
        $st = $pdo->query(
            'SELECT l.supplier_id, l.txn_type, l.ref_id, l.ref_no, l.txn_date, l.debit, l.credit,
                    s.code AS supplier_code, s.name_ar AS supplier_name
             FROM crm_supplier_ledger l
             INNER JOIN crm_supplier s ON s.id = l.supplier_id
             ORDER BY l.supplier_id ASC, l.txn_date ASC, l.id ASC'
        );
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $eps = 0.0005;
    $unpaid = [];

    /** @var array<int, list<array<string, mixed>>> $bySupplier */
    $bySupplier = [];
    foreach ($rows as $row) {
        $sid = (int) ($row['supplier_id'] ?? 0);
        if ($sid < 1) {
            continue;
        }
        $bySupplier[$sid][] = $row;
    }

    foreach ($bySupplier as $supplierRows) {
        /** @var list<array{remaining:float, invoice_id:int, invoice_no:string, invoice_date:string, original:float, supplier_id:int, supplier_code:string, supplier_name:string}> $open */
        $open = [];

        // المرحلة 1: التزامات المورد (دائن صافي) — فاتورة ذمم فقط (النقدي debit=credit فيُستبعد)
        foreach ($supplierRows as $row) {
            $debit = (float) ($row['debit'] ?? 0);
            $credit = (float) ($row['credit'] ?? 0);
            $netCredit = $credit - $debit;
            if ($netCredit <= $eps) {
                continue;
            }
            $isInvoice = ((string) ($row['txn_type'] ?? '')) === 'purchase_invoice';
            $open[] = [
                'remaining' => $netCredit,
                'original' => $netCredit,
                'invoice_id' => $isInvoice ? (int) ($row['ref_id'] ?? 0) : 0,
                'invoice_no' => $isInvoice ? trim((string) ($row['ref_no'] ?? '')) : '',
                'invoice_date' => (string) ($row['txn_date'] ?? ''),
                'supplier_id' => (int) ($row['supplier_id'] ?? 0),
                'supplier_code' => (string) ($row['supplier_code'] ?? ''),
                'supplier_name' => (string) ($row['supplier_name'] ?? ''),
            ];
        }

        // المرحلة 2: المدفوعات/المرتجعات (مدين صافي) من الأقدم
        foreach ($supplierRows as $row) {
            $debit = (float) ($row['debit'] ?? 0);
            $credit = (float) ($row['credit'] ?? 0);
            $netDebit = $debit - $credit;
            if ($netDebit <= $eps) {
                continue;
            }
            $left = $netDebit;
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

        foreach ($open as $item) {
            $remaining = (float) ($item['remaining'] ?? 0);
            $invoiceId = (int) ($item['invoice_id'] ?? 0);
            if ($invoiceId > 0 && $remaining > $eps) {
                $unpaid[] = [
                    'invoice_id' => $invoiceId,
                    'invoice_no' => (string) ($item['invoice_no'] ?? ''),
                    'invoice_date' => (string) ($item['invoice_date'] ?? ''),
                    'supplier_id' => (int) ($item['supplier_id'] ?? 0),
                    'supplier_code' => (string) ($item['supplier_code'] ?? ''),
                    'supplier_name' => (string) ($item['supplier_name'] ?? ''),
                    'original' => (float) ($item['original'] ?? 0),
                    'remaining' => round($remaining, 3),
                ];
            }
        }
    }

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

function pur_unpaid_invoices_count(PDO $pdo): int
{
    return count(pur_unpaid_invoices_list($pdo));
}
