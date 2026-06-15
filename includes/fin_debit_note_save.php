<?php
declare(strict_types=1);

require_once app_path('includes/fin_debit_note.php');

function handle_fin_debit_note_save(): void
{
    $wantsJson = request_wants_json_invoice_save();

    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        if ($wantsJson) {
            json_invoice_save_response(false, ['message' => 'انتهت صلاحية الجلسة.'], 403);
        }
        flash_set('error', 'انتهت صلاحية الجلسة.');
        redirect(app_url('index.php?r=debit_notes'));
    }

    $pdo = db();
    if (!fin_debit_note_ensure_schema($pdo)) {
        $msg = 'جداول إشعارات المدينة غير موجودة. نفّذ ترحيل قاعدة البيانات (schema).';
        if ($wantsJson) {
            json_invoice_save_response(false, ['message' => $msg], 500);
        }
        flash_set('error', $msg);
        redirect(app_url('index.php?r=debit_notes'));
    }

    $lines = json_decode((string) ($_POST['lines_json'] ?? '[]'), true);
    if (!is_array($lines)) {
        $lines = [];
    }

    $parsedLines = [];
    foreach ($lines as $ln) {
        if (!is_array($ln)) {
            continue;
        }
        $qty = (float) ($ln['qty'] ?? 0);
        $unitPrice = (float) ($ln['unit_price'] ?? 0);
        $lineTotal = (float) ($ln['line_total'] ?? 0);
        if ($lineTotal <= 0 && $qty > 0 && $unitPrice >= 0) {
            $lineTotal = round($qty * $unitPrice, 6);
        }
        if ($lineTotal <= 0) {
            continue;
        }
        $parsedLines[] = [
            'item_id' => (int) ($ln['item_id'] ?? 0),
            'description_ar' => trim((string) ($ln['description_ar'] ?? '')),
            'qty' => $qty > 0 ? $qty : 1,
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
        ];
    }

    try {
        $pdo->beginTransaction();
        $savedId = fin_debit_note_save($pdo, [
            'id' => (int) ($_POST['note_id'] ?? 0),
            'party_type' => (string) ($_POST['party_type'] ?? 'customer'),
            'party_id' => (int) ($_POST['party_id'] ?? 0),
            'note_date' => (string) ($_POST['note_date'] ?? ''),
            'reason' => (string) ($_POST['reason'] ?? ''),
        ], $parsedLines);
        $pdo->commit();

        $note = fin_debit_note_fetch($pdo, $savedId);
        if ($wantsJson) {
            json_invoice_save_response(true, [
                'id' => $savedId,
                'note_no' => (string) ($note['note_no'] ?? ''),
                'message' => 'تم حفظ إشعار المدينة.',
            ]);
        }
        flash_set('success', 'تم حفظ إشعار المدينة.');
        redirect(app_url('index.php?r=debit_notes&id=' . $savedId));
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($wantsJson) {
            json_invoice_save_response(false, ['message' => $e->getMessage()], 400);
        }
        flash_set('error', $e->getMessage());
        $rid = (int) ($_POST['note_id'] ?? 0);
        redirect(app_url('index.php?r=debit_notes' . ($rid > 0 ? '&id=' . $rid : '')));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($wantsJson) {
            json_invoice_save_response(false, ['message' => 'تعذر الحفظ.'], 500);
        }
        flash_set('error', 'تعذر الحفظ.');
        redirect(app_url('index.php?r=debit_notes'));
    }
}
