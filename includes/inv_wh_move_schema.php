<?php
declare(strict_types=1);

function inv_wh_move_has_tables(PDO $pdo, bool $refresh = false): bool
{
    static $ok = null;
    if ($refresh) {
        $ok = null;
    }
    if ($ok !== null) {
        return $ok;
    }
    try {
        $pdo->query('SELECT id FROM inv_wh_move LIMIT 1');
        $pdo->query('SELECT id FROM inv_wh_move_line LIMIT 1');
        $ok = true;
    } catch (Throwable $e) {
        $ok = false;
    }

    return $ok;
}

function inv_wh_move_install_tables(PDO $pdo): void
{
    $moveWithFk = 'CREATE TABLE IF NOT EXISTS inv_wh_move (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          move_no VARCHAR(20) NOT NULL,
          move_date DATE NOT NULL,
          movement_type_code VARCHAR(40) NOT NULL,
          warehouse_id INT UNSIGNED NOT NULL,
          warehouse_to_id INT UNSIGNED NULL,
          status ENUM(\'draft\',\'posted\') NOT NULL DEFAULT \'draft\',
          notes VARCHAR(500) NULL,
          created_by INT UNSIGNED NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          posted_at DATETIME NULL,
          UNIQUE KEY uq_inv_wh_move_no (move_no),
          KEY idx_inv_wh_move_date (move_date),
          KEY idx_inv_wh_move_type (movement_type_code),
          KEY idx_inv_wh_move_status (status),
          CONSTRAINT fk_iwm_wh FOREIGN KEY (warehouse_id) REFERENCES inv_warehouse(id),
          CONSTRAINT fk_iwm_wh_to FOREIGN KEY (warehouse_to_id) REFERENCES inv_warehouse(id) ON DELETE SET NULL,
          CONSTRAINT fk_iwm_user FOREIGN KEY (created_by) REFERENCES sys_user(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $moveNoFk = 'CREATE TABLE IF NOT EXISTS inv_wh_move (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          move_no VARCHAR(20) NOT NULL,
          move_date DATE NOT NULL,
          movement_type_code VARCHAR(40) NOT NULL,
          warehouse_id INT UNSIGNED NOT NULL,
          warehouse_to_id INT UNSIGNED NULL,
          status ENUM(\'draft\',\'posted\') NOT NULL DEFAULT \'draft\',
          notes VARCHAR(500) NULL,
          created_by INT UNSIGNED NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          posted_at DATETIME NULL,
          UNIQUE KEY uq_inv_wh_move_no (move_no),
          KEY idx_inv_wh_move_date (move_date),
          KEY idx_inv_wh_move_type (movement_type_code),
          KEY idx_inv_wh_move_status (status),
          KEY idx_iwm_wh (warehouse_id),
          KEY idx_iwm_wh_to (warehouse_to_id),
          KEY idx_iwm_user (created_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $lineWithFk = 'CREATE TABLE IF NOT EXISTS inv_wh_move_line (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          move_id BIGINT UNSIGNED NOT NULL,
          line_no INT UNSIGNED NOT NULL,
          item_id INT UNSIGNED NOT NULL,
          qty DECIMAL(18,6) NOT NULL,
          CONSTRAINT fk_iwml_move FOREIGN KEY (move_id) REFERENCES inv_wh_move(id) ON DELETE CASCADE,
          CONSTRAINT fk_iwml_item FOREIGN KEY (item_id) REFERENCES inv_item(id),
          UNIQUE KEY uq_iwml_move_line (move_id, line_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $lineNoFk = 'CREATE TABLE IF NOT EXISTS inv_wh_move_line (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          move_id BIGINT UNSIGNED NOT NULL,
          line_no INT UNSIGNED NOT NULL,
          item_id INT UNSIGNED NOT NULL,
          qty DECIMAL(18,6) NOT NULL,
          UNIQUE KEY uq_iwml_move_line (move_id, line_no),
          KEY idx_iwml_move (move_id),
          KEY idx_iwml_item (item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    try {
        $pdo->exec($moveWithFk);
    } catch (Throwable $e) {
        try {
            $pdo->exec($moveNoFk);
        } catch (Throwable $e2) {
            //
        }
    }

    try {
        $pdo->exec($lineWithFk);
    } catch (Throwable $e) {
        try {
            $pdo->exec($lineNoFk);
        } catch (Throwable $e2) {
            //
        }
    }
}

function inv_wh_move_ensure_schema(PDO $pdo): bool
{
    if (inv_wh_move_has_tables($pdo, true)) {
        return true;
    }

    require_once app_path('includes/sql_migration.php');
    require_once app_path('includes/inv_movement_type_schema.php');
    inv_movement_type_ensure_schema($pdo);

    sql_migration_run_file($pdo, 'database/migrations/089_inv_wh_move.sql');
    sql_migration_run_file($pdo, 'database/migrations/090_inv_movement_type_affects_gl.sql');

    if (!inv_wh_move_has_tables($pdo, true)) {
        inv_wh_move_install_tables($pdo);
    }

    return inv_wh_move_has_tables($pdo, true);
}

/** رقم حركة تسلسلي — أرقام فقط بدون أحرف. */
function inv_wh_move_generate_next_no(PDO $pdo): string
{
    $st = $pdo->query('SELECT move_no FROM inv_wh_move FOR UPDATE');
    $max = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $no) {
        $no = trim((string) $no);
        if ($no !== '' && preg_match('/^(\d+)$/', $no, $m)) {
            $max = max($max, (int) $m[1]);
        }
    }

    return str_pad((string) ($max + 1), 6, '0', STR_PAD_LEFT);
}

/** @return array<string, mixed>|null */
function inv_wh_move_by_id(PDO $pdo, int $id): ?array
{
    if ($id < 1 || !inv_wh_move_ensure_schema($pdo)) {
        return null;
    }
    $st = $pdo->prepare(
        'SELECT m.*, t.name_ar AS movement_type_name
         FROM inv_wh_move m
         LEFT JOIN inv_movement_type t ON t.code = m.movement_type_code
         WHERE m.id = ? LIMIT 1'
    );
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/** @return list<array<string, mixed>> */
function inv_wh_move_lines(PDO $pdo, int $moveId): array
{
    if ($moveId < 1) {
        return [];
    }
    require_once app_path('includes/inv_item_barcode.php');
    $hasBarcode = inv_item_has_barcode_column($pdo);
    $barcodeSel = $hasBarcode ? 'i.barcode' : 'NULL AS barcode';
    $st = $pdo->prepare(
        "SELECT l.id, l.line_no, l.item_id, l.qty, i.sku, {$barcodeSel}, i.name_ar AS item_name
         FROM inv_wh_move_line l
         INNER JOIN inv_item i ON i.id = l.item_id
         WHERE l.move_id = ?
         ORDER BY l.line_no ASC, l.id ASC"
    );
    $st->execute([$moveId]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return array{out: string, in: string}|null */
function inv_wh_move_stock_ref_types(string $movementTypeCode): ?array
{
    return match ($movementTypeCode) {
        'adjust_in' => ['out' => '', 'in' => 'stock_adjust_in'],
        'adjust_out' => ['out' => 'stock_adjust_out', 'in' => ''],
        'disposal' => ['out' => 'stock_disposal', 'in' => ''],
        'transfer' => ['out' => 'warehouse_transfer_out', 'in' => 'warehouse_transfer_in'],
        default => null,
    };
}

function inv_wh_move_is_transfer(string $movementTypeCode): bool
{
    return $movementTypeCode === 'transfer';
}

function inv_wh_move_requires_dest_warehouse(string $movementTypeCode): bool
{
    return inv_wh_move_is_transfer($movementTypeCode);
}
