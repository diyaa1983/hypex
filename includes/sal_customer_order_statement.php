<?php
declare(strict_types=1);

require_once app_path('includes/sal_customer_order.php');

/**
 * طلب الشراء لا يظهر في كشوف الحساب (تابلت / كمبيوتر / Oracle).
 * الدوال التالية تُبقي الاستدعاءات القديمة دون دمج أي أسطر.
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
    return [];
}

/**
 * @return list<array<string,mixed>>
 */
function sal_customer_order_statement_supplement_lines(PDO $pdo, int $customerId, string $from, string $to): array
{
    return [];
}

function sal_customer_order_statement_opening_adjustment(PDO $pdo, int $customerId, string $from): float
{
    return 0.0;
}

/**
 * @param array<string,mixed> $stmt ناتج oracle_fetch_customer_statement
 * @return array<string,mixed>
 */
function sal_customer_order_statement_merge_oracle(PDO $pdo, int $customerId, array $stmt, string $from, string $to): array
{
    $stmt['approved_orders_merged'] = 0;

    return $stmt;
}

/**
 * @return array{opening_adj:float,extra_debit:float,extra_credit:float,order_count:int}
 */
function sal_customer_order_statement_period_adjustment(PDO $pdo, int $customerId, string $from, string $to): array
{
    return [
        'opening_adj' => 0.0,
        'extra_debit' => 0.0,
        'extra_credit' => 0.0,
        'order_count' => 0,
    ];
}

/**
 * @param array<string,mixed> $summary ناتج oracle_customer_ar_summary قبل الدمج
 * @return array<string,mixed>
 */
function sal_customer_order_statement_merge_summary(PDO $pdo, int $customerId, array $summary, string $from, string $to): array
{
    $summary['approved_orders_merged'] = 0;

    return $summary;
}

/**
 * @param array<string,mixed> $built
 * @return array<string,mixed>
 */
function sal_customer_order_statement_merge_crm(
    PDO $pdo,
    int $customerId,
    array $built,
    string $from,
    string $to
): array {
    $built['approved_orders_merged'] = 0;

    return $built;
}
