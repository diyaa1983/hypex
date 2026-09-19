<?php
declare(strict_types=1);

require_once app_path('includes/sal_invoice_schema.php');

function pur_invoice_line_has_tax_columns(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT tax_rate_percent FROM pur_invoice_line LIMIT 1');

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function pur_invoice_has_payment_type(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT payment_type FROM pur_invoice LIMIT 1');

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function pur_invoice_has_supplier_invoice_no(PDO $pdo): bool
{
    return sal_invoice_column_exists($pdo, 'pur_invoice', 'supplier_invoice_no');
}

function pur_invoice_has_invoice_table(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT id FROM pur_invoice LIMIT 1');

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function pur_invoice_has_line_table(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT id FROM pur_invoice_line LIMIT 1');

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** إنشاء جداول فاتورة الشراء إن وُجدت قاعدة ناقصة. */
function pur_invoice_ensure_core_tables(PDO $pdo): void
{
    if (!pur_invoice_has_invoice_table($pdo)) {
        try {
            $pdo->exec(
                'CREATE TABLE pur_invoice (
                  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                  invoice_no VARCHAR(40) NOT NULL,
                  invoice_date DATE NOT NULL,
                  supplier_id INT UNSIGNED NOT NULL,
                  warehouse_id INT UNSIGNED NULL,
                  payment_type ENUM(\'cash\',\'credit\') NOT NULL DEFAULT \'credit\',
                  subtotal DECIMAL(18,6) NOT NULL DEFAULT 0,
                  tax_amount DECIMAL(18,6) NOT NULL DEFAULT 0,
                  total DECIMAL(18,6) NOT NULL DEFAULT 0,
                  status ENUM(\'draft\',\'confirmed\',\'cancelled\') NOT NULL DEFAULT \'draft\',
                  notes VARCHAR(500) NULL,
                  created_by INT UNSIGNED NULL,
                  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  UNIQUE KEY uq_pur_invoice_no (invoice_no),
                  CONSTRAINT fk_pur_sup FOREIGN KEY (supplier_id) REFERENCES crm_supplier(id),
                  CONSTRAINT fk_pur_wh FOREIGN KEY (warehouse_id) REFERENCES inv_warehouse(id) ON DELETE SET NULL,
                  CONSTRAINT fk_pur_user FOREIGN KEY (created_by) REFERENCES sys_user(id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (Throwable $e) {
            //
        }
    }

    if (!pur_invoice_has_invoice_table($pdo)) {
        return;
    }

    if (!pur_invoice_has_line_table($pdo)) {
        try {
            $pdo->exec(
                'CREATE TABLE pur_invoice_line (
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
                  CONSTRAINT fk_pill_inv FOREIGN KEY (invoice_id) REFERENCES pur_invoice(id) ON DELETE CASCADE,
                  CONSTRAINT fk_pill_it FOREIGN KEY (item_id) REFERENCES inv_item(id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (Throwable $e) {
            //
        }
    }
}

/** جداول وأعمدة فاتورة الشراء وذمة المورد. */
function pur_invoice_ensure_schema(PDO $pdo): void
{
    require_once app_path('includes/crm_supplier_ledger.php');
    crm_supplier_ledger_ensure_schema($pdo);

    require_once app_path('includes/inv_item_schema.php');
    inv_warehouse_ensure_table($pdo);
    inv_item_ensure_extended_schema($pdo);

    require_once app_path('includes/inv_stock.php');
    inv_stock_move_ensure_table($pdo);

    pur_invoice_ensure_core_tables($pdo);

    try {
        $pdo->query('SELECT payment_type FROM pur_invoice LIMIT 1');
    } catch (Throwable $e) {
        try {
            $pdo->exec(
                "ALTER TABLE pur_invoice ADD COLUMN payment_type ENUM('cash','credit') NOT NULL DEFAULT 'credit' AFTER warehouse_id"
            );
        } catch (Throwable $e2) {
        }
    }

    if (!pur_invoice_line_has_tax_columns($pdo)) {
        try {
            $pdo->exec(
                'ALTER TABLE pur_invoice_line
                 ADD COLUMN tax_rate_percent DECIMAL(6,3) NOT NULL DEFAULT 0 AFTER discount_pct,
                 ADD COLUMN tax_amount DECIMAL(18,6) NOT NULL DEFAULT 0 AFTER tax_rate_percent,
                 ADD COLUMN line_gross DECIMAL(18,6) NOT NULL DEFAULT 0 AFTER tax_amount'
            );
        } catch (Throwable $e) {
        }
    }

    if (!sal_invoice_column_exists($pdo, 'pur_invoice_line', 'discount_amount')) {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/051_inv_invoice_discount.sql');
    }

    if (!sal_invoice_has_tax_rate_table($pdo)) {
        sal_invoice_ensure_schema($pdo);
    }

    if (!pur_invoice_has_supplier_invoice_no($pdo)) {
        try {
            $pdo->exec(
                'ALTER TABLE pur_invoice ADD COLUMN supplier_invoice_no VARCHAR(80) NULL AFTER invoice_no'
            );
        } catch (Throwable $e) {
        }
    }

    require_once app_path('includes/invoice_amount_decimals.php');
    invoice_amount_decimals_ensure_schema($pdo);

    require_once app_path('includes/inv_invoice_line_qty.php');
    inv_invoice_line_ensure_qty_extra($pdo);
}

function pur_invoice_is_cancelled(?string $status): bool
{
    return $status === 'cancelled';
}

function pur_invoice_id_is_cancelled(PDO $pdo, int $invoiceId): bool
{
    if ($invoiceId < 1) {
        return false;
    }
    try {
        $st = $pdo->prepare('SELECT status FROM pur_invoice WHERE id = ? LIMIT 1');
        $st->execute([$invoiceId]);

        return pur_invoice_is_cancelled((string) ($st->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * رقم فاتورة شراء تسلسلي: 001-2026 (ثلاثة أرقام على الأقل + سنة الفاتورة).
 * يُحتسب أعلى تسلسل سنوي (يشمل الأرقام القديمة ببادئة P إن وُجدت).
 */
function pur_invoice_generate_next_no(PDO $pdo, string $invoiceDate): string
{
    require_once app_path('includes/doc_number_pool.php');

    $year = (int) date('Y', strtotime($invoiceDate));
    $suffix = '-' . $year;

    $pooled = doc_number_pool_take($pdo, doc_number_pool_key_pur_invoice(), $year, 1);
    if ($pooled !== []) {
        return (string) $pooled[0];
    }

    $st = $pdo->prepare('SELECT invoice_no FROM pur_invoice WHERE invoice_no LIKE ? FOR UPDATE');
    $st->execute(['%' . $suffix]);

    $maxSeq = 0;
    $suffixQuoted = preg_quote($suffix, '/');
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $no) {
        $no = (string) $no;
        if (preg_match('/^(?:P)?(\d+)' . $suffixQuoted . '$/', $no, $m)) {
            $maxSeq = max($maxSeq, (int) $m[1]);
        }
    }

    return str_pad((string) ($maxSeq + 1), 3, '0', STR_PAD_LEFT) . $suffix;
}

function pur_invoice_release_no_to_pool(PDO $pdo, string $invoiceNo, string $invoiceDate): void
{
    require_once app_path('includes/doc_number_pool.php');
    doc_number_pool_release($pdo, doc_number_pool_key_pur_invoice(), $invoiceNo, $invoiceDate);
}

function pur_invoice_insert_header(
    PDO $pdo,
    string $invoiceNo,
    string $invoiceDate,
    int $supplierId,
    ?int $warehouseId,
    string $paymentType,
    float $sumSub,
    float $sumTax,
    float $sumGross,
    ?string $notes,
    ?int $uid,
    ?string $supplierInvoiceNo = null
): void {
    $hasPay = pur_invoice_has_payment_type($pdo);
    $hasSupNo = pur_invoice_has_supplier_invoice_no($pdo);
    $supNoVal = $hasSupNo && $supplierInvoiceNo !== null && trim($supplierInvoiceNo) !== ''
        ? trim($supplierInvoiceNo)
        : null;

    if ($hasPay && $hasSupNo) {
        $pdo->prepare(
            'INSERT INTO pur_invoice (invoice_no, supplier_invoice_no, invoice_date, supplier_id, warehouse_id, payment_type, subtotal, tax_amount, total, status, notes, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $invoiceNo, $supNoVal, $invoiceDate, $supplierId, $warehouseId, $paymentType,
            company_round_amount($sumSub, $pdo), company_round_amount($sumTax, $pdo), company_round_amount($sumGross, $pdo), 'confirmed', $notes, $uid,
        ]);

        return;
    }

    if ($hasPay) {
        $pdo->prepare(
            'INSERT INTO pur_invoice (invoice_no, invoice_date, supplier_id, warehouse_id, payment_type, subtotal, tax_amount, total, status, notes, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $invoiceNo, $invoiceDate, $supplierId, $warehouseId, $paymentType,
            company_round_amount($sumSub, $pdo), company_round_amount($sumTax, $pdo), company_round_amount($sumGross, $pdo), 'confirmed', $notes, $uid,
        ]);

        return;
    }

    if ($hasSupNo) {
        $pdo->prepare(
            'INSERT INTO pur_invoice (invoice_no, supplier_invoice_no, invoice_date, supplier_id, warehouse_id, subtotal, tax_amount, total, status, notes, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $invoiceNo, $supNoVal, $invoiceDate, $supplierId, $warehouseId,
            company_round_amount($sumSub, $pdo), company_round_amount($sumTax, $pdo), company_round_amount($sumGross, $pdo), 'confirmed', $notes, $uid,
        ]);

        return;
    }

    $pdo->prepare(
        'INSERT INTO pur_invoice (invoice_no, invoice_date, supplier_id, warehouse_id, subtotal, tax_amount, total, status, notes, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $invoiceNo, $invoiceDate, $supplierId, $warehouseId,
        company_round_amount($sumSub, $pdo), company_round_amount($sumTax, $pdo), company_round_amount($sumGross, $pdo), 'confirmed', $notes, $uid,
    ]);
}

/** @param array<string, mixed> $ln */
function pur_invoice_insert_line(PDO $pdo, int $invoiceId, array $ln, ?int $decimals = null): void
{
    require_once app_path('includes/invoice_amount_decimals.php');
    require_once app_path('includes/inv_item_units.php');
    inv_item_units_ensure_schema($pdo);
    $dp = invoice_amount_decimals_clamp($decimals ?? company_decimal_places($pdo));
    $ln = invoice_normalize_line_array($ln, $dp);

    $hasTax = pur_invoice_line_has_tax_columns($pdo);
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
    $hasUnitCols = inv_item_units_column_exists($pdo, 'pur_invoice_line', 'unit_id');

    if ($hasTax) {
        $tr = (float) ($ln['tax_rate_percent'] ?? 0);
        $taxAmt = (float) ($ln['tax_amount'] ?? 0);
        $gross = (float) ($ln['line_gross'] ?? 0);
        $discPct = (float) ($ln['discount_pct'] ?? 0);
        $discAmt = (float) ($ln['discount_amount'] ?? 0);
        if ($hasUnitCols) {
            $pdo->prepare(
                'INSERT INTO pur_invoice_line (invoice_id, item_id, line_desc, qty, qty_extra, unit_price, discount_pct, discount_amount, line_total, tax_rate_percent, tax_amount, line_gross, unit_id, unit_name, unit_factor, qty_base)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $invoiceId, $itemId, $nameAr !== '' ? $nameAr : null, $qty, $qtyExtra, $up,
                round($discPct, 3), round($discAmt, $dp),
                round($sub, $dp), round($tr, 3), round($taxAmt, $dp), round($gross, $dp),
                $unitId > 0 ? $unitId : null, $unitName !== '' ? $unitName : null, $unitFactor, $qtyBase,
            ]);
        } else {
            $pdo->prepare(
                'INSERT INTO pur_invoice_line (invoice_id, item_id, line_desc, qty, qty_extra, unit_price, discount_pct, discount_amount, line_total, tax_rate_percent, tax_amount, line_gross)
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
    if (sal_invoice_column_exists($pdo, 'pur_invoice_line', 'discount_amount')) {
        if ($hasUnitCols) {
            $pdo->prepare(
                'INSERT INTO pur_invoice_line (invoice_id, item_id, line_desc, qty, qty_extra, unit_price, discount_pct, discount_amount, line_total, unit_id, unit_name, unit_factor, qty_base)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $invoiceId, $itemId, $nameAr !== '' ? $nameAr : null, $qty, $qtyExtra, $up,
                round($discPct, 3), round($discAmt, $dp), round($sub, $dp),
                $unitId > 0 ? $unitId : null, $unitName !== '' ? $unitName : null, $unitFactor, $qtyBase,
            ]);
        } else {
            $pdo->prepare(
                'INSERT INTO pur_invoice_line (invoice_id, item_id, line_desc, qty, qty_extra, unit_price, discount_pct, discount_amount, line_total)
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
            'INSERT INTO pur_invoice_line (invoice_id, item_id, line_desc, qty, qty_extra, unit_price, discount_pct, line_total, unit_id, unit_name, unit_factor, qty_base)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $invoiceId, $itemId, $nameAr !== '' ? $nameAr : null, $qty, $qtyExtra, $up, round($discPct, 3), round($sub, $dp),
            $unitId > 0 ? $unitId : null, $unitName !== '' ? $unitName : null, $unitFactor, $qtyBase,
        ]);

        return;
    }

    $pdo->prepare(
        'INSERT INTO pur_invoice_line (invoice_id, item_id, line_desc, qty, qty_extra, unit_price, discount_pct, line_total)
         VALUES (?,?,?,?,?,?,?,?)'
    )->execute([
        $invoiceId, $itemId, $nameAr !== '' ? $nameAr : null, $qty, $qtyExtra, $up, round($discPct, 3), round($sub, $dp),
    ]);
}

function pur_invoice_set_invoice_discount_input(PDO $pdo, int $invoiceId, ?string $input): void
{
    if ($invoiceId < 1 || !sal_invoice_column_exists($pdo, 'pur_invoice', 'invoice_discount_input')) {
        return;
    }
    $v = trim((string) $input);
    $pdo->prepare('UPDATE pur_invoice SET invoice_discount_input = ? WHERE id = ?')
        ->execute([$v !== '' ? $v : null, $invoiceId]);
}

/** @param list<array<string, mixed>> $lines */
function pur_invoice_replace_lines(PDO $pdo, int $invoiceId, array $lines, ?int $decimals = null): void
{
    $pdo->prepare('DELETE FROM pur_invoice_line WHERE invoice_id = ?')->execute([$invoiceId]);
    foreach ($lines as $ln) {
        pur_invoice_insert_line($pdo, $invoiceId, $ln, $decimals);
    }
}

function pur_invoice_update_header_unposted(
    PDO $pdo,
    int $invoiceId,
    string $invoiceDate,
    int $supplierId,
    ?int $warehouseId,
    string $paymentType,
    float $sumSub,
    float $sumTax,
    float $sumGross,
    ?string $notes,
    ?string $supplierInvoiceNo = null
): void {
    require_once app_path('includes/pur_invoice_post.php');
    if (pur_invoice_is_posted($pdo, $invoiceId)) {
        throw new RuntimeException('لا يمكن تعديل فاتورة مرحّلة.');
    }

    $hasPay = pur_invoice_has_payment_type($pdo);
    $hasSupNo = pur_invoice_has_supplier_invoice_no($pdo);
    $supNoVal = $hasSupNo && $supplierInvoiceNo !== null && trim($supplierInvoiceNo) !== ''
        ? trim($supplierInvoiceNo)
        : null;

    if ($hasPay && $hasSupNo) {
        $pdo->prepare(
            'UPDATE pur_invoice SET invoice_date = ?, supplier_id = ?, warehouse_id = ?,
                    payment_type = ?, supplier_invoice_no = ?, subtotal = ?, tax_amount = ?, total = ?, notes = ?
             WHERE id = ?'
        )->execute([
            $invoiceDate, $supplierId, $warehouseId, $paymentType, $supNoVal,
            company_round_amount($sumSub, $pdo), company_round_amount($sumTax, $pdo), company_round_amount($sumGross, $pdo), $notes, $invoiceId,
        ]);

        return;
    }

    if ($hasPay) {
        $pdo->prepare(
            'UPDATE pur_invoice SET invoice_date = ?, supplier_id = ?, warehouse_id = ?,
                    payment_type = ?, subtotal = ?, tax_amount = ?, total = ?, notes = ?
             WHERE id = ?'
        )->execute([
            $invoiceDate, $supplierId, $warehouseId, $paymentType,
            company_round_amount($sumSub, $pdo), company_round_amount($sumTax, $pdo), company_round_amount($sumGross, $pdo), $notes, $invoiceId,
        ]);

        return;
    }

    if ($hasSupNo) {
        $pdo->prepare(
            'UPDATE pur_invoice SET invoice_date = ?, supplier_id = ?, warehouse_id = ?,
                    supplier_invoice_no = ?, subtotal = ?, tax_amount = ?, total = ?, notes = ?
             WHERE id = ?'
        )->execute([
            $invoiceDate, $supplierId, $warehouseId, $supNoVal,
            company_round_amount($sumSub, $pdo), company_round_amount($sumTax, $pdo), company_round_amount($sumGross, $pdo), $notes, $invoiceId,
        ]);

        return;
    }

    $pdo->prepare(
        'UPDATE pur_invoice SET invoice_date = ?, supplier_id = ?, warehouse_id = ?,
                subtotal = ?, tax_amount = ?, total = ?, notes = ?
         WHERE id = ?'
    )->execute([
        $invoiceDate, $supplierId, $warehouseId,
        company_round_amount($sumSub, $pdo), company_round_amount($sumTax, $pdo), company_round_amount($sumGross, $pdo), $notes, $invoiceId,
    ]);
}
