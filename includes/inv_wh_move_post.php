<?php
declare(strict_types=1);

require_once app_path('includes/inv_wh_move_schema.php');
require_once app_path('includes/inv_movement_type_schema.php');
require_once app_path('includes/inv_stock.php');
require_once app_path('includes/inv_wh_move_gl.php');

/**
 * @param list<array{item_id:int, qty:float}> $lines
 * @return array{ok:bool, error:?string, id:?int, move_no:?string}
 */
function inv_wh_move_save(
    PDO $pdo,
    int $moveId,
    string $moveDate,
    string $movementTypeCode,
    int $warehouseId,
    int $warehouseToId,
    string $notes,
    array $lines,
    int $userId
): array {
    $out = ['ok' => false, 'error' => null, 'id' => null, 'move_no' => null];

    if (!inv_wh_move_ensure_schema($pdo)) {
        $out['error'] = 'جداول حركات المستودع غير جاهزة. حدّث الصفحة (F5) لمحاولة إنشائها تلقائياً.';

        return $out;
    }

    inv_movement_type_ensure_schema($pdo);

    $moveDate = parse_date_to_iso(trim($moveDate)) ?? '';
    $movementTypeCode = trim($movementTypeCode);
    $typeRow = inv_movement_type_by_code($pdo, $movementTypeCode);
    if ($moveDate === '') {
        $out['error'] = 'تاريخ الحركة غير صالح.';

        return $out;
    }
    if ($typeRow === null || (int) ($typeRow['is_active'] ?? 0) !== 1) {
        $out['error'] = 'نوع الحركة غير صالح أو غير مفعّل.';

        return $out;
    }
    if ($warehouseId < 1) {
        $out['error'] = 'اختر المستودع.';

        return $out;
    }
    if (inv_wh_move_requires_dest_warehouse($movementTypeCode)) {
        if ($warehouseToId < 1) {
            $out['error'] = 'اختر المستودع المستهدف للنقل.';

            return $out;
        }
        if ($warehouseToId === $warehouseId) {
            $out['error'] = 'لا يمكن النقل إلى نفس المستودع.';

            return $out;
        }
    } else {
        $warehouseToId = 0;
    }

    $normLines = inv_wh_move_normalize_lines($lines);
    if ($normLines === []) {
        $out['error'] = 'أضف مادة واحدة على الأقل.';

        return $out;
    }

    if ($moveId > 0) {
        $existing = inv_wh_move_by_id($pdo, $moveId);
        if ($existing === null) {
            $out['error'] = 'الحركة غير موجودة.';

            return $out;
        }
        if ((string) ($existing['status'] ?? '') === 'posted') {
            $out['error'] = 'لا يمكن تعديل حركة مرحّلة.';

            return $out;
        }
    }

    try {
        $pdo->beginTransaction();

        if ($moveId < 1) {
            $moveNo = inv_wh_move_generate_next_no($pdo);
            $st = $pdo->prepare(
                'INSERT INTO inv_wh_move (move_no, move_date, movement_type_code, warehouse_id, warehouse_to_id, status, notes, created_by)
                 VALUES (?,?,?,?,?,\'draft\',?,?)'
            );
            $st->execute([
                $moveNo,
                $moveDate,
                $movementTypeCode,
                $warehouseId,
                $warehouseToId > 0 ? $warehouseToId : null,
                $notes !== '' ? $notes : null,
                $userId > 0 ? $userId : null,
            ]);
            $moveId = (int) $pdo->lastInsertId();
            $out['move_no'] = $moveNo;
        } else {
            $st = $pdo->prepare(
                'UPDATE inv_wh_move
                 SET move_date = ?, movement_type_code = ?, warehouse_id = ?, warehouse_to_id = ?, notes = ?
                 WHERE id = ? AND status = \'draft\''
            );
            $st->execute([
                $moveDate,
                $movementTypeCode,
                $warehouseId,
                $warehouseToId > 0 ? $warehouseToId : null,
                $notes !== '' ? $notes : null,
                $moveId,
            ]);
            $out['move_no'] = (string) ($existing['move_no'] ?? '');
            $pdo->prepare('DELETE FROM inv_wh_move_line WHERE move_id = ?')->execute([$moveId]);
        }

        $lineSt = $pdo->prepare(
            'INSERT INTO inv_wh_move_line (move_id, line_no, item_id, qty) VALUES (?,?,?,?)'
        );
        $lineNo = 0;
        foreach ($normLines as $ln) {
            $lineNo++;
            $lineSt->execute([$moveId, $lineNo, $ln['item_id'], $ln['qty']]);
        }

        $pdo->commit();
        $out['ok'] = true;
        $out['id'] = $moveId;

        require_once app_path('includes/sys_audit_log.php');
        sys_audit_log_inv_wh_move($pdo, 'save', $moveId);

        return $out;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $out['error'] = 'تعذر الحفظ.';

        return $out;
    }
}

/**
 * @param list<array{item_id?:mixed, qty?:mixed}> $lines
 * @return list<array{item_id:int, qty:float}>
 */
function inv_wh_move_normalize_lines(array $lines): array
{
    $out = [];
    foreach ($lines as $ln) {
        if (!is_array($ln)) {
            continue;
        }
        $itemId = (int) ($ln['item_id'] ?? 0);
        $qty = (float) str_replace(',', '.', (string) ($ln['qty'] ?? '0'));
        if ($itemId < 1 || $qty <= 0) {
            continue;
        }
        $out[] = ['item_id' => $itemId, 'qty' => round($qty, 6)];
    }

    return $out;
}

/** @return array{ok:bool, error:?string, gl_warning:?string} */
function inv_wh_move_post_document(PDO $pdo, int $moveId): array
{
    $out = ['ok' => false, 'error' => null, 'gl_warning' => null];

    $move = inv_wh_move_by_id($pdo, $moveId);
    if ($move === null) {
        $out['error'] = 'الحركة غير موجودة.';

        return $out;
    }
    if ((string) ($move['status'] ?? '') === 'posted') {
        $out['error'] = 'الحركة مرحّلة مسبقاً.';

        return $out;
    }

    $typeCode = (string) ($move['movement_type_code'] ?? '');
    $refTypes = inv_wh_move_stock_ref_types($typeCode);
    if ($refTypes === null) {
        $out['error'] = 'نوع الحركة لا يدعم الترحيل المخزني.';

        return $out;
    }

    $typeRow = inv_movement_type_by_code($pdo, $typeCode);
    if ($typeRow === null || (int) ($typeRow['is_active'] ?? 0) !== 1) {
        $out['error'] = 'نوع الحركة غير مفعّل.';

        return $out;
    }

    $lines = inv_wh_move_lines($pdo, $moveId);
    if ($lines === []) {
        $out['error'] = 'لا توجد بنود للترحيل.';

        return $out;
    }

    $warehouseId = (int) ($move['warehouse_id'] ?? 0);
    $warehouseToId = (int) ($move['warehouse_to_id'] ?? 0);
    $moveDate = (string) ($move['move_date'] ?? '');
    $moveNo = (string) ($move['move_no'] ?? '');
    $note = $moveNo !== '' ? 'حركة #' . $moveNo : null;

    // شغّل أي ترحيلات/ALTER لازمة قبل فتح المعاملة؛
    // بعض محركات MySQL تنفّذ COMMIT ضمني عند DDL مما يكسر المعاملة النشطة.
    acc_gl_ensure_schema($pdo);
    inv_movement_type_ensure_affects_gl_column($pdo);
    inv_stock_move_ensure_table($pdo);

    try {
        $pdo->beginTransaction();

        foreach ($lines as $ln) {
            $itemId = (int) ($ln['item_id'] ?? 0);
            $qty = (float) ($ln['qty'] ?? 0);
            if ($itemId < 1 || $qty <= 0) {
                throw new RuntimeException('بند غير صالح.');
            }

            if ($refTypes['out'] !== '') {
                $res = inv_stock_issue(
                    $pdo,
                    $moveDate,
                    $warehouseId,
                    $itemId,
                    $qty,
                    $refTypes['out'],
                    $moveId,
                    $note
                );
                if (!$res['ok']) {
                    throw new RuntimeException($res['error'] ?? 'تعذر صرف المخزون.');
                }
            }

            if ($refTypes['in'] !== '') {
                $whIn = inv_wh_move_is_transfer($typeCode) ? $warehouseToId : $warehouseId;
                $res = inv_stock_receipt(
                    $pdo,
                    $moveDate,
                    $whIn,
                    $itemId,
                    $qty,
                    $refTypes['in'],
                    $moveId,
                    $note
                );
                if (!$res['ok']) {
                    throw new RuntimeException($res['error'] ?? 'تعذر إدخال المخزون.');
                }
            }
        }

        $gl = acc_gl_post_warehouse_move($pdo, $moveId);
        if (!$gl['ok'] && !($gl['skipped'] ?? true)) {
            throw new RuntimeException($gl['error'] ?? 'تعذر الترحيل المحاسبي.');
        }
        if (($gl['warning'] ?? '') !== '') {
            $out['gl_warning'] = (string) $gl['warning'];
        }

        $pdo->prepare(
            'UPDATE inv_wh_move SET status = \'posted\', posted_at = NOW() WHERE id = ?'
        )->execute([$moveId]);

        $pdo->commit();
        $out['ok'] = true;

        require_once app_path('includes/sys_audit_log.php');
        sys_audit_log_inv_wh_move($pdo, 'post', $moveId);

        return $out;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $out['error'] = $e->getMessage() !== '' ? $e->getMessage() : 'تعذر الترحيل.';

        return $out;
    }
}

function inv_wh_move_delete_stock_for_document(PDO $pdo, int $moveId): void
{
    if ($moveId < 1 || !inv_stock_move_has_table($pdo)) {
        return;
    }
    $types = [
        'stock_adjust_in',
        'stock_adjust_out',
        'stock_disposal',
        'warehouse_transfer_out',
        'warehouse_transfer_in',
    ];
    $placeholders = implode(',', array_fill(0, count($types), '?'));
    $params = array_merge($types, [$moveId]);
    $pdo->prepare(
        "DELETE FROM inv_stock_move WHERE ref_type IN ({$placeholders}) AND ref_id = ?"
    )->execute($params);
}
