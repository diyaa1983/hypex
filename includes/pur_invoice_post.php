<?php
declare(strict_types=1);

require_once app_path('includes/crm_supplier_ledger.php');
require_once app_path('includes/pur_invoice_stock.php');
require_once app_path('includes/inv_invoice_line_qty.php');

function pur_inv_db_has_column(PDO $pdo, string $table, string $column): bool
{
    try {
        $pdo->query('SELECT `' . str_replace('`', '', $column) . '` FROM `' . str_replace('`', '', $table) . '` LIMIT 1');

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** هل تتطلب الفاتورة ترحيلًا مستودعيًا. */
function pur_invoice_stock_posting_required(PDO $pdo, int $invoiceId): bool
{
    if ($invoiceId < 1 || !inv_stock_move_has_table($pdo)) {
        return false;
    }

    $st = $pdo->prepare('SELECT warehouse_id FROM pur_invoice WHERE id = ? LIMIT 1');
    $st->execute([$invoiceId]);
    $wh = $st->fetchColumn();
    if ($wh === false || $wh === null || (int) $wh < 1) {
        return false;
    }

    if (!pur_inv_db_has_column($pdo, 'inv_item', 'track_inventory')) {
        return false;
    }

    $ln = $pdo->prepare(
        'SELECT 1
         FROM pur_invoice_line il
         INNER JOIN inv_item i ON i.id = il.item_id
         WHERE il.invoice_id = ? AND i.track_inventory = 1 AND ' . inv_invoice_line_sql_stock_positive('il') . '
         LIMIT 1'
    );
    $ln->execute([$invoiceId]);

    return (bool) $ln->fetch();
}

function pur_invoice_stock_is_posted(PDO $pdo, int $invoiceId): bool
{
    if ($invoiceId < 1) {
        return false;
    }
    if (!pur_invoice_stock_posting_required($pdo, $invoiceId)) {
        return true;
    }
    if (!inv_stock_move_has_table($pdo)) {
        return false;
    }

    $st = $pdo->prepare(
        "SELECT id FROM inv_stock_move WHERE ref_type = 'purchase_invoice' AND ref_id = ? LIMIT 1"
    );
    $st->execute([$invoiceId]);

    return (bool) $st->fetch();
}

function pur_invoice_sql_is_posted_expr(string $invoiceAlias = 'i'): string
{
    $i = $invoiceAlias;
    $ledger = "EXISTS (
        SELECT 1 FROM crm_supplier_ledger l
        WHERE l.txn_type = 'purchase_invoice' AND l.ref_id = {$i}.id
    )";
    $stockPos = inv_invoice_line_sql_stock_positive('il');
    $stockNeeded = "({$i}.warehouse_id IS NOT NULL AND EXISTS (
        SELECT 1 FROM pur_invoice_line il
        INNER JOIN inv_item it ON it.id = il.item_id
        WHERE il.invoice_id = {$i}.id AND it.track_inventory = 1 AND {$stockPos}
    ))";
    $stockDone = "EXISTS (
        SELECT 1 FROM inv_stock_move m
        WHERE m.ref_type = 'purchase_invoice' AND m.ref_id = {$i}.id
    )";

    return "({$ledger} AND (NOT {$stockNeeded} OR {$stockDone}))";
}

function pur_invoice_is_posted(PDO $pdo, int $invoiceId): bool
{
    if ($invoiceId < 1) {
        return false;
    }

    return crm_supplier_ledger_purchase_invoice_is_posted($pdo, $invoiceId)
        && pur_invoice_stock_is_posted($pdo, $invoiceId);
}

/** هل اكتمل الترحيل التشغيلي (مخزون إن لزم + ذمة المورد) بغضّ النظر عن القيد المحاسبي. */
function pur_invoice_operational_post_complete(PDO $pdo, int $invoiceId): bool
{
    if ($invoiceId < 1 || !crm_supplier_ledger_purchase_invoice_is_posted($pdo, $invoiceId)) {
        return false;
    }
    if (!pur_invoice_stock_posting_required($pdo, $invoiceId)) {
        return true;
    }

    return pur_invoice_stock_is_posted($pdo, $invoiceId);
}

/**
 * ترحيل فاتورة شراء: مخزون (إدخال) ثم ذمة المورد.
 *
 * @return array{ok:bool, skipped:bool, error:?string}
 */
function pur_invoice_post_by_id(PDO $pdo, int $invoiceId): array
{
    $out = ['ok' => false, 'skipped' => false, 'error' => null, 'warning' => null];

    if ($invoiceId < 1) {
        $out['error'] = 'معرّف الفاتورة غير صالح.';

        return $out;
    }

    require_once app_path('includes/invoice_amount_decimals.php');
    $lockDecimals = pur_invoice_amount_decimals($pdo, $invoiceId);
    pur_invoice_persist_normalized($pdo, $invoiceId, $lockDecimals);

    try {
        $financialPosted = crm_supplier_ledger_purchase_invoice_is_posted($pdo, $invoiceId);
        $stockPosted = pur_invoice_stock_is_posted($pdo, $invoiceId);

        if ($financialPosted && $stockPosted) {
            $out['ok'] = true;
            $out['skipped'] = true;

            return $out;
        }

        if (!$stockPosted) {
            $stock = pur_invoice_stock_post($pdo, $invoiceId);
            if (!$stock['ok']) {
                $out['error'] = $stock['error'] ?? 'تعذر الترحيل المستودعي.';

                return $out;
            }
        }

        if (!$financialPosted) {
            $ledger = crm_supplier_ledger_post_purchase_invoice_by_id($pdo, $invoiceId);
            if (!$ledger['ok']) {
                $out['error'] = $ledger['error'] ?? 'تعذر الترحيل المالي.';

                return $out;
            }
        }

        require_once app_path('includes/acc_gl.php');
        $gl = acc_gl_post_purchase_invoice($pdo, $invoiceId);
        $operationalComplete = pur_invoice_operational_post_complete($pdo, $invoiceId);
        $glResult = acc_gl_soften_if_operational_posted($gl, $operationalComplete);
        if (!$glResult['ok'] && !$glResult['skipped']) {
            $out['error'] = $gl['error'] ?? 'تعذر الترحيل المحاسبي.';

            return $out;
        }

        $out['ok'] = true;
        if (!empty($glResult['warning'])) {
            $out['warning'] = (string) $glResult['warning'];
        }

        if (pur_invoice_operational_post_complete($pdo, $invoiceId)) {
            require_once app_path('includes/inv_item_purchase_cost.php');
            inv_item_sync_costs_after_purchase_invoice_post($pdo, $invoiceId);
        }

        require_once app_path('includes/sys_audit_log.php');
        sys_audit_log_pur_invoice($pdo, 'post', $invoiceId);

        return $out;
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'skipped' => false,
            'error' => 'تعذر الترحيل: ' . $e->getMessage(),
        ];
    }
}

/**
 * @param list<int> $invoiceIds
 * @return array{posted:int, skipped:int, errors:list<string>}
 */
function pur_invoice_post_by_ids(PDO $pdo, array $invoiceIds): array
{
    $result = ['posted' => 0, 'skipped' => 0, 'errors' => [], 'warnings' => []];
    foreach ($invoiceIds as $rawId) {
        $id = (int) $rawId;
        if ($id < 1) {
            continue;
        }
        $one = pur_invoice_post_by_id($pdo, $id);
        if ($one['skipped']) {
            $result['skipped']++;
        } elseif ($one['ok']) {
            $result['posted']++;
            if (!empty($one['warning'])) {
                $result['warnings'][] = 'فاتورة #' . $id . ': ' . $one['warning'];
            }
        } elseif ($one['error'] !== null) {
            if (pur_invoice_is_posted($pdo, $id)) {
                $result['posted']++;
                $result['warnings'][] = 'فاتورة #' . $id . ': ' . $one['error'];
            } else {
                $result['errors'][] = 'فاتورة #' . $id . ': ' . $one['error'];
            }
        }
    }

    return $result;
}
