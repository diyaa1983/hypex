<?php
declare(strict_types=1);

require_once app_path('includes/sql_migration.php');

function inv_stocktake_has_tables(PDO $pdo, bool $refresh = false): bool
{
    static $ok = null;
    if ($refresh) {
        $ok = null;
    }
    if ($ok !== null) {
        return $ok;
    }
    try {
        $pdo->query('SELECT id FROM inv_stocktake_doc LIMIT 1');
        $pdo->query('SELECT id FROM inv_stocktake_line LIMIT 1');
        $ok = true;
    } catch (Throwable $e) {
        $ok = false;
    }

    return $ok;
}

function inv_stocktake_ensure_schema(PDO $pdo): bool
{
    if (inv_stocktake_has_tables($pdo, true)) {
        return true;
    }
    sql_migration_run_file($pdo, 'database/migrations/094_inv_stocktake.sql');

    return inv_stocktake_has_tables($pdo, true);
}

function inv_stocktake_generate_next_no(PDO $pdo): string
{
    $max = 0;
    $st = $pdo->query("SELECT COALESCE(MAX(CAST(take_no AS UNSIGNED)),0) FROM inv_stocktake_doc WHERE take_no REGEXP '^[0-9]+$'");
    $max = (int) $st->fetchColumn();

    return str_pad((string) ($max + 1), 6, '0', STR_PAD_LEFT);
}

/** @return array<string,mixed>|null */
function inv_stocktake_doc_by_id(PDO $pdo, int $id): ?array
{
    if ($id < 1 || !inv_stocktake_ensure_schema($pdo)) {
        return null;
    }
    $st = $pdo->prepare(
        'SELECT d.*, w.name_ar AS warehouse_name
         FROM inv_stocktake_doc d
         INNER JOIN inv_warehouse w ON w.id = d.warehouse_id
         WHERE d.id = ? LIMIT 1'
    );
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/** @return list<array<string,mixed>> */
function inv_stocktake_doc_lines(PDO $pdo, int $docId): array
{
    if ($docId < 1) {
        return [];
    }
    require_once app_path('includes/inv_item_display.php');
    $itemNoSql = inv_item_sql_material_number($pdo, 'i');
    $st = $pdo->prepare(
        "SELECT l.id, l.line_no, l.item_id, l.book_qty, l.counted_qty, {$itemNoSql} AS item_sku, i.name_ar AS item_name
         FROM inv_stocktake_line l
         INNER JOIN inv_item i ON i.id = l.item_id
         WHERE l.doc_id = ?
         ORDER BY l.line_no ASC, l.id ASC"
    );
    $st->execute([$docId]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
