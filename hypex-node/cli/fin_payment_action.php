<?php
declare(strict_types=1);

/**
 * CLI لعمليات سند الصرف من hypex-node.
 * Usage: php fin_payment_action.php <action> <userId>
 * stdin (JSON): payload
 * stdout: single JSON line
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "cli only\n");
    exit(1);
}

$action = strtolower(trim((string) ($argv[1] ?? '')));
$userId = (int) ($argv[2] ?? 0);

$raw = stream_get_contents(STDIN);
$payload = json_decode($raw !== false && $raw !== '' ? $raw : '{}', true);
if (!is_array($payload)) {
    $payload = [];
}

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once app_path('includes/fin_voucher.php');
require_once app_path('includes/fin_voucher_schema.php');
require_once app_path('includes/fin_voucher_load.php');
require_once app_path('includes/fin_voucher_post.php');
require_once app_path('includes/fin_voucher_unpost.php');
require_once app_path('includes/fin_voucher_cancel.php');
require_once app_path('includes/fin_payment_save.php');
require_once app_path('includes/fin_payment_parties.php');
require_once app_path('includes/acc_gl.php');
require_once app_path('includes/acc_period_lock.php');
require_once app_path('includes/sys_audit_log.php');

function cli_out(array $data, int $code = 0): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
    exit($code);
}

function cli_login(int $userId): void
{
    if ($userId < 1) {
        cli_out(['ok' => false, 'error' => 'user_id مطلوب.'], 1);
    }
    $u = null;
    $queries = [
        'SELECT id, username, full_name_ar AS full_name FROM sys_user WHERE id = ? AND is_active = 1 LIMIT 1',
        'SELECT id, username, username AS full_name FROM sys_user WHERE id = ? AND is_active = 1 LIMIT 1',
    ];
    foreach ($queries as $sql) {
        try {
            $st = db()->prepare($sql);
            $st->execute([$userId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $u = $row;
                break;
            }
        } catch (Throwable $e) {
            continue;
        }
    }
    if (!$u) {
        cli_out(['ok' => false, 'error' => 'المستخدم غير موجود.'], 1);
    }
    $_SESSION['user'] = [
        'id' => (int) $u['id'],
        'username' => (string) ($u['username'] ?? ''),
        'name' => (string) ($u['full_name'] ?? $u['username'] ?? ''),
        'full_name' => (string) ($u['full_name'] ?? ''),
        'full_name_ar' => (string) ($u['full_name'] ?? ''),
    ];
    $_SESSION['is_system_admin'] = user_is_system_admin((int) $u['id']);
    $_SESSION['permissions'] = load_user_permissions((int) $u['id']);
    $_SESSION['permissions_user_id'] = (int) $u['id'];
    $_SESSION['permissions_loaded_at'] = time();
    $_SESSION['app_context'] = 'desktop';
}

try {
    cli_login($userId);
    $pdo = db();
    fin_voucher_ensure_schema_full($pdo);

    if ($action === 'save') {
        $id = (int) ($payload['voucher_id'] ?? $payload['id'] ?? 0);
        $voucherDate = parse_date_to_iso(trim((string) ($payload['voucher_date'] ?? ''))) ?? '';
        $partyType = fin_payment_normalize_party_type(trim((string) ($payload['party_type'] ?? 'supplier')));
        $partyId = match ($partyType) {
            'supplier' => (int) ($payload['supplier_id'] ?? 0),
            'customer' => (int) ($payload['customer_id'] ?? 0),
            'employee' => (int) ($payload['employee_id'] ?? 0),
            'account' => 0,
            default => 0,
        };
        $offsetAccountId = (int) ($payload['offset_account_id'] ?? 0);
        $hrAdvanceId = (int) ($payload['hr_advance_id'] ?? 0);
        $payMethod = fin_voucher_normalize_pay_method(trim((string) ($payload['pay_method'] ?? 'cash')));
        $amount = function_exists('parse_amount_input')
            ? parse_amount_input($payload['amount'] ?? 0)
            : (float) ($payload['amount'] ?? 0);
        $checkAmount = function_exists('parse_amount_input')
            ? parse_amount_input($payload['check_amount'] ?? 0)
            : (float) ($payload['check_amount'] ?? 0);
        $effectiveAmount = $amount;
        if ($payMethod === 'check' && $checkAmount > 0) {
            $effectiveAmount = $checkAmount;
        } elseif ($payMethod === 'check' && $amount > 0) {
            $effectiveAmount = $amount;
        }
        $checkNo = trim((string) ($payload['check_no'] ?? ''));
        $bankName = trim((string) ($payload['bank_name'] ?? ''));
        $checkDueDate = parse_date_to_iso(trim((string) ($payload['check_due_date'] ?? ''))) ?? '';
        $notes = trim((string) ($payload['notes'] ?? ''));
        $voucherNo = trim((string) ($payload['voucher_no'] ?? ''));
        $cashAccountId = (int) ($payload['cash_account_id'] ?? 0);

        $cashAccounts = fin_voucher_load_cash_bank_accounts($pdo);
        if (!$cashAccounts) {
            cli_out(['ok' => false, 'error' => 'لا توجد حسابات صرف (صناديق، شيكات، أو بنوك).'], 1);
        }
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
            cli_out(['ok' => false, 'error' => $err], 1);
        }

        $pdo->beginTransaction();
        try {
            $savedId = fin_voucher_save(
                $pdo,
                'payment',
                $id,
                $voucherNo,
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
            sys_audit_log_fin_voucher($pdo, 'save', $savedId, 'payment');
            $row = fin_voucher_fetch_by_id($pdo, $savedId, 'payment');
            cli_out([
                'ok' => true,
                'message' => $id > 0 ? 'تم تحديث سند الصرف.' : 'تم حفظ سند الصرف.',
                'voucher_id' => $savedId,
                'voucher_no' => (string) ($row['voucher_no'] ?? ''),
                'is_posted' => fin_voucher_is_posted($pdo, $savedId),
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    $id = (int) ($payload['voucher_id'] ?? $payload['id'] ?? 0);
    if ($id < 1) {
        cli_out(['ok' => false, 'error' => 'معرّف السند غير صالح.'], 1);
    }

    if ($action === 'post') {
        fin_voucher_prepare_payment_post_schemas($pdo);
        $pdo->beginTransaction();
        $result = fin_voucher_post_payments_by_ids($pdo, [$id]);
        if (!empty($result['errors'])) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            cli_out(['ok' => false, 'error' => implode("\n", $result['errors'])], 1);
        }
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
        $posted = (int) ($result['posted'] ?? 0);
        $skipped = (int) ($result['skipped'] ?? 0);
        if ($posted === 0 && $skipped > 0) {
            $msg = 'السند مرحّل مسبقًا.';
        } elseif ($posted > 0) {
            $msg = 'تم ترحيل سند الصرف.';
        } else {
            $msg = 'لم يتم ترحيل أي سند.';
        }
        cli_out(['ok' => true, 'message' => $msg, 'is_posted' => true]);
    }

    if ($action === 'unpost') {
        $pdo->beginTransaction();
        $result = fin_voucher_unpost_payment_by_id($pdo, $id);
        if (!$result['ok']) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            cli_out(['ok' => false, 'error' => $result['error'] ?? 'تعذر فك الترحيل.'], 1);
        }
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
        cli_out([
            'ok' => true,
            'message' => $result['message'] ?? 'تم فك ترحيل سند الصرف.',
            'is_posted' => false,
        ]);
    }

    if ($action === 'delete') {
        $pdo->beginTransaction();
        try {
            fin_voucher_delete($pdo, $id, 'payment');
            $pdo->commit();
            sys_audit_log_fin_voucher($pdo, 'delete', $id, 'payment');
            cli_out(['ok' => true, 'message' => 'تم حذف سند الصرف.']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    if ($action === 'cancel') {
        $pdo->beginTransaction();
        $result = fin_voucher_cancel_payment_by_id($pdo, $id);
        if (!$result['ok']) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            cli_out(['ok' => false, 'error' => $result['error'] ?? 'تعذر الإلغاء.'], 1);
        }
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
        cli_out([
            'ok' => true,
            'message' => $result['message'] ?? 'تم إلغاء سند الصرف.',
            'is_cancelled' => true,
        ]);
    }

    cli_out(['ok' => false, 'error' => 'إجراء غير معروف: ' . $action], 1);
} catch (Throwable $e) {
    cli_out(['ok' => false, 'error' => $e->getMessage() ?: 'خطأ غير متوقع.'], 1);
}
