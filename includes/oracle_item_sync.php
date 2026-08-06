<?php
declare(strict_types=1);

/**
 * مزامنة مجموعات ومواد Oracle → inv_item_category / inv_item
 */

require_once app_path('includes/oracle_pdo.php');

/**
 * @param array<string, mixed> $row
 */
function oracle_row_get(array $row, string $col): string
{
    if ($col === '') {
        return '';
    }
    $v = $row[$col]
        ?? $row[strtolower($col)]
        ?? $row[strtoupper($col)]
        ?? '';
    if (is_object($v) && method_exists($v, 'load')) {
        $v = $v->load();
    }

    return trim((string) $v);
}

function oracle_item_schema_ensure(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $st = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inv_item' AND COLUMN_NAME = 'oracle_key'"
        );
        if ((int) $st->fetchColumn() === 0) {
            $pdo->exec(
                "ALTER TABLE inv_item
                 ADD COLUMN oracle_key VARCHAR(80) NULL,
                 ADD UNIQUE KEY uq_inv_item_oracle_key (oracle_key)"
            );
        }
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $st = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inv_item_category' AND COLUMN_NAME = 'oracle_key'"
        );
        if ((int) $st->fetchColumn() === 0) {
            $pdo->exec(
                "ALTER TABLE inv_item_category
                 ADD COLUMN oracle_key VARCHAR(80) NULL,
                 ADD UNIQUE KEY uq_inv_cat_oracle_key (oracle_key)"
            );
        }
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * توليد باركود قصير فريد من sku عند الحاجة.
 */
function oracle_item_make_barcode(PDO $pdo, string $sku, ?int $excludeId = null): string
{
    $digits = preg_replace('/\D+/', '', $sku) ?? '';
    if ($digits === '') {
        $digits = (string) (abs(crc32($sku)) % 10000000000000);
    }
    $base = substr($digits, 0, 14);
    if ($base === '') {
        $base = '1';
    }
    $candidate = $base;
    $n = 0;
    $sql = 'SELECT id FROM inv_item WHERE barcode = ? LIMIT 1';
    $st = $pdo->prepare($sql);
    while (true) {
        $st->execute([$candidate]);
        $id = (int) ($st->fetchColumn() ?: 0);
        if ($id < 1 || ($excludeId !== null && $id === $excludeId)) {
            return $candidate;
        }
        $n++;
        $suffix = (string) $n;
        $candidate = substr($base, 0, max(1, 14 - strlen($suffix))) . $suffix;
        if ($n > 500) {
            return substr((string) time() . (string) random_int(100, 999), 0, 14);
        }
    }
}

/**
 * @param array<string, string> $columnMap
 * @return array{inserted:int, updated:int, skipped:int, errors:list<string>}
 */
function oracle_sync_item_categories_to_mysql(
    PDO $mysql,
    array $oraConn,
    string $owner,
    string $table,
    array $columnMap
): array {
    oracle_item_schema_ensure($mysql);
    $result = ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
    $owner = strtoupper(trim($owner));
    $table = strtoupper(trim($table));
    $keyCol = strtoupper(trim((string) ($columnMap['oracle_key'] ?? $columnMap['code'] ?? '')));
    $codeCol = strtoupper(trim((string) ($columnMap['code'] ?? $keyCol)));
    $nameCol = strtoupper(trim((string) ($columnMap['name_ar'] ?? '')));

    if ($owner === '' || $table === '' || $keyCol === '' || $nameCol === '') {
        $result['errors'][] = 'تعيين مجموعات المواد ناقص (owner/table/key/name).';

        return $result;
    }

    $cols = array_values(array_unique(array_filter([$keyCol, $codeCol, $nameCol])));
    $quoted = [];
    foreach ($cols as $c) {
        $quoted[] = '"' . str_replace('"', '""', $c) . '"';
    }
    $sql = 'SELECT ' . implode(', ', $quoted)
        . ' FROM "' . str_replace('"', '""', $owner) . '"."' . str_replace('"', '""', $table) . '"';

    try {
        $rows = oracle_query_all($oraConn, $sql);
    } catch (Throwable $e) {
        $result['errors'][] = 'فشل قراءة مجموعات Oracle: ' . $e->getMessage();

        return $result;
    }

    $selKey = $mysql->prepare('SELECT id FROM inv_item_category WHERE oracle_key = ? LIMIT 1');
    $selCode = $mysql->prepare('SELECT id FROM inv_item_category WHERE code = ? LIMIT 1');
    $ins = $mysql->prepare(
        'INSERT INTO inv_item_category (code, name_ar, is_active, oracle_key) VALUES (?,?,1,?)'
    );
    $upd = $mysql->prepare(
        'UPDATE inv_item_category SET code = ?, name_ar = ?, is_active = 1, oracle_key = ? WHERE id = ?'
    );
    $usedCodes = [];

    foreach ($rows as $row) {
        $key = oracle_row_get($row, $keyCol);
        $name = oracle_row_get($row, $nameCol);
        if ($key === '' || $name === '') {
            $result['skipped']++;
            continue;
        }
        $code = $codeCol !== '' ? oracle_row_get($row, $codeCol) : $key;
        if ($code === '') {
            $code = $key;
        }
        $code = mb_substr($code, 0, 40);
        $base = $code;
        $n = 1;
        while (isset($usedCodes[$code])) {
            $suf = '-' . $n;
            $code = mb_substr($base, 0, 40 - strlen($suf)) . $suf;
            $n++;
        }
        $usedCodes[$code] = true;

        try {
            $selKey->execute([$key]);
            $id = (int) ($selKey->fetchColumn() ?: 0);
            if ($id < 1) {
                $selCode->execute([$code]);
                $id = (int) ($selCode->fetchColumn() ?: 0);
            }
            if ($id > 0) {
                $upd->execute([$code, $name, $key, $id]);
                $result['updated']++;
            } else {
                $ins->execute([$code, $name, $key]);
                $result['inserted']++;
            }
        } catch (Throwable $e) {
            $result['errors'][] = 'مجموعة ' . $key . ': ' . $e->getMessage();
            if (count($result['errors']) > 30) {
                break;
            }
        }
    }

    return $result;
}

/**
 * @param array<string, string> $columnMap
 * @return array{inserted:int, updated:int, skipped:int, errors:list<string>}
 */
function oracle_sync_items_to_mysql(
    PDO $mysql,
    array $oraConn,
    string $owner,
    string $table,
    array $columnMap
): array {
    oracle_item_schema_ensure($mysql);
    require_once app_path('includes/inv_item_schema.php');
    inv_item_ensure_extended_schema($mysql);

    $result = ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
    $owner = strtoupper(trim($owner));
    $table = strtoupper(trim($table));
    $keyCol = strtoupper(trim((string) ($columnMap['oracle_key'] ?? $columnMap['code'] ?? $columnMap['sku'] ?? '')));
    $skuCol = strtoupper(trim((string) ($columnMap['sku'] ?? $columnMap['code'] ?? $keyCol)));
    $nameCol = strtoupper(trim((string) ($columnMap['name_ar'] ?? '')));
    $groupCol = strtoupper(trim((string) ($columnMap['group_key'] ?? $columnMap['category_key'] ?? '')));
    $costCol = strtoupper(trim((string) ($columnMap['default_cost'] ?? '')));
    $saleCol = strtoupper(trim((string) ($columnMap['default_sale'] ?? '')));
    $unitCol = strtoupper(trim((string) ($columnMap['unit_name'] ?? '')));
    $barcodeCol = strtoupper(trim((string) ($columnMap['barcode'] ?? '')));

    if ($owner === '' || $table === '' || $keyCol === '' || $nameCol === '') {
        $result['errors'][] = 'تعيين المواد ناقص (owner/table/key/name).';

        return $result;
    }

    $cols = array_values(array_unique(array_filter([
        $keyCol, $skuCol, $nameCol, $groupCol, $costCol, $saleCol, $unitCol, $barcodeCol,
    ])));
    $quoted = [];
    foreach ($cols as $c) {
        $quoted[] = '"' . str_replace('"', '""', $c) . '"';
    }
    $sql = 'SELECT ' . implode(', ', $quoted)
        . ' FROM "' . str_replace('"', '""', $owner) . '"."' . str_replace('"', '""', $table) . '"';

    try {
        $rows = oracle_query_all($oraConn, $sql);
    } catch (Throwable $e) {
        $result['errors'][] = 'فشل قراءة مواد Oracle: ' . $e->getMessage();

        return $result;
    }

    // خريطة oracle_key المجموعة → category_id
    $catMap = [];
    try {
        $stCats = $mysql->query(
            "SELECT id, oracle_key, code FROM inv_item_category
             WHERE oracle_key IS NOT NULL AND oracle_key <> ''"
        );
        foreach ($stCats->fetchAll(PDO::FETCH_ASSOC) ?: [] as $c) {
            $catMap[strtoupper((string) $c['oracle_key'])] = (int) $c['id'];
            $catMap[strtoupper((string) $c['code'])] = (int) $c['id'];
        }
    } catch (Throwable $e) {
        // ignore
    }

    $selKey = $mysql->prepare('SELECT id FROM inv_item WHERE oracle_key = ? LIMIT 1');
    $selSku = $mysql->prepare('SELECT id FROM inv_item WHERE sku = ? LIMIT 1');
    $hasBarcode = true;
    try {
        $mysql->query('SELECT barcode FROM inv_item LIMIT 1');
    } catch (Throwable $e) {
        $hasBarcode = false;
    }

    if ($hasBarcode) {
        $ins = $mysql->prepare(
            'INSERT INTO inv_item (sku, barcode, name_ar, category_id, unit_name, default_cost, default_sale, track_inventory, is_active, oracle_key)
             VALUES (?,?,?,?,?,?,?,1,1,?)'
        );
        $upd = $mysql->prepare(
            'UPDATE inv_item
             SET sku=?, barcode=?, name_ar=?, category_id=?, unit_name=?, default_cost=?, default_sale=?, is_active=1, oracle_key=?
             WHERE id=?'
        );
    } else {
        $ins = $mysql->prepare(
            'INSERT INTO inv_item (sku, name_ar, category_id, unit_name, default_cost, default_sale, track_inventory, is_active, oracle_key)
             VALUES (?,?,?,?,?,?,1,1,?)'
        );
        $upd = $mysql->prepare(
            'UPDATE inv_item
             SET sku=?, name_ar=?, category_id=?, unit_name=?, default_cost=?, default_sale=?, is_active=1, oracle_key=?
             WHERE id=?'
        );
    }

    $usedSku = [];

    foreach ($rows as $row) {
        $key = oracle_row_get($row, $keyCol);
        $name = oracle_row_get($row, $nameCol);
        if ($key === '' || $name === '') {
            $result['skipped']++;
            continue;
        }
        $sku = $skuCol !== '' ? oracle_row_get($row, $skuCol) : $key;
        if ($sku === '') {
            $sku = $key;
        }
        $sku = mb_substr($sku, 0, 64);
        $baseSku = $sku;
        $n = 1;
        while (isset($usedSku[$sku])) {
            $suf = '-' . $n;
            $sku = mb_substr($baseSku, 0, 64 - strlen($suf)) . $suf;
            $n++;
        }
        $usedSku[$sku] = true;

        $catId = null;
        if ($groupCol !== '') {
            $gk = strtoupper(oracle_row_get($row, $groupCol));
            if ($gk !== '' && isset($catMap[$gk])) {
                $catId = $catMap[$gk];
            }
        }

        $cost = 0.0;
        $sale = 0.0;
        if ($costCol !== '') {
            $cost = (float) str_replace([',', ' '], ['', ''], oracle_row_get($row, $costCol));
        }
        if ($saleCol !== '') {
            $sale = (float) str_replace([',', ' '], ['', ''], oracle_row_get($row, $saleCol));
        }
        $unitName = 'قطعة';
        if ($unitCol !== '') {
            $u = oracle_row_get($row, $unitCol);
            if ($u !== '') {
                $unitName = mb_substr($u, 0, 30);
            }
        }

        try {
            $selKey->execute([$key]);
            $id = (int) ($selKey->fetchColumn() ?: 0);
            if ($id < 1) {
                $selSku->execute([$sku]);
                $id = (int) ($selSku->fetchColumn() ?: 0);
            }

            if ($hasBarcode) {
                $barcode = $barcodeCol !== '' ? oracle_row_get($row, $barcodeCol) : '';
                $barcode = preg_replace('/\s+/', '', $barcode) ?? '';
                if ($barcode === '') {
                    $barcode = oracle_item_make_barcode($mysql, $sku, $id > 0 ? $id : null);
                } else {
                    $barcode = mb_substr($barcode, 0, 14);
                }
                if ($id > 0) {
                    $upd->execute([$sku, $barcode, $name, $catId, $unitName, $cost, $sale, $key, $id]);
                    $result['updated']++;
                } else {
                    $ins->execute([$sku, $barcode, $name, $catId, $unitName, $cost, $sale, $key]);
                    $result['inserted']++;
                }
            } else {
                if ($id > 0) {
                    $upd->execute([$sku, $name, $catId, $unitName, $cost, $sale, $key, $id]);
                    $result['updated']++;
                } else {
                    $ins->execute([$sku, $name, $catId, $unitName, $cost, $sale, $key]);
                    $result['inserted']++;
                }
            }
        } catch (Throwable $e) {
            $result['errors'][] = 'مادة ' . $key . ': ' . $e->getMessage();
            if (count($result['errors']) > 40) {
                $result['errors'][] = '… توقفت بعد أخطاء كثيرة';
                break;
            }
        }
    }

    return $result;
}

/**
 * @return array{owner:string,table:string,columns:array<string,string>}|null
 */
function oracle_cfg_entity_map(string $section): ?array
{
    $cfg = oracle_config();
    $s = is_array($cfg[$section] ?? null) ? $cfg[$section] : [];
    $owner = strtoupper(trim((string) ($s['owner'] ?? '')));
    $table = strtoupper(trim((string) ($s['table'] ?? '')));
    $cols = is_array($s['columns'] ?? null) ? $s['columns'] : [];
    if ($owner === '' || $table === '' || $cols === []) {
        return null;
    }
    $norm = [];
    foreach ($cols as $k => $v) {
        $v = strtoupper(trim((string) $v));
        if ($v !== '') {
            $norm[(string) $k] = $v;
        }
    }
    if ($norm === []) {
        return null;
    }

    return ['owner' => $owner, 'table' => $table, 'columns' => $norm];
}
