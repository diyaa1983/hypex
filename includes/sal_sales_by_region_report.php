<?php
declare(strict_types=1);

require_once app_path('includes/sal_invoice_schema.php');
require_once app_path('includes/sal_invoice_post.php');
require_once app_path('includes/crm_region.php');

/**
 * ملخص مبيعات الفواتير المؤكدة مجمّعة حسب منطقة العميل.
 *
 * @return list<array{
 *   region_id:int, region_name:string, invoice_count:int, customer_count:int,
 *   subtotal:float, total:float
 * }>
 */
function sal_report_sales_by_region_summary(PDO $pdo, string $from, string $to): array
{
    if ($from === '' || $to === '') {
        return [];
    }

    sal_invoice_ensure_schema($pdo);
    crm_region_ensure_schema($pdo);

    $st = $pdo->prepare(
        "SELECT COALESCE(rg.id, 0) AS region_id,
                COALESCE(rg.name_ar, 'بدون منطقة') AS region_name,
                COUNT(i.id) AS invoice_count,
                COUNT(DISTINCT i.customer_id) AS customer_count,
                COALESCE(SUM(i.subtotal), 0) AS subtotal,
                COALESCE(SUM(i.total), 0) AS total
         FROM sal_invoice i
         INNER JOIN crm_customer c ON c.id = i.customer_id
         LEFT JOIN crm_region rg ON rg.id = c.region_id
         WHERE i.status = 'confirmed'
           AND i.invoice_date >= ?
           AND i.invoice_date <= ?
         GROUP BY COALESCE(rg.id, 0), COALESCE(rg.name_ar, 'بدون منطقة')
         ORDER BY total DESC, region_name ASC"
    );
    $st->execute([$from, $to]);

    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $rows[] = [
            'region_id' => (int) ($row['region_id'] ?? 0),
            'region_name' => (string) ($row['region_name'] ?? 'بدون منطقة'),
            'invoice_count' => (int) ($row['invoice_count'] ?? 0),
            'customer_count' => (int) ($row['customer_count'] ?? 0),
            'subtotal' => (float) ($row['subtotal'] ?? 0),
            'total' => (float) ($row['total'] ?? 0),
        ];
    }

    return $rows;
}

/**
 * تفصيل فواتير منطقة (region_id=0 = عملاء بلا منطقة).
 *
 * @return list<array<string, mixed>>
 */
function sal_report_sales_by_region_detail(PDO $pdo, int $regionId, string $from, string $to): array
{
    if ($from === '' || $to === '') {
        return [];
    }

    sal_invoice_ensure_schema($pdo);
    crm_region_ensure_schema($pdo);

    $postedExpr = sal_invoice_sql_is_posted_expr('i');

    if ($regionId > 0) {
        $sql = "SELECT i.id, i.invoice_no, i.invoice_date, i.subtotal, i.total, i.payment_type,
                       c.name_ar AS customer_name, c.code AS customer_code,
                       COALESCE(r.name_ar, '') AS sales_rep_name,
                       (CASE WHEN {$postedExpr} THEN 1 ELSE 0 END) AS is_posted
                FROM sal_invoice i
                INNER JOIN crm_customer c ON c.id = i.customer_id
                LEFT JOIN crm_sales_rep r ON r.id = i.sales_rep_id
                WHERE c.region_id = ?
                  AND i.status = 'confirmed'
                  AND i.invoice_date >= ?
                  AND i.invoice_date <= ?
                ORDER BY i.invoice_date ASC, i.id ASC";
        $params = [$regionId, $from, $to];
    } else {
        $sql = "SELECT i.id, i.invoice_no, i.invoice_date, i.subtotal, i.total, i.payment_type,
                       c.name_ar AS customer_name, c.code AS customer_code,
                       COALESCE(r.name_ar, '') AS sales_rep_name,
                       (CASE WHEN {$postedExpr} THEN 1 ELSE 0 END) AS is_posted
                FROM sal_invoice i
                INNER JOIN crm_customer c ON c.id = i.customer_id
                LEFT JOIN crm_sales_rep r ON r.id = i.sales_rep_id
                WHERE c.region_id IS NULL
                  AND i.status = 'confirmed'
                  AND i.invoice_date >= ?
                  AND i.invoice_date <= ?
                ORDER BY i.invoice_date ASC, i.id ASC";
        $params = [$from, $to];
    }

    $st = $pdo->prepare($sql);
    $st->execute($params);

    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $pay = (string) ($row['payment_type'] ?? 'cash');
        $posted = (int) ($row['is_posted'] ?? 0) === 1;
        $rows[] = [
            'id' => (int) ($row['id'] ?? 0),
            'invoice_no' => (string) ($row['invoice_no'] ?? ''),
            'invoice_date' => (string) ($row['invoice_date'] ?? ''),
            'customer_name' => (string) ($row['customer_name'] ?? ''),
            'customer_code' => (string) ($row['customer_code'] ?? ''),
            'sales_rep_name' => (string) ($row['sales_rep_name'] ?? ''),
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
