<?php
declare(strict_types=1);

require_once app_path('includes/inv_wh_move_schema.php');
require_once app_path('includes/inv_wh_move_post.php');
require_once app_path('includes/inv_wh_move_gl.php');
require_once app_path('includes/inv_stock.php');
require_once app_path('includes/acc_gl.php');

/**
 * فك ترحيل حركة مستودع: إلغاء حركات المخزون + القيد المحاسبي (إن وُجد) وإعادة الحالة إلى مسودة.
 *
 * @return array{ok:bool, error:?string, message:?string}
 */
function inv_wh_move_unpost_document(PDO $pdo, int $moveId): array
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

    if ((string) ($move['status'] ?? '') !== 'posted') {
        $out['ok'] = true;
        $out['message'] = 'الحركة غير مرحّلة.';

        return $out;
    }

    try {
        $pdo->beginTransaction();

        inv_wh_move_delete_stock_for_document($pdo, $moveId);

        $gl = acc_gl_unpost_warehouse_move($pdo, $moveId);
        if (!$gl['ok']) {
            throw new RuntimeException($gl['error'] ?? 'تعذر إلغاء القيد المحاسبي.');
        }

        $pdo->prepare(
            "UPDATE inv_wh_move SET status = 'draft', posted_at = NULL WHERE id = ? AND status = 'posted'"
        )->execute([$moveId]);

        $pdo->commit();
        $out['ok'] = true;
        $out['message'] = 'تم فك ترحيل الحركة. يمكنك التعديل وإعادة الترحيل.';

        return $out;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $out['error'] = $e->getMessage() !== '' ? $e->getMessage() : 'تعذر فك الترحيل.';

        return $out;
    }
}
