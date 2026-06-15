<?php
declare(strict_types=1);

require_once app_path('includes/sal_return_schema.php');

/**
 * مرتجعات مبيعات مؤكدة بين تاريخين — سطر واحد لكل مستند مرتجع.
 *
 * @return list<array{
 *   return_no: string,
 *   source_invoice_no: string,
 *   customer_name: string,
 *   return_inclusive: float,
 *   return_exclusive: float
 * }>
 */
function sal_report_sales_returns(PDO $pdo, int $customerId, string $from, string $to): array
{
    if ($from === '' || $to === '' || $customerId < 0) {
        return [];
    }

    if (!sal_return_has_tables($pdo)) {
        return [];
    }

    $custFilter = $customerId > 0 ? ' AND r.customer_id = ? ' : '';
    $params = [$from, $to];
    if ($customerId > 0) {
        $params[] = $customerId;
    }

    $st = $pdo->prepare(
        "SELECT COALESCE(NULLIF(TRIM(r.return_no), ''), '') AS return_no,
                COALESCE(NULLIF(TRIM(i.invoice_no), ''), '') AS source_invoice_no,
                c.name_ar AS customer_name,
                r.subtotal AS return_exclusive,
                r.total AS return_inclusive
         FROM sal_return r
         INNER JOIN sal_invoice i ON i.id = r.invoice_id
         INNER JOIN crm_customer c ON c.id = r.customer_id
         WHERE r.status = 'confirmed'
           AND r.return_date >= ?
           AND r.return_date <= ?
           {$custFilter}
         ORDER BY r.return_date ASC, r.id ASC"
    );
    $st->execute($params);

    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $exclusive = (float) ($row['return_exclusive'] ?? 0);
        $inclusive = (float) ($row['return_inclusive'] ?? 0);
        if ($inclusive <= 0 && $exclusive > 0) {
            $inclusive = $exclusive;
        }
        $rows[] = [
            'return_no' => (string) ($row['return_no'] ?? ''),
            'source_invoice_no' => (string) ($row['source_invoice_no'] ?? ''),
            'customer_name' => (string) ($row['customer_name'] ?? ''),
            'return_inclusive' => $inclusive,
            'return_exclusive' => $exclusive,
        ];
    }

    return $rows;
}
