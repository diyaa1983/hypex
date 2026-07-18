<?php
declare(strict_types=1);

require_once app_path('includes/sal_return_browse.php');
require_once app_path('includes/sal_return_post.php');

/** @return array<string, mixed>|null */
function sal_return_fetch_header(PDO $pdo, int $id): ?array
{
    if ($id < 1) {
        return null;
    }

    // أعمدة الفوترة الإلكترونية على المرتجع (إن وُجدت).
    require_once app_path('includes/einvoice_schema.php');
    $einvCols = '';
    $optional = ['einv_qr', 'einv_status', 'einv_num', 'einv_sent_at', 'reason_return', 'invoice_uuid'];
    foreach ($optional as $col) {
        if (einvoice_column_exists($pdo, 'sal_return', $col)) {
            $einvCols .= ', r.`' . $col . '`';
        }
    }
    // einv_qr للفاتورة الأصلية كي نعرف هل أُرسلت للفوترة.
    $hasInvoiceEinvQr = einvoice_column_exists($pdo, 'sal_invoice', 'einv_qr');
    $invQrSelect = $hasInvoiceEinvQr ? ', i.einv_qr AS invoice_einv_qr, i.einv_num AS invoice_einv_num' : '';

    $st = $pdo->prepare(
        'SELECT r.id, r.return_no, r.return_date, r.customer_id, r.invoice_id, r.warehouse_id,
                r.subtotal, r.tax_amount, r.total, r.status, r.notes' . $einvCols . ',
                c.name_ar AS customer_name, i.invoice_no, i.invoice_date' . $invQrSelect . '
         FROM sal_return r
         INNER JOIN crm_customer c ON c.id = r.customer_id
         INNER JOIN sal_invoice i ON i.id = r.invoice_id
         WHERE r.id = ? LIMIT 1'
    );
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (is_array($row)) {
        $row['einv_sent'] = !empty($row['einv_qr']);
        require_once app_path('includes/sal_einvoice_tracking.php');
        $invoiceDate = (string) ($row['invoice_date'] ?? '');
        $invoiceSentHere = !empty($row['invoice_einv_qr'] ?? '');
        // فاتورة قبل نطاق المتابعة = مُرسلة سابقاً (نظام قديم) حتى بدون einv_qr هنا.
        $row['invoice_einv_legacy'] = sal_einvoice_invoice_is_legacy_pre_tracking($invoiceDate);
        $row['invoice_einv_sent'] = $invoiceSentHere || !empty($row['invoice_einv_legacy']);
        $row['einv_tracking_required'] = sal_einvoice_doc_date_requires_tracking((string) ($row['return_date'] ?? ''));
    }

    return is_array($row) ? $row : null;
}

/** @return array<string, mixed>|null */
function sal_return_fetch_by_no(PDO $pdo, string $returnNo): ?array
{
    require_once app_path('includes/doc_no_fragment_search.php');

    return doc_no_fetch_exact_or_fragment(
        $pdo,
        $returnNo,
        'SELECT id FROM sal_return WHERE return_no = ? LIMIT 1',
        [trim($returnNo)],
        static fn (string $frag) => sal_return_search_ids_by_no_fragment($pdo, $frag),
        static fn (int $id) => sal_return_fetch_full($pdo, $id)
    );
}

/** @return list<array<string, mixed>> */
function sal_return_load_lines(PDO $pdo, int $returnId): array
{
    if ($returnId < 1) {
        return [];
    }

    $hasBarcode = false;
    try {
        $pdo->query('SELECT barcode FROM inv_item LIMIT 1');
        $hasBarcode = true;
    } catch (Throwable $e) {
        $hasBarcode = false;
    }

    $barcodeCol = $hasBarcode ? 'it.barcode' : 'it.sku AS barcode';

    $sql = "SELECT rl.id AS return_line_id, rl.invoice_line_id, rl.item_id, rl.qty,
                   COALESCE(rl.qty_extra, 0) AS qty_extra,
                   rl.unit_price,
                   rl.tax_rate_percent, rl.line_subtotal, rl.tax_amount, rl.line_gross,
                   il.qty AS qty_sold,
                   COALESCE(il.qty_extra, 0) AS qty_extra_sold,
                   il.line_total,
                   {$barcodeCol}, it.name_ar, il.line_desc
            FROM sal_return_line rl
            INNER JOIN sal_invoice_line il ON il.id = rl.invoice_line_id
            INNER JOIN inv_item it ON it.id = rl.item_id
            WHERE rl.return_id = ?
            ORDER BY rl.id ASC";

    $st = $pdo->prepare($sql);
    $st->execute([$returnId]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return array<string, mixed>|null */
function sal_return_fetch_full(PDO $pdo, int $id): ?array
{
    $hdr = sal_return_fetch_header($pdo, $id);
    if (!$hdr) {
        return null;
    }

    $hdr['prev_id'] = sal_return_nav_neighbor_id($pdo, $id, 'prev');
    $hdr['next_id'] = sal_return_nav_neighbor_id($pdo, $id, 'next');
    $hdr['browse_count'] = sal_return_count_all($pdo);
    $hdr['lines'] = sal_return_load_lines($pdo, $id);
    $hdr['is_posted'] = sal_return_is_posted($pdo, $id) ? 1 : 0;

    return $hdr;
}
