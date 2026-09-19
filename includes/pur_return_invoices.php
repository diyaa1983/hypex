<?php
declare(strict_types=1);

require_once app_path('includes/pur_invoice_post.php');
require_once app_path('includes/pur_return_invoice_lines.php');

/**
 * فواتير شراء للمورد — للاختيار في مردود المشتريات.
 * بشكل افتراضي: مرحّلة فقط وبها كميات متبقية للإرجاع.
 *
 * @return list<array<string, mixed>>
 */
function pur_return_invoices_for_supplier(PDO $pdo, int $supplierId, bool $onlyPosted = true): array
{
    if ($supplierId < 1) {
        return [];
    }

    $st = $pdo->prepare(
        "SELECT i.id, i.invoice_no, i.invoice_date, i.total
         FROM pur_invoice i
         WHERE i.supplier_id = ?
           AND i.status = 'confirmed'
         ORDER BY i.invoice_date DESC, i.id DESC
         LIMIT 200"
    );
    $st->execute([$supplierId]);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        $posted = pur_invoice_is_posted($pdo, $id);
        $row['is_posted'] = $posted ? 1 : 0;
        if ((!$onlyPosted || $posted) && pur_return_invoice_has_returnable_lines($pdo, $id)) {
            $out[] = $row;
        }
    }

    return $out;
}
