<?php
declare(strict_types=1);

require_once app_path('includes/fin_voucher.php');

function fin_voucher_checks_has_table(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT id FROM fin_voucher_check LIMIT 1');

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function fin_voucher_checks_ensure_table(PDO $pdo): bool
{
    if (fin_voucher_checks_has_table($pdo)) {
        return true;
    }
    require_once app_path('includes/sql_migration.php');
    sql_migration_run_file($pdo, 'database/migrations/056_fin_voucher_checks.sql');
    if (fin_voucher_checks_has_table($pdo)) {
        return true;
    }

    // محاولة احتياطية: إنشاء الجدول مباشرة بدون FK في حال فشل الترحيل لأي سبب
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS fin_voucher_check (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                voucher_id INT UNSIGNED NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                check_no VARCHAR(80) NULL,
                bank_name VARCHAR(120) NULL,
                check_amount DECIMAL(18,6) NOT NULL DEFAULT 0,
                due_date DATE NULL,
                notes VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_fvc_voucher (voucher_id),
                KEY idx_fvc_due (due_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } catch (Throwable $e) {
        // تجاهل الفشل — سيتم إعادة المحاولة في الاستدعاء التالي
    }

    return fin_voucher_checks_has_table($pdo);
}

/**
 * @return list<array{id:int, sort_order:int, check_no:string, bank_name:string, check_amount:float, due_date:string, notes:string}>
 */
function fin_voucher_checks_load(PDO $pdo, int $voucherId): array
{
    if ($voucherId < 1) {
        return [];
    }
    if (!fin_voucher_checks_ensure_table($pdo)) {
        return [];
    }
    try {
        $st = $pdo->prepare(
            'SELECT id, sort_order, check_no, bank_name, check_amount, due_date, notes
             FROM fin_voucher_check WHERE voucher_id = ? ORDER BY sort_order ASC, id ASC'
        );
        $st->execute([$voucherId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'id' => (int) ($row['id'] ?? 0),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'check_no' => (string) ($row['check_no'] ?? ''),
            'bank_name' => (string) ($row['bank_name'] ?? ''),
            'check_amount' => (float) ($row['check_amount'] ?? 0),
            'due_date' => (string) ($row['due_date'] ?? ''),
            'notes' => (string) ($row['notes'] ?? ''),
        ];
    }

    return $out;
}

/**
 * يستبدل جميع شيكات السند بالقائمة المعطاة (يحذف القديم ثم يدرج الجديد).
 *
 * @param list<array<string, mixed>> $checks كل عنصر يتضمن: check_no, bank_name, check_amount, due_date(YYYY-MM-DD), notes
 * @return float إجمالي قيم الشيكات
 */
function fin_voucher_checks_replace(PDO $pdo, int $voucherId, array $checks): float
{
    if ($voucherId < 1) {
        return 0.0;
    }
    if (!fin_voucher_checks_ensure_table($pdo)) {
        return 0.0;
    }

    try {
        $pdo->prepare('DELETE FROM fin_voucher_check WHERE voucher_id = ?')->execute([$voucherId]);
    } catch (Throwable $e) {
        return 0.0;
    }

    if ($checks === []) {
        return 0.0;
    }

    $ins = $pdo->prepare(
        'INSERT INTO fin_voucher_check
            (voucher_id, sort_order, check_no, bank_name, check_amount, due_date, notes)
         VALUES (?,?,?,?,?,?,?)'
    );
    $total = 0.0;
    $order = 0;
    foreach ($checks as $chk) {
        $amount = (float) ($chk['check_amount'] ?? 0);
        if ($amount <= 0) {
            continue;
        }
        $order++;
        $checkNo = trim((string) ($chk['check_no'] ?? ''));
        $bank = trim((string) ($chk['bank_name'] ?? ''));
        $due = trim((string) ($chk['due_date'] ?? ''));
        $notes = trim((string) ($chk['notes'] ?? ''));
        try {
            $ins->execute([
                $voucherId,
                $order,
                $checkNo !== '' ? $checkNo : null,
                $bank !== '' ? $bank : null,
                round($amount, 6),
                $due !== '' ? $due : null,
                $notes !== '' ? $notes : null,
            ]);
            $total += $amount;
        } catch (Throwable $e) {
            continue;
        }
    }

    return round($total, 6);
}

/** @param list<array<string, mixed>> $checks */
function fin_voucher_checks_total(array $checks): float
{
    $total = 0.0;
    foreach ($checks as $chk) {
        $amt = (float) ($chk['check_amount'] ?? 0);
        if ($amt > 0) {
            $total += $amt;
        }
    }

    return round($total, 6);
}

/** حساب صندوق الشيكات كما في ربط الترحيل / الاستاذ العام. */
function fin_voucher_checks_fund_account_id(PDO $pdo): int
{
    require_once app_path('includes/acc_gl.php');

    return acc_gl_checks_fund_account_id($pdo);
}

/**
 * رصيد حساب صندوق الشيكات من القيود المرحّلة (مدين − دائن) — مطابق للاستاذ العام.
 */
function fin_voucher_checks_fund_gl_balance(PDO $pdo): float
{
    $accountId = fin_voucher_checks_fund_account_id($pdo);
    if ($accountId < 1) {
        return 0.0;
    }
    require_once app_path('includes/acc_report.php');

    return (float) (acc_report_account_sums($pdo, $accountId)['balance'] ?? 0.0);
}

/** @deprecated استخدم fin_voucher_checks_fund_gl_balance */
function fin_voucher_checks_fund_total_amount(PDO $pdo): float
{
    return fin_voucher_checks_fund_gl_balance($pdo);
}

/**
 * شرط: سند قبض مُرحّل محاسبياً (قيد تلقائي cash_receipt) أو ترحيل ذمم قديم.
 */
function fin_voucher_checks_receipt_posted_sql(PDO $pdo, string $voucherAlias = 'v'): string
{
    require_once app_path('includes/acc_gl.php');
    if (acc_gl_journal_has_ref_columns($pdo)) {
        return "EXISTS (
            SELECT 1 FROM acc_journal_entry e
            WHERE e.ref_type = 'cash_receipt' AND e.ref_id = {$voucherAlias}.id AND e.status = 'posted'
        )";
    }

    return fin_voucher_has_column($pdo, 'is_posted')
        ? "{$voucherAlias}.is_posted = 1"
        : "EXISTS (SELECT 1 FROM crm_customer_ledger l WHERE l.txn_type = 'cash_receipt' AND l.ref_id = {$voucherAlias}.id)";
}

/** هل جدول الشيكات يدعم حالة دورة الحياة (قيد / صرف / إرجاع / تجيير)؟ */
function fin_voucher_checks_has_lifecycle(PDO $pdo): bool
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

/** شيكات ما زالت «قيد» ولم تُصرَف أو تُرجَع أو تُجيَّر — للتنبيهات والبريد. */
function fin_voucher_checks_sql_pending_only(PDO $pdo, string $checkAlias = 'c', string $voucherAlias = 'v'): string
{
    require_once app_path('includes/fin_voucher_schema.php');
    $parts = [];
    if (fin_voucher_checks_has_lifecycle($pdo)) {
        // NULL يُعامل كقيد (شيكات قُبلت قبل/بدون تعبئة الحالة)
        $parts[] = "({$checkAlias}.lifecycle_status = 'pending' OR {$checkAlias}.lifecycle_status IS NULL OR {$checkAlias}.lifecycle_status = '')";
    }
    if (fin_voucher_has_column($pdo, 'is_cancelled')) {
        $parts[] = "({$voucherAlias}.is_cancelled = 0 OR {$voucherAlias}.is_cancelled IS NULL)";
    }

    return $parts === [] ? '' : ' AND ' . implode(' AND ', $parts);
}

/**
 * إجمالي دائن صندوق الشيكات من القيود المرحّلة (تحصيل / نقل إلى الصندوق عبر سند قيد…).
 */
function fin_voucher_checks_fund_collected_credits(PDO $pdo): float
{
    $accountId = fin_voucher_checks_fund_account_id($pdo);
    if ($accountId < 1) {
        return 0.0;
    }
    try {
        $st = $pdo->prepare(
            'SELECT COALESCE(SUM(l.credit), 0)
             FROM acc_journal_line l
             INNER JOIN acc_journal_entry e ON e.id = l.journal_id AND e.status = \'posted\'
             WHERE l.account_id = ? AND l.credit > 0.000001'
        );
        $st->execute([$accountId]);

        return round((float) $st->fetchColumn(), 6);
    } catch (Throwable $e) {
        return 0.0;
    }
}

/**
 * يستبعد الشيكات المُغطّاة بمجموع التحصيل (FIFO حسب الاستحقاق).
 *
 * @param list<array<string, mixed>> $checks
 * @return list<array<string, mixed>>
 */
function fin_voucher_checks_apply_collection_fifo(array $checks, float $collectedCredits): array
{
    $remaining = round(max(0, $collectedCredits), 6);
    $pending = [];
    foreach ($checks as $chk) {
        $amt = round((float) ($chk['amount'] ?? 0), 6);
        if ($amt <= 0) {
            continue;
        }
        if ($remaining + 0.000001 >= $amt) {
            $remaining = round($remaining - $amt, 6);
            continue;
        }
        $pending[] = $chk;
    }

    return $pending;
}

/**
 * شيكات سندات قبض مرحّلة ولا تزال في صندوق الشيكات (قيد التحصيل).
 * يُستبعد ما صُرف أو أُرجِع أو جُيِّر أو أُلغي سنده — لا يظهر في تنبيهات الاستحقاق.
 *
 * @return list<array{
 *   check_id:int,
 *   check_no:string,
 *   bank_name:string,
 *   amount:float,
 *   due_date:string,
 *   days_until_due:int|null,
 *   urgency:string,
 *   urgency_label:string,
 *   voucher_id:int,
 *   voucher_no:string,
 *   voucher_date:string,
 *   party_name:string,
 *   notes:string,
 *   url:string
 * }>
 */
function fin_voucher_checks_pending_collection(PDO $pdo, ?string $today = null): array
{
    if (!fin_voucher_checks_ensure_table($pdo)) {
        return [];
    }
    require_once app_path('includes/fin_voucher_schema.php');
    if (!fin_voucher_has_table($pdo)) {
        return [];
    }

    $today = $today ?? date('Y-m-d');
    $postedExpr = fin_voucher_checks_receipt_posted_sql($pdo, 'v');
    $payFilter = fin_voucher_has_column($pdo, 'pay_method') ? " AND v.pay_method = 'check' " : '';
    $pendingOnly = fin_voucher_checks_sql_pending_only($pdo, 'c', 'v');
    $checksFundId = fin_voucher_checks_fund_account_id($pdo);
    $glDebitFilter = '';
    $params = [];
    if ($checksFundId > 0) {
        require_once app_path('includes/acc_gl.php');
        if (acc_gl_journal_has_ref_columns($pdo)) {
            $glDebitFilter = " AND EXISTS (
                SELECT 1 FROM acc_journal_entry e
                INNER JOIN acc_journal_line l ON l.journal_id = e.id
                WHERE e.ref_type = 'cash_receipt' AND e.ref_id = v.id AND e.status = 'posted'
                  AND l.account_id = ? AND l.debit > 0.000001
            ) ";
            $params[] = $checksFundId;
        }
    }

    $sql =
        "SELECT c.id AS check_id, c.check_no, c.bank_name, c.check_amount, c.due_date, c.notes,
                v.id AS voucher_id, v.voucher_no, v.voucher_date, v.amount AS voucher_amount,
                COALESCE(cust.name_ar, '') AS party_name
         FROM fin_voucher_check c
         INNER JOIN fin_voucher v ON v.id = c.voucher_id AND v.voucher_type = 'receipt'
         LEFT JOIN crm_customer cust ON v.party_type = 'customer' AND v.party_id = cust.id
         WHERE {$postedExpr}
           AND c.check_amount > 0
           {$payFilter}
           {$pendingOnly}
           {$glDebitFilter}
         ORDER BY c.due_date ASC, v.voucher_date ASC, c.id ASC";

    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $due = trim((string) ($row['due_date'] ?? ''));
        $daysUntil = null;
        $urgency = 'pending';
        $urgencyLabel = 'قيد التحصيل';
        if ($due !== '') {
            try {
                $dueDt = new DateTimeImmutable($due);
                $todayDt = new DateTimeImmutable($today);
                $daysUntil = (int) $todayDt->diff($dueDt)->format('%r%a');
                if ($daysUntil < 0) {
                    $urgency = 'overdue';
                    $urgencyLabel = 'متأخر';
                } elseif ($daysUntil === 0) {
                    $urgency = 'today';
                    $urgencyLabel = 'مستحق اليوم';
                } elseif ($daysUntil <= 7) {
                    $urgency = 'soon';
                    $urgencyLabel = 'قريب الاستحقاق';
                }
            } catch (Throwable $e) {
                // ignore invalid date
            }
        } else {
            $urgency = 'nodate';
            $urgencyLabel = 'بدون تاريخ استحقاق';
        }

        $vid = (int) ($row['voucher_id'] ?? 0);
        $out[] = [
            'check_id' => (int) ($row['check_id'] ?? 0),
            'check_no' => trim((string) ($row['check_no'] ?? '')),
            'bank_name' => trim((string) ($row['bank_name'] ?? '')),
            'amount' => (float) ($row['check_amount'] ?? 0),
            'due_date' => $due,
            'days_until_due' => $daysUntil,
            'urgency' => $urgency,
            'urgency_label' => $urgencyLabel,
            'voucher_id' => $vid,
            'voucher_no' => (string) ($row['voucher_no'] ?? ''),
            'voucher_date' => (string) ($row['voucher_date'] ?? ''),
            'party_name' => (string) ($row['party_name'] ?? ''),
            'notes' => (string) ($row['notes'] ?? ''),
            'voucher_amount' => (float) ($row['voucher_amount'] ?? 0),
            'url' => $vid > 0 ? app_url('index.php?r=cash_receipt&id=' . $vid) : '',
        ];
    }

    $collected = fin_voucher_checks_fund_collected_credits($pdo);

    return fin_voucher_checks_apply_collection_fifo($out, $collected);
}

/**
 * شرط: سند صرف مُرحّل (عمود is_posted أو قيد محاسبي cash_payment).
 */
function fin_voucher_checks_payment_posted_sql(PDO $pdo, string $voucherAlias = 'v'): string
{
    require_once app_path('includes/acc_gl.php');
    $parts = [];
    if (fin_voucher_has_column($pdo, 'is_posted')) {
        $parts[] = "{$voucherAlias}.is_posted = 1";
    }
    if (acc_gl_journal_has_ref_columns($pdo)) {
        $parts[] = "EXISTS (
            SELECT 1 FROM acc_journal_entry e
            WHERE e.ref_type = 'cash_payment' AND e.ref_id = {$voucherAlias}.id AND e.status = 'posted'
        )";
    }

    if ($parts === []) {
        return '1=1';
    }

    return '(' . implode(' OR ', $parts) . ')';
}

/**
 * شيكات صادرة (سندات صرف) قيد ولم تُصرَف بعد — لتنبيهات الاستحقاق بالبريد.
 *
 * @return list<array{
 *   check_id:int,
 *   check_no:string,
 *   bank_name:string,
 *   amount:float,
 *   due_date:string,
 *   days_until_due:?int,
 *   urgency:string,
 *   urgency_label:string,
 *   voucher_id:int,
 *   voucher_no:string,
 *   voucher_date:string,
 *   party_name:string,
 *   notes:string,
 *   url:string
 * }>
 */
function fin_voucher_checks_pending_disbursement(PDO $pdo, ?string $today = null): array
{
    if (!fin_voucher_checks_ensure_table($pdo)) {
        return [];
    }
    require_once app_path('includes/fin_voucher_schema.php');
    if (!fin_voucher_has_table($pdo)) {
        return [];
    }

    $today = $today ?? date('Y-m-d');
    $postedExpr = fin_voucher_checks_payment_posted_sql($pdo, 'v');
    $payFilter = fin_voucher_has_column($pdo, 'pay_method') ? " AND v.pay_method = 'check' " : '';
    $pendingOnly = fin_voucher_checks_sql_pending_only($pdo, 'c', 'v');

    $hasHr = false;
    $hasAcc = false;
    try {
        $pdo->query('SELECT id FROM hr_employee LIMIT 1');
        $hasHr = true;
    } catch (Throwable $e) {
        $hasHr = false;
    }
    try {
        $pdo->query('SELECT id FROM acc_account LIMIT 1');
        $hasAcc = true;
    } catch (Throwable $e) {
        $hasAcc = false;
    }

    $partyJoins = "
         LEFT JOIN crm_customer cust ON v.party_type = 'customer' AND cust.id = v.party_id
         LEFT JOIN crm_supplier sup ON v.party_type = 'supplier' AND sup.id = v.party_id";
    $partyCoalesce = "COALESCE(cust.name_ar, sup.name_ar, '')";
    if ($hasHr) {
        $partyJoins .= "
         LEFT JOIN hr_employee emp ON v.party_type = 'employee' AND emp.id = v.party_id";
        $partyCoalesce = "COALESCE(cust.name_ar, sup.name_ar, emp.name_ar, '')";
    }
    if ($hasAcc) {
        $partyJoins .= "
         LEFT JOIN acc_account acc_party ON v.party_type = 'account' AND acc_party.id = v.party_id";
        $partyCoalesce = $hasHr
            ? "COALESCE(cust.name_ar, sup.name_ar, emp.name_ar, acc_party.name_ar, '')"
            : "COALESCE(cust.name_ar, sup.name_ar, acc_party.name_ar, '')";
    }

    $sql =
        "SELECT c.id AS check_id, c.check_no, c.bank_name, c.check_amount, c.due_date, c.notes,
                v.id AS voucher_id, v.voucher_no, v.voucher_date, v.amount AS voucher_amount,
                {$partyCoalesce} AS party_name
         FROM fin_voucher_check c
         INNER JOIN fin_voucher v ON v.id = c.voucher_id AND v.voucher_type = 'payment'
         {$partyJoins}
         WHERE {$postedExpr}
           AND c.check_amount > 0.000001
           AND c.due_date IS NOT NULL
           {$payFilter}
           {$pendingOnly}
         ORDER BY c.due_date ASC, v.voucher_date ASC, c.id ASC";

    try {
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $due = trim((string) ($row['due_date'] ?? ''));
        $daysUntil = null;
        $urgency = 'pending';
        $urgencyLabel = 'قيد الصرف';
        if ($due !== '') {
            try {
                $dueDt = new DateTimeImmutable($due);
                $todayDt = new DateTimeImmutable($today);
                $daysUntil = (int) $todayDt->diff($dueDt)->format('%r%a');
                if ($daysUntil < 0) {
                    $urgency = 'overdue';
                    $urgencyLabel = 'متأخر';
                } elseif ($daysUntil === 0) {
                    $urgency = 'today';
                    $urgencyLabel = 'مستحق اليوم';
                } elseif ($daysUntil <= 7) {
                    $urgency = 'soon';
                    $urgencyLabel = 'قريب الاستحقاق';
                }
            } catch (Throwable $e) {
                // ignore
            }
        } else {
            $urgency = 'nodate';
            $urgencyLabel = 'بدون تاريخ صرف';
        }

        $vid = (int) ($row['voucher_id'] ?? 0);
        $out[] = [
            'check_id' => (int) ($row['check_id'] ?? 0),
            'check_no' => trim((string) ($row['check_no'] ?? '')),
            'bank_name' => trim((string) ($row['bank_name'] ?? '')),
            'amount' => (float) ($row['check_amount'] ?? 0),
            'due_date' => $due,
            'days_until_due' => $daysUntil,
            'urgency' => $urgency,
            'urgency_label' => $urgencyLabel,
            'voucher_id' => $vid,
            'voucher_no' => (string) ($row['voucher_no'] ?? ''),
            'voucher_date' => (string) ($row['voucher_date'] ?? ''),
            'party_name' => (string) ($row['party_name'] ?? ''),
            'notes' => (string) ($row['notes'] ?? ''),
            'voucher_amount' => (float) ($row['voucher_amount'] ?? 0),
            'url' => $vid > 0 ? app_url('index.php?r=cash_payment&id=' . $vid) : '',
        ];
    }

    return $out;
}

function fin_voucher_checks_from_post(array $post): array
{
    $raw = $post['checks'] ?? null;
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $row) {
        if (!is_array($row)) {
            continue;
        }
        $amount = (float) ($row['check_amount'] ?? 0);
        if ($amount <= 0) {
            continue;
        }
        $due = trim((string) ($row['due_date'] ?? ''));
        if ($due !== '') {
            $iso = parse_date_to_iso($due);
            $due = $iso ?? '';
        }
        $out[] = [
            'check_no' => trim((string) ($row['check_no'] ?? '')),
            'bank_name' => trim((string) ($row['bank_name'] ?? '')),
            'check_amount' => round($amount, 6),
            'due_date' => $due,
            'notes' => trim((string) ($row['notes'] ?? '')),
        ];
    }

    return $out;
}
