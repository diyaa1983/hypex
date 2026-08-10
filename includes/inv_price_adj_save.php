<?php
declare(strict_types=1);

require_once app_path('includes/inv_price_adj_schema.php');
require_once app_path('includes/inv_item_sale_price_adj.php');

/**
 * @param list<array{item_id?:mixed, new_sale_price?:mixed, new_wholesale?:mixed}> $lines
 * @return array{ok:bool, error:?string, id:?int, adj_no:?string}
 */
function inv_price_adj_save(
    PDO $pdo,
    int $docId,
    string $adjDate,
    array $lines,
    ?int $userId = null,
    string $notes = ''
): array {
    $out = ['ok' => false, 'error' => null, 'id' => null, 'adj_no' => null];

    if (!inv_price_adj_ensure_schema($pdo)) {
        $out['error'] = 'جداول تعديل الأسعار غير جاهزة. حدّث الصفحة.';

        return $out;
    }

    inv_price_adj_ensure_wholesale_columns($pdo);

    // تاريخ التعديل دائماً تاريخ اليوم (لا يعتمد على إدخال العميل)
    $adjDate = date('Y-m-d');

    $norm = inv_price_adj_normalize_lines($pdo, $lines);
    if ($norm === []) {
        $out['error'] = 'أضف مادة واحدة على الأقل بسعر بيع أو جملة معدّل.';

        return $out;
    }

    if ($docId > 0) {
        $existing = inv_price_adj_doc_by_id($pdo, $docId);
        if ($existing === null) {
            $out['error'] = 'الحركة غير موجودة.';

            return $out;
        }
        if ((string) ($existing['status'] ?? '') === 'posted') {
            $out['error'] = 'لا يمكن تعديل حركة مرحّلة.';

            return $out;
        }
    }

    $tax = inv_item_sale_price_adj_default_tax_percent($pdo);
    $hasWh = inv_price_adj_has_wholesale_line_columns($pdo);

    try {
        $pdo->beginTransaction();

        if ($docId < 1) {
            $adjNo = inv_price_adj_generate_next_no($pdo);
            $st = $pdo->prepare(
                "INSERT INTO inv_price_adj_doc (adj_no, adj_date, status, notes, created_by)
                 VALUES (?,?, 'draft', ?, ?)"
            );
            $st->execute([
                $adjNo,
                $adjDate,
                $notes !== '' ? $notes : null,
                $userId !== null && $userId > 0 ? $userId : null,
            ]);
            $docId = (int) $pdo->lastInsertId();
            $out['adj_no'] = $adjNo;
        } else {
            $st = $pdo->prepare(
                "UPDATE inv_price_adj_doc
                 SET adj_date = ?, notes = ?
                 WHERE id = ? AND status = 'draft'"
            );
            $st->execute([
                $adjDate,
                $notes !== '' ? $notes : null,
                $docId,
            ]);
            $out['adj_no'] = (string) ($existing['adj_no'] ?? '');
            $pdo->prepare('DELETE FROM inv_item_sale_price_adj WHERE doc_id = ?')->execute([$docId]);
        }

        if ($hasWh) {
            $lineSt = $pdo->prepare(
                "INSERT INTO inv_item_sale_price_adj
                 (doc_id, line_no, item_id, old_sale_price, new_sale_price, old_wholesale, new_wholesale,
                  tax_rate_percent, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)"
            );
        } else {
            $lineSt = $pdo->prepare(
                "INSERT INTO inv_item_sale_price_adj
                 (doc_id, line_no, item_id, old_sale_price, new_sale_price, tax_rate_percent, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, 'draft', ?)"
            );
        }

        $lineNo = 0;
        foreach ($norm as $ln) {
            $lineNo++;
            $item = inv_item_sale_price_adj_item_row($pdo, $ln['item_id']);
            if ($item === null) {
                throw new RuntimeException('مادة غير موجودة أو غير نشطة.');
            }
            $oldSale = company_round_unit_price((float) $item['default_sale'], $pdo);
            $oldWh = company_round_unit_price((float) ($item['default_wholesale'] ?? 0), $pdo);
            $newSale = $ln['new_sale_price'];
            $newWh = $ln['new_wholesale'];
            $saleChanged = abs($newSale - $oldSale) >= 0.000001;
            $whChanged = abs($newWh - $oldWh) >= 0.000001;
            if (!$saleChanged && !$whChanged) {
                throw new RuntimeException(
                    'لم يتغيّر أي سعر للمادة: ' . (string) ($item['name_ar'] ?? '')
                );
            }
            if ($hasWh) {
                $lineSt->execute([
                    $docId,
                    $lineNo,
                    $ln['item_id'],
                    $oldSale,
                    $newSale,
                    $oldWh,
                    $newWh,
                    $tax,
                    $userId !== null && $userId > 0 ? $userId : null,
                ]);
            } else {
                $lineSt->execute([
                    $docId,
                    $lineNo,
                    $ln['item_id'],
                    $oldSale,
                    $newSale,
                    $tax,
                    $userId !== null && $userId > 0 ? $userId : null,
                ]);
            }
        }

        $pdo->commit();
        $out['ok'] = true;
        $out['id'] = $docId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $msg = trim((string) $e->getMessage());
        $out['error'] = $msg !== '' && mb_strlen($msg) < 200
            ? $msg
            : 'تعذر الحفظ.';
    }

    return $out;
}

function inv_price_adj_has_wholesale_line_columns(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT old_wholesale, new_wholesale FROM inv_item_sale_price_adj LIMIT 1');

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @param list<array{item_id?:mixed, new_sale_price?:mixed, new_wholesale?:mixed}> $lines
 * @return list<array{item_id:int, new_sale_price:float, new_wholesale:float}>
 */
function inv_price_adj_normalize_lines(PDO $pdo, array $lines): array
{
    $out = [];
    $seen = [];
    foreach ($lines as $ln) {
        if (!is_array($ln)) {
            continue;
        }
        $itemId = (int) ($ln['item_id'] ?? 0);
        if ($itemId < 1 || isset($seen[$itemId])) {
            continue;
        }
        $item = inv_item_sale_price_adj_item_row($pdo, $itemId);
        if ($item === null) {
            continue;
        }
        $oldSale = company_round_unit_price((float) $item['default_sale'], $pdo);
        $oldWh = company_round_unit_price((float) ($item['default_wholesale'] ?? 0), $pdo);

        $rawSale = $ln['new_sale_price'] ?? null;
        $rawWh = $ln['new_wholesale'] ?? null;
        $newSale = ($rawSale === '' || $rawSale === null)
            ? $oldSale
            : company_round_unit_price((float) str_replace(',', '.', (string) $rawSale), $pdo);
        $newWh = ($rawWh === '' || $rawWh === null)
            ? $oldWh
            : company_round_unit_price((float) str_replace(',', '.', (string) $rawWh), $pdo);

        if ($newSale < 0 || $newWh < 0) {
            continue;
        }
        if (abs($newSale - $oldSale) < 0.000001 && abs($newWh - $oldWh) < 0.000001) {
            continue;
        }
        $seen[$itemId] = true;
        $out[] = [
            'item_id' => $itemId,
            'new_sale_price' => $newSale,
            'new_wholesale' => $newWh,
        ];
    }

    return $out;
}
