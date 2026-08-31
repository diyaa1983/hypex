<?php
declare(strict_types=1);

require_once app_path('includes/inv_invoice_line_qty.php');

/**
 * بنود فاتورة بيع القابلة للإرجاع (كمية أو كمية إضافية متبقية > 0).
 *
 * @param int $excludeReturnId عند تعديل مرتجع موجود: استثناء كمياته من «المُرجع» حتى تظهر في المتبقي
 * @return list<array<string, mixed>>
 */
function sal_return_fetch_invoice_lines(PDO $pdo, int $invoiceId, int $excludeReturnId = 0): array
{
    if ($invoiceId < 1) {
        return [];
    }

    require_once app_path('includes/sal_return_line_qty.php');
    sal_return_line_ensure_qty_extra($pdo);
    inv_invoice_line_ensure_qty_extra($pdo);

    $hasExtraInv = inv_invoice_line_has_qty_extra($pdo, 'sal_invoice_line');
    $hasExtraRet = sal_return_line_has_qty_extra($pdo);

    $hasBarcode = false;
    try {
        $pdo->query('SELECT barcode FROM inv_item LIMIT 1');
        $hasBarcode = true;
    } catch (Throwable $e) {
        $hasBarcode = false;
    }

    $barcodeCol = $hasBarcode ? 'i.barcode' : 'i.sku AS barcode';
    $extraSoldCol = $hasExtraInv ? 'COALESCE(il.qty_extra, 0)' : '0';
    $extraRetCol = $hasExtraRet ? 'COALESCE(SUM(rl.qty_extra), 0)' : '0';
    // استثناء المرتجع الحالي من JOIN حتى يبقى المتبقي شاملاً كمياته عند التعديل.
    $rlJoinExtra = $excludeReturnId > 0
        ? ' AND NOT EXISTS (
                SELECT 1 FROM sal_return rx
                WHERE rx.id = rl.return_id AND rx.id = ? AND rx.status <> \'cancelled\'
            )'
        : '';

    $sql = "SELECT il.id AS invoice_line_id, il.item_id, il.line_desc, il.qty AS qty_sold,
                   {$extraSoldCol} AS qty_extra_sold,
                   il.unit_price, il.line_total, il.tax_rate_percent,
                   COALESCE(il.tax_amount, 0) AS tax_amount,
                   COALESCE(SUM(rl.qty), 0) AS qty_returned,
                   {$extraRetCol} AS qty_extra_returned,
                   {$barcodeCol}, i.name_ar
            FROM sal_invoice_line il
            INNER JOIN inv_item i ON i.id = il.item_id
            LEFT JOIN sal_return_line rl ON rl.invoice_line_id = il.id{$rlJoinExtra}
            LEFT JOIN sal_return r ON r.id = rl.return_id AND r.status <> 'cancelled'
            WHERE il.invoice_id = ?
            GROUP BY il.id
            ORDER BY il.id ASC";

    $st = $pdo->prepare($sql);
    $params = [];
    if ($excludeReturnId > 0) {
        $params[] = $excludeReturnId;
    }
    $params[] = $invoiceId;
    $st->execute($params);

    $lines = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $sold = (float) ($row['qty_sold'] ?? 0);
        $extraSold = (float) ($row['qty_extra_sold'] ?? 0);
        $returned = (float) ($row['qty_returned'] ?? 0);
        $extraReturned = (float) ($row['qty_extra_returned'] ?? 0);
        $remaining = max(0.0, $sold - $returned);
        $extraRemaining = max(0.0, $extraSold - $extraReturned);
        if ($remaining <= 0.000001 && $extraRemaining <= 0.000001) {
            continue;
        }
        $lines[] = [
            'invoice_line_id' => (int) $row['invoice_line_id'],
            'item_id' => (int) $row['item_id'],
            'line_desc' => (string) ($row['line_desc'] ?? ''),
            'name_ar' => (string) ($row['name_ar'] ?? ''),
            'barcode' => (string) ($row['barcode'] ?? ''),
            'qty_sold' => $sold,
            'qty_extra_sold' => $extraSold,
            'qty_returned' => $returned,
            'qty_extra_returned' => $extraReturned,
            'qty_remaining' => $remaining,
            'qty_extra_remaining' => $extraRemaining,
            'unit_price' => (float) ($row['unit_price'] ?? 0),
            'line_total' => (float) ($row['line_total'] ?? 0),
            'tax_rate_percent' => (float) ($row['tax_rate_percent'] ?? 0),
            'tax_amount' => (float) ($row['tax_amount'] ?? 0),
        ];
    }

    return $lines;
}

/** هل تبقى على الفاتورة كميات قابلة للإرجاع؟ */
function sal_return_invoice_has_returnable_lines(PDO $pdo, int $invoiceId): bool
{
    return $invoiceId > 0 && sal_return_fetch_invoice_lines($pdo, $invoiceId) !== [];
}
