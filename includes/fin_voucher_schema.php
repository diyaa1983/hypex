<?php
declare(strict_types=1);

require_once app_path('includes/fin_voucher.php');

function fin_voucher_has_column(PDO $pdo, string $column): bool
{
    static $cache = [];
    $key = $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    if (!fin_voucher_has_table($pdo)) {
        $cache[$key] = false;

        return false;
    }
    try {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'fin_voucher\' AND COLUMN_NAME = ?'
        );
        $st->execute([$column]);
        $cache[$key] = (int) $st->fetchColumn() > 0;
    } catch (Throwable $e) {
        try {
            $pdo->query('SELECT `' . str_replace('`', '', $column) . '` FROM fin_voucher LIMIT 1');
            $cache[$key] = true;
        } catch (Throwable $e2) {
            $cache[$key] = false;
        }
    }

    return $cache[$key];
}

function fin_voucher_ensure_receipt_columns(PDO $pdo): void
{
    if (!fin_voucher_has_table($pdo)) {
        return;
    }
    $alters = [
        'is_posted' => 'ALTER TABLE fin_voucher ADD COLUMN is_posted TINYINT(1) NOT NULL DEFAULT 0',
        'posted_at' => 'ALTER TABLE fin_voucher ADD COLUMN posted_at DATETIME NULL',
        'pay_method' => "ALTER TABLE fin_voucher ADD COLUMN pay_method ENUM('cash','check') NOT NULL DEFAULT 'cash'",
        'check_amount' => 'ALTER TABLE fin_voucher ADD COLUMN check_amount DECIMAL(18,6) NULL',
        'bank_name' => 'ALTER TABLE fin_voucher ADD COLUMN bank_name VARCHAR(120) NULL',
    ];
    foreach ($alters as $col => $sql) {
        if (!fin_voucher_has_column($pdo, $col)) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $e) {
                // عمود موجود أو صلاحية ناقصة
            }
        }
    }
}

function fin_voucher_ensure_pay_method_bank_enum(PDO $pdo): void
{
    if (!fin_voucher_has_table($pdo) || !fin_voucher_has_column($pdo, 'pay_method')) {
        return;
    }
    try {
        $st = $pdo->query(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fin_voucher' AND COLUMN_NAME = 'pay_method'
             LIMIT 1"
        );
        $type = (string) ($st->fetchColumn() ?: '');
        if ($type !== '' && !str_contains($type, 'bank')) {
            $pdo->exec(
                "ALTER TABLE fin_voucher MODIFY COLUMN pay_method ENUM('cash','check','bank') NOT NULL DEFAULT 'cash'"
            );
        }
    } catch (Throwable $e) {
        // ignore
    }
}

function fin_voucher_ensure_voucher_no_unique_per_type(PDO $pdo): void
{
    if (!fin_voucher_has_table($pdo)) {
        return;
    }
    try {
        $st = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fin_voucher'
               AND INDEX_NAME = 'uq_fin_voucher_type_no'"
        );
        if ((int) $st->fetchColumn() > 0) {
            return;
        }
        $hasOld = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fin_voucher'
               AND INDEX_NAME = 'uq_fin_voucher_no'"
        );
        if ((int) $hasOld->fetchColumn() > 0) {
            $pdo->exec('ALTER TABLE fin_voucher DROP INDEX uq_fin_voucher_no');
        }
        $pdo->exec(
            'ALTER TABLE fin_voucher ADD UNIQUE KEY uq_fin_voucher_type_no (voucher_type, voucher_no)'
        );
    } catch (Throwable $e) {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/052_fin_voucher_no_per_type.sql');
    }
}

function fin_voucher_ensure_payment_party_columns(PDO $pdo): void
{
    if (!fin_voucher_has_table($pdo)) {
        return;
    }
    require_once app_path('includes/sql_migration.php');
    sql_migration_run_file($pdo, 'database/migrations/163_fin_voucher_payment_parties.sql');
}

function fin_voucher_ensure_schema_full(PDO $pdo): bool
{
    if (!fin_voucher_ensure_schema($pdo)) {
        return false;
    }
    fin_voucher_ensure_receipt_columns($pdo);
    fin_voucher_ensure_pay_method_bank_enum($pdo);
    fin_voucher_ensure_voucher_no_unique_per_type($pdo);
    fin_voucher_ensure_payment_party_columns($pdo);
    require_once app_path('includes/sql_migration.php');
    sql_migration_run_file($pdo, 'database/migrations/164_hr_advance_disbursement.sql');
    sql_migration_run_file($pdo, 'database/migrations/165_hr_advance_disbursement_fix.sql');
    sql_migration_run_file($pdo, 'database/migrations/166_hr_salary_disbursement.sql');
    require_once app_path('includes/sql_migration.php');
    sql_migration_run_file($pdo, 'database/migrations/029_fin_voucher_receipt_ext.sql');
    require_once app_path('includes/fin_voucher_checks.php');
    fin_voucher_checks_ensure_table($pdo);

    return fin_voucher_has_table($pdo);
}

function fin_voucher_ensure_cancel_columns(PDO $pdo): void
{
    if (!fin_voucher_has_table($pdo)) {
        return;
    }
    require_once app_path('includes/doc_number_pool.php');
    doc_number_pool_ensure_table($pdo);
    $alters = [
        'is_cancelled' => 'ALTER TABLE fin_voucher ADD COLUMN is_cancelled TINYINT(1) NOT NULL DEFAULT 0',
        'cancelled_at' => 'ALTER TABLE fin_voucher ADD COLUMN cancelled_at DATETIME NULL',
        'cancelled_by' => 'ALTER TABLE fin_voucher ADD COLUMN cancelled_by INT UNSIGNED NULL',
    ];
    foreach ($alters as $col => $sql) {
        if (!fin_voucher_has_column($pdo, $col)) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $e) {
                //
            }
        }
    }
}

function fin_voucher_is_cancelled(PDO $pdo, int $id): bool
{
    if ($id < 1 || !fin_voucher_has_table($pdo)) {
        return false;
    }
    fin_voucher_ensure_cancel_columns($pdo);
    if (!fin_voucher_has_column($pdo, 'is_cancelled')) {
        return false;
    }
    $st = $pdo->prepare('SELECT is_cancelled FROM fin_voucher WHERE id = ? LIMIT 1');
    $st->execute([$id]);

    return (int) $st->fetchColumn() === 1;
}

function fin_voucher_is_posted(PDO $pdo, int $id): bool
{
    if ($id < 1 || !fin_voucher_has_table($pdo)) {
        return false;
    }
    if (fin_voucher_is_cancelled($pdo, $id)) {
        return false;
    }
    if (!fin_voucher_has_column($pdo, 'is_posted')) {
        return crm_ledger_voucher_is_posted($pdo, $id);
    }
    $st = $pdo->prepare('SELECT is_posted FROM fin_voucher WHERE id = ? LIMIT 1');
    $st->execute([$id]);

    return (int) $st->fetchColumn() === 1;
}

function crm_ledger_voucher_is_posted(PDO $pdo, int $voucherId): bool
{
    require_once app_path('includes/crm_customer_ledger.php');
    require_once app_path('includes/crm_supplier_ledger.php');
    if ($voucherId < 1 || !fin_voucher_has_table($pdo)) {
        return false;
    }
    $st = $pdo->prepare('SELECT voucher_type, party_type FROM fin_voucher WHERE id = ? LIMIT 1');
    $st->execute([$voucherId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    $vt = (string) ($row['voucher_type'] ?? '');
    if ($vt === 'payment') {
        $pt = (string) ($row['party_type'] ?? '');
        if (in_array($pt, ['employee', 'account'], true)) {
            if (fin_voucher_has_column($pdo, 'is_posted')) {
                return fin_voucher_is_posted($pdo, $voucherId);
            }
            require_once app_path('includes/acc_gl.php');

            return acc_gl_ref_exists($pdo, 'cash_payment', $voucherId);
        }
        if ($pt === 'supplier') {
            return crm_supplier_ledger_cash_payment_is_posted($pdo, $voucherId);
        }

        return crm_ledger_cash_payment_is_posted($pdo, $voucherId);
    }

    return crm_ledger_cash_receipt_is_posted($pdo, $voucherId);
}
