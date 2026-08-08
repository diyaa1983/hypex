<?php
declare(strict_types=1);

require_once app_path('includes/mobile_auth.php');

function mobile_can_access_customer_order_api(): bool
{
    return user_can('sales_customer_orders') || user_can('m_customer_orders');
}

function sal_customer_order_has_column(PDO $pdo, string $table, string $column): bool
{
    static $trueCache = [];
    $key = $table . '.' . $column;
    if (isset($trueCache[$key])) {
        return true;
    }
    try {
        $pdo->query('SELECT `' . str_replace('`', '', $column) . '` FROM `' . str_replace('`', '', $table) . '` LIMIT 1');
        $trueCache[$key] = true;

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** أسعار/خصم/ضريبة على مستوى الطلب والبند. */
function sal_customer_order_has_pricing(PDO $pdo): bool
{
    return sal_customer_order_has_column($pdo, 'sal_customer_order_line', 'unit_price')
        && sal_customer_order_has_column($pdo, 'sal_customer_order', 'invoice_discount_input');
}

function sal_customer_order_ensure_pricing_schema(PDO $pdo): void
{
    if (sal_customer_order_has_pricing($pdo)) {
        return;
    }
    try {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/253_sal_customer_order_pricing.sql');
    } catch (Throwable $e) {
        // fallback column-by-column
    }
    $orderCols = [
        'subtotal' => 'ALTER TABLE sal_customer_order ADD COLUMN subtotal DECIMAL(18,6) NOT NULL DEFAULT 0',
        'discount_amount' => 'ALTER TABLE sal_customer_order ADD COLUMN discount_amount DECIMAL(18,6) NOT NULL DEFAULT 0',
        'tax_amount' => 'ALTER TABLE sal_customer_order ADD COLUMN tax_amount DECIMAL(18,6) NOT NULL DEFAULT 0',
        'total' => 'ALTER TABLE sal_customer_order ADD COLUMN total DECIMAL(18,6) NOT NULL DEFAULT 0',
        'invoice_discount_input' => 'ALTER TABLE sal_customer_order ADD COLUMN invoice_discount_input VARCHAR(40) NULL',
    ];
    foreach ($orderCols as $col => $sql) {
        if (!sal_customer_order_has_column($pdo, 'sal_customer_order', $col)) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $e) {
                //
            }
        }
    }
    $lineCols = [
        'qty_extra' => 'ALTER TABLE sal_customer_order_line ADD COLUMN qty_extra DECIMAL(18,6) NOT NULL DEFAULT 0',
        'unit_price' => 'ALTER TABLE sal_customer_order_line ADD COLUMN unit_price DECIMAL(18,10) NOT NULL DEFAULT 0',
        'discount_pct' => 'ALTER TABLE sal_customer_order_line ADD COLUMN discount_pct DECIMAL(6,3) NOT NULL DEFAULT 0',
        'discount_amount' => 'ALTER TABLE sal_customer_order_line ADD COLUMN discount_amount DECIMAL(18,10) NOT NULL DEFAULT 0',
        'line_total' => 'ALTER TABLE sal_customer_order_line ADD COLUMN line_total DECIMAL(18,10) NOT NULL DEFAULT 0',
        'tax_rate_percent' => 'ALTER TABLE sal_customer_order_line ADD COLUMN tax_rate_percent DECIMAL(6,3) NOT NULL DEFAULT 0',
        'tax_amount' => 'ALTER TABLE sal_customer_order_line ADD COLUMN tax_amount DECIMAL(18,10) NOT NULL DEFAULT 0',
        'line_gross' => 'ALTER TABLE sal_customer_order_line ADD COLUMN line_gross DECIMAL(18,10) NOT NULL DEFAULT 0',
    ];
    foreach ($lineCols as $col => $sql) {
        if (!sal_customer_order_has_column($pdo, 'sal_customer_order_line', $col)) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $e) {
                //
            }
        }
    }
    // reset has-column cache after alters
    // (static cache inside has_column still valid once true; failed false entries retry wrong)
}

function sal_customer_order_ensure_schema(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $pdo->query('SELECT id FROM sal_customer_order_line LIMIT 1');
        $ok = true;
    } catch (Throwable $e) {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/235_sal_customer_order.sql');
        try {
            $pdo->query('SELECT id FROM sal_customer_order_line LIMIT 1');
            $ok = true;
        } catch (Throwable $e2) {
            $ok = false;
        }
    }

    if ($ok) {
        sal_customer_order_ensure_pricing_schema($pdo);
        // صلاحية الموبايل مرة واحدة فقط — لا INSERT في كل تنقّل/إشعار
        try {
            require_once app_path('includes/acc_coa_bootstrap.php');
            if (acc_coa_meta_get($pdo, 'sal_customer_order_mobile_perm_v1') !== '1') {
                $pdo->exec(
                    "INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
                     SELECT g.id, s.id, 1
                     FROM sys_group g
                     INNER JOIN sys_screen s ON s.code = 'm_customer_orders'
                     WHERE g.code IN ('MOBILE', 'ADMINS')"
                );
                acc_coa_meta_set($pdo, 'sal_customer_order_mobile_perm_v1', '1');
            }
            if (acc_coa_meta_get($pdo, 'sal_customer_order_approved_screen_v1') !== '1') {
                $pdo->exec(
                    "INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
                     SELECT 'sales_customer_orders_approved', 'الطلبات المعتمدة', 'screen', 238
                     FROM DUAL WHERE NOT EXISTS (
                         SELECT 1 FROM sys_screen WHERE code = 'sales_customer_orders_approved'
                     )"
                );
                $pdo->exec(
                    "INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
                     SELECT gp.group_id, sn.id, gp.allowed
                     FROM sys_group_permission gp
                     INNER JOIN sys_screen so ON so.id = gp.screen_id AND so.code = 'sales_customer_orders_approve'
                     INNER JOIN sys_screen sn ON sn.code = 'sales_customer_orders_approved'
                     WHERE gp.allowed = 1"
                );
                $pdo->exec(
                    "INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
                     SELECT g.id, s.id, 1
                     FROM sys_group g
                     INNER JOIN sys_screen s ON s.code = 'sales_customer_orders_approved'
                     WHERE g.code IN ('ADMINS', 'administrators', 'admin')"
                );
                acc_coa_meta_set($pdo, 'sal_customer_order_approved_screen_v1', '1');
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    return $ok;
}

function sal_customer_order_generate_next_no(PDO $pdo, string $orderDate): string
{
    $year = (int) date('Y', strtotime($orderDate) ?: time());
    require_once app_path('includes/doc_number_pool.php');
    $pooled = doc_number_pool_take($pdo, doc_number_pool_key_sal_customer_order(), $year);
    if ($pooled !== []) return (string) $pooled[0];
    $suffix = '-' . $year;
    $st = $pdo->prepare('SELECT order_no FROM sal_customer_order WHERE order_no LIKE ? FOR UPDATE');
    $st->execute(['%' . $suffix]);
    $max = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $no) {
        if (preg_match('/^CO(\d+)-' . $year . '$/', (string) $no, $m)) $max = max($max, (int) $m[1]);
    }
    return 'CO' . str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT) . $suffix;
}

function sal_customer_order_status_label(string $status): string
{
    return $status === 'approved' ? 'معتمد' : 'مسودة';
}

/** @return array<string,mixed>|null */
function sal_customer_order_fetch(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare(
        'SELECT o.*, c.name_ar customer_name, c.code customer_code, w.name_ar warehouse_name,
                COALESCE(r.name_ar, \'\') sales_rep_name, COALESCE(a.full_name_ar, \'\') approved_by_name
         FROM sal_customer_order o
         INNER JOIN crm_customer c ON c.id=o.customer_id
         INNER JOIN inv_warehouse w ON w.id=o.warehouse_id
         LEFT JOIN crm_sales_rep r ON r.id=o.sales_rep_id
         LEFT JOIN sys_user a ON a.id=o.approved_by WHERE o.id=?'
    );
    $st->execute([$id]); $order = $st->fetch(PDO::FETCH_ASSOC);
    if (!$order) return null;

    $hasBarcode = false;
    try {
        require_once app_path('includes/inv_item_barcode.php');
        $hasBarcode = inv_item_has_barcode_column($pdo);
    } catch (Throwable $e) {
        $hasBarcode = false;
    }
    $itemSku = $hasBarcode
        ? 'COALESCE(NULLIF(TRIM(i.barcode), \'\'), i.sku) AS item_sku, i.sku AS item_code'
        : 'i.sku AS item_sku, i.sku AS item_code';
    $lines = $pdo->prepare(
        "SELECT l.*, {$itemSku}, COALESCE(i.default_sale, 0) AS item_default_sale
         FROM sal_customer_order_line l
         LEFT JOIN inv_item i ON i.id = l.item_id
         WHERE l.order_id = ?
         ORDER BY l.line_no, l.id"
    );
    $lines->execute([$id]);
    $orderLines = $lines->fetchAll(PDO::FETCH_ASSOC) ?: [];

    require_once app_path('includes/company_settings.php');
    require_once app_path('includes/inv_invoice_discount.php');
    $dp = company_decimal_places($pdo);
    $hasPricing = sal_customer_order_has_pricing($pdo);
    foreach ($orderLines as &$ln) {
        $ln['qty'] = (float) ($ln['qty'] ?? 0);
        $ln['qty_extra'] = (float) ($ln['qty_extra'] ?? 0);
        $ln['unit_price'] = (float) ($ln['unit_price'] ?? 0);
        $ln['discount_pct'] = (float) ($ln['discount_pct'] ?? 0);
        $ln['discount_amount'] = (float) ($ln['discount_amount'] ?? 0);
        $ln['line_total'] = (float) ($ln['line_total'] ?? 0);
        $ln['tax_rate_percent'] = (float) ($ln['tax_rate_percent'] ?? 0);
        $ln['tax_amount'] = (float) ($ln['tax_amount'] ?? 0);
        $ln['line_gross'] = (float) ($ln['line_gross'] ?? 0);
        if ($hasPricing) {
            $ln['line_discount_input'] = inv_discount_format_input_for_ui(
                (float) $ln['discount_pct'],
                (float) $ln['discount_amount'],
                $dp
            );
        } else {
            $ln['line_discount_input'] = '';
        }
        $sku = trim((string) ($ln['item_sku'] ?? ''));
        $ln['sku'] = $sku !== '' ? $sku : trim((string) ($ln['item_code'] ?? ''));
        $ln['barcode'] = $ln['sku'];
    }
    unset($ln);

    $order['lines'] = $orderLines;
    $order['status_label'] = sal_customer_order_status_label((string) $order['status']);
    $order['has_pricing'] = $hasPricing;
    if ($hasPricing) {
        $order['invoice_discount_input'] = (string) ($order['invoice_discount_input'] ?? '');
        $order['subtotal'] = (float) ($order['subtotal'] ?? 0);
        $order['discount_amount'] = (float) ($order['discount_amount'] ?? 0);
        $order['tax_amount'] = (float) ($order['tax_amount'] ?? 0);
        $order['total'] = (float) ($order['total'] ?? 0);
    }

    return $order;
}

/**
 * @return array{0:string,1:list<mixed>} SQL WHERE fragment + params (without leading AND)
 */
function sal_customer_order_list_where(
    string $search = '',
    ?int $salesRepId = null,
    ?string $status = null,
    ?int $customerId = null
): array {
    $sql = '1=1';
    $params = [];
    if ($salesRepId !== null && $salesRepId > 0) {
        $sql .= ' AND o.sales_rep_id = ?';
        $params[] = $salesRepId;
    }
    if ($customerId !== null && $customerId > 0) {
        $sql .= ' AND o.customer_id = ?';
        $params[] = $customerId;
    }
    if ($status !== null && in_array($status, ['draft', 'approved'], true)) {
        $sql .= ' AND o.status = ?';
        $params[] = $status;
    }
    if ($search !== '') {
        $sql .= ' AND (o.order_no LIKE ? OR c.name_ar LIKE ? OR c.code LIKE ? OR r.name_ar LIKE ?)';
        $params = array_merge($params, array_fill(0, 4, '%' . $search . '%'));
    }

    return [$sql, $params];
}

function sal_customer_order_list_count(
    PDO $pdo,
    string $search = '',
    ?int $salesRepId = null,
    ?string $status = null,
    ?int $customerId = null
): int {
    [$where, $params] = sal_customer_order_list_where($search, $salesRepId, $status, $customerId);
    $sql = 'SELECT COUNT(*)
            FROM sal_customer_order o
            INNER JOIN crm_customer c ON c.id = o.customer_id
            LEFT JOIN crm_sales_rep r ON r.id = o.sales_rep_id
            WHERE ' . $where;
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return (int) $st->fetchColumn();
}

/** @return list<array<string,mixed>> */
function sal_customer_order_list_fetch(
    PDO $pdo,
    string $search = '',
    ?int $salesRepId = null,
    ?string $status = null,
    ?int $customerId = null,
    ?int $limit = 200,
    int $offset = 0
): array {
    [$where, $params] = sal_customer_order_list_where($search, $salesRepId, $status, $customerId);
    $sql = 'SELECT o.id, o.order_no, o.order_date, o.status, o.customer_id, o.sales_rep_id, o.warehouse_id,
                   c.name_ar AS customer_name, w.name_ar AS warehouse_name,
                   COALESCE(r.name_ar, \'\') AS sales_rep_name,
                   COALESCE(lc.line_count, 0) AS line_count,
                   COALESCE(lc.total_qty, 0) AS total_qty
            FROM sal_customer_order o
            INNER JOIN crm_customer c ON c.id = o.customer_id
            INNER JOIN inv_warehouse w ON w.id = o.warehouse_id
            LEFT JOIN crm_sales_rep r ON r.id = o.sales_rep_id
            LEFT JOIN (
                SELECT order_id, COUNT(*) AS line_count, COALESCE(SUM(qty), 0) AS total_qty
                FROM sal_customer_order_line
                GROUP BY order_id
            ) lc ON lc.order_id = o.id
            WHERE ' . $where . '
            ORDER BY o.order_date DESC, o.id DESC';
    if ($limit !== null) {
        $sql .= ' LIMIT ' . max(1, (int) $limit) . ' OFFSET ' . max(0, $offset);
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function sal_customer_order_delete(PDO $pdo, int $id, ?int $scopedRepId = null): void
{
    if ($id < 1) {
        throw new RuntimeException('معرّف الطلب غير صالح.');
    }
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id, status, sales_rep_id FROM sal_customer_order WHERE id = ? FOR UPDATE');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('الطلب غير موجود.');
        }
        if ((string) ($row['status'] ?? '') !== 'draft') {
            throw new RuntimeException('لا يمكن حذف طلب معتمد. فك الاعتماد أولاً.');
        }
        if ($scopedRepId !== null && (int) ($row['sales_rep_id'] ?? 0) !== $scopedRepId) {
            throw new RuntimeException('لا يمكنك حذف طلب لمندوب آخر.');
        }
        $pdo->prepare('DELETE FROM sal_customer_order_line WHERE order_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM sal_customer_order WHERE id = ?')->execute([$id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** هل يمكن للمستخدم الحالي حفظ/حذف مسودة طلب (مندوب أو اعتماد). */
function sal_customer_order_user_can_edit_drafts(): bool
{
    return user_can('sales_customer_orders_approve')
        || user_can('sales_customer_orders')
        || user_can('m_customer_orders');
}

/** اعتماد طلب شراء عميل — شاشة الاعتماد + صلاحية الإجراء. */
function sal_customer_order_user_can_approve(): bool
{
    return user_is_system_admin()
        || (
            user_can('sales_customer_orders_approve')
            && user_can_action('action_approve_customer_order')
        );
}

/** فك اعتماد طلب شراء عميل. */
function sal_customer_order_user_can_unapprove(): bool
{
    return user_is_system_admin()
        || (
            (user_can('sales_customer_orders_approve') || user_can('sales_customer_orders_approved'))
            && user_can_action('action_unapprove_customer_order')
        );
}

/**
 * حذف طلب من شاشة الاعتماد/الإدارة.
 * حذف المندوب لطلباته من الموبايل يبقى عبر صلاحية شاشة الموبايل دون هذا الإجراء.
 */
function sal_customer_order_user_can_delete_managed(): bool
{
    return user_is_system_admin()
        || (
            user_can('sales_customer_orders_approve')
            && user_can_action('action_delete_customer_order')
        );
}

/**
 * تطبيع بنود الطلب مع الأسعار/الخصم/الضريبة.
 *
 * @param list<array<string,mixed>> $lines
 * @return array{lines:list<array<string,mixed>>,subtotal:float,discount_amount:float,tax_amount:float,total:float,invoice_discount_input:?string}
 */
function sal_customer_order_normalize_priced_lines(PDO $pdo, array $lines, ?string $headerDiscountInput): array
{
    require_once app_path('includes/company_settings.php');
    require_once app_path('includes/inv_invoice_discount.php');
    require_once app_path('includes/inv_item_units.php');
    inv_item_units_ensure_schema($pdo);

    $dp = company_decimal_places($pdo);
    $settings = company_settings($pdo);
    $defaultTax = (float) ($settings['tax_rate_percent'] ?? 15);

    $built = [];
    foreach ($lines as $line) {
        $itemId = (int) ($line['item_id'] ?? 0);
        $qty = (float) (int) round((float) ($line['qty'] ?? 0));
        if ($itemId < 1 || $qty < 1) {
            continue;
        }
        $itemName = trim((string) ($line['item_name'] ?? ''));
        $defaultSale = 0.0;
        if ($itemName === '' || !isset($line['unit_price'])) {
            $q = $pdo->prepare('SELECT name_ar, default_sale FROM inv_item WHERE id = ?');
            $q->execute([$itemId]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new RuntimeException('صنف غير صالح.');
            }
            if ($itemName === '') {
                $itemName = (string) ($row['name_ar'] ?? '');
            }
            $defaultSale = (float) ($row['default_sale'] ?? 0);
        } else {
            $q = $pdo->prepare('SELECT default_sale FROM inv_item WHERE id = ?');
            $q->execute([$itemId]);
            $defaultSale = (float) $q->fetchColumn();
        }
        if ($itemName === '') {
            throw new RuntimeException('صنف غير صالح.');
        }

        $unitId = (int) ($line['unit_id'] ?? 0);
        $resolved = inv_item_unit_resolve($pdo, $itemId, $unitId > 0 ? $unitId : null);
        $unitId = $resolved ? (int) $resolved['unit_id'] : ($unitId ?: null);
        $unitName = $resolved ? (string) $resolved['unit_name'] : (trim((string) ($line['unit_name'] ?? '')) ?: null);
        $factor = $resolved ? (float) $resolved['unit_factor'] : max(0.000001, (float) ($line['unit_factor'] ?? 1));

        $qtyExtra = max(0.0, (float) (int) round((float) ($line['qty_extra'] ?? 0)));
        $unitPrice = company_round_unit_price((float) ($line['unit_price'] ?? 0), $pdo);
        if ($unitPrice <= 0 && $defaultSale > 0) {
            $unitPrice = company_round_unit_price($defaultSale * $factor, $pdo);
        }
        $taxRate = (float) ($line['tax_rate_percent'] ?? $defaultTax);
        if ($taxRate < 0) {
            $taxRate = 0.0;
        }

        $built[] = [
            'item_id' => $itemId,
            'item_name' => $itemName,
            'unit_id' => $unitId,
            'unit_name' => $unitName,
            'unit_factor' => $factor,
            'qty' => $qty,
            'qty_extra' => $qtyExtra,
            'unit_price' => $unitPrice,
            'tax_rate_percent' => $taxRate,
            'line_discount_input' => trim((string) ($line['line_discount_input'] ?? $line['discount_input'] ?? '')),
            'discount_pct' => (float) ($line['discount_pct'] ?? 0),
            'discount_amount' => (float) ($line['discount_amount'] ?? 0),
            'notes' => trim((string) ($line['notes'] ?? '')) ?: null,
        ];
    }
    if ($built === []) {
        throw new RuntimeException('أدخل بنداً واحداً بكمية موجبة على الأقل.');
    }

    $headerRaw = trim((string) ($headerDiscountInput ?? ''));
    if ($headerRaw !== '') {
        $built = inv_invoice_apply_header_discount($built, $headerRaw, $dp);
    }

    $sumSub = 0.0;
    $sumDisc = 0.0;
    $sumTax = 0.0;
    $sumGross = 0.0;
    foreach ($built as &$ln) {
        $base = inv_invoice_line_merchandise_before_tax($ln, $dp);
        $discAmt = inv_discount_amount_for_base(
            $base,
            (string) ($ln['line_discount_input'] ?? ''),
            (float) ($ln['discount_pct'] ?? 0),
            (float) ($ln['discount_amount'] ?? 0),
            $dp
        );
        $stored = inv_discount_storage_from_input(
            (string) ($ln['line_discount_input'] ?? ''),
            $base,
            $discAmt,
            $dp
        );
        // عند خصم الرأس: amount فقط دون نسبة
        if ($headerRaw !== '') {
            $ln['discount_pct'] = 0.0;
            $ln['discount_amount'] = round($discAmt, $dp);
        } else {
            $ln['discount_pct'] = (float) $stored['discount_pct'];
            $ln['discount_amount'] = (float) $stored['discount_amount'];
        }
        $lineTotal = company_round_amount(max(0.0, $base - $discAmt), $pdo, $dp);
        $taxAmt = company_round_amount($lineTotal * ((float) $ln['tax_rate_percent'] / 100.0), $pdo, $dp);
        $lineGross = company_round_amount($lineTotal + $taxAmt, $pdo, $dp);
        $ln['line_total'] = $lineTotal;
        $ln['tax_amount'] = $taxAmt;
        $ln['line_gross'] = $lineGross;
        $ln['qty_base'] = inv_item_unit_to_base_qty((float) $ln['qty'] + (float) $ln['qty_extra'], (float) $ln['unit_factor']);
        $sumSub += $lineTotal;
        $sumDisc += $discAmt;
        $sumTax += $taxAmt;
        $sumGross += $lineGross;
    }
    unset($ln);

    return [
        'lines' => $built,
        'subtotal' => company_round_amount($sumSub, $pdo, $dp),
        'discount_amount' => company_round_amount($sumDisc, $pdo, $dp),
        'tax_amount' => company_round_amount($sumTax, $pdo, $dp),
        'total' => company_round_amount($sumGross, $pdo, $dp),
        'invoice_discount_input' => $headerRaw !== '' ? $headerRaw : null,
    ];
}

/** @param list<array<string,mixed>> $lines */
function sal_customer_order_save(PDO $pdo, array $data, array $lines, ?int $userId, ?int $forceRepId = null): int
{
    $id = (int) ($data['id'] ?? 0);
    $date = (string) ($data['order_date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new RuntimeException('تاريخ الطلب غير صالح.');
    }
    $customerId = (int) ($data['customer_id'] ?? 0);
    $warehouseId = (int) ($data['warehouse_id'] ?? 0);
    if ($customerId < 1 || $warehouseId < 1) {
        throw new RuntimeException('العميل والمستودع مطلوبان.');
    }
    $notes = trim((string) ($data['notes'] ?? '')) ?: null;
    $headerDisc = trim((string) ($data['invoice_discount'] ?? $data['invoice_discount_input'] ?? ''));

    sal_customer_order_ensure_pricing_schema($pdo);
    $hasPricing = sal_customer_order_has_pricing($pdo);

    if ($hasPricing) {
        $norm = sal_customer_order_normalize_priced_lines($pdo, $lines, $headerDisc);
        $valid = $norm['lines'];
    } else {
        $valid = [];
        foreach ($lines as $line) {
            if ((int) ($line['item_id'] ?? 0) > 0 && (float) ($line['qty'] ?? 0) > 0) {
                $valid[] = $line;
            }
        }
        if ($valid === []) {
            throw new RuntimeException('أدخل بنداً واحداً بكمية موجبة على الأقل.');
        }
        $norm = null;
    }

    $pdo->beginTransaction();
    try {
        if ($id > 0) {
            $st = $pdo->prepare('SELECT status FROM sal_customer_order WHERE id=? FOR UPDATE');
            $st->execute([$id]);
            if ($st->fetchColumn() !== 'draft') {
                throw new RuntimeException('لا يمكن تعديل طلب معتمد. فك الاعتماد أولاً.');
            }
            if ($hasPricing) {
                $pdo->prepare(
                    'UPDATE sal_customer_order SET order_date=?,customer_id=?,warehouse_id=?,notes=?,
                     subtotal=?,discount_amount=?,tax_amount=?,total=?,invoice_discount_input=?,updated_by=? WHERE id=?'
                )->execute([
                    $date, $customerId, $warehouseId, $notes,
                    $norm['subtotal'], $norm['discount_amount'], $norm['tax_amount'], $norm['total'],
                    $norm['invoice_discount_input'], $userId, $id,
                ]);
            } else {
                $pdo->prepare('UPDATE sal_customer_order SET order_date=?,customer_id=?,warehouse_id=?,notes=?,updated_by=? WHERE id=?')
                    ->execute([$date, $customerId, $warehouseId, $notes, $userId, $id]);
            }
        } else {
            $no = sal_customer_order_generate_next_no($pdo, $date);
            $rep = $forceRepId ?? ((int) ($data['sales_rep_id'] ?? 0) ?: null);
            if ($hasPricing) {
                $pdo->prepare(
                    'INSERT INTO sal_customer_order
                     (order_no,order_date,customer_id,sales_rep_id,warehouse_id,notes,subtotal,discount_amount,tax_amount,total,invoice_discount_input,created_by,updated_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
                )->execute([
                    $no, $date, $customerId, $rep, $warehouseId, $notes,
                    $norm['subtotal'], $norm['discount_amount'], $norm['tax_amount'], $norm['total'],
                    $norm['invoice_discount_input'], $userId, $userId,
                ]);
            } else {
                $pdo->prepare(
                    'INSERT INTO sal_customer_order (order_no,order_date,customer_id,sales_rep_id,warehouse_id,notes,created_by,updated_by)
                     VALUES (?,?,?,?,?,?,?,?)'
                )->execute([$no, $date, $customerId, $rep, $warehouseId, $notes, $userId, $userId]);
            }
            $id = (int) $pdo->lastInsertId();
        }

        $pdo->prepare('DELETE FROM sal_customer_order_line WHERE order_id=?')->execute([$id]);
        require_once app_path('includes/inv_item_units.php');
        inv_item_units_ensure_schema($pdo);
        $hasFactor = inv_item_units_column_exists($pdo, 'sal_customer_order_line', 'unit_factor');

        if ($hasPricing) {
            $ins = $hasFactor
                ? $pdo->prepare(
                    'INSERT INTO sal_customer_order_line
                     (order_id,line_no,item_id,item_name,unit_id,unit_name,unit_factor,qty,qty_extra,qty_base,
                      unit_price,discount_pct,discount_amount,line_total,tax_rate_percent,tax_amount,line_gross,notes)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                )
                : $pdo->prepare(
                    'INSERT INTO sal_customer_order_line
                     (order_id,line_no,item_id,item_name,unit_id,unit_name,qty,qty_extra,
                      unit_price,discount_pct,discount_amount,line_total,tax_rate_percent,tax_amount,line_gross,notes)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                );
            foreach ($valid as $i => $line) {
                if ($hasFactor) {
                    $ins->execute([
                        $id, $i + 1, $line['item_id'], $line['item_name'],
                        $line['unit_id'], $line['unit_name'], $line['unit_factor'],
                        $line['qty'], $line['qty_extra'], $line['qty_base'],
                        $line['unit_price'], $line['discount_pct'], $line['discount_amount'],
                        $line['line_total'], $line['tax_rate_percent'], $line['tax_amount'], $line['line_gross'],
                        $line['notes'],
                    ]);
                } else {
                    $ins->execute([
                        $id, $i + 1, $line['item_id'], $line['item_name'],
                        $line['unit_id'], $line['unit_name'],
                        $line['qty'], $line['qty_extra'],
                        $line['unit_price'], $line['discount_pct'], $line['discount_amount'],
                        $line['line_total'], $line['tax_rate_percent'], $line['tax_amount'], $line['line_gross'],
                        $line['notes'],
                    ]);
                }
            }
        } else {
            $ins = $hasFactor
                ? $pdo->prepare('INSERT INTO sal_customer_order_line (order_id,line_no,item_id,item_name,unit_id,unit_name,unit_factor,qty,qty_base,notes) VALUES (?,?,?,?,?,?,?,?,?,?)')
                : $pdo->prepare('INSERT INTO sal_customer_order_line (order_id,line_no,item_id,item_name,unit_id,unit_name,qty,notes) VALUES (?,?,?,?,?,?,?,?)');
            foreach ($valid as $i => $line) {
                $itemId = (int) $line['item_id'];
                $itemName = trim((string) ($line['item_name'] ?? ''));
                if ($itemName === '') {
                    $q = $pdo->prepare('SELECT name_ar FROM inv_item WHERE id=?');
                    $q->execute([$itemId]);
                    $itemName = (string) $q->fetchColumn();
                }
                if ($itemName === '') {
                    throw new RuntimeException('صنف غير صالح.');
                }
                $qty = (float) (int) round((float) $line['qty']);
                if ($qty < 1) {
                    throw new RuntimeException('الكمية يجب أن تكون عدداً صحيحاً موجباً.');
                }
                $unitId = (int) ($line['unit_id'] ?? 0);
                $resolved = inv_item_unit_resolve($pdo, $itemId, $unitId > 0 ? $unitId : null);
                $unitId = $resolved ? (int) $resolved['unit_id'] : ($unitId ?: null);
                $unitName = $resolved ? (string) $resolved['unit_name'] : (trim((string) ($line['unit_name'] ?? '')) ?: null);
                $factor = $resolved ? (float) $resolved['unit_factor'] : 1.0;
                $qtyBase = inv_item_unit_to_base_qty($qty, $factor);
                if ($hasFactor) {
                    $ins->execute([$id, $i + 1, $itemId, $itemName, $unitId, $unitName, $factor, $qty, $qtyBase, trim((string) ($line['notes'] ?? '')) ?: null]);
                } else {
                    $ins->execute([$id, $i + 1, $itemId, $itemName, $unitId, $unitName, $qty, trim((string) ($line['notes'] ?? '')) ?: null]);
                }
            }
        }

        $pdo->commit();

        return $id;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function sal_customer_order_set_approved(PDO $pdo, int $id, bool $approved, ?int $userId): void
{
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) {
        $pdo->beginTransaction();
    }
    try {
        $st = $pdo->prepare('SELECT status FROM sal_customer_order WHERE id=? FOR UPDATE');
        $st->execute([$id]);
        $status = $st->fetchColumn();
        if ($status === false) {
            throw new RuntimeException('الطلب غير موجود.');
        }
        if ($approved && $status !== 'draft') {
            throw new RuntimeException('الطلب معتمد بالفعل.');
        }
        if (!$approved && $status !== 'approved') {
            throw new RuntimeException('الطلب ليس معتمداً.');
        }
        if ($approved) {
            $pdo->prepare(
                "UPDATE sal_customer_order SET status='approved',approved_by=?,approved_at=NOW(),updated_by=? WHERE id=?"
            )->execute([$userId, $userId, $id]);
        } else {
            $pdo->prepare(
                "UPDATE sal_customer_order SET status='draft',approved_by=NULL,approved_at=NULL,updated_by=? WHERE id=?"
            )->execute([$userId, $id]);
        }
        if ($ownTx) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** يظهر تنبيه الاعتماد فقط لمن لديه صلاحية شاشة اعتماد طلبات الشراء. */
function sal_customer_order_notifications_user_can_see(): bool
{
    return user_can('sales_customer_orders_approve');
}

function sal_customer_order_pending_approve_count(PDO $pdo): int
{
    if (!sal_customer_order_notifications_user_can_see() || !sal_customer_order_ensure_schema($pdo)) {
        return 0;
    }
    try {
        return (int) $pdo->query(
            "SELECT COUNT(*) FROM sal_customer_order WHERE status = 'draft'"
        )->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * طلبات شراء عملاء بانتظار الاعتماد (مسودة).
 *
 * @return list<array<string,mixed>>
 */
function sal_customer_order_pending_approve_alerts(PDO $pdo, int $limit = 20): array
{
    if (!sal_customer_order_notifications_user_can_see() || !sal_customer_order_ensure_schema($pdo)) {
        return [];
    }
    $limit = max(1, min(50, $limit));
    try {
        $st = $pdo->query(
            "SELECT o.id, o.order_no, o.order_date, c.name_ar AS customer_name,
                    COALESCE(r.name_ar, '') AS sales_rep_name
             FROM sal_customer_order o
             INNER JOIN crm_customer c ON c.id = o.customer_id
             LEFT JOIN crm_sales_rep r ON r.id = o.sales_rep_id
             WHERE o.status = 'draft'
             ORDER BY o.order_date ASC, o.id ASC
             LIMIT {$limit}"
        );
        $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        return [];
    }

    $urlBase = app_url('index.php?r=sales_customer_orders_approve&id=');
    $out = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        $out[] = [
            'id' => $id,
            'order_no' => (string) ($row['order_no'] ?? ''),
            'order_date' => (string) ($row['order_date'] ?? ''),
            'customer_name' => (string) ($row['customer_name'] ?? ''),
            'sales_rep_name' => (string) ($row['sales_rep_name'] ?? ''),
            'url' => $urlBase . $id,
            'urgency' => 'pending',
            'urgency_label' => 'بانتظار الاعتماد',
            'type_label' => 'طلب شراء عميل',
        ];
    }

    return $out;
}
