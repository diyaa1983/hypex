<?php
declare(strict_types=1);

require_once app_path('includes/inv_price_adj_schema.php');

/**
 * @return array{ok:bool, error:?string}
 */
function inv_price_adj_post_document(PDO $pdo, int $docId, ?int $userId = null): array
{
    $out = ['ok' => false, 'error' => null];

    if ($docId < 1 || !inv_price_adj_ensure_schema($pdo)) {
        $out['error'] = 'بيانات غير صالحة.';

        return $out;
    }

    inv_price_adj_ensure_wholesale_columns($pdo);

    $doc = inv_price_adj_doc_by_id($pdo, $docId);
    if ($doc === null) {
        $out['error'] = 'الحركة غير موجودة.';

        return $out;
    }
    if ((string) ($doc['status'] ?? '') === 'posted') {
        $out['error'] = 'تم ترحيل هذه الحركة مسبقًا.';

        return $out;
    }

    $lines = inv_price_adj_lines($pdo, $docId);
    if ($lines === []) {
        $out['error'] = 'لا توجد بنود للترحيل.';

        return $out;
    }

    $hasItemWh = false;
    try {
        $pdo->query('SELECT default_wholesale FROM inv_item LIMIT 1');
        $hasItemWh = true;
    } catch (Throwable $e) {
        $hasItemWh = false;
    }
    $hasLineWh = false;
    try {
        $pdo->query('SELECT new_wholesale FROM inv_item_sale_price_adj LIMIT 1');
        $hasLineWh = true;
    } catch (Throwable $e) {
        $hasLineWh = false;
    }
    $hasPostedBy = false;
    try {
        $pdo->query('SELECT posted_by FROM inv_price_adj_doc LIMIT 1');
        $hasPostedBy = true;
    } catch (Throwable $e) {
        $hasPostedBy = false;
    }

    try {
        $pdo->beginTransaction();
        if ($hasItemWh && $hasLineWh) {
            $updItem = $pdo->prepare('UPDATE inv_item SET default_sale = ?, default_wholesale = ? WHERE id = ?');
        } else {
            $updItem = $pdo->prepare('UPDATE inv_item SET default_sale = ? WHERE id = ?');
        }
        foreach ($lines as $ln) {
            $itemId = (int) ($ln['item_id'] ?? 0);
            $newSale = company_round_unit_price((float) ($ln['new_sale_price'] ?? 0), $pdo);
            if ($itemId < 1) {
                continue;
            }
            if ($hasItemWh && $hasLineWh) {
                $newWh = company_round_unit_price((float) ($ln['new_wholesale'] ?? 0), $pdo);
                $updItem->execute([$newSale, $newWh, $itemId]);
            } else {
                $updItem->execute([$newSale, $itemId]);
            }
        }
        $pdo->prepare(
            "UPDATE inv_item_sale_price_adj SET status = 'posted', posted_at = NOW() WHERE doc_id = ?"
        )->execute([$docId]);

        if ($hasPostedBy) {
            $pdo->prepare(
                "UPDATE inv_price_adj_doc
                 SET status = 'posted', posted_at = NOW(), posted_by = ?
                 WHERE id = ? AND status = 'draft'"
            )->execute([
                $userId !== null && $userId > 0 ? $userId : null,
                $docId,
            ]);
        } else {
            $pdo->prepare(
                "UPDATE inv_price_adj_doc SET status = 'posted', posted_at = NOW() WHERE id = ? AND status = 'draft'"
            )->execute([$docId]);
        }
        $pdo->commit();
        $out['ok'] = true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $out['error'] = 'تعذر الترحيل.';
    }

    return $out;
}
