<?php
declare(strict_types=1);

require_once app_path('includes/sal_delivery_schema.php');
require_once app_path('includes/sal_delivery_load.php');
require_once app_path('includes/sal_invoice_schema.php');

function sal_delivery_invoice_link_ensure(PDO $pdo): void
{
    sal_delivery_ensure_extended_schema($pdo);
    if (!sal_invoice_column_exists($pdo, 'sal_invoice', 'delivery_id')) {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/116_sal_delivery_warehouse_invoice_link.sql');
    }
}

function sal_invoice_delivery_id(PDO $pdo, int $invoiceId): int
{
    if ($invoiceId < 1 || !sal_invoice_column_exists($pdo, 'sal_invoice', 'delivery_id')) {
        return 0;
    }
    $st = $pdo->prepare('SELECT delivery_id FROM sal_invoice WHERE id = ? LIMIT 1');
    $st->execute([$invoiceId]);
    $v = $st->fetchColumn();

    return $v !== false && $v !== null ? (int) $v : 0;
}

function sal_invoice_set_delivery_id(PDO $pdo, int $invoiceId, ?int $deliveryId): void
{
    sal_delivery_invoice_link_ensure($pdo);
    if ($invoiceId < 1) {
        return;
    }
    $pdo->prepare('UPDATE sal_invoice SET delivery_id = ? WHERE id = ?')->execute([
        $deliveryId !== null && $deliveryId > 0 ? $deliveryId : null,
        $invoiceId,
    ]);
}

/**
 * أول فاتورة مرتبطة بالسند (للعرض في الرسائل).
 *
 * @return array{id:int, invoice_no:string, is_posted:bool}|null
 */
function sal_delivery_first_linked_invoice(PDO $pdo, int $deliveryId): ?array
{
    if ($deliveryId < 1 || !sal_invoice_column_exists($pdo, 'sal_invoice', 'delivery_id')) {
        return null;
    }
    $st = $pdo->prepare(
        'SELECT id, invoice_no FROM sal_invoice WHERE delivery_id = ? ORDER BY id DESC LIMIT 1'
    );
    $st->execute([$deliveryId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $invId = (int) ($row['id'] ?? 0);
    if ($invId < 1) {
        return null;
    }
    require_once app_path('includes/sal_invoice_post.php');

    return [
        'id' => $invId,
        'invoice_no' => (string) ($row['invoice_no'] ?? ''),
        'is_posted' => sal_invoice_operational_post_complete($pdo, $invId),
    ];
}

/** فاتورة مربوطة بالسند ومرحّلة تشغيلياً (ذمة العميل، والمخزون من السند إن لزم). */
function sal_delivery_linked_invoice_is_posted(PDO $pdo, int $invoiceId): bool
{
    if ($invoiceId < 1) {
        return false;
    }
    require_once app_path('includes/sal_invoice_post.php');

    return sal_invoice_operational_post_complete($pdo, $invoiceId);
}

/**
 * فك ربط السند عن الفواتير غير المرحّلة ماليًا (مسودات أو فواتير فُكّ ترحيلها).
 *
 * @return int عدد الفواتير التي فُكّ ربطها
 */
function sal_delivery_detach_unposted_invoice_links(PDO $pdo, int $deliveryId): int
{
    if ($deliveryId < 1 || !sal_invoice_column_exists($pdo, 'sal_invoice', 'delivery_id')) {
        return 0;
    }
    require_once app_path('includes/sal_invoice_post.php');
    $st = $pdo->prepare('SELECT id FROM sal_invoice WHERE delivery_id = ?');
    $st->execute([$deliveryId]);
    $detached = 0;
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $invId = (int) ($row['id'] ?? 0);
        if ($invId < 1 || sal_delivery_linked_invoice_is_posted($pdo, $invId)) {
            continue;
        }
        sal_invoice_set_delivery_id($pdo, $invId, null);
        $detached++;
    }

    return $detached;
}

/** هل السند مربوط بأي فاتورة (مسودة أو مرحّلة). */
function sal_delivery_has_linked_invoice(PDO $pdo, int $deliveryId, int $excludeInvoiceId = 0): bool
{
    if ($deliveryId < 1 || !sal_invoice_column_exists($pdo, 'sal_invoice', 'delivery_id')) {
        return false;
    }
    $sql = 'SELECT id FROM sal_invoice WHERE delivery_id = ?';
    $params = [$deliveryId];
    if ($excludeInvoiceId > 0) {
        $sql .= ' AND id <> ?';
        $params[] = $excludeInvoiceId;
    }
    $sql .= ' LIMIT 1';
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return (bool) $st->fetch();
}

/** فاتورة مبيعات مرتبطة بالسند ومُرحَّلة ماليًا (ذمة العميل). */
function sal_delivery_has_financially_posted_invoice(PDO $pdo, int $deliveryId): bool
{
    if ($deliveryId < 1) {
        return false;
    }
    sal_delivery_invoice_link_ensure($pdo);
    if (!sal_invoice_column_exists($pdo, 'sal_invoice', 'delivery_id')) {
        return false;
    }
    require_once app_path('includes/crm_customer_ledger.php');
    if (!crm_ledger_has_table($pdo)) {
        return false;
    }
    $st = $pdo->prepare('SELECT id FROM sal_invoice WHERE delivery_id = ?');
    $st->execute([$deliveryId]);
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $invId = (int) ($row['id'] ?? 0);
        if ($invId > 0 && crm_ledger_sale_invoice_is_posted($pdo, $invId)) {
            return true;
        }
    }

    return false;
}

/**
 * فك ربط السند عن فاتورة المبيعات المرتبطة به في قاعدة البيانات.
 *
 * @return array{ok:bool, error:?string, invoice_id:int, invoice_no:string}
 */
function sal_delivery_unlink_invoice(PDO $pdo, int $deliveryId): array
{
    $out = ['ok' => false, 'error' => null, 'invoice_id' => 0, 'invoice_no' => ''];
    if ($deliveryId < 1) {
        $out['error'] = 'معرّف السند غير صالح.';

        return $out;
    }

    $linked = sal_delivery_first_linked_invoice($pdo, $deliveryId);
    if ($linked === null) {
        $out['ok'] = true;

        return $out;
    }

    $invId = (int) ($linked['id'] ?? 0);
    if ($invId < 1) {
        $out['ok'] = true;

        return $out;
    }

    sal_invoice_set_delivery_id($pdo, $invId, null);
    $out['ok'] = true;
    $out['invoice_id'] = $invId;
    $out['invoice_no'] = (string) ($linked['invoice_no'] ?? '');

    return $out;
}

/**
 * فك ربط فاتورة عن سند التسليم (دون فك ترحيل الفاتورة أو مسح بيانات الضريبة).
 *
 * @return array{ok:bool, error:?string, delivery_id:int, delivery_no:string}
 */
function sal_invoice_unlink_delivery(PDO $pdo, int $invoiceId): array
{
    $out = ['ok' => false, 'error' => null, 'delivery_id' => 0, 'delivery_no' => ''];
    if ($invoiceId < 1) {
        $out['error'] = 'معرّف الفاتورة غير صالح.';

        return $out;
    }

    $deliveryId = sal_invoice_delivery_id($pdo, $invoiceId);
    if ($deliveryId < 1) {
        $out['ok'] = true;

        return $out;
    }

    $dn = $pdo->prepare('SELECT delivery_no FROM sal_delivery WHERE id = ? LIMIT 1');
    $dn->execute([$deliveryId]);
    $deliveryNo = (string) ($dn->fetchColumn() ?: '');

    sal_invoice_set_delivery_id($pdo, $invoiceId, null);
    $out['ok'] = true;
    $out['delivery_id'] = $deliveryId;
    $out['delivery_no'] = $deliveryNo;

    return $out;
}

/**
 * إزالة ربط السند بفواتير مسودة فارغة (بدون بنود) تبقى أحياناً بعد فشل الحفظ أو الحذف.
 */
function sal_delivery_prune_empty_draft_links(PDO $pdo, int $deliveryId): void
{
    if ($deliveryId < 1 || !sal_invoice_column_exists($pdo, 'sal_invoice', 'delivery_id')) {
        return;
    }
    require_once app_path('includes/sal_invoice_post.php');
    $st = $pdo->prepare('SELECT id FROM sal_invoice WHERE delivery_id = ?');
    $st->execute([$deliveryId]);
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $invId = (int) ($row['id'] ?? 0);
        if ($invId < 1 || sal_delivery_linked_invoice_is_posted($pdo, $invId)) {
            continue;
        }
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM sal_invoice_line WHERE invoice_id = ?');
        $cnt->execute([$invId]);
        if ((int) $cnt->fetchColumn() === 0) {
            sal_invoice_set_delivery_id($pdo, $invId, null);
        }
    }
}

/** تنظيف كل روابط المسودات الفارغة دفعة واحدة. */
function sal_delivery_prune_all_empty_draft_links(PDO $pdo): void
{
    if (!sal_invoice_column_exists($pdo, 'sal_invoice', 'delivery_id')) {
        return;
    }
    require_once app_path('includes/sal_invoice_post.php');
    $st = $pdo->query(
        'SELECT i.id
         FROM sal_invoice i
         WHERE i.delivery_id IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM sal_invoice_line l WHERE l.invoice_id = i.id)'
    );
    if (!$st) {
        return;
    }
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $invId = (int) ($row['id'] ?? 0);
        if ($invId < 1 || sal_delivery_linked_invoice_is_posted($pdo, $invId)) {
            continue;
        }
        sal_invoice_set_delivery_id($pdo, $invId, null);
    }
}

/**
 * سند مرحّل بلا أي فاتورة مبيعات مربوطة (بعد تنظيف المسودات الفارغة).
 * يُخفى التنبيه إذا وُجدت فاتورة مربوطة حتى لو ما زالت مسودة.
 */
function sal_delivery_needs_invoice(PDO $pdo, int $deliveryId): bool
{
    if ($deliveryId < 1 || !sal_delivery_is_posted($pdo, $deliveryId)) {
        return false;
    }

    sal_delivery_prune_empty_draft_links($pdo, $deliveryId);

    return !sal_delivery_has_linked_invoice($pdo, $deliveryId);
}

/**
 * @return list<array<string,mixed>>
 */
function sal_delivery_list_available_for_invoice(PDO $pdo, int $customerId = 0): array
{
    sal_delivery_invoice_link_ensure($pdo);
    if (!sal_delivery_has_table($pdo)) {
        return [];
    }

    sal_delivery_prune_all_empty_draft_links($pdo);

    $sql = 'SELECT d.id, d.delivery_no, d.delivery_date, d.customer_id, d.warehouse_id, d.notes,
                   c.name_ar AS customer_name, w.name_ar AS warehouse_name
            FROM sal_delivery d
            INNER JOIN crm_customer c ON c.id = d.customer_id
            LEFT JOIN inv_warehouse w ON w.id = d.warehouse_id
            WHERE d.is_posted = 1
              AND NOT EXISTS (
                  SELECT 1 FROM sal_invoice i WHERE i.delivery_id = d.id
              )';
    $params = [];
    if ($customerId > 0) {
        $sql .= ' AND d.customer_id = ?';
        $params[] = $customerId;
    }
    $sql .= ' ORDER BY d.delivery_date DESC, d.id DESC LIMIT 200';

    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return array{ok:bool, error:?string}
 */
function sal_invoice_validate_delivery_link(
    PDO $pdo,
    int $deliveryId,
    int $customerId,
    ?int $warehouseId,
    int $excludeInvoiceId = 0
): array {
    $out = ['ok' => false, 'error' => null];
    if ($deliveryId < 1) {
        $out['ok'] = true;

        return $out;
    }

    sal_delivery_invoice_link_ensure($pdo);
    $row = sal_delivery_fetch_by_id($pdo, $deliveryId);
    if (!$row) {
        $out['error'] = 'سند التسليم غير موجود.';

        return $out;
    }
    if (!(bool) ($row['is_posted'] ?? false)) {
        $out['error'] = 'يجب ترحيل سند التسليم قبل ربطه بالفاتورة.';

        return $out;
    }
    if (sal_delivery_has_linked_invoice($pdo, $deliveryId, $excludeInvoiceId)) {
        $out['error'] = 'سند التسليم مربوط بفاتورة أخرى.';

        return $out;
    }
    if ($customerId > 0 && (int) ($row['customer_id'] ?? 0) !== $customerId) {
        $out['error'] = 'عميل الفاتورة يجب أن يطابق عميل سند التسليم.';

        return $out;
    }
    $delWh = (int) ($row['warehouse_id'] ?? 0);
    if ($delWh > 0 && $warehouseId !== null && $warehouseId > 0 && $delWh !== $warehouseId) {
        $out['error'] = 'مستودع الفاتورة يجب أن يطابق مستودع سند التسليم.';

        return $out;
    }

    $out['ok'] = true;

    return $out;
}

/** المخزون صُرف عند ترحيل سند التسليم المرتبط — لا تُكرَّر حركة الفاتورة. */
function sal_invoice_stock_handled_by_delivery(PDO $pdo, int $invoiceId): bool
{
    $deliveryId = sal_invoice_delivery_id($pdo, $invoiceId);
    if ($deliveryId < 1) {
        return false;
    }

    require_once app_path('includes/sal_delivery_stock.php');

    return sal_delivery_is_posted($pdo, $deliveryId) && sal_delivery_stock_is_posted($pdo, $deliveryId);
}
