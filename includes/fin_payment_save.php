<?php
declare(strict_types=1);

require_once app_path('includes/fin_payment_parties.php');
require_once app_path('includes/fin_voucher.php');

function handle_fin_payment_save(): void
{
    $wantsJson = request_wants_json_invoice_save();

    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        if ($wantsJson) {
            json_invoice_save_response(false, ['message' => 'انتهت صلاحية الجلسة.'], 403);
        }
        flash_set('error', 'انتهت صلاحية الجلسة.');
        redirect(app_url('index.php?r=cash_payment'));
    }

    $pdo = db();
    require_once app_path('includes/fin_voucher_schema.php');

    if (!fin_voucher_ensure_schema_full($pdo)) {
        $msg = 'جدول سندات الصرف غير موجود.';
        if ($wantsJson) {
            json_invoice_save_response(false, ['message' => $msg], 500);
        }
        flash_set('error', $msg);
        redirect(app_url('index.php?r=cash_payment'));
    }

    $cashAccounts = fin_voucher_load_cash_bank_accounts($pdo);
    if (!$cashAccounts) {
        $msg = 'لا توجد حسابات صرف (صناديق، شيكات، أو بنوك).';
        if ($wantsJson) {
            json_invoice_save_response(false, ['message' => $msg], 500);
        }
        flash_set('error', $msg);
        redirect(app_url('index.php?r=cash_payment'));
    }
    require_once app_path('includes/acc_period_lock.php');

    $id = (int) ($_POST['voucher_id'] ?? 0);
    $voucherDate = parse_date_to_iso(trim((string) ($_POST['voucher_date'] ?? ''))) ?? '';
    $partyType = fin_payment_normalize_party_type(trim((string) ($_POST['party_type'] ?? 'supplier')));
    $partyId = match ($partyType) {
        'supplier' => (int) ($_POST['supplier_id'] ?? 0),
        'customer' => (int) ($_POST['customer_id'] ?? 0),
        'employee' => (int) ($_POST['employee_id'] ?? 0),
        'account' => 0,
        default => 0,
    };
    $offsetAccountId = (int) ($_POST['offset_account_id'] ?? 0);
    $hrAdvanceId = (int) ($_POST['hr_advance_id'] ?? 0);
    $payMethod = fin_voucher_normalize_pay_method(trim((string) ($_POST['pay_method'] ?? 'cash')));
    $amount = parse_amount_input($_POST['amount'] ?? 0);
    $checkAmount = parse_amount_input($_POST['check_amount'] ?? 0);
    $effectiveAmount = $amount;
    if ($payMethod === 'check' && $checkAmount > 0) {
        $effectiveAmount = $checkAmount;
    } elseif ($payMethod === 'check' && $amount > 0) {
        $effectiveAmount = $amount;
    }
    $checkNo = trim((string) ($_POST['check_no'] ?? ''));
    $bankName = trim((string) ($_POST['bank_name'] ?? ''));
    $checkDueDate = parse_date_to_iso(trim((string) ($_POST['check_due_date'] ?? ''))) ?? '';
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $cashAccountId = (int) ($_POST['cash_account_id'] ?? 0);
    if ($cashAccountId < 1) {
        $cashAccountId = (int) ($cashAccounts[0]['id'] ?? 0);
    }

    $allowedCash = [];
    foreach ($cashAccounts as $ca) {
        $allowedCash[(int) ($ca['id'] ?? 0)] = true;
    }

    $err = '';
    if ($voucherDate === '') {
        $err = 'تاريخ السند غير صالح.';
    } elseif (($periodErr = acc_period_date_lock_error($pdo, $voucherDate)) !== null) {
        $err = $periodErr;
    } elseif ($partyType === 'supplier' && $partyId < 1) {
        $err = 'اختر المورد.';
    } elseif ($partyType === 'customer' && $partyId < 1) {
        $err = 'اختر العميل.';
    } elseif ($partyType === 'employee') {
        $err = fin_payment_validate_employee_party($pdo, $partyId) ?? '';
        $offsetAccountId = 0;
    } elseif ($partyType === 'account') {
        if ($offsetAccountId < 1) {
            $err = 'اختر الحساب المُصروف إليه من الشجرة.';
        } elseif (!fin_payment_offset_account_allowed($pdo, 'account', $offsetAccountId)) {
            $err = 'الحساب المختار غير صالح للصرف (يجب أن يكون خصوماً أو مصروفاً).';
        }
    }

    if ($err === '' && ($cashAccountId < 1 || !isset($allowedCash[$cashAccountId]))) {
        $err = 'اختر حساب الصرف (صندوق، شيكات، أو بنك) الذي يُخصم منه المبلغ.';
    } elseif ($err === '' && $payMethod === 'bank') {
        if (fin_voucher_cash_account_group($cashAccounts, $cashAccountId) !== 'bank') {
            $err = 'اختر حساب بنك يُخصم منه المبلغ.';
        }
    } elseif ($err === '' && $payMethod === 'cash') {
        $cashGroup = fin_voucher_cash_account_group($cashAccounts, $cashAccountId);
        if ($cashGroup !== null && $cashGroup !== 'cash') {
            $err = 'عند الدفع نقداً اختر حساباً من الصناديق.';
        }
    } elseif ($err === '' && $payMethod === 'check') {
        $checkGroup = fin_voucher_cash_account_group($cashAccounts, $cashAccountId);
        if ($checkGroup !== null && $checkGroup !== 'bank') {
            $err = 'عند الدفع بشيك اختر حساباً من البنوك.';
        }
        if ($checkAmount <= 0 && $amount <= 0) {
            $err = 'أدخل قيمة الشيك.';
        }
    } elseif ($err === '' && $effectiveAmount <= 0) {
        $err = 'أدخل المبلغ.';
    }

    if ($err === '' && $payMethod === 'check' && $checkAmount > 0) {
        $amount = $checkAmount;
    } elseif ($err === '' && $effectiveAmount > 0) {
        $amount = $effectiveAmount;
    }

    if ($err === '' && $hrAdvanceId > 0) {
        require_once app_path('includes/hr_employee_advance.php');
        $advance = hr_employee_advance_load($pdo, $hrAdvanceId);
        if (!$advance) {
            $err = 'السلفة غير موجودة.';
        } elseif ($partyType === 'employee') {
            $err = hr_employee_advance_validate_for_disbursement($pdo, $hrAdvanceId, $partyId, $amount, $id) ?? '';
        }
    }

    if ($err !== '') {
        if ($wantsJson) {
            json_invoice_save_response(false, ['message' => $err], 400);
        }
        flash_set('error', $err);
        redirect(app_url('index.php?r=cash_payment' . ($id > 0 ? '&id=' . $id : '')));
    }

    try {
        $pdo->beginTransaction();
        $savedId = fin_voucher_save(
            $pdo,
            'payment',
            $id,
            trim((string) ($_POST['voucher_no'] ?? '')),
            $voucherDate,
            $amount,
            $notes,
            $checkNo,
            $partyType,
            $partyId,
            $cashAccountId,
            $payMethod,
            $checkAmount,
            $bankName,
            null,
            $offsetAccountId
        );
        if ($payMethod === 'check') {
            fin_payment_sync_outgoing_check_row(
                $pdo,
                $savedId,
                $checkNo,
                $bankName,
                $amount,
                $checkDueDate
            );
        }
        fin_payment_save_apply_employee_hr_links(
            $pdo,
            $savedId,
            $hrAdvanceId > 0 ? 'advance' : 'other',
            $hrAdvanceId,
            0
        );
        $pdo->commit();

        require_once app_path('includes/sys_audit_log.php');
        sys_audit_log_fin_voucher($pdo, 'save', $savedId, 'payment');

        require_once app_path('includes/fin_voucher_load.php');
        $row = fin_voucher_fetch_by_id($pdo, $savedId, 'payment');

        if ($wantsJson) {
            json_invoice_save_response(true, [
                'message' => $id > 0 ? 'تم تحديث سند الصرف.' : 'تم حفظ سند الصرف.',
                'voucher_id' => $savedId,
                'voucher_no' => (string) ($row['voucher_no'] ?? ''),
                'is_posted' => fin_voucher_is_posted($pdo, $savedId),
            ]);
        }
        flash_set('success', $id > 0 ? 'تم تحديث سند الصرف.' : 'تم حفظ سند الصرف.');
        redirect(app_url('index.php?r=cash_payment&id=' . $savedId));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $msg = $e->getMessage() !== '' ? $e->getMessage() : 'تعذر حفظ السند.';
        if ($wantsJson) {
            json_invoice_save_response(false, ['message' => $msg], 500);
        }
        flash_set('error', $msg);
        redirect(app_url('index.php?r=cash_payment' . ($id > 0 ? '&id=' . $id : '')));
    }
}

/**
 * يزامن صف الشيك الواحد لسند الصرف (رقم/بنك/مبلغ/تاريخ الصرف) دون مسح رقم التسجيل.
 */
function fin_payment_sync_outgoing_check_row(
    PDO $pdo,
    int $voucherId,
    string $checkNo,
    string $bankName,
    float $amount,
    string $dueDateIso
): void {
    if ($voucherId < 1 || $amount <= 0.000001) {
        return;
    }

    require_once app_path('includes/fin_voucher_checks.php');
    require_once app_path('includes/fin_outgoing_check_register.php');
    if (!fin_voucher_checks_ensure_table($pdo)) {
        return;
    }

    $checkNo = trim($checkNo);
    $bankName = trim($bankName);
    $dueDateIso = trim($dueDateIso);
    if ($dueDateIso !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDateIso)) {
        $dueDateIso = '';
    }

    $st = $pdo->prepare(
        'SELECT id FROM fin_voucher_check
         WHERE voucher_id = ?
         ORDER BY sort_order ASC, id ASC
         LIMIT 1'
    );
    $st->execute([$voucherId]);
    $checkId = (int) $st->fetchColumn();

    if ($checkId > 0) {
        $pdo->prepare(
            'UPDATE fin_voucher_check
             SET check_no = ?, bank_name = ?, check_amount = ?, due_date = ?
             WHERE id = ?'
        )->execute([
            $checkNo !== '' ? $checkNo : null,
            $bankName !== '' ? $bankName : null,
            round($amount, 6),
            $dueDateIso !== '' ? $dueDateIso : null,
            $checkId,
        ]);
    } else {
        $pdo->prepare(
            'INSERT INTO fin_voucher_check
                (voucher_id, sort_order, check_no, bank_name, check_amount, due_date, notes, lifecycle_status)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([
            $voucherId,
            1,
            $checkNo !== '' ? $checkNo : null,
            $bankName !== '' ? $bankName : null,
            round($amount, 6),
            $dueDateIso !== '' ? $dueDateIso : null,
            null,
            'pending',
        ]);
    }

    fin_outgoing_check_register_sync_voucher($pdo, $voucherId);
}
