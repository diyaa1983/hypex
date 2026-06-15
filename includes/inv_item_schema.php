<?php
declare(strict_types=1);

function inv_item_has_category_table(PDO $pdo, bool $refresh = false): bool
{
    static $ok = null;
    if ($refresh) {
        $ok = null;
    }
    if ($ok !== null) {
        return $ok;
    }
    try {
        $pdo->query('SELECT id FROM inv_item_category LIMIT 1');
        $ok = true;
    } catch (Throwable $e) {
        $ok = false;
    }

    return $ok;
}

function inv_item_has_unit_table(PDO $pdo, bool $refresh = false): bool
{
    static $ok = null;
    if ($refresh) {
        $ok = null;
    }
    if ($ok !== null) {
        return $ok;
    }
    try {
        $pdo->query('SELECT id FROM inv_unit LIMIT 1');
        $ok = true;
    } catch (Throwable $e) {
        $ok = false;
    }

    return $ok;
}

function inv_item_has_extended_columns(PDO $pdo, bool $refresh = false): bool
{
    static $ok = null;
    if ($refresh) {
        $ok = null;
    }
    if ($ok !== null) {
        return $ok;
    }
    try {
        $pdo->query('SELECT category_id, unit_id, default_warehouse_id FROM inv_item LIMIT 1');
        $ok = true;
    } catch (Throwable $e) {
        $ok = false;
    }

    return $ok;
}

/** هل جدول المواد الأساسي موجود؟ */
function inv_item_has_main_table(PDO $pdo, bool $refresh = false): bool
{
    static $ok = null;
    if ($refresh) {
        $ok = null;
    }
    if ($ok !== null) {
        return $ok;
    }
    try {
        $pdo->query('SELECT id FROM inv_item LIMIT 1');
        $ok = true;
    } catch (Throwable $e) {
        $ok = false;
    }

    return $ok;
}

/** مستودع افتراضي إن لم يكن الجدول موجوداً (مطلوب لمفاتيح inv_item). */
function inv_warehouse_ensure_table(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT id FROM inv_warehouse LIMIT 1');

        return true;
    } catch (Throwable $e) {
        // إنشاء الجدول
    }

    try {
        $pdo->exec(
            'CREATE TABLE inv_warehouse (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              code VARCHAR(40) NOT NULL UNIQUE,
              name_ar VARCHAR(200) NOT NULL,
              is_active TINYINT(1) NOT NULL DEFAULT 1,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            "INSERT INTO inv_warehouse (code, name_ar) VALUES ('MAIN', 'المستودع الرئيسي')"
        );
    } catch (Throwable $e) {
        // قد يكون الجدول وُجد سباقًا
    }

    try {
        $pdo->query('SELECT id FROM inv_warehouse LIMIT 1');

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * التحقق قبل حذف مستودع (يُمنع إن وُجدت مواد أو حركات مخزنية مرتبطة به).
 *
 * @return array{can_delete:bool, items:int, stock_moves:int, message:string}
 */
function inv_warehouse_delete_check(PDO $pdo, int $warehouseId): array
{
    if ($warehouseId < 1) {
        return [
            'can_delete' => false,
            'items' => 0,
            'stock_moves' => 0,
            'message' => 'معرّف المستودع غير صالح.',
        ];
    }

    $st = $pdo->prepare('SELECT code, name_ar FROM inv_warehouse WHERE id = ? LIMIT 1');
    $st->execute([$warehouseId]);
    $whRow = $st->fetch(PDO::FETCH_ASSOC);
    if (!$whRow) {
        return [
            'can_delete' => false,
            'items' => 0,
            'stock_moves' => 0,
            'message' => 'المستودع غير موجود.',
        ];
    }
    $whLabel = trim((string) ($whRow['name_ar'] ?? ''));
    if ($whLabel === '') {
        $whLabel = (string) ($whRow['code'] ?? $warehouseId);
    }

    inv_item_ensure_extended_schema($pdo);
    $items = 0;
    if (inv_item_has_main_table($pdo, true)) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM inv_item WHERE default_warehouse_id = ?');
        $st->execute([$warehouseId]);
        $items = (int) $st->fetchColumn();
    }

    $stockMoves = 0;
    require_once app_path('includes/inv_stock.php');
    inv_stock_move_ensure_table($pdo);
    if (inv_stock_move_has_table($pdo)) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM inv_stock_move WHERE warehouse_id = ?');
        $st->execute([$warehouseId]);
        $stockMoves = (int) $st->fetchColumn();
    }

    if ($items > 0 || $stockMoves > 0) {
        $parts = [];
        if ($items > 0) {
            $parts[] = $items . ' مادة';
        }
        if ($stockMoves > 0) {
            $parts[] = $stockMoves . ' حركة مخزنية';
        }

        return [
            'can_delete' => false,
            'items' => $items,
            'stock_moves' => $stockMoves,
            'message' => 'لا يمكن حذف المستودع «' . $whLabel . '»: مرتبط بـ ' . implode(' و', $parts)
                . '. انقل المواد أو احذف الحركات أولاً.',
        ];
    }

    return [
        'can_delete' => true,
        'items' => 0,
        'stock_moves' => 0,
        'message' => '',
    ];
}

/** جداول الفئة/الوحدة + جدول المواد inv_item إن وُجد التثبيت بدونه + الأعمدة الإضافية. */
function inv_item_ensure_extended_schema(PDO $pdo): bool
{
    inv_warehouse_ensure_table($pdo);

    if (!inv_item_has_category_table($pdo)) {
        try {
            $pdo->exec(
                'CREATE TABLE inv_item_category (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    code VARCHAR(40) NOT NULL,
                    name_ar VARCHAR(200) NOT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_inv_cat_code (code)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $pdo->exec(
                "INSERT INTO inv_item_category (code, name_ar) VALUES
                ('GEN', 'عام'),
                ('FOOD', 'مواد غذائية'),
                ('BLD', 'مواد بناء')"
            );
        } catch (Throwable $e) {
            // ignore
        }
    }

    if (!inv_item_has_unit_table($pdo)) {
        try {
            $pdo->exec(
                'CREATE TABLE inv_unit (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    code VARCHAR(40) NOT NULL,
                    name_ar VARCHAR(100) NOT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_inv_unit_code (code)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $pdo->exec(
                "INSERT INTO inv_unit (code, name_ar) VALUES
                ('PCS', 'قطعة'),
                ('BOX', 'كرتون'),
                ('KG', 'كيلو'),
                ('L', 'لتر')"
            );
        } catch (Throwable $e) {
            // ignore
        }
    }

    if (!inv_item_has_main_table($pdo)) {
        try {
            $pdo->exec(
                'CREATE TABLE inv_item (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    sku VARCHAR(64) NOT NULL UNIQUE,
                    name_ar VARCHAR(200) NOT NULL,
                    category_id INT UNSIGNED NULL,
                    unit_id INT UNSIGNED NULL,
                    default_warehouse_id INT UNSIGNED NULL,
                    unit_name VARCHAR(30) NOT NULL DEFAULT \'قطعة\',
                    default_cost DECIMAL(18,6) NOT NULL DEFAULT 0,
                    default_sale DECIMAL(18,6) NOT NULL DEFAULT 0,
                    track_inventory TINYINT(1) NOT NULL DEFAULT 1,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_item_cat FOREIGN KEY (category_id) REFERENCES inv_item_category(id) ON DELETE SET NULL,
                    CONSTRAINT fk_item_unit FOREIGN KEY (unit_id) REFERENCES inv_unit(id) ON DELETE SET NULL,
                    CONSTRAINT fk_item_wh FOREIGN KEY (default_warehouse_id) REFERENCES inv_warehouse(id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (Throwable $e) {
            // ignore
        }
        inv_item_has_main_table($pdo, true);
    }

    if (inv_item_has_main_table($pdo) && !inv_item_has_extended_columns($pdo)) {
        try {
            $pdo->exec('ALTER TABLE inv_item ADD COLUMN category_id INT UNSIGNED NULL AFTER name_ar');
        } catch (Throwable $e) {
            // ignore duplicate
        }
        try {
            $pdo->exec('ALTER TABLE inv_item ADD COLUMN unit_id INT UNSIGNED NULL AFTER category_id');
        } catch (Throwable $e) {
            // ignore
        }
        try {
            $pdo->exec('ALTER TABLE inv_item ADD COLUMN default_warehouse_id INT UNSIGNED NULL AFTER unit_id');
        } catch (Throwable $e) {
            // ignore
        }
    }

    return inv_item_has_category_table($pdo, true)
        && inv_item_has_unit_table($pdo, true)
        && inv_item_has_main_table($pdo, true)
        && inv_item_has_extended_columns($pdo, true);
}

/** @return list<array{id:int,name_ar:string}> */
function inv_item_load_categories(PDO $pdo): array
{
    if (!inv_item_has_category_table($pdo)) {
        return [];
    }

    return $pdo->query('SELECT id, name_ar FROM inv_item_category WHERE is_active = 1 ORDER BY name_ar')->fetchAll() ?: [];
}

/**
 * التحقق قبل حذف فئة مواد (يُمنع إن وُجدت حركات مخزنية على مواد تابعة للفئة).
 *
 * @return array{can_delete:bool, stock_moves:int, items:int, message:string}
 */
function inv_category_delete_check(PDO $pdo, int $categoryId): array
{
    if ($categoryId < 1) {
        return [
            'can_delete' => false,
            'stock_moves' => 0,
            'items' => 0,
            'message' => 'معرّف الفئة غير صالح.',
        ];
    }

    $st = $pdo->prepare('SELECT code, name_ar FROM inv_item_category WHERE id = ? LIMIT 1');
    $st->execute([$categoryId]);
    $catRow = $st->fetch(PDO::FETCH_ASSOC);
    if (!$catRow) {
        return [
            'can_delete' => false,
            'stock_moves' => 0,
            'items' => 0,
            'message' => 'الفئة غير موجودة.',
        ];
    }
    $catLabel = trim((string) ($catRow['name_ar'] ?? ''));
    if ($catLabel === '') {
        $catLabel = (string) ($catRow['code'] ?? $categoryId);
    }

    $st = $pdo->prepare('SELECT COUNT(*) FROM inv_item WHERE category_id = ?');
    $st->execute([$categoryId]);
    $items = (int) $st->fetchColumn();

    $stockMoves = 0;
    require_once app_path('includes/inv_stock.php');
    inv_stock_move_ensure_table($pdo);
    if (inv_stock_move_has_table($pdo)) {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM inv_stock_move m
             INNER JOIN inv_item i ON i.id = m.item_id
             WHERE i.category_id = ?'
        );
        $st->execute([$categoryId]);
        $stockMoves = (int) $st->fetchColumn();
    }

    if ($stockMoves > 0) {
        return [
            'can_delete' => false,
            'stock_moves' => $stockMoves,
            'items' => $items,
            'message' => 'لا يمكن حذف الفئة «' . $catLabel . '»: توجد '
                . $stockMoves
                . ' حركة مخزنية على مواد تابعة لها. احذف الحركات أولاً ثم أعد المحاولة.',
        ];
    }

    return [
        'can_delete' => true,
        'stock_moves' => 0,
        'items' => $items,
        'message' => '',
    ];
}

/** @return list<array{id:int,name_ar:string}> */
function inv_item_load_units(PDO $pdo): array
{
    if (!inv_item_has_unit_table($pdo)) {
        return [];
    }

    return $pdo->query('SELECT id, name_ar FROM inv_unit WHERE is_active = 1 ORDER BY name_ar')->fetchAll() ?: [];
}

/** @return list<array{id:int,name_ar:string}> */
function inv_item_load_warehouses(PDO $pdo): array
{
    try {
        return $pdo->query('SELECT id, name_ar FROM inv_warehouse WHERE is_active = 1 ORDER BY name_ar')->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function inv_item_has_expiry_columns(PDO $pdo, bool $refresh = false): bool
{
    static $ok = null;
    if ($refresh) {
        $ok = null;
    }
    if ($ok !== null) {
        return $ok;
    }
    try {
        $pdo->query('SELECT expiry_date, notify_on_expiry FROM inv_item LIMIT 1');
        $ok = true;
    } catch (Throwable $e) {
        $ok = false;
    }

    return $ok;
}

function inv_item_ensure_expiry_schema(PDO $pdo): bool
{
    if (!inv_item_has_expiry_columns($pdo)) {
        try {
            $pdo->exec('ALTER TABLE inv_item ADD COLUMN expiry_date DATE NULL AFTER track_inventory');
        } catch (Throwable $e) {
            // ignore duplicate
        }
        try {
            $pdo->exec('ALTER TABLE inv_item ADD COLUMN notify_on_expiry TINYINT(1) NOT NULL DEFAULT 0 AFTER expiry_date');
        } catch (Throwable $e) {
            // ignore duplicate
        }
    }

    return inv_item_has_expiry_columns($pdo, true);
}

/** @return array{0: ?string, 1: int} تاريخ Y-m-d أو null، وعلم الإشعار */
function inv_item_parse_expiry_input(array $post): array
{
    $raw = trim((string) ($post['expiry_date'] ?? ''));
    $notify = isset($post['notify_on_expiry']) ? 1 : 0;

    if ($raw === '') {
        if ($notify) {
            throw new RuntimeException('حدد تاريخ انتهاء المادة لتفعيل الإشعار.');
        }

        return [null, 0];
    }

    $dt = DateTime::createFromFormat('Y-m-d', $raw);
    if (!$dt || $dt->format('Y-m-d') !== $raw) {
        throw new RuntimeException('تاريخ انتهاء المادة غير صالح.');
    }

    return [$raw, $notify];
}

function inv_item_format_expiry_for_input(?string $value): string
{
    if ($value === null || $value === '') {
        return '';
    }

    return substr($value, 0, 10);
}

/**
 * التحقق قبل حذف مادة (يُمنع إن وُجدت حركات مخزنية أو بنود فواتير مرتبطة).
 *
 * @return array{can_delete:bool, stock_moves:int, doc_lines:int, message:string}
 */
function inv_item_delete_check(PDO $pdo, int $itemId): array
{
    if ($itemId < 1) {
        return [
            'can_delete' => false,
            'stock_moves' => 0,
            'doc_lines' => 0,
            'message' => 'معرّف المادة غير صالح.',
        ];
    }

    $st = $pdo->prepare('SELECT sku, name_ar FROM inv_item WHERE id = ? LIMIT 1');
    $st->execute([$itemId]);
    $itemRow = $st->fetch(PDO::FETCH_ASSOC);
    if (!$itemRow) {
        return [
            'can_delete' => false,
            'stock_moves' => 0,
            'doc_lines' => 0,
            'message' => 'المادة غير موجودة.',
        ];
    }
    $itemLabel = trim((string) ($itemRow['name_ar'] ?? ''));
    if ($itemLabel === '') {
        $itemLabel = (string) ($itemRow['sku'] ?? $itemId);
    }

    $stockMoves = 0;
    require_once app_path('includes/inv_stock.php');
    inv_stock_move_ensure_table($pdo);
    if (inv_stock_move_has_table($pdo)) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM inv_stock_move WHERE item_id = ?');
        $st->execute([$itemId]);
        $stockMoves = (int) $st->fetchColumn();
    }

    $docLines = 0;
    foreach (['sal_invoice_line', 'pur_invoice_line', 'sal_return_line', 'pur_return_line'] as $table) {
        try {
            $st = $pdo->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE item_id = ?');
            $st->execute([$itemId]);
            $docLines += (int) $st->fetchColumn();
        } catch (Throwable $e) {
            // جدول غير موجود بعد
        }
    }

    if ($stockMoves > 0 || $docLines > 0) {
        $parts = [];
        if ($stockMoves > 0) {
            $parts[] = $stockMoves . ' حركة مخزنية';
        }
        if ($docLines > 0) {
            $parts[] = $docLines . ' سطر في فواتير/مردودات';
        }

        return [
            'can_delete' => false,
            'stock_moves' => $stockMoves,
            'doc_lines' => $docLines,
            'message' => 'لا يمكن حذف المادة «' . $itemLabel . '»: مرتبطة بـ ' . implode(' و', $parts) . '.',
        ];
    }

    return [
        'can_delete' => true,
        'stock_moves' => 0,
        'doc_lines' => 0,
        'message' => '',
    ];
}

/** @return array<int, int> item_id => doc line count (فواتير ومردودات) */
function inv_item_doc_line_counts(PDO $pdo): array
{
    $out = [];
    foreach (['sal_invoice_line', 'pur_invoice_line', 'sal_return_line', 'pur_return_line'] as $table) {
        try {
            foreach ($pdo->query('SELECT item_id, COUNT(*) AS c FROM ' . $table . ' GROUP BY item_id') as $row) {
                $id = (int) $row['item_id'];
                $out[$id] = ($out[$id] ?? 0) + (int) $row['c'];
            }
        } catch (Throwable $e) {
            // جدول غير موجود
        }
    }

    return $out;
}

/** @return array<int, int> item_id => move_count */
function inv_item_stock_move_counts(PDO $pdo): array
{
    require_once app_path('includes/inv_stock.php');
    inv_stock_move_ensure_table($pdo);
    if (!inv_stock_move_has_table($pdo)) {
        return [];
    }
    $out = [];
    foreach ($pdo->query('SELECT item_id, COUNT(*) AS c FROM inv_stock_move GROUP BY item_id') as $row) {
        $out[(int) $row['item_id']] = (int) $row['c'];
    }

    return $out;
}

function inv_item_unit_name_by_id(PDO $pdo, int $unitId): string
{
    if ($unitId < 1 || !inv_item_has_unit_table($pdo)) {
        return 'قطعة';
    }
    $st = $pdo->prepare('SELECT name_ar FROM inv_unit WHERE id = ? LIMIT 1');
    $st->execute([$unitId]);
    $n = $st->fetchColumn();

    return is_string($n) && $n !== '' ? $n : 'قطعة';
}

/** رمز SKU القياسي من المعرّف الداخلي. */
function inv_item_sku_from_id(int $id): string
{
    return 'SKU-' . str_pad((string) max(1, $id), 5, '0', STR_PAD_LEFT);
}

function inv_item_sku_exists(PDO $pdo, string $sku, int $excludeId = 0): bool
{
    if ($sku === '') {
        return false;
    }
    if ($excludeId > 0) {
        $st = $pdo->prepare('SELECT id FROM inv_item WHERE sku = ? AND id <> ? LIMIT 1');
        $st->execute([$sku, $excludeId]);
    } else {
        $st = $pdo->prepare('SELECT id FROM inv_item WHERE sku = ? LIMIT 1');
        $st->execute([$sku]);
    }

    return (bool) $st->fetchColumn();
}

/** توليد رمز SKU فريد (يُفضّل ربطه بمعرّف السجل بعد الإدراج). */
function inv_item_allocate_sku(PDO $pdo, int $forId = 0): string
{
    if ($forId > 0) {
        $candidate = inv_item_sku_from_id($forId);
        if (!inv_item_sku_exists($pdo, $candidate, $forId)) {
            return $candidate;
        }
    }

    $maxNum = 0;
    try {
        $st = $pdo->query(
            "SELECT MAX(CAST(SUBSTRING(sku, 5) AS UNSIGNED))
             FROM inv_item
             WHERE sku REGEXP '^SKU-[0-9]+$'"
        );
        $maxNum = (int) $st->fetchColumn();
    } catch (Throwable $e) {
        $maxNum = (int) $pdo->query('SELECT IFNULL(MAX(id), 0) FROM inv_item')->fetchColumn();
    }

    for ($n = max($maxNum, $forId) + 1; $n < $maxNum + 50000; $n++) {
        $candidate = inv_item_sku_from_id($n);
        if (!inv_item_sku_exists($pdo, $candidate, $forId)) {
            return $candidate;
        }
    }

    throw new RuntimeException('تعذر توليد رمز SKU فريد.');
}

/** رمز مؤقت فريد للإدراج قبل تعيين SKU النهائي. */
function inv_item_pending_sku(): string
{
    return 'P' . strtoupper(bin2hex(random_bytes(8)));
}

/**
 * @return array{sql:string, params:list<mixed>, extended:bool}
 */
function inv_item_list_search_parts(PDO $pdo, string $search): array
{
    $search = trim($search);
    if ($search === '') {
        return ['sql' => '', 'params' => [], 'extended' => inv_item_has_extended_columns($pdo)];
    }

    $extended = inv_item_has_extended_columns($pdo);
    $barcode = inv_item_has_barcode_column($pdo);
    $like = '%' . $search . '%';
    $parts = ['i.name_ar LIKE ?', 'i.sku LIKE ?'];
    $params = [$like, $like];
    if ($barcode) {
        $parts[] = 'i.barcode LIKE ?';
        $params[] = $like;
    }
    if ($extended) {
        $parts[] = 'IFNULL(c.name_ar, \'\') LIKE ?';
        $params[] = $like;
        $parts[] = 'IFNULL(u.name_ar, i.unit_name) LIKE ?';
        $params[] = $like;
        $parts[] = 'IFNULL(w.name_ar, \'\') LIKE ?';
        $params[] = $like;
    } else {
        $parts[] = 'i.unit_name LIKE ?';
        $params[] = $like;
    }

    return [
        'sql' => ' WHERE (' . implode(' OR ', $parts) . ')',
        'params' => $params,
        'extended' => $extended,
    ];
}

function inv_item_count_list(PDO $pdo, string $search = ''): int
{
    if (!inv_item_has_main_table($pdo, true)) {
        return 0;
    }

    $filter = inv_item_list_search_parts($pdo, $search);
    if ($filter['sql'] === '') {
        return (int) $pdo->query('SELECT COUNT(*) FROM inv_item')->fetchColumn();
    }

    if ($filter['extended']) {
        $sql = 'SELECT COUNT(*) FROM inv_item i
            LEFT JOIN inv_item_category c ON c.id = i.category_id
            LEFT JOIN inv_unit u ON u.id = i.unit_id
            LEFT JOIN inv_warehouse w ON w.id = i.default_warehouse_id' . $filter['sql'];
    } else {
        $sql = 'SELECT COUNT(*) FROM inv_item i' . $filter['sql'];
    }
    $st = $pdo->prepare($sql);
    $st->execute($filter['params']);

    return (int) $st->fetchColumn();
}

/** @return list<array<string, mixed>> */
function inv_item_fetch_list(PDO $pdo, ?int $limit = null, ?int $offset = null, string $search = ''): array
{
    if (!inv_item_has_main_table($pdo, true)) {
        return [];
    }

    $barcode = inv_item_has_barcode_column($pdo);
    $extended = inv_item_has_extended_columns($pdo);
    $expiry = inv_item_has_expiry_columns($pdo);
    $filter = inv_item_list_search_parts($pdo, $search);
    $extended = $filter['extended'] || $extended;
    $pageSql = '';
    if ($limit !== null && $offset !== null) {
        $pageSql = ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);
    }

    if ($extended) {
        $cols = 'i.id, i.sku';
        if ($barcode) {
            $cols .= ', i.barcode';
        }
        $cols .= ', i.name_ar, c.name_ar AS category_name, COALESCE(u.name_ar, i.unit_name) AS unit_name, w.name_ar AS warehouse_name, i.default_cost, i.default_sale, i.is_active';
        if ($expiry) {
            $cols .= ', i.expiry_date, i.notify_on_expiry';
        }
        $sql = 'SELECT ' . $cols . ' FROM inv_item i
            LEFT JOIN inv_item_category c ON c.id = i.category_id
            LEFT JOIN inv_unit u ON u.id = i.unit_id
            LEFT JOIN inv_warehouse w ON w.id = i.default_warehouse_id'
            . $filter['sql']
            . ' ORDER BY i.id ASC' . $pageSql;
        if ($filter['params'] === []) {
            return $pdo->query($sql)->fetchAll() ?: [];
        }
        $st = $pdo->prepare($sql);
        $st->execute($filter['params']);

        return $st->fetchAll() ?: [];
    }

    $cols = inv_item_list_columns($pdo);
    if ($expiry) {
        $cols .= ', expiry_date, notify_on_expiry';
    }

    $sql = 'SELECT ' . $cols . ' FROM inv_item i' . $filter['sql'] . ' ORDER BY i.id ASC' . $pageSql;
    if ($filter['params'] === []) {
        return $pdo->query($sql)->fetchAll() ?: [];
    }
    $st = $pdo->prepare($sql);
    $st->execute($filter['params']);

    return $st->fetchAll() ?: [];
}
