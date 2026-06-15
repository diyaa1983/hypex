<?php
declare(strict_types=1);

require_once app_path('includes/inv_wh_move_schema.php');
require_once app_path('includes/inv_wh_move_post.php');
require_once app_path('includes/inv_wh_move_unpost.php');
require_once app_path('includes/inv_wh_move_gl.php');
require_once app_path('includes/inv_stock.php');
require_once app_path('includes/acc_gl.php');

/**
 * @return array{ok:bool, error:?string, message:?string}
 */
function inv_wh_move_delete_by_id(PDO $pdo, int $moveId): array
{
    $out = ['ok' => false, 'error' => null, 'message' => null];

    if ($moveId < 1) {
        $out['error'] = 'معرّف الحركة غير صالح.';

        return $out;
    }

    $move = inv_wh_move_by_id($pdo, $moveId);
    if ($move === null) {
        $out['error'] = 'الحركة غير موجودة.';

        return $out;
    }

    if ((string) ($move['status'] ?? '') === 'posted') {
        $out['error'] = 'لا يمكن حذف حركة مرحّلة. فكّ الترحيل أولاً ثم احذف.';

        return $out;
    }

    try {
        $pdo->beginTransaction();

        inv_wh_move_delete_stock_for_document($pdo, $moveId);
        acc_gl_unpost_warehouse_move($pdo, $moveId);

        $pdo->prepare('DELETE FROM inv_wh_move WHERE id = ?')->execute([$moveId]);

        $pdo->commit();
        $out['ok'] = true;
        $out['message'] = 'تم حذف الحركة.';

        return $out;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $out['error'] = $e->getMessage() !== '' ? $e->getMessage() : 'تعذر الحذف.';

        return $out;
    }
}
