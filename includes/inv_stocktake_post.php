<?php
declare(strict_types=1);

require_once app_path('includes/inv_stocktake_schema.php');
require_once app_path('includes/inv_stock.php');

/** @return array{ok:bool,error:?string} */
function inv_stocktake_post_document(PDO $pdo, int $docId): array
{
    $out = ['ok' => false, 'error' => null];
    $doc = inv_stocktake_doc_by_id($pdo, $docId);
    if ($doc === null) {
        $out['error'] = 'السند غير موجود.';
        return $out;
    }
    if ((string) ($doc['status'] ?? '') === 'posted') {
        $out['error'] = 'السند مرحّل مسبقاً.';
        return $out;
    }
    $lines = inv_stocktake_doc_lines($pdo, $docId);
    if ($lines === []) {
        $out['error'] = 'لا توجد بنود للترحيل.';
        return $out;
    }
    $warehouseId = (int) ($doc['warehouse_id'] ?? 0);
    $takeDate = (string) ($doc['take_date'] ?? '');
    $takeNo = (string) ($doc['take_no'] ?? '');
    $note = $takeNo !== '' ? 'سند جرد #' . $takeNo : 'سند جرد';

    try {
        $pdo->beginTransaction();
        foreach ($lines as $ln) {
            $itemId = (int) ($ln['item_id'] ?? 0);
            $book = (float) ($ln['book_qty'] ?? 0);
            $counted = (float) ($ln['counted_qty'] ?? 0);
            if ($itemId < 1) {
                continue;
            }
            $delta = round($counted - $book, 6);
            if (abs($delta) < 0.000001) {
                continue;
            }
            if ($delta > 0) {
                $res = inv_stock_receipt($pdo, $takeDate, $warehouseId, $itemId, $delta, 'stocktake_adjust_in', $docId, $note);
            } else {
                $res = inv_stock_issue($pdo, $takeDate, $warehouseId, $itemId, abs($delta), 'stocktake_adjust_out', $docId, $note);
            }
            if (empty($res['ok'])) {
                throw new RuntimeException((string) ($res['error'] ?? 'تعذر ترحيل بند جرد.'));
            }
        }
        $pdo->prepare("UPDATE inv_stocktake_doc SET status='posted', posted_at=NOW() WHERE id = ?")->execute([$docId]);
        $pdo->commit();
        $out['ok'] = true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $out['error'] = $e->getMessage() !== '' ? $e->getMessage() : 'تعذر الترحيل.';
    }

    return $out;
}

/** @return array{ok:bool,error:?string} */
function inv_stocktake_unpost_document(PDO $pdo, int $docId): array
{
    $out = ['ok' => false, 'error' => null];
    $doc = inv_stocktake_doc_by_id($pdo, $docId);
    if ($doc === null) {
        $out['error'] = 'السند غير موجود.';
        return $out;
    }
    if ((string) ($doc['status'] ?? '') !== 'posted') {
        $out['error'] = 'السند غير مرحّل.';
        return $out;
    }

    try {
        $pdo->beginTransaction();
        if (inv_stock_move_has_table($pdo)) {
            $pdo->prepare(
                "DELETE FROM inv_stock_move
                 WHERE ref_type IN ('stocktake_adjust_in','stocktake_adjust_out')
                   AND ref_id = ?"
            )->execute([$docId]);
        }
        $pdo->prepare("UPDATE inv_stocktake_doc SET status='draft', posted_at=NULL WHERE id = ?")->execute([$docId]);
        $pdo->commit();
        $out['ok'] = true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $out['error'] = $e->getMessage() !== '' ? $e->getMessage() : 'تعذر فك الترحيل.';
    }

    return $out;
}
