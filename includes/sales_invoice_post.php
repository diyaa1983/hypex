<?php
declare(strict_types=1);

require_once app_path('includes/mobile_auth.php');

function handle_sales_invoice_post(): void
{
    $wantsJson = request_wants_json_invoice_save();

    if (!user_can_sales_invoices() && !user_can('m_sales_invoices')) {
        if ($wantsJson) {
            json_invoice_save_response(false, ['message' => 'ليس لديك صلاحية حفظ الفاتورة.'], 403);
        }
        http_response_code(403);
        exit('ليس لديك صلاحية حفظ الفاتورة.');
    }

    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        if ($wantsJson) {
            json_invoice_save_response(false, ['message' => 'انتهت صلاحية الجلسة.'], 403);
        }
        flash_set('error', 'انتهت صلاحية الجلسة.');
        redirect(sales_invoice_post_redirect_route());
    }

    $pdo = db();
    require_once app_path('includes/sal_invoice_schema.php');
    sal_invoice_ensure_schema($pdo);
    require_once app_path('includes/sal_invoice_post.php');
    require_once app_path('includes/crm_sales_rep_schema.php');
    require_once app_path('includes/acc_period_lock.php');

    $invoiceDate = parse_date_to_iso(trim((string) ($_POST['invoice_date'] ?? ''))) ?? '';
    $customerId = (int) ($_POST['customer_id'] ?? 0);
    $salesRepId = null;
    $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
    $paymentType = ($_POST['payment_type'] ?? 'credit') === 'credit' ? 'credit' : 'cash';
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
    $deliveryId = (int) ($_POST['delivery_id'] ?? 0);
    $lines = json_decode((string) ($_POST['lines_json'] ?? '[]'), true);

    require_once app_path('includes/warehouse_access.php');
    $issueWarehouses = wh_access_list_warehouses($pdo, 'issue');
    $whCount = count($issueWarehouses);
    $whFinal = $whCount > 0 ? $warehouseId : null;

    $err = '';
    if ($invoiceDate === '') {
        $err = 'تاريخ الفاتورة غير صالح.';
    } elseif (($periodErr = acc_period_date_lock_error($pdo, $invoiceDate)) !== null) {
        $err = $periodErr;
    } elseif ($customerId < 1) {
        $err = 'اختر العميل.';
    } elseif ($whCount > 0 && $warehouseId < 1) {
        $err = 'اختر المستودع.';
    }

    if ($err === '' && $whCount > 0 && $warehouseId > 0) {
        require_once app_path('includes/warehouse_access.php');
        if (!wh_access_can_issue($pdo, $warehouseId)) {
            $err = wh_access_deny_issue_message();
        }
    }

    if ($err === '' && !is_array($lines)) {
        $err = 'بيانات الأسطر غير صالحة.';
    } elseif ($err === '' && is_array($lines)) {
        require_once app_path('includes/inv_invoice_line_qty.php');
        $allowEmptyLines = $invoiceId > 0 && count($lines) < 1;
        $lineErr = inv_invoice_validate_save_lines($lines, $allowEmptyLines);
        if ($lineErr !== null) {
            $err = $lineErr;
        }
    }

    if ($err === '' && $deliveryId > 0) {
        require_once app_path('includes/sal_delivery_invoice_link.php');
        $linkCheck = sal_invoice_validate_delivery_link($pdo, $deliveryId, $customerId, $whFinal, $invoiceId);
        if (!$linkCheck['ok']) {
            $err = $linkCheck['error'] ?? 'ربط سند التسليم غير صالح.';
        }
    }

    if ($err === '' && $customerId > 0) {
        $repCheck = crm_customer_resolve_invoice_sales_rep($pdo, $customerId, (int) ($_POST['sales_rep_id'] ?? 0));
        if (!$repCheck['ok']) {
            $err = $repCheck['error'] ?? 'اختر مندوب المبيعات.';
        } else {
            $salesRepId = $repCheck['rep_id'];
        }
    }

    if ($err !== '') {
        if ($wantsJson) {
            json_invoice_save_response(false, ['message' => $err], 400);
        }
        flash_set('error', $err);
        redirect(sales_invoice_post_redirect_route());
    }
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
            require_once app_path('includes/einvoice_schema.php');
            require_once app_path('includes/einvoice_settings.php');
            if (einvoice_column_exists($pdo, 'sal_invoice', 'einv_qr') && einvoice_sale_is_sent($pdo, $invoiceId)) {
                throw new RuntimeException('لا يمكن تعديل فاتورة أُرسلت إلى نظام الفوترة.');
            }
            if (sal_invoice_is_posted($pdo, $invoiceId)) {
                throw new RuntimeException('لا يمكن تعديل فاتورة مرحّلة.');
            }
            sal_invoice_update_header_unposted(
                $pdo,
                $invoiceId,
                $invoiceDate,
                $customerId,
                $salesRepId,
                $whFinal,
                $paymentType,
                $sumSub,
                $sumTax,
                $sumGross,
                $notes !== '' ? $notes : null
            );
            sal_invoice_replace_lines($pdo, $invoiceId, $lines, $amountDecimals);
            sal_invoice_persist_normalized($pdo, $invoiceId, $amountDecimals);
            sal_invoice_set_invoice_discount_input($pdo, $invoiceId, $headerDiscount !== '' ? $headerDiscount : null);
            if ($deliveryId < 1) {
                $existingDeliveryId = sal_invoice_delivery_id($pdo, $invoiceId);
                if ($existingDeliveryId > 0) {
                    $deliveryId = $existingDeliveryId;
                }
            }
            sal_invoice_set_delivery_id($pdo, $invoiceId, $deliveryId > 0 ? $deliveryId : null);
            $pdo->commit();
            if (!$wantsJson) {
                flash_set('success', 'تم تحديث الفاتورة (غير مرحّلة). يمكنك ترحيلها لاحقًا من زر «ترحيل».');
            }
        } else {
            $savedInvoiceNo = sal_invoice_generate_next_no($pdo, $invoiceDate);

            sal_invoice_insert_header(
                $pdo,
                $savedInvoiceNo,
                $invoiceDate,
                $customerId,
                $salesRepId,
                $whFinal,
                $paymentType,
                $sumSub,
                $sumTax,
                $sumGross,
                $notes !== '' ? $notes : null,
                $uid
            );

            $invoiceId = (int) $pdo->lastInsertId();

            foreach ($lines as $ln) {
                sal_invoice_insert_line($pdo, $invoiceId, $ln, $amountDecimals);
            }
            sal_invoice_persist_normalized($pdo, $invoiceId, $amountDecimals);
            sal_invoice_set_invoice_discount_input($pdo, $invoiceId, $headerDiscount !== '' ? $headerDiscount : null);
            sal_invoice_set_delivery_id($pdo, $invoiceId, $deliveryId > 0 ? $deliveryId : null);

            $pdo->commit();
            if (!$wantsJson) {
                flash_set('success', 'تم حفظ الفاتورة (بدون أثر مالي أو مستودعي). يمكنك ترحيلها لاحقًا من زر «ترحيل» أو من قائمة الفواتير.');
            }
        }

        if ($savedInvoiceNo === '') {
            $stNo = $pdo->prepare('SELECT invoice_no FROM sal_invoice WHERE id = ? LIMIT 1');
            $stNo->execute([$invoiceId]);
            $savedInvoiceNo = (string) $stNo->fetchColumn();
        }

        require_once app_path('includes/sys_audit_log.php');
        sys_audit_log_sal_invoice($pdo, 'save', $invoiceId);

        $archiveUploaded = false;
        $archiveError = null;
        if ($invoiceId > 0 && isset($_FILES['archive_photo']) && is_array($_FILES['archive_photo'])) {
            require_once app_path('includes/mobile_invoice.php');
            $archiveError = sal_invoice_archive_upload_photo_from_request($pdo, $invoiceId, $_FILES['archive_photo']);
            $archiveUploaded = $archiveError === null;
        }

        if ($wantsJson) {
            $payload = [
                'invoice_id' => $invoiceId,
                'invoice_no' => $savedInvoiceNo,
                'amount_decimals' => $amountDecimals,
            ];
            if ($archiveUploaded) {
                $payload['archive_uploaded'] = true;
            } elseif ($archiveError !== null) {
                $payload['archive_error'] = $archiveError;
            }
            json_invoice_save_response(true, $payload);
        }

        require_once app_path('includes/mobile_auth.php');
        if (app_request_from_mobile_app()) {
            flash_set('success', 'تم حفظ الفاتورة رقم ' . $savedInvoiceNo . ' (غير مرحّلة).');
            redirect(mobile_url('r=m_sales_invoice_view&id=' . $invoiceId));
        }
        require_once app_path('includes/nav_helpers.php');
        redirect(app_url('index.php?r=sales_invoices&id=' . $invoiceId . nav_hub_query_for_redirect()));
    } catch (Throwable $e) {
        $pdo->rollBack();
        $detail = $e->getMessage();
        $msg = 'تعذر الحفظ.';
        if (strpos($detail, 'Unknown column') !== false) {
            $msg = 'تعذر تحديث قاعدة البيانات تلقائيًا. نفّذ من phpMyAdmin: database/migrations/002_sales_invoice_enhancements.sql و 010_sales_rep_links.sql — ' . $detail;
        } elseif (strpos($detail, "doesn't exist") !== false || strpos($detail, 'Unknown table') !== false || strpos($detail, 'Base table') !== false) {
            $msg .= ' جداول الفاتورة أو الجداول المرتبطة غير موجودة؛ حدّث الصفحة بعد تشغيل التطبيق أو استورد database/schema.sql.';
        } elseif (strpos($detail, 'foreign key') !== false || strpos($detail, 'Cannot add or update') !== false || strpos($detail, '1452') !== false) {
            $msg .= ' تحقق من أن العميل والمواد والمستودع موجودون ومفعّلون.';
        } elseif ($detail !== '') {
            $msg .= ' (' . $detail . ')';
        }
        if ($wantsJson) {
            json_invoice_save_response(false, ['message' => $msg], 500);
        }
        flash_set('error', $msg);
        redirect(sales_invoice_post_redirect_route());
    }
}

function sales_invoice_post_redirect_route(): string
{
    require_once app_path('includes/mobile_auth.php');
    if (app_request_from_mobile_app()) {
        return mobile_url('r=m_sales_invoices');
    }

    return app_url('index.php?r=sales_invoices');
}
