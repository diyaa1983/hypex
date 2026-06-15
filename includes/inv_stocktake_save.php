<?php
declare(strict_types=1);

require_once app_path('includes/inv_stocktake_schema.php');
require_once app_path('includes/inv_stock.php');

/**
 * @param list<array{item_id?:mixed, book_qty?:mixed, counted_qty?:mixed}> $lines
 * @return array{ok:bool,error:?string,id:?int,take_no:?string}
 */
function inv_stocktake_save(PDO $pdo, int $docId, string $takeDate, int $warehouseId, array $lines, ?int $userId = null, string $notes = ''): array
{
    $out = ['ok' => false, 'error' => null, 'id' => null, 'take_no' => null];
    if (!inv_stocktake_ensure_schema($pdo)) {
        $out['error'] = 'تعذر تجهيز جداول سندات الجرد.';
        return $out;
    }
    $takeDate = parse_date_to_iso(trim($takeDate)) ?? '';
    if ($takeDate === '') {
        $out['error'] = 'تاريخ السند غير صالح.';
        return $out;
    }
    if ($warehouseId < 1) {
        $out['error'] = 'اختر المستودع.';
        return $out;
    }
    $norm = inv_stocktake_normalize_lines($lines);
    if ($norm === []) {
        $out['error'] = 'اختر مادة واحدة على الأقل.';
        return $out;
    }

    if ($docId > 0) {
        $existing = inv_stocktake_doc_by_id($pdo, $docId);
        if ($existing === null) {
            $out['error'] = 'السند غير موجود.';
            return $out;
        }
        if ((string) ($existing['status'] ?? '') === 'posted') {
            $out['error'] = 'لا يمكن تعديل سند مرحّل.';
            return $out;
        }
    }

    try {
        $pdo->beginTransaction();
        if ($docId < 1) {
            $takeNo = inv_stocktake_generate_next_no($pdo);
            $st = $pdo->prepare(
                "INSERT INTO inv_stocktake_doc (take_no,take_date,warehouse_id,status,notes,created_by)
                 VALUES (?,?,?,?,?,?)"
            );
            $st->execute([
                $takeNo,
                $takeDate,
                $warehouseId,
                'draft',
                $notes !== '' ? $notes : null,
                $userId !== null && $userId > 0 ? $userId : null,
            ]);
            $docId = (int) $pdo->lastInsertId();
            $out['take_no'] = $takeNo;
        } else {
            $st = $pdo->prepare(
                "UPDATE inv_stocktake_doc
                 SET take_date = ?, warehouse_id = ?, notes = ?
                 WHERE id = ? AND status = 'draft'"
            );
            $st->execute([$takeDate, $warehouseId, $notes !== '' ? $notes : null, $docId]);
            $pdo->prepare('DELETE FROM inv_stocktake_line WHERE doc_id = ?')->execute([$docId]);
        }

        $ins = $pdo->prepare(
            'INSERT INTO inv_stocktake_line (doc_id,line_no,item_id,book_qty,counted_qty) VALUES (?,?,?,?,?)'
        );
        $lineNo = 0;
        foreach ($norm as $ln) {
            $lineNo++;
            $ins->execute([$docId, $lineNo, $ln['item_id'], $ln['book_qty'], $ln['counted_qty']]);
        }
        $pdo->commit();
        $out['ok'] = true;
        $out['id'] = $docId;
        if ($out['take_no'] === null) {
            $noSt = $pdo->prepare('SELECT take_no FROM inv_stocktake_doc WHERE id = ?');
            $noSt->execute([$docId]);
            $out['take_no'] = (string) ($noSt->fetchColumn() ?: '');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $out['error'] = 'تعذر حفظ السند.';
    }

    return $out;
}

/**
 * @param list<array{item_id?:mixed, book_qty?:mixed, counted_qty?:mixed}> $lines
 * @return list<array{item_id:int,book_qty:float,counted_qty:float}>
 */
function inv_stocktake_normalize_lines(array $lines): array
{
    $out = [];
    $seen = [];
    foreach ($lines as $ln) {
        if (!is_array($ln)) {
            continue;
        }
        $itemId = (int) ($ln['item_id'] ?? 0);
        if ($itemId < 1 || isset($seen[$itemId])) {
            continue;
        }
        $book = (float) str_replace(',', '.', (string) ($ln['book_qty'] ?? '0'));
        $counted = (float) str_replace(',', '.', (string) ($ln['counted_qty'] ?? '0'));
        $seen[$itemId] = true;
        $out[] = [
            'item_id' => $itemId,
            'book_qty' => round($book, 6),
            'counted_qty' => round($counted, 6),
        ];
    }

    return $out;
}
