<?php
declare(strict_types=1);

require_once app_path('includes/fin_voucher.php');
require_once app_path('includes/fin_voucher_schema.php');
require_once app_path('includes/crm_customer_ledger.php');
require_once app_path('includes/crm_supplier_ledger.php');
require_once app_path('includes/acc_gl.php');

/**
 * @return array{ok:bool, error:?string, message:?string}
 */
function fin_voucher_unpost_payment_by_id(PDO $pdo, int $voucherId): array
{
    return fin_voucher_unpost_by_id($pdo, $voucherId, 'payment');
}

/**
 * @return array{ok:bool, error:?string, message:?string}
 */
function fin_voucher_unpost_receipt_by_id(PDO $pdo, int $voucherId): array
{
    return fin_voucher_unpost_by_id($pdo, $voucherId, 'receipt');
}

/**
 * @param list<int> $voucherIds
 * @return array{unposted:int, skipped:int, errors:list<string>}
 */
function fin_voucher_unpost_payments_by_ids(PDO $pdo, array $voucherIds): array
{
    return fin_voucher_unpost_many($pdo, $voucherIds, 'payment');
}

/**
 * @param list<int> $voucherIds
 * @return array{unposted:int, skipped:int, errors:list<string>}
 */
function fin_voucher_unpost_receipts_by_ids(PDO $pdo, array $voucherIds): array
{
    return fin_voucher_unpost_many($pdo, $voucherIds, 'receipt');
}

/**
 * @param list<int> $voucherIds
 * @return array{unposted:int, skipped:int, errors:list<string>}
 */
function fin_voucher_unpost_many(PDO $pdo, array $voucherIds, string $type): array
{
    $out = ['unposted' => 0, 'skipped' => 0, 'errors' => []];
    foreach ($voucherIds as $rawId) {
        $id = (int) $rawId;
        if ($id < 1) {
            continue;
        }
        if (!fin_voucher_is_posted($pdo, $id) && !fin_voucher_has_posting_artifacts($pdo, $id, $type)) {
            $out['skipped']++;
            continue;
        }
        $one = fin_voucher_unpost_by_id($pdo, $id, $type);
        if (!$one['ok']) {
            $out['errors'][] = 'سند #' . $id . ': ' . ($one['error'] ?? 'تعذر إلغاء الترحيل.');
            continue;
        }
        $out['unposted']++;
    }

    return $out;
}

function fin_voucher_has_posting_artifacts(PDO $pdo, int $voucherId, string $type): bool
{
    if ($voucherId < 1) {
        return false;
    }
    $row = fin_voucher_load($pdo, $voucherId, $type);
    if (!$row) {
        return false;
    }
    if (acc_gl_ref_exists($pdo, $type === 'receipt' ? 'cash_receipt' : 'cash_payment', $voucherId)) {
        return true;
    }
    $partyType = (string) ($row['party_type'] ?? '');
    if ($type === 'payment') {
        if ($partyType === 'supplier') {
            return crm_supplier_ledger_cash_payment_is_posted($pdo, $voucherId);
        }
        if ($partyType === 'customer') {
            return crm_ledger_cash_payment_is_posted($pdo, $voucherId);
        }
    } else {
        return crm_ledger_cash_receipt_is_posted($pdo, $voucherId);
    }

    return false;
}

/**
 * إلغاء ترحيل سند قبض/صرف: كشف الطرف + القيد التلقائي.
 *
 * @return array{ok:bool, error:?string, message:?string}
 */
function fin_voucher_unpost_by_id(PDO $pdo, int $voucherId, string $type): array
{
    $out = ['ok' => false, 'error' => null, 'message' => null];
    if ($voucherId < 1 || !fin_voucher_type_valid($type)) {
        $out['error'] = 'معرّف السند غير صالح.';

        return $out;
    }

    $row = fin_voucher_load($pdo, $voucherId, $type);
    if (!$row) {
        $out['error'] = $type === 'receipt' ? 'سند القبض غير موجود.' : 'سند الصرف غير موجود.';

        return $out;
    }

    if (!fin_voucher_has_posting_artifacts($pdo, $voucherId, $type)) {
        if (fin_voucher_has_column($pdo, 'is_posted')) {
            $pdo->prepare('UPDATE fin_voucher SET is_posted = 0, posted_at = NULL WHERE id = ? AND voucher_type = ?')
                ->execute([$voucherId, $type]);
        }
        $out['ok'] = true;
        $out['message'] = 'لا توجد آثار ترحيل على هذا السند.';

        return $out;
    }

    crm_supplier_ledger_ensure_schema($pdo);
    crm_ledger_ensure_schema($pdo);

    $partyType = (string) ($row['party_type'] ?? '');
    if ($type === 'payment') {
        if ($partyType === 'supplier') {
            crm_supplier_ledger_unpost_cash_payment($pdo, $voucherId);
        } elseif ($partyType === 'customer') {
            crm_ledger_unpost_cash_payment($pdo, $voucherId);
        }
        $glRef = 'cash_payment';
    } else {
        crm_ledger_unpost_cash_receipt($pdo, $voucherId);
        $glRef = 'cash_receipt';
    }

    $gl = acc_gl_unpost_ref($pdo, $glRef, $voucherId);
    if (!$gl['ok']) {
        $out['error'] = $gl['error'] ?? 'تعذر إلغاء الترحيل المحاسبي.';

        return $out;
    }

    if ($type === 'payment' && fin_voucher_has_column($pdo, 'hr_advance_id')) {
        require_once app_path('includes/hr_employee_advance.php');
        hr_employee_advance_clear_disbursement_by_voucher($pdo, $voucherId);
    }
    if ($type === 'payment' && fin_voucher_has_column($pdo, 'hr_salary_id')) {
        require_once app_path('includes/hr_salary.php');
        hr_salary_clear_disbursement_by_voucher($pdo, $voucherId);
    }

    if (fin_voucher_has_column($pdo, 'is_posted')) {
        $pdo->prepare('UPDATE fin_voucher SET is_posted = 0, posted_at = NULL WHERE id = ? AND voucher_type = ?')
            ->execute([$voucherId, $type]);
    }

    if (fin_voucher_has_posting_artifacts($pdo, $voucherId, $type)) {
        $out['error'] = 'تعذر إلغاء الترحيل بالكامل (ما زالت هناك حركات مرتبطة).';

        return $out;
    }

    $out['ok'] = true;
    $out['message'] = $type === 'receipt'
        ? 'تم إلغاء ترحيل سند القبض. يمكنك تعديله أو حذفه.'
        : 'تم إلغاء ترحيل سند الصرف. يمكنك تعديله أو حذفه.';

    require_once app_path('includes/sys_audit_log.php');
    sys_audit_log_fin_voucher($pdo, 'unpost', $voucherId, $type);

    return $out;
}
