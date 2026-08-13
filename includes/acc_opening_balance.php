<?php
declare(strict_types=1);

/** إدخال الأرصدة الافتتاحية — قيد افتتاحي واحد لكل سنة مالية. */

require_once app_path('includes/acc_gl.php');
require_once app_path('includes/acc_journal.php');
require_once app_path('includes/acc_period_lock.php');

function acc_opening_balance_default_date(int $year): string
{
    return sprintf('%04d-01-01', $year);
}

/** @return array<string, mixed>|null */
function acc_opening_balance_journal_row(PDO $pdo, int $year): ?array
{
    if ($year < 2000 || $year > 2100 || !acc_journal_has_tables($pdo)) {
        return null;
    }
    if (!acc_gl_journal_has_ref_columns($pdo)) {
        return null;
    }

    $st = $pdo->prepare(
        "SELECT e.* FROM acc_journal_entry e
         WHERE e.ref_type = 'opening_balance' AND e.ref_id = ? AND e.source = 'auto'
         ORDER BY e.id DESC LIMIT 1"
    );
    $st->execute([$year]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row !== false ? $row : null;
}

function acc_opening_balance_is_posted(PDO $pdo, int $year): bool
{
    $row = acc_opening_balance_journal_row($pdo, $year);

    return $row !== null && (string) ($row['status'] ?? '') === 'posted';
}

/**
 * @return array<int, array{debit: float, credit: float}>
 */
function acc_opening_balance_amounts_from_journal(PDO $pdo, int $journalId): array
{
    if ($journalId < 1) {
        return [];
    }

    require_once app_path('includes/acc_journal_party.php');
    $hasParty = acc_journal_party_has_columns($pdo);
    $sql = $hasParty
        ? 'SELECT account_id, debit, credit, party_type, party_id
           FROM acc_journal_line WHERE journal_id = ? ORDER BY id ASC'
        : 'SELECT account_id, debit, credit FROM acc_journal_line WHERE journal_id = ? ORDER BY id ASC';

    $st = $pdo->prepare($sql);
    $st->execute([$journalId]);
    $out = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        /* أسطر العملاء/الموردين تُعرض في قسم منفصل — لا تُجمَّع في شبكة الحسابات */
        if ($hasParty) {
            $partyType = strtolower(trim((string) ($row['party_type'] ?? '')));
            $partyId = (int) ($row['party_id'] ?? 0);
            if ($partyId > 0 && in_array($partyType, ['customer', 'supplier'], true)) {
                continue;
            }
        }
        $aid = (int) ($row['account_id'] ?? 0);
        if ($aid < 1) {
            continue;
        }
        if (!isset($out[$aid])) {
            $out[$aid] = ['debit' => 0.0, 'credit' => 0.0];
        }
        $out[$aid]['debit'] = round($out[$aid]['debit'] + (float) ($row['debit'] ?? 0), 6);
        $out[$aid]['credit'] = round($out[$aid]['credit'] + (float) ($row['credit'] ?? 0), 6);
    }

    return $out;
}

/**
 * أرصدة افتتاحية مربوطة بعملاء/موردين من قيد الافتتاح.
 *
 * @return array{customers:list<array<string,mixed>>, suppliers:list<array<string,mixed>>, ar_account_id:int, ap_account_id:int}
 */
function acc_opening_balance_parties_from_journal(PDO $pdo, int $journalId): array
{
    require_once app_path('includes/acc_journal_party.php');
    $ids = acc_journal_party_ar_ap_ids($pdo);
    $empty = [
        'customers' => [],
        'suppliers' => [],
        'ar_account_id' => (int) ($ids['ar'] ?? 0),
        'ap_account_id' => (int) ($ids['ap'] ?? 0),
    ];
    if ($journalId < 1 || !acc_journal_party_has_columns($pdo)) {
        return $empty;
    }

    $st = $pdo->prepare(
        "SELECT l.party_type, l.party_id, l.debit, l.credit, l.memo,
                CASE l.party_type
                  WHEN 'customer' THEN c.code
                  WHEN 'supplier' THEN s.code
                  ELSE ''
                END AS party_code,
                CASE l.party_type
                  WHEN 'customer' THEN c.name_ar
                  WHEN 'supplier' THEN s.name_ar
                  ELSE ''
                END AS party_name
         FROM acc_journal_line l
         LEFT JOIN crm_customer c ON l.party_type = 'customer' AND c.id = l.party_id
         LEFT JOIN crm_supplier s ON l.party_type = 'supplier' AND s.id = l.party_id
         WHERE l.journal_id = ?
           AND l.party_id IS NOT NULL AND l.party_id > 0
           AND l.party_type IN ('customer','supplier')
         ORDER BY l.id ASC"
    );
    $st->execute([$journalId]);
    $customers = [];
    $suppliers = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $item = [
            'party_id' => (int) ($row['party_id'] ?? 0),
            'party_code' => (string) ($row['party_code'] ?? ''),
            'party_name' => (string) ($row['party_name'] ?? ''),
            'debit' => round((float) ($row['debit'] ?? 0), 6),
            'credit' => round((float) ($row['credit'] ?? 0), 6),
            'memo' => (string) ($row['memo'] ?? ''),
        ];
        if ($item['party_id'] < 1) {
            continue;
        }
        if ((string) ($row['party_type'] ?? '') === 'customer') {
            $customers[] = $item;
        } else {
            $suppliers[] = $item;
        }
    }

    return [
        'customers' => $customers,
        'suppliers' => $suppliers,
        'ar_account_id' => (int) ($ids['ar'] ?? 0),
        'ap_account_id' => (int) ($ids['ap'] ?? 0),
    ];
}

/**
 * @return list<array{account_id:int, code:string, name_ar:string, account_type:string, debit:float, credit:float}>
 */
function acc_opening_balance_grid(PDO $pdo, int $year): array
{
    $amounts = [];
    $journal = acc_opening_balance_journal_row($pdo, $year);
    if ($journal !== null) {
        $amounts = acc_opening_balance_amounts_from_journal($pdo, (int) ($journal['id'] ?? 0));
    }

    $rows = [];
    foreach (acc_journal_load_leaf_accounts($pdo) as $acc) {
        $id = (int) ($acc['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        $amt = $amounts[$id] ?? ['debit' => 0.0, 'credit' => 0.0];
        $rows[] = [
            'account_id' => $id,
            'code' => (string) ($acc['code'] ?? ''),
            'name_ar' => (string) ($acc['name_ar'] ?? ''),
            'account_type' => (string) ($acc['account_type'] ?? ''),
            'debit' => (float) $amt['debit'],
            'credit' => (float) $amt['credit'],
        ];
    }

    return $rows;
}

/**
 * @return array{customers:list<array<string,mixed>>, suppliers:list<array<string,mixed>>, ar_account_id:int, ap_account_id:int}
 */
function acc_opening_balance_parties(PDO $pdo, int $year): array
{
    require_once app_path('includes/acc_journal_party.php');
    $ids = acc_journal_party_ar_ap_ids($pdo);
    $journal = acc_opening_balance_journal_row($pdo, $year);
    if ($journal === null) {
        return [
            'customers' => [],
            'suppliers' => [],
            'ar_account_id' => (int) ($ids['ar'] ?? 0),
            'ap_account_id' => (int) ($ids['ap'] ?? 0),
        ];
    }

    return acc_opening_balance_parties_from_journal($pdo, (int) ($journal['id'] ?? 0));
}

/**
 * @return array{journal_id:int, entry_date:string, entry_no:string, total_debit:float, total_credit:float, line_count:int}
 */
function acc_opening_balance_status(PDO $pdo, int $year): array
{
    $journal = acc_opening_balance_journal_row($pdo, $year);
    if ($journal === null) {
        return [
            'journal_id' => 0,
            'entry_date' => acc_opening_balance_default_date($year),
            'entry_no' => '',
            'total_debit' => 0.0,
            'total_credit' => 0.0,
            'line_count' => 0,
        ];
    }

    $jid = (int) ($journal['id'] ?? 0);
    $amounts = acc_opening_balance_amounts_from_journal($pdo, $jid);
    $parties = acc_opening_balance_parties_from_journal($pdo, $jid);
    $totalDebit = 0.0;
    $totalCredit = 0.0;
    $lineCount = 0;
    foreach ($amounts as $amt) {
        $totalDebit += (float) $amt['debit'];
        $totalCredit += (float) $amt['credit'];
        if ((float) $amt['debit'] > 0 || (float) $amt['credit'] > 0) {
            $lineCount++;
        }
    }
    foreach (array_merge($parties['customers'], $parties['suppliers']) as $p) {
        $totalDebit += (float) ($p['debit'] ?? 0);
        $totalCredit += (float) ($p['credit'] ?? 0);
        $lineCount++;
    }

    return [
        'journal_id' => $jid,
        'entry_date' => (string) ($journal['entry_date'] ?? acc_opening_balance_default_date($year)),
        'entry_no' => (string) ($journal['entry_no'] ?? ''),
        'total_debit' => round($totalDebit, 6),
        'total_credit' => round($totalCredit, 6),
        'line_count' => $lineCount,
    ];
}

/**
 * @param array<int, array{debit?:mixed, credit?:mixed}> $posted account_id => amounts
 * @return list<array{account_id:int, debit:float, credit:float, memo:string}>
 */
function acc_opening_balance_normalize_posted_lines(array $posted): array
{
    $lines = [];
    foreach ($posted as $accountId => $raw) {
        $accountId = (int) $accountId;
        if ($accountId < 1 || !is_array($raw)) {
            continue;
        }
        $debit = round(max(0, (float) str_replace(',', '.', (string) ($raw['debit'] ?? '0'))), 6);
        $credit = round(max(0, (float) str_replace(',', '.', (string) ($raw['credit'] ?? '0'))), 6);
        if ($debit <= 0 && $credit <= 0) {
            continue;
        }
        if ($debit > 0 && $credit > 0) {
            throw new RuntimeException('لا يمكن إدخال مدين ودائن معاً لنفس الحساب.');
        }
        $lines[] = [
            'account_id' => $accountId,
            'debit' => $debit,
            'credit' => $credit,
            'memo' => 'رصيد افتتاحي',
        ];
    }

    return $lines;
}

/**
 * @param list<array{party_type?:string, party_id?:mixed, debit?:mixed, credit?:mixed, memo?:string}> $parties
 * @return list<array{account_id:int, debit:float, credit:float, memo:string, party_type:string, party_id:int}>
 */
function acc_opening_balance_normalize_party_lines(PDO $pdo, array $parties): array
{
    require_once app_path('includes/acc_journal_party.php');
    acc_journal_party_ensure_schema($pdo);
    $ids = acc_journal_party_ar_ap_ids($pdo);
    $arId = (int) ($ids['ar'] ?? 0);
    $apId = (int) ($ids['ap'] ?? 0);

    $lines = [];
    $seen = [];
    foreach ($parties as $raw) {
        if (!is_array($raw)) {
            continue;
        }
        $partyType = strtolower(trim((string) ($raw['party_type'] ?? '')));
        $partyId = (int) ($raw['party_id'] ?? 0);
        if ($partyId < 1 || !in_array($partyType, ['customer', 'supplier'], true)) {
            continue;
        }
        $debit = round(max(0, (float) str_replace(',', '.', (string) ($raw['debit'] ?? '0'))), 6);
        $credit = round(max(0, (float) str_replace(',', '.', (string) ($raw['credit'] ?? '0'))), 6);
        if ($debit <= 0 && $credit <= 0) {
            continue;
        }
        if ($debit > 0 && $credit > 0) {
            throw new RuntimeException('لا يمكن إدخال مدين ودائن معاً لنفس العميل/المورد.');
        }

        $key = $partyType . ':' . $partyId;
        if (isset($seen[$key])) {
            throw new RuntimeException('تكرار في أرصدة ' . ($partyType === 'customer' ? 'العملاء' : 'الموردين') . '.');
        }
        $seen[$key] = true;

        if ($partyType === 'customer') {
            if ($arId < 1) {
                throw new RuntimeException('عرّف حساب ذمم العملاء في ربط الحسابات قبل إدخال أرصدة العملاء.');
            }
            $accountId = $arId;
            $name = acc_journal_party_display_name($pdo, 'customer', $partyId);
            if ($name === '') {
                throw new RuntimeException('عميل غير موجود (#' . $partyId . ').');
            }
            $memo = 'رصيد افتتاحي — عميل: ' . $name;
        } else {
            if ($apId < 1) {
                throw new RuntimeException('عرّف حساب ذمم الموردين في ربط الحسابات قبل إدخال أرصدة الموردين.');
            }
            $accountId = $apId;
            $name = acc_journal_party_display_name($pdo, 'supplier', $partyId);
            if ($name === '') {
                throw new RuntimeException('مورد غير موجود (#' . $partyId . ').');
            }
            $memo = 'رصيد افتتاحي — مورد: ' . $name;
        }

        $lines[] = [
            'account_id' => $accountId,
            'debit' => $debit,
            'credit' => $credit,
            'memo' => $memo,
            'party_type' => $partyType,
            'party_id' => $partyId,
        ];
    }

    return $lines;
}

/**
 * @return array{ok:bool, errors:list<string>, warnings:list<string>, totals:array{debit:float, credit:float, diff:float}, line_count:int}
 */
function acc_opening_balance_preflight(PDO $pdo, int $year, string $entryDate, array $posted, array $parties = []): array
{
    $errors = [];
    $warnings = [];

    if ($year < 2000 || $year > 2100) {
        return [
            'ok' => false,
            'errors' => ['السنة غير صالحة.'],
            'warnings' => [],
            'totals' => ['debit' => 0.0, 'credit' => 0.0, 'diff' => 0.0],
            'line_count' => 0,
        ];
    }

    $entryDate = parse_date_to_iso(trim($entryDate)) ?? '';
    if ($entryDate === '') {
        $errors[] = 'تاريخ الافتتاح غير صالح.';
    } else {
        $entryYear = (int) date('Y', strtotime($entryDate));
        if ($entryYear !== $year) {
            $warnings[] = 'تاريخ الافتتاح (' . format_date_dmY($entryDate) . ') لا يطابق السنة المختارة (' . $year . ').';
        }
        $periodErr = acc_period_date_lock_error($pdo, $entryDate);
        if ($periodErr !== null) {
            $errors[] = $periodErr;
        }
        require_once app_path('includes/acc_year_close.php');
        $fiscalErr = acc_fiscal_year_date_lock_error($pdo, $entryDate);
        if ($fiscalErr !== null) {
            $errors[] = $fiscalErr;
        }
    }

    if (!acc_journal_has_tables($pdo)) {
        $errors[] = 'نظام القيود المحاسبية غير مهيأ.';
    }

    try {
        $lines = acc_opening_balance_normalize_posted_lines($posted);
        $partyLines = acc_opening_balance_normalize_party_lines($pdo, $parties);
        $lines = array_merge($lines, $partyLines);
    } catch (RuntimeException $e) {
        $errors[] = $e->getMessage();

        return [
            'ok' => false,
            'errors' => $errors,
            'warnings' => $warnings,
            'totals' => ['debit' => 0.0, 'credit' => 0.0, 'diff' => 0.0],
            'line_count' => 0,
        ];
    }

    if ($lines === []) {
        $errors[] = 'أدخل رصيداً افتتاحياً لحساب أو عميل أو مورد واحد على الأقل.';
    }

    $totalDebit = 0.0;
    $totalCredit = 0.0;
    foreach ($lines as $ln) {
        $totalDebit += (float) $ln['debit'];
        $totalCredit += (float) $ln['credit'];
    }
    $totalDebit = round($totalDebit, 6);
    $totalCredit = round($totalCredit, 6);
    $diff = round($totalDebit - $totalCredit, 6);

    if ($lines !== [] && abs($diff) >= 0.000001) {
        $errors[] = 'مجموع المدين (' . format_money($totalDebit) . ') لا يساوي مجموع الدائن (' . format_money($totalCredit) . ').';
    }

    if ($entryDate !== '' && acc_journal_has_tables($pdo)) {
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM acc_journal_entry
             WHERE status = 'posted'
               AND entry_date >= ?
               AND NOT (ref_type = 'opening_balance' AND ref_id = ? AND source = 'auto')"
        );
        $st->execute([$entryDate, $year]);
        $otherCount = (int) $st->fetchColumn();
        if ($otherCount > 0) {
            $warnings[] = 'يوجد ' . $otherCount . ' قيداً مرحّلاً في أو بعد تاريخ الافتتاح — تأكد أن الأرصدة الافتتاحية تُسجَّل قبل العمليات اليومية.';
        }
    }

    $validIds = [];
    foreach (acc_journal_load_leaf_accounts($pdo) as $acc) {
        $validIds[(int) $acc['id']] = true;
    }
    foreach ($lines as $ln) {
        if (!isset($validIds[(int) $ln['account_id']])) {
            $errors[] = 'أحد الحسابات المختارة غير صالح للترحيل.';
            break;
        }
    }

    return [
        'ok' => $errors === [],
        'errors' => $errors,
        'warnings' => $warnings,
        'totals' => ['debit' => $totalDebit, 'credit' => $totalCredit, 'diff' => $diff],
        'line_count' => count($lines),
    ];
}

/**
 * @param array<int, array{debit?:mixed, credit?:mixed}> $posted
 * @return array{journal_id:int, replaced:bool}
 */
function acc_opening_balance_save_and_post(PDO $pdo, int $year, string $entryDate, array $posted, ?int $userId, array $parties = []): array
{
    $check = acc_opening_balance_preflight($pdo, $year, $entryDate, $posted, $parties);
    if (!$check['ok']) {
        throw new RuntimeException(implode(' ', $check['errors']));
    }

    $entryDate = parse_date_to_iso(trim($entryDate)) ?? acc_opening_balance_default_date($year);
    $lines = array_merge(
        acc_opening_balance_normalize_posted_lines($posted),
        acc_opening_balance_normalize_party_lines($pdo, $parties)
    );
    $replaced = acc_opening_balance_is_posted($pdo, $year);

    $pdo->beginTransaction();
    try {
        if ($replaced || acc_gl_ref_exists($pdo, 'opening_balance', $year)) {
            $unpost = acc_gl_unpost_ref($pdo, 'opening_balance', $year);
            if (!$unpost['ok']) {
                throw new RuntimeException($unpost['error'] ?? 'تعذر استبدال القيد الافتتاحي السابق.');
            }
        }

        $journalId = acc_gl_post_entry(
            $pdo,
            'opening_balance',
            $year,
            $entryDate,
            'أرصدة افتتاحية — ' . $year,
            $lines
        );

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return ['journal_id' => $journalId, 'replaced' => $replaced];
}

function acc_opening_balance_unpost_execute(PDO $pdo, int $year, ?int $userId): void
{
    if ($year < 2000 || $year > 2100) {
        throw new RuntimeException('السنة غير صالحة.');
    }
    if (!acc_opening_balance_is_posted($pdo, $year)) {
        throw new RuntimeException('لا يوجد قيد افتتاحي مرحّل لهذه السنة.');
    }

    $journal = acc_opening_balance_journal_row($pdo, $year);
    if ($journal === null) {
        throw new RuntimeException('لم يُعثر على القيد الافتتاحي.');
    }

    $entryDate = (string) ($journal['entry_date'] ?? acc_opening_balance_default_date($year));
    $periodErr = acc_period_date_lock_error($pdo, $entryDate);
    if ($periodErr !== null) {
        throw new RuntimeException($periodErr);
    }

    require_once app_path('includes/acc_year_close.php');
    $fiscalErr = acc_fiscal_year_date_lock_error($pdo, $entryDate);
    if ($fiscalErr !== null) {
        throw new RuntimeException($fiscalErr);
    }

    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM acc_journal_entry
         WHERE status = 'posted'
           AND entry_date >= ?
           AND NOT (ref_type = 'opening_balance' AND ref_id = ? AND source = 'auto')"
    );
    $st->execute([$entryDate, $year]);
    if ((int) $st->fetchColumn() > 0) {
        throw new RuntimeException('لا يمكن فك الترحيل — يوجد قيود مرحّلة في أو بعد تاريخ الافتتاح. عالج تلك القيود أولاً.');
    }

    $unpost = acc_gl_unpost_ref($pdo, 'opening_balance', $year);
    if (!$unpost['ok']) {
        throw new RuntimeException($unpost['error'] ?? 'تعذر فك الترحيل.');
    }
    if ($unpost['skipped']) {
        throw new RuntimeException('لم يُعثر على القيد الافتتاحي.');
    }
}

function acc_opening_balance_account_type_label(string $type): string
{
    return match ($type) {
        'asset' => 'أصل',
        'liability' => 'خصم',
        'equity' => 'ملكية',
        'revenue' => 'إيراد',
        'expense' => 'مصروف',
        default => $type,
    };
}
