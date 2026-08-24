<?php
declare(strict_types=1);

/**
 * بناء payload كشف حساب عميل Oracle للموبايل (عرض / كاش Offline).
 *
 * @return array{ok:bool,message?:string,customer_id?:int,...}
 */
function oracle_mobile_customer_statement_payload(
    PDO $pdo,
    int $customerId,
    string $from,
    string $to
): array {
    require_once app_path('includes/oracle_statement.php');
    require_once app_path('includes/oracle_customer_sync.php');
    require_once app_path('includes/sal_customer_order_statement.php');
    require_once app_path('includes/document_header.php');

    if ($customerId < 1) {
        return ['ok' => false, 'message' => 'اختر العميل أولاً.'];
    }
    if ($from === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $from = date('Y') . '-01-01';
    }
    if ($to === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $to = date('Y-m-d');
    }

    try {
        if (function_exists('oracle_customer_schema_ensure')) {
            oracle_customer_schema_ensure($pdo);
        }
    } catch (Throwable $e) {
        //
    }

    $st = $pdo->prepare(
        'SELECT id, code, name_ar, oracle_key FROM crm_customer WHERE id = ? LIMIT 1'
    );
    $st->execute([$customerId]);
    $party = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$party) {
        return ['ok' => false, 'message' => 'العميل غير موجود.'];
    }

    $accountNo = trim((string) ($party['oracle_key'] ?? ''));
    if ($accountNo === '') {
        $accountNo = preg_replace('/\D+/', '', (string) ($party['code'] ?? '')) ?? '';
    }
    if ($accountNo === '' || !preg_match('/^\d+$/', $accountNo)) {
        return [
            'ok' => false,
            'message' => 'لا يوجد رقم حساب Oracle لهذا العميل.',
            'party_name' => (string) ($party['name_ar'] ?? ''),
            'party_code' => (string) ($party['code'] ?? ''),
            'customer_id' => $customerId,
        ];
    }

    $stmt = oracle_fetch_customer_statement($accountNo, $from, $to);
    if (!$stmt['ok']) {
        return [
            'ok' => false,
            'message' => (string) ($stmt['message'] ?? 'تعذر جلب الكشف من Oracle.'),
            'account' => $accountNo,
            'party_name' => (string) ($party['name_ar'] ?? ''),
            'party_code' => (string) ($party['code'] ?? ''),
            'customer_id' => $customerId,
        ];
    }

    $stmt = sal_customer_order_statement_merge_oracle($pdo, $customerId, $stmt, $from, $to);

    $name = (string) ($stmt['name'] ?? '');
    if ($name === '') {
        $name = (string) ($party['name_ar'] ?? '');
    }

    $lines = [];
    foreach ((array) ($stmt['lines'] ?? []) as $ln) {
        if (!is_array($ln)) {
            continue;
        }
        $lnDate = (string) ($ln['trn_date'] ?? $ln['date'] ?? $ln['doc_date'] ?? '');
        $lnDesc = (string) ($ln['description'] ?? $ln['remark'] ?? $ln['desc'] ?? '');
        $lines[] = [
            'date' => $lnDate,
            'trn_date' => $lnDate,
            'doc_no' => (string) ($ln['doc_no'] ?? $ln['num'] ?? $ln['number'] ?? ''),
            'doc_type' => (string) ($ln['doc_type'] ?? ''),
            'description' => $lnDesc,
            'remark' => $lnDesc,
            'debit' => (float) ($ln['debit'] ?? 0),
            'credit' => (float) ($ln['credit'] ?? 0),
            'balance' => (float) ($ln['balance'] ?? 0),
        ];
    }

    $brand = [];
    try {
        $brand = document_header_brand_api($pdo);
    } catch (Throwable $e) {
        $brand = [];
    }

    $cheques = is_array($stmt['cheques'] ?? null) ? $stmt['cheques'] : [];

    $salesRepNames = '';
    try {
        require_once app_path('includes/crm_sales_rep_schema.php');
        $salesRepNames = crm_customer_sales_rep_names($pdo, $customerId);
    } catch (Throwable $e) {
        $salesRepNames = '';
    }

    return [
        'ok' => true,
        'message' => '',
        'source' => 'oracle',
        'includes_approved_orders' => (int) ($stmt['approved_orders_merged'] ?? 0) > 0,
        'company_name' => (string) ($brand['company_name'] ?? 'الشركة'),
        'logo_url' => $brand['logo_url'] ?? null,
        'customer_id' => $customerId,
        'party_type' => 'customer',
        'party_id' => $customerId,
        'party_name' => $name,
        'party_code' => (string) ($party['code'] ?? ''),
        'sales_rep_name' => $salesRepNames,
        'sales_rep_names' => $salesRepNames,
        'account' => (string) ($stmt['account'] ?? $accountNo),
        'from' => (string) ($stmt['from'] ?? $from),
        'to' => (string) ($stmt['to'] ?? $to),
        'opening' => (float) ($stmt['opening'] ?? 0),
        'total_debit' => (float) ($stmt['total_debit'] ?? 0),
        'total_credit' => (float) ($stmt['total_credit'] ?? 0),
        'balance' => (float) ($stmt['balance'] ?? 0),
        'lines' => $lines,
        'rows' => $lines,
        'cheques' => $cheques,
        'cheque_total' => (float) ($stmt['cheque_total'] ?? 0),
        'cheque_count' => count($cheques),
        'cached_at' => date('c'),
    ];
}
