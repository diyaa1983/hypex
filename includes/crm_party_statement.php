<?php
declare(strict_types=1);

require_once app_path('includes/crm_customer_ledger.php');
require_once app_path('includes/crm_supplier_ledger.php');

/** @return bool */
function crm_party_statement_fin_voucher_has_table(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $pdo->query('SELECT id FROM fin_voucher LIMIT 1');
        $ok = true;
    } catch (Throwable $e) {
        $ok = false;
    }

    return $ok;
}

/** @return bool */
function crm_party_statement_voucher_has_check_no(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    if (!crm_party_statement_fin_voucher_has_table($pdo)) {
        $ok = false;

        return false;
    }
    try {
        $pdo->query('SELECT check_no FROM fin_voucher LIMIT 1');
        $ok = true;
    } catch (Throwable $e) {
        $ok = false;
    }

    return $ok;
}

/**
 * نص «وذلك عن» من سند القبض للعرض بجانب رقم السند في الكشف.
 * يُستخرج من memo الدفتر (حقل description في السند) مع استبعاد بيانات الشيك الملحقة تلقائياً.
 */
function crm_party_statement_receipt_doc_hint(string $txnType, string $memo): string
{
    if (!in_array($txnType, ['cash_receipt', 'receipt_voucher'], true)) {
        return '';
    }
    $memo = trim($memo);
    if ($memo === '') {
        return '';
    }
    if (preg_match('/\s+—\s+شيك:/u', $memo)) {
        $hint = trim((string) preg_replace('/\s+—\s+شيك:.*$/us', '', $memo));

        return $hint;
    }
    if (preg_match('/^(?:شيك:|البنك:)/u', $memo)) {
        return '';
    }

    return $memo;
}

/** استخراج رقم الشيك من مذكرة الترحيل (سندات مرحّلة سابقاً). */
function crm_party_statement_memo_check_no(string $memo): string
{
    $memo = trim($memo);
    if ($memo === '') {
        return '';
    }
    if (preg_match('/شيك:\s*([^|—]+)/u', $memo, $m)) {
        return trim($m[1]);
    }

    return '';
}

function crm_party_statement_is_check_payment(string $paymentType, string $memo, string $checkNo): bool
{
    if ($checkNo !== '') {
        return true;
    }
    $p = strtolower(trim($paymentType));
    if ($p === 'check' || $p === 'cheque' || $p === 'شيك') {
        return true;
    }

    return crm_party_statement_memo_check_no($memo) !== '';
}

/** تسمية عمود الرقم حسب نوع الحركة (فاتورة، مرتجع، سند، شيك). */
function crm_party_statement_doc_number_label(string $txnType, string $checkNo): string
{
    if ($checkNo !== '' || $txnType === 'cheque' || $txnType === 'check') {
        return 'رقم الشيك';
    }
    if ($txnType === 'cash_receipt' || $txnType === 'receipt_voucher') {
        return 'رقم سند القبض';
    }
    if ($txnType === 'cash_payment' || $txnType === 'payment_voucher') {
        return 'رقم سند الصرف';
    }
    if ($txnType === 'sale_return') {
        return 'رقم المرتجع';
    }
    if ($txnType === 'purchase_return') {
        return 'رقم المردود';
    }
    if ($txnType === 'purchase_invoice') {
        return 'رقم فاتورة الشراء';
    }
    if ($txnType === 'sale_invoice') {
        return 'رقم فاتورة البيع';
    }
    if ($txnType === 'journal_voucher') {
        return 'رقم سند القيد';
    }

    return 'رقم المستند';
}

/** وصف الحركة للعرض في كشف الحساب. */
function crm_party_statement_format_description(
    string $txnType,
    string $partyType,
    string $paymentType,
    string $memo,
    string $checkNo,
    string $sourceInvoiceNo = ''
): string {
    $txn = strtolower(trim($txnType));
    $pay = strtolower(trim($paymentType));
    $isCheck = crm_party_statement_is_check_payment($paymentType, $memo, $checkNo);
    $isCredit = in_array($pay, ['credit', 'ذمم'], true);
    $srcInv = trim($sourceInvoiceNo);
    if ($srcInv === '' && ($txn === 'sale_return' || $txn === 'purchase_return') && $memo !== '') {
        if (preg_match('/فاتورة\s+([^—|]+)/u', $memo, $m)) {
            $srcInv = trim($m[1]);
        }
    }

    if ($txn === 'sale_invoice') {
        if ($isCredit) {
            return 'فاتورة بيع ذمم';
        }

        return 'فاتورة بيع نقدي';
    }
    if ($txn === 'sale_return') {
        $base = $isCredit ? 'مرتجع مبيعات ذمم' : 'مرتجع مبيعات نقدي';
        if ($srcInv !== '') {
            return $base . ' — من فاتورة رقم ' . $srcInv;
        }

        return $base;
    }
    if (in_array($txn, ['cash_receipt', 'receipt_voucher'], true)) {
        if ($isCheck) {
            return 'دفعة - شيك';
        }

        return 'دفعة نقدية';
    }
    if (in_array($txn, ['cash_payment', 'payment_voucher'], true)) {
        if ($isCheck) {
            return 'دفعة - شيك';
        }

        return 'دفعة نقدية';
    }
    if ($txn === 'cheque' || $txn === 'check') {
        return 'دفعة - شيك';
    }
    if ($txn === 'purchase_return') {
        $base = $isCredit ? 'مردود مشتريات ذمم' : 'مردود مشتريات نقدي';
        if ($srcInv !== '') {
            return $base . ' — من فاتورة رقم ' . $srcInv;
        }

        return $base;
    }

    return crm_party_statement_txn_type_label($txnType, $partyType);
}

function crm_party_statement_txn_type_label(string $txnType, string $partyType = 'customer'): string
{
    $map = [
        'sale_invoice' => 'فاتورة بيع',
        'sale_return' => 'مرتجع مبيعات',
        'purchase_invoice' => 'فاتورة شراء',
        'purchase_return' => 'مردود مشتريات',
        'cash_receipt' => 'سند قبض',
        'cash_payment' => 'سند صرف',
        'receipt_voucher' => 'سند قبض',
        'payment_voucher' => 'سند صرف',
        'cheque' => 'شيك',
        'check' => 'شيك',
        'journal_voucher' => 'سند قيد',
        'check_return' => 'إرجاع شيك',
        'debit_note' => 'إشعار مدين',
        'credit_note' => 'إشعار دائن',
    ];

    if (isset($map[$txnType])) {
        return $map[$txnType];
    }

    return $txnType !== '' ? $txnType : 'حركة';
}

function crm_party_statement_amount_epsilon(): float
{
    return 0.000001;
}

/** تحديث الرصيد الجاري بعد حركة واحدة (عميل: +مدين −دائن | مورد: +دائن −مدين). */
function crm_party_statement_apply_amount(string $partyType, float $balance, float $debit, float $credit): float
{
    if ($partyType === 'supplier') {
        return $balance + $credit - $debit;
    }

    return $balance + $debit - $credit;
}

/**
 * الرصيد التراكمي = رصيد افتتاحي + مجموع المدين − مجموع الدائن (عميل).
 * للمورد: رصيد افتتاحي + مجموع الدائن − مجموع المدين.
 */
function crm_party_statement_balance_from_totals(
    string $partyType,
    float $openingBalance,
    float $sumDebit,
    float $sumCredit
): float {
    if ($partyType === 'supplier') {
        return $openingBalance + $sumCredit - $sumDebit;
    }

    return $openingBalance + $sumDebit - $sumCredit;
}

/**
 * تحويل رصيد إلى عمود مدين/دائن حسب طبيعة الحساب.
 *
 * @return array{debit:float,credit:float}
 */
function crm_party_statement_balance_to_columns(string $partyType, float $balance): array
{
    $eps = crm_party_statement_amount_epsilon();
    if (abs($balance) < $eps) {
        return ['debit' => 0.0, 'credit' => 0.0];
    }

    if ($partyType === 'supplier') {
        if ($balance > 0) {
            return ['debit' => 0.0, 'credit' => abs($balance)];
        }

        return ['debit' => abs($balance), 'credit' => 0.0];
    }

    if ($balance > 0) {
        return ['debit' => abs($balance), 'credit' => 0.0];
    }

    return ['debit' => 0.0, 'credit' => abs($balance)];
}

/**
 * مدين/دائن العرض في كشف الحساب حسب نوع الحركة (قواعد المحاسبة):
 * عميل: فاتورة بيع → مدين | مرتجع بيع → دائن | قبض/صرف → دائن
 * مورد: فاتورة شراء → دائن | مردود شراء → مدين | قبض/صرف → مدين
 * الحركات النقدية (مدين=دائن) لا تُعرض — لا أثر على الذمة.
 *
 * @return array{debit:float,credit:float,skip:bool}
 */
function crm_party_statement_row_display_amounts(
    string $partyType,
    float $debit,
    float $credit,
    string $txnType = '',
    string $paymentType = ''
): array {
    $eps = crm_party_statement_amount_epsilon();
    $pay = strtolower(trim($paymentType));
    $txn = strtolower(trim($txnType));

    // فاتورة بيع/مرتجع نقدي: مسجّلة مدين=دائن في الدفتر — تُعرض في الكشف دون تغيير الرصيد
    if ($pay === 'cash' && abs($debit - $credit) < $eps && ($debit > $eps || $credit > $eps)) {
        if (in_array($txn, ['sale_invoice', 'sale_return'], true)) {
            return ['debit' => $debit, 'credit' => $credit, 'skip' => false];
        }

        return ['debit' => 0.0, 'credit' => 0.0, 'skip' => true];
    }

    $amt = max($debit, $credit);
    if ($amt < $eps) {
        return ['debit' => 0.0, 'credit' => 0.0, 'skip' => true];
    }


    if ($partyType === 'supplier') {
        if ($txn === 'purchase_invoice') {
            return ['debit' => 0.0, 'credit' => $amt, 'skip' => false];
        }
        if ($txn === 'purchase_return') {
            return ['debit' => $amt, 'credit' => 0.0, 'skip' => false];
        }
        if (in_array($txn, ['cash_payment', 'payment_voucher', 'cash_receipt', 'receipt_voucher', 'cheque', 'check'], true)) {
            return ['debit' => $amt, 'credit' => 0.0, 'skip' => false];
        }
        if ($txn === 'debit_note') {
            return ['debit' => 0.0, 'credit' => $amt, 'skip' => false];
        }
        if ($txn === 'credit_note') {
            return ['debit' => $amt, 'credit' => 0.0, 'skip' => false];
        }
    } else {
        if ($txn === 'sale_invoice') {
            return ['debit' => $amt, 'credit' => 0.0, 'skip' => false];
        }
        if ($txn === 'sale_return') {
            return ['debit' => 0.0, 'credit' => $amt, 'skip' => false];
        }
        if (in_array($txn, ['cash_receipt', 'receipt_voucher', 'cash_payment', 'payment_voucher', 'cheque', 'check'], true)) {
            return ['debit' => 0.0, 'credit' => $amt, 'skip' => false];
        }
        if ($txn === 'debit_note') {
            return ['debit' => $amt, 'credit' => 0.0, 'skip' => false];
        }
        if ($txn === 'credit_note') {
            return ['debit' => 0.0, 'credit' => $amt, 'skip' => false];
        }
    }

    $net = $partyType === 'supplier' ? ($credit - $debit) : ($debit - $credit);
    if (abs($net) < $eps) {
        return ['debit' => 0.0, 'credit' => 0.0, 'skip' => true];
    }
    $cols = crm_party_statement_balance_to_columns($partyType, $net);

    return ['debit' => $cols['debit'], 'credit' => $cols['credit'], 'skip' => false];
}

/** رصيد افتتاحي قبل تاريخ (غير شامل). */
function crm_party_statement_opening_balance(PDO $pdo, string $partyType, int $partyId, string $beforeDate): float
{
    if ($partyId < 1 || $beforeDate === '') {
        return 0.0;
    }

    if ($partyType === 'supplier') {
        crm_supplier_ledger_ensure_schema($pdo);

        return crm_supplier_ledger_balance_before_date($pdo, $partyId, $beforeDate)
            + crm_party_statement_voucher_balance_before($pdo, 'supplier', $partyId, $beforeDate);
    }

    crm_ledger_ensure_schema($pdo);

    return crm_ledger_balance_before_date($pdo, $partyId, $beforeDate)
        + crm_party_statement_voucher_balance_before($pdo, 'customer', $partyId, $beforeDate);
}

function crm_party_statement_voucher_balance_before(
    PDO $pdo,
    string $partyType,
    int $partyId,
    string $beforeDate
): float {
    if (!crm_party_statement_fin_voucher_has_table($pdo) || $partyId < 1 || $beforeDate === '') {
        return 0.0;
    }

    $pt = $partyType === 'supplier' ? 'supplier' : 'customer';
    $st = $pdo->prepare(
        "SELECT voucher_type, amount FROM fin_voucher
         WHERE party_type = ? AND party_id = ? AND voucher_date < ?"
    );
    $st->execute([$pt, $partyId, $beforeDate]);
    $bal = 0.0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $amt = (float) ($row['amount'] ?? 0);
        $vt = (string) ($row['voucher_type'] ?? '');
        $debit = 0.0;
        $credit = 0.0;
        crm_party_statement_voucher_amounts($partyType, $vt, $amt, $debit, $credit);
        $bal = crm_party_statement_apply_amount($partyType, $bal, $debit, $credit);
    }

    return $bal;
}

/**
 * مدين/دائن السند على حساب الطرف (قواعد المحاسبة).
 * عميل (ذمم مدينة): القبض والصرف له يخفّضان الرصيد → دائن.
 * مورد (ذمم دائنة): الصرف والقبض منه يخفّضان الرصيد → مدين.
 *
 * @param-out float $debit
 * @param-out float $credit
 */
function crm_party_statement_voucher_amounts(
    string $partyType,
    string $voucherType,
    float $amount,
    float &$debit,
    float &$credit
): void {
    $debit = 0.0;
    $credit = 0.0;
    if ($amount <= 0) {
        return;
    }

    if ($partyType === 'supplier') {
        $debit = $amount;

        return;
    }

    $credit = $amount;
}

/** @return list<array<string, mixed>> */
function crm_party_statement_fetch_ledger(PDO $pdo, string $partyType, int $partyId, ?string $from, ?string $to): array
{
    if ($partyId < 1) {
        return [];
    }

    if ($partyType === 'supplier') {
        crm_supplier_ledger_ensure_schema($pdo);

        return crm_supplier_ledger_fetch($pdo, $partyId, $from, $to);
    }

    crm_ledger_ensure_schema($pdo);

    return crm_ledger_fetch_customer($pdo, $partyId, $from, $to);
}

/** @return list<array<string, mixed>> */
function crm_party_statement_fetch_vouchers(
    PDO $pdo,
    string $partyType,
    int $partyId,
    ?string $from,
    ?string $to
): array {
    if ($partyId < 1 || !crm_party_statement_fin_voucher_has_table($pdo)) {
        return [];
    }

    $hasCheck = crm_party_statement_voucher_has_check_no($pdo);
    $checkCol = $hasCheck ? ', check_no' : '';
    $pt = $partyType === 'supplier' ? 'supplier' : 'customer';

    $ledgerExclude = '';
    if ($partyType === 'customer' && crm_ledger_has_table($pdo)) {
        $ledgerExclude = " AND NOT EXISTS (
            SELECT 1 FROM crm_customer_ledger l
            WHERE l.customer_id = v.party_id
              AND l.txn_type IN ('cash_receipt','receipt_voucher','cash_payment','payment_voucher')
              AND l.ref_id = v.id
        )";
    } elseif ($partyType === 'supplier') {
        require_once app_path('includes/crm_supplier_ledger.php');
        if (crm_supplier_ledger_has_table($pdo)) {
            $ledgerExclude = " AND NOT EXISTS (
                SELECT 1 FROM crm_supplier_ledger l
                WHERE l.supplier_id = v.party_id
                  AND l.txn_type = 'cash_payment'
                  AND l.ref_id = v.id
            )";
        }
    }

    $postedOnly = '';
    require_once app_path('includes/fin_voucher_schema.php');
    if (fin_voucher_has_column($pdo, 'is_posted')) {
        $postedOnly = ' AND v.is_posted = 1';
    }

    $sql = "SELECT v.id, v.voucher_type, v.voucher_no, v.voucher_date, v.amount, v.description{$checkCol}
            FROM fin_voucher v
            WHERE v.party_type = ? AND v.party_id = ?{$postedOnly}{$ledgerExclude}";
    $params = [$pt, $partyId];
    if ($from !== null && $from !== '') {
        $sql .= ' AND voucher_date >= ?';
        $params[] = $from;
    }
    if ($to !== null && $to !== '') {
        $sql .= ' AND voucher_date <= ?';
        $params[] = $to;
    }
    $sql .= ' ORDER BY voucher_date ASC, id ASC';

    $st = $pdo->prepare($sql);
    $st->execute($params);

    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $v) {
        $vt = (string) ($v['voucher_type'] ?? '');
        $amt = (float) ($v['amount'] ?? 0);
        $debit = 0.0;
        $credit = 0.0;
        crm_party_statement_voucher_amounts($partyType, $vt, $amt, $debit, $credit);
        $txnType = $vt === 'receipt' ? 'cash_receipt' : 'cash_payment';
        $rows[] = [
            'txn_date' => (string) ($v['voucher_date'] ?? ''),
            'txn_type' => $txnType,
            'ref_id' => (int) ($v['id'] ?? 0),
            'ref_no' => (string) ($v['voucher_no'] ?? ''),
            'payment_type' => $vt === 'receipt' ? 'receipt' : 'payment',
            'debit' => $debit,
            'credit' => $credit,
            'memo' => (string) ($v['description'] ?? ''),
            'check_no' => $hasCheck ? trim((string) ($v['check_no'] ?? '')) : '',
            'source' => 'voucher',
        ];
    }

    return $rows;
}

/**
 * بناء كشف حساب عميل أو مورد.
 *
 * @return array{
 *   rows:list<array<string,mixed>>,
 *   opening_balance:float,
 *   opening_debit:float,
 *   opening_credit:float,
 *   closing_balance:float,
 *   total_debit:float,
 *   total_credit:float
 * }
 */
function crm_party_statement_build(
    PDO $pdo,
    string $partyType,
    int $partyId,
    string $from,
    string $to
): array {
    $partyType = $partyType === 'supplier' ? 'supplier' : 'customer';
    $out = [
        'rows' => [],
        'opening_balance' => 0.0,
        'opening_debit' => 0.0,
        'opening_credit' => 0.0,
        'closing_balance' => 0.0,
        'total_debit' => 0.0,
        'total_credit' => 0.0,
    ];

    if ($partyId < 1) {
        return $out;
    }

    if ($partyType === 'customer') {
        crm_ledger_repair_customer_cash_sale_ledger($pdo, $partyId);
    }

    if ($from !== '') {
        $out['opening_balance'] = crm_party_statement_opening_balance($pdo, $partyType, $partyId, $from);
        $openCols = crm_party_statement_balance_to_columns($partyType, $out['opening_balance']);
        $out['opening_debit'] = $openCols['debit'];
        $out['opening_credit'] = $openCols['credit'];
    }

    $ledgerRows = crm_party_statement_fetch_ledger($pdo, $partyType, $partyId, $from !== '' ? $from : null, $to !== '' ? $to : null);
    $voucherRows = crm_party_statement_fetch_vouchers($pdo, $partyType, $partyId, $from !== '' ? $from : null, $to !== '' ? $to : null);

    $merged = [];
    foreach ($ledgerRows as $lr) {
        $merged[] = [
            'txn_date' => (string) ($lr['txn_date'] ?? ''),
            'txn_type' => (string) ($lr['txn_type'] ?? ''),
            'ref_id' => (int) ($lr['ref_id'] ?? 0),
            'ref_no' => (string) ($lr['ref_no'] ?? ''),
            'payment_type' => (string) ($lr['payment_type'] ?? ''),
            'debit' => (float) ($lr['debit'] ?? 0),
            'credit' => (float) ($lr['credit'] ?? 0),
            'memo' => (string) ($lr['memo'] ?? ''),
            'source_invoice_no' => (string) ($lr['source_invoice_no'] ?? ''),
            'check_no' => '',
            'source' => 'ledger',
            'sort_id' => (int) ($lr['id'] ?? 0),
        ];
    }
    foreach ($voucherRows as $vr) {
        $merged[] = array_merge($vr, ['sort_id' => 1000000000 + (int) ($vr['ref_id'] ?? 0)]);
    }

    usort($merged, static function (array $a, array $b): int {
        $da = (string) ($a['txn_date'] ?? '');
        $db = (string) ($b['txn_date'] ?? '');
        if ($da !== $db) {
            return $da <=> $db;
        }

        return ((int) ($a['sort_id'] ?? 0)) <=> ((int) ($b['sort_id'] ?? 0));
    });

    $periodDebit = 0.0;
    $periodCredit = 0.0;
    foreach ($merged as $lr) {
        $rawDebit = (float) $lr['debit'];
        $rawCredit = (float) $lr['credit'];
        $display = crm_party_statement_row_display_amounts(
            $partyType,
            $rawDebit,
            $rawCredit,
            (string) ($lr['txn_type'] ?? ''),
            (string) ($lr['payment_type'] ?? '')
        );
        if ($display['skip']) {
            continue;
        }

        $debit = $display['debit'];
        $credit = $display['credit'];
        $periodDebit += $debit;
        $periodCredit += $credit;
        $running = crm_party_statement_balance_from_totals(
            $partyType,
            $out['opening_balance'],
            $periodDebit,
            $periodCredit
        );
        $out['total_debit'] = $periodDebit;
        $out['total_credit'] = $periodCredit;

        $txnType = (string) ($lr['txn_type'] ?? '');
        $memo = (string) ($lr['memo'] ?? '');
        $checkNo = (string) ($lr['check_no'] ?? '');
        if ($checkNo === '') {
            $checkNo = crm_party_statement_memo_check_no($memo);
        }

        $pay = (string) ($lr['payment_type'] ?? '');

        $refNo = (string) ($lr['ref_no'] ?? '');
        $displayRef = $checkNo !== '' ? $checkNo : $refNo;
        $docHint = crm_party_statement_receipt_doc_hint($txnType, $memo);
        $out['rows'][] = [
            'ref_no' => $displayRef,
            'doc_hint' => $docHint,
            'doc_label' => crm_party_statement_doc_number_label($txnType, $checkNo),
            'date' => (string) $lr['txn_date'],
            'description' => crm_party_statement_format_description(
                $txnType,
                $partyType,
                $pay,
                $memo,
                $checkNo,
                (string) ($lr['source_invoice_no'] ?? '')
            ),
            'debit' => $debit,
            'credit' => $credit,
            'balance' => $running,
            'txn_type' => $txnType,
            'ref_id' => (int) ($lr['ref_id'] ?? 0),
        ];
    }

    $out['closing_balance'] = crm_party_statement_balance_from_totals(
        $partyType,
        $out['opening_balance'],
        $out['total_debit'],
        $out['total_credit']
    );

    return $out;
}

function crm_party_statement_balance_label(string $partyType, float $bal): string
{
    $s = format_money($bal);
    if (abs($bal) < 0.000001) {
        return $s;
    }

    if ($partyType === 'supplier') {
        if ($bal > 0) {
            return $s . ' (له — علينا للمورد)';
        }

        return $s . ' (مدين — لنا على المورد)';
    }

    if ($bal > 0) {
        return $s . ' (عليه — للعميل)';
    }

    return $s . ' (له — على العميل)';
}
