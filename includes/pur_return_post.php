<?php
declare(strict_types=1);

require_once app_path('includes/crm_supplier_ledger.php');
require_once app_path('includes/inv_stock.php');
require_once app_path('includes/pur_invoice_post.php');

/** تعبير SQL: المرتجع مرحّل (ذمة + مخزون إن لزم). */
function pur_return_sql_is_posted_expr(string $returnAlias = 'r'): string
{
    $r = $returnAlias;
    $ledger = "EXISTS (
        SELECT 1 FROM crm_supplier_ledger l
        WHERE l.txn_type = 'purchase_return' AND l.ref_id = {$r}.id
    )";
    $stockNeeded = "({$r}.warehouse_id IS NOT NULL AND EXISTS (
        SELECT 1 FROM pur_return_line pl
        INNER JOIN inv_item it ON it.id = pl.item_id
        WHERE pl.return_id = {$r}.id AND it.track_inventory = 1 AND pl.qty > 0
    ))";
    $stockDone = "EXISTS (
        SELECT 1 FROM inv_stock_move m
        WHERE m.ref_type = 'purchase_return' AND m.ref_id = {$r}.id
    )";

    return "({$ledger} AND (NOT {$stockNeeded} OR {$stockDone}))";
}

function pur_return_stock_posting_required(PDO $pdo, int $returnId): bool
{
    if ($returnId < 1 || !inv_stock_move_has_table($pdo)) {
        return false;
    }

    $st = $pdo->prepare('SELECT warehouse_id FROM pur_return WHERE id = ? LIMIT 1');
    $st->execute([$returnId]);
    $wh = $st->fetchColumn();
    if ($wh === false || $wh === null || (int) $wh < 1) {
        return false;
    }

    $ln = $pdo->prepare(
        'SELECT 1
         FROM pur_return_line pl
         INNER JOIN inv_item i ON i.id = pl.item_id
         WHERE pl.return_id = ? AND i.track_inventory = 1 AND pl.qty > 0
         LIMIT 1'
    );
    $ln->execute([$returnId]);

    return (bool) $ln->fetch();
}

function pur_return_stock_is_posted(PDO $pdo, int $returnId): bool
{
    if ($returnId < 1) {
        return false;
    }
    if (!pur_return_stock_posting_required($pdo, $returnId)) {
        return true;
    }
    if (!inv_stock_move_has_table($pdo)) {
        return false;
    }

    $st = $pdo->prepare(
        "SELECT id FROM inv_stock_move WHERE ref_type = 'purchase_return' AND ref_id = ? LIMIT 1"
    );
    $st->execute([$returnId]);

    return (bool) $st->fetch();
}

function pur_return_financial_is_posted(PDO $pdo, int $returnId): bool
{
    return $returnId > 0 && crm_supplier_ledger_exists($pdo, 'purchase_return', $returnId);
}

function pur_return_is_posted(PDO $pdo, int $returnId): bool
{
    return pur_return_financial_is_posted($pdo, $returnId)
        && pur_return_stock_is_posted($pdo, $returnId);
}

/**
 * صرف مخزون لمردود مشتريات (إخراج من المستودع).
 *
 * @return array{ok:bool, skipped:bool, error:?string}
 */
function pur_return_stock_post(PDO $pdo, int $returnId): array
{
    $out = ['ok' => false, 'skipped' => false, 'error' => null];

    if ($returnId < 1) {
        $out['error'] = 'معرّف المردود غير صالح.';

        return $out;
    }

    if (!pur_return_stock_posting_required($pdo, $returnId)) {
        $out['ok'] = true;
        $out['skipped'] = true;

        return $out;
    }

    if (!inv_stock_move_has_table($pdo)) {
        $out['error'] = 'جدول حركات المخزون غير موجود.';

        return $out;
    }

    if (pur_return_stock_is_posted($pdo, $returnId)) {
        $out['ok'] = true;
        $out['skipped'] = true;

        return $out;
    }

    $hdr = $pdo->prepare(
        'SELECT return_no, return_date, warehouse_id, status FROM pur_return WHERE id = ? LIMIT 1'
    );
    $hdr->execute([$returnId]);
    $ret = $hdr->fetch(PDO::FETCH_ASSOC);
    if (!$ret) {
        $out['error'] = 'المردود غير موجود.';

        return $out;
    }

    if ((string) ($ret['status'] ?? '') !== 'confirmed') {
        $out['error'] = 'لا يمكن ترحيل مردود غير مؤكد.';

        return $out;
    }

    $warehouseId = (int) ($ret['warehouse_id'] ?? 0);
    if ($warehouseId < 1) {
        $out['error'] = 'المستودع غير محدد على المردود.';

        return $out;
    }

    $lines = $pdo->prepare(
        'SELECT pl.item_id, pl.qty, i.name_ar, i.track_inventory
         FROM pur_return_line pl
         INNER JOIN inv_item i ON i.id = pl.item_id
         WHERE pl.return_id = ? AND i.track_inventory = 1 AND pl.qty > 0
         ORDER BY pl.id ASC'
    );
    $lines->execute([$returnId]);
    $rows = $lines->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $returnNo = (string) ($ret['return_no'] ?? '');
    $moveDate = (string) ($ret['return_date'] ?? date('Y-m-d'));

    foreach ($rows as $row) {
        $itemId = (int) $row['item_id'];
        $qty = (float) $row['qty'];
        if ($itemId < 1 || $qty <= 0) {
            continue;
        }

        $note = 'صرف مردود مشتريات ' . $returnNo;
        $move = inv_stock_issue($pdo, $moveDate, $warehouseId, $itemId, $qty, 'purchase_return', $returnId, $note);
        if (!$move['ok']) {
            $name = (string) ($row['name_ar'] ?? ('#' . $itemId));
            $out['error'] = ($move['error'] ?? 'تعذر صرف المخزون.') . ' — «' . $name . '»';

            return $out;
        }
    }

    $out['ok'] = true;

    return $out;
}

/**
 * ترحيل مردود مشتريات: مخزون ثم ذمة المورد.
 *
 * @return array{ok:bool, skipped:bool, error:?string}
 */
function pur_return_post_by_id(PDO $pdo, int $returnId): array
{
    $out = ['ok' => false, 'skipped' => false, 'error' => null];

    if ($returnId < 1) {
        $out['error'] = 'معرّف المردود غير صالح.';

        return $out;
    }

    if (!crm_supplier_ledger_ensure_schema($pdo)) {
        $out['error'] = 'جدول ذمة المورد غير متوفر.';

        return $out;
    }

    if (pur_return_is_posted($pdo, $returnId)) {
        $out['ok'] = true;
        $out['skipped'] = true;

        return $out;
    }

    $sql = 'SELECT r.id, r.return_no, r.return_date, r.supplier_id, r.total, r.status,
                   i.invoice_no, i.payment_type
            FROM pur_return r
            INNER JOIN pur_invoice i ON i.id = r.invoice_id
            WHERE r.id = ? LIMIT 1';
    try {
        $pdo->query('SELECT payment_type FROM pur_invoice LIMIT 1');
    } catch (Throwable $e) {
        $sql = 'SELECT r.id, r.return_no, r.return_date, r.supplier_id, r.total, r.status, i.invoice_no
                FROM pur_return r
                INNER JOIN pur_invoice i ON i.id = r.invoice_id
                WHERE r.id = ? LIMIT 1';
    }

    $st = $pdo->prepare($sql);
    $st->execute([$returnId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $out['error'] = 'المردود غير موجود.';

        return $out;
    }

    if ((string) ($row['status'] ?? '') !== 'confirmed') {
        $out['error'] = 'لا يمكن ترحيل مردود غير مؤكد.';

        return $out;
    }

    $total = (float) ($row['total'] ?? 0);
    if ($total <= 0) {
        $out['error'] = 'إجمالي المردود غير صالح.';

        return $out;
    }

    $invSt = $pdo->prepare('SELECT invoice_id FROM pur_return WHERE id = ? LIMIT 1');
    $invSt->execute([$returnId]);
    $invoiceId = (int) $invSt->fetchColumn();
    if ($invoiceId < 1) {
        $out['error'] = 'فاتورة الشراء المرتبطة بالمردود غير موجودة.';

        return $out;
    }
    if (!crm_supplier_ledger_purchase_invoice_is_posted($pdo, $invoiceId)) {
        $out['error'] = 'لا يمكن ترحيل المردود قبل ترحيل فاتورة الشراء على ذمة المورد. رحّل الفاتورة أولاً.';

        return $out;
    }
    if (!pur_invoice_is_posted($pdo, $invoiceId)) {
        $invNo = (string) ($row['invoice_no'] ?? '');
        $out['error'] = 'فاتورة الشراء'
            . ($invNo !== '' ? ' «' . $invNo . '»' : '')
            . ' لم يُكتمل ترحيلها (مستودع). رحّل الفاتورة بالكامل ثم أعد ترحيل المردود.';

        return $out;
    }

    $lineSt = $pdo->prepare(
        'SELECT pl.invoice_line_id, pl.qty
         FROM pur_return_line pl
         WHERE pl.return_id = ?'
    );
    $lineSt->execute([$returnId]);
    $returnLines = $lineSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $qtyByInvoiceLine = [];
    foreach ($returnLines as $rln) {
        $lineId = (int) ($rln['invoice_line_id'] ?? 0);
        if ($lineId < 1) {
            continue;
        }
        $qtyByInvoiceLine[$lineId] = ($qtyByInvoiceLine[$lineId] ?? 0.0) + (float) ($rln['qty'] ?? 0);
    }
    foreach ($qtyByInvoiceLine as $lineId => $qtyThisLine) {
        $chk = $pdo->prepare(
            'SELECT il.qty AS qty_sold,
                    COALESCE(SUM(CASE WHEN r2.id IS NOT NULL THEN rl2.qty ELSE 0 END), 0) AS qty_returned
             FROM pur_invoice_line il
             LEFT JOIN pur_return_line rl2 ON rl2.invoice_line_id = il.id
             LEFT JOIN pur_return r2 ON r2.id = rl2.return_id
                 AND r2.status <> ?
                 AND r2.id <> ?
             WHERE il.id = ? AND il.invoice_id = ?
             GROUP BY il.id'
        );
        $chk->execute(['cancelled', $returnId, $lineId, $invoiceId]);
        $chkRow = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$chkRow) {
            $out['error'] = 'بند فاتورة غير صالح في المردود.';

            return $out;
        }
        $qtySold = (float) $chkRow['qty_sold'];
        $qtyOtherReturns = (float) $chkRow['qty_returned'];
        if ($qtyOtherReturns + $qtyThisLine > $qtySold + 0.000001) {
            $out['error'] = 'كمية الإرجاع أكبر من الكمية المتبقية على الفاتورة — راجع المردودات الأخرى.';

            return $out;
        }
    }

    $stk = pur_return_stock_post($pdo, $returnId);
    if (!$stk['ok']) {
        $out['error'] = $stk['error'] ?? 'تعذر الترحيل المستودعي.';

        return $out;
    }

    if (!pur_return_financial_is_posted($pdo, $returnId)) {
        try {
            crm_supplier_ledger_post_purchase_return(
                $pdo,
                $returnId,
                (string) ($row['return_no'] ?? ''),
                (string) ($row['return_date'] ?? date('Y-m-d')),
                (int) ($row['supplier_id'] ?? 0),
                (string) ($row['payment_type'] ?? 'credit'),
                $total,
                (string) ($row['invoice_no'] ?? '')
            );
        } catch (Throwable $e) {
            $out['error'] = 'تعذر الترحيل المالي: ' . $e->getMessage();

            return $out;
        }
    }

    if (!pur_return_financial_is_posted($pdo, $returnId)) {
        $out['error'] = 'لم يُسجَّل المردود على ذمة المورد.';

        return $out;
    }

    require_once app_path('includes/acc_gl.php');
    $gl = acc_gl_post_purchase_return($pdo, $returnId);
    if (!$gl['ok'] && !$gl['skipped']) {
        $out['ok'] = false;
        $out['error'] = $gl['error'] ?? 'تعذر الترحيل المحاسبي.';

        return $out;
    }
    $out['ok'] = true;

    require_once app_path('includes/sys_audit_log.php');
    sys_audit_log_pur_return($pdo, 'post', $returnId);

    return $out;
}

/**
 * @param list<int> $returnIds
 * @return array{posted:int, skipped:int, errors:list<string>}
 */
function pur_return_post_by_ids(PDO $pdo, array $returnIds): array
{
    $result = ['posted' => 0, 'skipped' => 0, 'errors' => []];
    foreach ($returnIds as $rawId) {
        $id = (int) $rawId;
        if ($id < 1) {
            continue;
        }
        $one = pur_return_post_by_id($pdo, $id);
        if ($one['skipped']) {
            $result['skipped']++;
        } elseif ($one['ok']) {
            $result['posted']++;
        } elseif ($one['error'] !== null) {
            $result['errors'][] = 'مردود #' . $id . ': ' . $one['error'];
        }
    }

    return $result;
}
