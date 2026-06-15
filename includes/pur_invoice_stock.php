<?php
declare(strict_types=1);

require_once app_path('includes/inv_stock.php');
require_once app_path('includes/inv_invoice_line_qty.php');

/**
 * ترحيل مخزون فاتورة شراء (إدخال) — يُصحّح الرصيد السالب تلقائيًا.
 *
 * @return array{ok:bool, skipped:bool, error:?string}
 */
function pur_invoice_stock_post(PDO $pdo, int $invoiceId): array
{
    $out = ['ok' => false, 'skipped' => false, 'error' => null];

    if ($invoiceId < 1 || !inv_stock_move_has_table($pdo)) {
        $out['skipped'] = true;
        $out['ok'] = true;

        return $out;
    }

    $chk = $pdo->prepare(
        "SELECT id FROM inv_stock_move WHERE ref_type = 'purchase_invoice' AND ref_id = ? LIMIT 1"
    );
    $chk->execute([$invoiceId]);
    if ($chk->fetch()) {
        $out['ok'] = true;
        $out['skipped'] = true;

        return $out;
    }

    try {
        $hdr = $pdo->prepare(
            'SELECT invoice_no, invoice_date, warehouse_id, status FROM pur_invoice WHERE id = ? LIMIT 1'
        );
        $hdr->execute([$invoiceId]);
        $inv = $hdr->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $out['error'] = 'تعذر قراءة فاتورة الشراء: ' . $e->getMessage();

        return $out;
    }

    if (!$inv || (string) ($inv['status'] ?? '') !== 'confirmed') {
        $out['error'] = 'فاتورة الشراء غير موجودة أو غير مؤكدة.';

        return $out;
    }

    $warehouseId = (int) ($inv['warehouse_id'] ?? 0);
    if ($warehouseId < 1) {
        $out['error'] = 'المستودع غير محدد على فاتورة الشراء.';

        return $out;
    }

    try {
        $lines = $pdo->prepare(
            'SELECT pl.item_id, pl.qty, COALESCE(pl.qty_extra, 0) AS qty_extra, i.name_ar, i.track_inventory
             FROM pur_invoice_line pl
             INNER JOIN inv_item i ON i.id = pl.item_id
             WHERE pl.invoice_id = ? AND i.track_inventory = 1 AND ' . inv_invoice_line_sql_stock_positive('pl') . '
             ORDER BY pl.id ASC'
        );
        $lines->execute([$invoiceId]);
        $rows = $lines->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $out['error'] = 'تعذر قراءة بنود الفاتورة للمخزون: ' . $e->getMessage();

        return $out;
    }

    $invoiceNo = (string) ($inv['invoice_no'] ?? '');
    $moveDate = (string) ($inv['invoice_date'] ?? date('Y-m-d'));

    foreach ($rows as $row) {
        $itemId = (int) $row['item_id'];
        $stockQty = inv_invoice_line_stock_qty_sum((float) $row['qty'], (float) ($row['qty_extra'] ?? 0));
        if ($itemId < 1 || $stockQty <= 0) {
            continue;
        }

        $note = 'إدخال فاتورة شراء ' . $invoiceNo;
        try {
            $move = inv_stock_receipt(
                $pdo,
                $moveDate,
                $warehouseId,
                $itemId,
                $stockQty,
                'purchase_invoice',
                $invoiceId,
                $note
            );
        } catch (Throwable $e) {
            $name = (string) ($row['name_ar'] ?? ('#' . $itemId));
            $out['error'] = 'تعذر إدخال المخزون — «' . $name . '»: ' . $e->getMessage();

            return $out;
        }
        if (!$move['ok']) {
            $name = (string) ($row['name_ar'] ?? ('#' . $itemId));
            $out['error'] = ($move['error'] ?? 'تعذر إدخال المخزون.') . ' — «' . $name . '»';

            return $out;
        }
    }

    $out['ok'] = true;

    return $out;
}
