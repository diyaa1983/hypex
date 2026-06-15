<?php
declare(strict_types=1);

function fin_debit_note_probe_table(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT id FROM fin_debit_note LIMIT 1');

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function fin_debit_note_has_table(PDO $pdo): bool
{
    static $ok = false;
    if ($ok) {
        return true;
    }
    if (fin_debit_note_probe_table($pdo)) {
        $ok = true;
    }

    return $ok;
}

function fin_debit_note_ensure_schema(PDO $pdo): bool
{
    if (fin_debit_note_has_table($pdo)) {
        return true;
    }
    require_once app_path('includes/sql_migration.php');
    sql_migration_run_file($pdo, 'database/migrations/030_fin_debit_credit_notes.sql');

    return fin_debit_note_probe_table($pdo);
}

function fin_debit_note_next_no(PDO $pdo): string
{
    $st = $pdo->query(
        "SELECT note_no FROM fin_debit_note WHERE note_no REGEXP '^DN-[0-9]+$' ORDER BY id DESC LIMIT 1"
    );
    $last = $st ? $st->fetchColumn() : false;
    $n = 0;
    if (is_string($last) && preg_match('/^DN-(\d+)$/', $last, $m)) {
        $n = (int) $m[1];
    }

    return 'DN-' . str_pad((string) ($n + 1), 6, '0', STR_PAD_LEFT);
}

/** @return array<string, mixed>|null */
function fin_debit_note_fetch(PDO $pdo, int $id): ?array
{
    if ($id < 1) {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM fin_debit_note WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/** @return list<array<string, mixed>> */
function fin_debit_note_fetch_lines(PDO $pdo, int $noteId): array
{
    $st = $pdo->prepare(
        'SELECT l.id, l.item_id, l.description_ar, l.qty, l.unit_price, l.line_total,
                i.code AS item_code, i.name_ar AS item_name
         FROM fin_debit_note_line l
         LEFT JOIN inv_item i ON i.id = l.item_id
         WHERE l.note_id = ?
         ORDER BY l.id'
    );
    $st->execute([$noteId]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function fin_debit_note_nav_neighbor_id(PDO $pdo, int $id, string $dir): ?int
{
    if ($id < 1) {
        return null;
    }
    if ($dir === 'prev') {
        $st = $pdo->prepare('SELECT id FROM fin_debit_note WHERE id < ? ORDER BY id DESC LIMIT 1');
    } else {
        $st = $pdo->prepare('SELECT id FROM fin_debit_note WHERE id > ? ORDER BY id ASC LIMIT 1');
    }
    $st->execute([$id]);
    $nid = $st->fetchColumn();

    return $nid !== false ? (int) $nid : null;
}

function fin_debit_note_unsync_ledger(PDO $pdo, int $noteId): void
{
    if ($noteId < 1) {
        return;
    }
    require_once app_path('includes/crm_customer_ledger.php');
    require_once app_path('includes/crm_supplier_ledger.php');
    if (crm_ledger_has_table($pdo)) {
        $pdo->prepare('DELETE FROM crm_customer_ledger WHERE txn_type = ? AND ref_id = ?')
            ->execute(['debit_note', $noteId]);
    }
    if (crm_supplier_ledger_has_table($pdo)) {
        $pdo->prepare('DELETE FROM crm_supplier_ledger WHERE txn_type = ? AND ref_id = ?')
            ->execute(['debit_note', $noteId]);
    }
}

function fin_debit_note_sync_ledger(PDO $pdo, int $noteId): void
{
    $note = fin_debit_note_fetch($pdo, $noteId);
    if (!$note) {
        return;
    }

    fin_debit_note_unsync_ledger($pdo, $noteId);

    $partyType = (string) ($note['party_type'] ?? '');
    $partyId = (int) ($note['party_id'] ?? 0);
    $total = (float) ($note['total'] ?? 0);
    if ($partyId < 1 || $total <= 0) {
        return;
    }

    $txnDate = (string) ($note['note_date'] ?? date('Y-m-d'));
    $refNo = (string) ($note['note_no'] ?? '');
    $memo = trim((string) ($note['reason'] ?? ''));
    if ($memo === '') {
        $memo = 'إشعار مدين';
    }

    require_once app_path('includes/crm_customer_ledger.php');
    require_once app_path('includes/crm_supplier_ledger.php');

    if ($partyType === 'customer' && crm_ledger_has_table($pdo)) {
        crm_ledger_insert($pdo, $partyId, $txnDate, 'debit_note', $noteId, $refNo, 'credit', $total, 0.0, $memo);
    } elseif ($partyType === 'supplier' && crm_supplier_ledger_has_table($pdo)) {
        crm_supplier_ledger_insert($pdo, $partyId, $txnDate, 'debit_note', $noteId, $refNo, 'credit', 0.0, $total, $memo);
    }

    require_once app_path('includes/acc_gl.php');
    acc_gl_post_debit_note($pdo, $noteId);
}

/**
 * @param list<array<string, mixed>> $lines
 */
function fin_debit_note_save(PDO $pdo, array $header, array $lines): int
{
    $id = (int) ($header['id'] ?? 0);
    $partyType = (string) ($header['party_type'] ?? '');
    if (!in_array($partyType, ['customer', 'supplier'], true)) {
        throw new RuntimeException('نوع الطرف غير صالح.');
    }
    $partyId = (int) ($header['party_id'] ?? 0);
    if ($partyId < 1) {
        throw new RuntimeException($partyType === 'customer' ? 'اختر العميل.' : 'اختر المورد.');
    }

    $noteDate = parse_date_to_iso(trim((string) ($header['note_date'] ?? ''))) ?? '';
    if ($noteDate === '') {
        throw new RuntimeException('تاريخ الإشعار غير صالح.');
    }

    $reason = trim((string) ($header['reason'] ?? ''));
    $uid = (int) (current_user()['id'] ?? 0) ?: null;

    $total = 0.0;
    foreach ($lines as $ln) {
        $total += (float) ($ln['line_total'] ?? 0);
    }
    if ($total <= 0) {
        throw new RuntimeException('أضف سطرًا واحدًا على الأقل بمبلغ أكبر من صفر.');
    }

    if ($partyType === 'customer') {
        $chk = $pdo->prepare('SELECT id FROM crm_customer WHERE id = ? AND is_active = 1 LIMIT 1');
    } else {
        $chk = $pdo->prepare('SELECT id FROM crm_supplier WHERE id = ? AND is_active = 1 LIMIT 1');
    }
    $chk->execute([$partyId]);
    if (!$chk->fetch()) {
        throw new RuntimeException($partyType === 'customer' ? 'العميل غير موجود أو غير نشط.' : 'المورد غير موجود أو غير نشط.');
    }

    if ($id > 0) {
        $cur = fin_debit_note_fetch($pdo, $id);
        if (!$cur) {
            throw new RuntimeException('الإشعار غير موجود.');
        }
        $pdo->prepare(
            'UPDATE fin_debit_note SET note_date = ?, party_type = ?, party_id = ?, total = ?, reason = ? WHERE id = ?'
        )->execute([
            $noteDate,
            $partyType,
            $partyId,
            round($total, 6),
            $reason !== '' ? $reason : null,
            $id,
        ]);
        $pdo->prepare('DELETE FROM fin_debit_note_line WHERE note_id = ?')->execute([$id]);
    } else {
        $noteNo = fin_debit_note_next_no($pdo);
        $ins = $pdo->prepare(
            'INSERT INTO fin_debit_note (note_no, note_date, party_type, party_id, total, reason, created_by)
             VALUES (?,?,?,?,?,?,?)'
        );
        $ins->execute([
            $noteNo,
            $noteDate,
            $partyType,
            $partyId,
            round($total, 6),
            $reason !== '' ? $reason : null,
            $uid,
        ]);
        $id = (int) $pdo->lastInsertId();
    }

    $stLine = $pdo->prepare(
        'INSERT INTO fin_debit_note_line (note_id, item_id, description_ar, qty, unit_price, line_total)
         VALUES (?,?,?,?,?,?)'
    );
    foreach ($lines as $ln) {
        $itemId = (int) ($ln['item_id'] ?? 0);
        $desc = trim((string) ($ln['description_ar'] ?? ''));
        $qty = (float) ($ln['qty'] ?? 0);
        $unitPrice = (float) ($ln['unit_price'] ?? 0);
        $lineTotal = (float) ($ln['line_total'] ?? 0);
        if ($lineTotal <= 0) {
            continue;
        }
        $stLine->execute([
            $id,
            $itemId > 0 ? $itemId : null,
            $desc !== '' ? $desc : null,
            $qty > 0 ? $qty : 1,
            round($unitPrice, 6),
            round($lineTotal, 6),
        ]);
    }

    fin_debit_note_sync_ledger($pdo, $id);

    return $id;
}

function fin_debit_note_delete(PDO $pdo, int $id): void
{
    if ($id < 1) {
        throw new RuntimeException('معرّف غير صالح.');
    }
    if (!fin_debit_note_fetch($pdo, $id)) {
        throw new RuntimeException('الإشعار غير موجود.');
    }
    fin_debit_note_unsync_ledger($pdo, $id);
    $pdo->prepare('DELETE FROM fin_debit_note WHERE id = ?')->execute([$id]);
}
