<?php
declare(strict_types=1);

/** النظام يسمح بالبيع والصرف حتى لو أصبح الرصيد سالبًا؛ الإدخال لاحقًا يُصحّح الرصيد تلقائيًا. */
function inv_stock_allows_negative(): bool
{
    return true;
}

function inv_stock_move_has_table(PDO $pdo, bool $refresh = false): bool
{
    static $ok = null;
    if ($refresh) {
        $ok = null;
    }
    if ($ok !== null) {
        return $ok;
    }
    try {
        $pdo->query('SELECT id FROM inv_stock_move LIMIT 1');
        $ok = true;
    } catch (Throwable $e) {
        $ok = false;
    }

    return $ok;
}

/** إنشاء جدول حركات المخزون إن وُجدت قاعدة ناقصة (مطلوب لقوائم الترحيل وتعبير «مرحّل»). */
function inv_stock_move_ensure_table(PDO $pdo): void
{
    if (inv_stock_move_has_table($pdo)) {
        return;
    }

    require_once app_path('includes/inv_item_schema.php');
    inv_warehouse_ensure_table($pdo);
    inv_item_ensure_extended_schema($pdo);

    try {
        $pdo->exec(
            'CREATE TABLE inv_stock_move (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              move_date DATE NOT NULL,
              warehouse_id INT UNSIGNED NOT NULL,
              item_id INT UNSIGNED NOT NULL,
              qty_delta DECIMAL(18,6) NOT NULL,
              ref_type VARCHAR(40) NOT NULL,
              ref_id BIGINT UNSIGNED NULL,
              note VARCHAR(255) NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              CONSTRAINT fk_ism_wh FOREIGN KEY (warehouse_id) REFERENCES inv_warehouse(id),
              CONSTRAINT fk_ism_it FOREIGN KEY (item_id) REFERENCES inv_item(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } catch (Throwable $e) {
        // جداول مرجعية ناقصة
    }

    inv_stock_move_has_table($pdo, true);
}

function inv_stock_qty_on_hand(PDO $pdo, int $warehouseId, int $itemId): float
{
    if ($warehouseId < 1 || $itemId < 1 || !inv_stock_move_has_table($pdo)) {
        return 0.0;
    }
    $st = $pdo->prepare(
        'SELECT COALESCE(SUM(qty_delta), 0) FROM inv_stock_move WHERE warehouse_id = ? AND item_id = ?'
    );
    $st->execute([$warehouseId, $itemId]);

    return (float) $st->fetchColumn();
}

/** رصيد مادة في كل المستودعات. */
function inv_item_stock_qty_total(PDO $pdo, int $itemId): float
{
    if ($itemId < 1 || !inv_stock_move_has_table($pdo)) {
        return 0.0;
    }

    $st = $pdo->prepare(
        'SELECT COALESCE(SUM(qty_delta), 0) FROM inv_stock_move WHERE item_id = ?'
    );
    $st->execute([$itemId]);

    return (float) $st->fetchColumn();
}

/**
 * أرصدة مواد (مجموع المستودعات أو مستودع محدد).
 *
 * @param list<int> $itemIds
 * @return array<int, float>
 */
function inv_item_stock_qty_map(PDO $pdo, array $itemIds, int $warehouseId = 0): array
{
    $itemIds = array_values(array_filter(array_map('intval', $itemIds), static fn (int $id): bool => $id > 0));
    if ($itemIds === [] || !inv_stock_move_has_table($pdo)) {
        return [];
    }

    inv_stock_move_ensure_table($pdo);
    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    if ($warehouseId > 0) {
        $sql = "SELECT item_id, COALESCE(SUM(qty_delta), 0) AS qty
                FROM inv_stock_move
                WHERE item_id IN ({$placeholders}) AND warehouse_id = ?
                GROUP BY item_id";
        $params = array_merge($itemIds, [$warehouseId]);
    } else {
        $sql = "SELECT item_id, COALESCE(SUM(qty_delta), 0) AS qty
                FROM inv_stock_move
                WHERE item_id IN ({$placeholders})
                GROUP BY item_id";
        $params = $itemIds;
    }

    $st = $pdo->prepare($sql);
    $st->execute($params);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $id = (int) ($row['item_id'] ?? 0);
        if ($id > 0) {
            $out[$id] = (float) ($row['qty'] ?? 0);
        }
    }

    return $out;
}

/**
 * @return array{ok:bool, error:?string, id:?int}
 */
function inv_stock_insert_move(
    PDO $pdo,
    string $moveDate,
    int $warehouseId,
    int $itemId,
    float $qtyDelta,
    string $refType,
    ?int $refId,
    ?string $note
): array {
    $out = ['ok' => false, 'error' => null, 'id' => null];

    if (!inv_stock_move_has_table($pdo)) {
        $out['error'] = 'جدول حركات المخزون غير موجود.';

        return $out;
    }
    if ($warehouseId < 1 || $itemId < 1) {
        $out['error'] = 'المستودع أو المادة غير صالحة.';

        return $out;
    }
    if (abs($qtyDelta) < 0.000001) {
        $out['error'] = 'الكمية صفر.';

        return $out;
    }
    if ($moveDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $moveDate)) {
        $out['error'] = 'تاريخ الحركة غير صالح.';

        return $out;
    }

    $st = $pdo->prepare(
        'INSERT INTO inv_stock_move (move_date, warehouse_id, item_id, qty_delta, ref_type, ref_id, note)
         VALUES (?,?,?,?,?,?,?)'
    );
    $st->execute([
        $moveDate,
        $warehouseId,
        $itemId,
        round($qtyDelta, 6),
        $refType,
        $refId,
        $note !== '' && $note !== null ? $note : null,
    ]);

    $out['ok'] = true;
    $out['id'] = (int) $pdo->lastInsertId();

    return $out;
}

/** إدخال مخزون (شراء، رصيد افتتاحي، تسوية) — يزيد الرصيد ويُغطّي العجز السابق تلقائيًا. */
function inv_stock_receipt(
    PDO $pdo,
    string $moveDate,
    int $warehouseId,
    int $itemId,
    float $qty,
    string $refType,
    ?int $refId,
    ?string $note
): array {
    if ($qty <= 0) {
        return ['ok' => false, 'error' => 'كمية الإدخال يجب أن تكون أكبر من صفر.', 'id' => null];
    }

    return inv_stock_insert_move($pdo, $moveDate, $warehouseId, $itemId, $qty, $refType, $refId, $note);
}

/** إخراج مخزون (بيع، صرف) — يُسمح بالرصيد السالب. */
function inv_stock_issue(
    PDO $pdo,
    string $moveDate,
    int $warehouseId,
    int $itemId,
    float $qty,
    string $refType,
    ?int $refId,
    ?string $note
): array {
    if ($qty <= 0) {
        return ['ok' => false, 'error' => 'كمية الصرف يجب أن تكون أكبر من صفر.', 'id' => null];
    }

    $onHandBefore = inv_stock_qty_on_hand($pdo, $warehouseId, $itemId);
    $move = inv_stock_insert_move($pdo, $moveDate, $warehouseId, $itemId, -$qty, $refType, $refId, $note);
    if (!$move['ok']) {
        return $move;
    }

    if (inv_stock_allows_negative() && $onHandBefore < $qty - 0.000001) {
        $move['went_negative'] = true;
        $move['balance_after'] = round($onHandBefore - $qty, 6);
    }

    return $move;
}
