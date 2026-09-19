<?php
declare(strict_types=1);

require_once app_path('includes/sal_invoice_schema.php');
require_once app_path('includes/sal_invoice_post.php');
require_once app_path('includes/sal_return_schema.php');
require_once app_path('includes/sal_return_post.php');

/**
 * فواتير ومرتجعات مبيعات مرحّلة بين تاريخين (لعميل/مندوب محدد أو الكل).
 * المرتجعات تُعرض بقيم سالبة ليطابق الإجمالي صافي المبيعات.
 *
 * @return list<array<string, mixed>>
 */
function sal_report_sales_by_customer(
    PDO $pdo,
    int $customerId,
    string $from,
    string $to,
    int $salesRepId = 0
): array
{
    if ($from === '' || $to === '' || $customerId < 0) {
        return [];
    }

    $invoices = sal_report_sales_fetch_invoices($pdo, $customerId, $from, $to, $salesRepId);
    $returns = sal_report_sales_fetch_returns($pdo, $customerId, $from, $to, $salesRepId);
    $rows = array_merge($invoices, $returns);

    usort($rows, static function (array $a, array $b) use ($customerId): int {
        if ($customerId === 0) {
            $byCust = strcmp((string) ($a['customer_name'] ?? ''), (string) ($b['customer_name'] ?? ''));
            if ($byCust !== 0) {
                return $byCust;
            }
        }
        $byDate = strcmp((string) ($a['invoice_date'] ?? ''), (string) ($b['invoice_date'] ?? ''));
        if ($byDate !== 0) {
            return $byDate;
        }
        $kindOrder = ['invoice' => 0, 'return' => 1];
        $ka = $kindOrder[(string) ($a['doc_kind'] ?? 'invoice')] ?? 0;
        $kb = $kindOrder[(string) ($b['doc_kind'] ?? 'invoice')] ?? 0;
        if ($ka !== $kb) {
            return $ka <=> $kb;
        }

        return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
    });

    return $rows;
}

/**
 * @return array{subtotal:float,total:float,invoice_count:int,return_count:int}
 */
function sal_report_sales_rows_totals(array $rows): array
{
    $sub = 0.0;
    $tot = 0.0;
    $inv = 0;
    $ret = 0;
    foreach ($rows as $r) {
        $sub += (float) ($r['subtotal'] ?? 0);
        $tot += (float) ($r['total'] ?? 0);
        if (($r['doc_kind'] ?? '') === 'return') {
            $ret++;
        } else {
            $inv++;
        }
    }

    return [
        'subtotal' => round($sub, 6),
        'total' => round($tot, 6),
        'invoice_count' => $inv,
        'return_count' => $ret,
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function sal_report_sales_fetch_invoices(
    PDO $pdo,
    int $customerId,
    string $from,
    string $to,
    int $salesRepId
): array
{
    sal_invoice_ensure_schema($pdo);

    $postedExpr = sal_invoice_sql_is_posted_expr('i');
    $custFilter = $customerId > 0 ? ' AND i.customer_id = ? ' : '';
    $repFilter = $salesRepId > 0 ? ' AND i.sales_rep_id = ? ' : '';
    $params = [$from, $to];
    if ($customerId > 0) {
        $params[] = $customerId;
    }
    if ($salesRepId > 0) {
        $params[] = $salesRepId;
    }

    $itemsSearchSub = "(SELECT GROUP_CONCAT(DISTINCT CONCAT_WS(' ', it.name_ar, it.sku, it.barcode) SEPARATOR ' ')
            FROM sal_invoice_line il
            INNER JOIN inv_item it ON it.id = il.item_id
            WHERE il.invoice_id = i.id)";

    $st = $pdo->prepare(
        "SELECT i.id, i.invoice_no, i.invoice_date, i.subtotal, i.total, i.payment_type,
                c.name_ar AS customer_name,
                sr.name_ar AS sales_rep_name,
                {$itemsSearchSub} AS items_search_text
         FROM sal_invoice i
         INNER JOIN crm_customer c ON c.id = i.customer_id
         LEFT JOIN crm_sales_rep sr ON sr.id = i.sales_rep_id
         WHERE i.status = 'confirmed'
           AND i.invoice_date >= ?
           AND i.invoice_date <= ?
           AND {$postedExpr}
           {$custFilter}
           {$repFilter}"
    );
    $st->execute($params);

    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $pay = (string) ($row['payment_type'] ?? 'cash');
        $rows[] = sal_report_sales_row_invoice($row, $pay);
    }

    return $rows;
}

/**
 * @return list<array<string, mixed>>
 */
function sal_report_sales_fetch_returns(
    PDO $pdo,
    int $customerId,
    string $from,
    string $to,
    int $salesRepId
): array
{
    if (!sal_return_has_tables($pdo)) {
        return [];
    }

    $postedExpr = sal_return_sql_is_posted_expr('r');
    $custFilter = $customerId > 0 ? ' AND r.customer_id = ? ' : '';
    $repFilter = $salesRepId > 0 ? ' AND i.sales_rep_id = ? ' : '';
    $params = [$from, $to];
    if ($customerId > 0) {
        $params[] = $customerId;
    }
    if ($salesRepId > 0) {
        $params[] = $salesRepId;
    }

    $itemsSearchSub = "(SELECT GROUP_CONCAT(DISTINCT CONCAT_WS(' ', it.name_ar, it.sku, it.barcode) SEPARATOR ' ')
            FROM sal_return_line rl
            INNER JOIN inv_item it ON it.id = rl.item_id
            WHERE rl.return_id = r.id)";

    $st = $pdo->prepare(
        "SELECT r.id, r.return_no, r.return_date, r.subtotal, r.total,
                c.name_ar AS customer_name,
                sr.name_ar AS sales_rep_name,
                i.payment_type,
                {$itemsSearchSub} AS items_search_text
         FROM sal_return r
         INNER JOIN sal_invoice i ON i.id = r.invoice_id
         INNER JOIN crm_customer c ON c.id = r.customer_id
         LEFT JOIN crm_sales_rep sr ON sr.id = i.sales_rep_id
         WHERE r.status = 'confirmed'
           AND r.return_date >= ?
           AND r.return_date <= ?
           AND {$postedExpr}
           {$custFilter}
           {$repFilter}"
    );
    $st->execute($params);

    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $rows[] = sal_report_sales_row_return($row);
    }

    return $rows;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function sal_report_sales_row_invoice(array $row, string $pay): array
{
    return [
        'doc_kind' => 'invoice',
        'id' => (int) ($row['id'] ?? 0),
        'invoice_no' => (string) ($row['invoice_no'] ?? ''),
        'customer_name' => (string) ($row['customer_name'] ?? ''),
        'sales_rep_name' => (string) ($row['sales_rep_name'] ?? ''),
        'invoice_date' => (string) ($row['invoice_date'] ?? ''),
        'subtotal' => (float) ($row['subtotal'] ?? 0),
        'total' => (float) ($row['total'] ?? 0),
        'payment_type' => $pay,
        'payment_label' => $pay === 'credit' ? 'ذمم' : 'نقدي',
        'is_posted' => 1,
        'posted_label' => 'مرحّلة',
        'items_search_text' => (string) ($row['items_search_text'] ?? ''),
    ];
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function sal_report_sales_row_return(array $row): array
{
    $sub = (float) ($row['subtotal'] ?? 0);
    $tot = (float) ($row['total'] ?? 0);

    return [
        'doc_kind' => 'return',
        'id' => (int) ($row['id'] ?? 0),
        'invoice_no' => (string) ($row['return_no'] ?? ''),
        'customer_name' => (string) ($row['customer_name'] ?? ''),
        'sales_rep_name' => (string) ($row['sales_rep_name'] ?? ''),
        'invoice_date' => (string) ($row['return_date'] ?? ''),
        'subtotal' => $sub > 0 ? -$sub : $sub,
        'total' => $tot > 0 ? -$tot : $tot,
        'payment_type' => 'return',
        'payment_label' => 'مرتجع',
        'is_posted' => 1,
        'posted_label' => 'مرحّلة',
        'items_search_text' => (string) ($row['items_search_text'] ?? ''),
    ];
}
