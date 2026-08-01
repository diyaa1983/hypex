<?php
declare(strict_types=1);

function sal_invoice_column_exists(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $pdo->query('SELECT `' . str_replace('`', '', $column) . '` FROM `' . str_replace('`', '', $table) . '` LIMIT 1');
        $cache[$key] = true;
    } catch (Throwable $e) {
        $cache[$key] = false;
    }

    return $cache[$key];
}

function sal_invoice_has_tax_rate_table(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT id FROM sys_tax_rate LIMIT 1');

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function sal_invoice_run_migration_file(PDO $pdo, string $filename): void
{
    require_once app_path('includes/sql_migration.php');
    sql_migration_run_file($pdo, 'database/migrations/' . $filename);
}

/**
 * تَعبئة `sal_invoice.sales_rep_id` للفَواتير القديمة التي ليس لها مندوب،
 * بِناءً على المندوب المُسَجَّل في كرت العميل (`crm_customer.sales_rep_id`).
 *
 * - idempotent: يُمكن تَشغيلها مرارًا بدون آثار جانبية.
 * - مرَّة واحدة لكل request عبر static cache.
 */
function sal_invoice_backfill_sales_rep_from_customer(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        if (!sal_invoice_column_exists($pdo, 'sal_invoice', 'sales_rep_id')) {
            return;
        }
        if (!sal_invoice_column_exists($pdo, 'crm_customer', 'sales_rep_id')) {
            return;
        }
        $pdo->exec(
            'UPDATE sal_invoice i
             INNER JOIN crm_customer c ON c.id = i.customer_id
             SET i.sales_rep_id = c.sales_rep_id
             WHERE i.sales_rep_id IS NULL
               AND c.sales_rep_id IS NOT NULL'
        );
    } catch (Throwable $e) {
        // ignore — لا نُريد كَسر تَحميل الفواتير بسبب فَشل تَحديث ثانوي.
    }
}

function sal_invoice_has_invoice_table(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT id FROM sal_invoice LIMIT 1');

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function sal_invoice_has_line_table(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT id FROM sal_invoice_line LIMIT 1');

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** إنشاء جداول رأس الفاتورة وبنودها إن وُجدت القاعدة بدونهما (تثبيت جزئي). */
function sal_invoice_ensure_core_tables(PDO $pdo): void
{
    if (!sal_invoice_has_invoice_table($pdo)) {
        try {
            $pdo->exec(
                'CREATE TABLE sal_invoice (
                  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                  invoice_no VARCHAR(40) NOT NULL,
                  invoice_date DATE NOT NULL,
                  customer_id INT UNSIGNED NOT NULL,
                  sales_rep_id INT UNSIGNED NULL,
                  warehouse_id INT UNSIGNED NULL,
                  payment_type ENUM(\'cash\',\'credit\') NOT NULL DEFAULT \'cash\',
                  subtotal DECIMAL(18,6) NOT NULL DEFAULT 0,
                  tax_amount DECIMAL(18,6) NOT NULL DEFAULT 0,
                  total DECIMAL(18,6) NOT NULL DEFAULT 0,
                  status ENUM(\'draft\',\'confirmed\',\'cancelled\') NOT NULL DEFAULT \'draft\',
                  notes VARCHAR(500) NULL,
                  created_by INT UNSIGNED NULL,
                  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  UNIQUE KEY uq_sal_invoice_no (invoice_no),
                  CONSTRAINT fk_sal_cust FOREIGN KEY (customer_id) REFERENCES crm_customer(id),
                  CONSTRAINT fk_sal_rep FOREIGN KEY (sales_rep_id) REFERENCES crm_sales_rep(id) ON DELETE SET NULL,
                  CONSTRAINT fk_sal_wh FOREIGN KEY (warehouse_id) REFERENCES inv_warehouse(id) ON DELETE SET NULL,
                  CONSTRAINT fk_sal_user FOREIGN KEY (created_by) REFERENCES sys_user(id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (Throwable $e) {
            // الجداول المرجعية أو الصلاحيات ناقصة — رسالة الحفظ توضح لاحقًا
        }
    }

    if (!sal_invoice_has_invoice_table($pdo)) {
        return;
    }

    if (!sal_invoice_has_line_table($pdo)) {
        try {
            $pdo->exec(
                'CREATE TABLE sal_invoice_line (
                  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                  invoice_id INT UNSIGNED NOT NULL,
                  item_id INT UNSIGNED NOT NULL,
                  line_desc VARCHAR(255) NULL,
                  qty DECIMAL(18,6) NOT NULL,
                  qty_extra DECIMAL(18,6) NOT NULL DEFAULT 0,
                  unit_price DECIMAL(18,6) NOT NULL,
                  discount_pct DECIMAL(6,3) NOT NULL DEFAULT 0,
                  line_total DECIMAL(18,6) NOT NULL,
                  tax_rate_percent DECIMAL(6,3) NOT NULL DEFAULT 0,
                  tax_amount DECIMAL(18,6) NOT NULL DEFAULT 0,
                  line_gross DECIMAL(18,6) NOT NULL DEFAULT 0,
                  CONSTRAINT fk_sill_inv FOREIGN KEY (invoice_id) REFERENCES sal_invoice(id) ON DELETE CASCADE,
                  CONSTRAINT fk_sill_it FOREIGN KEY (item_id) REFERENCES inv_item(id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (Throwable $e) {
            //
        }
    }
}

function sal_invoice_set_posted_by_if_empty(PDO $pdo, int $invoiceId, int $userId): void
{
    if ($invoiceId < 1 || $userId < 1) {
        return;
    }
    if (!sal_invoice_column_exists($pdo, 'sal_invoice', 'posted_by')) {
        return;
    }

    $pdo->prepare(
        'UPDATE sal_invoice SET posted_by = ? WHERE id = ? AND posted_by IS NULL'
    )->execute([$userId, $invoiceId]);
}

/** تجهيز جداول/أعمدة فاتورة البيع (002، 010، مندوب، ضريبة). */
function sal_invoice_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    require_once app_path('includes/crm_sales_rep_schema.php');
    crm_sales_rep_ensure_schema($pdo);

    require_once app_path('includes/inv_item_schema.php');
    inv_warehouse_ensure_table($pdo);
    inv_item_ensure_extended_schema($pdo);

    require_once app_path('includes/inv_stock.php');
    inv_stock_move_ensure_table($pdo);

    sal_invoice_ensure_core_tables($pdo);

    require_once app_path('includes/sal_delivery_invoice_link.php');
    sal_delivery_invoice_link_ensure($pdo);

    crm_sales_rep_ensure_customer_invoice_links($pdo);

    sal_invoice_backfill_sales_rep_from_customer($pdo);

    if (!sal_invoice_has_tax_rate_table($pdo)) {
        sal_invoice_run_migration_file($pdo, '002_sales_invoice_enhancements.sql');
    }

    if (!sal_invoice_column_exists($pdo, 'sal_invoice', 'payment_type')) {
        try {
            $pdo->exec(
                "ALTER TABLE sal_invoice ADD COLUMN payment_type ENUM('cash','credit') NOT NULL DEFAULT 'cash' AFTER warehouse_id"
            );
        } catch (Throwable $e) {
        }
    }

    if (!sal_invoice_column_exists($pdo, 'sal_invoice', 'sales_rep_id')) {
        sal_invoice_run_migration_file($pdo, '010_sales_rep_links.sql');
        if (!sal_invoice_column_exists($pdo, 'sal_invoice', 'sales_rep_id')) {
            try {
                $pdo->exec(
                    'ALTER TABLE sal_invoice ADD COLUMN sales_rep_id INT UNSIGNED NULL AFTER customer_id'
                );
            } catch (Throwable $e) {
            }
        }
    }

    if (!sal_invoice_column_exists($pdo, 'sal_invoice_line', 'tax_rate_percent')) {
        try {
            $pdo->exec(
                'ALTER TABLE sal_invoice_line
                 ADD COLUMN tax_rate_percent DECIMAL(6,3) NOT NULL DEFAULT 0 AFTER discount_pct,
                 ADD COLUMN tax_amount DECIMAL(18,6) NOT NULL DEFAULT 0 AFTER tax_rate_percent,
                 ADD COLUMN line_gross DECIMAL(18,6) NOT NULL DEFAULT 0 AFTER tax_amount'
            );
        } catch (Throwable $e) {
            sal_invoice_run_migration_file($pdo, '002_sales_invoice_enhancements.sql');
        }
    }

    if (!sal_invoice_column_exists($pdo, 'sal_invoice_line', 'discount_amount')) {
        sal_invoice_run_migration_file($pdo, 'database/migrations/051_inv_invoice_discount.sql');
    }

    require_once app_path('includes/inv_invoice_line_qty.php');
    inv_invoice_line_ensure_qty_extra($pdo);

    if (!sal_invoice_has_tax_rate_table($pdo)) {
        try {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS sys_tax_rate (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name_ar VARCHAR(100) NOT NULL,
                    rate_percent DECIMAL(6,3) NOT NULL DEFAULT 0,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    sort_order INT NOT NULL DEFAULT 0,
                    UNIQUE KEY uq_tax_name_ar (name_ar)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $pdo->exec(
                "INSERT IGNORE INTO sys_tax_rate (name_ar, rate_percent, sort_order) VALUES
                ('معفى', 0.000, 0),
                ('ضريبة 5%', 5.000, 5),
                ('ضريبة قياسية 15%', 15.000, 10)"
            );
        } catch (Throwable $e) {
        }
    }

    require_once app_path('includes/crm_customer_ledger.php');
    crm_ledger_ensure_schema($pdo);

    require_once app_path('includes/einvoice_schema.php');
    einvoice_ensure_schema($pdo);

    require_once app_path('includes/invoice_amount_decimals.php');
    invoice_amount_decimals_ensure_schema($pdo);
}

function sal_invoice_is_cancelled(?string $status): bool
{
    return $status === 'cancelled';
}

function sal_invoice_id_is_cancelled(PDO $pdo, int $invoiceId): bool
{
    if ($invoiceId < 1) {
        return false;
    }
    try {
        $st = $pdo->prepare('SELECT status FROM sal_invoice WHERE id = ? LIMIT 1');
        $st->execute([$invoiceId]);

        return sal_invoice_is_cancelled((string) ($st->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * رقم فاتورة تسلسلي: 001-2026 (ثلاثة أرقام على الأقل + سنة الفاتورة).
 * التسلسل يبدأ من 1 كل سنة ويستمر 002، 003 …
 */
function sal_invoice_generate_next_no(PDO $pdo, string $invoiceDate): string
{
    require_once app_path('includes/doc_sequence.php');
    require_once app_path('includes/doc_number_pool.php');

    return doc_seq_generate_next_no(
        $pdo,
        'sal_invoice',
        'invoice_no',
        $invoiceDate,
        '',
        [],
        doc_number_pool_key_sal_invoice()
    );
}

function sal_invoice_release_no_to_pool(PDO $pdo, string $invoiceNo, string $invoiceDate): void
{
    require_once app_path('includes/doc_number_pool.php');
    doc_number_pool_release($pdo, doc_number_pool_key_sal_invoice(), $invoiceNo, $invoiceDate);
}

function sal_invoice_insert_header(
    PDO $pdo,
    string $invoiceNo,
    string $invoiceDate,
    int $customerId,
    ?int $salesRepId,
    ?int $warehouseId,
    string $paymentType,
    float $sumSub,
    float $sumTax,
    float $sumGross,
    ?string $notes,
    ?int $uid
): void {
    $hasRep = sal_invoice_column_exists($pdo, 'sal_invoice', 'sales_rep_id');
    $hasPay = sal_invoice_column_exists($pdo, 'sal_invoice', 'payment_type');

    if ($hasRep && $hasPay) {
        $pdo->prepare(
            'INSERT INTO sal_invoice (invoice_no, invoice_date, customer_id, sales_rep_id, warehouse_id, payment_type, subtotal, tax_amount, total, status, notes, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $invoiceNo, $invoiceDate, $customerId, $salesRepId, $warehouseId, $paymentType,
            round($sumSub, company_decimal_places($pdo)), round($sumTax, company_decimal_places($pdo)), round($sumGross, company_decimal_places($pdo)), 'confirmed', $notes, $uid,
        ]);

        return;
    }

    if ($hasPay) {
        $pdo->prepare(
            'INSERT INTO sal_invoice (invoice_no, invoice_date, customer_id, warehouse_id, payment_type, subtotal, tax_amount, total, status, notes, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $invoiceNo, $invoiceDate, $customerId, $warehouseId, $paymentType,
            round($sumSub, company_decimal_places($pdo)), round($sumTax, company_decimal_places($pdo)), round($sumGross, company_decimal_places($pdo)), 'confirmed', $notes, $uid,
        ]);

        return;
    }

    $pdo->prepare(
        'INSERT INTO sal_invoice (invoice_no, invoice_date, customer_id, warehouse_id, subtotal, tax_amount, total, status, notes, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $invoiceNo, $invoiceDate, $customerId, $warehouseId,
        round($sumSub, company_decimal_places($pdo)), round($sumTax, company_decimal_places($pdo)), round($sumGross, company_decimal_places($pdo)), 'confirmed', $notes, $uid,
    ]);
}

function sal_invoice_insert_line(PDO $pdo, int $invoiceId, array $ln, ?int $decimals = null): void
{
    require_once app_path('includes/invoice_amount_decimals.php');
    require_once app_path('includes/inv_item_units.php');
    inv_item_units_ensure_schema($pdo);
    $dp = invoice_amount_decimals_clamp($decimals ?? company_decimal_places($pdo));
    $ln = invoice_normalize_line_array($ln, $dp);

    $hasTax = sal_invoice_column_exists($pdo, 'sal_invoice_line', 'tax_rate_percent');
    $itemId = (int) $ln['item_id'];
    $nameAr = trim((string) ($ln['name_ar'] ?? ''));
    $qty = (float) $ln['qty'];
    $qtyExtra = (float) ($ln['qty_extra'] ?? 0);
    $up = (float) $ln['unit_price'];
    $sub = (float) ($ln['line_subtotal'] ?? 0);

    $unitId = isset($ln['unit_id']) ? (int) $ln['unit_id'] : 0;
    $unitName = trim((string) ($ln['unit_name'] ?? ''));
    $unitFactor = (float) ($ln['unit_factor'] ?? 0);
    if ($unitFactor <= 0 || $unitId < 1 || $unitName === '') {
        try {
            $resolved = inv_item_unit_resolve($pdo, $itemId, $unitId > 0 ? $unitId : null);
            if ($resolved) {
                $unitId = (int) $resolved['unit_id'];
                $unitName = (string) $resolved['unit_name'];
                $unitFactor = (float) $resolved['unit_factor'];
            }
        } catch (Throwable $e) {
            $unitFactor = $unitFactor > 0 ? $unitFactor : 1.0;
        }
    }
    if ($unitFactor <= 0) {
        $unitFactor = 1.0;
    }
    $qtyBase = array_key_exists('qty_base', $ln) && $ln['qty_base'] !== null && $ln['qty_base'] !== ''
        ? (float) $ln['qty_base']
        : inv_item_unit_to_base_qty($qty, $unitFactor);
    $hasUnitCols = inv_item_units_column_exists($pdo, 'sal_invoice_line', 'unit_id');

    if ($hasTax) {
        $tr = (float) ($ln['tax_rate_percent'] ?? 0);
        $taxAmt = (float) ($ln['tax_amount'] ?? 0);
        $gross = (float) ($ln['line_gross'] ?? 0);
        $discPct = (float) ($ln['discount_pct'] ?? 0);
        $discAmt = (float) ($ln['discount_amount'] ?? 0);
        if ($hasUnitCols) {
            $pdo->prepare(
                'INSERT INTO sal_invoice_line (invoice_id, item_id, line_desc, qty, qty_extra, unit_price, discount_pct, discount_amount, line_total, tax_rate_percent, tax_amount, line_gross, unit_id, unit_name, unit_factor, qty_base)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $invoiceId, $itemId, $nameAr !== '' ? $nameAr : null, $qty, $qtyExtra, $up,
                round($discPct, 3), round($discAmt, $dp),
                round($sub, $dp), round($tr, 3), round($taxAmt, $dp), round($gross, $dp),
                $unitId > 0 ? $unitId : null, $unitName !== '' ? $unitName : null, $unitFactor, $qtyBase,
            ]);
        } else {
            $pdo->prepare(
                'INSERT INTO sal_invoice_line (invoice_id, item_id, line_desc, qty, qty_extra, unit_price, discount_pct, discount_amount, line_total, tax_rate_percent, tax_amount, line_gross)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $invoiceId, $itemId, $nameAr !== '' ? $nameAr : null, $qty, $qtyExtra, $up,
                round($discPct, 3), round($discAmt, $dp),
                round($sub, $dp), round($tr, 3), round($taxAmt, $dp), round($gross, $dp),
            ]);
        }

        return;
    }

    $discPct = (float) ($ln['discount_pct'] ?? 0);
    $discAmt = (float) ($ln['discount_amount'] ?? 0);
    $hasDiscAmt = sal_invoice_column_exists($pdo, 'sal_invoice_line', 'discount_amount');
    if ($hasDiscAmt) {
        if ($hasUnitCols) {
            $pdo->prepare(
                'INSERT INTO sal_invoice_line (invoice_id, item_id, line_desc, qty, qty_extra, unit_price, discount_pct, discount_amount, line_total, unit_id, unit_name, unit_factor, qty_base)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $invoiceId, $itemId, $nameAr !== '' ? $nameAr : null, $qty, $qtyExtra, $up,
                round($discPct, 3), round($discAmt, $dp), round($sub, $dp),
                $unitId > 0 ? $unitId : null, $unitName !== '' ? $unitName : null, $unitFactor, $qtyBase,
            ]);
        } else {
            $pdo->prepare(
                'INSERT INTO sal_invoice_line (invoice_id, item_id, line_desc, qty, qty_extra, unit_price, discount_pct, discount_amount, line_total)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([
                $invoiceId, $itemId, $nameAr !== '' ? $nameAr : null, $qty, $qtyExtra, $up,
                round($discPct, 3), round($discAmt, $dp), round($sub, $dp),
            ]);
        }

        return;
    }

    if ($hasUnitCols) {
        $pdo->prepare(
            'INSERT INTO sal_invoice_line (invoice_id, item_id, line_desc, qty, qty_extra, unit_price, discount_pct, line_total, unit_id, unit_name, unit_factor, qty_base)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $invoiceId, $itemId, $nameAr !== '' ? $nameAr : null, $qty, $qtyExtra, $up, round($discPct, 3), round($sub, $dp),
            $unitId > 0 ? $unitId : null, $unitName !== '' ? $unitName : null, $unitFactor, $qtyBase,
        ]);

        return;
    }

    $pdo->prepare(
        'INSERT INTO sal_invoice_line (invoice_id, item_id, line_desc, qty, qty_extra, unit_price, discount_pct, line_total)
         VALUES (?,?,?,?,?,?,?,?)'
    )->execute([
        $invoiceId, $itemId, $nameAr !== '' ? $nameAr : null, $qty, $qtyExtra, $up, round($discPct, 3), round($sub, $dp),
    ]);
}

function sal_invoice_set_invoice_discount_input(PDO $pdo, int $invoiceId, ?string $input): void
{
    if ($invoiceId < 1 || !sal_invoice_column_exists($pdo, 'sal_invoice', 'invoice_discount_input')) {
        return;
    }
    $v = trim((string) $input);
    $pdo->prepare('UPDATE sal_invoice SET invoice_discount_input = ? WHERE id = ?')
        ->execute([$v !== '' ? $v : null, $invoiceId]);
}

/** @param list<array<string, mixed>> $lines */
function sal_invoice_replace_lines(PDO $pdo, int $invoiceId, array $lines, ?int $decimals = null): void
{
    $pdo->prepare('DELETE FROM sal_invoice_line WHERE invoice_id = ?')->execute([$invoiceId]);
    foreach ($lines as $ln) {
        sal_invoice_insert_line($pdo, $invoiceId, $ln, $decimals);
    }
}

function sal_invoice_update_header_unposted(
    PDO $pdo,
    int $invoiceId,
    string $invoiceDate,
    int $customerId,
    ?int $salesRepId,
    ?int $warehouseId,
    string $paymentType,
    float $sumSub,
    float $sumTax,
    float $sumGross,
    ?string $notes
): void {
    require_once app_path('includes/sal_invoice_post.php');
    if (sal_invoice_is_posted($pdo, $invoiceId)) {
        throw new RuntimeException('لا يمكن تعديل فاتورة مرحّلة.');
    }

    $hasRep = sal_invoice_column_exists($pdo, 'sal_invoice', 'sales_rep_id');
    $hasPay = sal_invoice_column_exists($pdo, 'sal_invoice', 'payment_type');

    if ($hasRep && $hasPay) {
        $pdo->prepare(
            'UPDATE sal_invoice SET invoice_date = ?, customer_id = ?, sales_rep_id = ?, warehouse_id = ?,
                    payment_type = ?, subtotal = ?, tax_amount = ?, total = ?, notes = ?
             WHERE id = ?'
        )->execute([
            $invoiceDate, $customerId, $salesRepId, $warehouseId, $paymentType,
            round($sumSub, company_decimal_places($pdo)), round($sumTax, company_decimal_places($pdo)), round($sumGross, company_decimal_places($pdo)), $notes, $invoiceId,
        ]);

        return;
    }

    if ($hasPay) {
        $pdo->prepare(
            'UPDATE sal_invoice SET invoice_date = ?, customer_id = ?, warehouse_id = ?,
                    payment_type = ?, subtotal = ?, tax_amount = ?, total = ?, notes = ?
             WHERE id = ?'
        )->execute([
            $invoiceDate, $customerId, $warehouseId, $paymentType,
            round($sumSub, company_decimal_places($pdo)), round($sumTax, company_decimal_places($pdo)), round($sumGross, company_decimal_places($pdo)), $notes, $invoiceId,
        ]);

        return;
    }

    $pdo->prepare(
        'UPDATE sal_invoice SET invoice_date = ?, customer_id = ?, warehouse_id = ?,
                subtotal = ?, tax_amount = ?, total = ?, notes = ?
         WHERE id = ?'
    )->execute([
        $invoiceDate, $customerId, $warehouseId,
        round($sumSub, company_decimal_places($pdo)), round($sumTax, company_decimal_places($pdo)), round($sumGross, company_decimal_places($pdo)), $notes, $invoiceId,
    ]);
}
