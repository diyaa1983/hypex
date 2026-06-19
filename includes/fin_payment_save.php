<?php
declare(strict_types=1);

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

    $cashAccounts = fin_voucher_load_cash_accounts($pdo);
    if (!$cashAccounts) {
        $msg = 'لا توجد حسابات صرف (صندوق، بنك، أو شريك).';
        if ($wantsJson) {
            json_invoice_save_response(false, ['message' => $msg], 500);
        }
        flash_set('error', $msg);
        redirect(app_url('index.php?r=cash_payment'));
    }
    require_once app_path('includes/acc_period_lock.php');

    $id = (int) ($_POST['voucher_id'] ?? 0);
    $voucherDate = parse_date_to_iso(trim((string) ($_POST['voucher_date'] ?? ''))) ?? '';
    $partyType = trim((string) ($_POST['party_type'] ?? 'supplier'));
    if ($partyType !== 'customer' && $partyType !== 'supplier') {
        $partyType = 'supplier';
    }
    $partyId = $partyType === 'supplier'
        ? (int) ($_POST['supplier_id'] ?? 0)
        : (int) ($_POST['customer_id'] ?? 0);
    $payMethod = trim((string) ($_POST['pay_method'] ?? 'cash')) === 'check' ? 'check' : 'cash';
    $amount = (float) ($_POST['amount'] ?? 0);
    $checkAmount = (float) ($_POST['check_amount'] ?? 0);
    $checkNo = trim((string) ($_POST['check_no'] ?? ''));
    $bankName = trim((string) ($_POST['bank_name'] ?? ''));
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
    } elseif ($partyId < 1) {
        $err = $partyType === 'supplier' ? 'اختر المورد.' : 'اختر العميل.';
    } elseif ($cashAccountId < 1 || !isset($allowedCash[$cashAccountId])) {
        $err = 'اختر حساب الصرف (صندوق، بنك، أو حساب شريك) الذي يُخصم منه المبلغ.';
    } elseif ($payMethod === 'check') {
        if ($checkAmount <= 0 && $amount <= 0) {
            $err = 'أدخل قيمة الشيك.';
        }
    } elseif ($amount <= 0) {
        $err = 'أدخل المبلغ.';
    }

    if ($err === '' && $payMethod === 'check' && $checkAmount > 0) {
        $amount = $checkAmount;
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
            $bankName
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
