<?php
declare(strict_types=1);

require_once app_path('includes/acc_gl.php');
require_once app_path('includes/acc_report_vat_jordan.php');

/** هل قيد المردود يفصل الضريبة على حساب vat بالفعل؟ */
function acc_return_vat_gl_is_split(PDO $pdo, string $refType, int $refId, int $vatAccountId, bool $vatIsOutput): bool
{
    if ($refId < 1 || $vatAccountId < 1 || !acc_journal_has_tables($pdo)) {
        return true;
    }

    $side = $vatIsOutput ? 'debit' : 'credit';
    $st = $pdo->prepare(
        "SELECT COALESCE(SUM(l.{$side}), 0)
         FROM acc_journal_line l
         INNER JOIN acc_journal_entry e ON e.id = l.journal_id
         WHERE e.ref_type = ? AND e.ref_id = ? AND e.source = 'auto'
           AND l.account_id = ?"
    );
    $st->execute([$refType, $refId, $vatAccountId]);

    return (float) $st->fetchColumn() > 0.000001;
}

/**
 * @return array{
 *   id:int, return_no:string, return_date:string,
 *   subtotal:float, tax_amount:float, total:float,
 *   needs_repost:bool, reason:string, vat_on_gl:float
 * }|null
 */
function acc_return_vat_repost_row_sale(PDO $pdo, int $returnId, int $vatOutId): ?array
{
    $st = $pdo->prepare(
        "SELECT r.id, r.return_no, r.return_date, r.subtotal, r.tax_amount, r.total, r.status
         FROM sal_return r WHERE r.id = ? LIMIT 1"
    );
    $st->execute([$returnId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || (string) ($row['status'] ?? '') !== 'confirmed') {
        return null;
    }

    $tax = round((float) ($row['tax_amount'] ?? 0), 6);
    $split = acc_return_vat_gl_is_split($pdo, 'sale_return', $returnId, $vatOutId, true);
    $needs = $tax > 0.000001 && !$split;

    return [
        'id' => $returnId,
        'return_no' => (string) $row['return_no'],
        'return_date' => (string) $row['return_date'],
        'subtotal' => round((float) $row['subtotal'], 6),
        'tax_amount' => $tax,
        'total' => round((float) $row['total'], 6),
        'needs_repost' => $needs,
        'reason' => $tax <= 0.000001 ? 'بدون ضريبة' : ($split ? 'مُحدَّث مسبقاً' : 'يجب فصل الضريبة'),
        'vat_on_gl' => $tax,
    ];
}

/**
 * @return array{id:int, return_no:string, return_date:string, subtotal:float, tax_amount:float, total:float, needs_repost:bool, reason:string, vat_on_gl:float}|null
 */
function acc_return_vat_repost_row_purchase(PDO $pdo, int $returnId, int $vatInId): ?array
{
    require_once app_path('includes/pur_invoice_schema.php');
    pur_invoice_ensure_schema($pdo);

    $st = $pdo->prepare(
        "SELECT r.id, r.return_no, r.return_date, r.subtotal, r.tax_amount, r.total, r.status
         FROM pur_return r WHERE r.id = ? LIMIT 1"
    );
    $st->execute([$returnId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || (string) ($row['status'] ?? '') !== 'confirmed') {
        return null;
    }

    $tax = round((float) ($row['tax_amount'] ?? 0), 6);
    $split = acc_return_vat_gl_is_split($pdo, 'purchase_return', $returnId, $vatInId, false);
    $needs = $tax > 0.000001 && !$split;

    return [
        'id' => $returnId,
        'return_no' => (string) $row['return_no'],
        'return_date' => (string) $row['return_date'],
        'subtotal' => round((float) $row['subtotal'], 6),
        'tax_amount' => $tax,
        'total' => round((float) $row['total'], 6),
        'needs_repost' => $needs,
        'reason' => $tax <= 0.000001 ? 'بدون ضريبة' : ($split ? 'مُحدَّث مسبقاً' : 'يجب فصل الضريبة'),
        'vat_on_gl' => $tax,
    ];
}

/**
 * قائمة مردودات لها قيد GL — للمعاينة قبل التنفيذ.
 *
 * @return array{
 *   sale: list<array<string,mixed>>,
 *   purchase: list<array<string,mixed>>,
 *   to_fix_count: int
 * }
 */
function acc_return_vat_repost_scan(PDO $pdo): array
{
    $settings = acc_gl_load_settings($pdo);
    $vatOut = (int) ($settings['vat_output']['account_id'] ?? 0);
    $vatIn = (int) ($settings['vat_input']['account_id'] ?? 0);

    $sale = [];
    $st = $pdo->query(
        "SELECT r.id FROM sal_return r
         INNER JOIN acc_journal_entry e ON e.ref_type = 'sale_return' AND e.ref_id = r.id AND e.source = 'auto'
         ORDER BY r.return_date, r.id"
    );
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $rawId) {
        $info = acc_return_vat_repost_row_sale($pdo, (int) $rawId, $vatOut);
        if ($info !== null) {
            $info['type'] = 'sale_return';
            $info['type_label'] = 'مردود بيع';
            $sale[] = $info;
        }
    }

    $purchase = [];
    $st = $pdo->query(
        "SELECT r.id FROM pur_return r
         INNER JOIN acc_journal_entry e ON e.ref_type = 'purchase_return' AND e.ref_id = r.id AND e.source = 'auto'
         ORDER BY r.return_date, r.id"
    );
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $rawId) {
        $info = acc_return_vat_repost_row_purchase($pdo, (int) $rawId, $vatIn);
        if ($info !== null) {
            $info['type'] = 'purchase_return';
            $info['type_label'] = 'مردود شراء';
            $purchase[] = $info;
        }
    }

    $toFix = 0;
    foreach (array_merge($sale, $purchase) as $row) {
        if (!empty($row['needs_repost'])) {
            $toFix++;
        }
    }

    return ['sale' => $sale, 'purchase' => $purchase, 'to_fix_count' => $toFix];
}

/** نسخة احتياطية لقيد تلقائي قبل إعادة الترحيل. */
function acc_return_vat_journal_backup(PDO $pdo, string $refType, int $refId): ?array
{
    if ($refId < 1 || !acc_journal_has_tables($pdo)) {
        return null;
    }

    $st = $pdo->prepare(
        "SELECT * FROM acc_journal_entry
         WHERE ref_type = ? AND ref_id = ? AND source = 'auto' LIMIT 1"
    );
    $st->execute([$refType, $refId]);
    $entry = $st->fetch(PDO::FETCH_ASSOC);
    if (!$entry) {
        return null;
    }

    $journalId = (int) $entry['id'];
    $lnSt = $pdo->prepare(
        'SELECT account_id, debit, credit, memo FROM acc_journal_line WHERE journal_id = ? ORDER BY id'
    );
    $lnSt->execute([$journalId]);
    $lines = [];
    foreach ($lnSt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $ln) {
        $lines[] = [
            'account_id' => (int) $ln['account_id'],
            'debit' => (float) $ln['debit'],
            'credit' => (float) $ln['credit'],
            'memo' => (string) ($ln['memo'] ?? ''),
        ];
    }

    return ['entry' => $entry, 'lines' => $lines];
}

/** استعادة القيد الاحتياطي إذا فشلت إعادة الترحيل. */
function acc_return_vat_journal_restore(PDO $pdo, ?array $backup): void
{
    if ($backup === null || !acc_journal_has_tables($pdo)) {
        return;
    }

    $entry = $backup['entry'];
    $refType = (string) ($entry['ref_type'] ?? '');
    $refId = (int) ($entry['ref_id'] ?? 0);
    if ($refId < 1 || $refType === '') {
        return;
    }

    acc_gl_unpost_ref($pdo, $refType, $refId);

    $uid = (int) ($entry['created_by'] ?? 0) ?: null;
    $pdo->prepare(
        "INSERT INTO acc_journal_entry (entry_no, entry_date, description_ar, status, ref_type, ref_id, source, created_by)
         VALUES (?,?,?,?,?,?,'auto',?)"
    )->execute([
        (string) ($entry['entry_no'] ?? ''),
        (string) ($entry['entry_date'] ?? ''),
        ($entry['description_ar'] ?? '') !== '' ? (string) $entry['description_ar'] : null,
        (string) ($entry['status'] ?? 'posted'),
        $refType,
        $refId,
        $uid,
    ]);
    $journalId = (int) $pdo->lastInsertId();
    acc_journal_replace_lines($pdo, $journalId, $backup['lines']);
}

/**
 * إعادة قيد GL لمردود واحد فقط (لا يمس المستودع ولا ذمم التشغيل).
 *
 * @return array{ok:bool, skipped:bool, message:string}
 */
function acc_return_vat_repost_one(PDO $pdo, string $refType, int $refId): array
{
    if ($refId < 1 || !in_array($refType, ['sale_return', 'purchase_return'], true)) {
        return ['ok' => false, 'skipped' => true, 'message' => 'مرجع غير صالح.'];
    }

    acc_gl_ensure_schema($pdo);

    $settings = acc_gl_load_settings($pdo);
    $vatOut = (int) ($settings['vat_output']['account_id'] ?? 0);
    $vatIn = (int) ($settings['vat_input']['account_id'] ?? 0);

    if ($refType === 'sale_return') {
        $info = acc_return_vat_repost_row_sale($pdo, $refId, $vatOut);
    } else {
        $info = acc_return_vat_repost_row_purchase($pdo, $refId, $vatIn);
    }

    if ($info === null) {
        return ['ok' => false, 'skipped' => true, 'message' => 'المردود غير موجود أو غير مؤكد.'];
    }
    if (!$info['needs_repost']) {
        return ['ok' => true, 'skipped' => true, 'message' => $info['reason']];
    }

    $backup = acc_return_vat_journal_backup($pdo, $refType, $refId);

    if ($backup !== null) {
        $unpost = acc_gl_unpost_ref($pdo, $refType, $refId);
        if (!$unpost['ok']) {
            return ['ok' => false, 'skipped' => false, 'message' => $unpost['error'] ?? 'تعذر إلغاء القيد القديم.'];
        }
    }

    $post = $refType === 'sale_return'
        ? acc_gl_post_sale_return($pdo, $refId)
        : acc_gl_post_purchase_return($pdo, $refId);

    if (!$post['ok']) {
        acc_return_vat_journal_restore($pdo, $backup);

        return ['ok' => false, 'skipped' => false, 'message' => $post['error'] ?? 'تعذر إنشاء القيد الجديد.'];
    }
    if ($post['skipped']) {
        acc_return_vat_journal_restore($pdo, $backup);

        return ['ok' => false, 'skipped' => false, 'message' => 'لم يُنشأ قيد جديد — راجع الربط المحاسبي.'];
    }

    $vatId = $refType === 'sale_return' ? $vatOut : $vatIn;
    $isOut = $refType === 'sale_return';
    if (!acc_return_vat_gl_is_split($pdo, $refType, $refId, $vatId, $isOut)) {
        acc_return_vat_journal_restore($pdo, $backup);

        return ['ok' => false, 'skipped' => false, 'message' => 'التحقق فشل: الضريبة لم تُفصل على حساب الضريبة.'];
    }

    return [
        'ok' => true,
        'skipped' => false,
        'message' => 'تم — ضريبة ' . format_money((float) $info['tax_amount']) . ' على حساب الضريبة.',
    ];
}

/**
 * @return array{ok:bool, fixed:int, skipped:int, errors:list<string>}
 */
function acc_return_vat_repost_all(PDO $pdo): array
{
    acc_gl_ensure_schema($pdo);
    $scan = acc_return_vat_repost_scan($pdo);
    $result = ['ok' => true, 'fixed' => 0, 'skipped' => 0, 'errors' => []];

    foreach (array_merge($scan['sale'], $scan['purchase']) as $row) {
        if (empty($row['needs_repost'])) {
            $result['skipped']++;
            continue;
        }
        $one = acc_return_vat_repost_one($pdo, (string) $row['type'], (int) $row['id']);
        if ($one['ok'] && !$one['skipped']) {
            $result['fixed']++;
        } elseif ($one['skipped']) {
            $result['skipped']++;
        } else {
            $result['ok'] = false;
            $result['errors'][] = ($row['return_no'] ?? '') . ': ' . $one['message'];
        }
    }

    return $result;
}
