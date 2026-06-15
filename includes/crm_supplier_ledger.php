<?php
declare(strict_types=1);

function crm_supplier_ledger_has_table(PDO $pdo, bool $refresh = false): bool
{
    static $ok = null;
    if ($refresh) {
        $ok = null;
    }
    if ($ok !== null) {
        return $ok;
    }
    try {
        $pdo->query('SELECT id FROM crm_supplier_ledger LIMIT 1');
        $ok = true;
    } catch (Throwable $e) {
        $ok = false;
    }

    return $ok;
}

function crm_supplier_ledger_ensure_schema(PDO $pdo): bool
{
    if (!crm_supplier_ledger_has_table($pdo)) {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/015_purchase_returns_supplier_ledger.sql');
        if (!crm_supplier_ledger_has_table($pdo, true)) {
            return false;
        }
    }
    crm_supplier_ledger_ensure_voucher_enums($pdo);

    return true;
}

/** إضافة cash_payment و check لأعمدة كشف المورد عند الحاجة. */
function crm_supplier_ledger_ensure_voucher_enums(PDO $pdo): void
{
    static $done = false;
    if ($done || !crm_supplier_ledger_has_table($pdo)) {
        return;
    }
    try {
        $st = $pdo->query(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_supplier_ledger' AND COLUMN_NAME = 'txn_type'"
        );
        $txnType = (string) ($st->fetchColumn() ?: '');
        if ($txnType !== '' && stripos($txnType, 'cash_payment') === false) {
            $pdo->exec(
                "ALTER TABLE crm_supplier_ledger
                 MODIFY txn_type ENUM('purchase_invoice','purchase_return','cash_payment') NOT NULL"
            );
        }

        $st = $pdo->query(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_supplier_ledger' AND COLUMN_NAME = 'payment_type'"
        );
        $payType = (string) ($st->fetchColumn() ?: '');
        if ($payType !== '' && stripos($payType, 'check') === false) {
            $pdo->exec(
                "ALTER TABLE crm_supplier_ledger
                 MODIFY payment_type ENUM('cash','credit','check') NOT NULL DEFAULT 'credit'"
            );
        }
    } catch (Throwable $e) {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/053_crm_supplier_ledger_cash_payment.sql');
    }
    $done = true;
}

function crm_supplier_ledger_normalize_payment_type(string $paymentType): string
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

function crm_supplier_ledger_exists(PDO $pdo, string $txnType, int $refId): bool
{
    if (!crm_supplier_ledger_has_table($pdo)) {
        return false;
    }
    $st = $pdo->prepare('SELECT id FROM crm_supplier_ledger WHERE txn_type = ? AND ref_id = ? LIMIT 1');
    $st->execute([$txnType, $refId]);

    return (bool) $st->fetch();
}

function crm_supplier_ledger_insert(
    PDO $pdo,
    int $supplierId,
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
        'INSERT INTO crm_supplier_ledger (supplier_id, txn_date, txn_type, ref_id, ref_no, payment_type, debit, credit, memo)
         VALUES (?,?,?,?,?,?,?,?,?)'
    )->execute([
        $supplierId,
        $txnDate,
        $txnType,
        $refId,
        $refNo,
        crm_supplier_ledger_normalize_payment_type($paymentType),
        round(max(0, $debit), 6),
        round(max(0, $credit), 6),
        $memo !== '' ? $memo : null,
    ]);
}

/** رصيد المورد (دائن − مدين): موجب = لنا عليه / مطلوب سداده (ذمة الشركة تجاه المورد). */
function crm_supplier_ledger_balance(PDO $pdo, int $supplierId): float
{
    if (!crm_supplier_ledger_has_table($pdo) || $supplierId < 1) {
        return 0.0;
    }
    $st = $pdo->prepare(
        'SELECT COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) FROM crm_supplier_ledger WHERE supplier_id = ?'
    );
    $st->execute([$supplierId]);

    return (float) $st->fetchColumn();
}

function crm_supplier_ledger_purchase_invoice_is_posted(PDO $pdo, int $invoiceId): bool
{
    return $invoiceId > 0 && crm_supplier_ledger_exists($pdo, 'purchase_invoice', $invoiceId);
}

/**
 * تسجيل فاتورة شراء على ذمة المورد (عكس منطق العميل في البيع).
 *
 * @return array{ok:bool, skipped:bool, error:?string}
 */
function crm_supplier_ledger_post_purchase_invoice_by_id(PDO $pdo, int $invoiceId): array
{
    $out = ['ok' => false, 'skipped' => false, 'error' => null];

    if ($invoiceId < 1) {
        $out['error'] = 'معرّف الفاتورة غير صالح.';

        return $out;
    }

    if (!crm_supplier_ledger_ensure_schema($pdo)) {
        $out['error'] = 'جدول ذمة المورد غير موجود.';

        return $out;
    }

    if (crm_supplier_ledger_purchase_invoice_is_posted($pdo, $invoiceId)) {
        $out['ok'] = true;
        $out['skipped'] = true;

        return $out;
    }

    $hasPay = true;
    try {
        $pdo->query('SELECT payment_type FROM pur_invoice LIMIT 1');
    } catch (Throwable $e) {
        $hasPay = false;
    }

    $sql = $hasPay
        ? 'SELECT id, invoice_no, invoice_date, supplier_id, total, payment_type, status FROM pur_invoice WHERE id = ? LIMIT 1'
        : 'SELECT id, invoice_no, invoice_date, supplier_id, total, status FROM pur_invoice WHERE id = ? LIMIT 1';

    $st = $pdo->prepare($sql);
    $st->execute([$invoiceId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $out['error'] = 'فاتورة الشراء غير موجودة.';

        return $out;
    }

    if ((string) ($row['status'] ?? '') !== 'confirmed') {
        $out['error'] = 'لا يمكن ترحيل فاتورة غير مؤكدة.';

        return $out;
    }

    if ((int) ($row['supplier_id'] ?? 0) < 1) {
        $out['error'] = 'المورد غير صالح على فاتورة الشراء.';

        return $out;
    }

    $total = (float) ($row['total'] ?? 0);
    if ($total <= 0) {
        $out['error'] = 'إجمالي الفاتورة غير صالح للترحيل.';

        return $out;
    }

    try {
        crm_supplier_ledger_post_purchase_invoice(
            $pdo,
            $invoiceId,
            (string) $row['invoice_no'],
            (string) $row['invoice_date'],
            (int) $row['supplier_id'],
            $hasPay ? (string) ($row['payment_type'] ?? 'credit') : 'credit',
            $total
        );
    } catch (Throwable $e) {
        $out['error'] = $e->getMessage();

        return $out;
    }

    if (!crm_supplier_ledger_purchase_invoice_is_posted($pdo, $invoiceId)) {
        $out['error'] = 'لم يُسجَّل المبلغ على ذمة المورد. تحقق من المورد أو جدول crm_supplier_ledger.';

        return $out;
    }

    $out['ok'] = true;

    return $out;
}

/** تسجيل فاتورة شراء — ذمة: دائن المورد (يزيد ما علينا)؛ نقدي: مدين=دائن بلا أثر على الذمة. */
function crm_supplier_ledger_post_purchase_invoice(
    PDO $pdo,
    int $invoiceId,
    string $invoiceNo,
    string $invoiceDate,
    int $supplierId,
    string $paymentType,
    float $total
): void {
    if (!crm_supplier_ledger_ensure_schema($pdo) || $total <= 0 || $supplierId < 1) {
        return;
    }
    if (crm_supplier_ledger_exists($pdo, 'purchase_invoice', $invoiceId)) {
        return;
    }

    $pay = $paymentType === 'cash' ? 'cash' : 'credit';
    $debit = 0.0;
    $credit = 0.0;
    $memo = 'فاتورة شراء ' . $invoiceNo;

    if ($pay === 'credit') {
        $credit = $total;
        $memo .= ' — ذمم';
    } else {
        $debit = $total;
        $credit = $total;
        $memo .= ' — نقدي (لا يؤثر على الرصيد)';
    }

    crm_supplier_ledger_insert($pdo, $supplierId, $invoiceDate, 'purchase_invoice', $invoiceId, $invoiceNo, $pay, $debit, $credit, $memo);
}

/** مردود مشتريات — ذمة: مدين المورد (يخفّض ما علينا). */
function crm_supplier_ledger_post_purchase_return(
    PDO $pdo,
    int $returnId,
    string $returnNo,
    string $returnDate,
    int $supplierId,
    string $paymentType,
    float $total,
    string $invoiceNo
): void {
    if (!crm_supplier_ledger_ensure_schema($pdo) || $total <= 0 || $supplierId < 1) {
        return;
    }
    if (crm_supplier_ledger_exists($pdo, 'purchase_return', $returnId)) {
        return;
    }

    $pay = $paymentType === 'cash' ? 'cash' : 'credit';
    $debit = 0.0;
    $credit = 0.0;
    $memo = 'مردود مشتريات ' . $returnNo . ' — فاتورة ' . $invoiceNo;

    if ($pay === 'credit') {
        $debit = $total;
        $memo .= ' — ذمم';
    } else {
        $debit = $total;
        $credit = $total;
        $memo .= ' — نقدي (لا يؤثر على الرصيد)';
    }

    crm_supplier_ledger_insert($pdo, $supplierId, $returnDate, 'purchase_return', $returnId, $returnNo, $pay, $debit, $credit, $memo);
}

/** @return array{invoices:int, returns:int} عدد فواتير الشراء ومردودات المشتريات المؤكدة غير المرحّلة على ذمة المورد / المخزون. */
function crm_supplier_ledger_count_unposted(PDO $pdo): array
{
    $out = ['invoices' => 0, 'returns' => 0];
    if (!crm_supplier_ledger_ensure_schema($pdo)) {
        return $out;
    }

    try {
        require_once app_path('includes/pur_invoice_post.php');
        $notPosted = 'NOT ' . pur_invoice_sql_is_posted_expr('i');
        $out['invoices'] = (int) $pdo->query(
            "SELECT COUNT(*) FROM pur_invoice i WHERE i.status = 'confirmed' AND {$notPosted}"
        )->fetchColumn();
    } catch (Throwable $e) {
        $out['invoices'] = 0;
    }

    try {
        require_once app_path('includes/pur_return_post.php');
        $notPostedRet = 'NOT ' . pur_return_sql_is_posted_expr('r');
        $out['returns'] = (int) $pdo->query(
            "SELECT COUNT(*) FROM pur_return r WHERE r.status = 'confirmed' AND {$notPostedRet}"
        )->fetchColumn();
    } catch (Throwable $e) {
        $out['returns'] = 0;
    }

    return $out;
}

/** رصيد المورد حتى تاريخ محدد (شامل): دائن − مدين — موجب = ذمة على الشركة للمورد. */
function crm_supplier_ledger_balance_as_of(PDO $pdo, int $supplierId, string $asOfDate): float
{
    if (!crm_supplier_ledger_has_table($pdo) || $supplierId < 1 || $asOfDate === '') {
        return 0.0;
    }
    $st = $pdo->prepare(
        'SELECT COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0)
         FROM crm_supplier_ledger
         WHERE supplier_id = ? AND txn_date <= ?'
    );
    $st->execute([$supplierId, $asOfDate]);

    return (float) $st->fetchColumn();
}

/** رصيد المورد قبل تاريخ (غير شامل): دائن − مدين. */
function crm_supplier_ledger_balance_before_date(PDO $pdo, int $supplierId, string $beforeDate): float
{
    if (!crm_supplier_ledger_has_table($pdo) || $supplierId < 1 || $beforeDate === '') {
        return 0.0;
    }
    $st = $pdo->prepare(
        'SELECT COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0)
         FROM crm_supplier_ledger
         WHERE supplier_id = ? AND txn_date < ?'
    );
    $st->execute([$supplierId, $beforeDate]);

    return (float) $st->fetchColumn();
}

/** @return list<array<string, mixed>> */
function crm_supplier_ledger_fetch(PDO $pdo, int $supplierId, ?string $from = null, ?string $to = null): array
{
    if (!crm_supplier_ledger_has_table($pdo) || $supplierId < 1) {
        return [];
    }

    $sql = 'SELECT l.id, l.txn_date, l.txn_type, l.ref_id, l.ref_no, l.payment_type, l.debit, l.credit, l.memo, l.created_at,
            (CASE WHEN l.txn_type = \'purchase_return\' THEN
                (SELECT i.invoice_no FROM pur_return r INNER JOIN pur_invoice i ON i.id = r.invoice_id WHERE r.id = l.ref_id LIMIT 1)
             ELSE NULL END) AS source_invoice_no
            FROM crm_supplier_ledger l WHERE l.supplier_id = ?';
    $params = [$supplierId];

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

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function crm_supplier_ledger_cash_payment_is_posted(PDO $pdo, int $voucherId): bool
{
    return $voucherId > 0 && crm_supplier_ledger_exists($pdo, 'cash_payment', $voucherId);
}

/** ترحيل سند صرف على حساب المورد (مدين — تخفيض الذمة). */
function crm_supplier_ledger_post_cash_payment(
    PDO $pdo,
    int $voucherId,
    string $voucherNo,
    string $voucherDate,
    int $supplierId,
    float $amount,
    string $memo,
    string $paymentType = 'cash'
): void {
    if ($voucherId < 1 || $supplierId < 1 || $amount <= 0) {
        throw new RuntimeException('بيانات سند الصرف غير صالحة للترحيل.');
    }
    if (!crm_supplier_ledger_ensure_schema($pdo)) {
        throw new RuntimeException('جدول حركات الموردين غير موجود.');
    }
    if (crm_supplier_ledger_cash_payment_is_posted($pdo, $voucherId)) {
        return;
    }
    crm_supplier_ledger_insert(
        $pdo,
        $supplierId,
        $voucherDate,
        'cash_payment',
        $voucherId,
        $voucherNo,
        $paymentType === 'check' ? 'check' : 'cash',
        $amount,
        0.0,
        $memo
    );
}

function crm_supplier_ledger_unpost_cash_payment(PDO $pdo, int $voucherId): void
{
    if ($voucherId < 1 || !crm_supplier_ledger_has_table($pdo)) {
        return;
    }
    $pdo->prepare('DELETE FROM crm_supplier_ledger WHERE txn_type = ? AND ref_id = ?')
        ->execute(['cash_payment', $voucherId]);
}

/**
 * @return array{ok:bool, skipped:bool, error:?string}
 */
function crm_supplier_ledger_post_cash_payment_by_id(PDO $pdo, int $voucherId): array
{
    $out = ['ok' => false, 'skipped' => false, 'error' => null];
    if ($voucherId < 1) {
        $out['error'] = 'معرّف السند غير صالح.';

        return $out;
    }
    if (crm_supplier_ledger_cash_payment_is_posted($pdo, $voucherId)) {
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
    if ((string) ($row['party_type'] ?? '') !== 'supplier' || (int) ($row['party_id'] ?? 0) < 1) {
        $out['error'] = 'يجب ربط السند بمورد قبل الترحيل.';

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
        crm_supplier_ledger_post_cash_payment(
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
        $out['error'] = $e->getMessage() !== '' ? $e->getMessage() : 'تعذر الترحيل.';

        return $out;
    }
}
