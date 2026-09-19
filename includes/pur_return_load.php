<?php
declare(strict_types=1);

require_once app_path('includes/pur_return_browse.php');
require_once app_path('includes/pur_return_post.php');

/** @return array<string, mixed>|null */
function pur_return_fetch_header(PDO $pdo, int $id): ?array
{
    if ($id < 1) {
        return null;
    }

    $st = $pdo->prepare(
        'SELECT r.id, r.return_no, r.return_date, r.supplier_id, r.invoice_id, r.warehouse_id,
                r.subtotal, r.tax_amount, r.total, r.status, r.notes,
                s.name_ar AS supplier_name, i.invoice_no, i.invoice_date
         FROM pur_return r
         INNER JOIN crm_supplier s ON s.id = r.supplier_id
         INNER JOIN pur_invoice i ON i.id = r.invoice_id
         WHERE r.id = ? LIMIT 1'
    );
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/** @return array<string, mixed>|null */
function pur_return_fetch_by_no(PDO $pdo, string $returnNo): ?array
{
    require_once app_path('includes/doc_no_fragment_search.php');

    return doc_no_fetch_exact_or_fragment(
        $pdo,
        $returnNo,
        'SELECT id FROM pur_return WHERE return_no = ? LIMIT 1',
        [trim($returnNo)],
        static fn (string $frag) => pur_return_search_ids_by_no_fragment($pdo, $frag),
        static fn (int $id) => pur_return_fetch_full($pdo, $id)
    );
}

/** @return list<array<string, mixed>> */
function pur_return_load_lines(PDO $pdo, int $returnId): array
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

    $sql = "SELECT rl.id AS return_line_id, rl.invoice_line_id, rl.item_id, rl.qty, rl.unit_price,
                   rl.tax_rate_percent, rl.line_subtotal, rl.tax_amount, rl.line_gross,
                   il.qty AS qty_sold,
                   {$barcodeCol}, it.name_ar, il.line_desc
            FROM pur_return_line rl
            INNER JOIN pur_invoice_line il ON il.id = rl.invoice_line_id
            INNER JOIN inv_item it ON it.id = rl.item_id
            WHERE rl.return_id = ?
            ORDER BY rl.id ASC";

    $st = $pdo->prepare($sql);
    $st->execute([$returnId]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return array<string, mixed>|null */
function pur_return_fetch_full(PDO $pdo, int $id): ?array
{
    $hdr = pur_return_fetch_header($pdo, $id);
    if (!$hdr) {
        return null;
    }

    $hdr['prev_id'] = pur_return_nav_neighbor_id($pdo, $id, 'prev');
    $hdr['next_id'] = pur_return_nav_neighbor_id($pdo, $id, 'next');
    $hdr['browse_count'] = pur_return_count_all($pdo);
    $hdr['lines'] = pur_return_load_lines($pdo, $id);
    $hdr['is_posted'] = pur_return_is_posted($pdo, $id) ? 1 : 0;

    return $hdr;
}
