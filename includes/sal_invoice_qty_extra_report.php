<?php
declare(strict_types=1);

require_once app_path('includes/sal_invoice_schema.php');
require_once app_path('includes/inv_invoice_line_qty.php');
require_once app_path('includes/inv_item_display.php');

/**
 * بنود فواتير بيع مؤكدة فيها كمية إضافية بين تاريخين.
 * يشمل: qty_extra، وبنود سعرها صفر (تُحسب كمية السطر ككمية إضافية).
 *
 * @param int $itemId 0 = جميع المواد، وإلا معرّف المادة
 * @return list<array{item_sku:string,item_name:string,invoice_no:string,invoice_date:string,qty_extra:float}>
 */
function sal_report_invoice_qty_extra_lines(PDO $pdo, int $itemId, string $from, string $to): array
{
    if ($from === '' || $to === '' || $itemId < 0) {
        return [];
    }

    sal_invoice_ensure_schema($pdo);
    inv_invoice_line_ensure_qty_extra($pdo);

    if (!inv_invoice_line_has_qty_extra($pdo, 'sal_invoice_line')) {
        return [];
    }

    $effectiveQty = inv_invoice_line_sql_effective_qty_extra('l');

    $itemNoSql = inv_item_sql_material_number($pdo, 'it');
    $sql =
        'SELECT l.id AS _line_id, i.id AS _inv_id,
                ' . $itemNoSql . ' AS item_sku,
                COALESCE(NULLIF(TRIM(l.line_desc), \'\'), it.name_ar) AS item_name,
                i.invoice_no,
                i.invoice_date,
                ' . $effectiveQty . ' AS qty_extra
         FROM sal_invoice_line l
         INNER JOIN sal_invoice i ON i.id = l.invoice_id
         INNER JOIN inv_item it ON it.id = l.item_id
         WHERE i.status = \'confirmed\'
           AND i.invoice_date >= ?
           AND i.invoice_date <= ?
           AND ' . inv_invoice_line_sql_effective_qty_extra_positive('l');

    $params = [$from, $to];

    if ($itemId > 0) {
        $sql .= ' AND l.item_id = ?';
        $params[] = $itemId;
    }

    $sql .= ' ORDER BY i.invoice_date ASC, i.id ASC, l.id ASC';

    $st = $pdo->prepare($sql);
    $st->execute($params);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $out[] = [
            'item_sku' => (string) ($row['item_sku'] ?? ''),
            'item_name' => (string) ($row['item_name'] ?? ''),
            'invoice_no' => (string) ($row['invoice_no'] ?? ''),
            'invoice_date' => (string) ($row['invoice_date'] ?? ''),
            'qty_extra' => (float) ($row['qty_extra'] ?? 0),
        ];
    }

    return $out;
}
