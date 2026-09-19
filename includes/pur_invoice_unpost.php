<?php
declare(strict_types=1);

require_once app_path('includes/pur_invoice_post.php');
require_once app_path('includes/pur_invoice_delete.php');
require_once app_path('includes/crm_supplier_ledger.php');
require_once app_path('includes/pur_return_schema.php');
require_once app_path('includes/inv_stock.php');
require_once app_path('includes/acc_gl.php');

/**
 * أَخطاء تَمنَع فك ترحيل فاتورة شراء (مثل وجود مردود مَرتبط).
 *
 * @return string|null رسالة الخَطأ، أو null إن لم يَكن هُناك مَوانع
 */
function pur_invoice_unpost_relations_block_error(PDO $pdo, int $invoiceId): ?string
{
    if ($invoiceId < 1) {
        return 'معرّف الفاتورة غير صالح.';
    }

    try {
        $st = $pdo->prepare('SELECT id FROM pur_invoice WHERE id = ? LIMIT 1');
        $st->execute([$invoiceId]);
        if (!$st->fetch()) {
            return 'الفاتورة غير موجودة.';
        }
    } catch (Throwable $e) {
        return 'تعذر التحقق من الفاتورة.';
    }

    if (pur_return_has_tables($pdo)) {
        try {
            $ret = $pdo->prepare(
                "SELECT id FROM pur_return WHERE invoice_id = ? AND status <> 'cancelled' LIMIT 1"
            );
            $ret->execute([$invoiceId]);
            if ($ret->fetch()) {
                return 'لا يمكن فك ترحيل الفاتورة لوجود مردود مشتريات مرتبط بها.';
            }
        } catch (Throwable $e) {
            return 'تعذر التحقق من مردودات المشتريات.';
        }
    }

    return null;
}

/**
 * هل توجد آثار ترحيل على فاتورة الشراء؟ (قيد محاسبي / ذمة مورد / حركات مخزون).
 */
function pur_invoice_has_posting_artifacts(PDO $pdo, int $invoiceId): bool
{
    if ($invoiceId < 1) {
        return false;
    }

    if (crm_supplier_ledger_has_table($pdo) && crm_supplier_ledger_exists($pdo, 'purchase_invoice', $invoiceId)) {
        return true;
    }

    if (inv_stock_move_has_table($pdo)) {
        try {
            $st = $pdo->prepare(
                "SELECT id FROM inv_stock_move WHERE ref_type = 'purchase_invoice' AND ref_id = ? LIMIT 1"
            );
            $st->execute([$invoiceId]);
            if ($st->fetch()) {
                return true;
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    if (acc_gl_ref_exists($pdo, 'purchase_invoice', $invoiceId)) {
        return true;
    }

    return pur_invoice_is_fully_posted($pdo, $invoiceId);
}

/**
 * فك ترحيل فاتورة شراء بالكامل:
 *   - عكس القيد المحاسبي تلقائياً (acc_gl_unpost_ref).
 *   - حذف حركات إدخال المخزون (inv_stock_move ref_type = 'purchase_invoice').
 *   - حذف أثر ذمة المورد (crm_supplier_ledger txn_type = 'purchase_invoice').
 *
 * المُعالجَة كاملة داخل المُتَّصِل، لكن نُنَفِّذها بشكل آمن وقابل لإعادة الترحيل لاحقاً.
 *
 * @return array{ok:bool, error:?string, message:?string}
 */
function pur_invoice_unpost_by_id(PDO $pdo, int $invoiceId): array
{
    $block = pur_invoice_unpost_relations_block_error($pdo, $invoiceId);
    if ($block !== null) {
        return ['ok' => false, 'error' => $block, 'message' => null];
    }

    if (!pur_invoice_has_posting_artifacts($pdo, $invoiceId)) {
        return [
            'ok' => true,
            'error' => null,
            'message' => 'لا توجد آثار ترحيل على هذه الفاتورة.',
        ];
    }

    $gl = acc_gl_unpost_ref($pdo, 'purchase_invoice', $invoiceId);
    if (!$gl['ok']) {
        return [
            'ok' => false,
            'error' => $gl['error'] ?? 'تعذر إلغاء الترحيل المحاسبي.',
            'message' => null,
        ];
    }

    pur_invoice_delete_cleanup_posting_artifacts($pdo, $invoiceId);

    if (pur_invoice_is_fully_posted($pdo, $invoiceId)) {
        return [
            'ok' => false,
            'error' => 'تعذر إلغاء الترحيل بالكامل (ما زالت الفاتورة تظهر كمرحّلة).',
            'message' => null,
        ];
    }

    require_once app_path('includes/sys_audit_log.php');
    sys_audit_log_pur_invoice($pdo, 'unpost', $invoiceId);

    return [
        'ok' => true,
        'error' => null,
        'message' => 'تم إلغاء ترحيل فاتورة المشتريات.',
    ];
}
