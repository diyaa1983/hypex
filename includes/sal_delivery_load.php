<?php
declare(strict_types=1);

require_once app_path('includes/sal_delivery_schema.php');
require_once app_path('includes/sal_delivery_browse.php');

function sal_delivery_fetch_lines(PDO $pdo, int $deliveryId): array
{
    $st = $pdo->prepare(
        'SELECT l.id, l.item_id, l.line_desc, l.qty, l.sort_order,
                i.name_ar, i.barcode, i.sku
         FROM sal_delivery_line l
         INNER JOIN inv_item i ON i.id = l.item_id
         WHERE l.delivery_id = ?
         ORDER BY l.sort_order ASC, l.id ASC'
    );
    $st->execute([$deliveryId]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function sal_delivery_enrich_row(PDO $pdo, array $row): array
{
    $id = (int) ($row['id'] ?? 0);
    $cid = (int) ($row['customer_id'] ?? 0);
    $st = $pdo->prepare('SELECT name_ar, code FROM crm_customer WHERE id = ? LIMIT 1');
    $st->execute([$cid]);
    $cust = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    $row['customer_name'] = (string) ($cust['name_ar'] ?? '');
    $row['customer_code'] = (string) ($cust['code'] ?? '');
    $row['warehouse_id'] = (int) ($row['warehouse_id'] ?? 0);
    $row['warehouse_name'] = '';
    if ($row['warehouse_id'] > 0) {
        $wh = $pdo->prepare('SELECT name_ar FROM inv_warehouse WHERE id = ? LIMIT 1');
        $wh->execute([$row['warehouse_id']]);
        $row['warehouse_name'] = (string) ($wh->fetchColumn() ?: '');
    }
    $row['is_posted'] = (int) ($row['is_posted'] ?? 0) === 1;
    $row['lines'] = sal_delivery_fetch_lines($pdo, $id);
    $row['prev_id'] = sal_delivery_nav_neighbor_id($pdo, $id, 'prev') ?? 0;
    $row['next_id'] = sal_delivery_nav_neighbor_id($pdo, $id, 'next') ?? 0;
    $row['browse_count'] = sal_delivery_count_all($pdo);

    $row['linked_invoice_id'] = 0;
    $row['linked_invoice_no'] = '';
    $row['linked_invoice_is_posted'] = false;
    require_once app_path('includes/sal_delivery_invoice_link.php');
    $linkedInv = sal_delivery_first_linked_invoice($pdo, $id);
    if ($linkedInv !== null) {
        $row['linked_invoice_id'] = (int) ($linkedInv['id'] ?? 0);
        $row['linked_invoice_no'] = (string) ($linkedInv['invoice_no'] ?? '');
        $row['linked_invoice_is_posted'] = !empty($linkedInv['is_posted']);
    }

    return $row;
}

function sal_delivery_fetch_by_id(PDO $pdo, int $id): ?array
{
    if ($id < 1 || !sal_delivery_has_table($pdo)) {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM sal_delivery WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ? sal_delivery_enrich_row($pdo, $row) : null;
}

function sal_delivery_fetch_by_no(PDO $pdo, string $deliveryNo): ?array
{
    require_once app_path('includes/doc_no_fragment_search.php');

    return doc_no_fetch_exact_or_fragment(
        $pdo,
        $deliveryNo,
        'SELECT id FROM sal_delivery WHERE delivery_no = ? LIMIT 1',
        [trim($deliveryNo)],
        static fn (string $frag) => sal_delivery_search_ids_by_no_fragment($pdo, $frag),
        static fn (int $id) => sal_delivery_fetch_by_id($pdo, $id)
    );
}
