<?php
declare(strict_types=1);

require_once app_path('includes/pur_invoice_post.php');

/**
 * بنود فاتورة شراء قابلة للإرجاع (كمية متبقية > 0).
 *
 * @return list<array<string, mixed>>
 */
function pur_return_fetch_invoice_lines(PDO $pdo, int $invoiceId): array
{
    if ($invoiceId < 1) {
        return [];
    }

    if (!pur_invoice_is_posted($pdo, $invoiceId)) {
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

    $hasTax = false;
    try {
        $pdo->query('SELECT tax_rate_percent FROM pur_invoice_line LIMIT 1');
        $hasTax = true;
    } catch (Throwable $e) {
        $hasTax = false;
    }

    $taxCol = $hasTax ? 'il.tax_rate_percent' : '0 AS tax_rate_percent';

    $sql = "SELECT il.id AS invoice_line_id, il.item_id, il.line_desc, il.qty AS qty_sold,
                   il.unit_price, {$taxCol},
                   COALESCE(SUM(rl.qty), 0) AS qty_returned,
                   {$barcodeCol}, i.name_ar
            FROM pur_invoice_line il
            INNER JOIN inv_item i ON i.id = il.item_id
            LEFT JOIN pur_return_line rl ON rl.invoice_line_id = il.id
            LEFT JOIN pur_return r ON r.id = rl.return_id AND r.status <> 'cancelled'
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
            'tax_rate_percent' => (float) ($row['tax_rate_percent'] ?? 0),
        ];
    }

    return $lines;
}

/** هل تبقى على فاتورة الشراء كميات قابلة للإرجاع؟ */
function pur_return_invoice_has_returnable_lines(PDO $pdo, int $invoiceId): bool
{
    return $invoiceId > 0 && pur_return_fetch_invoice_lines($pdo, $invoiceId) !== [];
}
