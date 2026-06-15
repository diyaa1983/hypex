<?php
declare(strict_types=1);

require_once app_path('includes/sal_return_post.php');
require_once app_path('includes/crm_customer_ledger.php');
require_once app_path('includes/inv_stock.php');
require_once app_path('includes/acc_gl.php');

/**
 * عدد مرتجعات أخرى مرحّلة لنفس فاتورة البيع.
 */
function sal_return_other_posted_count(PDO $pdo, int $invoiceId, int $excludeReturnId): int
{
    if ($invoiceId < 1) {
        return 0;
    }
    $st = $pdo->prepare(
        "SELECT id FROM sal_return
         WHERE invoice_id = ? AND status = 'confirmed' AND id <> ?"
    );
    $st->execute([$invoiceId, $excludeReturnId]);
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $raw) {
        $rid = (int) $raw;
        if ($rid > 0 && sal_return_is_posted($pdo, $rid)) {
            $n++;
        }
    }

    return $n;
}

/** هل توجد آثار ترحيل على المرتجع؟ */
function sal_return_has_posting_artifacts(PDO $pdo, int $returnId): bool
{
    if ($returnId < 1) {
        return false;
    }

    if (crm_ledger_has_table($pdo) && crm_ledger_exists($pdo, 'sale_return', $returnId)) {
        return true;
    }

    if (inv_stock_move_has_table($pdo)) {
        try {
            $st = $pdo->prepare(
                "SELECT id FROM inv_stock_move WHERE ref_type = 'sale_return' AND ref_id = ? LIMIT 1"
            );
            $st->execute([$returnId]);
            if ($st->fetch()) {
                return true;
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    if (acc_gl_ref_exists($pdo, 'sale_return', $returnId)) {
        return true;
    }

    return sal_return_is_posted($pdo, $returnId);
}

/**
 * إعادة فاتورة بيع «ذمم بعد تصحيح» إلى قيد نقدي متوازن (عكس تحويل الترحيل عند المرتجع).
 */
function crm_ledger_revert_credit_sale_invoice_to_cash(PDO $pdo, int $invoiceId): bool
{
    if ($invoiceId < 1 || !crm_ledger_has_table($pdo)) {
        return false;
    }

    $st = $pdo->prepare(
        "SELECT id, debit, credit, payment_type, ref_no
         FROM crm_customer_ledger
         WHERE txn_type = 'sale_invoice' AND ref_id = ?
         LIMIT 1"
    );
    $st->execute([$invoiceId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }

    $eps = 0.000001;
    $debit = (float) ($row['debit'] ?? 0);
    $credit = (float) ($row['credit'] ?? 0);
    $pay = strtolower(trim((string) ($row['payment_type'] ?? '')));

    if ($pay !== 'credit' || $debit <= $eps || $credit > $eps) {
        return false;
    }

    $refNo = (string) ($row['ref_no'] ?? '');
    $memo = 'فاتورة بيع ' . $refNo . ' — نقدي';

    $pdo->prepare(
        'UPDATE crm_customer_ledger
         SET payment_type = ?, credit = debit, memo = ?
         WHERE id = ?'
    )->execute(['cash', $memo, (int) ($row['id'] ?? 0)]);

    return true;
}

/** إزالة آثار ترحيل المرتجع (مخزون + دفتر عميل). */
function sal_return_unpost_cleanup_posting_artifacts(PDO $pdo, int $returnId): void
{
    if ($returnId < 1) {
        return;
    }

    $invoiceId = 0;
    try {
        $st = $pdo->prepare('SELECT invoice_id FROM sal_return WHERE id = ? LIMIT 1');
        $st->execute([$returnId]);
        $invoiceId = (int) $st->fetchColumn();
    } catch (Throwable $e) {
        $invoiceId = 0;
    }

    if (inv_stock_move_has_table($pdo)) {
        try {
            $pdo->prepare(
                "DELETE FROM inv_stock_move WHERE ref_type = 'sale_return' AND ref_id = ?"
            )->execute([$returnId]);
        } catch (Throwable $e) {
            // ignore
        }
    }

    crm_ledger_delete_sale_return_customer_row($pdo, $returnId);

    if ($invoiceId > 0
        && sal_invoice_payment_type_is_cash($pdo, $invoiceId)
        && sal_return_other_posted_count($pdo, $invoiceId, $returnId) === 0
    ) {
        crm_ledger_revert_credit_sale_invoice_to_cash($pdo, $invoiceId);
    }
}

/**
 * مسح حقول إرسال الفوترة الإلكترونية محلياً على مرتجع المبيعات.
 *
 * @return bool true إذا كان مُرسَلاً وتمّ المسح
 */
function sal_return_clear_einvoice_data(PDO $pdo, int $returnId): bool
{
    if ($returnId < 1) {
        return false;
    }
    try {
        require_once app_path('includes/einvoice_schema.php');
    } catch (Throwable $e) {
        return false;
    }

    if (!einvoice_column_exists($pdo, 'sal_return', 'einv_qr')) {
        return false;
    }

    $wasSent = false;
    try {
        $st = $pdo->prepare('SELECT einv_qr FROM sal_return WHERE id = ? LIMIT 1');
        $st->execute([$returnId]);
        $wasSent = trim((string) ($st->fetchColumn() ?: '')) !== '';
    } catch (Throwable $e) {
        $wasSent = false;
    }

    $cols = ['einv_status', 'einv_results', 'einv_signed_invoice', 'einv_qr', 'einv_num', 'einv_sent_at', 'invoice_uuid'];
    $sets = [];
    foreach ($cols as $col) {
        if (einvoice_column_exists($pdo, 'sal_return', $col)) {
            $sets[] = "`{$col}` = NULL";
        }
    }
    if ($sets !== []) {
        try {
            $pdo->prepare('UPDATE sal_return SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute([$returnId]);
        } catch (Throwable $e) {
            // ignore
        }
    }

    return $wasSent;
}

/**
 * فك ترحيل مرتجع مبيعات: عكس القيد المحاسبي + حركات المستودع + أثر العميل.
 *
 * @return array{ok:bool, error:?string, message:?string}
 */
function sal_return_unpost_by_id(PDO $pdo, int $returnId): array
{
    if ($returnId < 1) {
        return ['ok' => false, 'error' => 'معرّف المرتجع غير صالح.', 'message' => null];
    }

    $st = $pdo->prepare('SELECT id, status FROM sal_return WHERE id = ? LIMIT 1');
    $st->execute([$returnId]);
    $hdr = $st->fetch(PDO::FETCH_ASSOC);
    if (!$hdr) {
        return ['ok' => false, 'error' => 'المرتجع غير موجود.', 'message' => null];
    }
    if ((string) ($hdr['status'] ?? '') === 'cancelled') {
        return ['ok' => false, 'error' => 'لا يمكن فك ترحيل مرتجع ملغى.', 'message' => null];
    }

    if (sal_return_einvoice_is_sent($pdo, $returnId)) {
        return [
            'ok' => false,
            'error' => 'لا يمكن فك ترحيل مرتجع أُرسل إلى نظام الفوترة.',
            'message' => null,
        ];
    }

    if (!sal_return_has_posting_artifacts($pdo, $returnId)) {
        return [
            'ok' => true,
            'error' => null,
            'message' => 'لا توجد آثار ترحيل على هذا المرتجع.',
        ];
    }

    $gl = acc_gl_unpost_ref($pdo, 'sale_return', $returnId);
    if (!$gl['ok']) {
        return [
            'ok' => false,
            'error' => $gl['error'] ?? 'تعذر إلغاء الترحيل المحاسبي.',
            'message' => null,
        ];
    }

    sal_return_unpost_cleanup_posting_artifacts($pdo, $returnId);

    if (sal_return_is_posted($pdo, $returnId)) {
        return [
            'ok' => false,
            'error' => 'تعذر إلغاء الترحيل بالكامل (ما زال المرتجع يظهر كمرحّل).',
            'message' => null,
        ];
    }

    return [
        'ok' => true,
        'error' => null,
        'message' => 'تم فك ترحيل مرتجع المبيعات (عكس القيود والمستودع وذمة العميل).',
    ];
}

function sal_return_einvoice_is_sent(PDO $pdo, int $returnId): bool
{
    if ($returnId < 1) {
        return false;
    }
    try {
        require_once app_path('includes/einvoice_schema.php');
        if (!einvoice_column_exists($pdo, 'sal_return', 'einv_qr')) {
            return false;
        }
        $st = $pdo->prepare('SELECT einv_qr FROM sal_return WHERE id = ? LIMIT 1');
        $st->execute([$returnId]);
        return trim((string) ($st->fetchColumn() ?: '')) !== '';
    } catch (Throwable $e) {
        return false;
    }
}
