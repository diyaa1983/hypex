<?php
declare(strict_types=1);

require_once app_path('includes/sal_invoice_schema.php');
require_once app_path('includes/sal_invoice_post.php');

/**
 * فواتير مبيعات مؤكدة لمندوب بين تاريخين.
 *
 * @return list<array<string, mixed>>
 */
function sal_report_sales_by_rep(PDO $pdo, int $salesRepId, string $from, string $to): array
{
    if ($salesRepId < 1 || $from === '' || $to === '') {
        return [];
    }

    sal_invoice_ensure_schema($pdo);

    if (!sal_invoice_column_exists($pdo, 'sal_invoice', 'sales_rep_id')) {
        return [];
    }

    $postedExpr = sal_invoice_sql_is_posted_expr('i');

    $st = $pdo->prepare(
        "SELECT i.id, i.invoice_no, i.invoice_date, i.subtotal, i.total, i.payment_type,
                c.name_ar AS customer_name,
                (CASE WHEN {$postedExpr} THEN 1 ELSE 0 END) AS is_posted
         FROM sal_invoice i
         INNER JOIN crm_customer c ON c.id = i.customer_id
         WHERE i.sales_rep_id = ?
           AND i.status = 'confirmed'
           AND i.invoice_date >= ?
           AND i.invoice_date <= ?
         ORDER BY i.invoice_date ASC, i.id ASC"
    );
    $st->execute([$salesRepId, $from, $to]);

    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $pay = (string) ($row['payment_type'] ?? 'cash');
        $posted = (int) ($row['is_posted'] ?? 0) === 1;
        $rows[] = [
            'id' => (int) ($row['id'] ?? 0),
            'invoice_no' => (string) ($row['invoice_no'] ?? ''),
            'invoice_date' => (string) ($row['invoice_date'] ?? ''),
            'customer_name' => (string) ($row['customer_name'] ?? ''),
            'subtotal' => (float) ($row['subtotal'] ?? 0),
            'total' => (float) ($row['total'] ?? 0),
            'payment_type' => $pay,
            'payment_label' => $pay === 'credit' ? 'ذمم' : 'نقدي',
            'is_posted' => $posted ? 1 : 0,
            'posted_label' => $posted ? 'مرحّلة' : 'غير مرحّلة',
        ];
    }

    return $rows;
}
