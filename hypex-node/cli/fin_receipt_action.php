<?php
declare(strict_types=1);

/**
 * CLI لعمليات سند القبض من hypex-node.
 * Usage: php fin_receipt_action.php <action> <userId>
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
require_once app_path('includes/fin_voucher_checks.php');
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
    $st = db()->prepare(
        'SELECT id, username, full_name FROM sys_user WHERE id = ? AND is_active = 1 LIMIT 1'
    );
    $st->execute([$userId]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        cli_out(['ok' => false, 'error' => 'المستخدم غير موجود.'], 1);
    }
    $_SESSION['user'] = [
        'id' => (int) $u['id'],
        'username' => (string) ($u['username'] ?? ''),
        'name' => (string) ($u['full_name'] ?? $u['username'] ?? ''),
        'full_name' => (string) ($u['full_name'] ?? ''),
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
        $customerId = (int) ($payload['customer_id'] ?? 0);
        $payMethod = fin_voucher_normalize_pay_method(trim((string) ($payload['pay_method'] ?? 'cash')));
        $amount = function_exists('parse_amount_input')
            ? parse_amount_input($payload['amount'] ?? 0)
            : (float) ($payload['amount'] ?? 0);
        $checkAmount = function_exists('parse_amount_input')
            ? parse_amount_input($payload['check_amount'] ?? 0)
            : (float) ($payload['check_amount'] ?? 0);
        $checkNo = trim((string) ($payload['check_no'] ?? ''));
        $bankName = trim((string) ($payload['bank_name'] ?? ''));
        $notes = trim((string) ($payload['notes'] ?? ''));
        $voucherNo = trim((string) ($payload['voucher_no'] ?? ''));

        $cashAccounts = fin_voucher_load_cash_accounts($pdo);
        if (!$cashAccounts) {
            cli_out(['ok' => false, 'error' => 'لا توجد حسابات صندوق/بنك.'], 1);
        }

        if ($payMethod === 'check') {
            $checksFundId = acc_gl_checks_fund_account_id($pdo);
            $cashAccountId = $checksFundId > 0 ? $checksFundId : (int) ($cashAccounts[0]['id'] ?? 0);
        } elseif ($payMethod === 'bank') {
            $bankId = acc_gl_receipt_bank_deposit_account_id($pdo);
            if ($bankId < 1) {
                cli_out([
                    'ok' => false,
                    'error' => 'حساب إيداع البنك (1001003004) غير موجود. أنشئ الحساب في شجرة الحسابات.',
                ], 1);
            }
            $cashAccountId = $bankId;
        } else {
            $cashBoxId = acc_gl_cash_box_account_id($pdo);
            $cashAccountId = $cashBoxId > 0 ? $cashBoxId : (int) ($cashAccounts[0]['id'] ?? 0);
        }

        $checksList = [];
        if ($payMethod === 'check' && isset($payload['checks']) && is_array($payload['checks'])) {
            foreach ($payload['checks'] as $c) {
                if (!is_array($c)) {
                    continue;
                }
                $ca = function_exists('parse_amount_input')
                    ? parse_amount_input($c['check_amount'] ?? 0)
                    : (float) ($c['check_amount'] ?? 0);
                if ($ca <= 0) {
                    continue;
                }
                $due = parse_date_to_iso(trim((string) ($c['due_date'] ?? ''))) ?? '';
                $checksList[] = [
                    'check_no' => trim((string) ($c['check_no'] ?? '')),
                    'bank_name' => trim((string) ($c['bank_name'] ?? '')),
                    'check_amount' => $ca,
                    'due_date' => $due,
                    'notes' => trim((string) ($c['notes'] ?? '')),
                ];
            }
        }
        $checksTotal = fin_voucher_checks_total($checksList);

        $err = '';
        if ($voucherDate === '') {
            $err = 'تاريخ السند غير صالح.';
        } elseif (($periodErr = acc_period_date_lock_error($pdo, $voucherDate)) !== null) {
            $err = $periodErr;
        } elseif ($customerId < 1) {
            $err = 'اختر العميل.';
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
            cli_out(['ok' => false, 'error' => $err], 1);
        }

        $pdo->beginTransaction();
        try {
            $savedId = fin_voucher_save(
                $pdo,
                'receipt',
                $id,
                $voucherNo,
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
                $payMethod === 'check' ? $checksList : []
            );
            $pdo->commit();
            sys_audit_log_fin_voucher($pdo, 'save', $savedId, 'receipt');
            $row = fin_voucher_fetch_by_id($pdo, $savedId, 'receipt');
            cli_out([
                'ok' => true,
                'message' => $id > 0 ? 'تم تحديث سند القبض.' : 'تم حفظ سند القبض.',
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
    if ($id < 1 && $action !== 'save') {
        cli_out(['ok' => false, 'error' => 'معرّف السند غير صالح.'], 1);
    }

    if ($action === 'post') {
        require_once app_path('includes/crm_customer_ledger.php');
        crm_ledger_ensure_schema($pdo);
        acc_gl_ensure_schema($pdo);
        sys_audit_log_ensure_schema($pdo);

        $pdo->beginTransaction();
        $result = fin_voucher_post_receipts_by_ids($pdo, [$id]);
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
            $msg = 'تم ترحيل سند القبض (قيد دائن على حساب العميل).';
        } else {
            $msg = 'لم يتم ترحيل أي سند.';
        }
        cli_out(['ok' => true, 'message' => $msg, 'is_posted' => true]);
    }

    if ($action === 'unpost') {
        $pdo->beginTransaction();
        $result = fin_voucher_unpost_receipt_by_id($pdo, $id);
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
            'message' => $result['message'] ?? 'تم فك ترحيل سند القبض.',
            'is_posted' => false,
        ]);
    }

    if ($action === 'delete') {
        $pdo->beginTransaction();
        try {
            fin_voucher_delete($pdo, $id, 'receipt');
            $pdo->commit();
            sys_audit_log_fin_voucher($pdo, 'delete', $id, 'receipt');
            cli_out(['ok' => true, 'message' => 'تم حذف سند القبض.']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    if ($action === 'cancel') {
        $pdo->beginTransaction();
        $result = fin_voucher_cancel_receipt_by_id($pdo, $id);
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
            'message' => $result['message'] ?? 'تم إلغاء سند القبض.',
            'is_cancelled' => true,
        ]);
    }

    cli_out(['ok' => false, 'error' => 'إجراء غير معروف: ' . $action], 1);
} catch (Throwable $e) {
    cli_out(['ok' => false, 'error' => $e->getMessage() ?: 'خطأ غير متوقع.'], 1);
}
