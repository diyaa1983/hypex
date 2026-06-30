<?php
declare(strict_types=1);

/**
 * بنود فاتورة بيع القابلة للإرجاع (كمية متبقية > 0).
 *
 * @return list<array<string, mixed>>
 */
function sal_return_fetch_invoice_lines(PDO $pdo, int $invoiceId): array
{
    if ($invoiceId < 1) {
        return [];
    }

    $hasBarcode = false;
    try {
        $pdo->query('SELECT barcode FROM inv_item LIMIT 1');
        $hasBarcode = true;
    } catch (Throwable $e) {
        $hasBarcode = false;
    }

    $barcodeCol = $hasBarcode ? 'i.barcode' : 'i.sku AS barcode';

    $sql = "SELECT il.id AS invoice_line_id, il.item_id, il.line_desc, il.qty AS qty_sold,
                   il.unit_price, il.line_total, il.tax_rate_percent,
                   COALESCE(SUM(rl.qty), 0) AS qty_returned,
                   {$barcodeCol}, i.name_ar
            FROM sal_invoice_line il
            INNER JOIN inv_item i ON i.id = il.item_id
            LEFT JOIN sal_return_line rl ON rl.invoice_line_id = il.id
            LEFT JOIN sal_return r ON r.id = rl.return_id AND r.status <> 'cancelled'
            WHERE il.invoice_id = ?
            GROUP BY il.id
            ORDER BY il.id ASC";

    $st = $pdo->prepare($sql);
    $st->execute([$invoiceId]);

    $lines = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $sold = (float) ($row['qty_sold'] ?? 0);
        $returned = (float) ($row['qty_returned'] ?? 0);
        $remaining = max(0, $sold - $returned);
        if ($remaining <= 0.000001) {
            continue;
        }
        $lines[] = [
            'invoice_line_id' => (int) $row['invoice_line_id'],
            'item_id' => (int) $row['item_id'],
            'line_desc' => (string) ($row['line_desc'] ?? ''),
            'name_ar' => (string) ($row['name_ar'] ?? ''),
            'barcode' => (string) ($row['barcode'] ?? ''),
            'qty_sold' => $sold,
            'qty_returned' => $returned,
            'qty_remaining' => $remaining,
            'unit_price' => (float) ($row['unit_price'] ?? 0),
            'line_total' => (float) ($row['line_total'] ?? 0),
            'tax_rate_percent' => (float) ($row['tax_rate_percent'] ?? 0),
        ];
    }

    return $lines;
}

/** هل تبقى على الفاتورة كميات قابلة للإرجاع؟ */
function sal_return_invoice_has_returnable_lines(PDO $pdo, int $invoiceId): bool
{
    return $invoiceId > 0 && sal_return_fetch_invoice_lines($pdo, $invoiceId) !== [];
}
