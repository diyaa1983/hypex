<?php
declare(strict_types=1);

function crm_ledger_has_table(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $pdo->query('SELECT id FROM crm_customer_ledger LIMIT 1');
        $ok = true;
    } catch (Throwable $e) {
        $ok = false;
    }

    return $ok;
}

function crm_ledger_ensure_schema(PDO $pdo): bool
{
    if (!crm_ledger_has_table($pdo)) {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/008_crm_customer_ledger.sql');
    }
    if (crm_ledger_has_table($pdo)) {
        crm_ledger_ensure_payment_type_enum($pdo);
        crm_ledger_ensure_voucher_txn_types($pdo);
    }

    return crm_ledger_has_table($pdo);
}

/** إضافة cash_receipt / cash_payment لعمود txn_type عند الحاجة. */
function crm_ledger_ensure_voucher_txn_types(PDO $pdo): void
{
    static $done = false;
    if ($done || !crm_ledger_has_table($pdo)) {
        return;
    }
    try {
        $st = $pdo->query(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_customer_ledger' AND COLUMN_NAME = 'txn_type'"
        );
        $txnType = (string) ($st->fetchColumn() ?: '');
        if ($txnType !== '' && stripos($txnType, 'cash_payment') === false) {
            $pdo->exec(
                "ALTER TABLE crm_customer_ledger
                 MODIFY txn_type ENUM('sale_invoice','sale_return','cash_receipt','cash_payment') NOT NULL"
            );
        }
    } catch (Throwable $e) {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/054_crm_customer_ledger_voucher_txn.sql');
    }
    $done = true;
}

/** إضافة journal_voucher لعمود txn_type عند الحاجة. */
function crm_ledger_ensure_journal_voucher_txn(PDO $pdo): void
{
    static $done = false;
    if ($done || !crm_ledger_has_table($pdo)) {
        return;
    }
    try {
        $st = $pdo->query(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_customer_ledger' AND COLUMN_NAME = 'txn_type'"
        );
        $txnType = (string) ($st->fetchColumn() ?: '');
        if ($txnType !== '' && stripos($txnType, 'journal_voucher') === false) {
            $pdo->exec(
                "ALTER TABLE crm_customer_ledger
                 MODIFY txn_type ENUM('sale_invoice','sale_return','cash_receipt','cash_payment','journal_voucher') NOT NULL"
            );
        }
    } catch (Throwable $e) {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/159_acc_journal_line_party.sql');
    }
    $done = true;
}

/** إضافة قيمة check لعمود payment_type عند الحاجة. */
function crm_ledger_ensure_payment_type_enum(PDO $pdo): void
{
    static $done = false;
    if ($done || !crm_ledger_has_table($pdo)) {
        return;
    }
    try {
        $st = $pdo->query(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_customer_ledger' AND COLUMN_NAME = 'payment_type'"
        );
        $type = (string) ($st->fetchColumn() ?: '');
        if ($type !== '' && stripos($type, 'check') === false) {
            $pdo->exec(
                "ALTER TABLE crm_customer_ledger MODIFY payment_type ENUM('cash','credit','check') NOT NULL DEFAULT 'credit'"
            );
        }
        $pdo->exec(
            "UPDATE crm_customer_ledger SET payment_type = 'check'
             WHERE payment_type = 'cash'
               AND txn_type IN ('cash_receipt', 'cash_payment')
               AND memo LIKE '%شيك:%'"
        );
    } catch (Throwable $e) {
        // صلاحيات أو عمود معدّل مسبقاً
    }
    $done = true;
}

function crm_ledger_normalize_payment_type(string $paymentType): string
{
    $p = strtolower(trim($paymentType));
    if ($p === 'check' || $p === 'cheque' || $p === 'شيك') {
        return 'check';
    }
    if ($p === 'credit' || $p === 'ذمم') {
        return 'credit';
    }

    return 'cash';
}

function crm_ledger_exists(PDO $pdo, string $txnType, int $refId): bool
{
    if (!crm_ledger_has_table($pdo)) {
        return false;
    }
    $st = $pdo->prepare('SELECT id FROM crm_customer_ledger WHERE txn_type = ? AND ref_id = ? LIMIT 1');
    $st->execute([$txnType, $refId]);

    return (bool) $st->fetch();
}

function crm_ledger_insert(
    PDO $pdo,
    int $customerId,
    string $txnDate,
    string $txnType,
    int $refId,
    string $refNo,
    string $paymentType,
    float $debit,
    float $credit,
    string $memo
): void {
    $pdo->prepare(
        'INSERT INTO crm_customer_ledger (customer_id, txn_date, txn_type, ref_id, ref_no, payment_type, debit, credit, memo)
         VALUES (?,?,?,?,?,?,?,?,?)'
    )->execute([
        $customerId,
        $txnDate,
        $txnType,
        $refId,
        $refNo,
        crm_ledger_normalize_payment_type($paymentType),
        round(max(0, $debit), 6),
        round(max(0, $credit), 6),
        $memo !== '' ? $memo : null,
    ]);
}

/** رصيد العميل (مدين − دائن): موجب = عليه لنا. */
function crm_ledger_customer_balance(PDO $pdo, int $customerId): float
{
    if (!crm_ledger_has_table($pdo) || $customerId < 1) {
        return 0.0;
    }
    $st = $pdo->prepare(
        'SELECT COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) FROM crm_customer_ledger WHERE customer_id = ?'
    );
    $st->execute([$customerId]);

    return (float) $st->fetchColumn();
}

/** رصيد العميل حتى تاريخ محدد (شامل). */
function crm_ledger_customer_balance_as_of(PDO $pdo, int $customerId, string $asOfDate): float
{
    if (!crm_ledger_has_table($pdo) || $customerId < 1 || $asOfDate === '') {
        return 0.0;
    }
    $st = $pdo->prepare(
        'SELECT COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0)
         FROM crm_customer_ledger
         WHERE customer_id = ? AND txn_date <= ?'
    );
    $st->execute([$customerId, $asOfDate]);

    return (float) $st->fetchColumn();
}

function crm_ledger_sale_invoice_is_posted(PDO $pdo, int $invoiceId): bool
{
    return $invoiceId > 0 && crm_ledger_exists($pdo, 'sale_invoice', $invoiceId);
}

/**
 * ترحيل فاتورة بيع واحدة إلى حساب العميل.
 *
 * @return array{ok:bool, skipped:bool, error:?string}
 */
function crm_ledger_post_sale_invoice_by_id(PDO $pdo, int $invoiceId): array
{
    $out = ['ok' => false, 'skipped' => false, 'error' => null];

    if ($invoiceId < 1) {
        $out['error'] = 'معرّف الفاتورة غير صالح.';

        return $out;
    }

    if (!crm_ledger_ensure_schema($pdo)) {
        $out['error'] = 'جدول حركات العملاء غير موجود.';

        return $out;
    }

    if (crm_ledger_sale_invoice_is_posted($pdo, $invoiceId)) {
        $out['ok'] = true;
        $out['skipped'] = true;

        return $out;
    }

    $sql = 'SELECT id, invoice_no, invoice_date, customer_id, total, payment_type, status
            FROM sal_invoice WHERE id = ? LIMIT 1';
    try {
        $pdo->query('SELECT payment_type FROM sal_invoice LIMIT 1');
    } catch (Throwable $e) {
        $sql = 'SELECT id, invoice_no, invoice_date, customer_id, total, status
                FROM sal_invoice WHERE id = ? LIMIT 1';
    }

    $st = $pdo->prepare($sql);
    $st->execute([$invoiceId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $out['error'] = 'الفاتورة غير موجودة.';

        return $out;
    }

    if ((string) ($row['status'] ?? '') !== 'confirmed') {
        $out['error'] = 'لا يمكن ترحيل فاتورة غير مؤكدة.';

        return $out;
    }

    $total = (float) ($row['total'] ?? 0);
    if ($total <= 0) {
        require_once app_path('includes/inv_invoice_line_qty.php');
        inv_invoice_line_ensure_qty_extra($pdo);
        $stockSt = $pdo->prepare(
            'SELECT 1 FROM sal_invoice_line il WHERE il.invoice_id = ? AND '
            . inv_invoice_line_sql_stock_positive('il') . ' LIMIT 1'
        );
        $stockSt->execute([$invoiceId]);
        if ($stockSt->fetch()) {
            $out['ok'] = true;
            $out['skipped'] = true;

            return $out;
        }
        $out['error'] = 'إجمالي الفاتورة صفر — أدخل الكمية وسعر الوحدة لكل بند ثم احفظ الفاتورة قبل الترحيل.';

        return $out;
    }

    try {
        crm_ledger_post_sale_invoice(
            $pdo,
            $invoiceId,
            (string) $row['invoice_no'],
            (string) $row['invoice_date'],
            (int) $row['customer_id'],
            (string) ($row['payment_type'] ?? 'credit'),
            $total
        );
        $out['ok'] = true;

        return $out;
    } catch (Throwable $e) {
        $out['error'] = $e->getMessage();

        return $out;
    }
}

/**
 * @param list<int> $invoiceIds
 * @return array{posted:int, skipped:int, errors:list<string>}
 */
function crm_ledger_post_sale_invoices_by_ids(PDO $pdo, array $invoiceIds): array
{
    $result = ['posted' => 0, 'skipped' => 0, 'errors' => []];
    foreach ($invoiceIds as $rawId) {
        $id = (int) $rawId;
        if ($id < 1) {
            continue;
        }
        $one = crm_ledger_post_sale_invoice_by_id($pdo, $id);
        if ($one['skipped']) {
            $result['skipped']++;
        } elseif ($one['ok']) {
            $result['posted']++;
        } elseif ($one['error'] !== null) {
            $result['errors'][] = 'فاتورة #' . $id . ': ' . $one['error'];
        }
    }

    return $result;
}

/** تسجيل فاتورة بيع على حساب العميل (ذمم: مدين — يزيد ما على العميل). */
function crm_ledger_post_sale_invoice(
    PDO $pdo,
    int $invoiceId,
    string $invoiceNo,
    string $invoiceDate,
    int $customerId,
    string $paymentType,
    float $total
): void {
    if (!crm_ledger_ensure_schema($pdo)) {
        throw new RuntimeException('جدول حركات العملاء غير موجود.');
    }
    if ($total <= 0) {
        throw new RuntimeException('إجمالي الفاتورة غير صالح للترحيل.');
    }
    if ($customerId < 1) {
        throw new RuntimeException('الفاتورة غير مربوطة بعميل.');
    }
    if (crm_ledger_exists($pdo, 'sale_invoice', $invoiceId)) {
        return;
    }

    $pay = $paymentType === 'cash' ? 'cash' : 'credit';
    $debit = 0.0;
    $credit = 0.0;
    $memo = 'فاتورة بيع ' . $invoiceNo;

    if ($pay === 'credit') {
        $debit = $total;
        $memo .= ' — ذمم';
    } else {
        $debit = $total;
        $credit = $total;
        $memo .= ' — نقدي';
    }

    crm_ledger_insert($pdo, $customerId, $invoiceDate, 'sale_invoice', $invoiceId, $invoiceNo, $pay, $debit, $credit, $memo);
}

/** هل القيد نقدي بمدين ودائن متساويين (لا أثر صافٍ على الذمة في الدفتر الخام). */
function crm_ledger_is_cash_balanced_entry(array $row): bool
{
    $eps = 0.000001;
    $debit = (float) ($row['debit'] ?? 0);
    $credit = (float) ($row['credit'] ?? 0);
    $pay = strtolower(trim((string) ($row['payment_type'] ?? '')));

    return $pay === 'cash' && $debit > $eps && abs($debit - $credit) < $eps;
}

/**
 * فاتورة بيع نقدية في الدفتر (مدين=دائن) → تحويلها لذمم (مدين فقط) عند الإرجاع أو التصحيح.
 */
function crm_ledger_convert_cash_sale_invoice_to_credit(PDO $pdo, int $invoiceId): bool
{
    if ($invoiceId < 1 || !crm_ledger_has_table($pdo)) {
        return false;
    }

    $st = $pdo->prepare(
        "SELECT id, debit, credit, payment_type, ref_no
         FROM crm_customer_ledger
         WHERE txn_type = 'sale_invoice' AND ref_id = ?
         LIMIT 1"
    );
    $st->execute([$invoiceId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || !crm_ledger_is_cash_balanced_entry($row)) {
        return false;
    }

    $debit = (float) ($row['debit'] ?? 0);
    $refNo = (string) ($row['ref_no'] ?? '');
    $memo = 'فاتورة بيع ' . $refNo . ' — ذمم (تصحيح بعد إرجاع)';

    $pdo->prepare(
        'UPDATE crm_customer_ledger
         SET payment_type = ?, credit = 0, memo = ?
         WHERE id = ?'
    )->execute(['credit', $memo, (int) ($row['id'] ?? 0)]);

    return $debit > 0;
}

/** حذف قيد المرتجع من دفتر العميل (مرتجع فاتورة نقدية لا يُثبَّت على الذمة). */
function crm_ledger_delete_sale_return_customer_row(PDO $pdo, int $returnId): void
{
    if ($returnId < 1 || !crm_ledger_has_table($pdo)) {
        return;
    }
    $pdo->prepare("DELETE FROM crm_customer_ledger WHERE txn_type = 'sale_return' AND ref_id = ?")
        ->execute([$returnId]);
}

function sal_invoice_payment_type_is_cash(PDO $pdo, int $invoiceId): bool
{
    if ($invoiceId < 1) {
        return false;
    }
    try {
        $st = $pdo->prepare('SELECT payment_type FROM sal_invoice WHERE id = ? LIMIT 1');
        $st->execute([$invoiceId]);
        $v = strtolower(trim((string) ($st->fetchColumn() ?: '')));
    } catch (Throwable $e) {
        return false;
    }

    return $v === 'cash';
}

/** قيد فاتورة البيع على دفتر العميل = ذمة صافية (مدين بدون دائن موازٍ). */
function crm_ledger_sale_invoice_ledger_is_pure_receivable(PDO $pdo, int $invoiceId): bool
{
    if ($invoiceId < 1 || !crm_ledger_has_table($pdo)) {
        return false;
    }
    $st = $pdo->prepare(
        "SELECT debit, credit FROM crm_customer_ledger
         WHERE txn_type = 'sale_invoice' AND ref_id = ?
         LIMIT 1"
    );
    $st->execute([$invoiceId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    $eps = 0.000001;
    $d = (float) ($row['debit'] ?? 0);
    $c = (float) ($row['credit'] ?? 0);

    return $d > $eps && $c < $eps;
}

/** تصحيح قيود مرتجعات فواتير نقدية لعميل: حذف قيد المرتجع من الذمة وتحويل الفاتورة إلى ذمم. */
function crm_ledger_repair_customer_cash_sale_ledger(PDO $pdo, int $customerId): void
{
    if ($customerId < 1 || !crm_ledger_has_table($pdo)) {
        return;
    }

    $st = $pdo->prepare(
        "SELECT DISTINCT l.ref_id AS return_id
         FROM crm_customer_ledger l
         INNER JOIN sal_return r ON r.id = l.ref_id
         INNER JOIN sal_invoice i ON i.id = r.invoice_id
         WHERE l.customer_id = ?
           AND l.txn_type = 'sale_return'
           AND i.payment_type = 'cash'"
    );
    try {
        $st->execute([$customerId]);
    } catch (Throwable $e) {
        return;
    }

    $returnIds = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $seenInv = [];

    foreach ($returnIds as $rawId) {
        $returnId = (int) $rawId;
        if ($returnId < 1) {
            continue;
        }
        $invSt = $pdo->prepare('SELECT invoice_id FROM sal_return WHERE id = ? LIMIT 1');
        $invSt->execute([$returnId]);
        $invoiceId = (int) $invSt->fetchColumn();
        crm_ledger_delete_sale_return_customer_row($pdo, $returnId);
        if ($invoiceId > 0 && !isset($seenInv[$invoiceId])) {
            $seenInv[$invoiceId] = true;
            crm_ledger_convert_cash_sale_invoice_to_credit($pdo, $invoiceId);
        }
    }
}

/**
 * تسجيل مردود مبيعات على حساب العميل.
 * — فاتورة ذمم: دائن يخفّض الذمة.
 * — فاتورة نقدية: لا قيد مرتجع على العميل؛ يُحوَّل قيد الفاتورة إلى ذمم (مدين فقط) ليتوافق مع الدفعات.
 *
 * @throws RuntimeException
 */
function crm_ledger_post_sale_return(
    PDO $pdo,
    int $returnId,
    string $returnNo,
    string $returnDate,
    int $customerId,
    string $paymentType,
    float $total,
    string $invoiceNo
): void {
    if (!crm_ledger_ensure_schema($pdo)) {
        throw new RuntimeException('جدول حركات العملاء غير موجود.');
    }
    if ($customerId < 1) {
        throw new RuntimeException('العميل غير محدد على المرتجع.');
    }
    if ($total <= 0) {
        throw new RuntimeException('إجمالي المرتجع غير صالح.');
    }

    $invIdSt = $pdo->prepare('SELECT invoice_id FROM sal_return WHERE id = ? LIMIT 1');
    $invIdSt->execute([$returnId]);
    $invoiceId = (int) $invIdSt->fetchColumn();

    $pay = strtolower(trim($paymentType));

    if (crm_ledger_exists($pdo, 'sale_return', $returnId)) {
        if ($invoiceId > 0 && sal_invoice_payment_type_is_cash($pdo, $invoiceId)) {
            crm_ledger_delete_sale_return_customer_row($pdo, $returnId);
            crm_ledger_convert_cash_sale_invoice_to_credit($pdo, $invoiceId);
        }

        return;
    }

    if ($pay === 'cash') {
        if ($invoiceId > 0) {
            crm_ledger_convert_cash_sale_invoice_to_credit($pdo, $invoiceId);
        }

        return;
    }

    $memo = 'مردود ' . $returnNo . ' — فاتورة ' . $invoiceNo . ' — ذمم';

    crm_ledger_insert(
        $pdo,
        $customerId,
        $returnDate,
        'sale_return',
        $returnId,
        $returnNo,
        'credit',
        0.0,
        $total,
        $memo
    );
}

/** رصيد العميل قبل تاريخ معيّن (غير شامل ذلك اليوم). */
function crm_ledger_balance_before_date(PDO $pdo, int $customerId, string $beforeDate): float
{
    if (!crm_ledger_has_table($pdo) || $customerId < 1 || $beforeDate === '') {
        return 0.0;
    }
    $st = $pdo->prepare(
        'SELECT COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0)
         FROM crm_customer_ledger
         WHERE customer_id = ? AND txn_date < ?'
    );
    $st->execute([$customerId, $beforeDate]);

    return (float) $st->fetchColumn();
}

/** @return list<array<string, mixed>> */
function crm_ledger_fetch_customer(PDO $pdo, int $customerId, ?string $from = null, ?string $to = null): array
{
    if (!crm_ledger_has_table($pdo) || $customerId < 1) {
        return [];
    }

    $sql = 'SELECT l.id, l.txn_date, l.txn_type, l.ref_id, l.ref_no, l.payment_type, l.debit, l.credit, l.memo, l.created_at,
            (CASE WHEN l.txn_type = \'sale_return\' THEN
                (SELECT i.invoice_no FROM sal_return r INNER JOIN sal_invoice i ON i.id = r.invoice_id WHERE r.id = l.ref_id LIMIT 1)
             ELSE NULL END) AS source_invoice_no
            FROM crm_customer_ledger l WHERE l.customer_id = ?';
    $params = [$customerId];

    if ($from !== null && $from !== '') {
        $sql .= ' AND txn_date >= ?';
        $params[] = $from;
    }
    if ($to !== null && $to !== '') {
        $sql .= ' AND txn_date <= ?';
        $params[] = $to;
    }

    $sql .= ' ORDER BY txn_date ASC, id ASC';

    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll() ?: [];
}

/** @return array{invoices:int, returns:int} */
function crm_ledger_count_unposted(PDO $pdo): array
{
    $out = ['invoices' => 0, 'returns' => 0];
    if (!crm_ledger_ensure_schema($pdo)) {
        return $out;
    }

    try {
        require_once app_path('includes/sal_invoice_post.php');
        $notPosted = 'NOT ' . sal_invoice_sql_is_posted_expr('i');
        $out['invoices'] = (int) $pdo->query(
            "SELECT COUNT(*) FROM sal_invoice i WHERE i.status = 'confirmed' AND {$notPosted}"
        )->fetchColumn();
    } catch (Throwable $e) {
        $out['invoices'] = 0;
    }

    try {
        require_once app_path('includes/sal_return_post.php');
        $notPostedRet = 'NOT ' . sal_return_sql_is_posted_expr('r');
        $out['returns'] = (int) $pdo->query(
            "SELECT COUNT(*) FROM sal_return r WHERE r.status <> 'cancelled' AND {$notPostedRet}"
        )->fetchColumn();
    } catch (Throwable $e) {
        $out['returns'] = 0;
    }

    return $out;
}

/**
 * ترحيل فواتير البيع ومردودات المبيعات المؤكدة غير المسجّلة على حساب العميل.
 *
 * @return array{invoices:int, returns:int, errors:list<string>}
 */
function crm_ledger_batch_post_all(PDO $pdo): array
{
    $result = ['invoices' => 0, 'returns' => 0, 'errors' => []];

    if (!crm_ledger_ensure_schema($pdo)) {
        $result['errors'][] = 'جدول حركات العملاء غير موجود.';

        return $result;
    }

    $invoiceSql = 'SELECT id, invoice_no, invoice_date, customer_id, total, payment_type
                   FROM sal_invoice WHERE status = ? ORDER BY id ASC';
    try {
        $pdo->query('SELECT payment_type FROM sal_invoice LIMIT 1');
    } catch (Throwable $e) {
        $invoiceSql = 'SELECT id, invoice_no, invoice_date, customer_id, total FROM sal_invoice WHERE status = ? ORDER BY id ASC';
    }

    require_once app_path('includes/sal_invoice_post.php');

    try {
        $st = $pdo->prepare($invoiceSql);
        $st->execute(['confirmed']);
        while ($row = $st->fetch()) {
            $id = (int) $row['id'];
            $postedOne = sal_invoice_post_by_id($pdo, $id);
            if ($postedOne['skipped']) {
                continue;
            }
            if ($postedOne['ok']) {
                $result['invoices']++;
            } elseif ($postedOne['error'] !== null) {
                $result['errors'][] = 'فاتورة ' . ($row['invoice_no'] ?? $id) . ': ' . $postedOne['error'];
            }
        }
    } catch (Throwable $e) {
        $result['errors'][] = 'تعذر ترحيل الفواتير: ' . $e->getMessage();
    }

    $returnSql = 'SELECT r.id, r.return_no, r.return_date, r.customer_id, r.total,
                         i.invoice_no, i.payment_type
                  FROM sal_return r
                  INNER JOIN sal_invoice i ON i.id = r.invoice_id
                  WHERE r.status = ? ORDER BY r.id ASC';
    try {
        $pdo->query('SELECT id FROM sal_return LIMIT 1');
    } catch (Throwable $e) {
        return $result;
    }

    try {
        $pdo->query('SELECT payment_type FROM sal_invoice LIMIT 1');
    } catch (Throwable $e) {
        $returnSql = 'SELECT r.id, r.return_no, r.return_date, r.customer_id, r.total, i.invoice_no
                      FROM sal_return r
                      INNER JOIN sal_invoice i ON i.id = r.invoice_id
                      WHERE r.status = ? ORDER BY r.id ASC';
    }

    require_once app_path('includes/sal_return_post.php');

    try {
        $st = $pdo->prepare($returnSql);
        $st->execute(['confirmed']);
        while ($row = $st->fetch()) {
            $id = (int) $row['id'];
            $postedOne = sal_return_post_by_id($pdo, $id);
            if ($postedOne['skipped']) {
                continue;
            }
            if ($postedOne['ok']) {
                $result['returns']++;
            } elseif ($postedOne['error'] !== null) {
                $result['errors'][] = 'مردود ' . ($row['return_no'] ?? $id) . ': ' . $postedOne['error'];
            }
        }
    } catch (Throwable $e) {
        $result['errors'][] = 'تعذر ترحيل المردودات: ' . $e->getMessage();
    }

    return $result;
}

function crm_ledger_cash_receipt_is_posted(PDO $pdo, int $voucherId): bool
{
    return $voucherId > 0 && crm_ledger_exists($pdo, 'cash_receipt', $voucherId);
}

/** ترحيل سند قبض على حساب العميل (دائن — تخفيض الذمة). */
function crm_ledger_post_cash_receipt(
    PDO $pdo,
    int $voucherId,
    string $voucherNo,
    string $voucherDate,
    int $customerId,
    float $amount,
    string $memo,
    string $paymentType = 'cash'
): void {
    if ($voucherId < 1 || $customerId < 1 || $amount <= 0) {
        throw new RuntimeException('بيانات سند القبض غير صالحة للترحيل.');
    }
    if (!crm_ledger_ensure_schema($pdo)) {
        throw new RuntimeException('جدول حركات العملاء غير موجود.');
    }
    if (crm_ledger_cash_receipt_is_posted($pdo, $voucherId)) {
        return;
    }
    crm_ledger_insert(
        $pdo,
        $customerId,
        $voucherDate,
        'cash_receipt',
        $voucherId,
        $voucherNo,
        crm_ledger_normalize_payment_type($paymentType),
        0.0,
        $amount,
        $memo
    );
}

function crm_ledger_unpost_cash_receipt(PDO $pdo, int $voucherId): void
{
    if ($voucherId < 1 || !crm_ledger_has_table($pdo)) {
        return;
    }
    $pdo->prepare('DELETE FROM crm_customer_ledger WHERE txn_type = ? AND ref_id = ?')
        ->execute(['cash_receipt', $voucherId]);
}

/**
 * @return array{ok:bool, skipped:bool, error:?string}
 */
function crm_ledger_post_cash_receipt_by_id(PDO $pdo, int $voucherId): array
{
    $out = ['ok' => false, 'skipped' => false, 'error' => null];
    if ($voucherId < 1) {
        $out['error'] = 'معرّف السند غير صالح.';

        return $out;
    }
    if (crm_ledger_cash_receipt_is_posted($pdo, $voucherId)) {
        $out['ok'] = true;
        $out['skipped'] = true;

        return $out;
    }
    require_once app_path('includes/fin_voucher.php');
    $row = fin_voucher_load($pdo, $voucherId, 'receipt');
    if (!$row) {
        $out['error'] = 'سند القبض غير موجود.';

        return $out;
    }
    if ((string) ($row['party_type'] ?? '') !== 'customer' || (int) ($row['party_id'] ?? 0) < 1) {
        $out['error'] = 'يجب ربط السند بعميل قبل الترحيل.';

        return $out;
    }
    $amount = (float) ($row['amount'] ?? 0);
    if ($amount <= 0 && (string) ($row['pay_method'] ?? '') === 'check') {
        $amount = (float) ($row['check_amount'] ?? 0);
    }
    if ($amount <= 0) {
        $out['error'] = 'المبلغ غير صالح.';

        return $out;
    }
    $memo = trim((string) ($row['description'] ?? ''));
    $payMethod = (string) ($row['pay_method'] ?? 'cash');
    if ($payMethod === 'check') {
        $parts = [];
        $chk = trim((string) ($row['check_no'] ?? ''));
        $bank = trim((string) ($row['bank_name'] ?? ''));
        if ($chk !== '') {
            $parts[] = 'شيك: ' . $chk;
        }
        if ($bank !== '') {
            $parts[] = 'البنك: ' . $bank;
        }
        if ($parts) {
            $memo = trim($memo . ($memo !== '' ? ' — ' : '') . implode(' | ', $parts));
        }
    }
    try {
        crm_ledger_post_cash_receipt(
            $pdo,
            $voucherId,
            (string) $row['voucher_no'],
            (string) $row['voucher_date'],
            (int) $row['party_id'],
            $amount,
            $memo,
            $payMethod === 'check' ? 'check' : 'cash'
        );
        $out['ok'] = true;

        return $out;
    } catch (Throwable $e) {
        $out['error'] = $e->getMessage();

        return $out;
    }
}

function crm_ledger_cash_payment_is_posted(PDO $pdo, int $voucherId): bool
{
    return $voucherId > 0 && crm_ledger_exists($pdo, 'cash_payment', $voucherId);
}

/** ترحيل سند صرف على حساب العميل (دائن — تخفيض الذمة). */
function crm_ledger_post_cash_payment(
    PDO $pdo,
    int $voucherId,
    string $voucherNo,
    string $voucherDate,
    int $customerId,
    float $amount,
    string $memo,
    string $paymentType = 'cash'
): void {
    if ($voucherId < 1 || $customerId < 1 || $amount <= 0) {
        throw new RuntimeException('بيانات سند الصرف غير صالحة للترحيل.');
    }
    if (!crm_ledger_ensure_schema($pdo)) {
        throw new RuntimeException('جدول حركات العملاء غير موجود.');
    }
    if (crm_ledger_cash_payment_is_posted($pdo, $voucherId)) {
        return;
    }
    crm_ledger_insert(
        $pdo,
        $customerId,
        $voucherDate,
        'cash_payment',
        $voucherId,
        $voucherNo,
        crm_ledger_normalize_payment_type($paymentType),
        0.0,
        $amount,
        $memo
    );
}

function crm_ledger_unpost_cash_payment(PDO $pdo, int $voucherId): void
{
    if ($voucherId < 1 || !crm_ledger_has_table($pdo)) {
        return;
    }
    $pdo->prepare('DELETE FROM crm_customer_ledger WHERE txn_type = ? AND ref_id = ?')
        ->execute(['cash_payment', $voucherId]);
}

/**
 * @return array{ok:bool, skipped:bool, error:?string}
 */
function crm_ledger_post_cash_payment_by_id(PDO $pdo, int $voucherId): array
{
    $out = ['ok' => false, 'skipped' => false, 'error' => null];
    if ($voucherId < 1) {
        $out['error'] = 'معرّف السند غير صالح.';

        return $out;
    }
    if (crm_ledger_cash_payment_is_posted($pdo, $voucherId)) {
        $out['ok'] = true;
        $out['skipped'] = true;

        return $out;
    }
    require_once app_path('includes/fin_voucher.php');
    $row = fin_voucher_load($pdo, $voucherId, 'payment');
    if (!$row) {
        $out['error'] = 'سند الصرف غير موجود.';

        return $out;
    }
    if ((string) ($row['party_type'] ?? '') !== 'customer' || (int) ($row['party_id'] ?? 0) < 1) {
        $out['error'] = 'يجب ربط السند بعميل قبل الترحيل.';

        return $out;
    }
    $amount = (float) ($row['amount'] ?? 0);
    if ($amount <= 0 && (string) ($row['pay_method'] ?? '') === 'check') {
        $amount = (float) ($row['check_amount'] ?? 0);
    }
    if ($amount <= 0) {
        $out['error'] = 'المبلغ غير صالح.';

        return $out;
    }
    $memo = trim((string) ($row['description'] ?? ''));
    $payMethod = (string) ($row['pay_method'] ?? 'cash');
    if ($payMethod === 'check') {
        $parts = [];
        $chk = trim((string) ($row['check_no'] ?? ''));
        $bank = trim((string) ($row['bank_name'] ?? ''));
        if ($chk !== '') {
            $parts[] = 'شيك: ' . $chk;
        }
        if ($bank !== '') {
            $parts[] = 'البنك: ' . $bank;
        }
        if ($parts) {
            $memo = trim($memo . ($memo !== '' ? ' — ' : '') . implode(' | ', $parts));
        }
    }
    try {
        crm_ledger_post_cash_payment(
            $pdo,
            $voucherId,
            (string) $row['voucher_no'],
            (string) $row['voucher_date'],
            (int) $row['party_id'],
            $amount,
            $memo,
            $payMethod === 'check' ? 'check' : 'cash'
        );
        $out['ok'] = true;

        return $out;
    } catch (Throwable $e) {
        $out['error'] = $e->getMessage();

        return $out;
    }
}

function crm_ledger_post_journal_voucher_line(
    PDO $pdo,
    int $lineId,
    int $customerId,
    string $txnDate,
    string $refNo,
    float $debit,
    float $credit,
    string $memo
): void {
    if ($lineId < 1 || $customerId < 1 || !crm_ledger_ensure_schema($pdo)) {
        return;
    }
    crm_ledger_ensure_journal_voucher_txn($pdo);
    if (crm_ledger_exists($pdo, 'journal_voucher', $lineId)) {
        return;
    }
    crm_ledger_insert(
        $pdo,
        $customerId,
        $txnDate,
        'journal_voucher',
        $lineId,
        $refNo,
        'credit',
        $debit,
        $credit,
        $memo
    );
}

function crm_ledger_delete_journal_voucher_by_journal(PDO $pdo, int $journalId): void
{
    if ($journalId < 1 || !crm_ledger_has_table($pdo)) {
        return;
    }
    $pdo->prepare(
        'DELETE l FROM crm_customer_ledger l
         INNER JOIN acc_journal_line jl ON jl.id = l.ref_id AND l.txn_type = ?
         WHERE jl.journal_id = ?'
    )->execute(['journal_voucher', $journalId]);
}
