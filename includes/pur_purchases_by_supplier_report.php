<?php
declare(strict_types=1);

require_once app_path('includes/pur_invoice_schema.php');
require_once app_path('includes/pur_invoice_post.php');

/**
 * فواتير مشتريات مؤكدة بين تاريخين، لمورد محدد أو جميع الموردين.
 *
 * @return list<array<string, mixed>>
 */
function pur_report_purchases_by_supplier(PDO $pdo, int $supplierId, string $from, string $to): array
{
    if ($from === '' || $to === '' || $supplierId < 0) {
        return [];
    }

    pur_invoice_ensure_schema($pdo);

    $postedExpr = pur_invoice_sql_is_posted_expr('i');
    $hasPay = pur_invoice_has_payment_type($pdo);

    $payCol = $hasPay ? 'i.payment_type' : "'credit' AS payment_type";
    $supFilter = $supplierId > 0 ? ' AND i.supplier_id = ? ' : '';
    $params = [$from, $to];
    if ($supplierId > 0) {
        $params[] = $supplierId;
    }

    $orderBy = $supplierId > 0
        ? 'i.invoice_date ASC, i.id ASC'
        : 's.name_ar ASC, i.invoice_date ASC, i.id ASC';

    $st = $pdo->prepare(
        "SELECT i.id, i.invoice_no, i.invoice_date, i.subtotal, i.total, {$payCol},
                s.name_ar AS supplier_name,
                (CASE WHEN {$postedExpr} THEN 1 ELSE 0 END) AS is_posted
         FROM pur_invoice i
         INNER JOIN crm_supplier s ON s.id = i.supplier_id
         WHERE i.status = 'confirmed'
           AND i.invoice_date >= ?
           AND i.invoice_date <= ?
           {$supFilter}
         ORDER BY {$orderBy}"
    );
    $st->execute($params);

    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $pay = (string) ($row['payment_type'] ?? 'credit');
        $posted = (int) ($row['is_posted'] ?? 0) === 1;
        $rows[] = [
            'id' => (int) ($row['id'] ?? 0),
            'invoice_no' => (string) ($row['invoice_no'] ?? ''),
            'supplier_name' => (string) ($row['supplier_name'] ?? ''),
            'invoice_date' => (string) ($row['invoice_date'] ?? ''),
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
