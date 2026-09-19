<?php
declare(strict_types=1);

require_once app_path('includes/sal_return_schema.php');

/**
 * مرتجعات مبيعات مؤكّدة بين تاريخين — سطر واحد لكل مستند مرتجع (إجمالي المرتجع ورقم الفاتورة الأصلية).
 *
 * @return list<array{source_invoice_no:string,return_no:string,return_total:float}>
 */
function sal_report_returns_totals_by_document(PDO $pdo, string $from, string $to): array
{
    if ($from === '' || $to === '' || !sal_return_has_tables($pdo)) {
        return [];
    }

    $st = $pdo->prepare(
        "SELECT COALESCE(NULLIF(TRIM(i.invoice_no), ''), '') AS source_invoice_no,
                COALESCE(NULLIF(TRIM(r.return_no), ''), '') AS return_no,
                r.total AS return_total
         FROM sal_return r
         INNER JOIN sal_invoice i ON i.id = r.invoice_id
         WHERE r.status = 'confirmed'
           AND r.return_date >= ?
           AND r.return_date <= ?
         ORDER BY r.return_date ASC, r.id ASC"
    );
    $st->execute([$from, $to]);

    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $rows[] = [
            'source_invoice_no' => (string) ($row['source_invoice_no'] ?? ''),
            'return_no' => (string) ($row['return_no'] ?? ''),
            'return_total' => (float) ($row['return_total'] ?? 0),
        ];
    }

    return $rows;
}
