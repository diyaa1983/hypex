<?php
declare(strict_types=1);

require_once app_path('includes/company_settings.php');
require_once app_path('includes/inv_item_display.php');

function inv_item_sale_price_adj_has_table(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT id FROM inv_item_sale_price_adj LIMIT 1');

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function inv_item_sale_price_adj_ensure_table(PDO $pdo): void
{
    require_once app_path('includes/sql_migration.php');
    sql_migration_run_file($pdo, 'database/migrations/050_inv_item_sale_price_adj.sql');
    inv_item_sale_price_adj_has_table($pdo);
}

function inv_item_sale_price_adj_default_tax_percent(?PDO $pdo = null): float
{
    $settings = company_settings($pdo);

    return (float) ($settings['tax_rate_percent'] ?? 15.0);
}

/**
 * @return array{id:int, sku:string, barcode:string, material_number:string, name_ar:string, default_sale:float, default_wholesale:float}|null
 */
function inv_item_sale_price_adj_item_row(PDO $pdo, int $itemId): ?array
{
    if ($itemId < 1) {
        return null;
    }
    $barcodeCol = inv_item_has_barcode_column($pdo) ? ', barcode' : '';
    $whCol = '';
    try {
        $pdo->query('SELECT default_wholesale FROM inv_item LIMIT 1');
        $whCol = ', default_wholesale';
    } catch (Throwable $e) {
        $whCol = '';
    }
    $st = $pdo->prepare(
        'SELECT id, sku' . $barcodeCol . ', name_ar, default_sale' . $whCol . ' FROM inv_item WHERE id = ? AND is_active = 1 LIMIT 1'
    );
    $st->execute([$itemId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $barcode = (string) ($row['barcode'] ?? '');
    $sku = (string) ($row['sku'] ?? '');
    $defaultSale = (float) ($row['default_sale'] ?? 0);
    $defaultWholesale = (float) ($row['default_wholesale'] ?? 0);

    // إذا لم يُحدّد سعر بيع على البطاقة (أو = 0)، حاول استرجاع آخر سعر فعلي
    // من سجل تعديلات الأسعار المرحّل، ثم من آخر بند بفاتورة مبيعات
    if ($defaultSale <= 0.000001) {
        $defaultSale = inv_item_sale_price_adj_resolve_last_price($pdo, $itemId);
    }

    return [
        'id' => (int) $row['id'],
        'sku' => $sku,
        'barcode' => $barcode,
        'material_number' => inv_item_material_number($barcode, $sku),
        'name_ar' => (string) ($row['name_ar'] ?? ''),
        'default_sale' => $defaultSale,
        'default_wholesale' => $defaultWholesale,
    ];
}

/**
 * يحاول استرجاع آخر سعر بيع فعلي لمادة من:
 * 1) آخر تعديل سعر مرحّل (inv_item_sale_price_adj)
 * 2) آخر بند فاتورة مبيعات لنفس المادة (sal_invoice_line)
 */
function inv_item_sale_price_adj_resolve_last_price(PDO $pdo, int $itemId): float
{
    if ($itemId < 1) {
        return 0.0;
    }

    if (inv_item_sale_price_adj_has_table($pdo)) {
        try {
            $st = $pdo->prepare(
                "SELECT new_sale_price FROM inv_item_sale_price_adj
                 WHERE item_id = ? AND status = 'posted'
                 ORDER BY COALESCE(posted_at, created_at) DESC, id DESC LIMIT 1"
            );
            $st->execute([$itemId]);
            $val = $st->fetchColumn();
            if ($val !== false && (float) $val > 0.000001) {
                return (float) $val;
            }
        } catch (Throwable $e) {
            // ignore and fall through
        }
    }

    try {
        $st = $pdo->prepare(
            'SELECT l.unit_price FROM sal_invoice_line l
             JOIN sal_invoice i ON i.id = l.invoice_id
             WHERE l.item_id = ?
             ORDER BY i.invoice_date DESC, l.id DESC LIMIT 1'
        );
        $st->execute([$itemId]);
        $val = $st->fetchColumn();
        if ($val !== false && (float) $val > 0.000001) {
            return (float) $val;
        }
    } catch (Throwable $e) {
        // ignore
    }

    return 0.0;
}

/**
 * @return list<array<string, mixed>>
 */
function inv_item_sale_price_adj_list_for_item(PDO $pdo, int $itemId): array
{
    inv_item_sale_price_adj_ensure_table($pdo);
    if ($itemId < 1 || !inv_item_sale_price_adj_has_table($pdo)) {
        return [];
    }

    $st = $pdo->prepare(
        'SELECT id, item_id, old_sale_price, new_sale_price, tax_rate_percent, status,
                created_at, posted_at
         FROM inv_item_sale_price_adj
         WHERE item_id = ?
         ORDER BY COALESCE(posted_at, created_at) ASC, id ASC'
    );
    $st->execute([$itemId]);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $out[] = [
            'id' => (int) ($row['id'] ?? 0),
            'item_id' => (int) ($row['item_id'] ?? 0),
            'old_sale_price' => (float) ($row['old_sale_price'] ?? 0),
            'new_sale_price' => (float) ($row['new_sale_price'] ?? 0),
            'tax_rate_percent' => (float) ($row['tax_rate_percent'] ?? 0),
            'status' => (string) ($row['status'] ?? 'draft'),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'posted_at' => (string) ($row['posted_at'] ?? ''),
        ];
    }

    return $out;
}

/**
 * @return array{id:int}|null
 */
function inv_item_sale_price_adj_find_draft(PDO $pdo, int $itemId): ?array
{
    if ($itemId < 1 || !inv_item_sale_price_adj_has_table($pdo)) {
        return null;
    }
    $st = $pdo->prepare(
        "SELECT id FROM inv_item_sale_price_adj WHERE item_id = ? AND status = 'draft' ORDER BY id DESC LIMIT 1"
    );
    $st->execute([$itemId]);
    $id = $st->fetchColumn();
    if ($id === false) {
        return null;
    }

    return ['id' => (int) $id];
}

/**
 * حفظ مسودة تعديل سعر (لا يحدّث بطاقة المادة).
 *
 * @return array{ok:bool, error:?string, id:?int}
 */
function inv_item_sale_price_adj_save_draft(PDO $pdo, int $itemId, float $newSalePrice, ?int $userId = null): array
{
    $out = ['ok' => false, 'error' => null, 'id' => null];
    inv_item_sale_price_adj_ensure_table($pdo);

    $item = inv_item_sale_price_adj_item_row($pdo, $itemId);
    if ($item === null) {
        $out['error'] = 'المادة غير موجودة أو غير نشطة.';

        return $out;
    }

    if ($newSalePrice < 0) {
        $out['error'] = 'سعر البيع يجب أن يكون أكبر أو يساوي صفرًا.';

        return $out;
    }

    $newSalePrice = company_round_unit_price($newSalePrice, $pdo);
    $oldSale = company_round_unit_price((float) $item['default_sale'], $pdo);
    $tax = inv_item_sale_price_adj_default_tax_percent($pdo);

    if (abs($newSalePrice - $oldSale) < 0.000001) {
        $out['error'] = 'السعر الجديد مطابق لسعر البيع الحالي على البطاقة.';

        return $out;
    }

    $draft = inv_item_sale_price_adj_find_draft($pdo, $itemId);
    try {
        if ($draft !== null) {
            $st = $pdo->prepare(
                "UPDATE inv_item_sale_price_adj
                 SET old_sale_price = ?, new_sale_price = ?, tax_rate_percent = ?, created_at = NOW()
                 WHERE id = ? AND status = 'draft'"
            );
            $st->execute([$oldSale, $newSalePrice, $tax, $draft['id']]);
            $out['id'] = $draft['id'];
        } else {
            $st = $pdo->prepare(
                "INSERT INTO inv_item_sale_price_adj
                 (item_id, old_sale_price, new_sale_price, tax_rate_percent, status, created_by)
                 VALUES (?, ?, ?, ?, 'draft', ?)"
            );
            $st->execute([$itemId, $oldSale, $newSalePrice, $tax, $userId > 0 ? $userId : null]);
            $out['id'] = (int) $pdo->lastInsertId();
        }
        $out['ok'] = true;
    } catch (Throwable $e) {
        $out['error'] = 'تعذر حفظ التعديل: ' . $e->getMessage();
    }

    return $out;
}

/**
 * ترحيل مسودة: عكس السعر على بطاقة المادة (default_sale).
 *
 * @return array{ok:bool, error:?string}
 */
function inv_item_sale_price_adj_post_draft(PDO $pdo, int $adjId, int $itemId): array
{
    $out = ['ok' => false, 'error' => null];
    inv_item_sale_price_adj_ensure_table($pdo);

    if ($adjId < 1 || $itemId < 1) {
        $out['error'] = 'بيانات التعديل غير صالحة.';

        return $out;
    }

    $st = $pdo->prepare(
        "SELECT id, item_id, new_sale_price, status FROM inv_item_sale_price_adj WHERE id = ? LIMIT 1"
    );
    $st->execute([$adjId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $out['error'] = 'سجل التعديل غير موجود.';

        return $out;
    }
    if ((int) ($row['item_id'] ?? 0) !== $itemId) {
        $out['error'] = 'التعديل لا يخص المادة المحددة.';

        return $out;
    }
    if ((string) ($row['status'] ?? '') !== 'draft') {
        $out['error'] = 'تم ترحيل هذا التعديل مسبقًا.';

        return $out;
    }

    $newSale = company_round_unit_price((float) ($row['new_sale_price'] ?? 0), $pdo);

    try {
        $pdo->beginTransaction();
        $updItem = $pdo->prepare('UPDATE inv_item SET default_sale = ? WHERE id = ?');
        $updItem->execute([$newSale, $itemId]);
        $updAdj = $pdo->prepare(
            "UPDATE inv_item_sale_price_adj SET status = 'posted', posted_at = NOW() WHERE id = ? AND status = 'draft'"
        );
        $updAdj->execute([$adjId]);
        $pdo->commit();
        $out['ok'] = true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $out['error'] = 'تعذر ترحيل السعر: ' . $e->getMessage();
    }

    return $out;
}

function inv_item_sale_price_adj_format_price(float $price, ?PDO $pdo = null): string
{
    return format_money($price, company_invoice_unit_price_decimal_places($pdo));
}

function inv_item_sale_price_adj_format_tax(float $rate): string
{
    if (abs($rate) < 0.000001) {
        return 'معفى';
    }

    return format_amount($rate, 3) . '%';
}
