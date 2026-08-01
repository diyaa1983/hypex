<?php
declare(strict_types=1);

function crm_sales_rep_has_table(PDO $pdo, bool $refresh = false): bool
{
    static $ok = null;
    if (!$refresh && $ok !== null) {
        return $ok;
    }
    try {
        $pdo->query('SELECT id FROM crm_sales_rep LIMIT 1');
        $ok = true;
    } catch (Throwable $e) {
        $ok = false;
    }

    return $ok;
}

function crm_sales_rep_create_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS crm_sales_rep (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code        VARCHAR(40) NOT NULL,
            name_ar     VARCHAR(200) NOT NULL,
            phone       VARCHAR(40) NULL,
            address_ar  VARCHAR(500) NULL,
            is_active   TINYINT(1) NOT NULL DEFAULT 1,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_crm_sales_rep_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function crm_sales_rep_ensure_schema(PDO $pdo): bool
{
    if (crm_sales_rep_has_table($pdo)) {
        return true;
    }

    require_once app_path('includes/sql_migration.php');
    sql_migration_run_file($pdo, 'database/migrations/009_crm_sales_rep.sql');

    if (!crm_sales_rep_has_table($pdo, true)) {
        try {
            crm_sales_rep_create_table($pdo);
        } catch (Throwable $e) {
            // ignore
        }
    }

    return crm_sales_rep_has_table($pdo, true);
}

function crm_sales_rep_customer_has_link(PDO $pdo, bool $refresh = false): bool
{
    static $ok = null;
    if (!$refresh && $ok !== null) {
        return $ok;
    }
    try {
        $pdo->query('SELECT sales_rep_id FROM crm_customer LIMIT 1');
        $ok = true;
    } catch (Throwable $e) {
        $ok = false;
    }

    return $ok;
}

function crm_sales_rep_invoice_has_link(PDO $pdo, bool $refresh = false): bool
{
    static $ok = null;
    if (!$refresh && $ok !== null) {
        return $ok;
    }
    try {
        $pdo->query('SELECT sales_rep_id FROM sal_invoice LIMIT 1');
        $ok = true;
    } catch (Throwable $e) {
        $ok = false;
    }

    return $ok;
}

/** أعمدة sales_rep_id على العميل والفاتورة. */
function crm_sales_rep_ensure_customer_invoice_links(PDO $pdo): void
{
    crm_sales_rep_ensure_schema($pdo);
    if (!crm_sales_rep_has_table($pdo)) {
        return;
    }

    crm_customer_sales_rep_ensure_schema($pdo);

    if (!crm_sales_rep_customer_has_link($pdo) || !crm_sales_rep_invoice_has_link($pdo)) {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/010_sales_rep_links.sql');
    }

    if (!crm_sales_rep_customer_has_link($pdo)) {
        try {
            $pdo->exec(
                'ALTER TABLE crm_customer ADD COLUMN sales_rep_id INT UNSIGNED NULL AFTER address_ar,
                 ADD KEY idx_crm_cust_rep (sales_rep_id)'
            );
            try {
                $pdo->exec(
                    'ALTER TABLE crm_customer ADD CONSTRAINT fk_crm_cust_rep
                     FOREIGN KEY (sales_rep_id) REFERENCES crm_sales_rep(id) ON DELETE SET NULL'
                );
            } catch (Throwable $e) {
            }
        } catch (Throwable $e) {
        }
    }

    if (!crm_sales_rep_invoice_has_link($pdo)) {
        try {
            $pdo->exec(
                'ALTER TABLE sal_invoice ADD COLUMN sales_rep_id INT UNSIGNED NULL AFTER customer_id,
                 ADD KEY idx_sal_inv_rep (sales_rep_id)'
            );
            try {
                $pdo->exec(
                    'ALTER TABLE sal_invoice ADD CONSTRAINT fk_sal_inv_rep
                     FOREIGN KEY (sales_rep_id) REFERENCES crm_sales_rep(id) ON DELETE SET NULL'
                );
            } catch (Throwable $e) {
            }
        } catch (Throwable $e) {
        }
    }

    crm_sales_rep_customer_has_link($pdo, true);
    crm_sales_rep_invoice_has_link($pdo, true);
}

function crm_customer_sales_rep_has_table(PDO $pdo, bool $refresh = false): bool
{
    static $ok = null;
    if (!$refresh && $ok !== null) {
        return $ok;
    }
    try {
        $pdo->query('SELECT customer_id FROM crm_customer_sales_rep LIMIT 1');
        $ok = true;
    } catch (Throwable $e) {
        $ok = false;
    }

    return $ok;
}

function crm_customer_sales_rep_ensure_schema(PDO $pdo): void
{
    if (crm_customer_sales_rep_has_table($pdo)) {
        return;
    }
    require_once app_path('includes/sql_migration.php');
    sql_migration_run_file($pdo, 'database/migrations/151_crm_customer_sales_reps.sql');
    crm_customer_sales_rep_has_table($pdo, true);
}

/** @return list<int> */
function crm_customer_sales_rep_ids_for_customer(PDO $pdo, int $customerId): array
{
    if ($customerId < 1) {
        return [];
    }
    crm_customer_sales_rep_ensure_schema($pdo);
    if (crm_customer_sales_rep_has_table($pdo)) {
        $st = $pdo->prepare(
            'SELECT csr.sales_rep_id
             FROM crm_customer_sales_rep csr
             INNER JOIN crm_sales_rep r ON r.id = csr.sales_rep_id AND r.is_active = 1
             WHERE csr.customer_id = ?
             ORDER BY csr.sort_order, csr.sales_rep_id'
        );
        $st->execute([$customerId]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if ($ids !== []) {
            return $ids;
        }
    }

    $single = crm_sales_rep_id_for_customer($pdo, $customerId);

    return $single !== null ? [$single] : [];
}

/** أسماء مندوبي العميل مفصولة بـ «، » للعرض في الكشوف. */
function crm_customer_sales_rep_names(PDO $pdo, int $customerId): string
{
    $names = [];
    foreach (crm_customer_sales_reps_for_customer($pdo, $customerId) as $rep) {
        $n = trim((string) ($rep['name_ar'] ?? ''));
        if ($n !== '') {
            $names[] = $n;
        }
    }

    return implode('، ', $names);
}

/**
 * @return list<array{id:int,name_ar:string}>
 */
function crm_customer_sales_reps_for_customer(PDO $pdo, int $customerId): array
{
    if ($customerId < 1 || !crm_sales_rep_has_table($pdo)) {
        return [];
    }
    crm_customer_sales_rep_ensure_schema($pdo);
    if (crm_customer_sales_rep_has_table($pdo)) {
        $st = $pdo->prepare(
            'SELECT r.id, r.name_ar
             FROM crm_customer_sales_rep csr
             INNER JOIN crm_sales_rep r ON r.id = csr.sales_rep_id AND r.is_active = 1
             WHERE csr.customer_id = ?
             ORDER BY csr.sort_order, r.name_ar'
        );
        $st->execute([$customerId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows !== []) {
            return array_map(static function (array $r): array {
                return [
                    'id' => (int) $r['id'],
                    'name_ar' => (string) $r['name_ar'],
                ];
            }, $rows);
        }
    }

    $repId = crm_sales_rep_id_for_customer($pdo, $customerId);
    if ($repId === null) {
        return [];
    }
    $st = $pdo->prepare('SELECT id, name_ar FROM crm_sales_rep WHERE id = ? AND is_active = 1 LIMIT 1');
    $st->execute([$repId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ? [['id' => (int) $row['id'], 'name_ar' => (string) $row['name_ar']]] : [];
}

/** @param list<int> $repIds */
function crm_customer_save_sales_reps(PDO $pdo, int $customerId, array $repIds): void
{
    if ($customerId < 1) {
        return;
    }
    crm_customer_sales_rep_ensure_schema($pdo);
    $resolved = [];
    foreach ($repIds as $rawId) {
        $id = crm_sales_rep_resolve_id($pdo, (int) $rawId);
        if ($id !== null && !in_array($id, $resolved, true)) {
            $resolved[] = $id;
        }
    }

    if (crm_customer_sales_rep_has_table($pdo)) {
        $pdo->prepare('DELETE FROM crm_customer_sales_rep WHERE customer_id = ?')->execute([$customerId]);
        if ($resolved !== []) {
            $ins = $pdo->prepare(
                'INSERT INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order) VALUES (?,?,?)'
            );
            foreach ($resolved as $i => $repId) {
                $ins->execute([$customerId, $repId, $i]);
            }
        }
    }

    if (crm_sales_rep_customer_has_link($pdo)) {
        $primary = $resolved[0] ?? null;
        $pdo->prepare('UPDATE crm_customer SET sales_rep_id = ? WHERE id = ?')->execute([$primary, $customerId]);
    }
}

/**
 * @return array{ok:bool,rep_id:?int,error?:string}
 */
function crm_customer_resolve_invoice_sales_rep(PDO $pdo, int $customerId, int $submittedRepId): array
{
    $repIds = crm_customer_sales_rep_ids_for_customer($pdo, $customerId);
    if ($repIds === []) {
        $resolved = crm_sales_rep_resolve_id($pdo, $submittedRepId);

        return ['ok' => true, 'rep_id' => $resolved];
    }
    if (count($repIds) === 1) {
        $resolved = crm_sales_rep_resolve_id($pdo, $submittedRepId);
        if ($resolved === null || !in_array($resolved, $repIds, true)) {
            $resolved = $repIds[0];
        }

        return ['ok' => true, 'rep_id' => $resolved];
    }

    $resolved = crm_sales_rep_resolve_id($pdo, $submittedRepId);
    if ($resolved === null || !in_array($resolved, $repIds, true)) {
        return ['ok' => false, 'rep_id' => null, 'error' => 'اختر مندوب المبيعات لهذا العميل.'];
    }

    return ['ok' => true, 'rep_id' => $resolved];
}

/** @param list<array<string,mixed>> $customers @return list<array<string,mixed>> */
function crm_customers_attach_sales_reps(PDO $pdo, array $customers): array
{
    if ($customers === []) {
        return [];
    }
    crm_customer_sales_rep_ensure_schema($pdo);
    $ids = [];
    foreach ($customers as $c) {
        $id = (int) ($c['id'] ?? 0);
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    $ids = array_values(array_unique($ids));
    $byCustomer = [];
    if ($ids !== [] && crm_customer_sales_rep_has_table($pdo)) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare(
            "SELECT csr.customer_id, r.id, r.name_ar
             FROM crm_customer_sales_rep csr
             INNER JOIN crm_sales_rep r ON r.id = csr.sales_rep_id AND r.is_active = 1
             WHERE csr.customer_id IN ({$ph})
             ORDER BY csr.sort_order, r.name_ar"
        );
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $cid = (int) $row['customer_id'];
            if (!isset($byCustomer[$cid])) {
                $byCustomer[$cid] = [];
            }
            $byCustomer[$cid][] = [
                'id' => (int) $row['id'],
                'name_ar' => (string) $row['name_ar'],
            ];
        }
    }

    foreach ($customers as &$c) {
        $cid = (int) ($c['id'] ?? 0);
        $reps = $byCustomer[$cid] ?? [];
        if ($reps === [] && !empty($c['sales_rep_id'])) {
            $name = (string) ($c['sales_rep_name'] ?? '');
            $reps = [['id' => (int) $c['sales_rep_id'], 'name_ar' => $name]];
        }
        $c['sales_reps'] = $reps;
        if ($reps !== []) {
            $c['sales_rep_id'] = (int) $reps[0]['id'];
            $c['sales_rep_name'] = (string) $reps[0]['name_ar'];
        }
    }
    unset($c);

    return $customers;
}

function crm_sales_rep_resolve_id(PDO $pdo, int $repId): ?int
{
    if ($repId < 1 || !crm_sales_rep_has_table($pdo)) {
        return null;
    }
    $st = $pdo->prepare('SELECT id FROM crm_sales_rep WHERE id = ? AND is_active = 1 LIMIT 1');
    $st->execute([$repId]);
    $id = $st->fetchColumn();

    return ($id !== false && $id !== null) ? (int) $id : null;
}

function crm_sales_rep_id_for_customer(PDO $pdo, int $customerId): ?int
{
    if ($customerId < 1 || !crm_sales_rep_customer_has_link($pdo)) {
        return null;
    }
    $st = $pdo->prepare('SELECT sales_rep_id FROM crm_customer WHERE id = ? LIMIT 1');
    $st->execute([$customerId]);
    $v = $st->fetchColumn();

    return ($v !== false && $v !== null && (int) $v > 0) ? (int) $v : null;
}

/** @return list<array{id:int,name_ar:string,phone:?string}> */
function crm_sales_rep_load_active(PDO $pdo): array
{
    if (!crm_sales_rep_has_table($pdo)) {
        return [];
    }

    return $pdo->query(
        'SELECT id, name_ar, phone FROM crm_sales_rep WHERE is_active = 1 ORDER BY name_ar'
    )->fetchAll() ?: [];
}

function crm_sales_rep_user_has_link(PDO $pdo, bool $refresh = false): bool
{
    static $ok = null;
    if (!$refresh && $ok !== null) {
        return $ok;
    }
    try {
        $pdo->query('SELECT sales_rep_id FROM sys_user LIMIT 1');
        $ok = true;
    } catch (Throwable $e) {
        $ok = false;
    }

    return $ok;
}

function crm_sales_rep_has_warehouse_link(PDO $pdo, bool $refresh = false): bool
{
    static $ok = null;
    if (!$refresh && $ok !== null) {
        return $ok;
    }
    try {
        $pdo->query('SELECT warehouse_id FROM crm_sales_rep LIMIT 1');
        $ok = true;
    } catch (Throwable $e) {
        $ok = false;
    }

    return $ok;
}

/** أعمدة ربط المستخدم بالمندوب ومستودع العهدة (تطبيق الهاتف). */
function crm_sales_rep_ensure_mobile_custody_schema(PDO $pdo): void
{
    crm_sales_rep_ensure_schema($pdo);
    if (!crm_sales_rep_has_table($pdo)) {
        return;
    }

    if (!crm_sales_rep_user_has_link($pdo) || !crm_sales_rep_has_warehouse_link($pdo)) {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/207_crm_sales_rep_mobile_custody.sql');
    }

    crm_sales_rep_user_has_link($pdo, true);
    crm_sales_rep_has_warehouse_link($pdo, true);
}

function crm_sales_rep_id_for_user(PDO $pdo, int $userId): ?int
{
    if ($userId < 1) {
        return null;
    }
    crm_sales_rep_ensure_mobile_custody_schema($pdo);
    if (!crm_sales_rep_user_has_link($pdo)) {
        return null;
    }
    $st = $pdo->prepare('SELECT sales_rep_id FROM sys_user WHERE id = ? LIMIT 1');
    $st->execute([$userId]);
    $v = $st->fetchColumn();

    return ($v !== false && $v !== null && (int) $v > 0) ? (int) $v : null;
}

/** @return ?array{id:int,code:string,name_ar:string,warehouse_id:?int,is_active:int} */
function crm_sales_rep_row_for_user(PDO $pdo, int $userId): ?array
{
    $repId = crm_sales_rep_id_for_user($pdo, $userId);
    if ($repId === null) {
        return null;
    }
    crm_sales_rep_ensure_mobile_custody_schema($pdo);
    $cols = 'id, code, name_ar, is_active';
    if (crm_sales_rep_has_warehouse_link($pdo)) {
        $cols .= ', warehouse_id';
    }
    $st = $pdo->prepare("SELECT {$cols} FROM crm_sales_rep WHERE id = ? AND is_active = 1 LIMIT 1");
    $st->execute([$repId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function crm_sales_rep_warehouse_id_for_user(PDO $pdo, int $userId): ?int
{
    $rep = crm_sales_rep_row_for_user($pdo, $userId);
    if ($rep === null) {
        return null;
    }
    $whId = (int) ($rep['warehouse_id'] ?? 0);

    return $whId > 0 ? $whId : null;
}

/** إنشاء مستودع عهدة VAN-{code} إن لم يكن موجودًا وربطه بالمندوب. */
function crm_sales_rep_ensure_custody_warehouse(PDO $pdo, int $repId): ?int
{
    if ($repId < 1 || !crm_sales_rep_has_table($pdo)) {
        return null;
    }
    crm_sales_rep_ensure_mobile_custody_schema($pdo);

    $st = $pdo->prepare('SELECT id, code, name_ar, warehouse_id FROM crm_sales_rep WHERE id = ? LIMIT 1');
    $st->execute([$repId]);
    $rep = $st->fetch(PDO::FETCH_ASSOC);
    if (!$rep) {
        return null;
    }

    $existing = (int) ($rep['warehouse_id'] ?? 0);
    if ($existing > 0) {
        return $existing;
    }

    $code = 'VAN-' . preg_replace('/[^A-Za-z0-9\-_]/', '', (string) $rep['code']);
    if ($code === 'VAN-' || strlen($code) > 40) {
        $code = 'VAN-REP-' . str_pad((string) $repId, 4, '0', STR_PAD_LEFT);
    }

    $chk = $pdo->prepare('SELECT id FROM inv_warehouse WHERE UPPER(TRIM(code)) = UPPER(?) LIMIT 1');
    $chk->execute([$code]);
    $whId = $chk->fetchColumn();
    if ($whId === false || $whId === null) {
        $name = 'عهدة — ' . trim((string) $rep['name_ar']);
        if ($name === 'عهدة — ') {
            $name = 'عهدة مندوب ' . $code;
        }
        $ins = $pdo->prepare('INSERT INTO inv_warehouse (code, name_ar, is_active) VALUES (?,?,1)');
        $ins->execute([$code, $name]);
        $whId = (int) $pdo->lastInsertId();
    } else {
        $whId = (int) $whId;
    }

    $pdo->prepare('UPDATE crm_sales_rep SET warehouse_id = ? WHERE id = ?')->execute([$whId, $repId]);

    return $whId > 0 ? $whId : null;
}

/**
 * مندوب المستخدم الحالي على الهاتف (null = لا تقييد بقائمة عملاء).
 * يُقيَّد فقط إن كان للحساب مندوب مربوط.
 */
function crm_mobile_scoped_sales_rep_id(?PDO $pdo = null, ?int $userId = null): ?int
{
    $pdo = $pdo ?? db();
    if ($userId === null) {
        $userId = (int) (current_user()['id'] ?? 0);
    }
    if ($userId < 1) {
        return null;
    }

    return crm_sales_rep_id_for_user($pdo, $userId);
}

function crm_customer_is_linked_to_sales_rep(PDO $pdo, int $customerId, int $salesRepId): bool
{
    if ($customerId < 1 || $salesRepId < 1) {
        return false;
    }
    crm_sales_rep_ensure_customer_invoice_links($pdo);

    if (crm_customer_sales_rep_has_table($pdo)) {
        $st = $pdo->prepare(
            'SELECT 1 FROM crm_customer_sales_rep
             WHERE customer_id = ? AND sales_rep_id = ? LIMIT 1'
        );
        $st->execute([$customerId, $salesRepId]);
        if ($st->fetchColumn()) {
            return true;
        }
    }

    if (crm_sales_rep_customer_has_link($pdo)) {
        $st = $pdo->prepare(
            'SELECT 1 FROM crm_customer WHERE id = ? AND sales_rep_id = ? LIMIT 1'
        );
        $st->execute([$customerId, $salesRepId]);
        return (bool) $st->fetchColumn();
    }

    return false;
}

/**
 * شرط SQL: العميل مربوط بالمندوب (alias جدول العميل مثل c).
 * @return array{0:string,1:list<int>} [sqlFragment, params]
 */
function crm_customer_sql_linked_to_rep(PDO $pdo, string $customerAlias, int $salesRepId): array
{
    $alias = preg_replace('/[^a-zA-Z0-9_]/', '', $customerAlias) ?: 'c';
    crm_sales_rep_ensure_customer_invoice_links($pdo);
    $parts = [];
    $params = [];

    if (crm_customer_sales_rep_has_table($pdo)) {
        $parts[] = "EXISTS (
            SELECT 1 FROM crm_customer_sales_rep csr
            WHERE csr.customer_id = {$alias}.id AND csr.sales_rep_id = ?
        )";
        $params[] = $salesRepId;
    }
    if (crm_sales_rep_customer_has_link($pdo)) {
        $parts[] = "{$alias}.sales_rep_id = ?";
        $params[] = $salesRepId;
    }
    if ($parts === []) {
        return ['0', []];
    }

    return ['(' . implode(' OR ', $parts) . ')', $params];
}

function crm_customer_ensure_gps_columns(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo->query('SELECT latitude, longitude FROM crm_customer LIMIT 1');
    } catch (Throwable $e) {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/238_crm_customer_gps.sql');
    }
}

/**
 * @param array<string, mixed>|null $source
 * @return array{latitude:?float, longitude:?float, gps_accuracy:?float, clear:bool}
 */
function crm_customer_gps_parse_input(?array $source = null): array
{
    $source = $source ?? $_POST;
    $empty = [
        'latitude' => null,
        'longitude' => null,
        'gps_accuracy' => null,
        'clear' => true,
    ];
    if (!empty($source['clear_gps'])) {
        return $empty;
    }
    $latRaw = trim((string) ($source['latitude'] ?? ''));
    $lngRaw = trim((string) ($source['longitude'] ?? ''));
    if ($latRaw === '' || $lngRaw === '') {
        return $empty;
    }
    $lat = (float) $latRaw;
    $lng = (float) $lngRaw;
    if (!is_finite($lat) || !is_finite($lng) || abs($lat) > 90 || abs($lng) > 180) {
        return $empty;
    }
    if (abs($lat) < 1e-9 && abs($lng) < 1e-9) {
        return $empty;
    }
    $accRaw = $source['gps_accuracy'] ?? null;
    $acc = $accRaw !== null && $accRaw !== '' ? (float) $accRaw : null;
    if ($acc !== null && (!is_finite($acc) || $acc < 0)) {
        $acc = null;
    }

    return [
        'latitude' => round($lat, 7),
        'longitude' => round($lng, 7),
        'gps_accuracy' => $acc !== null ? round($acc, 2) : null,
        'clear' => false,
    ];
}

function crm_customer_generate_code(PDO $pdo): string
{
    $maxId = (int) $pdo->query('SELECT IFNULL(MAX(id), 0) FROM crm_customer')->fetchColumn();
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $code = 'C-' . str_pad((string) ($maxId + 1 + $attempt), 5, '0', STR_PAD_LEFT);
        $chk = $pdo->prepare('SELECT id FROM crm_customer WHERE code = ? LIMIT 1');
        $chk->execute([$code]);
        if (!$chk->fetch()) {
            return $code;
        }
    }

    throw new RuntimeException('تعذر توليد رمز العميل.');
}

/**
 * عملاء المندوب المربوط بالمستخدم (لقوائم الهاتف).
 * @return list<array{id:int,code:string,name_ar:string}>
 */
function crm_mobile_customers_for_picker(PDO $pdo, int $limit = 800): array
{
    $limit = max(1, min(2000, $limit));
    $scopedRepId = crm_mobile_scoped_sales_rep_id($pdo);
    $params = [];
    $sql = 'SELECT c.id, c.code, c.name_ar FROM crm_customer c WHERE c.is_active = 1';
    if ($scopedRepId !== null) {
        [$linkSql, $linkParams] = crm_customer_sql_linked_to_rep($pdo, 'c', $scopedRepId);
        $sql .= ' AND ' . $linkSql;
        $params = $linkParams;
    }
    $sql .= ' ORDER BY c.name_ar LIMIT ' . (int) $limit;
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * إضافة عميل من تطبيق الهاتف مربوط بالمندوب الحالي.
 * @return array{ok:bool,message:string,customer?:array{id:int,code:string,name:string}}
 */
/**
 * @param array{latitude?:float|null,longitude?:float|null,gps_accuracy?:float|null}|null $gps
 * @return array{ok:bool,message:string,customer?:array{id:int,code:string,name:string}}
 */
function crm_mobile_customer_create_for_user(
    PDO $pdo,
    int $userId,
    string $nameAr,
    string $phone = '',
    string $addressAr = '',
    ?array $gps = null
): array {
    $nameAr = trim($nameAr);
    $phone = trim($phone);
    $addressAr = trim($addressAr);
    if ($nameAr === '') {
        return ['ok' => false, 'message' => 'اسم العميل مطلوب.'];
    }

    $repId = crm_sales_rep_id_for_user($pdo, $userId);
    if ($repId === null) {
        return ['ok' => false, 'message' => 'حسابك غير مربوط بمندوب مبيعات. راجع مدير النظام.'];
    }

    crm_sales_rep_ensure_customer_invoice_links($pdo);
    crm_customer_ensure_gps_columns($pdo);
    $code = crm_customer_generate_code($pdo);
    $gpsParsed = crm_customer_gps_parse_input($gps ?? []);
    $hasGps = !$gpsParsed['clear'] && $gpsParsed['latitude'] !== null && $gpsParsed['longitude'] !== null;

    if ($hasGps) {
        $st = $pdo->prepare(
            'INSERT INTO crm_customer (code, name_ar, phone, email, tax_number, address_ar, latitude, longitude, gps_accuracy, gps_at, sales_rep_id, is_active)
             VALUES (?,?,?,?,?,?,?,?,?,NOW(),?,1)'
        );
        $st->execute([
            $code,
            $nameAr,
            $phone !== '' ? $phone : null,
            null,
            null,
            $addressAr !== '' ? $addressAr : null,
            $gpsParsed['latitude'],
            $gpsParsed['longitude'],
            $gpsParsed['gps_accuracy'],
            $repId,
        ]);
    } else {
        $st = $pdo->prepare(
            'INSERT INTO crm_customer (code, name_ar, phone, email, tax_number, address_ar, sales_rep_id, is_active)
             VALUES (?,?,?,?,?,?,?,1)'
        );
        $st->execute([
            $code,
            $nameAr,
            $phone !== '' ? $phone : null,
            null,
            null,
            $addressAr !== '' ? $addressAr : null,
            $repId,
        ]);
    }
    $newId = (int) $pdo->lastInsertId();
    crm_customer_save_sales_reps($pdo, $newId, [$repId]);

    return [
        'ok' => true,
        'message' => 'تم إضافة العميل وربطه بمندوبك.',
        'customer' => [
            'id' => $newId,
            'code' => $code,
            'name' => $nameAr,
        ],
    ];
}
