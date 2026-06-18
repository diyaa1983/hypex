<?php
declare(strict_types=1);

function handle_purchase_invoice_post(): void
{
    $wantsJson = request_wants_json_invoice_save();

    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        if ($wantsJson) {
            json_invoice_save_response(false, ['message' => 'انتهت صلاحية الجلسة.'], 403);
        }
        flash_set('error', 'انتهت صلاحية الجلسة.');
        redirect(app_url('index.php?r=purchase_invoices'));
    }

    $pdo = db();
    require_once app_path('includes/pur_invoice_schema.php');
    pur_invoice_ensure_schema($pdo);
    require_once app_path('includes/pur_invoice_post.php');
    require_once app_path('includes/acc_period_lock.php');

    $invoiceDate = parse_date_to_iso(trim((string) ($_POST['invoice_date'] ?? ''))) ?? '';
    $supplierId = (int) ($_POST['supplier_id'] ?? 0);
    $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
    $paymentType = ($_POST['payment_type'] ?? 'cash') === 'credit' ? 'credit' : 'cash';
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $supplierInvoiceNo = trim((string) ($_POST['supplier_invoice_no'] ?? ''));
    if (strlen($supplierInvoiceNo) > 80) {
        $supplierInvoiceNo = substr($supplierInvoiceNo, 0, 80);
    }
    $supplierInvoiceNo = $supplierInvoiceNo !== '' ? $supplierInvoiceNo : null;
    $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
    $lines = json_decode((string) ($_POST['lines_json'] ?? '[]'), true);

    $whCount = (int) $pdo->query('SELECT COUNT(*) FROM inv_warehouse WHERE is_active = 1')->fetchColumn();

    $err = '';
    if ($invoiceDate === '') {
        $err = 'تاريخ الفاتورة غير صالح.';
    } elseif (($periodErr = acc_period_date_lock_error($pdo, $invoiceDate)) !== null) {
        $err = $periodErr;
    } elseif ($supplierId < 1) {
        $err = 'اختر المورد.';
    } elseif ($whCount > 0 && $warehouseId < 1) {
        $err = 'اختر المستودع.';
    } elseif (!is_array($lines)) {
        $err = 'بيانات الأسطر غير صالحة.';
    } elseif (count($lines) < 1 && $invoiceId < 1) {
        $err = 'أضف مادة واحدة على الأقل.';
    } elseif (count($lines) >= 1) {
        foreach ($lines as $ln) {
            if (!is_array($ln)) {
                $err = 'بيانات الأسطر غير صالحة.';
                break;
            }
            $iid = (int) ($ln['item_id'] ?? 0);
            require_once app_path('includes/inv_invoice_line_qty.php');
            $qty = (float) ($ln['qty'] ?? 0);
            $qtyExtra = (float) ($ln['qty_extra'] ?? 0);
            $up = (float) ($ln['unit_price'] ?? 0);
            if ($iid < 1 || inv_invoice_line_stock_qty_sum($qty, $qtyExtra) <= 0 || $up < 0) {
                $err = 'تأكد من الكمية أو الكمية الإضافية والأسعار لكل سطر.';
                break;
            }
        }
    }

    if ($err !== '') {
        if ($wantsJson) {
            json_invoice_save_response(false, ['message' => $err], 400);
        }
        flash_set('error', $err);
        redirect(app_url('index.php?r=purchase_invoices'));
    }

    $whFinal = $whCount > 0 ? $warehouseId : null;
    $savedInvoiceNo = '';

    try {
        $pdo->beginTransaction();

        require_once app_path('includes/invoice_amount_decimals.php');
        require_once app_path('includes/inv_invoice_discount.php');
        $amountDecimals = company_decimal_places($pdo);
        $headerDiscount = trim((string) ($_POST['invoice_discount'] ?? ''));
        if ($headerDiscount !== '') {
            $lines = inv_invoice_apply_header_discount($lines, $headerDiscount, $amountDecimals);
        }
        $lines = invoice_normalize_lines_array($lines, $amountDecimals);

        $sumSub = 0.0;
        $sumTax = 0.0;
        $sumGross = 0.0;
        foreach ($lines as $ln) {
            $sumSub += (float) ($ln['line_subtotal'] ?? 0);
            $sumTax += (float) ($ln['tax_amount'] ?? 0);
            $sumGross += (float) ($ln['line_gross'] ?? 0);
        }

        $uid = (int) (current_user()['id'] ?? 0) ?: null;

        if ($invoiceId > 0) {
            if (pur_invoice_is_posted($pdo, $invoiceId)) {
                throw new RuntimeException('لا يمكن تعديل فاتورة مرحّلة.');
            }
            pur_invoice_update_header_unposted(
                $pdo,
                $invoiceId,
                $invoiceDate,
                $supplierId,
                $whFinal,
                $paymentType,
                $sumSub,
                $sumTax,
                $sumGross,
                $notes !== '' ? $notes : null,
                $supplierInvoiceNo
            );
            pur_invoice_replace_lines($pdo, $invoiceId, $lines, $amountDecimals);
            pur_invoice_persist_normalized($pdo, $invoiceId, $amountDecimals);
            pur_invoice_set_invoice_discount_input($pdo, $invoiceId, $headerDiscount !== '' ? $headerDiscount : null);
            $pdo->commit();
            if (!$wantsJson) {
                flash_set('success', 'تم تحديث فاتورة الشراء (غير مرحّلة). يمكنك ترحيلها لاحقًا من زر «ترحيل».');
            }
        } else {
            $savedInvoiceNo = pur_invoice_generate_next_no($pdo, $invoiceDate);

            pur_invoice_insert_header(
                $pdo,
                $savedInvoiceNo,
                $invoiceDate,
                $supplierId,
                $whFinal,
                $paymentType,
                $sumSub,
                $sumTax,
                $sumGross,
                $notes !== '' ? $notes : null,
                $uid,
                $supplierInvoiceNo
            );

            $invoiceId = (int) $pdo->lastInsertId();

            foreach ($lines as $ln) {
                pur_invoice_insert_line($pdo, $invoiceId, $ln, $amountDecimals);
            }
            pur_invoice_persist_normalized($pdo, $invoiceId, $amountDecimals);
            pur_invoice_set_invoice_discount_input($pdo, $invoiceId, $headerDiscount !== '' ? $headerDiscount : null);

            $pdo->commit();
            if (!$wantsJson) {
                flash_set('success', 'تم حفظ فاتورة الشراء (بدون أثر مالي أو مستودعي). يمكنك ترحيلها لاحقًا من زر «ترحيل» أو من قائمة الفواتير.');
            }
        }

        if ($savedInvoiceNo === '') {
            $stNo = $pdo->prepare('SELECT invoice_no FROM pur_invoice WHERE id = ? LIMIT 1');
            $stNo->execute([$invoiceId]);
            $savedInvoiceNo = (string) $stNo->fetchColumn();
        }

        if ($wantsJson) {
            json_invoice_save_response(true, [
                'invoice_id' => $invoiceId,
                'invoice_no' => $savedInvoiceNo,
            ]);
        }

        require_once app_path('includes/nav_helpers.php');
        redirect(app_url('index.php?r=purchase_invoices&id=' . $invoiceId . nav_hub_query_for_redirect()));
    } catch (Throwable $e) {
        $pdo->rollBack();
        $detail = $e->getMessage();
        $msg = 'تعذر الحفظ.';
        if (strpos($detail, "doesn't exist") !== false || strpos($detail, 'Unknown table') !== false || strpos($detail, 'Base table') !== false) {
            $msg .= ' تحقق من وجود جداول فاتورة الشراء (pur_invoice) أو نفّذ database/schema.sql ثم حدّث الصفحة.';
        } else {
            $msg .= ' ' . $detail;
        }
        if ($wantsJson) {
            json_invoice_save_response(false, ['message' => $msg], 500);
        }
        flash_set('error', $msg);
        redirect(app_url('index.php?r=purchase_invoices'));
    }
}
