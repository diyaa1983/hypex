<?php
declare(strict_types=1);

require_once app_path('includes/fin_voucher_schema.php');

/**
 * تقرير سندات القبض/الصرف بين تاريخين مع فلترة طريقة الدفع.
 *
 * @param string      $voucherType "receipt" أو "payment"
 * @param string      $payFilter   "cash" أو "check" أو "both"
 * @param string      $fromIso     YYYY-MM-DD
 * @param string      $toIso       YYYY-MM-DD
 *
 * @return list<array<string, mixed>>
 */
function fin_vouchers_report_fetch(
    PDO $pdo,
    string $voucherType,
    string $payFilter,
    string $fromIso,
    string $toIso
): array {
    if (!in_array($voucherType, ['receipt', 'payment'], true)) {
        return [];
    }
    if (!in_array($payFilter, ['cash', 'check', 'both'], true)) {
        $payFilter = 'both';
    }
    fin_voucher_ensure_schema_full($pdo);

    $hasPostedCol = fin_voucher_has_column($pdo, 'is_posted');
    $hasPayMethod = fin_voucher_has_column($pdo, 'pay_method');

    $postedExpr = $hasPostedCol ? 'v.is_posted' : '0';
    $payExpr = $hasPayMethod ? 'v.pay_method' : "'cash'";

    $sql = "SELECT v.id, v.voucher_no, v.voucher_date, v.amount, v.party_type, v.party_id,
                   v.cash_account_id, v.bank_name, v.description,
                   {$payExpr} AS pay_method,
                   ({$postedExpr}) AS is_posted,
                   COALESCE(c.name_ar, s.name_ar, '—') AS party_name,
                   a.code AS account_code,
                   a.name_ar AS account_name
            FROM fin_voucher v
            LEFT JOIN crm_customer c ON v.party_type = 'customer' AND c.id = v.party_id
            LEFT JOIN crm_supplier s ON v.party_type = 'supplier' AND s.id = v.party_id
            LEFT JOIN acc_account a ON a.id = v.cash_account_id
            WHERE v.voucher_type = ?
              AND v.voucher_date BETWEEN ? AND ?";
    $params = [$voucherType, $fromIso, $toIso];

    if ($hasPayMethod) {
        if ($payFilter === 'cash') {
            $sql .= ' AND v.pay_method = ?';
            $params[] = 'cash';
        } elseif ($payFilter === 'check') {
            $sql .= ' AND v.pay_method = ?';
            $params[] = 'check';
        }
    }

    $sql .= ' ORDER BY v.voucher_date ASC, v.id ASC';

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    require_once app_path('includes/fin_voucher_checks.php');
    foreach ($rows as &$r) {
        $r['amount'] = (float) ($r['amount'] ?? 0);
        $r['is_posted'] = (int) ($r['is_posted'] ?? 0) === 1;
        $r['pay_method'] = (string) ($r['pay_method'] ?? 'cash');

        if ($r['pay_method'] === 'check') {
            $banks = [];
            try {
                $checks = fin_voucher_checks_load($pdo, (int) $r['id']);
                foreach ($checks as $ch) {
                    $b = trim((string) ($ch['bank_name'] ?? ''));
                    if ($b !== '' && !in_array($b, $banks, true)) {
                        $banks[] = $b;
                    }
                }
            } catch (Throwable $e) {
                // تجاهل
            }
            if (!$banks && trim((string) ($r['bank_name'] ?? '')) !== '') {
                $banks[] = trim((string) $r['bank_name']);
            }
            $r['banks_label'] = implode('، ', $banks);
        } else {
            $r['banks_label'] = '';
        }

        $accCode = trim((string) ($r['account_code'] ?? ''));
        $accName = trim((string) ($r['account_name'] ?? ''));
        if ($accName !== '' || $accCode !== '') {
            $r['account_label'] = ($accCode !== '' ? $accCode . ' — ' : '') . $accName;
        } else {
            $r['account_label'] = '—';
        }
    }
    unset($r);

    return $rows;
}

function fin_vouchers_report_pay_method_label(string $method): string
{
    return fin_voucher_pay_method_label($method);
}
