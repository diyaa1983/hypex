<?php
declare(strict_types=1);

/**
 * مناطق العملاء — دليل + ربط على crm_customer.region_id
 */

function crm_region_ensure_schema(PDO $pdo): bool
{
    static $done = false;
    if ($done) {
        return true;
    }
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS crm_region (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                code VARCHAR(20) NOT NULL,
                name_ar VARCHAR(120) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_crm_region_code (code),
                KEY idx_crm_region_active (is_active, sort_order, name_ar)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $hasCol = false;
        try {
            $st = $pdo->query("SHOW COLUMNS FROM crm_customer LIKE 'region_id'");
            $hasCol = (bool) ($st && $st->fetch());
        } catch (Throwable $e) {
            $hasCol = false;
        }
        if (!$hasCol) {
            $pdo->exec(
                'ALTER TABLE crm_customer ADD COLUMN region_id INT UNSIGNED NULL, ADD KEY idx_crm_customer_region (region_id)'
            );
        }
        $done = true;

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @return list<array{id:int,code:string,name_ar:string,sort_order:int,is_active:int}>
 */
function crm_region_load_active(PDO $pdo): array
{
    crm_region_ensure_schema($pdo);
    try {
        $rows = $pdo->query(
            'SELECT id, code, name_ar, sort_order, is_active
             FROM crm_region
             WHERE is_active = 1
             ORDER BY sort_order ASC, name_ar ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $r): array {
            return [
                'id' => (int) ($r['id'] ?? 0),
                'code' => (string) ($r['code'] ?? ''),
                'name_ar' => (string) ($r['name_ar'] ?? ''),
                'sort_order' => (int) ($r['sort_order'] ?? 0),
                'is_active' => (int) ($r['is_active'] ?? 0),
            ];
        }, $rows);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @return list<array<string,mixed>>
 */
function crm_region_load_all(PDO $pdo): array
{
    crm_region_ensure_schema($pdo);
    crm_sales_rep_region_ensure_schema($pdo);
    try {
        return $pdo->query(
            'SELECT r.*,
                    (SELECT COUNT(*) FROM crm_customer c WHERE c.region_id = r.id) AS customer_count,
                    (SELECT COUNT(*) FROM crm_sales_rep_region srr WHERE srr.region_id = r.id) AS rep_count
             FROM crm_region r
             ORDER BY r.sort_order ASC, r.name_ar ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function crm_region_exists_active(PDO $pdo, int $regionId): bool
{
    if ($regionId < 1) {
        return false;
    }
    crm_region_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT id FROM crm_region WHERE id = ? AND is_active = 1 LIMIT 1');
    $st->execute([$regionId]);

    return (bool) $st->fetch();
}

function crm_sales_rep_region_ensure_schema(PDO $pdo): bool
{
    static $done = false;
    if ($done) {
        return true;
    }
    crm_region_ensure_schema($pdo);
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS crm_sales_rep_region (
                sales_rep_id INT UNSIGNED NOT NULL,
                region_id INT UNSIGNED NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                PRIMARY KEY (sales_rep_id, region_id),
                KEY idx_csrr_region (region_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $done = true;

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** @return list<int> */
function crm_sales_rep_region_ids(PDO $pdo, int $salesRepId): array
{
    if ($salesRepId < 1) {
        return [];
    }
    crm_sales_rep_region_ensure_schema($pdo);
    try {
        $st = $pdo->prepare(
            'SELECT region_id FROM crm_sales_rep_region WHERE sales_rep_id = ? ORDER BY sort_order ASC, region_id ASC'
        );
        $st->execute([$salesRepId]);
        $ids = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $v) {
            $ids[] = (int) $v;
        }

        return $ids;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @param list<int|string> $regionIds
 */
function crm_sales_rep_save_regions(PDO $pdo, int $salesRepId, array $regionIds): void
{
    if ($salesRepId < 1) {
        return;
    }
    crm_sales_rep_region_ensure_schema($pdo);

    $resolved = [];
    foreach ($regionIds as $raw) {
        $id = (int) $raw;
        if ($id > 0 && crm_region_exists_active($pdo, $id) && !in_array($id, $resolved, true)) {
            $resolved[] = $id;
        }
    }

    $pdo->prepare('DELETE FROM crm_sales_rep_region WHERE sales_rep_id = ?')->execute([$salesRepId]);
    if ($resolved === []) {
        return;
    }
    $ins = $pdo->prepare(
        'INSERT INTO crm_sales_rep_region (sales_rep_id, region_id, sort_order) VALUES (?,?,?)'
    );
    foreach ($resolved as $i => $regionId) {
        $ins->execute([$salesRepId, $regionId, $i]);
    }
}

/** أسماء مناطق مندوب — للعرض في القائمة */
function crm_sales_rep_region_names(PDO $pdo, int $salesRepId): string
{
    if ($salesRepId < 1) {
        return '';
    }
    crm_sales_rep_region_ensure_schema($pdo);
    try {
        $st = $pdo->prepare(
            'SELECT GROUP_CONCAT(rg.name_ar ORDER BY srr.sort_order, rg.name_ar SEPARATOR \'، \')
             FROM crm_sales_rep_region srr
             INNER JOIN crm_region rg ON rg.id = srr.region_id
             WHERE srr.sales_rep_id = ?'
        );
        $st->execute([$salesRepId]);
        $v = $st->fetchColumn();

        return is_string($v) ? $v : '';
    } catch (Throwable $e) {
        return '';
    }
}
