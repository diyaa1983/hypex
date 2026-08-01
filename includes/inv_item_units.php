<?php
declare(strict_types=1);

/**
 * وحدات المادة المتعددة (أساسية + وحدات صرف بمعامل تحويل للأساسية).
 */

function inv_item_units_ensure_schema(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $pdo->query('SELECT id FROM inv_item_unit LIMIT 1');
        $ok = true;
    } catch (Throwable $e) {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/237_inv_item_units.sql');
        try {
            $pdo->query('SELECT id FROM inv_item_unit LIMIT 1');
            $ok = true;
        } catch (Throwable $e2) {
            $ok = false;
        }
    }
    if ($ok) {
        // مرة واحدة فقط لكل قاعدة (لا تكرار ALTER / backfill عند كل تنقّل)
        require_once app_path('includes/acc_coa_bootstrap.php');
        if (acc_coa_meta_get($pdo, 'inv_item_units_ready_v1') !== '1') {
            inv_item_units_ensure_line_columns($pdo);
            inv_item_units_backfill_from_items($pdo);
            acc_coa_meta_set($pdo, 'inv_item_units_ready_v1', '1');
        }
    }

    return $ok;
}

function inv_item_units_column_exists(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table) ?? '';
    $column = preg_replace('/[^a-zA-Z0-9_]/', '', $column) ?? '';
    if ($table === '' || $column === '') {
        return $cache[$key] = false;
    }
    try {
        $pdo->query('SELECT `' . $column . '` FROM `' . $table . '` LIMIT 1');
        $cache[$key] = true;
    } catch (Throwable $e) {
        $cache[$key] = false;
    }

    return $cache[$key];
}

function inv_item_units_ensure_line_columns(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $tables = ['sal_invoice_line', 'pur_invoice_line', 'sal_customer_order_line', 'pur_order_line'];
    foreach ($tables as $table) {
        try {
            $pdo->query('SELECT id FROM `' . $table . '` LIMIT 1');
        } catch (Throwable $e) {
            continue;
        }
        $alters = [];
        if (!inv_item_units_column_exists($pdo, $table, 'unit_id')) {
            $alters[] = 'ADD COLUMN unit_id INT UNSIGNED NULL';
        }
        if (!inv_item_units_column_exists($pdo, $table, 'unit_name') && $table !== 'sal_customer_order_line') {
            $alters[] = 'ADD COLUMN unit_name VARCHAR(120) NULL';
        }
        if (!inv_item_units_column_exists($pdo, $table, 'unit_factor')) {
            $alters[] = 'ADD COLUMN unit_factor DECIMAL(18,6) NOT NULL DEFAULT 1';
        }
        if (!inv_item_units_column_exists($pdo, $table, 'qty_base')) {
            $alters[] = 'ADD COLUMN qty_base DECIMAL(18,6) NULL';
        }
        foreach ($alters as $sql) {
            try {
                $pdo->exec('ALTER TABLE `' . $table . '` ' . $sql);
            } catch (Throwable $e) {
                // ignore race
            }
        }
    }
}

function inv_item_units_backfill_from_items(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo->exec(
            "INSERT IGNORE INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
             SELECT i.id, i.unit_id, 1, 1, 1
             FROM inv_item i
             WHERE i.unit_id IS NOT NULL AND i.unit_id > 0
               AND NOT EXISTS (
                   SELECT 1 FROM inv_item_unit u WHERE u.item_id = i.id AND u.is_base = 1
               )"
        );
        $pcsId = (int) $pdo->query(
            "SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1"
        )->fetchColumn();
        if ($pcsId > 0) {
            $pdo->exec(
                "INSERT IGNORE INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
                 SELECT i.id, {$pcsId}, 1, 1, 1
                 FROM inv_item i
                 WHERE (i.unit_id IS NULL OR i.unit_id = 0)
                   AND NOT EXISTS (SELECT 1 FROM inv_item_unit u WHERE u.item_id = i.id)"
            );
        }
    } catch (Throwable $e) {
        // ignore
    }
}

function inv_item_unit_to_base_qty(float $qty, float $factor): float
{
    $f = $factor > 0 ? $factor : 1.0;

    return $qty * $f;
}

function inv_item_unit_price_for_factor(float $basePrice, float $factor): float
{
    $f = $factor > 0 ? $factor : 1.0;

    return $basePrice * $f;
}

/**
 * @return list<array{id:int,unit_id:int,name:string,factor:float,is_base:bool,is_default:bool}>
 */
function inv_item_units_for_item(PDO $pdo, int $itemId): array
{
    if ($itemId < 1 || !inv_item_units_ensure_schema($pdo)) {
        return inv_item_units_fallback_from_item($pdo, $itemId);
    }
    try {
        $st = $pdo->prepare(
            'SELECT iu.id, iu.unit_id, iu.factor_to_base, iu.is_base, iu.is_default_issue,
                    COALESCE(u.name_ar, \'\') AS unit_name
             FROM inv_item_unit iu
             INNER JOIN inv_unit u ON u.id = iu.unit_id
             WHERE iu.item_id = ?
             ORDER BY iu.is_base DESC, iu.is_default_issue DESC, iu.id ASC'
        );
        $st->execute([$itemId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return inv_item_units_fallback_from_item($pdo, $itemId);
    }
    if ($rows === []) {
        return inv_item_units_fallback_from_item($pdo, $itemId);
    }
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id' => (int) ($r['id'] ?? 0),
            'unit_id' => (int) ($r['unit_id'] ?? 0),
            'name' => (string) ($r['unit_name'] ?? ''),
            'factor' => (float) ($r['factor_to_base'] ?? 1),
            'is_base' => (int) ($r['is_base'] ?? 0) === 1,
            'is_default' => (int) ($r['is_default_issue'] ?? 0) === 1,
        ];
    }

    return $out;
}

/**
 * @return list<array{id:int,unit_id:int,name:string,factor:float,is_base:bool,is_default:bool}>
 */
function inv_item_units_fallback_from_item(PDO $pdo, int $itemId): array
{
    if ($itemId < 1) {
        return [];
    }
    try {
        $st = $pdo->prepare(
            'SELECT i.unit_id, COALESCE(u.name_ar, i.unit_name, \'قطعة\') AS unit_name
             FROM inv_item i
             LEFT JOIN inv_unit u ON u.id = i.unit_id
             WHERE i.id = ? LIMIT 1'
        );
        $st->execute([$itemId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
    if (!$row) {
        return [];
    }
    $unitId = (int) ($row['unit_id'] ?? 0);

    return [[
        'id' => 0,
        'unit_id' => $unitId,
        'name' => (string) ($row['unit_name'] ?? 'قطعة'),
        'factor' => 1.0,
        'is_base' => true,
        'is_default' => true,
    ]];
}

/**
 * @param list<array<string,mixed>> $units  each: unit_id, factor_to_base, is_base?, is_default_issue?
 */
function inv_item_units_save(PDO $pdo, int $itemId, int $baseUnitId, array $units): void
{
    if ($itemId < 1 || $baseUnitId < 1) {
        throw new RuntimeException('المادة والوحدة الأساسية مطلوبان.');
    }
    if (!inv_item_units_ensure_schema($pdo)) {
        throw new RuntimeException('تعذر تهيئة جدول وحدات المادة.');
    }

    $normalized = [];
    $seen = [];
    // الأساسية دائماً
    $normalized[] = [
        'unit_id' => $baseUnitId,
        'factor_to_base' => 1.0,
        'is_base' => 1,
        'is_default_issue' => 1,
    ];
    $seen[$baseUnitId] = true;
    $hasDefault = false;

    foreach ($units as $u) {
        $uid = (int) ($u['unit_id'] ?? 0);
        if ($uid < 1 || isset($seen[$uid])) {
            continue;
        }
        $factor = (float) ($u['factor_to_base'] ?? $u['factor'] ?? 0);
        if ($uid === $baseUnitId) {
            continue;
        }
        if ($factor <= 1) {
            throw new RuntimeException('وحدة الصرف يجب أن تعادل أكثر من 1 من الوحدة الأساسية.');
        }
        $isDefault = !empty($u['is_default_issue']) || !empty($u['is_default']);
        if ($isDefault) {
            $hasDefault = true;
            $normalized[0]['is_default_issue'] = 0;
        }
        $normalized[] = [
            'unit_id' => $uid,
            'factor_to_base' => $factor,
            'is_base' => 0,
            'is_default_issue' => $isDefault ? 1 : 0,
        ];
        $seen[$uid] = true;
    }
    if (!$hasDefault) {
        $normalized[0]['is_default_issue'] = 1;
    }

    $pdo->prepare('DELETE FROM inv_item_unit WHERE item_id = ?')->execute([$itemId]);
    $ins = $pdo->prepare(
        'INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
         VALUES (?,?,?,?,?)'
    );
    foreach ($normalized as $row) {
        $ins->execute([
            $itemId,
            $row['unit_id'],
            $row['factor_to_base'],
            $row['is_base'],
            $row['is_default_issue'],
        ]);
    }
}

/**
 * @return array{unit_id:int,unit_name:string,unit_factor:float}|null
 */
function inv_item_unit_resolve(PDO $pdo, int $itemId, ?int $unitId): ?array
{
    $units = inv_item_units_for_item($pdo, $itemId);
    if ($units === []) {
        return null;
    }
    if ($unitId !== null && $unitId > 0) {
        foreach ($units as $u) {
            if ((int) $u['unit_id'] === $unitId) {
                return [
                    'unit_id' => (int) $u['unit_id'],
                    'unit_name' => (string) $u['name'],
                    'unit_factor' => (float) $u['factor'],
                ];
            }
        }
        throw new RuntimeException('الوحدة المختارة غير معرّفة لهذه المادة.');
    }
    foreach ($units as $u) {
        if (!empty($u['is_default'])) {
            return [
                'unit_id' => (int) $u['unit_id'],
                'unit_name' => (string) $u['name'],
                'unit_factor' => (float) $u['factor'],
            ];
        }
    }
    $u = $units[0];

    return [
        'unit_id' => (int) $u['unit_id'],
        'unit_name' => (string) $u['name'],
        'unit_factor' => (float) $u['factor'],
    ];
}

/**
 * إرفاق مصفوفة units بكل صف مادة في نتائج البحث.
 *
 * @param list<array<string,mixed>> $items
 * @return list<array<string,mixed>>
 */
function inv_item_units_attach_to_items(PDO $pdo, array $items, string $idKey = 'id'): array
{
    if ($items === [] || !inv_item_units_ensure_schema($pdo)) {
        foreach ($items as &$it) {
            $uid = (int) ($it['unit_id'] ?? 0);
            $name = (string) ($it['unit_name'] ?? 'قطعة');
            $it['units'] = [[
                'id' => 0,
                'unit_id' => $uid,
                'name' => $name,
                'factor' => 1.0,
                'is_base' => true,
                'is_default' => true,
            ]];
        }
        unset($it);

        return $items;
    }
    $ids = [];
    foreach ($items as $it) {
        $id = (int) ($it[$idKey] ?? $it['item_id'] ?? $it['id'] ?? 0);
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    $ids = array_values(array_unique($ids));
    $map = [];
    if ($ids !== []) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        try {
            $st = $pdo->prepare(
                "SELECT iu.item_id, iu.id, iu.unit_id, iu.factor_to_base, iu.is_base, iu.is_default_issue,
                        COALESCE(u.name_ar, '') AS unit_name
                 FROM inv_item_unit iu
                 INNER JOIN inv_unit u ON u.id = iu.unit_id
                 WHERE iu.item_id IN ({$ph})
                 ORDER BY iu.item_id, iu.is_base DESC, iu.is_default_issue DESC, iu.id ASC"
            );
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $iid = (int) $r['item_id'];
                $map[$iid][] = [
                    'id' => (int) $r['id'],
                    'unit_id' => (int) $r['unit_id'],
                    'name' => (string) $r['unit_name'],
                    'factor' => (float) $r['factor_to_base'],
                    'is_base' => (int) $r['is_base'] === 1,
                    'is_default' => (int) $r['is_default_issue'] === 1,
                ];
            }
        } catch (Throwable $e) {
            $map = [];
        }
    }
    foreach ($items as &$it) {
        $iid = (int) ($it[$idKey] ?? $it['item_id'] ?? $it['id'] ?? 0);
        if (!empty($map[$iid])) {
            $it['units'] = $map[$iid];
        } else {
            $it['units'] = [[
                'id' => 0,
                'unit_id' => (int) ($it['unit_id'] ?? 0),
                'name' => (string) ($it['unit_name'] ?? 'قطعة'),
                'factor' => 1.0,
                'is_base' => true,
                'is_default' => true,
            ]];
        }
    }
    unset($it);

    return $items;
}
