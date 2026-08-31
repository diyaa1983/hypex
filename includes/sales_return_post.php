<?php
declare(strict_types=1);

require_once app_path('includes/sal_return_schema.php');
require_once app_path('includes/sal_invoice_post.php');
require_once app_path('includes/sal_return_invoices.php');
require_once app_path('includes/sal_return_post.php');
require_once app_path('includes/crm_customer_ledger.php');
require_once app_path('includes/inv_stock.php');

/** @return array<string, mixed>|null */
function sal_return_fetch_invoice(PDO $pdo, int $invoiceId): ?array
{
    try {
        $st = $pdo->prepare(
            'SELECT id, customer_id, warehouse_id, status, invoice_no, payment_type FROM sal_invoice WHERE id = ? LIMIT 1'
        );
        $st->execute([$invoiceId]);
        $row = $st->fetch();
    } catch (Throwable $e) {
        $st = $pdo->prepare(
            'SELECT id, customer_id, warehouse_id, status, invoice_no FROM sal_invoice WHERE id = ? LIMIT 1'
        );
        $st->execute([$invoiceId]);
        $row = $st->fetch();
        if (is_array($row)) {
            $row['payment_type'] = 'credit';
        }
    }

    return is_array($row) ? $row : null;
}

function sales_return_post_redirect_url(): string
{
    require_once app_path('includes/mobile_auth.php');
    if (app_request_from_mobile_app()) {
        return mobile_url('r=m_sales_returns');
    }

    return app_url('index.php?r=sales_returns');
}

function handle_sales_return_post(): void
{
    require_once app_path('includes/mobile_auth.php');
    require_once app_path('includes/mobile_return.php');

    $wantsJson = request_wants_json_invoice_save();
    $fail = static function (string $msg, int $status = 400) use ($wantsJson): void {
        if ($wantsJson) {
            json_invoice_save_response(false, ['message' => $msg], $status);
        }
        flash_set('error', $msg);
        redirect(sales_return_post_redirect_url());
    };

    if (!user_can_mobile_sales_returns()) {
        $fail('ليس لديك صلاحية حفظ مرتجع المبيعات.', 403);
    }

    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        $fail('انتهت صلاحية الجلسة.', 403);
    }

    $pdo = db();
    if (!sal_return_ensure_schema($pdo)) {
        $fail('نفّذ ملف الترحيل: database/migrations/007_sal_return.sql', 500);
    }
    require_once app_path('includes/acc_period_lock.php');

    $returnId = (int) ($_POST['return_id'] ?? 0);
    $isUpdate = $returnId > 0;
    $returnDate = parse_date_to_iso(trim((string) ($_POST['return_date'] ?? ''))) ?? '';
    $customerId = (int) ($_POST['customer_id'] ?? 0);
    $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $reasonReturn = trim((string) ($_POST['reason_return'] ?? ''));
    $lines = json_decode((string) ($_POST['lines_json'] ?? '[]'), true);

    $err = '';
    if ($returnId > 0) {
        require_once app_path('includes/sal_return_unpost.php');
        if (sal_return_einvoice_is_sent($pdo, $returnId)) {
            $err = 'لا يمكن تعديل مرتجع أُرسل إلى نظام الفوترة.';
        } elseif (sal_return_is_posted($pdo, $returnId)) {
            $err = 'لا يمكن تعديل مرتجع مرحّل.';
        } else {
            $hdrSt = $pdo->prepare(
                'SELECT id, customer_id, invoice_id, return_no FROM sal_return WHERE id = ? AND status <> ? LIMIT 1'
            );
            $hdrSt->execute([$returnId, 'cancelled']);
            $hdr = $hdrSt->fetch(PDO::FETCH_ASSOC);
            if (!$hdr) {
                $err = 'المرتجع غير موجود.';
            } elseif ($customerId > 0 && (int) $hdr['customer_id'] !== $customerId) {
                $err = 'العميل لا يطابق المرتجع المحفوظ.';
            } elseif ($invoiceId > 0 && (int) $hdr['invoice_id'] !== $invoiceId) {
                $err = 'فاتورة البيع لا تطابق المرتجع المحفوظ.';
            }
        }
    }

    if ($returnDate === '') {
        $err = 'تاريخ الإرجاع غير صالح.';
    } elseif (($periodErr = acc_period_date_lock_error($pdo, $returnDate)) !== null) {
        $err = $periodErr;
    } elseif ($customerId < 1) {
        $err = 'اختر العميل.';
    } elseif ($invoiceId < 1) {
        $err = 'اختر فاتورة البيع.';
    } elseif (!is_array($lines) || count($lines) < 1) {
        $err = 'أدخل كمية إرجاع لمادة واحدة على الأقل.';
    }

    $invoice = sal_return_fetch_invoice($pdo, $invoiceId);
    if (!$err && !$invoice) {
        $err = 'فاتورة البيع غير موجودة.';
    } elseif (!$err && (int) $invoice['customer_id'] !== $customerId) {
        $err = 'الفاتورة لا تخص العميل المختار.';
    } elseif (!$err && (string) $invoice['status'] !== 'confirmed') {
        $err = 'لا يمكن إرجاع فاتورة غير مؤكدة.';
    } elseif (!$err && !sal_invoice_is_posted($pdo, $invoiceId)) {
        $err = 'لا يمكن إرجاع فاتورة غير مرحّلة. رحّل الفاتورة أولاً من «ترحيل فواتير المبيعات».';
    }

    $validLines = [];
    if (!$err) {
        foreach ($lines as $ln) {
            if (!is_array($ln)) {
                $err = 'بيانات الأسطر غير صالحة.';
                break;
            }
            $lineId = (int) ($ln['invoice_line_id'] ?? 0);
            $itemId = (int) ($ln['item_id'] ?? 0);
            $qty = (float) ($ln['qty'] ?? 0);
            $qtyExtra = (float) ($ln['qty_extra'] ?? 0);
            // تطبيق الهاتف قد يرسل item_id فقط — نحلّه إلى سطر الفاتورة.
            if ($lineId < 1 && $itemId > 0 && $invoiceId > 0) {
                try {
                    $find = $pdo->prepare(
                        'SELECT id FROM sal_invoice_line
                         WHERE invoice_id = ? AND item_id = ?
                         ORDER BY id ASC LIMIT 1'
                    );
                    $find->execute([$invoiceId, $itemId]);
                    $lineId = (int) ($find->fetchColumn() ?: 0);
                    if ($lineId > 0) {
                        $ln['invoice_line_id'] = $lineId;
                    }
                } catch (Throwable $e) {
                    $lineId = 0;
                }
            }
            if ($lineId < 1 || ($qty <= 0 && $qtyExtra <= 0)) {
                continue;
            }
            $validLines[] = $ln;
        }
        if (!$err && $validLines === []) {
            $err = 'أدخل كمية إرجاع أو كمية إضافية لمادة واحدة على الأقل.';
        }
    }

    require_once app_path('includes/sal_return_line_qty.php');
    require_once app_path('includes/inv_invoice_line_qty.php');
    sal_return_line_ensure_qty_extra($pdo);
    inv_invoice_line_ensure_qty_extra($pdo);
    $hasExtraInv = inv_invoice_line_has_qty_extra($pdo, 'sal_invoice_line');
    $hasExtraRet = sal_return_line_has_qty_extra($pdo);
    $extraSoldSql = $hasExtraInv ? 'COALESCE(il.qty_extra, 0)' : '0';
    $extraRetSql = $hasExtraRet ? 'COALESCE(SUM(rl.qty_extra), 0)' : '0';

    $checkedLines = [];
    if (!$err) {
        foreach ($validLines as $ln) {
            $lineId = (int) $ln['invoice_line_id'];
            $qty = (float) ($ln['qty'] ?? 0);
            $qtyExtra = (float) ($ln['qty_extra'] ?? 0);

            $st = $pdo->prepare(
                'SELECT il.id, il.item_id, il.qty AS qty_sold, ' . $extraSoldSql . ' AS qty_extra_sold,
                        il.unit_price, il.line_total, il.tax_rate_percent,
                        COALESCE(il.tax_amount, 0) AS tax_amount,
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
            $row = $st->fetch();
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
                $err = 'الكمية الإضافية المرجعة أكبر من المتبقي على الفاتورة.';
                break;
            }
            if ($qty <= 0 && $qtyExtra <= 0) {
                continue;
            }
            $unitPrice = (float) $row['unit_price'];
            $taxRate = (float) $row['tax_rate_percent'];
            $qtySold = (float) $row['qty_sold'];
            $lineTotalSold = (float) $row['line_total'];
            $lineTaxSold = (float) ($row['tax_amount'] ?? 0);
            $amounts = sal_return_calc_line_amounts_from_invoice(
                $qty,
                $qtySold,
                $lineTotalSold,
                $unitPrice,
                $taxRate,
                $lineTaxSold
            );
            $ln['item_id'] = (int) $row['item_id'];
            $ln['_unit_price'] = $unitPrice;
            $ln['_tax_rate'] = (float) ($amounts['tax_rate_percent'] ?? $taxRate);
            $ln['qty'] = $qty;
            $ln['qty_extra'] = $qtyExtra;
            $ln['line_subtotal'] = $amounts['line_subtotal'];
            $ln['tax_amount'] = $amounts['tax_amount'];
            $ln['line_gross'] = $amounts['line_gross'];
            $checkedLines[] = $ln;
        }
        $validLines = $checkedLines;
    }

    if ($err !== '') {
        $fail($err);
    }

    // DDL يُنهي الـ transaction ضمنياً في MySQL — نهيّئ المخطط قبل البدء.
    require_once app_path('includes/doc_number_pool.php');
    doc_number_pool_ensure_table($pdo);

    try {
        $pdo->beginTransaction();

        $sumSub = 0.0;
        $sumTax = 0.0;
        $sumGross = 0.0;
        foreach ($validLines as $ln) {
            $sumSub += (float) ($ln['line_subtotal'] ?? 0);
            $sumTax += (float) ($ln['tax_amount'] ?? 0);
            $sumGross += (float) ($ln['line_gross'] ?? 0);
        }

        $whId = isset($invoice['warehouse_id']) && $invoice['warehouse_id'] !== null
            ? (int) $invoice['warehouse_id']
            : null;
        if ($whId < 1) {
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
            $uid = (int) (current_user()['id'] ?? 0) ?: null;

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
                $uid,
            ]);

            $returnId = (int) $pdo->lastInsertId();
        }

        if ($reasonReturn !== '') {
            try {
                require_once app_path('includes/einvoice_schema.php');
                if (einvoice_column_exists($pdo, 'sal_return', 'reason_return')) {
                    $pdo->prepare('UPDATE sal_return SET reason_return = ? WHERE id = ?')
                        ->execute([$reasonReturn, $returnId]);
                }
            } catch (Throwable $e) {
            }
        }

        $insLine = $pdo->prepare(
            'INSERT INTO sal_return_line (return_id, invoice_line_id, item_id, qty, qty_extra, unit_price, tax_rate_percent, line_subtotal, tax_amount, line_gross)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        );

        foreach ($validLines as $ln) {
            $insLine->execute([
                $returnId,
                (int) $ln['invoice_line_id'],
                (int) ($ln['item_id'] ?? 0),
                round((float) $ln['qty'], 6),
                round((float) ($ln['qty_extra'] ?? 0), 6),
                round((float) ($ln['_unit_price'] ?? $ln['unit_price'] ?? 0), 6),
                round((float) ($ln['_tax_rate'] ?? $ln['tax_rate_percent'] ?? 0), 3),
                round((float) ($ln['line_subtotal'] ?? 0), 6),
                round((float) ($ln['tax_amount'] ?? 0), 6),
                round((float) ($ln['line_gross'] ?? 0), 6),
            ]);
        }

        $pdo->commit();

        require_once app_path('includes/sys_audit_log.php');
        sys_audit_log_sal_return($pdo, 'save', $returnId);

        $msg = $isUpdate
            ? 'تم تحديث مرتجع المبيعات (غير مرحّل). رقم الإرجاع: ' . $returnNo
            : 'تم حفظ مرتجع المبيعات (غير مرحّل). رقم الإرجاع: ' . $returnNo;
        if ($wantsJson) {
            json_invoice_save_response(true, [
                'message' => $msg,
                'return_id' => $returnId,
                'return_no' => $returnNo,
            ]);
        }
        flash_set('success', $msg);
        if (app_request_from_mobile_app()) {
            redirect(mobile_url('r=m_sales_returns&id=' . $returnId));
        }
        require_once app_path('includes/nav_helpers.php');
        redirect(app_url('index.php?r=sales_returns&id=' . $returnId . nav_hub_query_for_redirect()));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $fail('تعذر حفظ مردود المبيعات.', 500);
    }
}
