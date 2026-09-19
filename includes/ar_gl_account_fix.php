<?php
declare(strict_types=1);

require_once app_path('includes/sal_invoice_schema.php');
require_once app_path('includes/acc_gl.php');
require_once app_path('includes/acc_journal.php');

/**
 * @return array{ar_account_id:int, ar_code:string}
 */
function ar_gl_fix_ar_account(PDO $pdo): array
{
    $st = $pdo->prepare('SELECT id, code FROM acc_account WHERE code = ? AND is_active = 1 LIMIT 1');
    $st->execute(['1001005']);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return ['ar_account_id' => (int) $row['id'], 'ar_code' => (string) $row['code']];
    }

    acc_gl_ensure_schema($pdo);
    $settings = acc_gl_load_settings($pdo);
    $aid = (int) ($settings['ar_customers']['account_id'] ?? 0);
    if ($aid > 0) {
        $st = $pdo->prepare('SELECT code FROM acc_account WHERE id = ? LIMIT 1');
        $st->execute([$aid]);

        return [
            'ar_account_id' => $aid,
            'ar_code' => (string) ($st->fetchColumn() ?: ''),
        ];
    }

    return ['ar_account_id' => 0, 'ar_code' => ''];
}

/**
 * نقل سطور الذمم في القيود التلقائية من حساب خاطئ (مثل 1002) إلى حساب العملاء الصحيح (1001005).
 * لا يمسّ دفتر العميل ولا المخزون ولا المرتجعات التشغيلية.
 *
 * @return array{
 *   ok:bool,
 *   error:?string,
 *   wrong_code:string,
 *   correct_code:string,
 *   preview:list<array<string,mixed>>,
 *   updated_lines:int
 * }
 */
function ar_gl_fix_wrong_ar_account(PDO $pdo, string $wrongCode = '1002', bool $dryRun = true): array
{
    $out = [
        'ok' => false,
        'error' => null,
        'wrong_code' => $wrongCode,
        'correct_code' => '',
        'preview' => [],
        'updated_lines' => 0,
    ];

    if (!acc_journal_ensure_schema($pdo)) {
        $out['error'] = 'جداول القيود غير موجودة.';

        return $out;
    }

    $ar = ar_gl_fix_ar_account($pdo);
    $correctId = (int) $ar['ar_account_id'];
    $out['correct_code'] = (string) $ar['ar_code'];
    if ($correctId < 1) {
        $out['error'] = 'حساب الذمم الصحيح (1001005) غير موجود.';

        return $out;
    }

    $st = $pdo->prepare('SELECT id, code, name_ar, is_leaf FROM acc_account WHERE code = ? AND is_active = 1 LIMIT 1');
    $st->execute([$wrongCode]);
    $wrong = $st->fetch(PDO::FETCH_ASSOC);
    if (!$wrong) {
        $out['error'] = 'الحساب الخاطئ ' . $wrongCode . ' غير موجود.';

        return $out;
    }
    $wrongId = (int) $wrong['id'];
    if ($wrongId === $correctId) {
        $out['ok'] = true;
        $out['error'] = 'نفس الحساب — لا حاجة للتصحيح.';

        return $out;
    }

    $hasPay = sal_invoice_column_exists($pdo, 'sal_invoice', 'payment_type');

    // فواتير بيع آجلة: مدين الذمم على الحساب الخاطئ
    $invSql = "SELECT l.id AS line_id, l.journal_id, l.debit, l.credit,
                      e.entry_no, e.ref_id AS invoice_id, i.invoice_no
               FROM acc_journal_line l
               INNER JOIN acc_journal_entry e ON e.id = l.journal_id
               INNER JOIN sal_invoice i ON i.id = e.ref_id
               WHERE e.ref_type = 'sale_invoice' AND e.source = 'auto'
                 AND l.account_id = ? AND l.debit > 0";
    if ($hasPay) {
        $invSql .= " AND i.payment_type = 'credit'";
    }
    $invSt = $pdo->prepare($invSql);
    $invSt->execute([$wrongId]);
    $invLines = $invSt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // مردودات: دائن الذمم على الحساب الخاطئ (فاتورة أصلية آجلة)
    $retSql = "SELECT l.id AS line_id, l.journal_id, l.debit, l.credit,
                      e.entry_no, e.ref_id AS return_id, r.return_no, i.invoice_no
               FROM acc_journal_line l
               INNER JOIN acc_journal_entry e ON e.id = l.journal_id
               INNER JOIN sal_return r ON r.id = e.ref_id
               INNER JOIN sal_invoice i ON i.id = r.invoice_id
               WHERE e.ref_type = 'sale_return' AND e.source = 'auto'
                 AND l.account_id = ? AND l.credit > 0";
    if ($hasPay) {
        $retSql .= " AND i.payment_type = 'credit'";
    }
    $retSt = $pdo->prepare($retSql);
    $retSt->execute([$wrongId]);
    $retLines = $retSt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $all = [];
    foreach ($invLines as $row) {
        $all[] = [
            'line_id' => (int) $row['line_id'],
            'journal_id' => (int) $row['journal_id'],
            'entry_no' => (string) $row['entry_no'],
            'ref' => 'sale_invoice',
            'doc_no' => (string) $row['invoice_no'],
            'debit' => (float) $row['debit'],
            'credit' => (float) $row['credit'],
        ];
    }
    foreach ($retLines as $row) {
        $all[] = [
            'line_id' => (int) $row['line_id'],
            'journal_id' => (int) $row['journal_id'],
            'entry_no' => (string) $row['entry_no'],
            'ref' => 'sale_return',
            'doc_no' => (string) $row['return_no'] . ' ← ' . (string) $row['invoice_no'],
            'debit' => (float) $row['debit'],
            'credit' => (float) $row['credit'],
        ];
    }

    if ($all === []) {
        $out['ok'] = true;

        return $out;
    }

    // تحقق: كل قيد يبقى متوازناً بعد التعديل
    $journalIds = array_values(array_unique(array_column($all, 'journal_id')));
    foreach ($journalIds as $jid) {
        $st = $pdo->prepare(
            'SELECT COALESCE(SUM(debit),0), COALESCE(SUM(credit),0) FROM acc_journal_line WHERE journal_id = ?'
        );
        $st->execute([$jid]);
        $sums = $st->fetch(PDO::FETCH_NUM);
        if (!$sums || abs((float) $sums[0] - (float) $sums[1]) > 0.0001) {
            $out['error'] = 'قيد غير متوازن #' . $jid . ' — أوقف التصحيح.';

            return $out;
        }
    }

    $out['preview'] = $all;

    if ($dryRun) {
        $out['ok'] = true;

        return $out;
    }

    try {
        $pdo->beginTransaction();
        $upd = $pdo->prepare(
            'UPDATE acc_journal_line SET account_id = ? WHERE id = ? AND account_id = ?'
        );
        foreach ($all as $row) {
            $upd->execute([$correctId, (int) $row['line_id'], $wrongId]);
            if ($upd->rowCount() > 0) {
                $out['updated_lines']++;
            }
        }

        // إعادة التحقق من التوازن
        foreach ($journalIds as $jid) {
            $st = $pdo->prepare(
                'SELECT COALESCE(SUM(debit),0), COALESCE(SUM(credit),0) FROM acc_journal_line WHERE journal_id = ?'
            );
            $st->execute([$jid]);
            $sums = $st->fetch(PDO::FETCH_NUM);
            if (!$sums || abs((float) $sums[0] - (float) $sums[1]) > 0.0001) {
                throw new RuntimeException('قيد #' . $jid . ' غير متوازن بعد التحديث.');
            }
        }

        $pdo->commit();
        $out['ok'] = true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $out['error'] = $e->getMessage();
    }

    return $out;
}
