<?php
declare(strict_types=1);

require_once app_path('includes/sal_invoice_post.php');
require_once app_path('includes/sal_invoice_delete.php');
require_once app_path('includes/crm_customer_ledger.php');
require_once app_path('includes/acc_gl.php');
require_once app_path('includes/sal_invoice_gps.php');

/**
 * أخطاء تمنع الحذف أو إلغاء الترحيل (مردود، فوترة إلكترونية، …).
 */
function sal_invoice_relations_block_error(PDO $pdo, int $invoiceId): ?string
{
    if ($invoiceId < 1) {
        return 'معرّف الفاتورة غير صالح.';
    }

    try {
        $st = $pdo->prepare('SELECT id FROM sal_invoice WHERE id = ? LIMIT 1');
        $st->execute([$invoiceId]);
        if (!$st->fetch()) {
            return 'الفاتورة غير موجودة.';
        }
    } catch (Throwable $e) {
        return 'تعذر التحقق من الفاتورة.';
    }

    try {
        require_once app_path('includes/einvoice_schema.php');
        require_once app_path('includes/einvoice_settings.php');
        if (einvoice_column_exists($pdo, 'sal_invoice', 'einv_qr') && einvoice_sale_is_sent($pdo, $invoiceId)) {
            return 'لا يمكن إلغاء ترحيل أو حذف فاتورة أُرسلت إلى نظام الفوترة.';
        }
    } catch (Throwable $e) {
        // ignore
    }

    require_once app_path('includes/sal_return_schema.php');
    if (sal_return_has_tables($pdo)) {
        try {
            $ret = $pdo->prepare('SELECT id FROM sal_return WHERE invoice_id = ? LIMIT 1');
            $ret->execute([$invoiceId]);
            if ($ret->fetch()) {
                return 'لا يمكن إلغاء ترحيل أو حذف الفاتورة لوجود مردود مبيعات مرتبط بها.';
            }
        } catch (Throwable $e) {
            return 'تعذر التحقق من مردودات المبيعات.';
        }
    }

    return null;
}

/**
 * مسح كل حقول إرسال الفوترة الإلكترونية محلياً على فاتورة المبيعات.
 * يُستدعى قبل فك الترحيل (إن كانت الفاتورة مُرسَلة للفوترة).
 * ملاحظة: لا يمسّ هذا قيد الفاتورة في منظومة الفوترة الأردنية — يجب إلغاؤه هناك يدوياً إن لزم.
 *
 * @return bool true إذا كانت الفاتورة مُرسَلة وتمّ مسحها محلياً
 */
function sal_invoice_clear_einvoice_data(PDO $pdo, int $invoiceId): bool
{
    if ($invoiceId < 1) {
        return false;
    }
    try {
        require_once app_path('includes/einvoice_schema.php');
        require_once app_path('includes/einvoice_settings.php');
    } catch (Throwable $e) {
        return false;
    }

    if (!einvoice_column_exists($pdo, 'sal_invoice', 'einv_qr')) {
        return false;
    }

    $wasSent = false;
    try {
        $st = $pdo->prepare('SELECT einv_qr FROM sal_invoice WHERE id = ? LIMIT 1');
        $st->execute([$invoiceId]);
        $qr = (string) ($st->fetchColumn() ?: '');
        $wasSent = trim($qr) !== '';
    } catch (Throwable $e) {
        $wasSent = false;
    }

    $cols = ['einv_status', 'einv_results', 'einv_signed_invoice', 'einv_qr', 'einv_num', 'einv_inv_uuid', 'einv_sent_at'];
    $sets = [];
    foreach ($cols as $col) {
        if (einvoice_column_exists($pdo, 'sal_invoice', $col)) {
            $sets[] = "`{$col}` = NULL";
        }
    }
    if ($sets !== []) {
        try {
            $pdo->prepare('UPDATE sal_invoice SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute([$invoiceId]);
        } catch (Throwable $e) {
            // ignore
        }
    }

    return $wasSent;
}

function sal_invoice_has_posting_artifacts(PDO $pdo, int $invoiceId): bool
{
    if ($invoiceId < 1) {
        return false;
    }
    if (crm_ledger_has_table($pdo) && crm_ledger_exists($pdo, 'sale_invoice', $invoiceId)) {
        return true;
    }
    if (inv_stock_move_has_table($pdo)) {
        try {
            $st = $pdo->prepare(
                "SELECT id FROM inv_stock_move WHERE ref_type = 'sale_invoice' AND ref_id = ? LIMIT 1"
            );
            $st->execute([$invoiceId]);
            if ($st->fetch()) {
                return true;
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
    if (acc_gl_ref_exists($pdo, 'sale_invoice', $invoiceId)) {
        return true;
    }

    return sal_invoice_is_fully_posted($pdo, $invoiceId);
}

/**
 * إلغاء ترحيل فاتورة بيع: قيد محاسبي تلقائي + ذمم العميل + حركات المخزون.
 *
 * @return array{ok:bool, error:?string, message:?string}
 */
function sal_invoice_unpost_by_id(PDO $pdo, int $invoiceId): array
{
    $block = sal_invoice_relations_block_error($pdo, $invoiceId);
    if ($block !== null) {
        return ['ok' => false, 'error' => $block, 'message' => null];
    }

    if (!sal_invoice_has_posting_artifacts($pdo, $invoiceId)) {
        sal_invoice_gps_clear_on_unpost($pdo, $invoiceId);

        return [
            'ok' => true,
            'error' => null,
            'message' => 'لا توجد آثار ترحيل على هذه الفاتورة.',
        ];
    }

    $gl = acc_gl_unpost_ref($pdo, 'sale_invoice', $invoiceId);
    if (!$gl['ok']) {
        return ['ok' => false, 'error' => $gl['error'] ?? 'تعذر إلغاء الترحيل المحاسبي.', 'message' => null];
    }

    // لا نفك ربط سند التسليم عند فك الترحيل — الربط يُزال عند الحذف فقط.
    sal_invoice_delete_cleanup_posting_artifacts($pdo, $invoiceId, false);
    sal_invoice_gps_clear_on_unpost($pdo, $invoiceId);

    if (sal_invoice_is_fully_posted($pdo, $invoiceId)) {
        return [
            'ok' => false,
            'error' => 'تعذر إلغاء الترحيل بالكامل (ما زالت الفاتورة تظهر كمرحّلة).',
            'message' => null,
        ];
    }

    require_once app_path('includes/sys_audit_log.php');
    sys_audit_log_sal_invoice($pdo, 'unpost', $invoiceId);

    return [
        'ok' => true,
        'error' => null,
        'message' => 'تم إلغاء ترحيل الفاتورة.',
    ];
}
