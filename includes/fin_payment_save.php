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
    $employeePayKind = fin_payment_normalize_employee_pay_kind(trim((string) ($_POST['employee_pay_kind'] ?? 'advance')));
    $hrAdvanceId = (int) ($_POST['hr_advance_id'] ?? 0);
    $hrSalaryId = (int) ($_POST['hr_salary_id'] ?? 0);
    $payMethod = trim((string) ($_POST['pay_method'] ?? 'cash')) === 'check' ? 'check' : 'cash';
    $amount = (float) ($_POST['amount'] ?? 0);
    $checkAmount = (float) ($_POST['check_amount'] ?? 0);
    $effectiveAmount = $amount;
    if ($payMethod === 'check' && $checkAmount > 0) {
        $effectiveAmount = $checkAmount;
    } elseif ($payMethod === 'check' && $amount > 0) {
        $effectiveAmount = $amount;
    }
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
    } elseif ($partyType === 'supplier' && $partyId < 1) {
        $err = 'اختر المورد.';
    } elseif ($partyType === 'customer' && $partyId < 1) {
        $err = 'اختر العميل.';
    } elseif ($partyType === 'employee') {
        $err = fin_payment_validate_employee_party($pdo, $partyId) ?? '';
        if ($err === '' && $employeePayKind === 'advance') {
            if ($hrAdvanceId < 1) {
                $err = 'اختر السلفة المعتمدة للصرف من قائمة السلف.';
            } else {
                $payableId = fin_payment_employee_advance_payable_account_id($pdo);
                if ($payableId < 1) {
                    $err = 'حساب «سلف موظفين مستحقة الصرف» غير مربوط في إعدادات الترحيل.';
                } else {
                    $offsetAccountId = $payableId;
                }
            }
            $hrSalaryId = 0;
        } elseif ($err === '' && $employeePayKind === 'other') {
            $hrAdvanceId = 0;
            if (!fin_payment_offset_account_allowed($pdo, 'employee', $offsetAccountId, 'other')) {
                $err = 'اختر حساب الالتزام (رواتب مستحقة / ضمان…).';
            } elseif ($offsetAccountId === fin_payment_salaries_payable_account_id($pdo)) {
                if ($hrSalaryId < 1) {
                    $err = 'اختر الراتب المرحّل للصرف من قائمة الرواتب.';
                }
            } else {
                $hrSalaryId = 0;
            }
        }
    } elseif ($partyType === 'account') {
        if ($offsetAccountId < 1) {
            $err = 'اختر الحساب المُصروف إليه من الشجرة.';
        } elseif (!fin_payment_offset_account_allowed($pdo, 'account', $offsetAccountId)) {
            $err = 'الحساب المختار غير صالح للصرف (يجب أن يكون خصوماً أو مصروفاً).';
        }
    }

    if ($err === '' && ($cashAccountId < 1 || !isset($allowedCash[$cashAccountId]))) {
        $err = 'اختر حساب الصرف (صندوق، شيكات، أو بنك) الذي يُخصم منه المبلغ.';
    } elseif ($err === '' && $payMethod === 'check') {
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

    if ($err === '' && $partyType === 'employee' && $employeePayKind === 'advance' && $hrAdvanceId > 0) {
        require_once app_path('includes/hr_employee_advance.php');
        $advance = hr_employee_advance_load($pdo, $hrAdvanceId);
        if (!$advance) {
            $err = 'السلفة غير موجودة.';
        } else {
            $amount = round((float) ($advance['total_amount'] ?? 0), 3);
            $effectiveAmount = $amount;
            $err = hr_employee_advance_validate_for_disbursement($pdo, $hrAdvanceId, $partyId, $amount, $id) ?? '';
        }
    } elseif ($err === '' && $partyType === 'employee' && $employeePayKind === 'other' && $hrSalaryId > 0) {
        require_once app_path('includes/hr_salary.php');
        try {
            $st = $pdo->prepare('SELECT net_salary FROM hr_salary WHERE id = ? LIMIT 1');
            $st->execute([$hrSalaryId]);
            $net = round((float) $st->fetchColumn(), 3);
            $amount = $net;
            $effectiveAmount = $net;
            $err = hr_salary_validate_for_disbursement($pdo, $hrSalaryId, $partyId, $amount, $id) ?? '';
        } catch (Throwable $e) {
            $err = 'الراتب غير موجود.';
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
        fin_payment_save_apply_employee_hr_links(
            $pdo,
            $savedId,
            $employeePayKind,
            $employeePayKind === 'advance' ? $hrAdvanceId : 0,
            $employeePayKind === 'other' ? $hrSalaryId : 0
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
