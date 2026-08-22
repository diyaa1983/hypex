<?php
declare(strict_types=1);

require_once app_path('includes/sal_customer_order.php');

/**
 * طلبات شراء معتمدة (غير المرحّلة لـ Oracle) تُضاف لكشف حساب العميل على الموبايل.
 * عند فك الاعتماد (status=draft) تختفي تلقائياً.
 */

function sal_customer_order_statement_order_amount(PDO $pdo, array $order): float
{
    $total = (float) ($order['total'] ?? 0);
    if ($total > 0.000001) {
        return $total;
    }
    $id = (int) ($order['id'] ?? 0);
    if ($id < 1) {
        return 0.0;
    }
    if (sal_customer_order_has_pricing($pdo)) {
        $st = $pdo->prepare(
            'SELECT COALESCE(SUM(line_gross), SUM(line_total), 0) FROM sal_customer_order_line WHERE order_id = ?'
        );
        $st->execute([$id]);

        return (float) $st->fetchColumn();
    }

    return 0.0;
}

/**
 * @return list<array<string,mixed>>
 */
function sal_customer_order_statement_fetch_approved(PDO $pdo, int $customerId, ?string $from = null, ?string $to = null): array
{
    if ($customerId < 1) {
        return [];
    }
    sal_customer_order_ensure_schema($pdo);

    $hasOracle = sal_customer_order_has_column($pdo, 'sal_customer_order', 'oracle_v_num');
    $hasPay = sal_customer_order_has_column($pdo, 'sal_customer_order', 'payment_type');

    $sql = "SELECT o.id, o.order_no, o.order_date, o.status, o.total"
        . ($hasPay ? ', o.payment_type' : ", 'credit' AS payment_type")
        . ($hasOracle ? ', COALESCE(o.oracle_v_num, 0) AS oracle_v_num' : ', 0 AS oracle_v_num')
        . ' FROM sal_customer_order o
           WHERE o.customer_id = ?
             AND o.status = \'approved\'';
    $params = [$customerId];

    if ($hasOracle) {
        $sql .= ' AND COALESCE(o.oracle_v_num, 0) = 0';
    }
    if ($from !== null && $from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $sql .= ' AND o.order_date >= ?';
        $params[] = $from;
    }
    if ($to !== null && $to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $sql .= ' AND o.order_date <= ?';
        $params[] = $to;
    }
    $sql .= ' ORDER BY o.order_date ASC, o.id ASC';

    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return list<array<string,mixed>>
 */
function sal_customer_order_statement_supplement_lines(PDO $pdo, int $customerId, string $from, string $to): array
{
    $rows = sal_customer_order_statement_fetch_approved($pdo, $customerId, $from, $to);
    $lines = [];
    foreach ($rows as $row) {
        $amt = sal_customer_order_statement_order_amount($pdo, $row);
        if ($amt <= 0.000001) {
            continue;
        }
        $isCash = strtolower((string) ($row['payment_type'] ?? 'credit')) === 'cash';
        $orderNo = trim((string) ($row['order_no'] ?? ''));
        if ($orderNo === '') {
            $orderNo = 'CO-' . (int) ($row['id'] ?? 0);
        }
        $desc = $isCash
            ? 'طلب شراء معتمد (نقدي)'
            : 'طلب شراء معتمد (ذمم)';
        $lines[] = [
            'date' => substr((string) ($row['order_date'] ?? ''), 0, 10),
            'trn_date' => substr((string) ($row['order_date'] ?? ''), 0, 10),
            'doc_no' => $orderNo,
            'doc_type' => 'customer_order',
            'description' => $desc,
            'remark' => $desc,
            'debit' => $isCash ? $amt : $amt,
            'credit' => $isCash ? $amt : 0.0,
            'source' => 'hypex_order',
            'order_id' => (int) ($row['id'] ?? 0),
        ];
    }

    return $lines;
}

function sal_customer_order_statement_opening_adjustment(PDO $pdo, int $customerId, string $from): float
{
    if ($from === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        return 0.0;
    }
    $rows = sal_customer_order_statement_fetch_approved($pdo, $customerId, null, date('Y-m-d', strtotime($from . ' -1 day')));
    $adj = 0.0;
    foreach ($rows as $row) {
        $orderDate = substr((string) ($row['order_date'] ?? ''), 0, 10);
        if ($orderDate === '' || $orderDate >= $from) {
            continue;
        }
        $amt = sal_customer_order_statement_order_amount($pdo, $row);
        if ($amt <= 0.000001) {
            continue;
        }
        $isCash = strtolower((string) ($row['payment_type'] ?? 'credit')) === 'cash';
        $adj += $isCash ? 0.0 : $amt;
    }

    return $adj;
}

/**
 * دمج حركات Oracle مع طلبات الشراء المعتمدة وإعادة حساب الأرصدة.
 *
 * @param array<string,mixed> $stmt ناتج oracle_fetch_customer_statement
 * @return array<string,mixed>
 */
function sal_customer_order_statement_merge_oracle(PDO $pdo, int $customerId, array $stmt, string $from, string $to): array
{
    if ($customerId < 1 || empty($stmt['ok'])) {
        return $stmt;
    }

    $oracleLines = [];
    foreach ((array) ($stmt['lines'] ?? []) as $ln) {
        if (!is_array($ln)) {
            continue;
        }
        $lnDate = (string) ($ln['trn_date'] ?? $ln['date'] ?? $ln['doc_date'] ?? '');
        $lnDesc = (string) ($ln['description'] ?? $ln['remark'] ?? $ln['desc'] ?? '');
        $oracleLines[] = [
            'date' => $lnDate,
            'trn_date' => $lnDate,
            'doc_no' => (string) ($ln['doc_no'] ?? $ln['num'] ?? $ln['number'] ?? ''),
            'doc_type' => (string) ($ln['doc_type'] ?? ''),
            'description' => $lnDesc,
            'remark' => $lnDesc,
            'debit' => (float) ($ln['debit'] ?? 0),
            'credit' => (float) ($ln['credit'] ?? 0),
            'source' => 'oracle',
        ];
    }

    $localLines = sal_customer_order_statement_supplement_lines($pdo, $customerId, $from, $to);
    $all = array_merge($oracleLines, $localLines);
    usort($all, static function (array $a, array $b): int {
        $da = (string) ($a['date'] ?? '');
        $db = (string) ($b['date'] ?? '');
        if ($da !== $db) {
            return strcmp($da, $db);
        }
        $srcA = (string) ($a['source'] ?? '');
        $srcB = (string) ($b['source'] ?? '');
        if ($srcA !== $srcB) {
            return strcmp($srcA, $srcB);
        }

        return strcmp((string) ($a['doc_no'] ?? ''), (string) ($b['doc_no'] ?? ''));
    });

    $opening = (float) ($stmt['opening'] ?? 0);
    $opening += sal_customer_order_statement_opening_adjustment($pdo, $customerId, $from);

    $balance = $opening;
    $totalDebit = 0.0;
    $totalCredit = 0.0;
    $merged = [];
    foreach ($all as $ln) {
        $debit = (float) ($ln['debit'] ?? 0);
        $credit = (float) ($ln['credit'] ?? 0);
        $totalDebit += $debit;
        $totalCredit += $credit;
        $balance += $debit - $credit;
        $ln['balance'] = $balance;
        $merged[] = $ln;
    }

    $stmt['opening'] = $opening;
    $stmt['lines'] = $merged;
    $stmt['total_debit'] = $totalDebit;
    $stmt['total_credit'] = $totalCredit;
    $stmt['balance'] = $balance;
    $stmt['approved_orders_merged'] = count($localLines);

    return $stmt;
}

/**
 * @return array{opening_adj:float,extra_debit:float,extra_credit:float,order_count:int}
 */
function sal_customer_order_statement_period_adjustment(PDO $pdo, int $customerId, string $from, string $to): array
{
    $openingAdj = sal_customer_order_statement_opening_adjustment($pdo, $customerId, $from);
    $lines = sal_customer_order_statement_supplement_lines($pdo, $customerId, $from, $to);
    $extraDebit = 0.0;
    $extraCredit = 0.0;
    foreach ($lines as $ln) {
        $extraDebit += (float) ($ln['debit'] ?? 0);
        $extraCredit += (float) ($ln['credit'] ?? 0);
    }

    return [
        'opening_adj' => $openingAdj,
        'extra_debit' => $extraDebit,
        'extra_credit' => $extraCredit,
        'order_count' => count($lines),
    ];
}

/**
 * @param array<string,mixed> $summary ناتج oracle_customer_ar_summary قبل الدمج
 * @return array<string,mixed>
 */
function sal_customer_order_statement_merge_summary(PDO $pdo, int $customerId, array $summary, string $from, string $to): array
{
    if ($customerId < 1 || empty($summary['ok'])) {
        return $summary;
    }

    $adj = sal_customer_order_statement_period_adjustment($pdo, $customerId, $from, $to);
    $summary['opening'] = (float) ($summary['opening'] ?? 0) + $adj['opening_adj'];
    $summary['total_debit'] = (float) ($summary['total_debit'] ?? 0) + $adj['extra_debit'];
    $summary['total_credit'] = (float) ($summary['total_credit'] ?? 0) + $adj['extra_credit'];
    $summary['balance'] = (float) ($summary['balance'] ?? 0)
        + $adj['opening_adj'] + $adj['extra_debit'] - $adj['extra_credit'];
    $summary['approved_orders_merged'] = $adj['order_count'];

    return $summary;
}
