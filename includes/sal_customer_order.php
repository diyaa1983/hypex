<?php
declare(strict_types=1);

require_once app_path('includes/mobile_auth.php');

function mobile_can_access_customer_order_api(): bool
{
    return user_can('sales_customer_orders') || user_can('m_customer_orders');
}

function sal_customer_order_ensure_schema(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT id FROM sal_customer_order_line LIMIT 1');
    } catch (Throwable $e) {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/235_sal_customer_order.sql');
        try {
            $pdo->query('SELECT id FROM sal_customer_order_line LIMIT 1');
        } catch (Throwable $e2) {
            return false;
        }
    }

    // تأكيد صلاحية الموبايل حتى لو نُفّذت الهجرة سابقاً قبل إضافة مجموعة MOBILE.
    try {
        $pdo->exec(
            "INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
             SELECT g.id, s.id, 1
             FROM sys_group g
             INNER JOIN sys_screen s ON s.code = 'm_customer_orders'
             WHERE g.code IN ('MOBILE', 'ADMINS')"
        );
    } catch (Throwable $e) {
        // ignore
    }

    return true;
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
    $lines = $pdo->prepare('SELECT * FROM sal_customer_order_line WHERE order_id=? ORDER BY line_no,id');
    $lines->execute([$id]); $order['lines'] = $lines->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $order['status_label'] = sal_customer_order_status_label((string) $order['status']);
    return $order;
}

/** @return list<array<string,mixed>> */
function sal_customer_order_list_fetch(PDO $pdo, string $search = '', ?int $salesRepId = null, ?string $status = null): array
{
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
            WHERE 1=1';
    $params = [];
    if ($salesRepId !== null) {
        $sql .= ' AND o.sales_rep_id = ?';
        $params[] = $salesRepId;
    }
    if ($status !== null && in_array($status, ['draft', 'approved'], true)) {
        $sql .= ' AND o.status = ?';
        $params[] = $status;
    }
    if ($search !== '') {
        $sql .= ' AND (o.order_no LIKE ? OR c.name_ar LIKE ? OR c.code LIKE ? OR r.name_ar LIKE ?)';
        $params = array_merge($params, array_fill(0, 4, '%' . $search . '%'));
    }
    $sql .= ' ORDER BY o.order_date DESC, o.id DESC LIMIT 200';
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @param list<array<string,mixed>> $lines */
function sal_customer_order_save(PDO $pdo, array $data, array $lines, ?int $userId, ?int $forceRepId = null): int
{
    $id = (int) ($data['id'] ?? 0);
    $date = (string) ($data['order_date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new RuntimeException('تاريخ الطلب غير صالح.');
    $customerId = (int) ($data['customer_id'] ?? 0); $warehouseId = (int) ($data['warehouse_id'] ?? 0);
    if ($customerId < 1 || $warehouseId < 1) throw new RuntimeException('العميل والمستودع مطلوبان.');
    $valid = [];
    foreach ($lines as $line) if ((int) ($line['item_id'] ?? 0) > 0 && (float) ($line['qty'] ?? 0) > 0) $valid[] = $line;
    if ($valid === []) throw new RuntimeException('أدخل بنداً واحداً بكمية موجبة على الأقل.');
    $pdo->beginTransaction();
    try {
        if ($id > 0) {
            $st = $pdo->prepare('SELECT status FROM sal_customer_order WHERE id=? FOR UPDATE'); $st->execute([$id]);
            if ($st->fetchColumn() !== 'draft') throw new RuntimeException('لا يمكن تعديل طلب معتمد. فك الاعتماد أولاً.');
            $pdo->prepare('UPDATE sal_customer_order SET order_date=?,customer_id=?,warehouse_id=?,notes=?,updated_by=? WHERE id=?')
                ->execute([$date,$customerId,$warehouseId,trim((string)($data['notes'] ?? '')) ?: null,$userId,$id]);
        } else {
            $no = sal_customer_order_generate_next_no($pdo, $date);
            $rep = $forceRepId ?? ((int)($data['sales_rep_id'] ?? 0) ?: null);
            $pdo->prepare('INSERT INTO sal_customer_order (order_no,order_date,customer_id,sales_rep_id,warehouse_id,notes,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$no,$date,$customerId,$rep,$warehouseId,trim((string)($data['notes'] ?? '')) ?: null,$userId,$userId]);
            $id = (int)$pdo->lastInsertId();
        }
        $pdo->prepare('DELETE FROM sal_customer_order_line WHERE order_id=?')->execute([$id]);
        $ins = $pdo->prepare('INSERT INTO sal_customer_order_line (order_id,line_no,item_id,item_name,unit_id,unit_name,qty,notes) VALUES (?,?,?,?,?,?,?,?)');
        foreach ($valid as $i => $line) {
            $itemId=(int)$line['item_id']; $itemName=trim((string)($line['item_name'] ?? ''));
            if ($itemName === '') { $q=$pdo->prepare('SELECT name_ar FROM inv_item WHERE id=?');$q->execute([$itemId]);$itemName=(string)$q->fetchColumn(); }
            if ($itemName === '') throw new RuntimeException('صنف غير صالح.');
            $ins->execute([$id,$i+1,$itemId,$itemName,(int)($line['unit_id']??0)?:null,trim((string)($line['unit_name']??''))?:null,(float)$line['qty'],trim((string)($line['notes']??''))?:null]);
        }
        $pdo->commit(); return $id;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
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
