<?php
declare(strict_types=1);

require_once app_path('includes/sal_delivery_schema.php');
require_once app_path('includes/sal_delivery_invoice_link.php');

function sal_delivery_notifications_user_can_see(): bool
{
    return user_can('sales_invoices') || user_can('sales_delivery');
}

/**
 * سندات تسليم مرحّلة بلا فاتورة مبيعات مربوطة.
 *
 * @return list<array<string,mixed>>
 */
function sal_delivery_uninvoiced_alerts(PDO $pdo): array
{
    if (!sal_delivery_notifications_user_can_see() || !sal_delivery_has_table($pdo)) {
        return [];
    }

    sal_delivery_invoice_link_ensure($pdo);
    sal_delivery_prune_all_empty_draft_links($pdo);

    $sql = 'SELECT d.id, d.delivery_no, d.delivery_date, d.customer_id, c.name_ar AS customer_name
            FROM sal_delivery d
            INNER JOIN crm_customer c ON c.id = d.customer_id
            WHERE d.is_posted = 1
              AND NOT EXISTS (
                  SELECT 1 FROM sal_invoice i WHERE i.delivery_id = d.id
              )
            ORDER BY d.delivery_date ASC, d.id ASC
            LIMIT 100';
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];

    foreach ($rows as $row) {
        $deliveryId = (int) ($row['id'] ?? 0);
        if ($deliveryId < 1) {
            continue;
        }
        $out[] = [
            'delivery_id' => $deliveryId,
            'delivery_no' => (string) ($row['delivery_no'] ?? ''),
            'delivery_date' => (string) ($row['delivery_date'] ?? ''),
            'customer_name' => (string) ($row['customer_name'] ?? ''),
            'urgency' => 'pending',
            'urgency_label' => 'يحتاج فوترة',
        ];
    }

    return $out;
}
