<?php
declare(strict_types=1);

require_once app_path('includes/sal_customer_order.php');

function sal_customer_order_return_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $pdo->query('SELECT id FROM sal_customer_order_return LIMIT 1');
        $done = true;
        return;
    } catch (Throwable $e) {
    }
    try {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/272_customer_visit_orders_returns.sql');
    } catch (Throwable $e) {
    }
    $done = true;
}

function sal_customer_order_return_generate_no(PDO $pdo, string $date): string
{
    $ym = substr($date, 0, 7);
    $prefix = 'COR-' . str_replace('-', '', $ym) . '-';
    $st = $pdo->prepare("SELECT return_no FROM sal_customer_order_return WHERE return_no LIKE ? ORDER BY id DESC LIMIT 1");
    $st->execute([$prefix . '%']);
    $last = (string) ($st->fetchColumn() ?: '');
    $n = 1;
    if (preg_match('/-(\d+)$/', $last, $m)) {
        $n = (int) $m[1] + 1;
    }
    return $prefix . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
}

/**
 * مرتجعات على طلبات معتمدة ومرسلة فقط.
 * @return list<array<string,mixed>>
 */
function sal_customer_order_returnable_orders(PDO $pdo, ?int $repId, ?int $customerId = null): array
{
    sal_customer_order_ensure_schema($pdo);
    $sql = "SELECT o.id, o.order_no, o.order_date, o.total, o.customer_id, o.warehouse_id,
                   c.name_ar AS customer_name, c.code AS customer_code
            FROM sal_customer_order o
            INNER JOIN crm_customer c ON c.id = o.customer_id
            WHERE o.status = 'approved' AND IFNULL(o.is_sent,1) = 1";
    $params = [];
    if ($repId !== null && $repId > 0) {
        $sql .= ' AND o.sales_rep_id = ?';
        $params[] = $repId;
    }
    if ($customerId !== null && $customerId > 0) {
        $sql .= ' AND o.customer_id = ?';
        $params[] = $customerId;
    }
    $sql .= ' ORDER BY o.order_date DESC, o.id DESC LIMIT 200';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return list<array<string,mixed>> */
function sal_customer_order_return_list(
    PDO $pdo,
    ?int $repId = null,
    ?int $customerId = null,
    ?string $from = null,
    ?string $to = null,
    ?string $status = null
): array {
    sal_customer_order_return_ensure_schema($pdo);
    $sql = "SELECT r.id, r.return_no, r.return_date, r.status, r.total, r.order_id,
                   r.customer_id, c.name_ar AS customer_name, o.order_no
            FROM sal_customer_order_return r
            INNER JOIN crm_customer c ON c.id = r.customer_id
            LEFT JOIN sal_customer_order o ON o.id = r.order_id
            WHERE 1=1";
    $params = [];
    if ($repId !== null && $repId > 0) {
        $sql .= ' AND r.sales_rep_id = ?';
        $params[] = $repId;
    }
    if ($customerId !== null && $customerId > 0) {
        $sql .= ' AND r.customer_id = ?';
        $params[] = $customerId;
    }
    if ($from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $sql .= ' AND r.return_date >= ?';
        $params[] = $from;
    }
    if ($to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $sql .= ' AND r.return_date <= ?';
        $params[] = $to;
    }
    if ($status === 'draft' || $status === 'posted') {
        $sql .= ' AND r.status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY r.return_date DESC, r.id DESC LIMIT 300';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function sal_customer_order_return_fetch(PDO $pdo, int $id): ?array
{
    sal_customer_order_return_ensure_schema($pdo);
    $st = $pdo->prepare(
        "SELECT r.*, c.name_ar AS customer_name, c.code AS customer_code, o.order_no,
                w.name_ar AS warehouse_name
         FROM sal_customer_order_return r
         INNER JOIN crm_customer c ON c.id = r.customer_id
         LEFT JOIN sal_customer_order o ON o.id = r.order_id
         LEFT JOIN inv_warehouse w ON w.id = r.warehouse_id
         WHERE r.id = ? LIMIT 1"
    );
    $st->execute([$id]);
    $h = $st->fetch(PDO::FETCH_ASSOC);
    if (!$h) {
        return null;
    }
    $ls = $pdo->prepare('SELECT * FROM sal_customer_order_return_line WHERE return_id=? ORDER BY line_no, id');
    $ls->execute([$id]);
    $h['lines'] = $ls->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return $h;
}

/**
 * إنشاء مرتجع من طلب معتمد — بنسخ بنوده (كميات قابلة للتعديل لاحقاً).
 * @param list<array<string,mixed>> $linesOverride إن فُرِغت تُنسخ كل بنود الطلب
 */
function sal_customer_order_return_save_from_order(
    PDO $pdo,
    int $orderId,
    array $linesOverride,
    ?int $userId,
    ?int $forceRepId = null
): int {
    sal_customer_order_ensure_schema($pdo);
    sal_customer_order_return_ensure_schema($pdo);
    $order = sal_customer_order_fetch($pdo, $orderId);
    if (!$order) {
        throw new RuntimeException('الطلب غير موجود.');
    }
    if ((string) ($order['status'] ?? '') !== 'approved') {
        throw new RuntimeException('يُسمح بالمرتجع فقط بعد اعتماد الطلب وترحيله.');
    }
    if (isset($order['is_sent']) && (int) $order['is_sent'] !== 1) {
        throw new RuntimeException('الطلب غير مُرسل للنظام بعد.');
    }

    $date = date('Y-m-d');
    $no = sal_customer_order_return_generate_no($pdo, $date);
    $customerId = (int) $order['customer_id'];
    $warehouseId = (int) $order['warehouse_id'];
    $repId = $forceRepId ?? ((int) ($order['sales_rep_id'] ?? 0) ?: null);

    $srcLines = is_array($order['lines'] ?? null) ? $order['lines'] : [];
    if ($linesOverride !== []) {
        $srcLines = $linesOverride;
    }
    if ($srcLines === []) {
        throw new RuntimeException('لا بنود للإرجاع.');
    }

    $subtotal = 0.0;
    $tax = 0.0;
    $total = 0.0;
    $norm = [];
    require_once app_path('includes/company_settings.php');
    $companyTax = (float) (company_settings($pdo)['tax_rate_percent'] ?? 0);

    foreach ($srcLines as $i => $ln) {
        $qty = (float) ($ln['qty'] ?? 0);
        if ($qty <= 0) {
            continue;
        }
        $up = (float) ($ln['unit_price'] ?? 0);
        $disc = (float) ($ln['discount_pct'] ?? 0);
        $taxPct = (float) ($ln['tax_rate_percent'] ?? 0);
        if ($taxPct <= 0.000001) {
            $taxPct = $companyTax;
        }
        $lineTotal = $up > 0
            ? $qty * $up * (1 - $disc / 100)
            : (float) ($ln['line_total'] ?? 0);
        $taxAmt = $lineTotal * $taxPct / 100;
        $gross = $lineTotal + $taxAmt;
        $subtotal += $lineTotal;
        $tax += $taxAmt;
        $total += $gross;
        $norm[] = [
            'order_line_id' => (int) ($ln['id'] ?? $ln['order_line_id'] ?? 0) ?: null,
            'item_id' => (int) ($ln['item_id'] ?? 0),
            'item_name' => (string) ($ln['item_name'] ?? $ln['name_ar'] ?? ''),
            'unit_id' => (int) ($ln['unit_id'] ?? 0) ?: null,
            'unit_name' => (string) ($ln['unit_name'] ?? ''),
            'unit_factor' => (float) ($ln['unit_factor'] ?? 1),
            'qty' => $qty,
            'qty_extra' => (float) ($ln['qty_extra'] ?? 0),
            'qty_base' => (float) ($ln['qty_base'] ?? $qty),
            'unit_price' => $up,
            'discount_pct' => $disc,
            'discount_amount' => (float) ($ln['discount_amount'] ?? 0),
            'line_total' => $lineTotal,
            'tax_rate_percent' => $taxPct,
            'tax_amount' => $taxAmt,
            'line_gross' => $gross,
            'notes' => trim((string) ($ln['notes'] ?? '')) ?: null,
        ];
    }
    if ($norm === []) {
        throw new RuntimeException('أدخل كمية موجبة لبند واحد على الأقل.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO sal_customer_order_return
             (return_no,return_date,customer_id,sales_rep_id,warehouse_id,order_id,status,notes,
              subtotal,discount_amount,tax_amount,total,created_by,updated_by)
             VALUES (?,?,?,?,?,?,\'draft\',NULL,?,0,?,?,?,?)'
        )->execute([
            $no, $date, $customerId, $repId, $warehouseId, $orderId,
            $subtotal, $tax, $total, $userId, $userId,
        ]);
        $rid = (int) $pdo->lastInsertId();
        $ins = $pdo->prepare(
            'INSERT INTO sal_customer_order_return_line
             (return_id,line_no,order_line_id,item_id,item_name,unit_id,unit_name,unit_factor,qty,qty_extra,qty_base,
              unit_price,discount_pct,discount_amount,line_total,tax_rate_percent,tax_amount,line_gross,notes)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        foreach ($norm as $i => $ln) {
            $ins->execute([
                $rid, $i + 1, $ln['order_line_id'], $ln['item_id'], $ln['item_name'],
                $ln['unit_id'], $ln['unit_name'], $ln['unit_factor'], $ln['qty'], $ln['qty_extra'], $ln['qty_base'],
                $ln['unit_price'], $ln['discount_pct'], $ln['discount_amount'], $ln['line_total'],
                $ln['tax_rate_percent'], $ln['tax_amount'], $ln['line_gross'], $ln['notes'],
            ]);
        }
        $pdo->commit();
        return $rid;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function sal_customer_order_return_post(PDO $pdo, int $id, ?int $userId): void
{
    sal_customer_order_return_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT status FROM sal_customer_order_return WHERE id=? FOR UPDATE');
    $pdo->beginTransaction();
    try {
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('المرتجع غير موجود.');
        }
        if ((string) $row['status'] === 'posted') {
            throw new RuntimeException('المرتجع مرحّل مسبقاً.');
        }
        $pdo->prepare(
            "UPDATE sal_customer_order_return SET status='posted', posted_at=NOW(), posted_by=?, updated_by=? WHERE id=?"
        )->execute([$userId, $userId, $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** حذف مرتجع مسودة فقط. */
function sal_customer_order_return_delete(PDO $pdo, int $id, ?int $forceRepId = null): void
{
    sal_customer_order_return_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT id, status, sales_rep_id FROM sal_customer_order_return WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('المرتجع غير موجود.');
    }
    if ((string) ($row['status'] ?? '') === 'posted') {
        throw new RuntimeException('لا يمكن حذف مرتجع مرحّل.');
    }
    if ($forceRepId !== null && $forceRepId > 0 && (int) ($row['sales_rep_id'] ?? 0) !== $forceRepId) {
        throw new RuntimeException('لا صلاحية لحذف هذا المرتجع.');
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM sal_customer_order_return_line WHERE return_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM sal_customer_order_return WHERE id=?')->execute([$id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
