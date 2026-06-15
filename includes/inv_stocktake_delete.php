<?php
declare(strict_types=1);

require_once app_path('includes/inv_stocktake_schema.php');

/**
 * @return array{ok:bool,error:?string,message:?string}
 */
function inv_stocktake_delete_by_id(PDO $pdo, int $docId): array
{
    $out = ['ok' => false, 'error' => null, 'message' => null];
    if ($docId < 1) {
        $out['error'] = 'معرّف السند غير صالح.';
        return $out;
    }

    $doc = inv_stocktake_doc_by_id($pdo, $docId);
    if ($doc === null) {
        $out['error'] = 'السند غير موجود.';
        return $out;
    }
    if ((string) ($doc['status'] ?? '') === 'posted') {
        $out['error'] = 'لا يمكن حذف سند مرحّل.';
        return $out;
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM inv_stocktake_doc WHERE id = ?')->execute([$docId]);
        $pdo->commit();
        $out['ok'] = true;
        $out['message'] = 'تم حذف سند الجرد.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $out['error'] = 'تعذر حذف السند.';
    }

    return $out;
}
