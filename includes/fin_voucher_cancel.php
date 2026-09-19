<?php
declare(strict_types=1);

require_once app_path('includes/fin_voucher.php');
require_once app_path('includes/fin_voucher_schema.php');
require_once app_path('includes/fin_voucher_unpost.php');

/**
 * @return array{ok:bool, error:?string, message:?string}
 */
function fin_voucher_cancel_receipt_by_id(PDO $pdo, int $voucherId): array
{
    return fin_voucher_cancel_by_id($pdo, $voucherId, 'receipt');
}

/**
 * @return array{ok:bool, error:?string, message:?string}
 */
function fin_voucher_cancel_payment_by_id(PDO $pdo, int $voucherId): array
{
    return fin_voucher_cancel_by_id($pdo, $voucherId, 'payment');
}

/**
 * @return array{ok:bool, error:?string, message:?string}
 */
function fin_voucher_cancel_by_id(PDO $pdo, int $voucherId, string $type): array
{
    $out = ['ok' => false, 'error' => null, 'message' => null];
    if ($voucherId < 1 || !fin_voucher_type_valid($type)) {
        $out['error'] = 'معرّف السند غير صالح.';

        return $out;
    }

    fin_voucher_ensure_cancel_columns($pdo);

    if (fin_voucher_is_cancelled($pdo, $voucherId)) {
        $out['error'] = 'السند ملغى مسبقاً.';

        return $out;
    }

    $row = fin_voucher_load($pdo, $voucherId, $type);
    if (!$row) {
        $out['error'] = $type === 'receipt' ? 'سند القبض غير موجود.' : 'سند الصرف غير موجود.';

        return $out;
    }

    $wasPosted = fin_voucher_is_posted($pdo, $voucherId)
        || fin_voucher_has_posting_artifacts($pdo, $voucherId, $type);

    if (!$wasPosted) {
        $out['error'] = 'يمكن إلغاء السندات المرحّلة فقط. للمسودات استخدم الحذف.';

        return $out;
    }

    $unpost = fin_voucher_unpost_by_id($pdo, $voucherId, $type);
    if (!$unpost['ok']) {
        $out['error'] = $unpost['error'] ?? 'تعذر إلغاء الآثار المحاسبية.';

        return $out;
    }

    $uid = (int) (current_user()['id'] ?? 0) ?: null;
    $pdo->prepare(
        'UPDATE fin_voucher SET is_cancelled = 1, cancelled_at = NOW(), cancelled_by = ?,
         is_posted = 0, posted_at = NULL WHERE id = ? AND voucher_type = ?'
    )->execute([$uid, $voucherId, $type]);

    $out['ok'] = true;
    $out['message'] = $type === 'receipt'
        ? 'تم إلغاء سند القبض. يبقى السند في السجل برقم «' . (string) ($row['voucher_no'] ?? '') . '».'
        : 'تم إلغاء سند الصرف. يبقى السند في السجل برقم «' . (string) ($row['voucher_no'] ?? '') . '».';

    require_once app_path('includes/sys_audit_log.php');
    sys_audit_log_fin_voucher($pdo, 'cancel', $voucherId, $type);

    return $out;
}
