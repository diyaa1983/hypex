<?php
declare(strict_types=1);

/**
 * CLI لعمليات سند القيد من hypex-node.
 * Usage: php fin_journal_action.php <action> <userId>
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
require_once app_path('includes/acc_journal.php');
require_once app_path('includes/acc_journal_party.php');
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

    if (!acc_journal_ensure_schema($pdo)) {
        cli_out(['ok' => false, 'error' => 'جداول القيود غير موجودة. نفّذ 026_acc_journal_tables.sql.'], 1);
    }
    acc_journal_party_ensure_schema($pdo);
    acc_gl_ensure_schema($pdo);

    if ($action === 'save') {
        $id = (int) ($payload['entry_id'] ?? $payload['id'] ?? 0);
        $entryNo = trim((string) ($payload['entry_no'] ?? ''));
        $entryDate = parse_date_to_iso(trim((string) ($payload['entry_date'] ?? ''))) ?? '';
        $description = trim((string) ($payload['description_ar'] ?? ''));
        $lines = $payload['lines'] ?? [];
        if (is_string($lines)) {
            $decoded = json_decode($lines, true);
            $lines = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($lines)) {
            $lines = [];
        }
        $postNow = !empty($payload['post_now']) || !empty($payload['post']);

        if ($entryDate === '') {
            cli_out(['ok' => false, 'error' => 'تاريخ السند غير صالح.'], 1);
        }
        if (($periodErr = acc_period_date_lock_error($pdo, $entryDate)) !== null) {
            cli_out(['ok' => false, 'error' => $periodErr], 1);
        }
        if ($entryNo === '' && $id < 1) {
            $entryNo = acc_journal_next_voucher_no($pdo, $entryDate);
        }

        $pdo->beginTransaction();
        try {
            $savedId = acc_journal_save($pdo, $id, $entryNo, $entryDate, $description, $lines, $postNow);
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
            sys_audit_log_acc_journal($pdo, 'save', $savedId);
            if ($postNow) {
                sys_audit_log_acc_journal($pdo, 'post', $savedId);
            }
            $entry = acc_journal_api_entry($pdo, $savedId);
            cli_out([
                'ok' => true,
                'message' => $postNow ? 'تم حفظ وترحيل سند القيد.' : 'تم حفظ سند القيد (مسودة).',
                'entry_id' => $savedId,
                'entry_no' => (string) ($entry['entry_no'] ?? $entryNo),
                'entry' => $entry,
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    $id = (int) ($payload['entry_id'] ?? $payload['id'] ?? 0);
    if ($id < 1) {
        cli_out(['ok' => false, 'error' => 'معرّف السند غير صالح.'], 1);
    }

    if ($action === 'post') {
        acc_journal_assert_manual_voucher($pdo, $id);
        $pdo->beginTransaction();
        try {
            acc_journal_post_by_id($pdo, $id);
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
            $entry = acc_journal_api_entry($pdo, $id);
            cli_out([
                'ok' => true,
                'message' => 'تم ترحيل سند القيد.',
                'entry' => $entry,
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    if ($action === 'unpost' || $action === 'edit_unlock') {
        if ($action === 'edit_unlock') {
            $password = (string) ($payload['password'] ?? '');
            if (!function_exists('verify_current_user_password') || !verify_current_user_password($password)) {
                cli_out(['ok' => false, 'error' => 'كلمة المرور غير صحيحة.'], 1);
            }
        }
        acc_journal_assert_manual_voucher($pdo, $id);
        $pdo->beginTransaction();
        try {
            acc_journal_unpost_by_id($pdo, $id);
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
            $entry = acc_journal_api_entry($pdo, $id);
            cli_out([
                'ok' => true,
                'message' => $action === 'edit_unlock'
                    ? 'تم فك الترحيل. يمكنك تعديل الحركات ثم الحفظ وإعادة الترحيل.'
                    : 'تم فك ترحيل سند القيد. لن يظهر في التقارير المحاسبية.',
                'entry' => $entry,
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    if ($action === 'delete') {
        acc_journal_assert_manual_voucher($pdo, $id);
        $pdo->beginTransaction();
        try {
            acc_journal_delete_draft($pdo, $id);
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
            cli_out(['ok' => true, 'message' => 'تم حذف سند القيد.']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    if ($action === 'cancel') {
        acc_journal_assert_manual_voucher($pdo, $id);
        $pdo->beginTransaction();
        try {
            acc_journal_cancel_by_id($pdo, $id);
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
            $entry = acc_journal_api_entry($pdo, $id);
            cli_out([
                'ok' => true,
                'message' => 'تم إلغاء سند القيد. يبقى في السجل برقم التسلسل.',
                'entry' => $entry,
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    cli_out(['ok' => false, 'error' => 'إجراء غير معروف: ' . $action], 1);
} catch (Throwable $e) {
    $msg = trim($e->getMessage());
    if ($msg === '' || stripos($msg, 'no active transaction') !== false) {
        $msg = 'خطأ غير متوقع.';
    }
    cli_out(['ok' => false, 'error' => $msg], 1);
}
