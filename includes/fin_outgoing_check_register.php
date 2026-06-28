<?php
declare(strict_types=1);

require_once app_path('includes/fin_voucher_checks.php');
require_once app_path('includes/fin_voucher_schema.php');
require_once app_path('includes/fin_voucher.php');

function fin_outgoing_check_register_has_column(PDO $pdo): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    if (!fin_voucher_checks_has_table($pdo)) {
        $cached = false;

        return false;
    }
    try {
        $pdo->query('SELECT register_no FROM fin_voucher_check LIMIT 1');
        $cached = true;
    } catch (Throwable $e) {
        $cached = false;
    }

    return $cached;
}

function fin_outgoing_check_register_ensure_schema(PDO $pdo): bool
{
    if (!fin_voucher_checks_ensure_table($pdo)) {
        return false;
    }
    if (!fin_outgoing_check_register_has_column($pdo)) {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/191_fin_outgoing_checks_register.sql');
    }
    if (!fin_outgoing_check_register_has_column($pdo)) {
        return false;
    }
    fin_outgoing_check_register_backfill($pdo);

    return true;
}

function fin_outgoing_check_register_backfill(PDO $pdo): void
{
    static $done = false;
    if ($done || !fin_outgoing_check_register_has_column($pdo) || !fin_voucher_has_table($pdo)) {
        return;
    }
    $done = true;

    if (!fin_voucher_has_column($pdo, 'pay_method')) {
        return;
    }

    try {
        $pdo->exec(
            "INSERT INTO fin_voucher_check (voucher_id, sort_order, check_no, bank_name, check_amount, notes, lifecycle_status)
             SELECT v.id, 1, NULLIF(TRIM(v.check_no), ''), NULLIF(TRIM(v.bank_name), ''), v.check_amount, NULL, 'pending'
             FROM fin_voucher v
             WHERE v.voucher_type = 'payment'
               AND v.pay_method = 'check'
               AND v.check_amount > 0.000001
               AND NOT EXISTS (SELECT 1 FROM fin_voucher_check c WHERE c.voucher_id = v.id)"
        );
    } catch (Throwable $e) {
        // ignore
    }

    try {
        $st = $pdo->query(
            "SELECT c.id, v.voucher_date
             FROM fin_voucher_check c
             INNER JOIN fin_voucher v ON v.id = c.voucher_id AND v.voucher_type = 'payment'
             WHERE (c.register_no IS NULL OR c.register_no = '')
               AND c.check_amount > 0.000001
             ORDER BY v.voucher_date ASC, v.id ASC, c.sort_order ASC, c.id ASC"
        );
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $checkId = (int) ($row['id'] ?? 0);
            $refDate = (string) ($row['voucher_date'] ?? date('Y-m-d'));
            if ($checkId < 1) {
                continue;
            }
            fin_outgoing_check_register_assign_one($pdo, $checkId, $refDate);
        }
    } catch (Throwable $e) {
        // ignore
    }
}

function fin_outgoing_check_register_generate_next_no(PDO $pdo, string $refDate): string
{
    $year = (int) date('Y', strtotime($refDate !== '' ? $refDate : 'now'));
    $suffix = '-' . $year;
    $st = $pdo->prepare(
        "SELECT c.register_no
         FROM fin_voucher_check c
         INNER JOIN fin_voucher v ON v.id = c.voucher_id AND v.voucher_type = 'payment'
         WHERE c.register_no LIKE ?
         FOR UPDATE"
    );
    $st->execute(['%' . $suffix]);
    $maxSeq = 0;
    $suffixQuoted = preg_quote($suffix, '/');
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $no) {
        $no = (string) $no;
        if (preg_match('/^(\d+)' . $suffixQuoted . '$/', $no, $m)) {
            $maxSeq = max($maxSeq, (int) $m[1]);
        }
    }

    return str_pad((string) ($maxSeq + 1), 3, '0', STR_PAD_LEFT) . $suffix;
}

function fin_outgoing_check_register_assign_one(PDO $pdo, int $checkId, string $refDate): void
{
    if ($checkId < 1 || !fin_outgoing_check_register_has_column($pdo)) {
        return;
    }
    $st = $pdo->prepare(
        'SELECT register_no FROM fin_voucher_check WHERE id = ? LIMIT 1 FOR UPDATE'
    );
    $st->execute([$checkId]);
    $existing = trim((string) ($st->fetchColumn() ?: ''));
    if ($existing !== '') {
        return;
    }
    $registerNo = fin_outgoing_check_register_generate_next_no($pdo, $refDate);
    $pdo->prepare('UPDATE fin_voucher_check SET register_no = ? WHERE id = ? AND (register_no IS NULL OR register_no = \'\')')
        ->execute([$registerNo, $checkId]);
}

function fin_outgoing_check_register_sync_voucher(PDO $pdo, int $voucherId): void
{
    if ($voucherId < 1 || !fin_outgoing_check_register_ensure_schema($pdo)) {
        return;
    }
    if (!fin_voucher_has_column($pdo, 'pay_method')) {
        return;
    }

    $st = $pdo->prepare(
        'SELECT voucher_type, pay_method, check_no, bank_name, check_amount, voucher_date
         FROM fin_voucher WHERE id = ? LIMIT 1'
    );
    $st->execute([$voucherId]);
    $v = $st->fetch(PDO::FETCH_ASSOC);
    if (!$v || (string) ($v['voucher_type'] ?? '') !== 'payment') {
        return;
    }
    if (fin_voucher_normalize_pay_method((string) ($v['pay_method'] ?? '')) !== 'check') {
        return;
    }

    $refDate = (string) ($v['voucher_date'] ?? date('Y-m-d'));
    $checkAmount = (float) ($v['check_amount'] ?? 0);

    $cntSt = $pdo->prepare('SELECT COUNT(*) FROM fin_voucher_check WHERE voucher_id = ?');
    $cntSt->execute([$voucherId]);
    $rowCount = (int) $cntSt->fetchColumn();

    if ($rowCount === 0 && $checkAmount > 0.000001) {
        $pdo->prepare(
            'INSERT INTO fin_voucher_check
                (voucher_id, sort_order, check_no, bank_name, check_amount, notes, lifecycle_status)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([
            $voucherId,
            1,
            trim((string) ($v['check_no'] ?? '')) !== '' ? trim((string) $v['check_no']) : null,
            trim((string) ($v['bank_name'] ?? '')) !== '' ? trim((string) $v['bank_name']) : null,
            round($checkAmount, 6),
            null,
            'pending',
        ]);
    }

    $listSt = $pdo->prepare(
        'SELECT id FROM fin_voucher_check
         WHERE voucher_id = ? AND check_amount > 0.000001
         ORDER BY sort_order ASC, id ASC'
    );
    $listSt->execute([$voucherId]);
    foreach ($listSt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $checkId) {
        fin_outgoing_check_register_assign_one($pdo, (int) $checkId, $refDate);
    }
}

/** @return array<string, mixed> */
function fin_outgoing_check_register_parse_filters(array $input): array
{
    $from = trim((string) ($input['from'] ?? ''));
    $to = trim((string) ($input['to'] ?? ''));
    $dateRangeActive = $from !== '' || $to !== '';

    return [
        'from' => $from,
        'to' => $to,
        'date_range_active' => $dateRangeActive,
        'check_id' => max(0, (int) ($input['check_id'] ?? 0)),
        'check_no' => trim((string) ($input['check_no'] ?? '')),
        'register_no' => trim((string) ($input['register_no'] ?? '')),
        'party_q' => trim((string) ($input['party_q'] ?? '')),
        'posted' => in_array((string) ($input['posted'] ?? 'all'), ['all', 'posted', 'unposted'], true)
            ? (string) ($input['posted'] ?? 'all')
            : 'all',
    ];
}

function fin_outgoing_check_register_party_type_label(string $partyType): string
{
    return match ($partyType) {
        'customer' => 'عميل',
        'supplier' => 'مورد',
        'employee' => 'موظف',
        'account' => 'حساب',
        default => '—',
    };
}

function fin_outgoing_check_register_posted_label(bool $isPosted): string
{
    return $isPosted ? 'مرحّل' : 'غير مرحّل';
}

/**
 * @return list<array<string, mixed>>
 */
function fin_outgoing_check_register_fetch(PDO $pdo, array $filters, ?string $today = null): array
{
    if (!fin_outgoing_check_register_ensure_schema($pdo) || !fin_voucher_has_table($pdo)) {
        return [];
    }

    $today = $today ?? date('Y-m-d');
    $fromIso = parse_date_to_iso($filters['from'] ?? '');
    $toIso = parse_date_to_iso($filters['to'] ?? '');
    if (!empty($filters['date_range_active']) && ($fromIso === null || $toIso === null || $fromIso > $toIso)) {
        return [];
    }

    $hasPostedCol = fin_voucher_has_column($pdo, 'is_posted');
    $postedExpr = $hasPostedCol ? 'v.is_posted' : '0';

    $sql =
        "SELECT c.id AS check_id, c.register_no, c.check_no, c.bank_name, c.check_amount, c.due_date, c.notes,
                c.lifecycle_status,
                v.id AS voucher_id, v.voucher_no, v.voucher_date, v.party_id, v.party_type,
                ({$postedExpr}) AS is_posted,
                COALESCE(cust.name_ar, sup.name_ar, emp.name_ar, acc_party.name_ar, '—') AS party_name
         FROM fin_voucher_check c
         INNER JOIN fin_voucher v ON v.id = c.voucher_id AND v.voucher_type = 'payment'
         LEFT JOIN crm_customer cust ON v.party_type = 'customer' AND cust.id = v.party_id
         LEFT JOIN crm_supplier sup ON v.party_type = 'supplier' AND sup.id = v.party_id
         LEFT JOIN hr_employee emp ON v.party_type = 'employee' AND emp.id = v.party_id
         LEFT JOIN acc_account acc_party ON v.party_type = 'account' AND acc_party.id = v.party_id
         WHERE c.check_amount > 0.000001
           AND c.register_no IS NOT NULL AND c.register_no <> ''";

    $params = [];

    $checkIdFilter = (int) ($filters['check_id'] ?? 0);
    if ($checkIdFilter > 0) {
        $sql .= ' AND c.id = ?';
        $params[] = $checkIdFilter;
    }

    if (!empty($filters['date_range_active'])) {
        $sql .= ' AND v.voucher_date BETWEEN ? AND ?';
        $params[] = $fromIso;
        $params[] = $toIso;
    }

    $registerNo = trim((string) ($filters['register_no'] ?? ''));
    if ($registerNo !== '') {
        $sql .= ' AND c.register_no LIKE ?';
        $params[] = '%' . $registerNo . '%';
    }

    $checkNo = trim((string) ($filters['check_no'] ?? ''));
    if ($checkNo !== '') {
        $sql .= ' AND c.check_no LIKE ?';
        $params[] = '%' . $checkNo . '%';
    }

    $partyQ = trim((string) ($filters['party_q'] ?? ''));
    if ($partyQ !== '') {
        $sql .= ' AND COALESCE(cust.name_ar, sup.name_ar, emp.name_ar, acc_party.name_ar, \'\') LIKE ?';
        $params[] = '%' . $partyQ . '%';
    }

    $posted = (string) ($filters['posted'] ?? 'all');
    if ($hasPostedCol) {
        if ($posted === 'posted') {
            $sql .= ' AND v.is_posted = 1';
        } elseif ($posted === 'unposted') {
            $sql .= ' AND v.is_posted = 0';
        }
    }

    $sql .= ' ORDER BY c.register_no ASC, v.voucher_date ASC, c.id ASC';

    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $vid = (int) ($row['voucher_id'] ?? 0);
        $partyType = (string) ($row['party_type'] ?? '');
        $lifecycle = (string) ($row['lifecycle_status'] ?? 'pending');
        $isPosted = (int) ($row['is_posted'] ?? 0) === 1;

        $out[] = [
            'check_id' => (int) ($row['check_id'] ?? 0),
            'register_no' => (string) ($row['register_no'] ?? ''),
            'check_no' => trim((string) ($row['check_no'] ?? '')),
            'bank_name' => trim((string) ($row['bank_name'] ?? '')),
            'check_amount' => (float) ($row['check_amount'] ?? 0),
            'due_date' => trim((string) ($row['due_date'] ?? '')),
            'notes' => trim((string) ($row['notes'] ?? '')),
            'voucher_id' => $vid,
            'voucher_no' => (string) ($row['voucher_no'] ?? ''),
            'voucher_date' => (string) ($row['voucher_date'] ?? ''),
            'party_id' => (int) ($row['party_id'] ?? 0),
            'party_type' => $partyType,
            'party_type_label' => fin_outgoing_check_register_party_type_label($partyType),
            'party_name' => (string) ($row['party_name'] ?? '—'),
            'is_posted' => $isPosted,
            'posted_label' => fin_outgoing_check_register_posted_label($isPosted),
            'lifecycle_status' => $lifecycle,
            'lifecycle_label' => match ($lifecycle) {
                'cleared' => 'مصروف',
                'returned' => 'مُرجَع',
                'endorsed' => 'مُجيَّر',
                default => 'قيد',
            },
            'voucher_url' => $vid > 0 ? app_url('index.php?r=cash_payment&id=' . $vid) : '',
        ];
    }

    return $out;
}

/** @return array<string, mixed>|null */
function fin_outgoing_check_register_load_one(PDO $pdo, int $checkId): ?array
{
    if ($checkId < 1) {
        return null;
    }
    $rows = fin_outgoing_check_register_fetch($pdo, ['check_id' => $checkId] + fin_outgoing_check_register_parse_filters([]));

    return $rows[0] ?? null;
}

/**
 * @return list<string>
 */
function fin_outgoing_check_register_filter_caption_lines(array $filters, string $fromDisplay, string $toDisplay): array
{
    $lines = [];
    if (!empty($filters['date_range_active'])) {
        $lines[] = 'الفترة: ' . $fromDisplay . ' — ' . $toDisplay;
    } else {
        $lines[] = 'الفترة: جميع الشيكات الصادرة';
    }

    $registerNo = trim((string) ($filters['register_no'] ?? ''));
    if ($registerNo !== '') {
        $lines[] = 'رقم تسلسلي: ' . $registerNo;
    }

    $checkNo = trim((string) ($filters['check_no'] ?? ''));
    if ($checkNo !== '') {
        $lines[] = 'رقم الشيك: ' . $checkNo;
    }

    $partyQ = trim((string) ($filters['party_q'] ?? ''));
    if ($partyQ !== '') {
        $lines[] = 'الجهة: ' . $partyQ;
    }

    $posted = (string) ($filters['posted'] ?? 'all');
    if ($posted === 'posted') {
        $lines[] = 'ترحيل السند: مرحّل فقط';
    } elseif ($posted === 'unposted') {
        $lines[] = 'ترحيل السند: غير مرحّل فقط';
    }

    return $lines;
}
