<?php
declare(strict_types=1);

require_once app_path('includes/inv_item_purchase_cost.php');

/**
 * تكلفة الوحدة للمخزون/COGS: آخر شراء مرحّل حتى التاريخ (إن وُجد) ثم default_cost.
 *
 * @param string|null $asOfDate تاريخ ISO — للتقارير والقيود التاريخية.
 */
function inv_item_inventory_unit_cost(PDO $pdo, int $itemId, ?string $asOfDate = null): float
{
    if ($itemId < 1) {
        return 0.0;
    }
    $fromPurchase = inv_item_last_posted_purchase_unit_price($pdo, $itemId, $asOfDate);
    if ($fromPurchase !== null && $fromPurchase > 0) {
        return $fromPurchase;
    }
    try {
        $st = $pdo->prepare('SELECT COALESCE(default_cost, 0) FROM inv_item WHERE id = ? LIMIT 1');
        $st->execute([$itemId]);

        return round(max(0, (float) $st->fetchColumn()), 6);
    } catch (Throwable $e) {
        return 0.0;
    }
}
