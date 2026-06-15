<?php
declare(strict_types=1);

require_once app_path('includes/company_settings.php');
require_once app_path('includes/pur_invoice_schema.php');

/**
 * آخر سعر شراء (قبل الضريبة) لمادة من فاتورة شراء مؤكدة ومرحّلة ماليًا.
 *
 * @param string|null $asOfDate تاريخ ISO (Y-m-d): آخر شراء حتى هذا التاريخ فقط (للتقارير التاريخية).
 */
function inv_item_last_posted_purchase_unit_price(PDO $pdo, int $itemId, ?string $asOfDate = null): ?float
{
    if ($itemId < 1) {
        return null;
    }

    pur_invoice_ensure_schema($pdo);

    try {
        $sql =
            "SELECT pl.unit_price
             FROM pur_invoice_line pl
             INNER JOIN pur_invoice i ON i.id = pl.invoice_id
             WHERE pl.item_id = ?
               AND i.status = 'confirmed'
               AND EXISTS (
                   SELECT 1 FROM crm_supplier_ledger l
                   WHERE l.txn_type = 'purchase_invoice' AND l.ref_id = i.id
               )";
        $params = [$itemId];
        if ($asOfDate !== null && $asOfDate !== '') {
            $sql .= ' AND i.invoice_date <= ?';
            $params[] = $asOfDate;
        }
        $sql .= ' ORDER BY i.invoice_date DESC, i.id DESC, pl.id DESC LIMIT 1';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        if ($v === false || $v === null) {
            return null;
        }

        $price = (float) $v;

        return $price >= 0 ? company_round_unit_price($price, $pdo) : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * تحديث default_cost على بطاقة المادة من آخر فاتورة شراء مرحّلة لكل بند في الفاتورة.
 */
function inv_item_sync_costs_after_purchase_invoice_post(PDO $pdo, int $invoiceId): void
{
    if ($invoiceId < 1) {
        return;
    }

    pur_invoice_ensure_schema($pdo);

    try {
        $st = $pdo->prepare(
            'SELECT DISTINCT item_id FROM pur_invoice_line WHERE invoice_id = ? AND item_id > 0'
        );
        $st->execute([$invoiceId]);
        $itemIds = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        return;
    }

    if ($itemIds === []) {
        return;
    }

    $upd = $pdo->prepare('UPDATE inv_item SET default_cost = ? WHERE id = ?');
    foreach ($itemIds as $rawId) {
        $itemId = (int) $rawId;
        if ($itemId < 1) {
            continue;
        }
        $unitPrice = inv_item_last_posted_purchase_unit_price($pdo, $itemId);
        if ($unitPrice === null) {
            continue;
        }
        $upd->execute([$unitPrice, $itemId]);
    }
}
