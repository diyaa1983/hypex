<?php
declare(strict_types=1);

require_once app_path('includes/pur_return_post.php');
require_once app_path('includes/crm_supplier_ledger.php');
require_once app_path('includes/inv_stock.php');
require_once app_path('includes/acc_gl.php');

/** هل توجد آثار ترحيل على مردود المشتريات؟ */
function pur_return_has_posting_artifacts(PDO $pdo, int $returnId): bool
{
    if ($returnId < 1) {
        return false;
    }

    if (crm_supplier_ledger_has_table($pdo) && crm_supplier_ledger_exists($pdo, 'purchase_return', $returnId)) {
        return true;
    }

    if (inv_stock_move_has_table($pdo)) {
        try {
            $st = $pdo->prepare(
                "SELECT id FROM inv_stock_move WHERE ref_type = 'purchase_return' AND ref_id = ? LIMIT 1"
            );
            $st->execute([$returnId]);
            if ($st->fetch()) {
                return true;
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    if (acc_gl_ref_exists($pdo, 'purchase_return', $returnId)) {
        return true;
    }

    return false;
}

/** إزالة آثار ترحيل مردود المشتريات (مخزون + ذمة المورد). */
function pur_return_unpost_cleanup_posting_artifacts(PDO $pdo, int $returnId): void
{
    if ($returnId < 1) {
        return;
    }

    if (inv_stock_move_has_table($pdo)) {
        try {
            $pdo->prepare(
                "DELETE FROM inv_stock_move WHERE ref_type = 'purchase_return' AND ref_id = ?"
            )->execute([$returnId]);
        } catch (Throwable $e) {
            // ignore
        }
    }

    if (crm_supplier_ledger_has_table($pdo)) {
        try {
            $pdo->prepare(
                "DELETE FROM crm_supplier_ledger WHERE txn_type = 'purchase_return' AND ref_id = ?"
            )->execute([$returnId]);
        } catch (Throwable $e) {
            // ignore
        }
    }
}

/**
 * فك ترحيل مردود مشتريات: عكس القيد المحاسبي + حركات المستودع + ذمة المورد.
 *
 * @return array{ok:bool, error:?string, message:?string}
 */
function pur_return_unpost_by_id(PDO $pdo, int $returnId): array
{
    if ($returnId < 1) {
        return ['ok' => false, 'error' => 'معرّف المردود غير صالح.', 'message' => null];
    }

    $st = $pdo->prepare('SELECT id, status FROM pur_return WHERE id = ? LIMIT 1');
    $st->execute([$returnId]);
    $hdr = $st->fetch(PDO::FETCH_ASSOC);
    if (!$hdr) {
        return ['ok' => false, 'error' => 'المردود غير موجود.', 'message' => null];
    }
    if ((string) ($hdr['status'] ?? '') === 'cancelled') {
        return ['ok' => false, 'error' => 'لا يمكن فك ترحيل مردود ملغى.', 'message' => null];
    }

    if (!pur_return_has_posting_artifacts($pdo, $returnId)) {
        return [
            'ok' => true,
            'error' => null,
            'message' => 'لا توجد آثار ترحيل على هذا المردود.',
        ];
    }

    $gl = acc_gl_unpost_ref($pdo, 'purchase_return', $returnId);
    if (!$gl['ok']) {
        return [
            'ok' => false,
            'error' => $gl['error'] ?? 'تعذر إلغاء الترحيل المحاسبي.',
            'message' => null,
        ];
    }

    pur_return_unpost_cleanup_posting_artifacts($pdo, $returnId);

    if (pur_return_has_posting_artifacts($pdo, $returnId)) {
        return [
            'ok' => false,
            'error' => 'تعذر إلغاء الترحيل بالكامل (ما زال للمردود أثر ترحيل).',
            'message' => null,
        ];
    }

    require_once app_path('includes/sys_audit_log.php');
    sys_audit_log_pur_return($pdo, 'unpost', $returnId);

    return [
        'ok' => true,
        'error' => null,
        'message' => 'تم فك ترحيل مردود المشتريات (عكس القيد وذمة المورد وحركات المستودع).',
    ];
}
