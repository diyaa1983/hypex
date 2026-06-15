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

/** @return list<array{id:int,name_ar:string}> */
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
