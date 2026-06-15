<?php
declare(strict_types=1);

function sal_return_has_tables(PDO $pdo, bool $refresh = false): bool
{
    static $ok = null;
    if ($refresh) {
        $ok = null;
    }
    if ($ok !== null) {
        return $ok;
    }
    try {
        $pdo->query('SELECT id FROM sal_return LIMIT 1');
        $pdo->query('SELECT id FROM sal_return_line LIMIT 1');
        $ok = true;
    } catch (Throwable $e) {
        $ok = false;
    }

    return $ok;
}

function sal_return_schema_last_error(): ?string
{
    return $GLOBALS['_sal_return_schema_error'] ?? null;
}

function sal_return_has_header_table(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT id FROM sal_return LIMIT 1');

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function sal_return_has_line_table(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT id FROM sal_return_line LIMIT 1');

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** إنشاء جداول مرتجع المبيعات إن وُجدت قاعدة ناقصة. */
function sal_return_ensure_core_tables(PDO $pdo): void
{
    require_once app_path('includes/sal_invoice_schema.php');
    sal_invoice_ensure_schema($pdo);

    if (!sal_return_has_header_table($pdo)) {
        try {
            $pdo->exec(
                'CREATE TABLE sal_return (
                  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                  return_no VARCHAR(40) NOT NULL,
                  return_date DATE NOT NULL,
                  customer_id INT UNSIGNED NOT NULL,
                  invoice_id INT UNSIGNED NOT NULL,
                  warehouse_id INT UNSIGNED NULL,
                  subtotal DECIMAL(18,6) NOT NULL DEFAULT 0,
                  tax_amount DECIMAL(18,6) NOT NULL DEFAULT 0,
                  total DECIMAL(18,6) NOT NULL DEFAULT 0,
                  status ENUM(\'draft\',\'confirmed\',\'cancelled\') NOT NULL DEFAULT \'confirmed\',
                  notes VARCHAR(500) NULL,
                  created_by INT UNSIGNED NULL,
                  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  UNIQUE KEY uq_sal_return_no (return_no),
                  KEY idx_sal_return_inv (invoice_id),
                  CONSTRAINT fk_sret_cust FOREIGN KEY (customer_id) REFERENCES crm_customer(id),
                  CONSTRAINT fk_sret_inv FOREIGN KEY (invoice_id) REFERENCES sal_invoice(id),
                  CONSTRAINT fk_sret_wh FOREIGN KEY (warehouse_id) REFERENCES inv_warehouse(id) ON DELETE SET NULL,
                  CONSTRAINT fk_sret_user FOREIGN KEY (created_by) REFERENCES sys_user(id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (Throwable $e) {
            //
        }
    }

    if (!sal_return_has_header_table($pdo)) {
        return;
    }

    if (!sal_return_has_line_table($pdo)) {
        try {
            $pdo->exec(
                'CREATE TABLE sal_return_line (
                  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                  return_id INT UNSIGNED NOT NULL,
                  invoice_line_id INT UNSIGNED NOT NULL,
                  item_id INT UNSIGNED NOT NULL,
                  qty DECIMAL(18,6) NOT NULL,
                  unit_price DECIMAL(18,6) NOT NULL,
                  tax_rate_percent DECIMAL(6,3) NOT NULL DEFAULT 0,
                  line_subtotal DECIMAL(18,6) NOT NULL DEFAULT 0,
                  tax_amount DECIMAL(18,6) NOT NULL DEFAULT 0,
                  line_gross DECIMAL(18,6) NOT NULL DEFAULT 0,
                  CONSTRAINT fk_srll_ret FOREIGN KEY (return_id) REFERENCES sal_return(id) ON DELETE CASCADE,
                  CONSTRAINT fk_srll_il FOREIGN KEY (invoice_line_id) REFERENCES sal_invoice_line(id),
                  CONSTRAINT fk_srll_it FOREIGN KEY (item_id) REFERENCES inv_item(id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (Throwable $e) {
            //
        }
    }

    sal_return_has_tables($pdo, true);
}

function sal_return_ensure_schema(PDO $pdo): bool
{
    sal_return_ensure_core_tables($pdo);

    if (sal_return_has_tables($pdo, true)) {
        $GLOBALS['_sal_return_schema_error'] = null;
        sal_return_apply_prefix_migration($pdo);

        return true;
    }

    require_once app_path('includes/sql_migration.php');
    $err = sql_migration_run_file($pdo, 'database/migrations/007_sal_return.sql');
    $GLOBALS['_sal_return_schema_error'] = $err;

    if (sal_return_has_tables($pdo, true)) {
        $GLOBALS['_sal_return_schema_error'] = null;
        sal_return_apply_prefix_migration($pdo);

        return true;
    }

    return false;
}

/**
 * تَطبيق بادئة SR على أرقام مرتجعات المبيعات القديمة (idempotent).
 * نَستخدم static cache لتَجنُّب التَكرار في نَفس الطَلب.
 */
function sal_return_apply_prefix_migration(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/066_returns_renumber_prefix.sql');
    } catch (Throwable $e) {
        // ignore — لا نُريد كَسر تَحميل المرتجعات بسبب فَشل migration ثانوي.
    }
}

/**
 * رقم مرتجع تسلسلي لمبيعات: SR001-2026 (بادئة SR + 3 أرقام + سنة).
 * نَستخدم بادئة SR (Sales Return) لِتَمييز المُرتَجَع عن فاتورة البيع.
 *
 * عند حِساب أعلى تسلسل سنوي، نَدعم الأرقام القديمة بدون بادئة
 * أو ببادئة SR، لضمان الاستمرار بدون تَكرار.
 */
function sal_return_generate_next_no(PDO $pdo, string $returnDate): string
{
    $year = (int) date('Y', strtotime($returnDate));
    $suffix = '-' . $year;

    $st = $pdo->prepare('SELECT return_no FROM sal_return WHERE return_no LIKE ? FOR UPDATE');
    $st->execute(['%' . $suffix]);

    $maxSeq = 0;
    $suffixQuoted = preg_quote($suffix, '/');
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $no) {
        $no = (string) $no;
        if (preg_match('/^(?:SR)?(\d+)' . $suffixQuoted . '$/', $no, $m)) {
            $maxSeq = max($maxSeq, (int) $m[1]);
        }
    }

    $next = $maxSeq + 1;

    return 'SR' . str_pad((string) $next, 3, '0', STR_PAD_LEFT) . $suffix;
}
