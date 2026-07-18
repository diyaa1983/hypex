<?php
declare(strict_types=1);

require_once app_path('includes/fin_voucher_schema.php');
require_once app_path('includes/crm_customer_ledger.php');
require_once app_path('includes/crm_supplier_ledger.php');

/**
 * تهيئة الجداول/الأعمدة اللازمة لترحيل سند الصرف — يجب استدعاؤها قبل beginTransaction.
 */
function fin_voucher_prepare_payment_post_schemas(PDO $pdo): void
{
    crm_supplier_ledger_ensure_schema($pdo);
    crm_ledger_ensure_schema($pdo);
    require_once app_path('includes/acc_gl.php');
    acc_gl_ensure_schema($pdo);
    require_once app_path('includes/sys_audit_log.php');
    sys_audit_log_ensure_schema($pdo);
    if (fin_voucher_has_column($pdo, 'hr_advance_id')) {
        require_once app_path('includes/hr_employee_advance.php');
        hr_employee_advance_ensure_schema($pdo);
    }
}

/**
 * @return array{ok:bool, skipped:bool, error:?string}
 */
function fin_voucher_post_payment_ledger_by_id(PDO $pdo, int $voucherId): array
{
    $row = fin_voucher_load($pdo, $voucherId, 'payment');
    if (!$row) {
        return ['ok' => false, 'skipped' => false, 'error' => 'سند الصرف غير موجود.'];
    }
    // شيك صادر: أثر كشف العميل/المورد عند صرف الشيك فقط.
    if ((string) ($row['pay_method'] ?? '') === 'check') {
        return ['ok' => true, 'skipped' => true, 'error' => null];
    }
    $partyType = (string) ($row['party_type'] ?? '');
    if ($partyType === 'supplier') {
        return crm_supplier_ledger_post_cash_payment_by_id($pdo, $voucherId);
    }
    if ($partyType === 'customer') {
        return crm_ledger_post_cash_payment_by_id($pdo, $voucherId);
    }
    if ($partyType === 'employee' || $partyType === 'account') {
        return ['ok' => true, 'skipped' => false, 'error' => null];
    }

    return ['ok' => false, 'skipped' => false, 'error' => 'يجب ربط السند بعميل أو مورد قبل الترحيل.'];
}

/**
 * ترحيل سند قبض — قيد دائن على حساب العميل.
 *
 * @param list<int> $voucherIds
 * @return array{posted:int, skipped:int, errors:list<string>}
 */
function fin_voucher_post_receipts_by_ids(PDO $pdo, array $voucherIds): array
{
    $out = ['posted' => 0, 'skipped' => 0, 'errors' => []];

    foreach ($voucherIds as $rawId) {
        $id = (int) $rawId;
        if ($id < 1) {
            continue;
        }
        $row = fin_voucher_load($pdo, $id, 'receipt');
        if (!$row) {
            $out['errors'][] = 'سند #' . $id . ': غير موجود.';
            continue;
        }
        if (fin_voucher_is_cancelled($pdo, $id)) {
            $out['errors'][] = 'سند #' . $id . ': ملغى ولا يمكن ترحيله.';
            continue;
        }
        if (fin_voucher_is_posted($pdo, $id)) {
            $out['skipped']++;
            continue;
        }
        $ledger = crm_ledger_post_cash_receipt_by_id($pdo, $id);
        if ($ledger['skipped']) {
            $out['skipped']++;
            if (fin_voucher_has_column($pdo, 'is_posted')) {
                $pdo->prepare('UPDATE fin_voucher SET is_posted = 1, posted_at = NOW() WHERE id = ?')
                    ->execute([$id]);
            }
            continue;
        }
        if (!$ledger['ok']) {
            $out['errors'][] = 'سند #' . $id . ': ' . ($ledger['error'] ?? 'تعذر الترحيل.');
            continue;
        }
        require_once app_path('includes/acc_gl.php');
        $gl = acc_gl_post_cash_receipt($pdo, $id);
        if (!$gl['ok'] && !$gl['skipped']) {
            $out['errors'][] = 'سند #' . $id . ' (محاسبة): ' . ($gl['error'] ?? 'تعذر الترحيل المحاسبي.');
            continue;
        }
        if (fin_voucher_has_column($pdo, 'is_posted')) {
            $pdo->prepare('UPDATE fin_voucher SET is_posted = 1, posted_at = NOW() WHERE id = ?')
                ->execute([$id]);
        }
        $out['posted']++;
        require_once app_path('includes/sys_audit_log.php');
        sys_audit_log_fin_voucher($pdo, 'post', $id, 'receipt');
    }

    return $out;
}

/**
 * ترحيل سند صرف — قيد على حساب العميل أو المورد (لا أثر قبل الترحيل).
 *
 * @param list<int> $voucherIds
 * @return array{posted:int, skipped:int, errors:list<string>}
 */
function fin_voucher_post_payments_by_ids(PDO $pdo, array $voucherIds): array
{
    $out = ['posted' => 0, 'skipped' => 0, 'errors' => []];

    foreach ($voucherIds as $rawId) {
        $id = (int) $rawId;
        if ($id < 1) {
            continue;
        }
        $row = fin_voucher_load($pdo, $id, 'payment');
        if (!$row) {
            $out['errors'][] = 'سند #' . $id . ': غير موجود.';
            continue;
        }
        if (fin_voucher_is_cancelled($pdo, $id)) {
            $out['errors'][] = 'سند #' . $id . ': ملغى ولا يمكن ترحيله.';
            continue;
        }
        if (fin_voucher_is_posted($pdo, $id)) {
            $out['skipped']++;
            continue;
        }

        // شيك صادر — لأي طرف (مورد/عميل/موظف/حساب): ترحيل السند فقط.
        // لا كشف ولا قيد ولا سلفة/راتب إلا عند «صرف» من سجل الشيكات الصادرة.
        $isCheckPayment = (string) ($row['pay_method'] ?? '') === 'check';
        if ($isCheckPayment) {
            if (fin_voucher_has_column($pdo, 'is_posted')) {
                $pdo->prepare('UPDATE fin_voucher SET is_posted = 1, posted_at = NOW() WHERE id = ?')
                    ->execute([$id]);
            }
            $out['posted']++;
            require_once app_path('includes/sys_audit_log.php');
            sys_audit_log_fin_voucher($pdo, 'post', $id, 'payment');
            continue;
        }

        $ledger = fin_voucher_post_payment_ledger_by_id($pdo, $id);
        if ($ledger['skipped']) {
            if (fin_voucher_has_column($pdo, 'is_posted')) {
                $pdo->prepare('UPDATE fin_voucher SET is_posted = 1, posted_at = NOW() WHERE id = ?')
                    ->execute([$id]);
                $out['skipped']++;
                continue;
            }
            $out['skipped']++;
            continue;
        }
        if (!$ledger['ok']) {
            $out['errors'][] = 'سند #' . $id . ': ' . ($ledger['error'] ?? 'تعذر الترحيل.');
            continue;
        }
        require_once app_path('includes/acc_gl.php');
        $gl = acc_gl_post_cash_payment($pdo, $id);
        if (!$gl['ok'] && !$gl['skipped']) {
            $out['errors'][] = 'سند #' . $id . ' (محاسبة): ' . ($gl['error'] ?? 'تعذر الترحيل المحاسبي.');
            continue;
        }
        if (fin_voucher_has_column($pdo, 'is_posted')) {
            $pdo->prepare('UPDATE fin_voucher SET is_posted = 1, posted_at = NOW() WHERE id = ?')
                ->execute([$id]);
        }
        require_once app_path('includes/hr_employee_advance.php');
        if (fin_voucher_has_column($pdo, 'hr_advance_id')) {
            $advId = (int) ($row['hr_advance_id'] ?? 0);
            if ($advId > 0) {
                hr_employee_advance_mark_disbursed($pdo, $advId, $id);
            }
        }
        if (fin_voucher_has_column($pdo, 'hr_salary_id')) {
            require_once app_path('includes/hr_salary.php');
            $salId = (int) ($row['hr_salary_id'] ?? 0);
            if ($salId > 0) {
                hr_salary_mark_disbursed($pdo, $salId, $id);
            }
        }
        $out['posted']++;
        require_once app_path('includes/sys_audit_log.php');
        sys_audit_log_fin_voucher($pdo, 'post', $id, 'payment');
    }

    return $out;
}

/** @return array{payments:int, receipts:int} */
function fin_voucher_count_unposted(PDO $pdo, string $type): array
{
    $out = ['payments' => 0, 'receipts' => 0];
    if (!fin_voucher_has_table($pdo)) {
        return $out;
    }
    $key = $type === 'receipt' ? 'receipts' : 'payments';
    try {
        fin_voucher_ensure_cancel_columns($pdo);
        $hasCancelledCol = fin_voucher_has_column($pdo, 'is_cancelled');
        if (fin_voucher_has_column($pdo, 'is_posted')) {
            $sql = 'SELECT COUNT(*) FROM fin_voucher WHERE voucher_type = ? AND is_posted = 0';
            if ($hasCancelledCol) {
                $sql .= ' AND (is_cancelled = 0 OR is_cancelled IS NULL)';
            }
            $st = $pdo->prepare($sql);
            $st->execute([$type]);
            $out[$key] = (int) $st->fetchColumn();
        } else {
            require_once app_path('includes/crm_customer_ledger.php');
            $cancelledSql = $hasCancelledCol
                ? ' AND (v.is_cancelled = 0 OR v.is_cancelled IS NULL)'
                : '';
            if (crm_ledger_has_table($pdo)) {
                $txnType = $type === 'receipt' ? 'cash_receipt' : 'cash_payment';
                $st = $pdo->prepare(
                    'SELECT COUNT(*) FROM fin_voucher v
                     WHERE v.voucher_type = ?
                       AND NOT EXISTS (
                         SELECT 1 FROM crm_customer_ledger l
                         WHERE l.txn_type = ? AND l.ref_id = v.id
                       )' . $cancelledSql
                );
                $st->execute([$type, $txnType]);
                $out[$key] = (int) $st->fetchColumn();
            }
        }
    } catch (Throwable $e) {
        $out[$key] = 0;
    }

    return $out;
}
