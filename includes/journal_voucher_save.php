<?php
declare(strict_types=1);

require_once app_path('includes/acc_journal.php');

function handle_journal_voucher_save(): void
{
    $wantsJson = request_wants_json_invoice_save();

    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        if ($wantsJson) {
            json_invoice_save_response(false, ['message' => 'انتهت صلاحية الجلسة.'], 403);
        }
        flash_set('error', 'انتهت صلاحية الجلسة.');
        redirect(app_url('index.php?r=journal_voucher'));
    }

    $pdo = db();
    if (!acc_journal_ensure_schema($pdo)) {
        $msg = 'جداول القيود غير موجودة. نفّذ ترحيل 026_acc_journal_tables.sql.';
        if ($wantsJson) {
            json_invoice_save_response(false, ['message' => $msg], 500);
        }
        flash_set('error', $msg);
        redirect(app_url('index.php?r=journal_voucher'));
    }

    $id = (int) ($_POST['entry_id'] ?? 0);
    $entryNo = trim((string) ($_POST['entry_no'] ?? ''));
    $entryDate = parse_date_to_iso(trim((string) ($_POST['entry_date'] ?? ''))) ?? '';
    $description = trim((string) ($_POST['description_ar'] ?? ''));
    $lines = json_decode((string) ($_POST['lines_json'] ?? '[]'), true);

    require_once app_path('includes/acc_period_lock.php');

    if ($entryDate === '') {
        $err = 'تاريخ السند غير صالح.';
        if ($wantsJson) {
            json_invoice_save_response(false, ['message' => $err], 400);
        }
        flash_set('error', $err);
        redirect(app_url('index.php?r=journal_voucher' . ($id > 0 ? '&id=' . $id : '')));
    }
    if (($periodErr = acc_period_date_lock_error($pdo, $entryDate)) !== null) {
        if ($wantsJson) {
            json_invoice_save_response(false, ['message' => $periodErr], 400);
        }
        flash_set('error', $periodErr);
        redirect(app_url('index.php?r=journal_voucher' . ($id > 0 ? '&id=' . $id : '')));
    }
    if (!is_array($lines)) {
        $err = 'أسطر السند غير صالحة.';
        if ($wantsJson) {
            json_invoice_save_response(false, ['message' => $err], 400);
        }
        flash_set('error', $err);
        redirect(app_url('index.php?r=journal_voucher' . ($id > 0 ? '&id=' . $id : '')));
    }

    if ($entryNo === '' && $id < 1) {
        $entryNo = acc_journal_next_voucher_no($pdo, $entryDate);
    }

    $postNow = (string) ($_POST['_action'] ?? '') === 'save_journal_voucher_post';

    require_once app_path('includes/acc_journal_party.php');
    acc_journal_party_ensure_schema($pdo);
    require_once app_path('includes/acc_gl.php');
    acc_gl_ensure_schema($pdo);

    try {
        $pdo->beginTransaction();
        $savedId = acc_journal_save($pdo, $id, $entryNo, $entryDate, $description, $lines, $postNow);
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }

        require_once app_path('includes/sys_audit_log.php');
        sys_audit_log_acc_journal($pdo, 'save', $savedId);
        if ($postNow) {
            sys_audit_log_acc_journal($pdo, 'post', $savedId);
        }

        $entry = acc_journal_api_entry($pdo, $savedId);
        $msg = $postNow ? 'تم حفظ وترحيل سند القيد.' : 'تم حفظ سند القيد (مسودة).';
        if ($wantsJson) {
            json_invoice_save_response(true, [
                'id' => $savedId,
                'entry_no' => (string) ($entry['entry_no'] ?? $entryNo),
                'message' => $msg,
                'entry' => $entry,
            ]);
        }
        flash_set('success', $msg);
        redirect(app_url('index.php?r=journal_voucher&id=' . $savedId));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $msg = trim($e->getMessage());
        if ($msg === '' || stripos($msg, 'no active transaction') !== false) {
            $msg = 'تعذر الحفظ.';
        }
        if ($wantsJson) {
            $status = $e instanceof RuntimeException ? 400 : 500;
            json_invoice_save_response(false, ['message' => $msg], $status);
        }
        flash_set('error', $msg);
        redirect(app_url('index.php?r=journal_voucher' . ($id > 0 ? '&id=' . $id : '')));
    }
}
