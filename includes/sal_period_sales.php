<?php
declare(strict_types=1);

/**
 * مبيعات الفترة الموحّدة — نفس المنطق في تقرير المبيعات وكشف الذمم ومقارنة حساب المبيعات في GL.
 *
 * المعيار: فواتير بيع مؤكدة ومرحّلة (مالياً ومستودعياً إن لزم) − مرتجعات مبيعات مؤكدة مرحّلة،
 * بتاريخ المستند (invoice_date / return_date)، المبلغ = إجمالي الفاتورة (total) ليطابق «قيمة الفاتورة شامل».
 */

require_once app_path('includes/sal_invoice_schema.php');
require_once app_path('includes/sal_invoice_post.php');
require_once app_path('includes/sal_return_schema.php');
require_once app_path('includes/sal_return_post.php');
require_once app_path('includes/crm_sales_rep_schema.php');

function sal_period_sales_has_rep_column(PDO $pdo): bool
{
    return sal_invoice_column_exists($pdo, 'sal_invoice', 'sales_rep_id');
}

/**
 * صافي مبيعات الفترة (شامل الضريبة) — المصدر الموحّد للتقارير.
 */
function sal_period_net_sales_total(
    PDO $pdo,
    string $from,
    string $to,
    int $customerId = 0,
    int $salesRepId = 0
): float {
    if ($from === '' || $to === '') {
        return 0.0;
    }

    $invoices = sal_period_sum_posted_invoices($pdo, $from, $to, $customerId, $salesRepId, 'total');
    $returns = sal_period_sum_posted_returns($pdo, $from, $to, $customerId, $salesRepId, 'total');

    return round(max(0.0, $invoices - $returns), 6);
}

/** إيراد المبيعات قبل الضريبة (subtotal) — يطابق حركة حساب المبيعات في GL عند فصل ضريبة المخرجات. */
function sal_period_net_sales_subtotal(
    PDO $pdo,
    string $from,
    string $to,
    int $customerId = 0,
    int $salesRepId = 0
): float {
    if ($from === '' || $to === '') {
        return 0.0;
    }

    $invoices = sal_period_sum_posted_invoices($pdo, $from, $to, $customerId, $salesRepId, 'subtotal');
    $returns = sal_period_sum_posted_returns($pdo, $from, $to, $customerId, $salesRepId, 'subtotal');

    return round(max(0.0, $invoices - $returns), 6);
}

/**
 * صافي إيراد حساب المبيعات في الاستاذ العام للفترة (دائن − مدين على حساب إيراد المبيعات/مردودات المبيعات).
 */
function sal_period_net_sales_gl_revenue(PDO $pdo, string $from, string $to): float
{
    if ($from === '' || $to === '') {
        return 0.0;
    }

    require_once app_path('includes/acc_gl.php');
    require_once app_path('includes/acc_report.php');

    if (!acc_journal_has_tables($pdo) || !acc_gl_has_posting_table($pdo)) {
        return 0.0;
    }

    $settings = acc_gl_load_settings($pdo);
    $accountIds = [];
    foreach (['sales_revenue', 'sales_returns'] as $rule) {
        $id = (int) ($settings[$rule]['account_id'] ?? 0);
        if ($id > 0) {
            $accountIds[$id] = true;
        }
    }

    if ($accountIds === []) {
        return 0.0;
    }

    $net = 0.0;
    foreach (array_keys($accountIds) as $accId) {
        $period = acc_report_account_sums($pdo, (int) $accId, $from, $to, false);
        $net += (float) ($period['sum_credit'] ?? 0) - (float) ($period['sum_debit'] ?? 0);
    }

    return round(max(0.0, $net), 6);
}

/**
 * @param 'total'|'subtotal' $amountColumn
 */
function sal_period_sum_posted_invoices(
    PDO $pdo,
    string $from,
    string $to,
    int $customerId,
    int $salesRepId,
    string $amountColumn
): float {
    sal_invoice_ensure_schema($pdo);
    $col = $amountColumn === 'subtotal' ? 'i.subtotal' : 'i.total';
    $postedExpr = sal_invoice_sql_is_posted_expr('i');
    $hasRep = sal_period_sales_has_rep_column($pdo);

    $sql = "SELECT COALESCE(SUM({$col}), 0)
            FROM sal_invoice i
            WHERE i.status = 'confirmed'
              AND i.invoice_date >= ? AND i.invoice_date <= ?
              AND {$postedExpr}";
    $params = [$from, $to];

    if ($customerId > 0) {
        $sql .= ' AND i.customer_id = ?';
        $params[] = $customerId;
    }
    if ($salesRepId > 0 && $hasRep) {
        $sql .= ' AND i.sales_rep_id = ?';
        $params[] = $salesRepId;
    }

    $st = $pdo->prepare($sql);
    $st->execute($params);

    return (float) $st->fetchColumn();
}

/**
 * @param 'total'|'subtotal' $amountColumn
 */
function sal_period_sum_posted_returns(
    PDO $pdo,
    string $from,
    string $to,
    int $customerId,
    int $salesRepId,
    string $amountColumn
): float {
    if (!sal_return_has_tables($pdo)) {
        return 0.0;
    }

    $col = $amountColumn === 'subtotal' ? 'r.subtotal' : 'r.total';
    $postedExpr = sal_return_sql_is_posted_expr('r');
    $hasRep = sal_period_sales_has_rep_column($pdo);

    $sql = "SELECT COALESCE(SUM({$col}), 0)
            FROM sal_return r
            INNER JOIN sal_invoice i ON i.id = r.invoice_id
            WHERE r.status = 'confirmed'
              AND r.return_date >= ? AND r.return_date <= ?
              AND {$postedExpr}";
    $params = [$from, $to];

    if ($customerId > 0) {
        $sql .= ' AND r.customer_id = ?';
        $params[] = $customerId;
    }
    if ($salesRepId > 0 && $hasRep) {
        $sql .= ' AND i.sales_rep_id = ?';
        $params[] = $salesRepId;
    }

    $st = $pdo->prepare($sql);
    $st->execute($params);

    return (float) $st->fetchColumn();
}
