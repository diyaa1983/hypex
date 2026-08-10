<?php
declare(strict_types=1);

/**
 * CLI لعمليات مرتجع المبيعات من hypex-node.
 * Usage: php sal_return_action.php <action> <userId>
 * actions: save | post | unpost | delete | einvoice | invoices | lines
 * stdin JSON, stdout single JSON line
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "cli only\n");
    exit(1);
}

$action = strtolower(trim((string) ($argv[1] ?? '')));
$userId = (int) ($argv[2] ?? 0);

$raw = stream_get_contents(STDIN);
$payload = json_decode($raw !== false && $raw !== '' ? $raw : '{}', true);
if (!is_array($payload)) {
    $payload = [];
}

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once app_path('includes/sal_return_schema.php');
require_once app_path('includes/sal_return_post.php');
require_once app_path('includes/sal_return_unpost.php');
require_once app_path('includes/sal_return_delete.php');
require_once app_path('includes/sal_return_invoices.php');
require_once app_path('includes/sal_return_invoice_lines.php');
require_once app_path('includes/sal_return_line_qty.php');
require_once app_path('includes/sal_invoice_post.php');
require_once app_path('includes/sales_return_post.php');
require_once app_path('includes/crm_customer_ledger.php');
require_once app_path('includes/inv_invoice_line_qty.php');
require_once app_path('includes/doc_number_pool.php');
require_once app_path('includes/einvoice_schema.php');
require_once app_path('includes/einvoice_send_return.php');
require_once app_path('includes/sys_audit_log.php');

function cli_out(array $data, int $code = 0): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
    exit($code);
}

function cli_login(int $userId): void
{
    if ($userId < 1) {
        cli_out(['ok' => false, 'error' => 'user_id مطلوب.'], 1);
    }
    $u = null;
    $queries = [
        'SELECT id, username, full_name_ar AS full_name FROM sys_user WHERE id = ? AND is_active = 1 LIMIT 1',
        'SELECT id, username, username AS full_name FROM sys_user WHERE id = ? AND is_active = 1 LIMIT 1',
    ];
    foreach ($queries as $sql) {
        try {
            $st = db()->prepare($sql);
            $st->execute([$userId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $u = $row;
                break;
            }
        } catch (Throwable $e) {
            continue;
        }
    }
    if (!$u) {
        cli_out(['ok' => false, 'error' => 'المستخدم غير موجود.'], 1);
    }
    $_SESSION['user'] = [
        'id' => (int) $u['id'],
        'username' => (string) ($u['username'] ?? ''),
        'name' => (string) ($u['full_name'] ?? $u['username'] ?? ''),
        'full_name' => (string) ($u['full_name'] ?? ''),
        'full_name_ar' => (string) ($u['full_name'] ?? ''),
    ];
    $_SESSION['is_system_admin'] = user_is_system_admin((int) $u['id']);
    $_SESSION['permissions'] = load_user_permissions((int) $u['id']);
    $_SESSION['permissions_user_id'] = (int) $u['id'];
    $_SESSION['permissions_loaded_at'] = time();
    $_SESSION['app_context'] = 'desktop';
}

try {
    cli_login($userId);
    $pdo = db();
    sal_return_ensure_schema($pdo);
    crm_ledger_ensure_schema($pdo);

    if ($action === 'invoices') {
        $customerId = (int) ($payload['customer_id'] ?? 0);
        $list = $customerId > 0 ? sal_return_invoices_for_customer($pdo, $customerId, true) : [];
        cli_out(['ok' => true, 'invoices' => $list]);
    }

    if ($action === 'lines') {
        $invoiceId = (int) ($payload['invoice_id'] ?? 0);
        $excludeReturnId = (int) ($payload['exclude_return_id'] ?? 0);
        $customerId = (int) ($payload['customer_id'] ?? 0);
        if ($invoiceId < 1) {
            cli_out(['ok' => false, 'error' => 'invoice_required'], 1);
        }
        $invSt = $pdo->prepare('SELECT id, customer_id, invoice_no, status FROM sal_invoice WHERE id = ? LIMIT 1');
        $invSt->execute([$invoiceId]);
        $inv = $invSt->fetch(PDO::FETCH_ASSOC);
        if (!$inv || (string) $inv['status'] !== 'confirmed') {
            cli_out(['ok' => false, 'error' => 'invoice_not_found', 'message' => 'الفاتورة غير موجودة.'], 1);
        }
        if ($customerId > 0 && (int) $inv['customer_id'] !== $customerId) {
            cli_out(['ok' => false, 'error' => 'customer_mismatch', 'message' => 'الفاتورة لا تخص العميل.'], 1);
        }
        if (!sal_invoice_is_posted($pdo, $invoiceId)) {
            cli_out([
                'ok' => false,
                'error' => 'invoice_not_posted',
                'message' => 'لا يمكن إرجاع إلا فواتير مبيعات مرحّلة.',
            ], 1);
        }
        $lines = sal_return_fetch_invoice_lines($pdo, $invoiceId, $excludeReturnId);
        cli_out([
            'ok' => true,
            'invoice_no' => (string) $inv['invoice_no'],
            'is_posted' => 1,
            'lines' => $lines,
        ]);
    }

    if ($action === 'save') {
        if (!user_can_sales_returns()) {
            cli_out(['ok' => false, 'error' => 'ليس لديك صلاحية حفظ مرتجع المبيعات.'], 1);
        }
        require_once app_path('includes/acc_period_lock.php');

        $returnId = (int) ($payload['return_id'] ?? $payload['id'] ?? 0);
        $isUpdate = $returnId > 0;
        $returnDate = parse_date_to_iso(trim((string) ($payload['return_date'] ?? ''))) ?? '';
        $customerId = (int) ($payload['customer_id'] ?? 0);
        $invoiceId = (int) ($payload['invoice_id'] ?? 0);
        $notes = trim((string) ($payload['notes'] ?? ''));
        $reasonReturn = trim((string) ($payload['reason_return'] ?? $payload['reason'] ?? ''));
        $lines = $payload['lines'] ?? [];
        if (!is_array($lines)) {
            $lines = [];
        }

        $err = '';
        if ($returnId > 0) {
            if (sal_return_einvoice_is_sent($pdo, $returnId)) {
                $err = 'لا يمكن تعديل مرتجع أُرسل إلى نظام الفوترة.';
            } elseif (sal_return_is_posted($pdo, $returnId)) {
                $err = 'لا يمكن تعديل مرتجع مرحّل.';
            }
        }
        if ($err === '' && $returnDate === '') {
            $err = 'تاريخ الإرجاع غير صالح.';
        } elseif ($err === '' && ($periodErr = acc_period_date_lock_error($pdo, $returnDate)) !== null) {
            $err = $periodErr;
        } elseif ($err === '' && $customerId < 1) {
            $err = 'اختر العميل.';
        } elseif ($err === '' && $invoiceId < 1) {
            $err = 'اختر فاتورة البيع.';
        } elseif ($err === '' && $lines === []) {
            $err = 'أدخل كمية إرجاع لمادة واحدة على الأقل.';
        }

        $invoice = sal_return_fetch_invoice($pdo, $invoiceId);
        if ($err === '' && !$invoice) {
            $err = 'فاتورة البيع غير موجودة.';
        } elseif ($err === '' && (int) $invoice['customer_id'] !== $customerId) {
            $err = 'الفاتورة لا تخص العميل المختار.';
        } elseif ($err === '' && (string) $invoice['status'] !== 'confirmed') {
            $err = 'لا يمكن إرجاع فاتورة غير مؤكدة.';
        } elseif ($err === '' && !sal_invoice_is_posted($pdo, $invoiceId)) {
            $err = 'لا يمكن إرجاع فاتورة غير مرحّلة.';
        }

        sal_return_line_ensure_qty_extra($pdo);
        inv_invoice_line_ensure_qty_extra($pdo);
        $hasExtraInv = inv_invoice_line_has_qty_extra($pdo, 'sal_invoice_line');
        $hasExtraRet = sal_return_line_has_qty_extra($pdo);
        $extraSoldSql = $hasExtraInv ? 'COALESCE(il.qty_extra, 0)' : '0';
        $extraRetSql = $hasExtraRet ? 'COALESCE(SUM(rl.qty_extra), 0)' : '0';

        $checkedLines = [];
        if ($err === '') {
            foreach ($lines as $ln) {
                if (!is_array($ln)) {
                    continue;
                }
                $lineId = (int) ($ln['invoice_line_id'] ?? 0);
                $itemId = (int) ($ln['item_id'] ?? 0);
                $qty = (float) ($ln['qty'] ?? 0);
                $qtyExtra = (float) ($ln['qty_extra'] ?? 0);
                if ($lineId < 1 && $itemId > 0) {
                    $find = $pdo->prepare(
                        'SELECT id FROM sal_invoice_line WHERE invoice_id = ? AND item_id = ? ORDER BY id ASC LIMIT 1'
                    );
                    $find->execute([$invoiceId, $itemId]);
                    $lineId = (int) ($find->fetchColumn() ?: 0);
                }
                if ($lineId < 1 || ($qty <= 0 && $qtyExtra <= 0)) {
                    continue;
                }

                $st = $pdo->prepare(
                    'SELECT il.id, il.item_id, il.qty AS qty_sold, ' . $extraSoldSql . ' AS qty_extra_sold,
                            il.unit_price, il.line_total, il.tax_rate_percent,
                            COALESCE(SUM(rl.qty), 0) AS qty_returned,
                            ' . $extraRetSql . ' AS qty_extra_returned
                     FROM sal_invoice_line il
                     LEFT JOIN sal_return_line rl ON rl.invoice_line_id = il.id
                     LEFT JOIN sal_return r ON r.id = rl.return_id AND r.status <> ?
                     WHERE il.id = ? AND il.invoice_id = ?
                       AND (' . ($returnId > 0 ? 'r.id IS NULL OR r.id <> ?' : '1=1') . ')
                     GROUP BY il.id'
                );
                $stParams = ['cancelled', $lineId, $invoiceId];
                if ($returnId > 0) {
                    $stParams[] = $returnId;
                }
                $st->execute($stParams);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    $err = 'سطر فاتورة غير صالح.';
                    break;
                }
                $remaining = (float) $row['qty_sold'] - (float) $row['qty_returned'];
                $extraRemaining = (float) $row['qty_extra_sold'] - (float) $row['qty_extra_returned'];
                if ($qty > $remaining + 0.000001) {
                    $err = 'كمية الإرجاع أكبر من الكمية المتبقية للمادة.';
                    break;
                }
                if ($qtyExtra > $extraRemaining + 0.000001) {
                    $err = 'الكمية الإضافية المرجعة أكبر من المتبقي.';
                    break;
                }
                $unitPrice = (float) $row['unit_price'];
                $taxRate = (float) $row['tax_rate_percent'];
                $amounts = sal_return_calc_line_amounts_from_invoice(
                    $qty,
                    (float) $row['qty_sold'],
                    (float) $row['line_total'],
                    $unitPrice,
                    $taxRate
                );
                $checkedLines[] = [
                    'invoice_line_id' => $lineId,
                    'item_id' => (int) $row['item_id'],
                    'qty' => $qty,
                    'qty_extra' => $qtyExtra,
                    '_unit_price' => $unitPrice,
                    '_tax_rate' => $taxRate,
                    'line_subtotal' => $amounts['line_subtotal'],
                    'tax_amount' => $amounts['tax_amount'],
                    'line_gross' => $amounts['line_gross'],
                ];
            }
            if ($err === '' && $checkedLines === []) {
                $err = 'أدخل كمية إرجاع أو كمية إضافية لمادة واحدة على الأقل.';
            }
        }

        if ($err !== '') {
            cli_out(['ok' => false, 'error' => $err], 1);
        }

        doc_number_pool_ensure_table($pdo);

        $pdo->beginTransaction();
        try {
            $sumSub = 0.0;
            $sumTax = 0.0;
            $sumGross = 0.0;
            foreach ($checkedLines as $ln) {
                $sumSub += (float) $ln['line_subtotal'];
                $sumTax += (float) $ln['tax_amount'];
                $sumGross += (float) $ln['line_gross'];
            }

            $whId = isset($invoice['warehouse_id']) && $invoice['warehouse_id'] !== null
                ? (int) $invoice['warehouse_id']
                : null;
            if ($whId !== null && $whId < 1) {
                $whId = null;
            }

            $returnNo = '';
            if ($returnId > 0) {
                $noSt = $pdo->prepare('SELECT return_no FROM sal_return WHERE id = ? LIMIT 1');
                $noSt->execute([$returnId]);
                $returnNo = (string) ($noSt->fetchColumn() ?: '');
                $pdo->prepare(
                    'UPDATE sal_return SET return_date = ?, customer_id = ?, invoice_id = ?, warehouse_id = ?,
                        subtotal = ?, tax_amount = ?, total = ?, notes = ? WHERE id = ?'
                )->execute([
                    $returnDate,
                    $customerId,
                    $invoiceId,
                    $whId,
                    round($sumSub, 6),
                    round($sumTax, 6),
                    round($sumGross, 6),
                    $notes !== '' ? $notes : null,
                    $returnId,
                ]);
                $pdo->prepare('DELETE FROM sal_return_line WHERE return_id = ?')->execute([$returnId]);
            } else {
                $returnNo = sal_return_generate_next_no($pdo, $returnDate);
                $pdo->prepare(
                    'INSERT INTO sal_return (return_no, return_date, customer_id, invoice_id, warehouse_id, subtotal, tax_amount, total, status, notes, created_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)'
                )->execute([
                    $returnNo,
                    $returnDate,
                    $customerId,
                    $invoiceId,
                    $whId,
                    round($sumSub, 6),
                    round($sumTax, 6),
                    round($sumGross, 6),
                    'draft',
                    $notes !== '' ? $notes : null,
                    $userId ?: null,
                ]);
                $returnId = (int) $pdo->lastInsertId();
            }

            if ($reasonReturn !== '' && einvoice_column_exists($pdo, 'sal_return', 'reason_return')) {
                $pdo->prepare('UPDATE sal_return SET reason_return = ? WHERE id = ?')
                    ->execute([$reasonReturn, $returnId]);
            }

            $insLine = $pdo->prepare(
                'INSERT INTO sal_return_line (return_id, invoice_line_id, item_id, qty, qty_extra, unit_price, tax_rate_percent, line_subtotal, tax_amount, line_gross)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            );
            foreach ($checkedLines as $ln) {
                $insLine->execute([
                    $returnId,
                    (int) $ln['invoice_line_id'],
                    (int) $ln['item_id'],
                    round((float) $ln['qty'], 6),
                    round((float) $ln['qty_extra'], 6),
                    round((float) $ln['_unit_price'], 6),
                    round((float) $ln['_tax_rate'], 3),
                    round((float) $ln['line_subtotal'], 6),
                    round((float) $ln['tax_amount'], 6),
                    round((float) $ln['line_gross'], 6),
                ]);
            }

            $pdo->commit();
            sys_audit_log_sal_return($pdo, 'save', $returnId);
            $msg = $isUpdate
                ? 'تم تحديث مرتجع المبيعات (بدون قيود). رقم: ' . $returnNo
                : 'تم حفظ مرتجع المبيعات (بدون قيود). رقم: ' . $returnNo;
            cli_out([
                'ok' => true,
                'id' => $returnId,
                'return_id' => $returnId,
                'return_no' => $returnNo,
                'message' => $msg,
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('sal_return save: ' . $e->getMessage());
            cli_out(['ok' => false, 'error' => 'تعذر حفظ المرتجع: ' . $e->getMessage()], 1);
        }
    }

    if ($action === 'post') {
        if (!user_can_sales_returns() || !user_can_action('action_post_sales_return')) {
            cli_out(['ok' => false, 'error' => 'لا صلاحية ترحيل المرتجع.'], 1);
        }
        $returnId = (int) ($payload['return_id'] ?? $payload['id'] ?? 0);
        if ($returnId < 1) {
            cli_out(['ok' => false, 'error' => 'لم يُحدَّد مرتجع.'], 1);
        }
        $result = sal_return_post_by_id($pdo, $returnId);
        if (empty($result['ok'])) {
            cli_out([
                'ok' => false,
                'error' => (string) ($result['error'] ?? 'تعذر الترحيل.'),
                'message' => (string) ($result['error'] ?? 'تعذر الترحيل.'),
            ], 1);
        }
        cli_out([
            'ok' => true,
            'return_id' => $returnId,
            'message' => (string) ($result['message'] ?? 'تم ترحيل المرتجع (مستودعيًا وماليًا).'),
        ]);
    }

    if ($action === 'unpost') {
        if (!user_can_sales_returns() || !user_can_action('action_unpost_sales_return')) {
            cli_out(['ok' => false, 'error' => 'لا صلاحية فك ترحيل المرتجع.'], 1);
        }
        $returnId = (int) ($payload['return_id'] ?? $payload['id'] ?? 0);
        if ($returnId < 1) {
            cli_out(['ok' => false, 'error' => 'لم يُحدَّد مرتجع.'], 1);
        }
        $pdo->beginTransaction();
        $res = sal_return_unpost_by_id($pdo, $returnId);
        if (empty($res['ok'])) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            cli_out([
                'ok' => false,
                'error' => (string) ($res['error'] ?? 'تعذر فك الترحيل.'),
            ], 1);
        }
        $pdo->commit();
        cli_out([
            'ok' => true,
            'return_id' => $returnId,
            'message' => (string) ($res['message'] ?? 'تم فك ترحيل المرتجع.'),
        ]);
    }

    if ($action === 'delete') {
        if (!user_can_sales_returns() || !user_can_action('action_delete_sales_return')) {
            cli_out(['ok' => false, 'error' => 'لا صلاحية حذف المرتجع.'], 1);
        }
        $returnId = (int) ($payload['return_id'] ?? $payload['id'] ?? 0);
        if ($returnId < 1) {
            cli_out(['ok' => false, 'error' => 'لم يُحدَّد مرتجع.'], 1);
        }
        $res = sal_return_delete_by_id($pdo, $returnId);
        if (empty($res['ok'])) {
            cli_out(['ok' => false, 'error' => (string) ($res['error'] ?? 'تعذر الحذف.')], 1);
        }
        cli_out([
            'ok' => true,
            'message' => (string) ($res['message'] ?? 'تم حذف المرتجع.'),
        ]);
    }

    if ($action === 'einvoice') {
        $may = user_can_sales_returns()
            && (user_can_action('sales_send_einvoice') || user_can('sales_send_einvoice')
                || user_can_action('action_post_sales_return') || user_is_system_admin($userId));
        if (!$may) {
            cli_out(['ok' => false, 'error' => 'لا صلاحية إرسال مرتجع للفوترة.'], 1);
        }
        $returnId = (int) ($payload['return_id'] ?? $payload['id'] ?? 0);
        $reason = trim((string) ($payload['reason'] ?? $payload['reason_return'] ?? ''));
        if ($returnId < 1) {
            cli_out(['ok' => false, 'error' => 'لم يُحدَّد مرتجع.'], 1);
        }
        if ($reason === '') {
            $reason = 'إرجاع بضاعة';
        }
        einvoice_ensure_schema($pdo);
        $pdo->beginTransaction();
        $result = einvoice_send_sale_return($pdo, $returnId, $reason);
        if (($result['error'] ?? null) !== null) {
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
            cli_out([
                'ok' => false,
                'error' => (string) $result['error'],
                'message' => (string) $result['error'],
            ], 1);
        }
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
        cli_out([
            'ok' => true,
            'skipped' => (bool) ($result['skipped'] ?? false),
            'message' => (string) ($result['message'] ?? 'تمت الفوترة.'),
            'return_id' => $returnId,
        ]);
    }

    cli_out(['ok' => false, 'error' => 'إجراء غير معروف: ' . $action], 1);
} catch (Throwable $e) {
    error_log('sal_return_action: ' . $e->getMessage());
    cli_out(['ok' => false, 'error' => $e->getMessage() ?: 'فشل التنفيذ.'], 1);
}
