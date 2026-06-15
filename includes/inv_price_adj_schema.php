<?php
declare(strict_types=1);

function inv_price_adj_schema_is_ready(PDO $pdo): bool
{
    return inv_price_adj_has_doc_table($pdo) && inv_price_sale_price_adj_has_doc_id_column($pdo);
}

function inv_price_adj_has_doc_table(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT id FROM inv_price_adj_doc LIMIT 1');

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function inv_price_sale_price_adj_has_doc_id_column(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT doc_id FROM inv_item_sale_price_adj LIMIT 1');

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function inv_price_adj_create_doc_table_fallback(PDO $pdo): void
{
    if (inv_price_adj_has_doc_table($pdo)) {
        return;
    }
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS inv_price_adj_doc (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                adj_no VARCHAR(20) NOT NULL,
                adj_date DATE NOT NULL,
                status ENUM('draft','posted') NOT NULL DEFAULT 'draft',
                notes VARCHAR(500) NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                posted_at DATETIME NULL,
                UNIQUE KEY uq_inv_price_adj_doc_no (adj_no),
                KEY idx_inv_price_adj_doc_status (status),
                KEY idx_inv_price_adj_doc_date (adj_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        // ignored
    }
}

function inv_price_adj_ensure_line_columns(PDO $pdo): void
{
    require_once app_path('includes/inv_item_sale_price_adj.php');
    if (!inv_item_sale_price_adj_has_table($pdo)) {
        return;
    }

    $cols = [];
    try {
        foreach ($pdo->query('SHOW COLUMNS FROM inv_item_sale_price_adj')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $cols[strtolower((string) ($r['Field'] ?? ''))] = true;
        }
    } catch (Throwable $e) {
        return;
    }

    $addedDocId = false;
    if (!isset($cols['doc_id'])) {
        try {
            $pdo->exec('ALTER TABLE inv_item_sale_price_adj ADD COLUMN doc_id BIGINT UNSIGNED NULL AFTER id');
            $addedDocId = true;
        } catch (Throwable $e) {
            // ignored
        }
    }
    if (!isset($cols['line_no'])) {
        try {
            $pdo->exec(
                'ALTER TABLE inv_item_sale_price_adj ADD COLUMN line_no INT UNSIGNED NOT NULL DEFAULT 1 AFTER doc_id'
            );
        } catch (Throwable $e) {
            try {
                $pdo->exec(
                    'ALTER TABLE inv_item_sale_price_adj ADD COLUMN line_no INT UNSIGNED NOT NULL DEFAULT 1'
                );
            } catch (Throwable $e2) {
                // ignored
            }
        }
    }

    if ($addedDocId && inv_price_adj_has_doc_table($pdo)) {
        try {
            $pdo->exec('ALTER TABLE inv_item_sale_price_adj ADD KEY idx_iispa_doc (doc_id)');
        } catch (Throwable $e) {
            // ignored
        }
        try {
            $pdo->exec(
                'ALTER TABLE inv_item_sale_price_adj
                 ADD CONSTRAINT fk_iispa_doc FOREIGN KEY (doc_id) REFERENCES inv_price_adj_doc(id) ON DELETE CASCADE'
            );
        } catch (Throwable $e) {
            // ignored
        }
    }
}

function inv_price_adj_ensure_schema(PDO $pdo): bool
{
    if (inv_price_adj_schema_is_ready($pdo)) {
        return true;
    }

    require_once app_path('includes/inv_item_sale_price_adj.php');
    inv_item_sale_price_adj_ensure_table($pdo);

    if (!inv_price_adj_has_doc_table($pdo)) {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/091_inv_price_adj_doc.sql');
    }
    inv_price_adj_create_doc_table_fallback($pdo);
    inv_price_adj_ensure_line_columns($pdo);

    if (!inv_price_adj_schema_is_ready($pdo)) {
        return false;
    }

    try {
        inv_price_adj_migrate_legacy_rows($pdo);
    } catch (Throwable $e) {
        // لا نوقف الشاشة
    }

    return inv_price_adj_schema_is_ready($pdo);
}

/** ترحيل دفعة من السجلات القديمة (بدون doc_id) — مرة واحدة حتى تُفرَّغ القائمة. */
function inv_price_adj_migrate_legacy_rows(PDO $pdo): void
{
    if (!inv_price_adj_schema_is_ready($pdo)) {
        return;
    }

    try {
        $pending = (int) $pdo->query(
            'SELECT COUNT(*) FROM inv_item_sale_price_adj WHERE doc_id IS NULL'
        )->fetchColumn();
    } catch (Throwable $e) {
        return;
    }
    if ($pending < 1) {
        return;
    }

    $st = $pdo->query(
        'SELECT id, item_id, status, created_by, created_at, posted_at
         FROM inv_item_sale_price_adj
         WHERE doc_id IS NULL
         ORDER BY id ASC
         LIMIT 200'
    );
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($rows === []) {
        return;
    }

    $insDoc = $pdo->prepare(
        'INSERT INTO inv_price_adj_doc (adj_no, adj_date, status, created_by, created_at, posted_at)
         VALUES (?,?,?,?,?,?)'
    );
    $updLine = $pdo->prepare(
        'UPDATE inv_item_sale_price_adj SET doc_id = ?, line_no = 1 WHERE id = ? AND doc_id IS NULL'
    );

    $pdo->beginTransaction();
    try {
        foreach ($rows as $row) {
            $lineId = (int) ($row['id'] ?? 0);
            if ($lineId < 1) {
                continue;
            }
            $status = (string) ($row['status'] ?? 'draft') === 'posted' ? 'posted' : 'draft';
            $createdAt = (string) ($row['created_at'] ?? '');
            $adjDate = $createdAt !== '' ? date('Y-m-d', strtotime($createdAt) ?: time()) : date('Y-m-d');
            $adjNo = inv_price_adj_generate_next_no($pdo);
            $insDoc->execute([
                $adjNo,
                $adjDate,
                $status,
                (int) ($row['created_by'] ?? 0) > 0 ? (int) $row['created_by'] : null,
                $createdAt !== '' ? $createdAt : date('Y-m-d H:i:s'),
                $status === 'posted' && ($row['posted_at'] ?? '') !== '' ? $row['posted_at'] : null,
            ]);
            $docId = (int) $pdo->lastInsertId();
            $updLine->execute([$docId, $lineId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** رقم تسلسلي — أرقام فقط. */
function inv_price_adj_generate_next_no(PDO $pdo): string
{
    if (!inv_price_adj_has_doc_table($pdo)) {
        return '000001';
    }
    $max = 0;
    try {
        $max = (int) $pdo->query(
            "SELECT COALESCE(MAX(CAST(adj_no AS UNSIGNED)), 0) FROM inv_price_adj_doc
             WHERE adj_no REGEXP '^[0-9]+$'"
        )->fetchColumn();
    } catch (Throwable $e) {
        try {
            $max = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM inv_price_adj_doc')->fetchColumn();
        } catch (Throwable $e2) {
            $max = 0;
        }
    }

    return str_pad((string) ($max + 1), 6, '0', STR_PAD_LEFT);
}

/** @return array<string, mixed>|null */
function inv_price_adj_doc_by_id(PDO $pdo, int $id): ?array
{
    if ($id < 1 || !inv_price_adj_has_doc_table($pdo)) {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM inv_price_adj_doc WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/** @return list<array<string, mixed>> */
function inv_price_adj_lines(PDO $pdo, int $docId): array
{
    if ($docId < 1 || !inv_price_sale_price_adj_has_doc_id_column($pdo)) {
        return [];
    }
    require_once app_path('includes/inv_item_display.php');
    $itemNoSql = inv_item_sql_material_number($pdo, 'i');
    try {
        $st = $pdo->prepare(
            "SELECT l.id, l.doc_id, l.line_no, l.item_id, l.old_sale_price, l.new_sale_price,
                    l.tax_rate_percent, l.status AS line_status,
                    {$itemNoSql} AS item_sku, i.name_ar AS item_name
             FROM inv_item_sale_price_adj l
             INNER JOIN inv_item i ON i.id = l.item_id
             WHERE l.doc_id = ?
             ORDER BY l.line_no ASC, l.id ASC"
        );
        $st->execute([$docId]);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $st = $pdo->prepare(
            'SELECT l.id, l.doc_id, l.line_no, l.item_id, l.old_sale_price, l.new_sale_price,
                    l.tax_rate_percent, l.status AS line_status,
                    i.sku AS item_sku, i.name_ar AS item_name
             FROM inv_item_sale_price_adj l
             INNER JOIN inv_item i ON i.id = l.item_id
             WHERE l.doc_id = ?
             ORDER BY l.line_no ASC, l.id ASC'
        );
        $st->execute([$docId]);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
