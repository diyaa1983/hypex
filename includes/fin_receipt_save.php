<?php
declare(strict_types=1);

require_once app_path('includes/mobile_receipt.php');

function fin_receipt_redirect_url(int $id = 0): string
{
    if (mobile_is_context()) {
        return mobile_url('r=m_receipt' . ($id > 0 ? '&id=' . $id : ''));
    }

    return app_url('index.php?r=cash_receipt' . ($id > 0 ? '&id=' . $id : ''));
}

function handle_fin_receipt_save(): void
{
    $wantsJson = request_wants_json_invoice_save();

    if (!user_can('cash_receipt') && !mobile_can_access_receipt_api()) {
        if ($wantsJson) {
            json_invoice_save_response(false, ['message' => 'ليس لديك صلاحية حفظ السند.'], 403);
        }
        http_response_code(403);
        exit('ليس لديك صلاحية حفظ السند.');
    }

    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        if ($wantsJson) {
            json_invoice_save_response(false, ['message' => 'انتهت صلاحية الجلسة.'], 403);
        }
        flash_set('error', 'انتهت صلاحية الجلسة.');
        redirect(fin_receipt_redirect_url((int) ($_POST['voucher_id'] ?? 0)));
    }

    $pdo = db();
    require_once app_path('includes/fin_voucher_schema.php');
    if (!fin_voucher_ensure_schema_full($pdo)) {
        $msg = 'جدول سندات القبض غير موجود.';
        if ($wantsJson) {
            json_invoice_save_response(false, ['message' => $msg], 500);
        }
        flash_set('error', $msg);
        redirect(fin_receipt_redirect_url());
    }

    $cashAccounts = fin_voucher_load_cash_accounts($pdo);
    if (!$cashAccounts) {
        $msg = 'لا توجد حسابات صندوق/بنك.';
        if ($wantsJson) {
            json_invoice_save_response(false, ['message' => $msg], 500);
        }
        flash_set('error', $msg);
        redirect(fin_receipt_redirect_url());
    }
    require_once app_path('includes/acc_period_lock.php');

    $id = (int) ($_POST['voucher_id'] ?? 0);
    $voucherDate = parse_date_to_iso(trim((string) ($_POST['voucher_date'] ?? ''))) ?? '';
    $customerId = (int) ($_POST['customer_id'] ?? 0);
    $payMethod = fin_voucher_normalize_pay_method(trim((string) ($_POST['pay_method'] ?? 'cash')));
    $amount = parse_amount_input($_POST['amount'] ?? 0);
    $checkAmount = parse_amount_input($_POST['check_amount'] ?? 0);
    $checkNo = trim((string) ($_POST['check_no'] ?? ''));
    $bankName = trim((string) ($_POST['bank_name'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));
    require_once app_path('includes/acc_gl.php');
    if ($payMethod === 'check') {
        $checksFundId = acc_gl_checks_fund_account_id($pdo);
        $cashAccountId = $checksFundId > 0
            ? $checksFundId
            : (int) ($cashAccounts[0]['id'] ?? 0);
    } elseif ($payMethod === 'bank') {
        $bankId = acc_gl_receipt_bank_deposit_account_id($pdo);
        if ($bankId < 1) {
            $err = 'حساب إيداع البنك (1001003004) غير موجود. أنشئ الحساب في شجرة الحسابات.';
            if ($wantsJson) {
                json_invoice_save_response(false, ['message' => $err], 400);
            }
            flash_set('error', $err);
            redirect(fin_receipt_redirect_url($id));
        }
        $cashAccountId = $bankId;
    } else {
        $cashBoxId = acc_gl_cash_box_account_id($pdo);
        $cashAccountId = $cashBoxId > 0
            ? $cashBoxId
            : (int) ($cashAccounts[0]['id'] ?? 0);
    }

    require_once app_path('includes/fin_voucher_checks.php');
    $checksList = $payMethod === 'check' ? fin_voucher_checks_from_post($_POST) : [];
    $checksTotal = fin_voucher_checks_total($checksList);

    $err = '';
    if ($voucherDate === '') {
        $err = 'تاريخ السند غير صالح.';
    } elseif (($periodErr = acc_period_date_lock_error($pdo, $voucherDate)) !== null) {
        $err = $periodErr;
    } elseif ($customerId < 1) {
        $err = 'اختر العميل.';
    } elseif (function_exists('mobile_is_context') && mobile_is_context()) {
        require_once app_path('includes/crm_sales_rep_schema.php');
        $userRepId = crm_sales_rep_id_for_user($pdo, (int) (current_user()['id'] ?? 0));
        if ($userRepId !== null && !crm_customer_is_linked_to_sales_rep($pdo, $customerId, $userRepId)) {
            $err = 'هذا العميل غير مربوط بمندوبك. اختر عميلاً من قائمتك فقط.';
        }
    }

    if ($err === '') {
        if ($payMethod === 'check') {
            if ($checksTotal <= 0 && $checkAmount <= 0 && $amount <= 0) {
                $err = 'أدخل قيمة شيك واحد على الأقل.';
            }
        } elseif ($amount <= 0) {
            $err = 'أدخل المبلغ.';
        }
    }

    if ($err === '' && $payMethod === 'check') {
        if ($checksTotal > 0) {
            $amount = $checksTotal;
            $checkAmount = $checksTotal;
        } elseif ($checkAmount > 0) {
            $amount = $checkAmount;
        }
    }

    if ($err !== '') {
        if ($wantsJson) {
            json_invoice_save_response(false, ['message' => $err], 400);
        }
        flash_set('error', $err);
        redirect(fin_receipt_redirect_url($id));
    }

    try {
        $pdo->beginTransaction();
        $savedId = fin_voucher_save(
            $pdo,
            'receipt',
            $id,
            trim((string) ($_POST['voucher_no'] ?? '')),
            $voucherDate,
            $amount,
            $notes,
            $checkNo,
            'customer',
            $customerId,
            $cashAccountId,
            $payMethod,
            $checkAmount,
            $bankName,
            $checksList
        );
        $pdo->commit();

        require_once app_path('includes/sys_audit_log.php');
        sys_audit_log_fin_voucher($pdo, 'save', $savedId, 'receipt');

        require_once app_path('includes/fin_voucher_load.php');
        $row = fin_voucher_fetch_by_id($pdo, $savedId, 'receipt');

        if ($wantsJson) {
            json_invoice_save_response(true, [
                'message' => $id > 0 ? 'تم تحديث سند القبض.' : 'تم حفظ سند القبض.',
                'voucher_id' => $savedId,
                'voucher_no' => (string) ($row['voucher_no'] ?? ''),
                'is_posted' => fin_voucher_is_posted($pdo, $savedId),
            ]);
        }
        flash_set('success', $id > 0 ? 'تم تحديث سند القبض.' : 'تم حفظ سند القبض.');
        redirect(fin_receipt_redirect_url($savedId));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $msg = $e->getMessage() !== '' ? $e->getMessage() : 'تعذر حفظ السند.';
        if ($wantsJson) {
            json_invoice_save_response(false, ['message' => $msg], 500);
        }
        flash_set('error', $msg);
        redirect(fin_receipt_redirect_url($id));
    }
}
