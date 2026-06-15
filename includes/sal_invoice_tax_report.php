<?php
declare(strict_types=1);

require_once app_path('includes/sal_invoice_schema.php');

/**
 * فواتير بيع مؤكّدة في الفترة مع إجمالي الفاتورة وضريبتها.
 *
 * @return list<array{
 *   invoice_no:string,
 *   invoice_date:string,
 *   total:float,
 *   tax_amount:float
 * }>
 */
function sal_report_invoice_tax_lines(PDO $pdo, string $from, string $to): array
{
    if ($from === '' || $to === '') {
        return [];
    }

    sal_invoice_ensure_schema($pdo);

    $st = $pdo->prepare(
        'SELECT i.invoice_no, i.invoice_date, i.total, i.tax_amount
         FROM sal_invoice i
         WHERE i.status = \'confirmed\'
           AND i.invoice_date >= ?
           AND i.invoice_date <= ?
         ORDER BY i.invoice_date ASC, i.id ASC'
    );
    $st->execute([$from, $to]);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $out[] = [
            'invoice_no' => (string) ($row['invoice_no'] ?? ''),
            'invoice_date' => (string) ($row['invoice_date'] ?? ''),
            'total' => (float) ($row['total'] ?? 0),
            'tax_amount' => (float) ($row['tax_amount'] ?? 0),
        ];
    }

    return $out;
}
