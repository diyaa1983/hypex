<?php
declare(strict_types=1);

function acc_journal_has_tables(PDO $pdo): bool
{
    static $ok = false;
    if ($ok) {
        return true;
    }
    try {
        $pdo->query('SELECT id FROM acc_journal_entry LIMIT 1');
        $pdo->query('SELECT id FROM acc_journal_line LIMIT 1');
        $pdo->query('SELECT id FROM acc_account LIMIT 1');
        $ok = true;
    } catch (Throwable $e) {
        return false;
    }

    return $ok;
}

/** إنشاء جداول القيود ودليل الحسابات إن لم تكن موجودة (بدون حذف بيانات). */
function acc_journal_ensure_schema(PDO $pdo): bool
{
    if (acc_journal_has_tables($pdo)) {
        acc_journal_ensure_updated_by_column($pdo);

        return true;
    }

    require_once app_path('includes/sql_migration.php');
    sql_migration_run_file($pdo, 'database/migrations/026_acc_journal_tables.sql');
    acc_journal_ensure_updated_by_column($pdo);

    return acc_journal_has_tables($pdo);
}

function acc_journal_ensure_updated_by_column(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    if (!acc_journal_has_tables($pdo)) {
        return;
    }
    try {
        $pdo->query('SELECT updated_by FROM acc_journal_entry LIMIT 1');
        $done = true;

        return;
    } catch (Throwable $e) {
        // column missing — migrate below
    }

    require_once app_path('includes/sql_migration.php');
    try {
        sql_migration_run_file($pdo, 'database/migrations/189_acc_journal_updated_by.sql');
    } catch (Throwable $e) {
        try {
            $pdo->exec(
                'ALTER TABLE acc_journal_entry ADD COLUMN updated_by INT UNSIGNED NULL AFTER created_by'
            );
        } catch (Throwable $e2) {
            // ignore if already exists
        }
    }
    $done = true;
}

/** اسم منشئ القيد أو آخر معدّل له. */
function acc_journal_entry_actor_name(array $row): string
{
    $updated = trim((string) ($row['updated_by_name'] ?? ''));
    if ($updated !== '') {
        return $updated;
    }
    $created = trim((string) ($row['created_by_name'] ?? ''));

    return $created !== '' ? $created : '—';
}

function acc_journal_entry_actor_kind(array $row): string
{
    $updated = trim((string) ($row['updated_by_name'] ?? ''));

    return $updated !== '' ? 'تعديل' : 'إنشاء';
}

function acc_journal_status_label(string $status): string
{
    $map = [
        'draft' => 'مسودة',
        'posted' => 'مرحّل',
        'cancelled' => 'ملغى',
    ];

    return $map[$status] ?? $status;
}

function acc_journal_next_entry_no(PDO $pdo, string $entryDate = ''): string
{
    if ($entryDate === '') {
        $entryDate = date('Y-m-d');
    }

    return acc_journal_next_voucher_no($pdo, $entryDate);
}

/** شرط SQL: سندات القيد اليدوية فقط (مُدخلة من شاشة سند القيد). */
function acc_journal_voucher_manual_sql(): string
{
    return "COALESCE(source, 'manual') = 'manual'";
}

function acc_journal_entry_source(PDO $pdo, int $id): string
{
    if ($id < 1) {
        return '';
    }
    $st = $pdo->prepare("SELECT COALESCE(source, 'manual') AS source FROM acc_journal_entry WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $source = $st->fetchColumn();

    return is_string($source) ? $source : 'manual';
}

function acc_journal_is_manual_voucher(PDO $pdo, int $id): bool
{
    return acc_journal_entry_source($pdo, $id) === 'manual';
}

function acc_journal_assert_manual_voucher(PDO $pdo, int $id): void
{
    if (!acc_journal_is_manual_voucher($pdo, $id)) {
        throw new RuntimeException('هذا قيد تلقائي من مستند آخر. عدّله من شاشة المستند الأصلي وليس من سند القيد.');
    }
}

/**
 * @return array{ref_type:string, ref_id:int, ref_url:?string, ref_label:string}|null
 */
function acc_journal_auto_entry_ref(PDO $pdo, int $id): ?array
{
    if ($id < 1 || acc_journal_is_manual_voucher($pdo, $id)) {
        return null;
    }
    $st = $pdo->prepare('SELECT ref_type, ref_id FROM acc_journal_entry WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $refType = trim((string) ($row['ref_type'] ?? ''));
    $refId = (int) ($row['ref_id'] ?? 0);
    if ($refType === '' || $refId < 1) {
        return null;
    }
    require_once app_path('includes/acc_report_ref.php');
    $refUrl = acc_report_ref_url($refType, $refId);

    return [
        'ref_type' => $refType,
        'ref_id' => $refId,
        'ref_url' => $refUrl,
        'ref_label' => acc_report_ref_type_label($refType),
    ];
}

/** آخر سند قيد يدوي (edge=first في الواجهة = أحدث مستند — مثل فواتير البيع). */
function acc_journal_voucher_first_id(PDO $pdo): ?int
{
    $sql = 'SELECT id FROM acc_journal_entry WHERE ' . acc_journal_voucher_manual_sql() . ' ORDER BY id DESC LIMIT 1';
    $id = $pdo->query($sql)->fetchColumn();

    return $id !== false ? (int) $id : null;
}

function acc_journal_voucher_neighbor_id(PDO $pdo, int $id, string $direction): ?int
{
    if ($id < 1) {
        return null;
    }
    $direction = $direction === 'prev' ? 'prev' : 'next';
    $manual = acc_journal_voucher_manual_sql();
    if ($direction === 'prev') {
        $st = $pdo->prepare(
            'SELECT id FROM acc_journal_entry WHERE id < ? AND ' . $manual . ' ORDER BY id DESC LIMIT 1'
        );
    } else {
        $st = $pdo->prepare(
            'SELECT id FROM acc_journal_entry WHERE id > ? AND ' . $manual . ' ORDER BY id ASC LIMIT 1'
        );
    }
    $st->execute([$id]);
    $nid = $st->fetchColumn();

    return $nid !== false ? (int) $nid : null;
}

/** @return list<int> */
function acc_journal_voucher_search_ids_by_no_fragment(PDO $pdo, string $fragment, int $limit = 200): array
{
    require_once app_path('includes/doc_no_fragment_search.php');
    $fragment = trim($fragment);
    if ($fragment === '') {
        return [];
    }

    $limit = max(1, min(500, $limit));
    $manual = acc_journal_voucher_manual_sql();

    return doc_no_search_ids_like(
        $pdo,
        "SELECT id FROM acc_journal_entry
         WHERE entry_no LIKE ? AND {$manual}
         ORDER BY entry_no ASC, id ASC
         LIMIT {$limit}",
        [doc_no_sql_like_pattern($fragment)]
    );
}

/** @return array<string, mixed>|null */
function acc_journal_voucher_fetch_by_no(PDO $pdo, string $entryNo): ?array
{
    require_once app_path('includes/doc_no_fragment_search.php');

    return doc_no_fetch_exact_or_fragment(
        $pdo,
        $entryNo,
        'SELECT id FROM acc_journal_entry WHERE entry_no = ? AND ' . acc_journal_voucher_manual_sql() . ' LIMIT 1',
        [trim($entryNo)],
        static fn (string $frag): array => acc_journal_voucher_search_ids_by_no_fragment($pdo, $frag),
        static fn (int $id): ?array => acc_journal_api_entry($pdo, $id)
    );
}

/** @return list<array<string, mixed>> */
function acc_journal_load_leaf_accounts(PDO $pdo): array
{
    return $pdo->query(
        'SELECT a.id, a.code, a.name_ar, a.account_type
         FROM acc_account a
         WHERE a.is_active = 1
           AND (
               a.is_leaf = 1
               OR NOT EXISTS (
                   SELECT 1 FROM acc_account c
                   WHERE c.parent_id = a.id AND c.is_active = 1
                   LIMIT 1
               )
           )
         ORDER BY a.code ASC, a.name_ar ASC'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * بحث حسابات نهائية للاختيار (شجرة / سند قيد / تقارير).
 *
 * @return list<array{id:int, code:string, name_ar:string, account_type?:string}>
 */
function acc_accounts_picker_search(PDO $pdo, string $query, int $limit = 80, bool $postedMovesOnly = false, bool $forMapping = false): array
{
    require_once app_path('includes/acc_account_tree.php');
    $limit = max(1, min(200, $limit));
    $q = trim($query);
    if ($q === '') {
        if ($forMapping) {
            return array_slice(acc_journal_accounts_picker_for_mapping($pdo), 0, $limit);
        }
        if ($postedMovesOnly) {
            require_once app_path('includes/acc_report.php');

            return array_slice(acc_report_leaf_accounts_picker($pdo), 0, $limit);
        }

        return array_slice(acc_journal_accounts_picker($pdo), 0, $limit);
    }

    $digits = preg_replace('/\D/', '', $q) ?? '';
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';
    $sql = 'SELECT a.id, a.code, a.name_ar, a.account_type
            FROM acc_account a
            WHERE a.is_active = 1
              AND (
                  a.is_leaf = 1
                  OR NOT EXISTS (
                      SELECT 1 FROM acc_account c
                      WHERE c.parent_id = a.id AND c.is_active = 1
                      LIMIT 1
                  )
              )';
    if ($postedMovesOnly) {
        $sql .= '
              AND EXISTS (
                  SELECT 1
                  FROM acc_journal_line l
                  INNER JOIN acc_journal_entry e ON e.id = l.journal_id
                  WHERE l.account_id = a.id AND e.status = \'posted\'
                  LIMIT 1
              )';
    }
    $sql .= '
              AND (a.name_ar LIKE ? OR a.code LIKE ?';
    $params = [$like, $like];
    if ($digits !== '') {
        $sql .= ' OR REPLACE(REPLACE(REPLACE(a.code, \' \', \'\'), \'-\', \'\'), \'_\', \'\') LIKE ?';
        $params[] = '%' . $digits . '%';
    }
    $sql .= ') ORDER BY a.code ASC, a.name_ar ASC LIMIT ' . $limit;

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        $row['code'] = acc_account_format_code((string) ($row['code'] ?? ''));
    }
    unset($row);

    if ($postedMovesOnly) {
        require_once app_path('includes/acc_report.php');

        return acc_report_deduplicate_picker_accounts($pdo, $rows, true);
    }

    if ($forMapping) {
        return acc_journal_filter_picker_for_mapping($pdo, $rows);
    }

    return $rows;
}

/**
 * حسابات نهائية للاختيار — يضمّن معرّفات إضافية (مثل الحساب المربوط حالياً) حتى لو كانت غير نشطة.
 *
 * @param list<int> $ensureIds
 * @return list<array<string, mixed>>
 */
function acc_journal_accounts_picker(PDO $pdo, array $ensureIds = []): array
{
    require_once app_path('includes/acc_account_tree.php');
    $rows = acc_journal_load_leaf_accounts($pdo);
    $seen = [];
    foreach ($rows as $row) {
        $seen[(int) ($row['id'] ?? 0)] = true;
    }
    foreach ($ensureIds as $rawId) {
        $id = (int) $rawId;
        if ($id < 1 || isset($seen[$id])) {
            continue;
        }
        $acc = acc_account_get($pdo, $id);
        if (!$acc || (int) ($acc['is_leaf'] ?? 0) !== 1) {
            continue;
        }
        $rows[] = [
            'id' => $id,
            'code' => (string) ($acc['code'] ?? ''),
            'name_ar' => (string) ($acc['name_ar'] ?? ''),
            'account_type' => (string) ($acc['account_type'] ?? ''),
        ];
        $seen[$id] = true;
    }
    usort($rows, static function (array $a, array $b): int {
        $cmp = strcmp((string) ($a['code'] ?? ''), (string) ($b['code'] ?? ''));
        if ($cmp !== 0) {
            return $cmp;
        }

        return strcmp((string) ($a['name_ar'] ?? ''), (string) ($b['name_ar'] ?? ''));
    });
    foreach ($rows as &$row) {
        $row['code'] = acc_account_format_code((string) ($row['code'] ?? ''));
    }
    unset($row);

    require_once app_path('includes/acc_report.php');

    return acc_report_deduplicate_picker_accounts($pdo, $rows, true);
}

/**
 * @param list<array<string, mixed>> $rows
 * @param list<int> $ensureIds
 * @return list<array<string, mixed>>
 */
function acc_journal_filter_picker_for_mapping(PDO $pdo, array $rows, array $ensureIds = []): array
{
    require_once app_path('includes/acc_coa_bootstrap.php');
    require_once app_path('includes/acc_account_tree.php');
    require_once app_path('includes/acc_report.php');

    $postedIds = acc_report_posted_account_ids($pdo);
    $ensureSet = [];
    foreach ($ensureIds as $rawId) {
        $id = (int) $rawId;
        if ($id > 0) {
            $ensureSet[$id] = true;
        }
    }

    $filtered = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        if (isset($postedIds[$id]) || isset($ensureSet[$id]) || acc_coa_is_posting_target($pdo, $id)) {
            $filtered[] = $row;
        }
    }

    $filtered = acc_report_deduplicate_picker_accounts($pdo, $filtered, true);

    $seen = [];
    foreach ($filtered as $row) {
        $seen[(int) ($row['id'] ?? 0)] = true;
    }
    foreach ($ensureIds as $rawId) {
        $id = (int) $rawId;
        if ($id < 1 || isset($seen[$id])) {
            continue;
        }
        $acc = acc_account_get($pdo, $id);
        if (!$acc || (int) ($acc['is_leaf'] ?? 0) !== 1) {
            continue;
        }
        $filtered[] = [
            'id' => $id,
            'code' => acc_account_format_code((string) ($acc['code'] ?? '')),
            'name_ar' => (string) ($acc['name_ar'] ?? ''),
            'account_type' => (string) ($acc['account_type'] ?? ''),
        ];
        $seen[$id] = true;
    }

    usort($filtered, static function (array $a, array $b): int {
        $cmp = strcmp((string) ($a['code'] ?? ''), (string) ($b['code'] ?? ''));
        if ($cmp !== 0) {
            return $cmp;
        }

        return strcmp((string) ($a['name_ar'] ?? ''), (string) ($b['name_ar'] ?? ''));
    });

    return $filtered;
}

/**
 * حسابات الربط: بدون تكرار الاسم، وعليها حركة مرحّلة أو مربوطة حالياً.
 *
 * @param list<int> $ensureIds
 * @return list<array<string, mixed>>
 */
function acc_journal_accounts_picker_for_mapping(PDO $pdo, array $ensureIds = []): array
{
    require_once app_path('includes/acc_account_tree.php');

    $rows = acc_journal_load_leaf_accounts($pdo);
    $seen = [];
    foreach ($rows as $row) {
        $seen[(int) ($row['id'] ?? 0)] = true;
    }
    foreach ($ensureIds as $rawId) {
        $id = (int) $rawId;
        if ($id < 1 || isset($seen[$id])) {
            continue;
        }
        $acc = acc_account_get($pdo, $id);
        if (!$acc || (int) ($acc['is_leaf'] ?? 0) !== 1) {
            continue;
        }
        $rows[] = [
            'id' => $id,
            'code' => (string) ($acc['code'] ?? ''),
            'name_ar' => (string) ($acc['name_ar'] ?? ''),
            'account_type' => (string) ($acc['account_type'] ?? ''),
        ];
        $seen[$id] = true;
    }
    foreach ($rows as &$row) {
        $row['code'] = acc_account_format_code((string) ($row['code'] ?? ''));
    }
    unset($row);

    return acc_journal_filter_picker_for_mapping($pdo, $rows, $ensureIds);
}

/**
 * @return array{header:array<string,mixed>,lines:list<array<string,mixed>>}|null
 */
function acc_journal_load_entry(PDO $pdo, int $id): ?array
{
    if ($id < 1 || !acc_journal_has_tables($pdo)) {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM acc_journal_entry WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $header = $st->fetch(PDO::FETCH_ASSOC);
    if (!$header) {
        return null;
    }

    $stL = $pdo->prepare(
        'SELECT l.id, l.account_id, l.debit, l.credit, l.memo, l.party_type, l.party_id,
                a.code AS account_code, a.name_ar AS account_name,
                c.name_ar AS customer_name, c.code AS customer_code,
                s.name_ar AS supplier_name, s.code AS supplier_code
         FROM acc_journal_line l
         INNER JOIN acc_account a ON a.id = l.account_id
         LEFT JOIN crm_customer c ON l.party_type = \'customer\' AND c.id = l.party_id
         LEFT JOIN crm_supplier s ON l.party_type = \'supplier\' AND s.id = l.party_id
         WHERE l.journal_id = ?
         ORDER BY l.id ASC'
    );
    $stL->execute([$id]);
    $rawLines = $stL->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $lines = [];
    foreach ($rawLines as $ln) {
        $partyType = (string) ($ln['party_type'] ?? '');
        $partyName = '';
        $partyCode = '';
        if ($partyType === 'customer') {
            $partyName = (string) ($ln['customer_name'] ?? '');
            $partyCode = (string) ($ln['customer_code'] ?? '');
        } elseif ($partyType === 'supplier') {
            $partyName = (string) ($ln['supplier_name'] ?? '');
            $partyCode = (string) ($ln['supplier_code'] ?? '');
        }
        $ln['party_name'] = $partyName;
        $ln['party_code'] = $partyCode;
        unset($ln['customer_name'], $ln['customer_code'], $ln['supplier_name'], $ln['supplier_code']);
        $lines[] = $ln;
    }

    return [
        'header' => $header,
        'lines' => $lines,
    ];
}

/**
 * @param list<array<string, mixed>> $lines
 * @return array{debit:float,credit:float,lines:list<array{account_id:int,debit:float,credit:float,memo:string}>}
 */
function acc_journal_normalize_lines(array $lines): array
{
    $out = [];
    $sumDebit = 0.0;
    $sumCredit = 0.0;

    foreach ($lines as $ln) {
        if (!is_array($ln)) {
            throw new RuntimeException('بيانات أسطر القيد غير صالحة.');
        }
        $accountId = (int) ($ln['account_id'] ?? 0);
        $debit = round(max(0, (float) ($ln['debit'] ?? 0)), 6);
        $credit = round(max(0, (float) ($ln['credit'] ?? 0)), 6);
        $memo = trim((string) ($ln['memo'] ?? ''));

        if ($accountId < 1) {
            continue;
        }
        if ($debit <= 0 && $credit <= 0) {
            continue;
        }
        if ($debit > 0 && $credit > 0) {
            throw new RuntimeException('كل سطر يجب أن يكون مديناً أو دائناً فقط.');
        }

        $sumDebit += $debit;
        $sumCredit += $credit;
        $row = [
            'account_id' => $accountId,
            'debit' => $debit,
            'credit' => $credit,
            'memo' => $memo,
        ];
        $partyType = strtolower(trim((string) ($ln['party_type'] ?? '')));
        $partyId = (int) ($ln['party_id'] ?? 0);
        if ($partyType !== '' && $partyId > 0) {
            $row['party_type'] = $partyType;
            $row['party_id'] = $partyId;
        }
        $out[] = $row;
    }

    if (count($out) < 2) {
        throw new RuntimeException('أضف سطرين على الأقل (مدين ودائن).');
    }

    if (abs($sumDebit - $sumCredit) >= 0.000001) {
        throw new RuntimeException('مجموع المدين يجب أن يساوي مجموع الدائن.');
    }

    return ['debit' => $sumDebit, 'credit' => $sumCredit, 'lines' => $out];
}

/**
 * @param list<array<string, mixed>> $lines
 */
function acc_journal_replace_lines(PDO $pdo, int $journalId, array $lines): void
{
    require_once app_path('includes/acc_journal_party.php');
    acc_journal_party_ledger_sync($pdo, $journalId, false);

    $pdo->prepare('DELETE FROM acc_journal_line WHERE journal_id = ?')->execute([$journalId]);
    $hasParty = acc_journal_party_has_columns($pdo);
    if ($hasParty) {
        $st = $pdo->prepare(
            'INSERT INTO acc_journal_line (journal_id, account_id, debit, credit, memo, party_type, party_id)
             VALUES (?,?,?,?,?,?,?)'
        );
    } else {
        $st = $pdo->prepare(
            'INSERT INTO acc_journal_line (journal_id, account_id, debit, credit, memo) VALUES (?,?,?,?,?)'
        );
    }
    foreach ($lines as $ln) {
        if ($hasParty) {
            $partyType = isset($ln['party_type']) && $ln['party_type'] !== '' && $ln['party_type'] !== null
                ? (string) $ln['party_type']
                : null;
            $partyId = isset($ln['party_id']) && (int) $ln['party_id'] > 0 ? (int) $ln['party_id'] : null;
            $st->execute([
                $journalId,
                (int) $ln['account_id'],
                (float) $ln['debit'],
                (float) $ln['credit'],
                ($ln['memo'] ?? '') !== '' ? (string) $ln['memo'] : null,
                $partyType,
                $partyId,
            ]);
        } else {
            $st->execute([
                $journalId,
                (int) $ln['account_id'],
                (float) $ln['debit'],
                (float) $ln['credit'],
                ($ln['memo'] ?? '') !== '' ? (string) $ln['memo'] : null,
            ]);
        }
    }
}

/**
 * @param list<array<string, mixed>> $lines
 */
function acc_journal_save(
    PDO $pdo,
    int $id,
    string $entryNo,
    string $entryDate,
    string $description,
    array $lines,
    bool $postNow
): int {
    if (!acc_journal_has_tables($pdo)) {
        throw new RuntimeException('جداول القيود غير موجودة.');
    }

    $normalized = acc_journal_normalize_lines($lines);
    require_once app_path('includes/acc_journal_party.php');
    $normalized['lines'] = acc_journal_party_normalize_lines($pdo, $normalized['lines']);
    $uid = (int) (current_user()['id'] ?? 0) ?: null;

    if ($id > 0) {
        acc_journal_assert_manual_voucher($pdo, $id);
        $st = $pdo->prepare('SELECT status FROM acc_journal_entry WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $cur = $st->fetch(PDO::FETCH_ASSOC);
        if (!$cur) {
            throw new RuntimeException('القيد غير موجود.');
        }
        if ((string) ($cur['status'] ?? '') !== 'draft') {
            throw new RuntimeException('لا يمكن تعديل قيد مرحّل أو ملغى.');
        }
    }

    if ($entryNo === '') {
        $entryNo = acc_journal_next_voucher_no($pdo, $entryDate);
    } else {
        if ($id > 0) {
            $chk = $pdo->prepare('SELECT id FROM acc_journal_entry WHERE entry_no = ? AND id <> ? LIMIT 1');
            $chk->execute([$entryNo, $id]);
        } else {
            $chk = $pdo->prepare('SELECT id FROM acc_journal_entry WHERE entry_no = ? LIMIT 1');
            $chk->execute([$entryNo]);
        }
        if ($chk->fetch()) {
            throw new RuntimeException('رقم القيد مستخدم مسبقاً.');
        }
    }

    $status = $postNow ? 'posted' : 'draft';

    if ($id > 0) {
        $pdo->prepare(
            'UPDATE acc_journal_entry SET entry_no = ?, entry_date = ?, description_ar = ?, status = ?, updated_by = ? WHERE id = ?'
        )->execute([
            $entryNo,
            $entryDate,
            $description !== '' ? $description : null,
            $status,
            $uid,
            $id,
        ]);
        acc_journal_replace_lines($pdo, $id, $normalized['lines']);
        if ($postNow) {
            acc_journal_party_ledger_sync($pdo, $id, true);
        }

        return $id;
    }

    $pdo->prepare(
        'INSERT INTO acc_journal_entry (entry_no, entry_date, description_ar, status, source, created_by) VALUES (?,?,?,?,?,?)'
    )->execute([
        $entryNo,
        $entryDate,
        $description !== '' ? $description : null,
        $status,
        'manual',
        $uid,
    ]);
    $newId = (int) $pdo->lastInsertId();
    acc_journal_replace_lines($pdo, $newId, $normalized['lines']);
    if ($postNow) {
        acc_journal_party_ledger_sync($pdo, $newId, true);
    }

    return $newId;
}

function acc_journal_post_by_id(PDO $pdo, int $id): void
{
    $loaded = acc_journal_load_entry($pdo, $id);
    if (!$loaded) {
        throw new RuntimeException('القيد غير موجود.');
    }
    if ((string) ($loaded['header']['status'] ?? '') !== 'draft') {
        throw new RuntimeException('يمكن ترحيل المسودات فقط.');
    }

    $lines = [];
    foreach ($loaded['lines'] as $ln) {
        $lines[] = [
            'account_id' => (int) ($ln['account_id'] ?? 0),
            'debit' => (float) ($ln['debit'] ?? 0),
            'credit' => (float) ($ln['credit'] ?? 0),
            'memo' => (string) ($ln['memo'] ?? ''),
        ];
    }
    acc_journal_normalize_lines($lines);

    $uid = (int) (current_user()['id'] ?? 0) ?: null;
    $pdo->prepare("UPDATE acc_journal_entry SET status = 'posted', updated_by = ? WHERE id = ?")->execute([$uid, $id]);

    require_once app_path('includes/acc_journal_party.php');
    acc_journal_party_ledger_sync($pdo, $id, true);

    require_once app_path('includes/sys_audit_log.php');
    sys_audit_log_acc_journal($pdo, 'post', $id);
}

/**
 * فك ترحيل قيد محاسبي يدوي (سند قيد). يعيد الحالة من «posted» إلى «draft»،
 * مما يُخفيه من التقارير المحاسبية (دفتر الأستاذ، ميزان المراجعة، …) فوراً
 * لأن استعلامات التقارير تشترط `status = 'posted'`.
 *
 * - يُسمح فقط بفك القيود اليدوية (source = 'manual')؛ القيود التلقائية النابعة
 *   من فواتير/سندات أخرى يجب فكّها من مصدرها (شاشة المستند الأصلي).
 */
function acc_journal_unpost_by_id(PDO $pdo, int $id): void
{
    $st = $pdo->prepare('SELECT status, source FROM acc_journal_entry WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('القيد غير موجود.');
    }
    if ((string) ($row['status'] ?? '') !== 'posted') {
        throw new RuntimeException('القيد غير مرحّل أصلاً.');
    }
    if ((string) ($row['source'] ?? '') !== 'manual') {
        throw new RuntimeException('هذا القيد تلقائي (من مستند آخر). افتح المستند الأصلي وافسخ ترحيله من هناك.');
    }
    $uid = (int) (current_user()['id'] ?? 0) ?: null;
    $pdo->prepare("UPDATE acc_journal_entry SET status = 'draft', updated_by = ? WHERE id = ?")->execute([$uid, $id]);

    require_once app_path('includes/acc_journal_party.php');
    acc_journal_party_ledger_sync($pdo, $id, false);

    require_once app_path('includes/sys_audit_log.php');
    sys_audit_log_acc_journal($pdo, 'unpost', $id);
}

function acc_journal_was_ever_posted(PDO $pdo, int $id): bool
{
    if ($id < 1) {
        return false;
    }

    $st = $pdo->prepare('SELECT status FROM acc_journal_entry WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $status = (string) ($st->fetchColumn() ?: '');
    if ($status === 'posted' || $status === 'cancelled') {
        return true;
    }

    require_once app_path('includes/sys_audit_log.php');
    if (!sys_audit_log_table_exists($pdo)) {
        return false;
    }

    $audit = $pdo->prepare(
        "SELECT 1 FROM sys_audit_log
         WHERE screen_code = 'journal_voucher' AND entity_id = ? AND action_code = 'post'
         LIMIT 1"
    );
    $audit->execute([$id]);

    return (bool) $audit->fetchColumn();
}

function acc_journal_delete_draft(PDO $pdo, int $id): void
{
    $st = $pdo->prepare('SELECT status, entry_no, entry_date FROM acc_journal_entry WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('القيد غير موجود.');
    }
    $status = (string) ($row['status'] ?? '');
    if ($status === 'cancelled') {
        throw new RuntimeException('لا يمكن حذف قيد ملغى. يبقى في السجل للحفاظ على التسلسل.');
    }
    if ($status !== 'draft') {
        throw new RuntimeException('لا يمكن حذف قيد مرحّل. استخدم «إلغاء السند» للحفاظ على رقم التسلسل.');
    }
    if (acc_journal_was_ever_posted($pdo, $id)) {
        throw new RuntimeException('لا يمكن حذف سند كان مرحّلاً مسبقاً. عدّل الحركات ثم احفظ وأعد الترحيل.');
    }
    $entryNo = trim((string) ($row['entry_no'] ?? ''));
    $entryDate = (string) ($row['entry_date'] ?? date('Y-m-d'));
    $pdo->prepare('DELETE FROM acc_journal_entry WHERE id = ?')->execute([$id]);
    if ($entryNo !== '') {
        require_once app_path('includes/doc_number_pool.php');
        doc_number_pool_release($pdo, doc_number_pool_key_journal(), $entryNo, $entryDate);
    }
}

function acc_journal_cancel_by_id(PDO $pdo, int $id): void
{
    $st = $pdo->prepare('SELECT status, source, entry_no FROM acc_journal_entry WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('القيد غير موجود.');
    }
    $status = (string) ($row['status'] ?? '');
    if ($status === 'cancelled') {
        throw new RuntimeException('القيد ملغى مسبقاً.');
    }
    if ($status !== 'posted') {
        throw new RuntimeException('يمكن إلغاء القيود المرحّلة فقط. للمسودات استخدم الحذف.');
    }
    if ((string) ($row['source'] ?? '') !== 'manual') {
        throw new RuntimeException('هذا القيد تلقائي (من مستند آخر). ألغِ المستند الأصلي.');
    }

    require_once app_path('includes/acc_journal_party.php');
    acc_journal_party_ledger_sync($pdo, $id, false);
    $uid = (int) (current_user()['id'] ?? 0) ?: null;
    $pdo->prepare("UPDATE acc_journal_entry SET status = 'cancelled', updated_by = ? WHERE id = ?")->execute([$uid, $id]);

    require_once app_path('includes/sys_audit_log.php');
    sys_audit_log_acc_journal($pdo, 'cancel', $id);
}

function acc_journal_next_voucher_no(PDO $pdo, string $entryDate): string
{
    require_once app_path('includes/doc_sequence.php');
    require_once app_path('includes/doc_number_pool.php');

    return doc_seq_generate_next_no(
        $pdo,
        'acc_journal_entry',
        'entry_no',
        $entryDate,
        '',
        [],
        doc_number_pool_key_journal()
    );
}

function acc_journal_id_by_no(PDO $pdo, string $entryNo): ?int
{
    $entryNo = trim($entryNo);
    if ($entryNo === '') {
        return null;
    }
    $st = $pdo->prepare('SELECT id FROM acc_journal_entry WHERE entry_no = ? LIMIT 1');
    $st->execute([$entryNo]);
    $id = $st->fetchColumn();

    return $id !== false ? (int) $id : null;
}

/** @return list<int> */
function acc_journal_search_ids_by_no_fragment(PDO $pdo, string $fragment, int $limit = 200): array
{
    require_once app_path('includes/doc_no_fragment_search.php');
    $fragment = trim($fragment);
    if ($fragment === '') {
        return [];
    }

    $limit = max(1, min(500, $limit));

    return doc_no_search_ids_like(
        $pdo,
        "SELECT id FROM acc_journal_entry
         WHERE entry_no LIKE ?
         ORDER BY entry_no ASC, id ASC
         LIMIT {$limit}",
        [doc_no_sql_like_pattern($fragment)]
    );
}

/** @return array<string, mixed>|null */
function acc_journal_fetch_by_no(PDO $pdo, string $entryNo): ?array
{
    require_once app_path('includes/doc_no_fragment_search.php');

    return doc_no_fetch_exact_or_fragment(
        $pdo,
        $entryNo,
        'SELECT id FROM acc_journal_entry WHERE entry_no = ? LIMIT 1',
        [trim($entryNo)],
        static fn (string $frag) => acc_journal_search_ids_by_no_fragment($pdo, $frag),
        static fn (int $id) => acc_journal_api_entry($pdo, $id)
    );
}

function acc_journal_first_id(PDO $pdo): ?int
{
    $id = $pdo->query('SELECT id FROM acc_journal_entry ORDER BY id ASC LIMIT 1')->fetchColumn();

    return $id !== false ? (int) $id : null;
}

function acc_journal_neighbor_id(PDO $pdo, int $id, string $direction): ?int
{
    if ($id < 1) {
        return null;
    }
    $direction = $direction === 'prev' ? 'prev' : 'next';
    if ($direction === 'prev') {
        $st = $pdo->prepare('SELECT id FROM acc_journal_entry WHERE id < ? ORDER BY id DESC LIMIT 1');
    } else {
        $st = $pdo->prepare('SELECT id FROM acc_journal_entry WHERE id > ? ORDER BY id ASC LIMIT 1');
    }
    $st->execute([$id]);
    $nid = $st->fetchColumn();

    return $nid !== false ? (int) $nid : null;
}

/**
 * @return array<string, mixed>|null
 */
function acc_journal_api_entry(PDO $pdo, int $id): ?array
{
    $loaded = acc_journal_load_entry($pdo, $id);
    if (!$loaded) {
        return null;
    }
    $header = $loaded['header'];
    $status = (string) ($header['status'] ?? 'draft');
    $source = acc_journal_entry_source($pdo, $id);
    $isManual = $source === 'manual';
    $refType = trim((string) ($header['ref_type'] ?? ''));
    $refId = (int) ($header['ref_id'] ?? 0);
    $refUrl = null;
    $refLabel = '';
    if (!$isManual && $refType !== '' && $refId > 0) {
        require_once app_path('includes/acc_report_ref.php');
        $refUrl = acc_report_ref_url($refType, $refId);
        $refLabel = acc_report_ref_type_label($refType);
    }
    $linesOut = [];
    foreach ($loaded['lines'] as $ln) {
        $linesOut[] = [
            'id' => (int) ($ln['id'] ?? 0),
            'account_id' => (int) ($ln['account_id'] ?? 0),
            'account_code' => (string) ($ln['account_code'] ?? ''),
            'account_name' => (string) ($ln['account_name'] ?? ''),
            'debit' => (float) ($ln['debit'] ?? 0),
            'credit' => (float) ($ln['credit'] ?? 0),
            'memo' => (string) ($ln['memo'] ?? ''),
            'party_type' => (string) ($ln['party_type'] ?? ''),
            'party_id' => (int) ($ln['party_id'] ?? 0),
            'party_name' => (string) ($ln['party_name'] ?? ''),
            'party_code' => (string) ($ln['party_code'] ?? ''),
        ];
    }

    return [
        'id' => (int) $header['id'],
        'entry_no' => (string) $header['entry_no'],
        'entry_date' => (string) $header['entry_date'],
        'entry_date_dmy' => format_date_dmY((string) $header['entry_date']),
        'description_ar' => (string) ($header['description_ar'] ?? ''),
        'status' => $status,
        'status_label' => acc_journal_status_label($status),
        'source' => $source,
        'is_manual' => $isManual,
        'is_editable' => $isManual && $status === 'draft',
        'can_edit_unlock' => $isManual && $status === 'posted',
        'is_cancelled' => $status === 'cancelled',
        'no_delete' => acc_journal_was_ever_posted($pdo, $id) || $status === 'cancelled',
        'ref_type' => $refType,
        'ref_id' => $refId,
        'ref_url' => $refUrl,
        'ref_label' => $refLabel,
        'lines' => $linesOut,
        'prev_id' => $isManual ? (acc_journal_voucher_neighbor_id($pdo, $id, 'prev') ?? 0) : 0,
        'next_id' => $isManual ? (acc_journal_voucher_neighbor_id($pdo, $id, 'next') ?? 0) : 0,
    ];
}

/**
 * FROM/JOIN مشترك لقائمة القيود (مع حسابات الأسطر).
 */
function acc_journal_list_from_sql(): string
{
    return ' FROM acc_journal_entry e
             LEFT JOIN acc_journal_line l ON l.journal_id = e.id
             LEFT JOIN acc_account a ON a.id = l.account_id
             LEFT JOIN sys_user u_creator ON u_creator.id = e.created_by
             LEFT JOIN sys_user u_updater ON u_updater.id = e.updated_by';
}

/** @return list<string> */
function acc_journal_list_sort_columns(): array
{
    return ['entry_no', 'entry_date', 'created_at', 'description_ar', 'status'];
}

function acc_journal_list_normalize_sort(string $sort): string
{
    return in_array($sort, acc_journal_list_sort_columns(), true) ? $sort : 'created_at';
}

function acc_journal_list_normalize_dir(string $dir): string
{
    return strtolower($dir) === 'asc' ? 'asc' : 'desc';
}

function acc_journal_list_order_clause(string $sort, string $dir): string
{
    $sort = acc_journal_list_normalize_sort($sort);
    $dir = acc_journal_list_normalize_dir($dir) === 'asc' ? 'ASC' : 'DESC';

    $columns = [
        'entry_no' => 'e.entry_no',
        'entry_date' => 'e.entry_date',
        'created_at' => 'e.created_at',
        'description_ar' => 'e.description_ar',
        'status' => 'e.status',
    ];

    return ' ORDER BY ' . $columns[$sort] . ' ' . $dir . ', e.id DESC';
}

/**
 * @param array<string, scalar|null> $query
 */
function acc_journal_list_sort_url(string $route, array $query, string $column, string $currentSort, string $currentDir): string
{
    $query['sort'] = $column;
    $query['dir'] = ($currentSort === $column && acc_journal_list_normalize_dir($currentDir) === 'asc') ? 'desc' : 'asc';
    unset($query['page']);

    return list_pager_base_url($route, $query);
}

function acc_journal_list_sort_th_html(
    string $label,
    string $column,
    string $route,
    array $query,
    string $currentSort,
    string $currentDir
): string {
    $href = acc_journal_list_sort_url($route, $query, $column, $currentSort, $currentDir);
    $class = 'je-ora-sort-th';
    if ($currentSort === $column) {
        $class .= acc_journal_list_normalize_dir($currentDir) === 'asc' ? ' is-sort-asc' : ' is-sort-desc';
    }

    return '<a class="' . esc($class) . '" href="' . esc($href) . '" title="ترتيب تصاعدي / تنازلي">'
        . esc($label) . '</a>';
}

/**
 * بحث قائمة القيود: رقم القيد، البيان، التاريخ، اسم/رمز الحساب، مبلغ السطر، ملاحظة السطر.
 *
 * @return array{where:string, params:list<mixed>}
 */
function acc_journal_list_search_clause(string $q): array
{
    $q = trim($q);
    if ($q === '') {
        return ['where' => '', 'params' => []];
    }

    $dateIso = parse_date_to_iso($q);
    $amount = null;
    $numNorm = str_replace([',', '٬', ' '], ['', '', ''], $q);
    if (preg_match('/^\d+(\.\d+)?$/u', $numNorm)) {
        $amount = round((float) $numNorm, 6);
    }

    $like = '%' . $q . '%';
    $parts = [];
    $params = [];

    $parts[] = 'e.entry_no LIKE ?';
    $params[] = $like;
    $parts[] = 'e.description_ar LIKE ?';
    $params[] = $like;
    $parts[] = 'a.name_ar LIKE ?';
    $params[] = $like;
    $parts[] = 'a.code LIKE ?';
    $params[] = $like;
    $parts[] = 'l.memo LIKE ?';
    $params[] = $like;
    $parts[] = "DATE_FORMAT(e.entry_date, '%d-%m-%Y') LIKE ?";
    $params[] = $like;
    $parts[] = "DATE_FORMAT(e.entry_date, '%d/%m/%Y') LIKE ?";
    $params[] = $like;
    $parts[] = "DATE_FORMAT(e.entry_date, '%Y-%m-%d') LIKE ?";
    $params[] = $like;

    if ($dateIso !== null && $dateIso !== '') {
        $parts[] = 'e.entry_date = ?';
        $params[] = $dateIso;
    }
    if ($amount !== null && $amount > 0) {
        $parts[] = '(l.debit = ? OR l.credit = ?)';
        $params[] = $amount;
        $params[] = $amount;
    }

    return [
        'where' => ' AND (' . implode(' OR ', $parts) . ')',
        'params' => $params,
    ];
}

/**
 * فلتر تاريخ قائمة القيود (على entry_date).
 *
 * @return array{where:string, params:list<mixed>, from:?string, to:?string}
 */
function acc_journal_list_date_clause(?string $dateFrom, ?string $dateTo): array
{
    $from = $dateFrom !== null && $dateFrom !== '' ? parse_date_to_iso(trim($dateFrom)) : null;
    $to = $dateTo !== null && $dateTo !== '' ? parse_date_to_iso(trim($dateTo)) : null;

    if ($from !== null && $to !== null && $from > $to) {
        [$from, $to] = [$to, $from];
    }

    $where = '';
    $params = [];
    if ($from !== null) {
        $where .= ' AND e.entry_date >= ?';
        $params[] = $from;
    }
    if ($to !== null) {
        $where .= ' AND e.entry_date <= ?';
        $params[] = $to;
    }

    return [
        'where' => $where,
        'params' => $params,
        'from' => $from,
        'to' => $to,
    ];
}
