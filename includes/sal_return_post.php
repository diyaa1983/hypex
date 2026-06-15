<?php
declare(strict_types=1);

require_once app_path('includes/crm_customer_ledger.php');
require_once app_path('includes/sal_return_schema.php');
require_once app_path('includes/sal_return_invoice_lines.php');
require_once app_path('includes/inv_stock.php');
require_once app_path('includes/sal_invoice_schema.php');
require_once app_path('includes/sal_invoice_post.php');

/**
 * تعبير SQL: المرتجع مرحّل ماليًا ومستودعيًا بالكامل (إن لزم المخزون).
 *
 * @see sal_invoice_sql_is_posted_expr
 */
function sal_return_sql_is_posted_expr(string $returnAlias = 'r'): string
{
    $r = $returnAlias;
    $ledgerReturn = "EXISTS (
        SELECT 1 FROM crm_customer_ledger l
        WHERE l.txn_type = 'sale_return' AND l.ref_id = {$r}.id
    )";
    $ledgerCashReturnDone = "EXISTS (
        SELECT 1
        FROM sal_return r_c
        INNER JOIN sal_invoice i_c ON i_c.id = r_c.invoice_id AND i_c.payment_type = 'cash'
        INNER JOIN crm_customer_ledger l_inv
            ON l_inv.txn_type = 'sale_invoice' AND l_inv.ref_id = i_c.id
            AND l_inv.debit > 0.000001 AND l_inv.credit < 0.000001
        WHERE r_c.id = {$r}.id
    )";
    $ledger = "({$ledgerReturn} OR {$ledgerCashReturnDone})";
    $stockNeeded = "({$r}.warehouse_id IS NOT NULL AND EXISTS (
        SELECT 1 FROM sal_return_line rl
        INNER JOIN inv_item it ON it.id = rl.item_id
        WHERE rl.return_id = {$r}.id AND it.track_inventory = 1 AND rl.qty > 0
    ))";
    $stockDone = "EXISTS (
        SELECT 1 FROM inv_stock_move m
        WHERE m.ref_type = 'sale_return' AND m.ref_id = {$r}.id
    )";

    return "({$ledger} AND (NOT {$stockNeeded} OR {$stockDone}))";
}

/**
 * تحويل مسودة مرتجع إلى «مؤكّد» قبل الترحيل (لا يغيّر المخزون ولا دفتر العميل).
 *
 * @return array{ok:bool, error:?string}
 */
function sal_return_promote_draft_to_confirmed(PDO $pdo, int $returnId): array
{
    $out = ['ok' => false, 'error' => null];
    if ($returnId < 1) {
        $out['error'] = 'معرّف المرتجع غير صالح.';

        return $out;
    }

    $st = $pdo->prepare('SELECT status FROM sal_return WHERE id = ? LIMIT 1');
    $st->execute([$returnId]);
    $status = (string) ($st->fetchColumn() ?: '');

    if ($status === 'cancelled') {
        $out['error'] = 'لا يمكن ترحيل مرتجع ملغى.';

        return $out;
    }
    if ($status === 'draft') {
        $pdo->prepare("UPDATE sal_return SET status = 'confirmed' WHERE id = ? AND status = 'draft'")
            ->execute([$returnId]);
    } elseif ($status !== 'confirmed') {
        $out['error'] = 'لا يمكن ترحيل مرتجع بحالة غير صالحة.';

        return $out;
    }

    $out['ok'] = true;

    return $out;
}

function sal_return_financial_is_posted(PDO $pdo, int $returnId): bool
{
    if ($returnId < 1 || !crm_ledger_ensure_schema($pdo)) {
        return false;
    }
    if (crm_ledger_exists($pdo, 'sale_return', $returnId)) {
        return true;
    }

    $st = $pdo->prepare(
        'SELECT r.invoice_id FROM sal_return r
         INNER JOIN sal_invoice i ON i.id = r.invoice_id
         WHERE r.id = ? AND i.payment_type = \'cash\'
         LIMIT 1'
    );
    try {
        $st->execute([$returnId]);
    } catch (Throwable $e) {
        return false;
    }
    $invoiceId = (int) $st->fetchColumn();
    if ($invoiceId < 1) {
        return false;
    }

    return crm_ledger_sale_invoice_ledger_is_pure_receivable($pdo, $invoiceId);
}

/** هل يتطلب المرتجع إدخال مخزون (مستودع + بنود قابلة للتتبع). */
function sal_return_stock_posting_required(PDO $pdo, int $returnId): bool
{
    if ($returnId < 1 || !inv_stock_move_has_table($pdo)) {
        return false;
    }

    $st = $pdo->prepare('SELECT warehouse_id FROM sal_return WHERE id = ? LIMIT 1');
    $st->execute([$returnId]);
    $wh = $st->fetchColumn();
    if ($wh === false || $wh === null || (int) $wh < 1) {
        return false;
    }

    if (!sal_invoice_column_exists($pdo, 'inv_item', 'track_inventory')) {
        return false;
    }

    $ln = $pdo->prepare(
        'SELECT 1
         FROM sal_return_line rl
         INNER JOIN inv_item i ON i.id = rl.item_id
         WHERE rl.return_id = ? AND i.track_inventory = 1 AND rl.qty > 0
         LIMIT 1'
    );
    $ln->execute([$returnId]);

    return (bool) $ln->fetch();
}

function sal_return_stock_is_posted(PDO $pdo, int $returnId): bool
{
    if ($returnId < 1) {
        return false;
    }
    if (!sal_return_stock_posting_required($pdo, $returnId)) {
        return true;
    }
    if (!inv_stock_move_has_table($pdo)) {
        inv_stock_move_ensure_table($pdo);
    }
    if (!inv_stock_move_has_table($pdo)) {
        return false;
    }

    $st = $pdo->prepare(
        "SELECT id FROM inv_stock_move WHERE ref_type = 'sale_return' AND ref_id = ? LIMIT 1"
    );
    $st->execute([$returnId]);

    return (bool) $st->fetch();
}

/** مرتجع مرحّل بالكامل (ذمة عميل + مخزون إن لزم). */
function sal_return_is_posted(PDO $pdo, int $returnId): bool
{
    if ($returnId < 1) {
        return false;
    }

    return sal_return_financial_is_posted($pdo, $returnId)
        && sal_return_stock_is_posted($pdo, $returnId);
}

/**
 * يمنع الحذف: أي أثر مالي أو حركة مخزون لهذا المرتجع (بما في ذلك ترحيل جزئي نادر).
 */
function sal_return_blocks_delete(PDO $pdo, int $returnId): bool
{
    if ($returnId < 1) {
        return true;
    }
    if (sal_return_financial_is_posted($pdo, $returnId)) {
        return true;
    }
    if (!inv_stock_move_has_table($pdo)) {
        return false;
    }
    $st = $pdo->prepare(
        "SELECT 1 FROM inv_stock_move WHERE ref_type = 'sale_return' AND ref_id = ? LIMIT 1"
    );
    $st->execute([$returnId]);

    return (bool) $st->fetch();
}

/**
 * إدخال مخزون لمرتجع مبيعات (عند الترحيل فقط): إرجاع البضاعة إلى المستودع.
 *
 * @return array{ok:bool, skipped:bool, error:?string}
 */
function sal_return_stock_post(PDO $pdo, int $returnId): array
{
    $out = ['ok' => false, 'skipped' => false, 'error' => null];

    if ($returnId < 1) {
        $out['error'] = 'معرّف المرتجع غير صالح.';

        return $out;
    }

    inv_stock_move_ensure_table($pdo);

    if (!sal_return_stock_posting_required($pdo, $returnId)) {
        $out['ok'] = true;
        $out['skipped'] = true;

        return $out;
    }

    if (sal_return_stock_is_posted($pdo, $returnId)) {
        $out['ok'] = true;
        $out['skipped'] = true;

        return $out;
    }

    $hdr = $pdo->prepare(
        'SELECT return_no, return_date, warehouse_id, status FROM sal_return WHERE id = ? LIMIT 1'
    );
    $hdr->execute([$returnId]);
    $ret = $hdr->fetch(PDO::FETCH_ASSOC);
    if (!$ret) {
        $out['error'] = 'المرتجع غير موجود.';

        return $out;
    }

    if ((string) ($ret['status'] ?? '') !== 'confirmed') {
        $out['error'] = 'لا يمكن ترحيل مرتجع غير مؤكد.';

        return $out;
    }

    $warehouseId = (int) ($ret['warehouse_id'] ?? 0);
    if ($warehouseId < 1) {
        $out['error'] = 'المستودع غير محدد على المرتجع.';

        return $out;
    }

    $lines = $pdo->prepare(
        'SELECT rl.item_id, rl.qty, i.name_ar, i.track_inventory
         FROM sal_return_line rl
         INNER JOIN inv_item i ON i.id = rl.item_id
         WHERE rl.return_id = ? AND i.track_inventory = 1 AND rl.qty > 0
         ORDER BY rl.id ASC'
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

        $note = 'إدخال مرتجع مبيعات ' . $returnNo;
        $move = inv_stock_receipt(
            $pdo,
            $moveDate,
            $warehouseId,
            $itemId,
            $qty,
            'sale_return',
            $returnId,
            $note
        );
        if (!$move['ok']) {
            $name = (string) ($row['name_ar'] ?? ('#' . $itemId));
            $out['error'] = ($move['error'] ?? 'تعذر إدخال المخزون.') . ' — «' . $name . '»';

            return $out;
        }
    }

    $out['ok'] = true;

    return $out;
}

/**
 * ترحيل مرتجع مبيعات: إدخال مخزون (إن لزم) ثم تسجيل على حساب العميل (عكس الفاتورة).
 *
 * @return array{ok:bool, skipped:bool, error:?string, warning:?string}
 */
function sal_return_post_by_id(PDO $pdo, int $returnId): array
{
    $out = ['ok' => false, 'skipped' => false, 'error' => null, 'warning' => null];

    if ($returnId < 1) {
        $out['error'] = 'معرّف المرتجع غير صالح.';

        return $out;
    }

    if (!sal_return_ensure_schema($pdo)) {
        $out['error'] = 'جداول المرتجع غير متوفرة.';

        return $out;
    }

    if (!crm_ledger_ensure_schema($pdo)) {
        $out['error'] = 'جدول حركات العملاء غير موجود.';

        return $out;
    }

    if (sal_return_is_posted($pdo, $returnId)) {
        $invRepair = $pdo->prepare('SELECT invoice_id FROM sal_return WHERE id = ? LIMIT 1');
        $invRepair->execute([$returnId]);
        $invRepairId = (int) $invRepair->fetchColumn();
        if ($invRepairId > 0 && sal_invoice_payment_type_is_cash($pdo, $invRepairId)) {
            crm_ledger_delete_sale_return_customer_row($pdo, $returnId);
            crm_ledger_convert_cash_sale_invoice_to_credit($pdo, $invRepairId);
        }

        $out['ok'] = true;
        $out['skipped'] = true;

        return $out;
    }

    $hasPay = sal_invoice_column_exists($pdo, 'sal_invoice', 'payment_type');
    $sql = 'SELECT r.id, r.return_no, r.return_date, r.customer_id, r.total, r.status,
                   i.invoice_no' . ($hasPay ? ', i.payment_type' : '') . '
            FROM sal_return r
            INNER JOIN sal_invoice i ON i.id = r.invoice_id
            WHERE r.id = ? LIMIT 1';

    $st = $pdo->prepare($sql);
    $st->execute([$returnId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $out['error'] = 'المرتجع غير موجود.';

        return $out;
    }

    $promote = sal_return_promote_draft_to_confirmed($pdo, $returnId);
    if (!$promote['ok']) {
        $out['error'] = $promote['error'];

        return $out;
    }

    $total = (float) ($row['total'] ?? 0);
    if ($total <= 0) {
        $out['error'] = 'إجمالي المرتجع غير صالح.';

        return $out;
    }

    $invSt = $pdo->prepare('SELECT invoice_id FROM sal_return WHERE id = ? LIMIT 1');
    $invSt->execute([$returnId]);
    $invoiceId = (int) $invSt->fetchColumn();
    if ($invoiceId < 1) {
        $out['error'] = 'فاتورة البيع المرتبطة بالمرتجع غير موجودة.';

        return $out;
    }
    if (!crm_ledger_sale_invoice_is_posted($pdo, $invoiceId)) {
        $out['error'] = 'لا يمكن ترحيل المرتجع قبل ترحيل فاتورة البيع على حساب العميل. رحّل الفاتورة أولاً من شاشة فواتير المبيعات.';

        return $out;
    }
    if (!sal_invoice_is_posted($pdo, $invoiceId)) {
        $invNo = (string) ($row['invoice_no'] ?? '');
        $out['error'] = 'فاتورة البيع'
            . ($invNo !== '' ? ' «' . $invNo . '»' : '')
            . ' لم يُكتمل ترحيلها (مستودع). رحّل الفاتورة بالكامل ثم أعد ترحيل المرتجع.';

        return $out;
    }

    $lineSt = $pdo->prepare(
        'SELECT rl.invoice_line_id, rl.qty
         FROM sal_return_line rl
         WHERE rl.return_id = ?'
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
             FROM sal_invoice_line il
             LEFT JOIN sal_return_line rl2 ON rl2.invoice_line_id = il.id
             LEFT JOIN sal_return r2 ON r2.id = rl2.return_id
                 AND r2.status <> ?
                 AND r2.id <> ?
             WHERE il.id = ? AND il.invoice_id = ?
             GROUP BY il.id'
        );
        $chk->execute(['cancelled', $returnId, $lineId, $invoiceId]);
        $chkRow = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$chkRow) {
            $out['error'] = 'بند فاتورة غير صالح في المرتجع.';

            return $out;
        }
        $qtySold = (float) $chkRow['qty_sold'];
        $qtyOtherReturns = (float) $chkRow['qty_returned'];
        if ($qtyOtherReturns + $qtyThisLine > $qtySold + 0.000001) {
            $out['error'] = 'كمية الإرجاع أكبر من الكمية المتبقية على الفاتورة — راجع المرتجعات الأخرى.';

            return $out;
        }
    }

    $whFix = $pdo->prepare(
        'SELECT r.warehouse_id, i.warehouse_id AS invoice_warehouse_id
         FROM sal_return r
         INNER JOIN sal_invoice i ON i.id = r.invoice_id
         WHERE r.id = ? LIMIT 1'
    );
    $whFix->execute([$returnId]);
    $whRow = $whFix->fetch(PDO::FETCH_ASSOC);
    if ($whRow && (int) ($whRow['warehouse_id'] ?? 0) < 1) {
        $invWh = (int) ($whRow['invoice_warehouse_id'] ?? 0);
        if ($invWh > 0) {
            $pdo->prepare('UPDATE sal_return SET warehouse_id = ? WHERE id = ?')->execute([$invWh, $returnId]);
        }
    }

    $stk = sal_return_stock_post($pdo, $returnId);
    if (!$stk['ok']) {
        $out['error'] = $stk['error'] ?? 'تعذر الترحيل المستودعي.';

        return $out;
    }

    if (!sal_return_financial_is_posted($pdo, $returnId)) {
        try {
            $payType = $hasPay ? (string) ($row['payment_type'] ?? 'credit') : 'credit';
            crm_ledger_post_sale_return(
                $pdo,
                $returnId,
                (string) ($row['return_no'] ?? ''),
                (string) ($row['return_date'] ?? date('Y-m-d')),
                (int) ($row['customer_id'] ?? 0),
                $payType,
                $total,
                (string) ($row['invoice_no'] ?? '')
            );
        } catch (Throwable $e) {
            $out['error'] = 'تعذر الترحيل المالي: ' . $e->getMessage();

            return $out;
        }
    }

    if (!sal_return_is_posted($pdo, $returnId)) {
        $out['error'] = 'لم يُكتمل ترحيل المرتجع (حساب عميل أو مخزون).';

        return $out;
    }

    require_once app_path('includes/acc_gl.php');
    $gl = acc_gl_post_sale_return($pdo, $returnId);
    $operationalComplete = sal_return_is_posted($pdo, $returnId);
    $glResult = acc_gl_soften_if_operational_posted($gl, $operationalComplete);
    if (!$glResult['ok'] && !$glResult['skipped']) {
        $out['ok'] = false;
        $out['error'] = $glResult['error'] ?? 'تعذر الترحيل المحاسبي.';

        return $out;
    }

    $out['ok'] = true;
    if (!empty($glResult['warning'])) {
        $out['warning'] = (string) $glResult['warning'];
    }

    return $out;
}

/**
 * @param list<int> $returnIds
 * @return array{posted:int, skipped:int, errors:list<string>, warnings:list<string>}
 */
function sal_return_post_by_ids(PDO $pdo, array $returnIds): array
{
    $result = ['posted' => 0, 'skipped' => 0, 'errors' => [], 'warnings' => []];
    foreach ($returnIds as $rawId) {
        $id = (int) $rawId;
        if ($id < 1) {
            continue;
        }
        $one = sal_return_post_by_id($pdo, $id);
        if ($one['skipped']) {
            $result['skipped']++;
        } elseif ($one['ok']) {
            $result['posted']++;
            if (!empty($one['warning'])) {
                $result['warnings'][] = 'مرتجع #' . $id . ': ' . $one['warning'];
            }
        } elseif ($one['error'] !== null) {
            if (sal_return_is_posted($pdo, $id)) {
                $result['posted']++;
                $result['warnings'][] = 'مرتجع #' . $id . ': ' . $one['error'];
            } else {
                $result['errors'][] = 'مرتجع #' . $id . ': ' . $one['error'];
            }
        }
    }

    return $result;
}
