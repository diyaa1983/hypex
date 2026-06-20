<?php
declare(strict_types=1);

require_once app_path('includes/fin_voucher_checks.php');
require_once app_path('includes/fin_voucher_checks_report.php');
require_once app_path('includes/fin_voucher_schema.php');

function fin_checks_manage_has_lifecycle(PDO $pdo): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $st = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fin_voucher_check' AND COLUMN_NAME = 'lifecycle_status'"
        );
        $cached = ((int) $st->fetchColumn()) > 0;
    } catch (Throwable $e) {
        $cached = false;
    }

    return $cached;
}

function fin_checks_manage_ensure_schema(PDO $pdo): bool
{
    if (!fin_voucher_checks_ensure_table($pdo)) {
        return false;
    }
    if (fin_checks_manage_has_lifecycle($pdo)) {
        return true;
    }
    require_once app_path('includes/sql_migration.php');
    sql_migration_run_file($pdo, 'database/migrations/162_fin_checks_manage.sql');

    return fin_checks_manage_has_lifecycle($pdo);
}

/**
 * @return array{
 *   direction:string,
 *   check_no:string,
 *   status:string,
 *   date_field:string,
 *   from:string,
 *   to:string,
 *   from:string,
 *   to:string,
 *   overdue_only:bool,
 *   date_range_active:bool
 * }
 */
function fin_checks_manage_parse_filters(array $input): array
{
    $direction = (string) ($input['direction'] ?? 'all');
    if (!in_array($direction, ['all', 'incoming', 'outgoing'], true)) {
        $direction = 'all';
    }
    $status = (string) ($input['status'] ?? 'all');
    if (!in_array($status, ['all', 'pending', 'cleared', 'returned'], true)) {
        $status = 'all';
    }
    $dateField = (string) ($input['date_field'] ?? 'voucher');
    if (!in_array($dateField, ['voucher', 'due', 'cleared', 'returned'], true)) {
        $dateField = 'voucher';
    }

    $from = trim((string) ($input['from'] ?? ''));
    $to = trim((string) ($input['to'] ?? ''));

    return [
        'direction' => $direction,
        'check_no' => trim((string) ($input['check_no'] ?? '')),
        'status' => $status,
        'date_field' => $dateField,
        'from' => $from,
        'to' => $to,
        'overdue_only' => !empty($input['overdue_only']),
        'date_range_active' => $from !== '' && $to !== '',
    ];
}

function fin_checks_manage_due_meta(string $dueDate, ?string $today = null): array
{
    $today = $today ?? date('Y-m-d');
    $due = trim($dueDate);
    if ($due === '') {
        return [
            'days_until_due' => null,
            'is_overdue' => false,
            'urgency' => 'nodate',
            'urgency_label' => 'بدون تاريخ استحقاق',
        ];
    }
    try {
        $dueDt = new DateTimeImmutable($due);
        $todayDt = new DateTimeImmutable($today);
        $daysUntil = (int) $todayDt->diff($dueDt)->format('%r%a');
        if ($daysUntil < 0) {
            return [
                'days_until_due' => $daysUntil,
                'is_overdue' => true,
                'urgency' => 'overdue',
                'urgency_label' => 'متأخر',
            ];
        }
        if ($daysUntil === 0) {
            return [
                'days_until_due' => 0,
                'is_overdue' => false,
                'urgency' => 'today',
                'urgency_label' => 'مستحق اليوم',
            ];
        }
        if ($daysUntil <= 7) {
            return [
                'days_until_due' => $daysUntil,
                'is_overdue' => false,
                'urgency' => 'soon',
                'urgency_label' => 'قريب الاستحقاق',
            ];
        }

        return [
            'days_until_due' => $daysUntil,
            'is_overdue' => false,
            'urgency' => 'pending',
            'urgency_label' => 'قيد المتابعة',
        ];
    } catch (Throwable $e) {
        return [
            'days_until_due' => null,
            'is_overdue' => false,
            'urgency' => 'nodate',
            'urgency_label' => 'تاريخ غير صالح',
        ];
    }
}

function fin_checks_manage_status_label(string $status): string
{
    return match ($status) {
        'cleared' => 'صرف',
        'returned' => 'إرجاع',
        default => '—',
    };
}

/** @return array{post:string, action:string, full:string, badge_class:string} */
function fin_checks_manage_post_display(string $lifecycle): array
{
    if ($lifecycle === 'cleared') {
        return [
            'post' => 'تم الترحيل',
            'action' => 'صرف',
            'full' => 'تم الترحيل — صرف',
            'badge_class' => 'fin-chk-badge fin-chk-badge--posted-clear',
        ];
    }
    if ($lifecycle === 'returned') {
        return [
            'post' => 'تم الترحيل',
            'action' => 'إرجاع',
            'full' => 'تم الترحيل — إرجاع',
            'badge_class' => 'fin-chk-badge fin-chk-badge--posted-return',
        ];
    }

    return [
        'post' => 'لم يُرحَّل',
        'action' => '—',
        'full' => 'لم يُرحَّل',
        'badge_class' => 'fin-chk-badge fin-chk-badge--pending',
    ];
}

/**
 * حسابات إيداع/تحصيل الشيك — كل الحسابات النهائية تحت مجموعة الصندوق/الصناديق وتحت مجموعة البنوك.
 *
 * @return list<array{id:int, code:string, name_ar:string, group_key:string, group_label:string, sort_order:int}>
 */
function fin_checks_manage_load_deposit_accounts(PDO $pdo): array
{
    require_once app_path('includes/acc_journal.php');
    if (!acc_journal_has_tables($pdo)) {
        return [];
    }

    require_once app_path('includes/acc_gl.php');
    require_once app_path('includes/acc_coa_bootstrap.php');
    require_once app_path('includes/acc_account_tree.php');

    $settings = acc_gl_is_ready($pdo) ? acc_gl_load_settings($pdo) : [];
    $forceIds = [];
    foreach (['cash', 'bank'] as $rule) {
        $aid = (int) ($settings[$rule]['account_id'] ?? 0);
        if ($aid > 0) {
            $forceIds[$aid] = true;
        }
    }
    $depositBankId = acc_gl_receipt_bank_deposit_account_id($pdo);
    if ($depositBankId > 0) {
        $forceIds[$depositBankId] = true;
    }
    $cashBoxId = acc_gl_cash_box_account_id($pdo);
    if ($cashBoxId > 0) {
        $forceIds[$cashBoxId] = true;
    }

    $checksFundId = acc_gl_checks_fund_account_id($pdo);

    $rows = $pdo->query(
        "SELECT a.id, a.code, a.name_ar, a.parent_id, p.name_ar AS parent_name_ar
         FROM acc_account a
         LEFT JOIN acc_account p ON p.id = a.parent_id
         WHERE a.is_active = 1 AND a.is_leaf = 1
         ORDER BY a.code ASC, a.id ASC"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $byId = [];
    foreach (
        $pdo->query('SELECT id, parent_id FROM acc_account WHERE is_active = 1')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r
    ) {
        $aid = (int) ($r['id'] ?? 0);
        if ($aid > 0) {
            $byId[$aid] = [
                'parent_id' => ($r['parent_id'] ?? null) !== null ? (int) $r['parent_id'] : null,
            ];
        }
    }

    $cashGroupIds = acc_coa_find_cash_group_parent_ids($pdo);
    $banksGroupIds = acc_coa_find_banks_group_parent_ids($pdo);
    $liquidityParentId = acc_coa_find_liquidity_parent_id($pdo);

    $isUnder = static function (int $accountId, ?int $ancestorId) use (&$byId): bool {
        if ($accountId < 1 || $ancestorId === null || $ancestorId < 1) {
            return false;
        }
        $cur = $accountId;
        $guard = 0;
        while ($cur > 0 && $guard < 2000) {
            if ($cur === $ancestorId) {
                return true;
            }
            $parent = $byId[$cur]['parent_id'] ?? null;
            $cur = $parent !== null ? (int) $parent : 0;
            $guard++;
        }

        return false;
    };

    $isUnderAny = static function (int $accountId, array $ancestorIds) use ($isUnder): bool {
        foreach ($ancestorIds as $ancestorId) {
            if ($isUnder($accountId, (int) $ancestorId)) {
                return true;
            }
        }

        return false;
    };

    $resolveSubtree = static function (int $accountId, string $code, string $name, string $parentName) use (
        $isUnderAny,
        $cashGroupIds,
        $banksGroupIds,
        $liquidityParentId,
        $isUnder
    ): ?string {
        if ($isUnderAny($accountId, $banksGroupIds)) {
            return 'bank';
        }
        if ($isUnderAny($accountId, $cashGroupIds)) {
            return 'cash';
        }

        $digits = acc_account_code_digits($code);
        if ($digits !== '' && str_starts_with($digits, '1001003')) {
            return 'bank';
        }
        if ($digits !== '' && str_starts_with($digits, '1001002')) {
            return 'cash';
        }

        $legacyCash = in_array($digits, ['111', '1001001001', '1001002001'], true);
        $legacyBank = in_array($digits, ['112', '1001003001', '1001003004'], true);
        if ($legacyBank) {
            return 'bank';
        }
        if ($legacyCash) {
            return 'cash';
        }

        $hay = $name . ' ' . $parentName;
        if ($liquidityParentId !== null && $isUnder($accountId, $liquidityParentId)) {
            if (preg_match('/بنك|bank|مصرف/u', $hay)) {
                return 'bank';
            }
            if (preg_match('/صندوق|نقد|cash|خزينة/u', $hay) && !preg_match('/شيك/u', $hay)) {
                return 'cash';
            }
        }

        return null;
    };

    $out = [];
    $seen = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id < 1 || isset($seen[$id]) || $id === $checksFundId) {
            continue;
        }

        $name = (string) ($row['name_ar'] ?? '');
        $parentName = (string) ($row['parent_name_ar'] ?? '');
        $code = (string) ($row['code'] ?? '');

        if (preg_match('/شريك|جاري|حصة/u', $name . ' ' . $parentName)) {
            continue;
        }
        if (preg_match('/صندوق\s*الشيكات|شيكات\s*تحت/u', $name)) {
            continue;
        }

        $subtree = $resolveSubtree($id, $code, $name, $parentName);
        if ($subtree === null && !isset($forceIds[$id])) {
            continue;
        }
        if ($subtree === null) {
            $subtree = preg_match('/بنك|bank|مصرف/u', $name . ' ' . $parentName) ? 'bank' : 'cash';
        }

        $seen[$id] = true;
        $out[] = [
            'id' => $id,
            'code' => $code,
            'name_ar' => $name,
            'group_key' => $subtree,
            'group_label' => $subtree === 'bank' ? 'البنوك' : 'الصناديق',
            'sort_order' => $subtree === 'bank' ? 20 : 10,
        ];
    }

    usort($out, static function (array $a, array $b): int {
        $sa = (int) ($a['sort_order'] ?? 0);
        $sb = (int) ($b['sort_order'] ?? 0);
        if ($sa !== $sb) {
            return $sa <=> $sb;
        }
        $ca = (string) ($a['code'] ?? '');
        $cb = (string) ($b['code'] ?? '');
        if ($ca !== $cb) {
            return strcmp($ca, $cb);
        }

        return (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0);
    });

    return $out;
}

/**
 * @return array<string, string>
 */
function fin_checks_manage_row_data_attrs(array $row): array
{
    $vd = trim((string) ($row['voucher_date'] ?? ''));
    $dd = trim((string) ($row['due_date'] ?? ''));

    return [
        'data-check-id' => (string) (int) ($row['check_id'] ?? 0),
        'data-check-no' => (string) ($row['check_no'] ?? ''),
        'data-check-amount' => format_money((float) ($row['check_amount'] ?? 0)),
        'data-bank-name' => (string) ($row['bank_name'] ?? ''),
        'data-party-name' => (string) ($row['party_name'] ?? ''),
        'data-voucher-no' => (string) ($row['voucher_no'] ?? ''),
        'data-voucher-date' => $vd !== '' ? format_date_dmY($vd) : '—',
        'data-due-date' => $dd !== '' ? format_date_dmY($dd) : '—',
        'data-direction' => (string) ($row['direction'] ?? ''),
    ];
}

/**
 * مزامنة الشيكات القديمة: تحصيل يدوي سابق (FIFO) أو قيود fin_check_* أو سند صرف مرحّل.
 */
function fin_checks_manage_sync_legacy_status(PDO $pdo): void
{
    static $done = false;
    if ($done || !fin_checks_manage_has_lifecycle($pdo)) {
        return;
    }
    $done = true;

    require_once app_path('includes/acc_gl.php');

    try {
        $st = $pdo->query(
            "SELECT c.id AS check_id, e.ref_type, e.entry_date, e.id AS journal_id,
                    (SELECT l.account_id FROM acc_journal_line l
                     WHERE l.journal_id = e.id AND l.debit > 0.000001 LIMIT 1) AS debit_account_id
             FROM fin_voucher_check c
             INNER JOIN acc_journal_entry e ON e.ref_id = c.id
                AND e.ref_type IN ('fin_check_clear', 'fin_check_return')
                AND e.status = 'posted'
             WHERE c.lifecycle_status = 'pending'"
        );
        $updClear = $pdo->prepare(
            "UPDATE fin_voucher_check
             SET lifecycle_status = 'cleared', action_date = ?, action_account_id = ?, action_journal_id = ?
             WHERE id = ? AND lifecycle_status = 'pending'"
        );
        $updReturn = $pdo->prepare(
            "UPDATE fin_voucher_check
             SET lifecycle_status = 'returned', action_date = ?, action_journal_id = ?,
                 return_reason = COALESCE(NULLIF(return_reason, ''), 'إرجاع — قيد سابق')
             WHERE id = ? AND lifecycle_status = 'pending'"
        );
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $cid = (int) ($row['check_id'] ?? 0);
            if ($cid < 1) {
                continue;
            }
            $entryDate = (string) ($row['entry_date'] ?? '');
            $journalId = (int) ($row['journal_id'] ?? 0);
            if ((string) ($row['ref_type'] ?? '') === 'fin_check_clear') {
                $updClear->execute([
                    $entryDate !== '' ? $entryDate : null,
                    (int) ($row['debit_account_id'] ?? 0) ?: null,
                    $journalId > 0 ? $journalId : null,
                    $cid,
                ]);
            } else {
                $updReturn->execute([
                    $entryDate !== '' ? $entryDate : null,
                    $journalId > 0 ? $journalId : null,
                    $cid,
                ]);
            }
        }
    } catch (Throwable $e) {
        // continue with other sync paths
    }

    // إرجاع مسجّل على ذمة العميل/المورد
    try {
        require_once app_path('includes/crm_customer_ledger.php');
        require_once app_path('includes/crm_supplier_ledger.php');
        $returnIds = [];
        if (crm_ledger_has_table($pdo)) {
            crm_ledger_ensure_journal_voucher_txn($pdo);
            fin_checks_manage_ensure_customer_check_return_txn($pdo);
            $st = $pdo->query(
                "SELECT ref_id FROM crm_customer_ledger WHERE txn_type = 'check_return'"
            );
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $rid) {
                $returnIds[(int) $rid] = true;
            }
        }
        if (crm_supplier_ledger_has_table($pdo)) {
            fin_checks_manage_ensure_supplier_check_return_txn($pdo);
            $st = $pdo->query(
                "SELECT ref_id FROM crm_supplier_ledger WHERE txn_type = 'check_return'"
            );
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $rid) {
                $returnIds[(int) $rid] = true;
            }
        }
        if ($returnIds !== []) {
            $upd = $pdo->prepare(
                "UPDATE fin_voucher_check
                 SET lifecycle_status = 'returned',
                     return_reason = COALESCE(NULLIF(return_reason, ''), 'إرجاع — سجل سابق')
                 WHERE id = ? AND lifecycle_status = 'pending'"
            );
            foreach (array_keys($returnIds) as $cid) {
                if ($cid > 0) {
                    $upd->execute([$cid]);
                }
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    // شيكات واردة مُحصّلة سابقاً (FIFO — خارج صندوق الشيكات)
    try {
        $pendingInFund = fin_voucher_checks_pending_collection($pdo);
        $stillPending = [];
        foreach ($pendingInFund as $p) {
            $stillPending[(int) ($p['check_id'] ?? 0)] = true;
        }

        $hasPostedCol = fin_voucher_has_column($pdo, 'is_posted');
        $postedFilter = $hasPostedCol ? ' AND v.is_posted = 1 ' : '';
        $st = $pdo->query(
            "SELECT c.id, COALESCE(c.due_date, v.voucher_date) AS action_guess
             FROM fin_voucher_check c
             INNER JOIN fin_voucher v ON v.id = c.voucher_id AND v.voucher_type = 'receipt'
             WHERE c.lifecycle_status = 'pending' {$postedFilter}"
        );
        $updLegacyClear = $pdo->prepare(
            "UPDATE fin_voucher_check
             SET lifecycle_status = 'cleared',
                 action_date = COALESCE(action_date, ?),
                 return_reason = NULL
             WHERE id = ? AND lifecycle_status = 'pending'"
        );
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $cid = (int) ($row['id'] ?? 0);
            if ($cid < 1 || isset($stillPending[$cid])) {
                continue;
            }
            $updLegacyClear->execute([
                (string) ($row['action_guess'] ?? '') ?: null,
                $cid,
            ]);
        }
    } catch (Throwable $e) {
        // ignore
    }

    // شيكات صادرة — سند الصرف مرحّل = مُصروف محاسبياً
    try {
        if (fin_voucher_has_column($pdo, 'is_posted')) {
            $pdo->exec(
                "UPDATE fin_voucher_check c
                 INNER JOIN fin_voucher v ON v.id = c.voucher_id AND v.voucher_type = 'payment'
                 SET c.lifecycle_status = 'cleared',
                     c.action_date = COALESCE(c.action_date, v.voucher_date),
                     c.action_account_id = COALESCE(c.action_account_id, v.cash_account_id)
                 WHERE c.lifecycle_status = 'pending' AND v.is_posted = 1"
            );
        }
    } catch (Throwable $e) {
        // ignore
    }

    // ترحيل شيكات رأس السند إلى fin_voucher_check إن وُجدت
    try {
        if (fin_voucher_has_column($pdo, 'pay_method')) {
            $pdo->exec(
                "INSERT INTO fin_voucher_check (voucher_id, sort_order, check_no, bank_name, check_amount, due_date, lifecycle_status)
                 SELECT v.id, 0, NULLIF(TRIM(v.check_no), ''), NULLIF(TRIM(v.bank_name), ''), v.check_amount, NULL, 'pending'
                 FROM fin_voucher v
                 WHERE v.pay_method = 'check'
                   AND v.check_amount > 0.000001
                   AND NOT EXISTS (SELECT 1 FROM fin_voucher_check c WHERE c.voucher_id = v.id)"
            );
        }
    } catch (Throwable $e) {
        // ignore
    }
}

function fin_checks_manage_direction_label(string $voucherType): string
{
    return $voucherType === 'payment' ? 'صادر' : 'وارد';
}

/**
 * @param array<string, mixed> $filters
 * @return list<array<string, mixed>>
 */
function fin_checks_manage_fetch(PDO $pdo, array $filters, ?string $today = null): array
{
    if (!fin_checks_manage_ensure_schema($pdo) || !fin_voucher_has_table($pdo)) {
        return [];
    }

    fin_checks_manage_sync_legacy_status($pdo);

    $today = $today ?? date('Y-m-d');
    $fromIso = parse_date_to_iso($filters['from'] ?? '');
    $toIso = parse_date_to_iso($filters['to'] ?? '');
    $dateRangeActive = !empty($filters['date_range_active']);
    if ($dateRangeActive && ($fromIso === null || $toIso === null || $fromIso > $toIso)) {
        return [];
    }

    $hasPostedCol = fin_voucher_has_column($pdo, 'is_posted');
    $postedExpr = $hasPostedCol ? 'v.is_posted' : '0';

    $dateCol = match ($filters['date_field'] ?? 'voucher') {
        'due' => 'c.due_date',
        'cleared' => "CASE WHEN c.lifecycle_status = 'cleared' THEN c.action_date ELSE NULL END",
        'returned' => "CASE WHEN c.lifecycle_status = 'returned' THEN c.action_date ELSE NULL END",
        default => 'v.voucher_date',
    };

    $sql =
        "SELECT c.id AS check_id, c.check_no, c.bank_name, c.check_amount, c.due_date, c.notes,
                c.lifecycle_status, c.action_date, c.return_reason, c.action_account_id, c.action_journal_id,
                v.id AS voucher_id, v.voucher_no, v.voucher_date, v.voucher_type, v.party_id, v.party_type,
                v.cash_account_id,
                ({$postedExpr}) AS is_posted,
                COALESCE(cust.name_ar, sup.name_ar, '—') AS party_name,
                COALESCE(acc.name_ar, '') AS action_account_name
         FROM fin_voucher_check c
         INNER JOIN fin_voucher v ON v.id = c.voucher_id
         LEFT JOIN crm_customer cust ON v.party_type = 'customer' AND cust.id = v.party_id
         LEFT JOIN crm_supplier sup ON v.party_type = 'supplier' AND sup.id = v.party_id
         LEFT JOIN acc_account acc ON acc.id = c.action_account_id
         WHERE c.check_amount > 0.000001";

    $params = [];

    if ($dateRangeActive) {
        $sql .= " AND {$dateCol} IS NOT NULL AND {$dateCol} BETWEEN ? AND ?";
        $params[] = $fromIso;
        $params[] = $toIso;
    }

    $direction = (string) ($filters['direction'] ?? 'all');
    if ($direction === 'incoming') {
        $sql .= " AND v.voucher_type = 'receipt'";
    } elseif ($direction === 'outgoing') {
        $sql .= " AND v.voucher_type = 'payment'";
    }

    $status = (string) ($filters['status'] ?? 'all');
    if ($status !== 'all') {
        $sql .= ' AND c.lifecycle_status = ?';
        $params[] = $status;
    }

    $checkNo = trim((string) ($filters['check_no'] ?? ''));
    if ($checkNo !== '') {
        $sql .= ' AND c.check_no LIKE ?';
        $params[] = '%' . $checkNo . '%';
    }

    $sql .= ' ORDER BY c.due_date ASC, v.voucher_date ASC, c.id ASC';

    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $lifecycle = (string) ($row['lifecycle_status'] ?? 'pending');
        $dueMeta = fin_checks_manage_due_meta((string) ($row['due_date'] ?? ''), $today);
        if (!empty($filters['overdue_only']) && empty($dueMeta['is_overdue'])) {
            continue;
        }

        $voucherType = (string) ($row['voucher_type'] ?? '');
        $vid = (int) ($row['voucher_id'] ?? 0);
        $voucherRoute = $voucherType === 'payment' ? 'cash_payment' : 'cash_receipt';
        $postDisplay = fin_checks_manage_post_display($lifecycle);
        $journalId = (int) ($row['action_journal_id'] ?? 0);
        $journalUrl = $journalId > 0 ? app_url('index.php?r=journal_entries&id=' . $journalId) : '';

        $out[] = [
            'check_id' => (int) ($row['check_id'] ?? 0),
            'check_no' => trim((string) ($row['check_no'] ?? '')),
            'bank_name' => trim((string) ($row['bank_name'] ?? '')),
            'check_amount' => (float) ($row['check_amount'] ?? 0),
            'due_date' => trim((string) ($row['due_date'] ?? '')),
            'notes' => trim((string) ($row['notes'] ?? '')),
            'lifecycle_status' => $lifecycle,
            'lifecycle_label' => fin_checks_manage_status_label($lifecycle),
            'post_status_label' => $postDisplay['post'],
            'action_type_label' => $postDisplay['action'],
            'status_display' => $postDisplay['full'],
            'status_badge_class' => $postDisplay['badge_class'],
            'action_date' => trim((string) ($row['action_date'] ?? '')),
            'return_reason' => trim((string) ($row['return_reason'] ?? '')),
            'action_account_id' => (int) ($row['action_account_id'] ?? 0),
            'action_account_name' => trim((string) ($row['action_account_name'] ?? '')),
            'action_journal_id' => $journalId,
            'journal_url' => $journalUrl,
            'voucher_id' => $vid,
            'voucher_no' => (string) ($row['voucher_no'] ?? ''),
            'voucher_date' => (string) ($row['voucher_date'] ?? ''),
            'voucher_type' => $voucherType,
            'direction' => fin_checks_manage_direction_label($voucherType),
            'party_id' => (int) ($row['party_id'] ?? 0),
            'party_type' => (string) ($row['party_type'] ?? ''),
            'party_name' => (string) ($row['party_name'] ?? '—'),
            'is_posted' => (int) ($row['is_posted'] ?? 0) === 1,
            'voucher_url' => $vid > 0 ? app_url('index.php?r=' . $voucherRoute . '&id=' . $vid) : '',
            'is_overdue' => (bool) ($dueMeta['is_overdue'] ?? false),
            'days_until_due' => $dueMeta['days_until_due'],
            'urgency' => (string) ($dueMeta['urgency'] ?? ''),
            'urgency_label' => (string) ($dueMeta['urgency_label'] ?? ''),
            'can_action' => (int) ($row['is_posted'] ?? 0) === 1 && $lifecycle === 'pending',
        ];
    }

    return $out;
}

/** @return array<string, mixed>|null */
function fin_checks_manage_load_check(PDO $pdo, int $checkId): ?array
{
    if ($checkId < 1 || !fin_checks_manage_ensure_schema($pdo)) {
        return null;
    }
    $hasPostedCol = fin_voucher_has_column($pdo, 'is_posted');
    $postedExpr = $hasPostedCol ? 'v.is_posted' : '0';
    $st = $pdo->prepare(
        "SELECT c.*, v.voucher_no, v.voucher_date, v.voucher_type, v.party_id, v.party_type, v.cash_account_id,
                ({$postedExpr}) AS is_posted
         FROM fin_voucher_check c
         INNER JOIN fin_voucher v ON v.id = c.voucher_id
         WHERE c.id = ? LIMIT 1"
    );
    $st->execute([$checkId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function fin_checks_manage_assert_actionable(array $check): void
{
    if ((int) ($check['is_posted'] ?? 0) !== 1) {
        throw new RuntimeException('يجب ترحيل السند قبل صرف أو إرجاع الشيك.');
    }
    $status = (string) ($check['lifecycle_status'] ?? 'pending');
    if ($status !== 'pending') {
        throw new RuntimeException('تمت معالجة هذا الشيك مسبقاً (' . fin_checks_manage_post_display($status)['full'] . ').');
    }
    $amount = (float) ($check['check_amount'] ?? 0);
    if ($amount <= 0) {
        throw new RuntimeException('قيمة الشيك غير صالحة.');
    }
}

function fin_checks_manage_party_memo(PDO $pdo, string $partyType, int $partyId): string
{
    require_once app_path('includes/fin_voucher.php');
    $name = fin_voucher_party_name($pdo, $partyType, $partyId);
    if ($name === '') {
        return '';
    }

    return match ($partyType) {
        'supplier' => 'المورد: ' . $name,
        'customer' => 'العميل: ' . $name,
        default => $name,
    };
}

function fin_checks_manage_post_gl(
    PDO $pdo,
    string $refType,
    int $checkId,
    string $entryDate,
    string $description,
    array $lines
): int {
    require_once app_path('includes/acc_gl.php');
    if (!acc_gl_is_ready($pdo)) {
        throw new RuntimeException('نظام الربط المحاسبي غير مهيأ.');
    }
    if (acc_gl_ref_exists($pdo, $refType, $checkId)) {
        $st = $pdo->prepare(
            "SELECT id FROM acc_journal_entry WHERE ref_type = ? AND ref_id = ? AND source = 'auto' LIMIT 1"
        );
        $st->execute([$refType, $checkId]);

        return (int) $st->fetchColumn();
    }

    return acc_gl_post_entry($pdo, $refType, $checkId, $entryDate, $description, $lines);
}

function fin_checks_manage_post_party_return(
    PDO $pdo,
    array $check,
    string $actionDate,
    string $reason
): void {
    $partyType = (string) ($check['party_type'] ?? '');
    $partyId = (int) ($check['party_id'] ?? 0);
    $checkId = (int) ($check['id'] ?? 0);
    $amount = round((float) ($check['check_amount'] ?? 0), 6);
    if ($partyId < 1 || $amount <= 0) {
        return;
    }

    $checkNo = trim((string) ($check['check_no'] ?? ''));
    $voucherNo = (string) ($check['voucher_no'] ?? '');
    $memo = 'إرجاع شيك';
    if ($checkNo !== '') {
        $memo .= ' ' . $checkNo;
    }
    if ($voucherNo !== '') {
        $memo .= ' — سند ' . $voucherNo;
    }
    if ($reason !== '') {
        $memo .= ' — ' . $reason;
    }

    if ($partyType === 'customer') {
        require_once app_path('includes/crm_customer_ledger.php');
        crm_ledger_ensure_schema($pdo);
        crm_ledger_ensure_journal_voucher_txn($pdo);
        fin_checks_manage_ensure_customer_check_return_txn($pdo);
        if (crm_ledger_exists($pdo, 'check_return', $checkId)) {
            return;
        }
        crm_ledger_insert(
            $pdo,
            $partyId,
            $actionDate,
            'check_return',
            $checkId,
            $voucherNo !== '' ? $voucherNo : ('CHK-' . $checkId),
            'check',
            $amount,
            0.0,
            $memo
        );

        return;
    }

    if ($partyType === 'supplier') {
        require_once app_path('includes/crm_supplier_ledger.php');
        crm_supplier_ledger_ensure_schema($pdo);
        crm_supplier_ledger_ensure_journal_voucher_txn($pdo);
        fin_checks_manage_ensure_supplier_check_return_txn($pdo);
        if (crm_supplier_ledger_exists($pdo, 'check_return', $checkId)) {
            return;
        }
        crm_supplier_ledger_insert(
            $pdo,
            $partyId,
            $actionDate,
            'check_return',
            $checkId,
            $voucherNo !== '' ? $voucherNo : ('CHK-' . $checkId),
            'check',
            0.0,
            $amount,
            $memo
        );
    }
}

function fin_checks_manage_ensure_customer_check_return_txn(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    require_once app_path('includes/crm_customer_ledger.php');
    if (!crm_ledger_has_table($pdo)) {
        return;
    }
    try {
        $st = $pdo->query(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_customer_ledger' AND COLUMN_NAME = 'txn_type'"
        );
        $txnType = (string) ($st->fetchColumn() ?: '');
        if ($txnType !== '' && stripos($txnType, 'check_return') === false) {
            $pdo->exec(
                "ALTER TABLE crm_customer_ledger
                 MODIFY txn_type ENUM('sale_invoice','sale_return','cash_receipt','cash_payment','journal_voucher','check_return') NOT NULL"
            );
        }
    } catch (Throwable $e) {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/162_fin_checks_manage.sql');
    }
    $done = true;
}

function fin_checks_manage_ensure_supplier_check_return_txn(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    require_once app_path('includes/crm_supplier_ledger.php');
    if (!crm_supplier_ledger_has_table($pdo)) {
        return;
    }
    try {
        $st = $pdo->query(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_supplier_ledger' AND COLUMN_NAME = 'txn_type'"
        );
        $txnType = (string) ($st->fetchColumn() ?: '');
        if ($txnType !== '' && stripos($txnType, 'check_return') === false) {
            $pdo->exec(
                "ALTER TABLE crm_supplier_ledger
                 MODIFY txn_type ENUM('purchase_invoice','purchase_return','cash_payment','journal_voucher','check_return') NOT NULL"
            );
        }
    } catch (Throwable $e) {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/162_fin_checks_manage.sql');
    }
    $done = true;
}

/**
 * صرف الشيك (تحصيل وارد → بنك/صندوق، أو تأكيد صرف الشيك الصادر).
 *
 * @return array{ok:bool, journal_id:int, message:string}
 */
function fin_checks_manage_clear(PDO $pdo, int $checkId, int $accountId, string $actionDate): array
{
    fin_checks_manage_ensure_schema($pdo);
    $check = fin_checks_manage_load_check($pdo, $checkId);
    if (!$check) {
        throw new RuntimeException('الشيك غير موجود.');
    }
    fin_checks_manage_assert_actionable($check);

    $actionIso = parse_date_to_iso($actionDate);
    if ($actionIso === null) {
        throw new RuntimeException('تاريخ الصرف غير صالح.');
    }

    require_once app_path('includes/acc_gl.php');
    if ($accountId < 1 || !acc_gl_is_valid_leaf_account($pdo, $accountId)) {
        throw new RuntimeException('اختر حساب بنك أو صندوق صالحاً.');
    }

    $amount = round((float) ($check['check_amount'] ?? 0), 6);
    $voucherType = (string) ($check['voucher_type'] ?? '');
    $checkNo = trim((string) ($check['check_no'] ?? ''));
    $partyMemo = fin_checks_manage_party_memo($pdo, (string) ($check['party_type'] ?? ''), (int) ($check['party_id'] ?? 0));
    $desc = ($voucherType === 'receipt' ? 'تحصيل شيك وارد' : 'صرف شيك صادر')
        . ($checkNo !== '' ? ' ' . $checkNo : '')
        . ' — ' . (string) ($check['voucher_no'] ?? '');

    $journalId = 0;
    if ($voucherType === 'receipt') {
        $checksFundId = acc_gl_checks_fund_account_id($pdo);
        if ($checksFundId < 1) {
            throw new RuntimeException('حساب صندوق الشيكات غير مهيأ.');
        }
        $journalId = fin_checks_manage_post_gl(
            $pdo,
            'fin_check_clear',
            $checkId,
            $actionIso,
            $desc,
            [
                ['account_id' => $accountId, 'debit' => $amount, 'credit' => 0, 'memo' => $partyMemo],
                ['account_id' => $checksFundId, 'debit' => 0, 'credit' => $amount, 'memo' => $partyMemo],
            ]
        );
    } else {
        // سند صرف مرحّل مسبقاً — تسجيل حالة الصرف فقط (القيد على البنك موجود من السند)
        $journalId = 0;
    }

    $uid = (int) (current_user()['id'] ?? 0) ?: null;
    $pdo->prepare(
        "UPDATE fin_voucher_check
         SET lifecycle_status = 'cleared', action_date = ?, action_account_id = ?,
             action_journal_id = ?, action_at = NOW(), action_by = ?
         WHERE id = ? AND lifecycle_status = 'pending'"
    )->execute([
        $actionIso,
        $accountId,
        $journalId > 0 ? $journalId : null,
        $uid,
        $checkId,
    ]);

    return [
        'ok' => true,
        'journal_id' => $journalId,
        'message' => 'تم الترحيل — صرف'
            . ($voucherType === 'receipt' && $journalId > 0 ? ' (قيد محاسبي)' : ''),
    ];
}

/**
 * إرجاع الشيك مع قيد عكسي على العميل/المورد.
 *
 * @return array{ok:bool, journal_id:int, message:string}
 */
function fin_checks_manage_return(PDO $pdo, int $checkId, string $reason, string $actionDate): array
{
    fin_checks_manage_ensure_schema($pdo);
    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('سبب الإرجاع مطلوب.');
    }

    $check = fin_checks_manage_load_check($pdo, $checkId);
    if (!$check) {
        throw new RuntimeException('الشيك غير موجود.');
    }
    fin_checks_manage_assert_actionable($check);

    $actionIso = parse_date_to_iso($actionDate);
    if ($actionIso === null) {
        throw new RuntimeException('تاريخ الإرجاع غير صالح.');
    }

    require_once app_path('includes/acc_gl.php');
    $amount = round((float) ($check['check_amount'] ?? 0), 6);
    $voucherType = (string) ($check['voucher_type'] ?? '');
    $partyType = (string) ($check['party_type'] ?? '');
    $partyMemo = fin_checks_manage_party_memo($pdo, $partyType, (int) ($check['party_id'] ?? 0));
    $checkNo = trim((string) ($check['check_no'] ?? ''));
    $desc = 'إرجاع شيك'
        . ($checkNo !== '' ? ' ' . $checkNo : '')
        . ' — ' . (string) ($check['voucher_no'] ?? '')
        . ' — ' . $reason;

    $lines = [];
    if ($voucherType === 'receipt') {
        $checksFundId = acc_gl_checks_fund_account_id($pdo);
        if ($checksFundId < 1) {
            throw new RuntimeException('حساب صندوق الشيكات غير مهيأ.');
        }
        $lines = [
            ['rule' => 'ar_customers', 'debit' => $amount, 'credit' => 0, 'memo' => $partyMemo],
            ['account_id' => $checksFundId, 'debit' => 0, 'credit' => $amount, 'memo' => $partyMemo],
        ];
    } else {
        $bankAccountId = (int) ($check['cash_account_id'] ?? 0);
        if ($bankAccountId < 1 || !acc_gl_is_valid_leaf_account($pdo, $bankAccountId)) {
            $bankAccountId = acc_gl_bank_account_id($pdo);
        }
        if ($bankAccountId < 1) {
            throw new RuntimeException('حساب البنك/الصندوق غير محدد في سند الصرف.');
        }
        if ($partyType === 'supplier') {
            $lines = [
                ['account_id' => $bankAccountId, 'debit' => $amount, 'credit' => 0, 'memo' => $partyMemo],
                ['rule' => 'ap_suppliers', 'debit' => 0, 'credit' => $amount, 'memo' => $partyMemo],
            ];
        } elseif ($partyType === 'customer') {
            $lines = [
                ['account_id' => $bankAccountId, 'debit' => $amount, 'credit' => 0, 'memo' => $partyMemo],
                ['rule' => 'ar_customers', 'debit' => 0, 'credit' => $amount, 'memo' => $partyMemo],
            ];
        } else {
            $lines = [
                ['account_id' => $bankAccountId, 'debit' => $amount, 'credit' => 0, 'memo' => $partyMemo],
                ['rule' => 'misc_expense', 'debit' => 0, 'credit' => $amount, 'memo' => $partyMemo],
            ];
        }
    }

    $journalId = fin_checks_manage_post_gl($pdo, 'fin_check_return', $checkId, $actionIso, $desc, $lines);
    fin_checks_manage_post_party_return($pdo, $check, $actionIso, $reason);

    $uid = (int) (current_user()['id'] ?? 0) ?: null;
    $pdo->prepare(
        "UPDATE fin_voucher_check
         SET lifecycle_status = 'returned', action_date = ?, return_reason = ?,
             action_journal_id = ?, action_at = NOW(), action_by = ?
         WHERE id = ? AND lifecycle_status = 'pending'"
    )->execute([
        $actionIso,
        $reason,
        $journalId,
        $uid,
        $checkId,
    ]);

    return [
        'ok' => true,
        'journal_id' => $journalId,
        'message' => 'تم الترحيل — إرجاع' . ($journalId > 0 ? ' (قيد عكسي)' : ''),
    ];
}
