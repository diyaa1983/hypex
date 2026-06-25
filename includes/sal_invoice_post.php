<?php
declare(strict_types=1);

require_once app_path('includes/crm_customer_ledger.php');
require_once app_path('includes/inv_stock.php');
require_once app_path('includes/inv_invoice_line_qty.php');
require_once app_path('includes/sal_invoice_schema.php');

/** هل تتطلب الفاتورة ترحيلًا مستودعيًا (مستودع + بنود قابلة للتتبع). */
function sal_invoice_stock_posting_required(PDO $pdo, int $invoiceId): bool
{
    if ($invoiceId < 1 || !inv_stock_move_has_table($pdo)) {
        return false;
    }

    require_once app_path('includes/sal_delivery_invoice_link.php');
    if (sal_invoice_stock_handled_by_delivery($pdo, $invoiceId)) {
        return false;
    }

    $st = $pdo->prepare('SELECT warehouse_id FROM sal_invoice WHERE id = ? LIMIT 1');
    $st->execute([$invoiceId]);
    $wh = $st->fetchColumn();
    if ($wh === false || $wh === null || (int) $wh < 1) {
        return false;
    }

    require_once app_path('includes/sal_invoice_schema.php');
    if (!sal_invoice_column_exists($pdo, 'inv_item', 'track_inventory')) {
        return false;
    }

    $ln = $pdo->prepare(
        'SELECT 1
         FROM sal_invoice_line il
         INNER JOIN inv_item i ON i.id = il.item_id
         WHERE il.invoice_id = ? AND i.track_inventory = 1 AND ' . inv_invoice_line_sql_stock_positive('il') . '
         LIMIT 1'
    );
    $ln->execute([$invoiceId]);

    return (bool) $ln->fetch();
}

function sal_invoice_stock_is_posted(PDO $pdo, int $invoiceId): bool
{
    if ($invoiceId < 1) {
        return false;
    }
    require_once app_path('includes/sal_delivery_invoice_link.php');
    if (sal_invoice_stock_handled_by_delivery($pdo, $invoiceId)) {
        return true;
    }

    if (!sal_invoice_stock_posting_required($pdo, $invoiceId)) {
        return true;
    }
    if (!inv_stock_move_has_table($pdo)) {
        return false;
    }

    $st = $pdo->prepare(
        "SELECT id FROM inv_stock_move WHERE ref_type = 'sale_invoice' AND ref_id = ? LIMIT 1"
    );
    $st->execute([$invoiceId]);

    return (bool) $st->fetch();
}

/** تعبير SQL: الفاتورة مرحّلة ماليًا ومستودعيًا بالكامل. */
function sal_invoice_sql_is_posted_expr(string $invoiceAlias = 'i'): string
{
    $i = $invoiceAlias;
    $ledger = "EXISTS (
        SELECT 1 FROM crm_customer_ledger l
        WHERE l.txn_type = 'sale_invoice' AND l.ref_id = {$i}.id
    )";
    $stockPos = inv_invoice_line_sql_stock_positive('il');
    $stockNeeded = "({$i}.warehouse_id IS NOT NULL AND EXISTS (
        SELECT 1 FROM sal_invoice_line il
        INNER JOIN inv_item it ON it.id = il.item_id
        WHERE il.invoice_id = {$i}.id AND it.track_inventory = 1 AND {$stockPos}
    ))";
    $stockDone = "EXISTS (
        SELECT 1 FROM inv_stock_move m
        WHERE m.ref_type = 'sale_invoice' AND m.ref_id = {$i}.id
    )";

    return "({$ledger} AND (NOT {$stockNeeded} OR {$stockDone}))";
}

/** فاتورة مرحّلة ماليًا ومستودعيًا (إن لزم). */
function sal_invoice_is_posted(PDO $pdo, int $invoiceId): bool
{
    if ($invoiceId < 1) {
        return false;
    }

    return crm_ledger_sale_invoice_is_posted($pdo, $invoiceId)
        && sal_invoice_stock_is_posted($pdo, $invoiceId);
}

/** هل اكتمل الترحيل التشغيلي (مخزون إن لزم + حساب العميل) بغضّ النظر عن القيد المحاسبي. */
function sal_invoice_operational_post_complete(PDO $pdo, int $invoiceId): bool
{
    if ($invoiceId < 1 || !crm_ledger_sale_invoice_is_posted($pdo, $invoiceId)) {
        return false;
    }
    if (!sal_invoice_stock_posting_required($pdo, $invoiceId)) {
        return true;
    }

    return sal_invoice_stock_is_posted($pdo, $invoiceId);
}

/**
 * إخراج مخزون فاتورة البيع (عند الترحيل فقط).
 *
 * @return array{ok:bool, skipped:bool, error:?string}
 */
function sal_invoice_stock_post(PDO $pdo, int $invoiceId): array
{
    $out = ['ok' => false, 'skipped' => false, 'error' => null];

    if ($invoiceId < 1) {
        $out['error'] = 'معرّف الفاتورة غير صالح.';

        return $out;
    }

    if (!sal_invoice_stock_posting_required($pdo, $invoiceId)) {
        $out['ok'] = true;
        $out['skipped'] = true;

        return $out;
    }

    if (!inv_stock_move_has_table($pdo)) {
        $out['error'] = 'جدول حركات المخزون غير موجود.';

        return $out;
    }

    if (sal_invoice_stock_is_posted($pdo, $invoiceId)) {
        $out['ok'] = true;
        $out['skipped'] = true;

        return $out;
    }

    $hdr = $pdo->prepare(
        'SELECT invoice_no, invoice_date, warehouse_id, status FROM sal_invoice WHERE id = ? LIMIT 1'
    );
    $hdr->execute([$invoiceId]);
    $inv = $hdr->fetch(PDO::FETCH_ASSOC);
    if (!$inv) {
        $out['error'] = 'الفاتورة غير موجودة.';

        return $out;
    }

    if ((string) ($inv['status'] ?? '') !== 'confirmed') {
        $out['error'] = 'لا يمكن ترحيل فاتورة غير مؤكدة.';

        return $out;
    }

    $warehouseId = (int) ($inv['warehouse_id'] ?? 0);
    if ($warehouseId < 1) {
        $out['error'] = 'المستودع غير محدد على الفاتورة.';

        return $out;
    }

    $lines = $pdo->prepare(
        'SELECT il.item_id, il.qty, COALESCE(il.qty_extra, 0) AS qty_extra, il.line_desc, i.name_ar, i.track_inventory
         FROM sal_invoice_line il
         INNER JOIN inv_item i ON i.id = il.item_id
         WHERE il.invoice_id = ? AND i.track_inventory = 1 AND ' . inv_invoice_line_sql_stock_positive('il') . '
         ORDER BY il.id ASC'
    );
    $lines->execute([$invoiceId]);
    $rows = $lines->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $invoiceNo = (string) ($inv['invoice_no'] ?? '');
    $moveDate = (string) ($inv['invoice_date'] ?? date('Y-m-d'));

    foreach ($rows as $row) {
        $itemId = (int) $row['item_id'];
        $stockQty = inv_invoice_line_stock_qty_sum((float) $row['qty'], (float) ($row['qty_extra'] ?? 0));
        if ($itemId < 1 || $stockQty <= 0) {
            continue;
        }

        $note = 'صرف فاتورة بيع ' . $invoiceNo;
        $move = inv_stock_issue($pdo, $moveDate, $warehouseId, $itemId, $stockQty, 'sale_invoice', $invoiceId, $note);
        if (!$move['ok']) {
            $name = (string) ($row['name_ar'] ?? $row['line_desc'] ?? ('#' . $itemId));
            $out['error'] = ($move['error'] ?? 'تعذر صرف المخزون.') . ' — «' . $name . '»';

            return $out;
        }
    }

    $out['ok'] = true;

    return $out;
}

/**
 * ترحيل فاتورة بيع: مستودع (صرف) ثم حساب العميل (مالي).
 *
 * @return array{ok:bool, skipped:bool, error:?string, warning:?string}
 */
function sal_invoice_post_by_id(PDO $pdo, int $invoiceId): array
{
    $out = ['ok' => false, 'skipped' => false, 'error' => null, 'warning' => null];

    if ($invoiceId < 1) {
        $out['error'] = 'معرّف الفاتورة غير صالح.';

        return $out;
    }

    require_once app_path('includes/invoice_amount_decimals.php');
    $lockDecimals = sal_invoice_amount_decimals($pdo, $invoiceId);
    sal_invoice_persist_normalized($pdo, $invoiceId, $lockDecimals);

    $financialPosted = crm_ledger_sale_invoice_is_posted($pdo, $invoiceId);
    $stockPosted = sal_invoice_stock_is_posted($pdo, $invoiceId);

    if ($financialPosted && $stockPosted) {
        $out['ok'] = true;
        $out['skipped'] = true;

        return $out;
    }

    if (!$stockPosted) {
        $stock = sal_invoice_stock_post($pdo, $invoiceId);
        if (!$stock['ok']) {
            $out['error'] = $stock['error'] ?? 'تعذر الترحيل المستودعي.';

            return $out;
        }
    }

    if (!$financialPosted) {
        $ledger = crm_ledger_post_sale_invoice_by_id($pdo, $invoiceId);
        if (!$ledger['ok']) {
            $out['error'] = $ledger['error'] ?? 'تعذر الترحيل المالي.';

            return $out;
        }
    }

    require_once app_path('includes/acc_gl.php');
    $gl = acc_gl_post_sale_invoice($pdo, $invoiceId);
    $operationalComplete = sal_invoice_operational_post_complete($pdo, $invoiceId);
    $glResult = acc_gl_soften_if_operational_posted($gl, $operationalComplete);
    if (!$glResult['ok'] && !$glResult['skipped']) {
        $out['error'] = $gl['error'] ?? 'تعذر الترحيل المحاسبي.';

        return $out;
    }

    $out['ok'] = true;
    if (!empty($glResult['warning'])) {
        $out['warning'] = (string) $glResult['warning'];
    }

    if (!$out['skipped'] && function_exists('current_user')) {
        $uid = (int) (current_user()['id'] ?? 0);
        if ($uid > 0) {
            require_once app_path('includes/sal_invoice_schema.php');
            sal_invoice_set_posted_by_if_empty($pdo, $invoiceId, $uid);
        }
    }

    if (!$out['skipped']) {
        require_once app_path('includes/sys_audit_log.php');
        sys_audit_log_sal_invoice($pdo, 'post', $invoiceId);
    }

    return $out;
}

/**
 * @param list<int> $invoiceIds
 * @return array{posted:int, skipped:int, errors:list<string>, warnings:list<string>}
 */
function sal_invoice_post_by_ids(PDO $pdo, array $invoiceIds): array
{
    $result = ['posted' => 0, 'skipped' => 0, 'errors' => [], 'warnings' => []];
    foreach ($invoiceIds as $rawId) {
        $id = (int) $rawId;
        if ($id < 1) {
            continue;
        }
        $one = sal_invoice_post_by_id($pdo, $id);
        if ($one['skipped']) {
            $result['skipped']++;
        } elseif ($one['ok']) {
            $result['posted']++;
            if (!empty($one['warning'])) {
                $result['warnings'][] = 'فاتورة #' . $id . ': ' . $one['warning'];
            }
        } elseif ($one['error'] !== null) {
            if (sal_invoice_is_posted($pdo, $id)) {
                $result['posted']++;
                $result['warnings'][] = 'فاتورة #' . $id . ': ' . $one['error'];
            } else {
                $result['errors'][] = 'فاتورة #' . $id . ': ' . $one['error'];
            }
        }
    }

    return $result;
}
