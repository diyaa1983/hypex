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

function fin_checks_manage_has_endorse_columns(PDO $pdo): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $st = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fin_voucher_check' AND COLUMN_NAME = 'endorsed_party_type'"
        );
        $cached = ((int) $st->fetchColumn()) > 0;
    } catch (Throwable $e) {
        $cached = false;
    }

    return $cached;
}

function fin_checks_manage_has_undo_columns(PDO $pdo): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $st = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fin_voucher_check' AND COLUMN_NAME = 'action_undo_at'"
        );
        $cached = ((int) $st->fetchColumn()) > 0;
    } catch (Throwable $e) {
        $cached = false;
    }

    return $cached;
}

function fin_checks_manage_has_supplier_check_endorse_txn(PDO $pdo): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    require_once app_path('includes/crm_supplier_ledger.php');
    if (!crm_supplier_ledger_has_table($pdo)) {
        $cached = false;

        return false;
    }
    try {
        $st = $pdo->query(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_supplier_ledger' AND COLUMN_NAME = 'txn_type'"
        );
        $txnType = (string) ($st->fetchColumn() ?: '');
        $cached = stripos($txnType, 'check_endorse') !== false;
    } catch (Throwable $e) {
        $cached = false;
    }

    return $cached;
}

/** إزالة سجلات تجيير الشيك من دفتر العميل (التجيير يظهر في كشف المورد فقط). */
function fin_checks_manage_purge_customer_check_endorse(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    require_once app_path('includes/crm_customer_ledger.php');
    if (crm_ledger_has_table($pdo)) {
        try {
            $pdo->exec("DELETE FROM crm_customer_ledger WHERE txn_type = 'check_endorse'");
        } catch (Throwable $e) {
            // ignore
        }
    }
    $done = true;
}

/** SQL fragment: مسح علامة الإلغاء عند ترحيل إجراء جديد. */
function fin_checks_manage_sql_clear_undo_flags(PDO $pdo): string
{
    return fin_checks_manage_has_undo_columns($pdo)
        ? ', action_undo_at = NULL, undone_action = NULL'
        : '';
}

/**
 * @param array<string, mixed> $row
 * @return array{
 *   action_was_undone:bool,
 *   execute_label:string,
 *   undone_action_label:string,
 *   action_undo_at:string,
 *   action_undo_at_dmy:string,
 *   post_status_label:string,
 *   action_type_label:string,
 *   status_display:string,
 *   status_badge_class:string
 * }
 */
function fin_checks_manage_undo_display(array $row, string $lifecycle): array
{
    $actionUndoAt = trim((string) ($row['action_undo_at'] ?? ''));
    $undoneAction = (string) ($row['undone_action'] ?? '');
    $actionWasUndone = $lifecycle === 'pending' && $actionUndoAt !== '';
    $undoneLabel = match ($undoneAction) {
        'returned' => 'إرجاع',
        'endorsed' => 'تجيير',
        'cleared' => 'صرف',
        default => '',
    };

    $undoAtDmy = '';
    if ($actionUndoAt !== '') {
        try {
            $undoAtDmy = format_date_dmY((new DateTimeImmutable($actionUndoAt))->format('Y-m-d'));
        } catch (Throwable $e) {
            $undoAtDmy = '';
        }
    }

    if (!$actionWasUndone) {
        return [
            'action_was_undone' => false,
            'execute_label' => '',
            'undone_action_label' => '',
            'action_undo_at' => '',
            'action_undo_at_dmy' => '',
            'post_status_label' => '',
            'action_type_label' => '',
            'status_display' => '',
            'status_badge_class' => '',
        ];
    }

    $statusFull = 'تم الإلغاء' . ($undoneLabel !== '' ? ' — ' . $undoneLabel : '');

    return [
        'action_was_undone' => true,
        'execute_label' => 'تم الإلغاء',
        'undone_action_label' => $undoneLabel,
        'action_undo_at' => $actionUndoAt,
        'action_undo_at_dmy' => $undoAtDmy,
        'post_status_label' => 'تم الإلغاء',
        'action_type_label' => $undoneLabel !== '' ? ('إلغاء ' . $undoneLabel) : '—',
        'status_display' => $statusFull,
        'status_badge_class' => 'fin-chk-badge fin-chk-badge--undo',
    ];
}

/**
 * @param array<string, mixed> $postDisplay
 * @param array<string, mixed> $undoDisplay
 * @return array{post_status_label:string, action_type_label:string, status_display:string, status_badge_class:string, action_date_display:string, action_date_dmy:string}
 */
function fin_checks_manage_row_labels(array $postDisplay, array $undoDisplay, string $actionDate): array
{
    if (!empty($undoDisplay['action_was_undone'])) {
        return [
            'post_status_label' => (string) $undoDisplay['post_status_label'],
            'action_type_label' => (string) $undoDisplay['action_type_label'],
            'status_display' => (string) $undoDisplay['status_display'],
            'status_badge_class' => (string) $undoDisplay['status_badge_class'],
            'action_date_display' => (string) ($undoDisplay['action_undo_at'] ?? ''),
            'action_date_dmy' => (string) ($undoDisplay['action_undo_at_dmy'] ?? ''),
        ];
    }

    $actionDate = trim($actionDate);

    return [
        'post_status_label' => (string) ($postDisplay['post'] ?? ''),
        'action_type_label' => (string) ($postDisplay['action'] ?? ''),
        'status_display' => (string) ($postDisplay['full'] ?? ''),
        'status_badge_class' => (string) ($postDisplay['badge_class'] ?? ''),
        'action_date_display' => $actionDate,
        'action_date_dmy' => $actionDate !== '' ? format_date_dmY($actionDate) : '',
    ];
}

function fin_checks_manage_ensure_schema(PDO $pdo): bool
{
    if (!fin_voucher_checks_ensure_table($pdo)) {
        return false;
    }
    if (!fin_checks_manage_has_lifecycle($pdo)) {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/162_fin_checks_manage.sql');
    }
    if (!fin_checks_manage_has_endorse_columns($pdo)) {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/172_fin_check_endorse.sql');
    }
    if (!fin_checks_manage_has_undo_columns($pdo)) {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/173_fin_check_action_undo.sql');
    }
    if (!fin_checks_manage_has_supplier_check_endorse_txn($pdo)) {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/175_fin_check_endorse_supplier_ledger.sql');
    }
    fin_checks_manage_purge_customer_check_endorse($pdo);

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
    if (!in_array($status, ['all', 'pending', 'cleared', 'returned', 'endorsed', 'undone'], true)) {
        $status = 'all';
    }
    $dateField = (string) ($input['date_field'] ?? 'voucher');
    if (!in_array($dateField, ['voucher', 'due', 'cleared', 'returned', 'endorsed'], true)) {
        $dateField = 'voucher';
    }

    $from = trim((string) ($input['from'] ?? ''));
    $to = trim((string) ($input['to'] ?? ''));

    $sortField = (string) ($input['sort_field'] ?? 'voucher');
    if (!in_array($sortField, ['due', 'voucher', 'action'], true)) {
        $sortField = 'voucher';
    }
    $sortDir = strtolower(trim((string) ($input['sort_dir'] ?? 'asc')));
    if (!in_array($sortDir, ['asc', 'desc'], true)) {
        $sortDir = 'asc';
    }

    return [
        'direction' => $direction,
        'check_no' => trim((string) ($input['check_no'] ?? '')),
        'check_id' => max(0, (int) ($input['check_id'] ?? 0)),
        'status' => $status,
        'date_field' => $dateField,
        'sort_field' => $sortField,
        'sort_dir' => $sortDir,
        'from' => $from,
        'to' => $to,
        'overdue_only' => !empty($input['overdue_only']),
        'date_range_active' => $from !== '' && $to !== '',
    ];
}

/** فلاتر شاشة الشيكات الواردة فقط (سندات القبض). */
function fin_checks_manage_parse_incoming_screen_filters(array $input): array
{
    $filters = fin_checks_manage_parse_filters($input);
    $filters['direction'] = 'incoming';

    return $filters;
}

/** تعبير SQL لتاريخ الترتيب حسب العمود المختار. */
function fin_checks_manage_sort_expr(PDO $pdo, string $sortField): string
{
    return match ($sortField) {
        'voucher' => 'v.voucher_date',
        'action' => fin_checks_manage_has_undo_columns($pdo)
            ? "COALESCE(
                CASE WHEN c.action_undo_at IS NOT NULL THEN DATE(c.action_undo_at) END,
                CASE WHEN c.lifecycle_status IN ('cleared','returned','endorsed') THEN c.action_date END
              )"
            : "CASE WHEN c.lifecycle_status IN ('cleared','returned','endorsed') THEN c.action_date END",
        default => 'c.due_date',
    };
}

function fin_checks_manage_order_sql(PDO $pdo, array $filters): string
{
    $sortField = (string) ($filters['sort_field'] ?? 'voucher');
    $sortDir = strtoupper((string) ($filters['sort_dir'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
    $primary = fin_checks_manage_sort_expr($pdo, $sortField);

    return " ORDER BY ({$primary} IS NULL), {$primary} {$sortDir}, v.voucher_date ASC, c.due_date ASC, c.id ASC";
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
        'endorsed' => 'تجيير',
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
    if ($lifecycle === 'endorsed') {
        return [
            'post' => 'تم الترحيل',
            'action' => 'تجيير',
            'full' => 'تم الترحيل — تجيير',
            'badge_class' => 'fin-chk-badge fin-chk-badge--posted-endorse',
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
 * شرط ترحيل السند (عمود is_posted أو قيد cash_receipt/cash_payment).
 */
function fin_checks_manage_voucher_posted_sql(PDO $pdo, string $alias = 'v'): string
{
    require_once app_path('includes/acc_gl.php');
    $hasPostedCol = fin_voucher_has_column($pdo, 'is_posted');
    if ($hasPostedCol && acc_gl_journal_has_ref_columns($pdo)) {
        return "({$alias}.is_posted = 1 OR EXISTS (
            SELECT 1 FROM acc_journal_entry e
            WHERE e.ref_id = {$alias}.id
              AND e.ref_type IN ('cash_receipt', 'cash_payment')
              AND e.status = 'posted'
        ))";
    }
    if ($hasPostedCol) {
        return "{$alias}.is_posted = 1";
    }

    return '0';
}

/**
 * إعادة الشيكات التي تُظهر «مصروف/مرتجع/مجيّر» دون قيد إجراء فعلي إلى «قيد».
 */
function fin_checks_manage_repair_spurious_lifecycle(PDO $pdo): void
{
    if (!fin_checks_manage_has_lifecycle($pdo)) {
        return;
    }
    require_once app_path('includes/acc_gl.php');
    $hasGlRef = acc_gl_journal_has_ref_columns($pdo);

    try {
        if ($hasGlRef) {
            // الشيكات الواردة فقط: «مصروف» بلا قيد إجراء يُعاد إلى قيد.
            // الشيكات الصادرة قد تُصرَف قديماً بلا قيد (أثر السند) أو تُرجَع بلا قيد في التدفق الجديد.
            $pdo->exec(
                "UPDATE fin_voucher_check c
                 INNER JOIN fin_voucher v ON v.id = c.voucher_id AND v.voucher_type = 'receipt'
                 SET c.lifecycle_status = 'pending',
                     c.action_date = NULL,
                     c.action_account_id = NULL,
                     c.action_journal_id = NULL,
                     c.return_reason = NULL
                 WHERE c.lifecycle_status = 'cleared'
                   AND (c.action_journal_id IS NULL OR c.action_journal_id = 0)
                   AND NOT EXISTS (
                       SELECT 1 FROM acc_journal_entry e
                       WHERE e.ref_type = 'fin_check_clear' AND e.ref_id = c.id AND e.status = 'posted'
                   )"
            );
            $pdo->exec(
                "UPDATE fin_voucher_check c
                 INNER JOIN fin_voucher v ON v.id = c.voucher_id AND v.voucher_type = 'receipt'
                 SET c.lifecycle_status = 'pending',
                     c.action_date = NULL,
                     c.action_journal_id = NULL,
                     c.return_reason = NULL
                 WHERE c.lifecycle_status = 'returned'
                   AND (c.action_journal_id IS NULL OR c.action_journal_id = 0)
                   AND NOT EXISTS (
                       SELECT 1 FROM acc_journal_entry e
                       WHERE e.ref_type = 'fin_check_return' AND e.ref_id = c.id AND e.status = 'posted'
                   )"
            );
            if (fin_checks_manage_has_endorse_columns($pdo)) {
                $pdo->exec(
                    "UPDATE fin_voucher_check c
                     SET lifecycle_status = 'pending',
                         action_date = NULL,
                         action_account_id = NULL,
                         action_journal_id = NULL,
                         return_reason = NULL,
                         endorsed_party_type = NULL,
                         endorsed_party_id = NULL,
                         endorse_notes = NULL
                     WHERE c.lifecycle_status = 'endorsed'
                       AND (c.action_journal_id IS NULL OR c.action_journal_id = 0)
                       AND NOT EXISTS (
                           SELECT 1 FROM acc_journal_entry e
                           WHERE e.ref_type = 'fin_check_endorse' AND e.ref_id = c.id AND e.status = 'posted'
                       )"
                );
            }
        } else {
            $pdo->exec(
                "UPDATE fin_voucher_check
                 SET lifecycle_status = 'pending',
                     action_date = NULL,
                     action_account_id = NULL,
                     action_journal_id = NULL,
                     return_reason = NULL
                 WHERE lifecycle_status IN ('cleared', 'returned', 'endorsed')
                   AND (action_journal_id IS NULL OR action_journal_id = 0)"
            );
        }
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * تطبيق حالة «تم الإلغاء» على الشيك بعد حذف قيد الصرف/الإرجاع/التجيير.
 */
function fin_checks_manage_apply_undo_state(PDO $pdo, int $checkId, string $previousStatus): bool
{
    if ($checkId < 1 || !fin_checks_manage_ensure_schema($pdo)) {
        return false;
    }
    if (!in_array($previousStatus, ['cleared', 'returned', 'endorsed'], true)) {
        return false;
    }

    try {
        $st = $pdo->prepare(
            'SELECT lifecycle_status, action_undo_at FROM fin_voucher_check WHERE id = ? LIMIT 1'
        );
        $st->execute([$checkId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
    if (!$row) {
        return false;
    }

    $lifecycle = (string) ($row['lifecycle_status'] ?? 'pending');
    if ($lifecycle === 'pending' && trim((string) ($row['action_undo_at'] ?? '')) !== '') {
        return true;
    }
    if ($lifecycle !== 'pending' && $lifecycle !== $previousStatus) {
        return false;
    }

    if ($previousStatus === 'returned') {
        fin_checks_manage_delete_party_return($pdo, $checkId);
    }
    if ($previousStatus === 'endorsed') {
        fin_checks_manage_delete_party_endorse($pdo, $checkId);
    }

    $hasEndorse = fin_checks_manage_has_endorse_columns($pdo);
    $endorseReset = $hasEndorse
        ? ', endorsed_party_type = NULL, endorsed_party_id = NULL, endorse_notes = NULL'
        : '';
    $undoSet = fin_checks_manage_has_undo_columns($pdo)
        ? ', action_undo_at = COALESCE(action_undo_at, NOW()), undone_action = COALESCE(undone_action, ?)'
        : '';
    $undoParams = fin_checks_manage_has_undo_columns($pdo) ? [$previousStatus] : [];

    try {
        $pdo->prepare(
            "UPDATE fin_voucher_check
             SET lifecycle_status = 'pending', action_date = NULL, return_reason = NULL,
                 action_account_id = NULL, action_journal_id = NULL, action_at = NULL, action_by = NULL{$endorseReset}{$undoSet}
             WHERE id = ?"
        )->execute(array_merge($undoParams, [$checkId]));
    } catch (Throwable $e) {
        return false;
    }

    return true;
}

/**
 * مزامنة الشيكات التي فقدت قيودها (إلغاء من السند/القيود) مع حالة «تم الإلغاء».
 */
function fin_checks_manage_sync_orphan_actions(PDO $pdo): void
{
    if (!fin_checks_manage_has_lifecycle($pdo)) {
        return;
    }
    require_once app_path('includes/acc_gl.php');
    if (!acc_gl_journal_has_ref_columns($pdo)) {
        return;
    }

    try {
        $st = $pdo->query(
            "SELECT c.id, c.lifecycle_status
             FROM fin_voucher_check c
             WHERE c.lifecycle_status IN ('cleared','returned','endorsed')
               AND NOT EXISTS (
                   SELECT 1 FROM acc_journal_entry e
                   WHERE e.ref_id = c.id AND e.status = 'posted'
                     AND (
                       (c.lifecycle_status = 'cleared' AND e.ref_type = 'fin_check_clear')
                       OR (c.lifecycle_status = 'returned' AND e.ref_type = 'fin_check_return')
                       OR (c.lifecycle_status = 'endorsed' AND e.ref_type = 'fin_check_endorse')
                     )
               )"
        );
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $checkId = (int) ($row['id'] ?? 0);
            $status = (string) ($row['lifecycle_status'] ?? '');
            if ($checkId > 0 && $status !== '') {
                fin_checks_manage_apply_undo_state($pdo, $checkId, $status);
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * @return 'cleared'|'returned'|'endorsed'|null
 */
function fin_checks_manage_ref_type_to_lifecycle(string $refType): ?string
{
    return match ($refType) {
        'fin_check_clear' => 'cleared',
        'fin_check_return' => 'returned',
        'fin_check_endorse' => 'endorsed',
        default => null,
    };
}

/**
 * مزامنة الشيكات القديمة: قيود fin_check_* المسجّلة سابقاً فقط.
 */
function fin_checks_manage_sync_legacy_status(PDO $pdo): void
{
    static $done = false;
    if ($done || !fin_checks_manage_has_lifecycle($pdo)) {
        return;
    }
    $done = true;

    fin_checks_manage_repair_spurious_lifecycle($pdo);
    fin_checks_manage_sync_orphan_actions($pdo);

    require_once app_path('includes/acc_gl.php');

    try {
        $refTypes = "'fin_check_clear', 'fin_check_return'";
        if (fin_checks_manage_has_endorse_columns($pdo)) {
            $refTypes .= ", 'fin_check_endorse'";
        }
        $st = $pdo->query(
            "SELECT c.id AS check_id, e.ref_type, e.entry_date, e.id AS journal_id,
                    (SELECT l.account_id FROM acc_journal_line l
                     WHERE l.journal_id = e.id AND l.debit > 0.000001 LIMIT 1) AS debit_account_id
             FROM fin_voucher_check c
             INNER JOIN acc_journal_entry e ON e.ref_id = c.id
                AND e.ref_type IN ({$refTypes})
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
        $updEndorse = fin_checks_manage_has_endorse_columns($pdo)
            ? $pdo->prepare(
                "UPDATE fin_voucher_check
                 SET lifecycle_status = 'endorsed', action_date = ?, action_journal_id = ?
                 WHERE id = ? AND lifecycle_status = 'pending'"
            )
            : null;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $cid = (int) ($row['check_id'] ?? 0);
            if ($cid < 1) {
                continue;
            }
            $entryDate = (string) ($row['entry_date'] ?? '');
            $journalId = (int) ($row['journal_id'] ?? 0);
            $refType = (string) ($row['ref_type'] ?? '');
            if ($refType === 'fin_check_clear') {
                $updClear->execute([
                    $entryDate !== '' ? $entryDate : null,
                    (int) ($row['debit_account_id'] ?? 0) ?: null,
                    $journalId > 0 ? $journalId : null,
                    $cid,
                ]);
            } elseif ($refType === 'fin_check_return') {
                $updReturn->execute([
                    $entryDate !== '' ? $entryDate : null,
                    $journalId > 0 ? $journalId : null,
                    $cid,
                ]);
            } elseif ($updEndorse !== null && $refType === 'fin_check_endorse') {
                $updEndorse->execute([
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

    // تجيير شيك — سجل كشف حساب المورد المُجيَّر إليه
    try {
        require_once app_path('includes/crm_customer_ledger.php');
        require_once app_path('includes/crm_supplier_ledger.php');
        fin_checks_manage_purge_customer_check_endorse($pdo);
        if (fin_checks_manage_has_endorse_columns($pdo) && crm_supplier_ledger_has_table($pdo)) {
            fin_checks_manage_ensure_supplier_check_endorse_txn($pdo);
            $st = $pdo->query(
                "SELECT c.id AS check_id, c.check_no, c.check_amount, c.action_date, c.action_journal_id,
                        c.endorsed_party_id, v.voucher_no
                 FROM fin_voucher_check c
                 INNER JOIN fin_voucher v ON v.id = c.voucher_id
                 WHERE c.lifecycle_status = 'endorsed'
                   AND c.endorsed_party_type = 'supplier'
                   AND c.endorsed_party_id > 0"
            );
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $checkId = (int) ($row['check_id'] ?? 0);
                $supplierId = (int) ($row['endorsed_party_id'] ?? 0);
                if ($checkId < 1 || $supplierId < 1) {
                    continue;
                }
                $actionDate = (string) ($row['action_date'] ?? '');
                if ($actionDate === '') {
                    continue;
                }
                $journalId = (int) ($row['action_journal_id'] ?? 0);
                if (!crm_supplier_ledger_exists($pdo, 'check_endorse', $checkId)) {
                    fin_checks_manage_post_party_endorse(
                        $pdo,
                        [
                            'id' => $checkId,
                            'check_no' => (string) ($row['check_no'] ?? ''),
                            'voucher_no' => (string) ($row['voucher_no'] ?? ''),
                            'check_amount' => (float) ($row['check_amount'] ?? 0),
                        ],
                        $actionDate,
                        $supplierId,
                        $journalId
                    );
                } elseif ($journalId > 0) {
                    crm_supplier_ledger_delete_journal_voucher_by_journal($pdo, $journalId);
                }
            }
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

function fin_checks_manage_check_has_posted_journal(PDO $pdo, int $checkId, string $lifecycle): bool
{
    if ($checkId < 1 || !in_array($lifecycle, ['cleared', 'returned', 'endorsed'], true)) {
        return false;
    }
    require_once app_path('includes/acc_gl.php');
    if (!acc_gl_journal_has_ref_columns($pdo)) {
        return false;
    }
    $refType = match ($lifecycle) {
        'returned' => 'fin_check_return',
        'endorsed' => 'fin_check_endorse',
        default => 'fin_check_clear',
    };
    try {
        $st = $pdo->prepare(
            "SELECT 1 FROM acc_journal_entry
             WHERE ref_type = ? AND ref_id = ? AND status = 'posted' LIMIT 1"
        );
        $st->execute([$refType, $checkId]);

        return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function fin_checks_manage_refresh_row_after_undo(PDO $pdo, array $row): array
{
    $checkId = (int) ($row['check_id'] ?? $row['id'] ?? 0);
    if ($checkId < 1) {
        return $row;
    }
    try {
        $hasUndo = fin_checks_manage_has_undo_columns($pdo);
        $undoCols = $hasUndo ? ', action_undo_at, undone_action' : '';
        $st = $pdo->prepare(
            "SELECT lifecycle_status, action_date, return_reason, action_account_id, action_journal_id{$undoCols}
             FROM fin_voucher_check WHERE id = ? LIMIT 1"
        );
        $st->execute([$checkId]);
        $fresh = $st->fetch(PDO::FETCH_ASSOC);
        if ($fresh) {
            return array_merge($row, $fresh);
        }
    } catch (Throwable $e) {
        // ignore
    }

    return $row;
}

function fin_checks_manage_direction_label(string $voucherType): string
{
    return $voucherType === 'payment' ? 'صادر' : 'وارد';
}

/**
 * إجمالي الشيكات الواردة المستحقة (قيد التحصيل) من سندات القبض المرحّلة.
 *
 * @return array{count:int, amount:float}
 */
function fin_checks_manage_sum_due_from_receipts(PDO $pdo): array
{
    if (!fin_checks_manage_ensure_schema($pdo) || !fin_voucher_has_table($pdo)) {
        return ['count' => 0, 'amount' => 0.0];
    }

    fin_checks_manage_sync_legacy_status($pdo);

    $postedExpr = fin_checks_manage_voucher_posted_sql($pdo, 'v');
    $hasUndo = fin_checks_manage_has_undo_columns($pdo);
    $undoFilter = $hasUndo ? " AND (c.action_undo_at IS NULL OR c.action_undo_at = '')" : '';
    $cancelFilter = '';
    if (fin_voucher_has_column($pdo, 'is_cancelled')) {
        $cancelFilter = ' AND (v.is_cancelled = 0 OR v.is_cancelled IS NULL)';
    }

    $sql =
        "SELECT COUNT(*) AS cnt, COALESCE(SUM(c.check_amount), 0) AS total
         FROM fin_voucher_check c
         INNER JOIN fin_voucher v ON v.id = c.voucher_id
         WHERE c.check_amount > 0.000001
           AND v.voucher_type = 'receipt'
           AND c.lifecycle_status = 'pending'
           {$undoFilter}
           {$cancelFilter}
           AND ({$postedExpr})";

    try {
        $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return ['count' => 0, 'amount' => 0.0];
    }

    return [
        'count' => (int) ($row['cnt'] ?? 0),
        'amount' => (float) ($row['total'] ?? 0),
    ];
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

    $postedExpr = fin_checks_manage_voucher_posted_sql($pdo, 'v');

    $dateCol = match ($filters['date_field'] ?? 'voucher') {
        'due' => 'c.due_date',
        'cleared' => "CASE WHEN c.lifecycle_status = 'cleared' THEN c.action_date ELSE NULL END",
        'returned' => "CASE WHEN c.lifecycle_status = 'returned' THEN c.action_date ELSE NULL END",
        'endorsed' => "CASE WHEN c.lifecycle_status = 'endorsed' THEN c.action_date ELSE NULL END",
        default => 'v.voucher_date',
    };

    $hasEndorse = fin_checks_manage_has_endorse_columns($pdo);
    $endorseCols = $hasEndorse
        ? ', c.endorsed_party_type, c.endorsed_party_id, c.endorse_notes'
        : '';
    $hasUndo = fin_checks_manage_has_undo_columns($pdo);
    $undoCols = $hasUndo ? ', c.action_undo_at, c.undone_action' : '';
    $endorseJoins = $hasEndorse
        ? '
         LEFT JOIN crm_customer end_cust ON c.endorsed_party_type = \'customer\' AND end_cust.id = c.endorsed_party_id
         LEFT JOIN crm_supplier end_sup ON c.endorsed_party_type = \'supplier\' AND end_sup.id = c.endorsed_party_id'
        : '';

    $sql =
        "SELECT c.id AS check_id, c.check_no, c.bank_name, c.check_amount, c.due_date, c.notes,
                c.lifecycle_status, c.action_date, c.return_reason, c.action_account_id, c.action_journal_id{$endorseCols}{$undoCols},
                v.id AS voucher_id, v.voucher_no, v.voucher_date, v.voucher_type, v.party_id, v.party_type,
                v.cash_account_id,
                ({$postedExpr}) AS is_posted,
                COALESCE(cust.name_ar, sup.name_ar, '—') AS party_name,
                COALESCE(acc.name_ar, '') AS action_account_name"
        . ($hasEndorse ? ", COALESCE(end_cust.name_ar, end_sup.name_ar, '') AS endorsed_party_name" : '') . "
         FROM fin_voucher_check c
         INNER JOIN fin_voucher v ON v.id = c.voucher_id
         LEFT JOIN crm_customer cust ON v.party_type = 'customer' AND cust.id = v.party_id
         LEFT JOIN crm_supplier sup ON v.party_type = 'supplier' AND sup.id = v.party_id
         LEFT JOIN acc_account acc ON acc.id = c.action_account_id{$endorseJoins}
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
    if ($status === 'undone') {
        if ($hasUndo) {
            $sql .= " AND c.lifecycle_status = 'pending' AND c.action_undo_at IS NOT NULL";
        } else {
            $sql .= ' AND 1=0';
        }
    } elseif ($status === 'pending') {
        if ($hasUndo) {
            $sql .= " AND c.lifecycle_status = 'pending' AND (c.action_undo_at IS NULL OR c.action_undo_at = '')";
        } else {
            $sql .= " AND c.lifecycle_status = 'pending'";
        }
    } elseif ($status !== 'all') {
        $sql .= ' AND c.lifecycle_status = ?';
        $params[] = $status;
    }

    $checkNo = trim((string) ($filters['check_no'] ?? ''));
    if ($checkNo !== '') {
        $sql .= ' AND c.check_no LIKE ?';
        $params[] = '%' . $checkNo . '%';
    }

    $focusCheckId = (int) ($filters['check_id'] ?? 0);
    if ($focusCheckId > 0) {
        $sql .= ' AND c.id = ?';
        $params[] = $focusCheckId;
    }

    $sql .= fin_checks_manage_order_sql($pdo, $filters);

    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $checkId = (int) ($row['check_id'] ?? 0);
        $lifecycle = (string) ($row['lifecycle_status'] ?? 'pending');
        if ($checkId > 0 && in_array($lifecycle, ['cleared', 'returned', 'endorsed'], true)
            && !fin_checks_manage_check_has_posted_journal($pdo, $checkId, $lifecycle)) {
            fin_checks_manage_apply_undo_state($pdo, $checkId, $lifecycle);
            $row = fin_checks_manage_refresh_row_after_undo($pdo, $row);
            $lifecycle = (string) ($row['lifecycle_status'] ?? 'pending');
        }

        $dueMeta = fin_checks_manage_due_meta((string) ($row['due_date'] ?? ''), $today);
        if (!empty($filters['overdue_only']) && empty($dueMeta['is_overdue'])) {
            continue;
        }

        $voucherType = (string) ($row['voucher_type'] ?? '');
        $vid = (int) ($row['voucher_id'] ?? 0);
        $voucherRoute = $voucherType === 'payment' ? 'cash_payment' : 'cash_receipt';
        $postDisplay = fin_checks_manage_post_display($lifecycle);
        $undoDisplay = fin_checks_manage_undo_display($row, $lifecycle);
        $labels = fin_checks_manage_row_labels(
            $postDisplay,
            $undoDisplay,
            trim((string) ($row['action_date'] ?? ''))
        );
        $journalId = (int) ($row['action_journal_id'] ?? 0);
        $journalUrl = $journalId > 0
            ? app_url('index.php?r=journal_entries&action=view&id=' . $journalId)
            : '';

        $out[] = [
            'check_id' => (int) ($row['check_id'] ?? 0),
            'check_no' => trim((string) ($row['check_no'] ?? '')),
            'bank_name' => trim((string) ($row['bank_name'] ?? '')),
            'check_amount' => (float) ($row['check_amount'] ?? 0),
            'due_date' => trim((string) ($row['due_date'] ?? '')),
            'notes' => trim((string) ($row['notes'] ?? '')),
            'lifecycle_status' => $lifecycle,
            'lifecycle_label' => fin_checks_manage_status_label($lifecycle),
            'post_status_label' => $labels['post_status_label'],
            'action_type_label' => $labels['action_type_label'],
            'status_display' => $labels['status_display'],
            'status_badge_class' => $labels['status_badge_class'],
            'action_date' => $labels['action_date_display'],
            'action_date_dmy' => $labels['action_date_dmy'],
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
            'can_undo' => (int) ($row['is_posted'] ?? 0) === 1 && in_array($lifecycle, ['cleared', 'returned', 'endorsed'], true),
            'action_was_undone' => (bool) ($undoDisplay['action_was_undone'] ?? false),
            'execute_label' => (string) ($undoDisplay['execute_label'] ?? ''),
            'undone_action_label' => (string) ($undoDisplay['undone_action_label'] ?? ''),
            'action_undo_at' => (string) ($undoDisplay['action_undo_at'] ?? ''),
            'action_undo_at_dmy' => (string) ($undoDisplay['action_undo_at_dmy'] ?? ''),
            'undo_label' => match ($lifecycle) {
                'returned' => 'إلغاء الإرجاع',
                'endorsed' => 'إلغاء التجيير',
                default => 'إلغاء الصرف',
            },
            'endorsed_party_type' => (string) ($row['endorsed_party_type'] ?? ''),
            'endorsed_party_id' => (int) ($row['endorsed_party_id'] ?? 0),
            'endorsed_party_name' => (string) ($row['endorsed_party_name'] ?? ''),
            'endorse_notes' => (string) ($row['endorse_notes'] ?? ''),
        ];
    }

    return $out;
}

/**
 * شيكات السند مع حالة الترحيل (لعرضها داخل سند القبض/الصرف).
 *
 * @return list<array<string, mixed>>
 */
function fin_checks_manage_checks_for_voucher_view(PDO $pdo, int $voucherId, bool $isPosted): array
{
    if ($voucherId < 1) {
        return [];
    }
    require_once app_path('includes/fin_voucher_checks.php');
    if (!fin_voucher_checks_ensure_table($pdo)) {
        return [];
    }
    fin_checks_manage_ensure_schema($pdo);

    $hasLifecycle = fin_checks_manage_has_lifecycle($pdo);
    $hasEndorse = fin_checks_manage_has_endorse_columns($pdo);
    $endorseJoins = $hasEndorse
        ? "
         LEFT JOIN crm_customer end_cust ON c.endorsed_party_type = 'customer' AND end_cust.id = c.endorsed_party_id
         LEFT JOIN crm_supplier end_sup ON c.endorsed_party_type = 'supplier' AND end_sup.id = c.endorsed_party_id"
        : '';
    $endorseCols = $hasEndorse
        ? ', c.endorsed_party_type, c.endorsed_party_id, c.endorse_notes'
        : '';
    $hasUndo = fin_checks_manage_has_undo_columns($pdo);
    $undoCols = $hasUndo ? ', c.action_undo_at, c.undone_action' : '';
    $endorseSelect = $hasEndorse
        ? ", COALESCE(end_cust.name_ar, end_sup.name_ar, '') AS endorsed_party_name"
        : ", '' AS endorsed_party_name";

    $lifecycleCols = $hasLifecycle
        ? ', c.lifecycle_status, c.action_date, c.return_reason, c.action_account_id, c.action_journal_id' . $endorseCols . $undoCols
        : ", 'pending' AS lifecycle_status, NULL AS action_date, NULL AS return_reason, NULL AS action_account_id, NULL AS action_journal_id";

    try {
        $st = $pdo->prepare(
            "SELECT c.id, c.sort_order, c.check_no, c.bank_name, c.check_amount, c.due_date, c.notes
                    {$lifecycleCols}
                    {$endorseSelect}
             FROM fin_voucher_check c{$endorseJoins}
             WHERE c.voucher_id = ?
             ORDER BY c.sort_order ASC, c.id ASC"
        );
        $st->execute([$voucherId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $due = trim((string) ($row['due_date'] ?? ''));
        $lifecycle = $hasLifecycle ? (string) ($row['lifecycle_status'] ?? 'pending') : 'pending';
        $postDisplay = fin_checks_manage_post_display($lifecycle);
        $undoDisplay = fin_checks_manage_undo_display($row, $lifecycle);
        $labels = fin_checks_manage_row_labels(
            $postDisplay,
            $undoDisplay,
            trim((string) ($row['action_date'] ?? ''))
        );
        $journalId = (int) ($row['action_journal_id'] ?? 0);
        $actionDate = trim((string) ($row['action_date'] ?? ''));

        $out[] = [
            'id' => (int) ($row['id'] ?? 0),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'check_no' => trim((string) ($row['check_no'] ?? '')),
            'bank_name' => trim((string) ($row['bank_name'] ?? '')),
            'check_amount' => (float) ($row['check_amount'] ?? 0),
            'due_date' => $due,
            'due_date_dmy' => $due !== '' ? format_date_dmY($due) : '',
            'notes' => trim((string) ($row['notes'] ?? '')),
            'lifecycle_status' => $lifecycle,
            'status_display' => $labels['status_display'],
            'action_type_label' => $labels['action_type_label'],
            'post_status_label' => $labels['post_status_label'],
            'status_badge_class' => $labels['status_badge_class'],
            'action_date' => $labels['action_date_display'],
            'action_date_dmy' => $labels['action_date_dmy'],
            'return_reason' => trim((string) ($row['return_reason'] ?? '')),
            'endorsed_party_name' => trim((string) ($row['endorsed_party_name'] ?? '')),
            'endorse_notes' => trim((string) ($row['endorse_notes'] ?? '')),
            'action_was_undone' => (bool) ($undoDisplay['action_was_undone'] ?? false),
            'execute_label' => (string) ($undoDisplay['execute_label'] ?? ''),
            'undone_action_label' => (string) ($undoDisplay['undone_action_label'] ?? ''),
            'action_undo_at_dmy' => (string) ($undoDisplay['action_undo_at_dmy'] ?? ''),
            'can_undo' => $isPosted && in_array($lifecycle, ['cleared', 'returned', 'endorsed'], true),
            'undo_label' => match ($lifecycle) {
                'returned' => 'إلغاء الإرجاع',
                'endorsed' => 'إلغاء التجيير',
                default => 'إلغاء الصرف',
            },
            'journal_url' => $journalId > 0
                ? app_url('index.php?r=journal_entries&action=view&id=' . $journalId)
                : '',
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
    $postedExpr = fin_checks_manage_voucher_posted_sql($pdo, 'v');
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

function fin_checks_manage_unpost_journal(PDO $pdo, int $journalId): void
{
    if ($journalId < 1) {
        return;
    }
    require_once app_path('includes/acc_gl.php');
    acc_gl_delete_auto_journal($pdo, $journalId);
}

/**
 * @param list<array{rule?:string, account_id?:int, debit:float, credit:float, memo?:string, party_type?:string, party_id?:int}> $lines
 */
function fin_checks_manage_post_gl_with_parties(
    PDO $pdo,
    string $refType,
    int $checkId,
    string $entryDate,
    string $description,
    array $lines
): int {
    require_once app_path('includes/acc_gl.php');
    require_once app_path('includes/acc_journal.php');
    require_once app_path('includes/acc_journal_party.php');

    if (!acc_gl_is_ready($pdo)) {
        throw new RuntimeException('نظام الربط المحاسبي غير مهيأ.');
    }
    if (acc_gl_ref_exists($pdo, $refType, $checkId)) {
        $st = $pdo->prepare(
            "SELECT id FROM acc_journal_entry WHERE ref_type = ? AND ref_id = ? AND source = 'auto' LIMIT 1"
        );
        $st->execute([$refType, $checkId]);
        $existingId = (int) $st->fetchColumn();
        if ($existingId > 0) {
            return $existingId;
        }
    }

    $settings = acc_gl_load_settings($pdo);
    $resolved = [];
    foreach ($lines as $ln) {
        $debit = round(max(0, (float) ($ln['debit'] ?? 0)), 6);
        $credit = round(max(0, (float) ($ln['credit'] ?? 0)), 6);
        if ($debit <= 0 && $credit <= 0) {
            continue;
        }
        $accountId = (int) ($ln['account_id'] ?? 0);
        if ($accountId < 1 && !empty($ln['rule'])) {
            $accountId = acc_gl_account_id($settings, (string) $ln['rule']);
        }
        if ($accountId < 1) {
            continue;
        }
        $row = [
            'account_id' => $accountId,
            'debit' => $debit,
            'credit' => $credit,
            'memo' => trim((string) ($ln['memo'] ?? '')),
        ];
        $partyType = strtolower(trim((string) ($ln['party_type'] ?? '')));
        $partyId = (int) ($ln['party_id'] ?? 0);
        if (in_array($partyType, ['customer', 'supplier'], true) && $partyId > 0) {
            $row['party_type'] = $partyType;
            $row['party_id'] = $partyId;
        }
        $resolved[] = $row;
    }

    $normalized = acc_journal_normalize_lines($resolved);
    $entryNo = acc_gl_next_auto_entry_no($pdo, $refType, $checkId);
    $uid = (int) (current_user()['id'] ?? 0) ?: null;

    $pdo->prepare(
        "INSERT INTO acc_journal_entry (entry_no, entry_date, description_ar, status, ref_type, ref_id, source, created_by)
         VALUES (?,?,?,'posted',?,?,'auto',?)"
    )->execute([
        $entryNo,
        $entryDate,
        $description !== '' ? $description : null,
        $refType,
        $checkId,
        $uid,
    ]);
    $journalId = (int) $pdo->lastInsertId();
    acc_journal_replace_lines($pdo, $journalId, $normalized['lines']);
    acc_journal_party_ledger_sync($pdo, $journalId, true);

    return $journalId;
}

/**
 * @return list<array{rule?:string, account_id?:int, debit:float, credit:float, memo?:string, party_type?:string, party_id?:int}>
 */
function fin_checks_manage_build_endorse_lines(
    PDO $pdo,
    array $check,
    string $targetPartyType,
    int $targetPartyId
): array {
    require_once app_path('includes/acc_gl.php');

    $amount = round((float) ($check['check_amount'] ?? 0), 6);
    if ($amount <= 0) {
        throw new RuntimeException('قيمة الشيك غير صالحة.');
    }

    $voucherType = (string) ($check['voucher_type'] ?? '');
    $origPartyType = (string) ($check['party_type'] ?? '');
    $origPartyId = (int) ($check['party_id'] ?? 0);
    $targetPartyType = strtolower(trim($targetPartyType));
    if ($targetPartyType !== 'supplier') {
        throw new RuntimeException('التجيير للموردين فقط.');
    }
    if ($targetPartyId < 1) {
        throw new RuntimeException('اختر المورد المُجيَّر إليه.');
    }

    $targetMemo = fin_checks_manage_party_memo($pdo, 'supplier', $targetPartyId);
    $checksFundId = acc_gl_checks_fund_account_id($pdo);

    if ($voucherType === 'receipt') {
        if ($checksFundId < 1) {
            throw new RuntimeException('حساب صندوق الشيكات غير مهيأ.');
        }

        return [
            [
                'rule' => 'ap_suppliers',
                'debit' => $amount,
                'credit' => 0,
                'memo' => $targetMemo,
                'party_type' => 'supplier',
                'party_id' => $targetPartyId,
            ],
            [
                'account_id' => $checksFundId,
                'debit' => 0,
                'credit' => $amount,
                'memo' => $targetMemo,
            ],
        ];
    }

    if ($origPartyType !== 'supplier' || $origPartyId < 1) {
        throw new RuntimeException('تجيير شيك صادر للموردين فقط — سند الصرف الأصلي يجب أن يكون لمورد.');
    }
    if ($targetPartyId === $origPartyId) {
        throw new RuntimeException('اختر مورداً مختلفاً عن مورد السند الأصلي.');
    }
    $origMemo = fin_checks_manage_party_memo($pdo, 'supplier', $origPartyId);

    return [
        [
            'rule' => 'ap_suppliers',
            'debit' => $amount,
            'credit' => 0,
            'memo' => $targetMemo,
            'party_type' => 'supplier',
            'party_id' => $targetPartyId,
        ],
        [
            'rule' => 'ap_suppliers',
            'debit' => 0,
            'credit' => $amount,
            'memo' => $origMemo,
            'party_type' => 'supplier',
            'party_id' => $origPartyId,
        ],
    ];
}

/**
 * @return array{ok:bool, journal_id:int, message:string}
 */
function fin_checks_manage_endorse(
    PDO $pdo,
    int $checkId,
    string $targetPartyType,
    int $targetPartyId,
    string $actionDate,
    string $notes
): array {
    fin_checks_manage_ensure_schema($pdo);
    $check = fin_checks_manage_load_check($pdo, $checkId);
    if (!$check) {
        throw new RuntimeException('الشيك غير موجود.');
    }
    fin_checks_manage_assert_actionable($check);

    $actionIso = parse_date_to_iso($actionDate);
    if ($actionIso === null) {
        throw new RuntimeException('تاريخ التجيير غير صالح.');
    }

    $targetPartyType = 'supplier';
    $targetPartyId = (int) $targetPartyId;
    $notes = trim($notes);

    require_once app_path('includes/fin_voucher.php');
    if (fin_voucher_party_name($pdo, 'supplier', $targetPartyId) === '') {
        throw new RuntimeException('المورد المختار غير موجود.');
    }

    $checkNo = trim((string) ($check['check_no'] ?? ''));
    $targetName = fin_voucher_party_name($pdo, 'supplier', $targetPartyId);
    $desc = 'تجيير شيك'
        . ($checkNo !== '' ? ' ' . $checkNo : '')
        . ' — ' . (string) ($check['voucher_no'] ?? '')
        . ' — إلى ' . $targetName;
    if ($notes !== '') {
        $desc .= ' — ' . $notes;
    }

    $lines = fin_checks_manage_build_endorse_lines($pdo, $check, $targetPartyType, $targetPartyId);
    $journalId = fin_checks_manage_post_gl_with_parties(
        $pdo,
        'fin_check_endorse',
        $checkId,
        $actionIso,
        $desc,
        $lines
    );

    $uid = (int) (current_user()['id'] ?? 0) ?: null;
    $clearUndo = fin_checks_manage_sql_clear_undo_flags($pdo);
    $pdo->prepare(
        "UPDATE fin_voucher_check
         SET lifecycle_status = 'endorsed', action_date = ?, endorse_notes = ?,
             endorsed_party_type = ?, endorsed_party_id = ?,
             action_journal_id = ?, action_at = NOW(), action_by = ?{$clearUndo}
         WHERE id = ? AND lifecycle_status = 'pending'"
    )->execute([
        $actionIso,
        $notes !== '' ? $notes : null,
        $targetPartyType,
        $targetPartyId,
        $journalId,
        $uid,
        $checkId,
    ]);

    fin_checks_manage_post_party_endorse($pdo, $check, $actionIso, $targetPartyId, $journalId);

    return [
        'ok' => true,
        'journal_id' => $journalId,
        'message' => 'تم الترحيل — تجيير الشيك (قيد محاسبي).',
    ];
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

function fin_checks_manage_post_party_endorse(
    PDO $pdo,
    array $check,
    string $actionDate,
    int $targetSupplierId,
    int $journalId = 0
): void {
    $checkId = (int) ($check['id'] ?? $check['check_id'] ?? 0);
    $amount = round((float) ($check['check_amount'] ?? 0), 6);
    if ($checkId < 1 || $targetSupplierId < 1 || $amount <= 0) {
        return;
    }

    require_once app_path('includes/crm_supplier_ledger.php');
    require_once app_path('includes/fin_voucher.php');
    crm_supplier_ledger_ensure_schema($pdo);
    fin_checks_manage_ensure_supplier_check_endorse_txn($pdo);
    if (crm_supplier_ledger_exists($pdo, 'check_endorse', $checkId)) {
        if ($journalId > 0) {
            crm_supplier_ledger_delete_journal_voucher_by_journal($pdo, $journalId);
        }

        return;
    }

    $checkNo = trim((string) ($check['check_no'] ?? ''));
    $voucherNo = (string) ($check['voucher_no'] ?? '');
    $memo = 'تجيير شيك';
    if ($checkNo !== '') {
        $memo .= ' ' . $checkNo;
    }
    if ($voucherNo !== '') {
        $memo .= ' — سند ' . $voucherNo;
    }

    crm_supplier_ledger_insert(
        $pdo,
        $targetSupplierId,
        $actionDate,
        'check_endorse',
        $checkId,
        $checkNo !== '' ? $checkNo : ('CHK-' . $checkId),
        'check',
        $amount,
        0.0,
        $memo
    );

    if ($journalId > 0) {
        crm_supplier_ledger_delete_journal_voucher_by_journal($pdo, $journalId);
    }
}

function fin_checks_manage_delete_party_endorse(PDO $pdo, int $checkId): void
{
    if ($checkId < 1) {
        return;
    }
    require_once app_path('includes/crm_supplier_ledger.php');
    if (crm_supplier_ledger_has_table($pdo)) {
        $pdo->prepare("DELETE FROM crm_supplier_ledger WHERE txn_type = 'check_endorse' AND ref_id = ?")
            ->execute([$checkId]);
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

function fin_checks_manage_ensure_supplier_check_endorse_txn(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    require_once app_path('includes/crm_supplier_ledger.php');
    if (!crm_supplier_ledger_has_table($pdo)) {
        return;
    }
    if (fin_checks_manage_has_supplier_check_endorse_txn($pdo)) {
        $done = true;

        return;
    }
    try {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/175_fin_check_endorse_supplier_ledger.sql');
    } catch (Throwable $e) {
        // ignore
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
        // شيك صادر: الأثر المحاسبي (بنك + الجهة) عند الصرف فقط.
        $voucherId = (int) ($check['voucher_id'] ?? 0);
        if ($voucherId > 0 && acc_gl_ref_exists($pdo, 'cash_payment', $voucherId)) {
            // سندات قديمة رُحّل عليها قيد البنك مسبقاً — تسجيل الحالة فقط.
            $journalId = 0;
        } else {
            $journalId = fin_checks_manage_post_outgoing_clear_accounting(
                $pdo,
                $check,
                $accountId,
                $actionIso,
                $desc,
                $partyMemo,
                $amount
            );
        }
    }

    $uid = (int) (current_user()['id'] ?? 0) ?: null;
    $clearUndo = fin_checks_manage_sql_clear_undo_flags($pdo);
    $pdo->prepare(
        "UPDATE fin_voucher_check
         SET lifecycle_status = 'cleared', action_date = ?, action_account_id = ?,
             action_journal_id = ?, action_at = NOW(), action_by = ?{$clearUndo}
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
            . ($journalId > 0 ? ' (قيد محاسبي على البنك والجهة)' : ''),
    ];
}

/**
 * قيد صرف شيك صادر + كشف الجهة (عميل/مورد) + سلفة/راتب إن وُجدت.
 */
function fin_checks_manage_post_outgoing_clear_accounting(
    PDO $pdo,
    array $check,
    int $bankAccountId,
    string $actionIso,
    string $desc,
    string $partyMemo,
    float $amount
): int {
    require_once app_path('includes/acc_gl.php');
    require_once app_path('includes/fin_voucher.php');
    require_once app_path('includes/fin_voucher_post.php');

    $voucherId = (int) ($check['voucher_id'] ?? 0);
    $partyType = (string) ($check['party_type'] ?? '');
    $partyId = (int) ($check['party_id'] ?? 0);
    $checkId = (int) ($check['id'] ?? 0);

    if ($bankAccountId < 1 || !acc_gl_is_valid_leaf_account($pdo, $bankAccountId)) {
        throw new RuntimeException('اختر حساب بنك أو صندوق صالحاً.');
    }

    $row = $voucherId > 0 ? fin_voucher_load($pdo, $voucherId, 'payment') : null;
    if ($row === null) {
        $row = [
            'party_type' => $partyType,
            'party_id' => $partyId,
            'cash_account_id' => $bankAccountId,
            'pay_method' => 'check',
            'hr_advance_id' => 0,
            'hr_salary_id' => 0,
            'offset_account_id' => 0,
        ];
    }

    // كشف العميل/المورد عند الصرف (إن لم يكن مُرحّلاً مسبقاً).
    if ($voucherId > 0 && in_array($partyType, ['supplier', 'customer'], true)) {
        require_once app_path('includes/crm_supplier_ledger.php');
        require_once app_path('includes/crm_customer_ledger.php');
        if ($partyType === 'supplier') {
            $ledger = crm_supplier_ledger_post_cash_payment_by_id($pdo, $voucherId, true);
        } else {
            $ledger = crm_ledger_post_cash_payment_by_id($pdo, $voucherId, true);
        }
        if (!$ledger['ok'] && empty($ledger['skipped'])) {
            throw new RuntimeException((string) ($ledger['error'] ?? 'تعذر ترحيل كشف الجهة.'));
        }
    }

    $lines = [];
    if ($partyType === 'supplier') {
        $lines[] = [
            'rule' => 'ap_suppliers',
            'debit' => $amount,
            'credit' => 0,
            'memo' => $partyMemo,
            'party_type' => 'supplier',
            'party_id' => $partyId,
        ];
    } elseif ($partyType === 'customer') {
        // مطابق لـ acc_gl_post_cash_payment: دائن على الذمم عند الصرف للعميل.
        $lines[] = [
            'rule' => 'ar_customers',
            'debit' => 0,
            'credit' => $amount,
            'memo' => $partyMemo,
            'party_type' => 'customer',
            'party_id' => $partyId,
        ];
    } elseif ($partyType === 'employee') {
        $empLine = acc_gl_payment_employee_debit_line($pdo, $row, $amount, $partyMemo);
        $lines[] = $empLine;
    } elseif ($partyType === 'account') {
        $offsetId = (int) ($row['offset_account_id'] ?? 0);
        if ($offsetId < 1) {
            throw new RuntimeException('حساب الصرف المُدين غير محدد في سند الصرف.');
        }
        $lines[] = [
            'account_id' => $offsetId,
            'debit' => $amount,
            'credit' => 0,
            'memo' => $partyMemo,
        ];
    } else {
        $lines[] = ['rule' => 'misc_expense', 'debit' => $amount, 'credit' => 0, 'memo' => $partyMemo];
    }
    $lines[] = [
        'account_id' => $bankAccountId,
        'debit' => 0,
        'credit' => $amount,
        'memo' => $partyMemo,
    ];

    $journalId = fin_checks_manage_post_gl_with_parties(
        $pdo,
        'fin_check_clear',
        $checkId,
        $actionIso,
        $desc,
        $lines
    );

    if ($voucherId > 0) {
        if (fin_voucher_has_column($pdo, 'hr_advance_id')) {
            require_once app_path('includes/hr_employee_advance.php');
            $advId = (int) ($row['hr_advance_id'] ?? 0);
            if ($advId > 0) {
                hr_employee_advance_mark_disbursed($pdo, $advId, $voucherId);
            }
        }
        if (fin_voucher_has_column($pdo, 'hr_salary_id')) {
            require_once app_path('includes/hr_salary.php');
            $salId = (int) ($row['hr_salary_id'] ?? 0);
            if ($salId > 0) {
                hr_salary_mark_disbursed($pdo, $salId, $voucherId);
            }
        }
    }

    return $journalId;
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
    $journalId = 0;
    if ($voucherType === 'receipt') {
        $checksFundId = acc_gl_checks_fund_account_id($pdo);
        if ($checksFundId < 1) {
            throw new RuntimeException('حساب صندوق الشيكات غير مهيأ.');
        }
        $lines = [
            ['rule' => 'ar_customers', 'debit' => $amount, 'credit' => 0, 'memo' => $partyMemo],
            ['account_id' => $checksFundId, 'debit' => 0, 'credit' => $amount, 'memo' => $partyMemo],
        ];
        $journalId = fin_checks_manage_post_gl($pdo, 'fin_check_return', $checkId, $actionIso, $desc, $lines);
        fin_checks_manage_post_party_return($pdo, $check, $actionIso, $reason);
    } else {
        $voucherId = (int) ($check['voucher_id'] ?? 0);
        // شيكات قديمة: القيد على البنك كان عند ترحيل السند — عكس عند الإرجاع.
        // الشيكات الجديدة: لا أثر محاسبي قبل الصرف — إرجاع الحالة فقط.
        if ($voucherId > 0 && acc_gl_ref_exists($pdo, 'cash_payment', $voucherId)) {
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
            $journalId = fin_checks_manage_post_gl($pdo, 'fin_check_return', $checkId, $actionIso, $desc, $lines);
            fin_checks_manage_post_party_return($pdo, $check, $actionIso, $reason);
        }
    }

    $uid = (int) (current_user()['id'] ?? 0) ?: null;
    $clearUndo = fin_checks_manage_sql_clear_undo_flags($pdo);
    $pdo->prepare(
        "UPDATE fin_voucher_check
         SET lifecycle_status = 'returned', action_date = ?, return_reason = ?,
             action_journal_id = ?, action_at = NOW(), action_by = ?{$clearUndo}
         WHERE id = ? AND lifecycle_status = 'pending'"
    )->execute([
        $actionIso,
        $reason,
        $journalId > 0 ? $journalId : null,
        $uid,
        $checkId,
    ]);

    return [
        'ok' => true,
        'journal_id' => $journalId,
        'message' => 'تم الترحيل — إرجاع' . ($journalId > 0 ? ' (قيد عكسي)' : ''),
    ];
}

function fin_checks_manage_delete_party_return(PDO $pdo, int $checkId): void
{
    if ($checkId < 1) {
        return;
    }

    require_once app_path('includes/crm_customer_ledger.php');
    require_once app_path('includes/crm_supplier_ledger.php');

    if (crm_ledger_has_table($pdo)) {
        $pdo->prepare("DELETE FROM crm_customer_ledger WHERE txn_type = 'check_return' AND ref_id = ?")
            ->execute([$checkId]);
    }
    if (crm_supplier_ledger_has_table($pdo)) {
        $pdo->prepare("DELETE FROM crm_supplier_ledger WHERE txn_type = 'check_return' AND ref_id = ?")
            ->execute([$checkId]);
    }
}

/**
 * إلغاء أثر كشف الجهة / سلفة / راتب الذي أُنشئ عند صرف شيك صادر (التدفق الجديد).
 */
function fin_checks_manage_unpost_outgoing_clear_party(PDO $pdo, array $check): void
{
    $voucherId = (int) ($check['voucher_id'] ?? 0);
    if ($voucherId < 1) {
        return;
    }

    $partyType = (string) ($check['party_type'] ?? '');
    require_once app_path('includes/crm_customer_ledger.php');
    require_once app_path('includes/crm_supplier_ledger.php');
    require_once app_path('includes/fin_voucher.php');

    if ($partyType === 'supplier') {
        crm_supplier_ledger_unpost_cash_payment($pdo, $voucherId);
    } elseif ($partyType === 'customer') {
        crm_ledger_unpost_cash_payment($pdo, $voucherId);
    }

    if (fin_voucher_has_column($pdo, 'hr_advance_id')) {
        require_once app_path('includes/hr_employee_advance.php');
        hr_employee_advance_clear_disbursement_by_voucher($pdo, $voucherId);
    }
    if (fin_voucher_has_column($pdo, 'hr_salary_id')) {
        require_once app_path('includes/hr_salary.php');
        hr_salary_clear_disbursement_by_voucher($pdo, $voucherId);
    }
}

/**
 * إلغاء صرف/تحصيل أو إرجاع شيك — حذف القيد وإعادة الشيك إلى «قيد».
 *
 * @return array{ok:bool, message:string}
 */
function fin_checks_manage_undo(PDO $pdo, int $checkId): array
{
    fin_checks_manage_ensure_schema($pdo);
    $check = fin_checks_manage_load_check($pdo, $checkId);
    if (!$check) {
        throw new RuntimeException('الشيك غير موجود.');
    }

    $status = (string) ($check['lifecycle_status'] ?? 'pending');
    if ($status === 'pending') {
        if (trim((string) ($check['action_undo_at'] ?? '')) !== '') {
            throw new RuntimeException('تم إلغاء إجراء هذا الشيك مسبقاً.');
        }
        throw new RuntimeException('الشيك لم يُصرَف ولم يُرجَع بعد.');
    }
    if (!in_array($status, ['cleared', 'returned', 'endorsed'], true)) {
        throw new RuntimeException('حالة الشيك غير مدعومة.');
    }

    require_once app_path('includes/acc_gl.php');

    if ($status === 'cleared') {
        $gl = acc_gl_unpost_ref($pdo, 'fin_check_clear', $checkId);
        if (!$gl['ok']) {
            throw new RuntimeException($gl['error'] ?? 'تعذر إلغاء قيد الصرف.');
        }
        if ($gl['skipped']) {
            $journalId = (int) ($check['action_journal_id'] ?? 0);
            if ($journalId < 1) {
                $st = $pdo->prepare(
                    "SELECT id FROM acc_journal_entry
                     WHERE ref_type = 'fin_check_clear' AND ref_id = ? LIMIT 1"
                );
                $st->execute([$checkId]);
                $journalId = (int) $st->fetchColumn();
            }
            if ($journalId > 0) {
                fin_checks_manage_unpost_journal($pdo, $journalId);
            }
        }
        // شيك صادر جديد: كشف الجهة وسُجلت عند الصرف — ألغِها مع إلغاء الصرف.
        $voucherType = (string) ($check['voucher_type'] ?? '');
        $voucherId = (int) ($check['voucher_id'] ?? 0);
        if ($voucherType === 'payment' && $voucherId > 0 && !acc_gl_ref_exists($pdo, 'cash_payment', $voucherId)) {
            fin_checks_manage_unpost_outgoing_clear_party($pdo, $check);
        }
        $message = 'تم إلغاء صرف/تحصيل الشيك وإعادته إلى «قيد».';
    } elseif ($status === 'returned') {
        $gl = acc_gl_unpost_ref($pdo, 'fin_check_return', $checkId);
        if (!$gl['ok']) {
            throw new RuntimeException($gl['error'] ?? 'تعذر إلغاء قيد الإرجاع.');
        }
        if ($gl['skipped']) {
            $journalId = (int) ($check['action_journal_id'] ?? 0);
            if ($journalId < 1) {
                $st = $pdo->prepare(
                    "SELECT id FROM acc_journal_entry
                     WHERE ref_type = 'fin_check_return' AND ref_id = ? LIMIT 1"
                );
                $st->execute([$checkId]);
                $journalId = (int) $st->fetchColumn();
            }
            if ($journalId > 0) {
                fin_checks_manage_unpost_journal($pdo, $journalId);
            }
        }
        $message = 'تم إلغاء إرجاع الشيك وإعادته إلى «قيد».';
    } else {
        $journalId = (int) ($check['action_journal_id'] ?? 0);
        if ($journalId < 1) {
            $st = $pdo->prepare(
                "SELECT id FROM acc_journal_entry
                 WHERE ref_type = 'fin_check_endorse' AND ref_id = ? AND source = 'auto' LIMIT 1"
            );
            $st->execute([$checkId]);
            $journalId = (int) $st->fetchColumn();
        }
        if ($journalId < 1) {
            throw new RuntimeException('قيد التجيير غير موجود.');
        }
        fin_checks_manage_unpost_journal($pdo, $journalId);
        $message = 'تم إلغاء تجيير الشيك وإعادته إلى «قيد».';
    }

    fin_checks_manage_apply_undo_state($pdo, $checkId, $status);

    return [
        'ok' => true,
        'message' => $message,
    ];
}
