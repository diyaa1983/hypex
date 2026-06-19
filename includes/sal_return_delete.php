<?php
declare(strict_types=1);

require_once app_path('includes/sal_return_post.php');
require_once app_path('includes/sal_return_schema.php');
require_once app_path('includes/crm_customer_ledger.php');

/**
 * حذف مرتجع **بلا أثر مالي أو مستودعي مسجّل** فقط: السجل يُحذف فيُعاد احتساب الكميات المتاحة للإرجاع.
 *
 * @return array{ok:bool, error:?string, return_no:?string}
 */
function sal_return_can_delete(PDO $pdo, int $returnId): array
{
    $out = ['ok' => false, 'error' => null, 'return_no' => null];

    if ($returnId < 1) {
        $out['error'] = 'معرّف المرتجع غير صالح.';

        return $out;
    }

    if (!sal_return_ensure_schema($pdo)) {
        $out['error'] = 'جداول المرتجع غير متوفرة.';

        return $out;
    }

    $st = $pdo->prepare('SELECT return_no, status FROM sal_return WHERE id = ? LIMIT 1');
    $st->execute([$returnId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $out['error'] = 'المرتجع غير موجود.';

        return $out;
    }

    $out['return_no'] = (string) ($row['return_no'] ?? '');

    if ((string) ($row['status'] ?? '') === 'cancelled') {
        $out['error'] = 'لا يمكن حذف مرتجع ملغى.';

        return $out;
    }

    if (sal_return_blocks_delete($pdo, $returnId)) {
        $out['error'] =
            'لا يمكن حذف مرتجع له أثر مالي أو مستودعي مسجّل. الحذف متاح فقط قبل اكتمال الترحيل (أو لمسودة بلا ترحيل).';

        return $out;
    }

    require_once app_path('includes/sal_return_unpost.php');
    if (sal_return_einvoice_is_sent($pdo, $returnId)) {
        $out['error'] = 'لا يمكن حذف مرتجع أُرسل إلى نظام الفوترة.';

        return $out;
    }

    $out['ok'] = true;

    return $out;
}

/**
 * @return array{ok:bool, error:?string}
 */
function sal_return_delete_by_id(PDO $pdo, int $returnId): array
{
    $check = sal_return_can_delete($pdo, $returnId);
    if (!$check['ok']) {
        return ['ok' => false, 'error' => $check['error']];
    }

    require_once app_path('includes/inv_stock.php');

    try {
        $pdo->beginTransaction();

        if (sal_return_blocks_delete($pdo, $returnId)) {
            $pdo->rollBack();

            return [
                'ok' => false,
                'error' => 'يوجد أثر مالي أو مستودعي لهذا المرتجع — لا يمكن الحذف.',
            ];
        }

        if (inv_stock_move_has_table($pdo)) {
            $pdo->prepare(
                "DELETE FROM inv_stock_move WHERE ref_type = 'sale_return' AND ref_id = ?"
            )->execute([$returnId]);
        }

        require_once app_path('includes/sys_audit_log.php');
        sys_audit_log_sal_return($pdo, 'delete', $returnId);

        $pdo->prepare('DELETE FROM sal_return_line WHERE return_id = ?')->execute([$returnId]);
        $pdo->prepare('DELETE FROM sal_return WHERE id = ?')->execute([$returnId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'error' => 'تعذر حذف المرتجع.'];
    }

    return ['ok' => true, 'error' => null];
}

/**
 * @param list<int> $returnIds
 * @return array{deleted:int, errors:list<string>}
 */
function sal_return_delete_by_ids(PDO $pdo, array $returnIds): array
{
    $result = ['deleted' => 0, 'errors' => []];
    foreach ($returnIds as $rawId) {
        $id = (int) $rawId;
        if ($id < 1) {
            continue;
        }
        $one = sal_return_delete_by_id($pdo, $id);
        if ($one['ok']) {
            $result['deleted']++;
        } elseif ($one['error'] !== null) {
            $result['errors'][] = $one['error'];
        }
    }

    return $result;
}
