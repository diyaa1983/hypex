<?php
declare(strict_types=1);

function handle_purchase_order_post(): void
{
    $wantsJson = request_wants_json_invoice_save();

    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        if ($wantsJson) {
            json_invoice_save_response(false, ['message' => 'انتهت صلاحية الجلسة.'], 403);
        }
        flash_set('error', 'انتهت صلاحية الجلسة.');
        redirect(app_url('index.php?r=purchase_orders'));
    }

    $pdo = db();
    require_once app_path('includes/pur_order_schema.php');
    pur_order_ensure_schema($pdo);
    require_once app_path('includes/acc_period_lock.php');

    $orderDate = parse_date_to_iso(trim((string) ($_POST['order_date'] ?? $_POST['invoice_date'] ?? ''))) ?? '';
    $expectedDateRaw = trim((string) ($_POST['expected_date'] ?? ''));
    $expectedDate = $expectedDateRaw !== '' ? (parse_date_to_iso($expectedDateRaw) ?? '') : '';
    $supplierId = (int) ($_POST['supplier_id'] ?? 0);
    $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
    $paymentType = ($_POST['payment_type'] ?? 'credit') === 'cash' ? 'cash' : 'credit';
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $referenceNo = trim((string) ($_POST['reference_no'] ?? $_POST['supplier_invoice_no'] ?? ''));
    if (strlen($referenceNo) > 80) {
        $referenceNo = substr($referenceNo, 0, 80);
    }
    $referenceNo = $referenceNo !== '' ? $referenceNo : null;
    $orderId = (int) ($_POST['order_id'] ?? $_POST['invoice_id'] ?? 0);
    $submitForApproval = ($_POST['submit_for_approval'] ?? '') === '1';
    $lines = json_decode((string) ($_POST['lines_json'] ?? '[]'), true);

    $whCount = (int) $pdo->query('SELECT COUNT(*) FROM inv_warehouse WHERE is_active = 1')->fetchColumn();

    $err = '';
    if ($orderDate === '') {
        $err = 'تاريخ الطلب غير صالح.';
    } elseif (($periodErr = acc_period_date_lock_error($pdo, $orderDate)) !== null) {
        $err = $periodErr;
    } elseif ($expectedDate !== '' && $expectedDate < $orderDate) {
        $err = 'تاريخ التسليم المتوقع يجب أن يكون بعد أو يساوي تاريخ الطلب.';
    } elseif ($supplierId < 1) {
        $err = 'اختر المورد.';
    } elseif ($whCount > 0 && $warehouseId < 1) {
        $err = 'اختر المستودع.';
    } elseif (!is_array($lines)) {
        $err = 'بيانات الأسطر غير صالحة.';
    } elseif (count($lines) < 1 && $orderId < 1) {
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

    if ($orderId > 0) {
        $status = pur_order_fetch_status($pdo, $orderId);
        if (!pur_order_is_editable_status($status)) {
            $err = 'لا يمكن تعديل طلب شراء معتمد أو مغلق أو ملغى.';
        }
    }

    if ($err !== '') {
        if ($wantsJson) {
            json_invoice_save_response(false, ['message' => $err], 400);
        }
        flash_set('error', $err);
        redirect(app_url('index.php?r=purchase_orders' . ($orderId > 0 ? '&id=' . $orderId : '')));
    }

    $whFinal = $whCount > 0 ? $warehouseId : null;
    $savedOrderNo = '';
    $newStatus = $submitForApproval ? 'submitted' : 'draft';

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

        if ($orderId > 0) {
            $pdo->prepare(
                'UPDATE pur_order SET order_date = ?, expected_date = ?, supplier_id = ?, warehouse_id = ?,
                 reference_no = ?, payment_type = ?, subtotal = ?, tax_amount = ?, total = ?, notes = ?,
                 status = ?, amount_decimals = ? WHERE id = ?'
            )->execute([
                $orderDate,
                $expectedDate !== '' ? $expectedDate : null,
                $supplierId,
                $whFinal,
                $referenceNo,
                $paymentType,
                company_round_amount($sumSub, $pdo, $amountDecimals),
                company_round_amount($sumTax, $pdo, $amountDecimals),
                company_round_amount($sumGross, $pdo, $amountDecimals),
                $notes !== '' ? $notes : null,
                $newStatus,
                $amountDecimals,
                $orderId,
            ]);
            pur_order_replace_lines($pdo, $orderId, $lines, $amountDecimals);
            pur_order_update_totals($pdo, $orderId, $amountDecimals);
            pur_order_set_discount_input($pdo, $orderId, $headerDiscount !== '' ? $headerDiscount : null);
            $pdo->commit();
            if (!$wantsJson) {
                flash_set('success', 'تم تحديث طلب الشراء.');
            }
        } else {
            $savedOrderNo = pur_order_generate_next_no($pdo, $orderDate);
            $pdo->prepare(
                'INSERT INTO pur_order (order_no, order_date, expected_date, supplier_id, warehouse_id,
                 reference_no, payment_type, subtotal, tax_amount, total, status, notes, created_by, amount_decimals)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $savedOrderNo,
                $orderDate,
                $expectedDate !== '' ? $expectedDate : null,
                $supplierId,
                $whFinal,
                $referenceNo,
                $paymentType,
                company_round_amount($sumSub, $pdo, $amountDecimals),
                company_round_amount($sumTax, $pdo, $amountDecimals),
                company_round_amount($sumGross, $pdo, $amountDecimals),
                $newStatus,
                $notes !== '' ? $notes : null,
                $uid,
                $amountDecimals,
            ]);
            $orderId = (int) $pdo->lastInsertId();
            pur_order_replace_lines($pdo, $orderId, $lines, $amountDecimals);
            pur_order_update_totals($pdo, $orderId, $amountDecimals);
            pur_order_set_discount_input($pdo, $orderId, $headerDiscount !== '' ? $headerDiscount : null);
            $pdo->commit();
            if (!$wantsJson) {
                flash_set('success', 'تم حفظ طلب الشراء.');
            }
        }

        if ($savedOrderNo === '') {
            $stNo = $pdo->prepare('SELECT order_no FROM pur_order WHERE id = ? LIMIT 1');
            $stNo->execute([$orderId]);
            $savedOrderNo = (string) $stNo->fetchColumn();
        }

        if ($wantsJson) {
            json_invoice_save_response(true, [
                'order_id' => $orderId,
                'order_no' => $savedOrderNo,
                'invoice_id' => $orderId,
                'invoice_no' => $savedOrderNo,
            ]);
        }

        require_once app_path('includes/nav_helpers.php');
        redirect(app_url('index.php?r=purchase_orders&id=' . $orderId . nav_hub_query_for_redirect()));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $msg = 'تعذر الحفظ.';
        if ($wantsJson) {
            json_invoice_save_response(false, ['message' => $msg . ' ' . $e->getMessage()], 500);
        }
        flash_set('error', $msg);
        redirect(app_url('index.php?r=purchase_orders'));
    }
}
