<?php
declare(strict_types=1);

/**
 * مناطق العملاء = قائمة أسماء المناطق + عناوين مربوطة بكل منطقة.
 * المندوب يغطي عدة (منطقة + عنوان).
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
                name_ar VARCHAR(180) NOT NULL,
                address_ar VARCHAR(255) NULL DEFAULT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_crm_region_code (code),
                KEY idx_crm_region_active (is_active, sort_order, name_ar)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        try {
            $pdo->query('SELECT address_ar FROM crm_region LIMIT 1');
        } catch (Throwable $e) {
            try {
                $pdo->exec('ALTER TABLE crm_region ADD COLUMN address_ar VARCHAR(255) NULL DEFAULT NULL AFTER name_ar');
            } catch (Throwable $e2) {
                //
            }
        }
        try {
            $pdo->exec('ALTER TABLE crm_region MODIFY name_ar VARCHAR(180) NOT NULL');
        } catch (Throwable $e) {
            //
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS crm_region_address (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                region_id INT UNSIGNED NOT NULL,
                name_ar VARCHAR(180) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_crm_ra_region_name (region_id, name_ar),
                KEY idx_crm_ra_region (region_id)
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

        $hasAddrCol = false;
        try {
            $st = $pdo->query("SHOW COLUMNS FROM crm_customer LIKE 'region_address_id'");
            $hasAddrCol = (bool) ($st && $st->fetch());
        } catch (Throwable $e) {
            $hasAddrCol = false;
        }
        if (!$hasAddrCol) {
            try {
                $pdo->exec(
                    'ALTER TABLE crm_customer ADD COLUMN region_address_id INT UNSIGNED NULL DEFAULT NULL AFTER region_id, ADD KEY idx_crm_customer_region_address (region_address_id)'
                );
            } catch (Throwable $e) {
                //
            }
        }

        crm_sales_rep_region_ensure_schema($pdo);
        crm_sales_rep_region_address_ensure_schema($pdo);
        crm_region_migrate_legacy_addresses($pdo);

        $done = true;

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function crm_sales_rep_region_address_ensure_schema(PDO $pdo): bool
{
    static $done = false;
    if ($done) {
        return true;
    }
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS crm_sales_rep_region_address (
                sales_rep_id INT UNSIGNED NOT NULL,
                region_address_id INT UNSIGNED NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                PRIMARY KEY (sales_rep_id, region_address_id),
                KEY idx_csrra_addr (region_address_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $done = true;

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** نقل address_ar القديم إلى جدول العناوين + دمج المناطق متشابهة الاسم. */
function crm_region_migrate_legacy_addresses(PDO $pdo): void
{
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;

    try {
        // 1) نسخ العنوان القديم لكل صف
        $rows = $pdo->query(
            "SELECT id, name_ar, address_ar, sort_order, is_active
             FROM crm_region
             WHERE address_ar IS NOT NULL AND TRIM(address_ar) <> ''"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $ins = $pdo->prepare(
            'INSERT IGNORE INTO crm_region_address (region_id, name_ar, sort_order, is_active) VALUES (?,?,?,?)'
        );
        foreach ($rows as $r) {
            $ins->execute([
                (int) $r['id'],
                trim((string) $r['address_ar']),
                (int) ($r['sort_order'] ?? 0),
                (int) ($r['is_active'] ?? 1),
            ]);
        }

        // 2) دمج المناطق بنفس الاسم — الإبقاء على أصغر id
        $groups = $pdo->query(
            'SELECT name_ar, GROUP_CONCAT(id ORDER BY id ASC) AS ids, COUNT(*) AS c
             FROM crm_region
             GROUP BY name_ar
             HAVING c > 1'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($groups as $g) {
            $ids = array_map('intval', explode(',', (string) ($g['ids'] ?? '')));
            $ids = array_values(array_filter($ids, static fn (int $x): bool => $x > 0));
            if (count($ids) < 2) {
                continue;
            }
            $keep = $ids[0];
            $drop = array_slice($ids, 1);
            foreach ($drop as $oldId) {
                // نقل العناوين
                $addrs = $pdo->prepare('SELECT id, name_ar FROM crm_region_address WHERE region_id = ?');
                $addrs->execute([$oldId]);
                foreach ($addrs->fetchAll(PDO::FETCH_ASSOC) ?: [] as $a) {
                    $name = (string) ($a['name_ar'] ?? '');
                    $exist = $pdo->prepare(
                        'SELECT id FROM crm_region_address WHERE region_id = ? AND name_ar = ? LIMIT 1'
                    );
                    $exist->execute([$keep, $name]);
                    $targetAddr = (int) $exist->fetchColumn();
                    if ($targetAddr < 1) {
                        $pdo->prepare('UPDATE crm_region_address SET region_id = ? WHERE id = ?')
                            ->execute([$keep, (int) $a['id']]);
                        $targetAddr = (int) $a['id'];
                    } else {
                        // نقل ربط المندوبين ثم حذف العنوان المكرر
                        try {
                            $pdo->prepare(
                                'UPDATE IGNORE crm_sales_rep_region_address SET region_address_id = ? WHERE region_address_id = ?'
                            )->execute([$targetAddr, (int) $a['id']]);
                            $pdo->prepare('DELETE FROM crm_sales_rep_region_address WHERE region_address_id = ?')
                                ->execute([(int) $a['id']]);
                        } catch (Throwable $e) {
                            //
                        }
                        try {
                            $pdo->prepare(
                                'UPDATE crm_customer SET region_address_id = ? WHERE region_address_id = ?'
                            )->execute([$targetAddr, (int) $a['id']]);
                        } catch (Throwable $e) {
                            //
                        }
                        $pdo->prepare('DELETE FROM crm_region_address WHERE id = ?')->execute([(int) $a['id']]);
                    }
                }

                // نقل عملاء
                $pdo->prepare('UPDATE crm_customer SET region_id = ? WHERE region_id = ?')
                    ->execute([$keep, $oldId]);
                // نقل ربط مناديب↔منطقة
                try {
                    $pdo->prepare(
                        'INSERT IGNORE INTO crm_sales_rep_region (sales_rep_id, region_id, sort_order)
                         SELECT sales_rep_id, ?, sort_order FROM crm_sales_rep_region WHERE region_id = ?'
                    )->execute([$keep, $oldId]);
                    $pdo->prepare('DELETE FROM crm_sales_rep_region WHERE region_id = ?')->execute([$oldId]);
                } catch (Throwable $e) {
                    //
                }
                $pdo->prepare('DELETE FROM crm_region WHERE id = ?')->execute([$oldId]);
            }
        }
    } catch (Throwable $e) {
        // أفضل عدم كسر التشغيل
    }
}

function crm_sales_rep_region_ensure_schema(PDO $pdo): bool
{
    static $done = false;
    if ($done) {
        return true;
    }
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

// ─── منطقة (اسم فقط) ───────────────────────────────────────────

/**
 * @return list<array{id:int,code:string,name_ar:string,sort_order:int,is_active:int,address_count:int,customer_count:int,rep_count:int,label:string,address_ar:string}>
 */
function crm_region_load_active(PDO $pdo): array
{
    crm_region_ensure_schema($pdo);
    try {
        $rows = $pdo->query(
            'SELECT r.id, r.code, r.name_ar, r.sort_order, r.is_active,
                    (SELECT COUNT(*) FROM crm_region_address a WHERE a.region_id = r.id AND a.is_active = 1) AS address_count
             FROM crm_region r
             WHERE r.is_active = 1
             ORDER BY r.sort_order ASC, r.name_ar ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $r): array {
            $name = (string) ($r['name_ar'] ?? '');

            return [
                'id' => (int) ($r['id'] ?? 0),
                'code' => (string) ($r['code'] ?? ''),
                'name_ar' => $name,
                'address_ar' => '',
                'sort_order' => (int) ($r['sort_order'] ?? 0),
                'is_active' => (int) ($r['is_active'] ?? 0),
                'address_count' => (int) ($r['address_count'] ?? 0),
                'label' => $name,
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
    try {
        return $pdo->query(
            'SELECT r.*,
                    (SELECT COUNT(*) FROM crm_region_address a WHERE a.region_id = r.id) AS address_count,
                    (SELECT COUNT(*) FROM crm_customer c WHERE c.region_id = r.id) AS customer_count,
                    (SELECT COUNT(DISTINCT srr.sales_rep_id) FROM crm_sales_rep_region srr WHERE srr.region_id = r.id) AS rep_count
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

/** إيجاد منطقة بالاسم فقط (بدون عنوان على الصف). */
function crm_region_find_or_create_by_name(PDO $pdo, string $nameAr, ?int $sortHint = null): int
{
    crm_region_ensure_schema($pdo);
    $nameAr = trim($nameAr);
    if ($nameAr === '') {
        throw new RuntimeException('اسم المنطقة فارغ.');
    }

    $st = $pdo->prepare('SELECT id FROM crm_region WHERE name_ar = ? ORDER BY id ASC LIMIT 1');
    $st->execute([$nameAr]);
    $id = (int) $st->fetchColumn();
    if ($id > 0) {
        $pdo->prepare('UPDATE crm_region SET is_active = 1 WHERE id = ?')->execute([$id]);

        return $id;
    }

    $n = (int) $pdo->query('SELECT IFNULL(MAX(id), 0) FROM crm_region')->fetchColumn();
    $code = 'R' . str_pad((string) ($n + 1), 4, '0', STR_PAD_LEFT);
    $try = 0;
    while ($try < 50) {
        $chk = $pdo->prepare('SELECT id FROM crm_region WHERE code = ? LIMIT 1');
        $chk->execute([$code]);
        if (!$chk->fetch()) {
            break;
        }
        $try++;
        $code = 'R' . str_pad((string) ($n + 1 + $try), 4, '0', STR_PAD_LEFT);
    }

    $sort = $sortHint !== null ? $sortHint : (($n + 1) * 10);
    $ins = $pdo->prepare(
        'INSERT INTO crm_region (code, name_ar, sort_order, is_active) VALUES (?,?,?,1)'
    );
    $ins->execute([$code, $nameAr, $sort]);

    return (int) $pdo->lastInsertId();
}

// ─── عناوين المنطقة ────────────────────────────────────────────

/**
 * @return list<array{id:int,region_id:int,name_ar:string,sort_order:int,is_active:int,customer_count:int}>
 */
function crm_region_address_load(PDO $pdo, int $regionId, bool $activeOnly = false): array
{
    if ($regionId < 1) {
        return [];
    }
    crm_region_ensure_schema($pdo);
    try {
        $sql = 'SELECT a.*,
                       (SELECT COUNT(*) FROM crm_customer c WHERE c.region_address_id = a.id) AS customer_count
                FROM crm_region_address a
                WHERE a.region_id = ?';
        if ($activeOnly) {
            $sql .= ' AND a.is_active = 1';
        }
        $sql .= ' ORDER BY a.sort_order ASC, a.name_ar ASC';
        $st = $pdo->prepare($sql);
        $st->execute([$regionId]);

        return array_map(static function (array $r): array {
            return [
                'id' => (int) ($r['id'] ?? 0),
                'region_id' => (int) ($r['region_id'] ?? 0),
                'name_ar' => (string) ($r['name_ar'] ?? ''),
                'sort_order' => (int) ($r['sort_order'] ?? 0),
                'is_active' => (int) ($r['is_active'] ?? 0),
                'customer_count' => (int) ($r['customer_count'] ?? 0),
            ];
        }, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * كل العناوين النشطة مع اسم المنطقة — لقوائم الاختيار.
 *
 * @return list<array{id:int,region_id:int,region_name:string,name_ar:string,label:string}>
 */
function crm_region_address_load_all_active(PDO $pdo): array
{
    crm_region_ensure_schema($pdo);
    try {
        $rows = $pdo->query(
            'SELECT a.id, a.region_id, a.name_ar, r.name_ar AS region_name
             FROM crm_region_address a
             INNER JOIN crm_region r ON r.id = a.region_id
             WHERE a.is_active = 1 AND r.is_active = 1
             ORDER BY r.sort_order, r.name_ar, a.sort_order, a.name_ar'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $r): array {
            $rn = (string) ($r['region_name'] ?? '');
            $an = (string) ($r['name_ar'] ?? '');

            return [
                'id' => (int) ($r['id'] ?? 0),
                'region_id' => (int) ($r['region_id'] ?? 0),
                'region_name' => $rn,
                'name_ar' => $an,
                'label' => $rn . ' — ' . $an,
            ];
        }, $rows);
    } catch (Throwable $e) {
        return [];
    }
}

function crm_region_address_exists_active(PDO $pdo, int $addressId): bool
{
    if ($addressId < 1) {
        return false;
    }
    crm_region_ensure_schema($pdo);
    $st = $pdo->prepare(
        'SELECT a.id FROM crm_region_address a
         INNER JOIN crm_region r ON r.id = a.region_id
         WHERE a.id = ? AND a.is_active = 1 AND r.is_active = 1 LIMIT 1'
    );
    $st->execute([$addressId]);

    return (bool) $st->fetch();
}

function crm_region_address_find_or_create(PDO $pdo, int $regionId, string $nameAr, ?int $sortHint = null): int
{
    crm_region_ensure_schema($pdo);
    $nameAr = trim($nameAr);
    if ($regionId < 1) {
        throw new RuntimeException('المنطقة غير صالحة.');
    }
    if ($nameAr === '') {
        throw new RuntimeException('اسم العنوان فارغ.');
    }
    if (!crm_region_exists_active($pdo, $regionId) && !crm_region_id_exists($pdo, $regionId)) {
        throw new RuntimeException('المنطقة غير موجودة.');
    }

    $st = $pdo->prepare(
        'SELECT id FROM crm_region_address WHERE region_id = ? AND name_ar = ? LIMIT 1'
    );
    $st->execute([$regionId, $nameAr]);
    $id = (int) $st->fetchColumn();
    if ($id > 0) {
        $pdo->prepare('UPDATE crm_region_address SET is_active = 1 WHERE id = ?')->execute([$id]);

        return $id;
    }

    $n = (int) $pdo->query('SELECT IFNULL(MAX(id), 0) FROM crm_region_address')->fetchColumn();
    $sort = $sortHint !== null ? $sortHint : (($n + 1) * 10);
    $ins = $pdo->prepare(
        'INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active) VALUES (?,?,?,1)'
    );
    $ins->execute([$regionId, $nameAr, $sort]);

    return (int) $pdo->lastInsertId();
}

function crm_region_id_exists(PDO $pdo, int $regionId): bool
{
    if ($regionId < 1) {
        return false;
    }
    $st = $pdo->prepare('SELECT id FROM crm_region WHERE id = ? LIMIT 1');
    $st->execute([$regionId]);

    return (bool) $st->fetch();
}

/**
 * خريطة منطقة → عناوين (JSON للواجهات).
 *
 * @return array<int, list<array{id:int,name_ar:string}>>
 */
function crm_region_addresses_map(PDO $pdo): array
{
    crm_region_ensure_schema($pdo);
    $map = [];
    try {
        $rows = $pdo->query(
            'SELECT id, region_id, name_ar FROM crm_region_address WHERE is_active = 1 ORDER BY sort_order, name_ar'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            $rid = (int) ($r['region_id'] ?? 0);
            if ($rid < 1) {
                continue;
            }
            if (!isset($map[$rid])) {
                $map[$rid] = [];
            }
            $map[$rid][] = [
                'id' => (int) ($r['id'] ?? 0),
                'name_ar' => (string) ($r['name_ar'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        //
    }

    return $map;
}

// ─── ربط المندوب ───────────────────────────────────────────────

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

/** @return list<int> */
function crm_sales_rep_region_address_ids(PDO $pdo, int $salesRepId): array
{
    if ($salesRepId < 1) {
        return [];
    }
    crm_sales_rep_region_address_ensure_schema($pdo);
    try {
        $st = $pdo->prepare(
            'SELECT region_address_id FROM crm_sales_rep_region_address WHERE sales_rep_id = ? ORDER BY sort_order, region_address_id'
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
 * تفاصيل تغطية المندوب (منطقة + عنوان).
 *
 * @return list<array{id:int,region_id:int,name_ar:string,address_ar:string,label:string,region_name:string,address_id:int}>
 */
function crm_sales_rep_coverage_detail(PDO $pdo, int $salesRepId): array
{
    if ($salesRepId < 1) {
        return [];
    }
    crm_region_ensure_schema($pdo);
    try {
        $st = $pdo->prepare(
            'SELECT a.id AS address_id, a.region_id, a.name_ar AS address_name,
                    r.name_ar AS region_name, srr.sort_order
             FROM crm_sales_rep_region_address srr
             INNER JOIN crm_region_address a ON a.id = srr.region_address_id
             INNER JOIN crm_region r ON r.id = a.region_id
             WHERE srr.sales_rep_id = ? AND a.is_active = 1 AND r.is_active = 1
             ORDER BY srr.sort_order ASC, r.name_ar ASC, a.name_ar ASC'
        );
        $st->execute([$salesRepId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $rn = (string) ($row['region_name'] ?? '');
            $an = (string) ($row['address_name'] ?? '');
            $out[] = [
                'id' => (int) ($row['address_id'] ?? 0),
                'address_id' => (int) ($row['address_id'] ?? 0),
                'region_id' => (int) ($row['region_id'] ?? 0),
                'name_ar' => $rn,
                'region_name' => $rn,
                'address_ar' => $an,
                'label' => $rn . ' — ' . $an,
            ];
        }

        // توافق خلفي: إن وُجدت مناطق على crm_sales_rep_region فقط
        if ($out === []) {
            $st2 = $pdo->prepare(
                'SELECT rg.id, rg.name_ar
                 FROM crm_sales_rep_region srr
                 INNER JOIN crm_region rg ON rg.id = srr.region_id
                 WHERE srr.sales_rep_id = ? AND rg.is_active = 1
                 ORDER BY srr.sort_order, rg.name_ar'
            );
            $st2->execute([$salesRepId]);
            foreach ($st2->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $rn = (string) ($row['name_ar'] ?? '');
                $out[] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'address_id' => 0,
                    'region_id' => (int) ($row['id'] ?? 0),
                    'name_ar' => $rn,
                    'region_name' => $rn,
                    'address_ar' => '',
                    'label' => $rn,
                ];
            }
        }

        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @param list<int|string> $regionAddressIds
 */
function crm_sales_rep_save_region_addresses(PDO $pdo, int $salesRepId, array $regionAddressIds): void
{
    if ($salesRepId < 1) {
        return;
    }
    crm_sales_rep_region_address_ensure_schema($pdo);
    crm_sales_rep_region_ensure_schema($pdo);

    $resolved = [];
    $regionIds = [];
    foreach ($regionAddressIds as $raw) {
        $id = (int) $raw;
        if ($id < 1 || in_array($id, $resolved, true)) {
            continue;
        }
        if (!crm_region_address_exists_active($pdo, $id)) {
            continue;
        }
        $resolved[] = $id;
        $st = $pdo->prepare('SELECT region_id FROM crm_region_address WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $rid = (int) $st->fetchColumn();
        if ($rid > 0 && !in_array($rid, $regionIds, true)) {
            $regionIds[] = $rid;
        }
    }

    $pdo->prepare('DELETE FROM crm_sales_rep_region_address WHERE sales_rep_id = ?')->execute([$salesRepId]);
    if ($resolved !== []) {
        $ins = $pdo->prepare(
            'INSERT INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order) VALUES (?,?,?)'
        );
        foreach ($resolved as $i => $aid) {
            $ins->execute([$salesRepId, $aid, $i]);
        }
    }

    // مزامنة جدول المناطق القديم للتوافق
    crm_sales_rep_save_regions($pdo, $salesRepId, $regionIds);
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
        if ($id > 0 && crm_region_id_exists($pdo, $id) && !in_array($id, $resolved, true)) {
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

/** أسماء تغطية مندوب — للعرض في القائمة */
function crm_sales_rep_region_names(PDO $pdo, int $salesRepId): string
{
    $items = crm_sales_rep_coverage_detail($pdo, $salesRepId);
    if ($items === []) {
        return '';
    }
    $labels = [];
    foreach ($items as $it) {
        $labels[] = (string) ($it['label'] ?? '');
    }

    return implode('، ', array_filter($labels));
}

/**
 * توافق: قديم — مناطق المندوب مع عنوان إن وُجد.
 *
 * @return list<array{id:int,name_ar:string,address_ar:string,label:string}>
 */
function crm_sales_rep_regions_detail(PDO $pdo, int $salesRepId): array
{
    return crm_sales_rep_coverage_detail($pdo, $salesRepId);
}
