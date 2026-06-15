<?php
declare(strict_types=1);

require_once app_path('includes/inv_stock.php');
require_once app_path('includes/sal_delivery_schema.php');

function sal_delivery_stock_is_posted(PDO $pdo, int $deliveryId): bool
{
    if ($deliveryId < 1 || !inv_stock_move_has_table($pdo)) {
        return false;
    }
    $st = $pdo->prepare(
        "SELECT id FROM inv_stock_move WHERE ref_type = 'sale_delivery' AND ref_id = ? LIMIT 1"
    );
    $st->execute([$deliveryId]);

    return (bool) $st->fetch();
}

/**
 * @return array{ok:bool, skipped:bool, error:?string}
 */
function sal_delivery_stock_post(PDO $pdo, int $deliveryId): array
{
    $out = ['ok' => false, 'skipped' => false, 'error' => null];

    if ($deliveryId < 1) {
        $out['error'] = 'معرّف السند غير صالح.';

        return $out;
    }
    if (sal_delivery_stock_is_posted($pdo, $deliveryId)) {
        $out['ok'] = true;
        $out['skipped'] = true;

        return $out;
    }

    $hdr = $pdo->prepare(
        'SELECT delivery_no, delivery_date, warehouse_id FROM sal_delivery WHERE id = ? LIMIT 1'
    );
    $hdr->execute([$deliveryId]);
    $del = $hdr->fetch(PDO::FETCH_ASSOC);
    if (!$del) {
        $out['error'] = 'السند غير موجود.';

        return $out;
    }

    $warehouseId = (int) ($del['warehouse_id'] ?? 0);
    $whCount = (int) $pdo->query('SELECT COUNT(*) FROM inv_warehouse WHERE is_active = 1')->fetchColumn();
    if ($whCount > 0 && $warehouseId < 1) {
        $out['error'] = 'المستودع غير محدد على سند التسليم.';

        return $out;
    }
    if ($whCount < 1) {
        $out['ok'] = true;
        $out['skipped'] = true;

        return $out;
    }

    $hasTrack = false;
    try {
        $pdo->query('SELECT track_inventory FROM inv_item LIMIT 1');
        $hasTrack = true;
    } catch (Throwable $e) {
        $hasTrack = false;
    }
    if (!$hasTrack) {
        $out['ok'] = true;
        $out['skipped'] = true;

        return $out;
    }

    $lines = $pdo->prepare(
        'SELECT l.item_id, l.qty, l.line_desc, i.name_ar, i.track_inventory
         FROM sal_delivery_line l
         INNER JOIN inv_item i ON i.id = l.item_id
         WHERE l.delivery_id = ? AND i.track_inventory = 1 AND l.qty > 0
         ORDER BY l.sort_order ASC, l.id ASC'
    );
    $lines->execute([$deliveryId]);
    $rows = $lines->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $deliveryNo = (string) ($del['delivery_no'] ?? '');
    $moveDate = (string) ($del['delivery_date'] ?? date('Y-m-d'));

    foreach ($rows as $row) {
        $itemId = (int) $row['item_id'];
        $qty = (float) ($row['qty'] ?? 0);
        if ($itemId < 1 || $qty <= 0) {
            continue;
        }
        $note = 'صرف سند تسليم ' . $deliveryNo;
        $move = inv_stock_issue($pdo, $moveDate, $warehouseId, $itemId, $qty, 'sale_delivery', $deliveryId, $note);
        if (!$move['ok']) {
            $name = (string) ($row['name_ar'] ?? $row['line_desc'] ?? ('#' . $itemId));
            $out['error'] = ($move['error'] ?? 'تعذر صرف المخزون.') . ' — «' . $name . '»';

            return $out;
        }
    }

    $out['ok'] = true;

    return $out;
}

/**
 * @return array{ok:bool, error:?string}
 */
function sal_delivery_stock_unpost(PDO $pdo, int $deliveryId): array
{
    if ($deliveryId < 1) {
        return ['ok' => false, 'error' => 'معرّف السند غير صالح.'];
    }
    if (!inv_stock_move_has_table($pdo)) {
        return ['ok' => true, 'error' => null];
    }
    if (!sal_delivery_stock_is_posted($pdo, $deliveryId)) {
        return ['ok' => true, 'error' => null];
    }

    $pdo->prepare("DELETE FROM inv_stock_move WHERE ref_type = 'sale_delivery' AND ref_id = ?")
        ->execute([$deliveryId]);

    return ['ok' => true, 'error' => null];
}
