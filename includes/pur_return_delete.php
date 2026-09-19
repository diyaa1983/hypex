<?php
declare(strict_types=1);

require_once app_path('includes/pur_return_post.php');
require_once app_path('includes/pur_return_schema.php');
require_once app_path('includes/crm_supplier_ledger.php');

/** @return array{ok:bool, error:?string, return_no:?string} */
function pur_return_can_delete(PDO $pdo, int $returnId): array
{
    $out = ['ok' => false, 'error' => null, 'return_no' => null];

    if ($returnId < 1) {
        $out['error'] = 'معرّف المردود غير صالح.';

        return $out;
    }

    if (!pur_return_ensure_schema($pdo)) {
        $out['error'] = 'جداول مردود المشتريات غير متوفرة.';

        return $out;
    }

    $st = $pdo->prepare('SELECT return_no, status FROM pur_return WHERE id = ? LIMIT 1');
    $st->execute([$returnId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $out['error'] = 'المردود غير موجود.';

        return $out;
    }

    $out['return_no'] = (string) ($row['return_no'] ?? '');

    if ((string) ($row['status'] ?? '') !== 'confirmed') {
        $out['error'] = 'لا يمكن حذف مردود ملغى أو غير مؤكد.';

        return $out;
    }

    if (pur_return_is_posted($pdo, $returnId)) {
        $out['error'] =
            'لا يمكن حذف مردود مرحّل (ذمة المورد أو مخزون). الحذف متاح فقط لغير المرحّلة.';

        return $out;
    }

    $out['ok'] = true;

    return $out;
}

/** @return array{ok:bool, error:?string} */
function pur_return_delete_by_id(PDO $pdo, int $returnId): array
{
    $check = pur_return_can_delete($pdo, $returnId);
    if (!$check['ok']) {
        return ['ok' => false, 'error' => $check['error']];
    }

    require_once app_path('includes/inv_stock.php');

    try {
        $pdo->beginTransaction();

        if (crm_supplier_ledger_has_table($pdo) && crm_supplier_ledger_exists($pdo, 'purchase_return', $returnId)) {
            $pdo->rollBack();

            return [
                'ok' => false,
                'error' => 'يوجد قيد على ذمة المورد لهذا المردود — لا يمكن الحذف.',
            ];
        }

        if (inv_stock_move_has_table($pdo)) {
            $pdo->prepare(
                "DELETE FROM inv_stock_move WHERE ref_type = 'purchase_return' AND ref_id = ?"
            )->execute([$returnId]);
        }

        require_once app_path('includes/sys_audit_log.php');
        sys_audit_log_pur_return($pdo, 'delete', $returnId);

        $pdo->prepare('DELETE FROM pur_return_line WHERE return_id = ?')->execute([$returnId]);
        $pdo->prepare('DELETE FROM pur_return WHERE id = ?')->execute([$returnId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'error' => 'تعذر حذف المردود.'];
    }

    return ['ok' => true, 'error' => null];
}

/** @param list<int> $returnIds @return array{deleted:int, errors:list<string>} */
function pur_return_delete_by_ids(PDO $pdo, array $returnIds): array
{
    $result = ['deleted' => 0, 'errors' => []];
    foreach ($returnIds as $rawId) {
        $id = (int) $rawId;
        if ($id < 1) {
            continue;
        }
        $one = pur_return_delete_by_id($pdo, $id);
        if ($one['ok']) {
            $result['deleted']++;
        } elseif ($one['error'] !== null) {
            $result['errors'][] = $one['error'];
        }
    }

    return $result;
}
