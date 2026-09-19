<?php
declare(strict_types=1);

require_once app_path('includes/pur_return_schema.php');

/**
 * مرتجعات مشتريات مؤكدة بين تاريخين — سطر واحد لكل مستند مرتجع.
 *
 * @return list<array{
 *   return_no: string,
 *   source_invoice_no: string,
 *   supplier_name: string,
 *   return_inclusive: float,
 *   return_exclusive: float
 * }>
 */
function pur_report_purchase_returns(PDO $pdo, int $supplierId, string $from, string $to): array
{
    if ($from === '' || $to === '' || $supplierId < 0) {
        return [];
    }

    pur_return_ensure_schema($pdo);

    if (!pur_return_has_tables($pdo)) {
        return [];
    }

    $supFilter = $supplierId > 0 ? ' AND r.supplier_id = ? ' : '';
    $params = [$from, $to];
    if ($supplierId > 0) {
        $params[] = $supplierId;
    }

    $st = $pdo->prepare(
        "SELECT COALESCE(NULLIF(TRIM(r.return_no), ''), '') AS return_no,
                COALESCE(NULLIF(TRIM(i.invoice_no), ''), '') AS source_invoice_no,
                s.name_ar AS supplier_name,
                r.subtotal AS return_exclusive,
                r.total AS return_inclusive
         FROM pur_return r
         INNER JOIN pur_invoice i ON i.id = r.invoice_id
         INNER JOIN crm_supplier s ON s.id = r.supplier_id
         WHERE r.status = 'confirmed'
           AND r.return_date >= ?
           AND r.return_date <= ?
           {$supFilter}
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
            'supplier_name' => (string) ($row['supplier_name'] ?? ''),
            'return_inclusive' => $inclusive,
            'return_exclusive' => $exclusive,
        ];
    }

    return $rows;
}
