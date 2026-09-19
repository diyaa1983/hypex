<?php
declare(strict_types=1);

function sal_delivery_has_table(PDO $pdo): bool
{
    static $ok = false;
    static $checked = false;
    if ($checked) {
        return $ok;
    }
    $checked = true;
    try {
        $pdo->query('SELECT id FROM sal_delivery LIMIT 1');
        $pdo->query('SELECT id FROM sal_delivery_line LIMIT 1');
        $ok = true;
    } catch (Throwable $e) {
        $ok = false;
    }

    return $ok;
}

function sal_delivery_ensure_schema(PDO $pdo): bool
{
    if (!sal_delivery_has_table($pdo)) {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/028_sal_delivery.sql');
    }
    sal_delivery_ensure_extended_schema($pdo);

    return sal_delivery_has_table($pdo);
}

function sal_delivery_column_exists(PDO $pdo, string $column): bool
{
    try {
        $pdo->query('SELECT `' . str_replace('`', '', $column) . '` FROM sal_delivery LIMIT 1');

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function sal_delivery_ensure_extended_schema(PDO $pdo): void
{
    if (!sal_delivery_has_table($pdo)) {
        return;
    }
    if (sal_delivery_column_exists($pdo, 'warehouse_id')) {
        return;
    }
    require_once app_path('includes/sql_migration.php');
    sql_migration_run_file($pdo, 'database/migrations/116_sal_delivery_warehouse_invoice_link.sql');
}

function sal_delivery_is_posted(PDO $pdo, int $deliveryId): bool
{
    if ($deliveryId < 1 || !sal_delivery_has_table($pdo)) {
        return false;
    }
    $st = $pdo->prepare('SELECT is_posted FROM sal_delivery WHERE id = ? LIMIT 1');
    $st->execute([$deliveryId]);

    return (int) $st->fetchColumn() === 1;
}

/** رقم تسلسلي: 001-2026 */
function sal_delivery_generate_next_no(PDO $pdo, string $deliveryDate): string
{
    $year = (int) date('Y', strtotime($deliveryDate));
    $suffix = '-' . $year;
    $st = $pdo->prepare('SELECT delivery_no FROM sal_delivery WHERE delivery_no LIKE ? FOR UPDATE');
    $st->execute(['%' . $suffix]);
    $maxSeq = 0;
    $suffixQuoted = preg_quote($suffix, '/');
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $no) {
        $no = (string) $no;
        if (preg_match('/^(\d+)' . $suffixQuoted . '$/', $no, $m)) {
            $maxSeq = max($maxSeq, (int) $m[1]);
        }
    }

    return str_pad((string) ($maxSeq + 1), 3, '0', STR_PAD_LEFT) . $suffix;
}

/** @param list<array<string,mixed>> $lines */
function sal_delivery_replace_lines(PDO $pdo, int $deliveryId, array $lines): void
{
    $pdo->prepare('DELETE FROM sal_delivery_line WHERE delivery_id = ?')->execute([$deliveryId]);
    $st = $pdo->prepare(
        'INSERT INTO sal_delivery_line (delivery_id, item_id, line_desc, qty, sort_order) VALUES (?,?,?,?,?)'
    );
    $sort = 0;
    foreach ($lines as $ln) {
        $itemId = (int) ($ln['item_id'] ?? 0);
        $qty = (float) ($ln['qty'] ?? 0);
        if ($itemId < 1 || $qty <= 0) {
            continue;
        }
        $sort++;
        $desc = trim((string) ($ln['line_desc'] ?? ''));
        $st->execute([
            $deliveryId,
            $itemId,
            $desc !== '' ? $desc : null,
            round($qty, 6),
            $sort,
        ]);
    }
}

function sal_delivery_update_header(
    PDO $pdo,
    int $deliveryId,
    string $deliveryDate,
    int $customerId,
    ?int $warehouseId,
    ?string $notes
): void {
    if (sal_delivery_column_exists($pdo, 'warehouse_id')) {
        $pdo->prepare(
            'UPDATE sal_delivery SET delivery_date = ?, customer_id = ?, warehouse_id = ?, notes = ? WHERE id = ?'
        )->execute([
            $deliveryDate,
            $customerId,
            $warehouseId !== null && $warehouseId > 0 ? $warehouseId : null,
            $notes !== '' ? $notes : null,
            $deliveryId,
        ]);

        return;
    }

    $pdo->prepare(
        'UPDATE sal_delivery SET delivery_date = ?, customer_id = ?, notes = ? WHERE id = ?'
    )->execute([
        $deliveryDate,
        $customerId,
        $notes !== '' ? $notes : null,
        $deliveryId,
    ]);
}

function sal_delivery_insert_header(
    PDO $pdo,
    string $deliveryNo,
    string $deliveryDate,
    int $customerId,
    ?int $warehouseId,
    ?string $notes,
    ?int $userId
): int {
    if (sal_delivery_column_exists($pdo, 'warehouse_id')) {
        $pdo->prepare(
            'INSERT INTO sal_delivery (delivery_no, delivery_date, customer_id, warehouse_id, status, notes, created_by)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([
            $deliveryNo,
            $deliveryDate,
            $customerId,
            $warehouseId !== null && $warehouseId > 0 ? $warehouseId : null,
            'confirmed',
            $notes !== '' ? $notes : null,
            $userId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    $pdo->prepare(
        'INSERT INTO sal_delivery (delivery_no, delivery_date, customer_id, status, notes, created_by)
         VALUES (?,?,?,?,?,?)'
    )->execute([
        $deliveryNo,
        $deliveryDate,
        $customerId,
        'confirmed',
        $notes !== '' ? $notes : null,
        $userId,
    ]);

    return (int) $pdo->lastInsertId();
}
