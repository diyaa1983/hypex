<?php
declare(strict_types=1);

function handle_sales_delivery_save(): void
{
    $wantsJson = request_wants_json_invoice_save();

    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        if ($wantsJson) {
            json_invoice_save_response(false, ['message' => 'انتهت صلاحية الجلسة.'], 403);
        }
        flash_set('error', 'انتهت صلاحية الجلسة.');
        redirect(app_url('index.php?r=sales_delivery'));
    }

    $pdo = db();
    require_once app_path('includes/sal_delivery_schema.php');
    if (!sal_delivery_ensure_schema($pdo)) {
        $msg = 'جدول سند التسليم غير موجود.';
        if ($wantsJson) {
            json_invoice_save_response(false, ['message' => $msg], 500);
        }
        flash_set('error', $msg);
        redirect(app_url('index.php?r=sales_delivery'));
    }
    require_once app_path('includes/acc_period_lock.php');

    $deliveryDate = parse_date_to_iso(trim((string) ($_POST['delivery_date'] ?? ''))) ?? '';
    $customerId = (int) ($_POST['customer_id'] ?? 0);
    $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $deliveryId = (int) ($_POST['delivery_id'] ?? 0);
    $lines = json_decode((string) ($_POST['lines_json'] ?? '[]'), true);

    $whCount = (int) $pdo->query('SELECT COUNT(*) FROM inv_warehouse WHERE is_active = 1')->fetchColumn();
    $whFinal = $whCount > 0 ? $warehouseId : null;

    $err = '';
    if ($deliveryDate === '') {
        $err = 'تاريخ السند غير صالح.';
    } elseif (($periodErr = acc_period_date_lock_error($pdo, $deliveryDate)) !== null) {
        $err = $periodErr;
    } elseif ($customerId < 1) {
        $err = 'اختر العميل.';
    } elseif ($whCount > 0 && $warehouseId < 1) {
        $err = 'اختر المستودع.';
    } elseif (!is_array($lines) || count($lines) < 1) {
        $err = 'أضف مادة واحدة على الأقل.';
    } else {
        $valid = 0;
        foreach ($lines as $ln) {
            if (!is_array($ln)) {
                $err = 'بيانات الأسطر غير صالحة.';
                break;
            }
            if ((int) ($ln['item_id'] ?? 0) < 1) {
                continue;
            }
            if ((float) ($ln['qty'] ?? 0) <= 0) {
                $err = 'تأكد من الكميات لكل مادة.';
                break;
            }
            $valid++;
        }
        if ($err === '' && $valid < 1) {
            $err = 'أضف مادة واحدة على الأقل.';
        }
    }

    if ($err !== '') {
        if ($wantsJson) {
            json_invoice_save_response(false, ['message' => $err], 400);
        }
        flash_set('error', $err);
        redirect(app_url('index.php?r=sales_delivery'));
    }

    $savedNo = '';

    try {
        $pdo->beginTransaction();
        $uid = (int) (current_user()['id'] ?? 0) ?: null;

        if ($deliveryId > 0) {
            if (sal_delivery_is_posted($pdo, $deliveryId)) {
                throw new RuntimeException('لا يمكن تعديل سند مرحّل.');
            }
            sal_delivery_update_header($pdo, $deliveryId, $deliveryDate, $customerId, $whFinal, $notes !== '' ? $notes : null);
            sal_delivery_replace_lines($pdo, $deliveryId, $lines);
        } else {
            $savedNo = sal_delivery_generate_next_no($pdo, $deliveryDate);
            $deliveryId = sal_delivery_insert_header(
                $pdo,
                $savedNo,
                $deliveryDate,
                $customerId,
                $whFinal,
                $notes !== '' ? $notes : null,
                $uid
            );
            sal_delivery_replace_lines($pdo, $deliveryId, $lines);
        }

        $pdo->commit();

        if ($savedNo === '') {
            $st = $pdo->prepare('SELECT delivery_no FROM sal_delivery WHERE id = ? LIMIT 1');
            $st->execute([$deliveryId]);
            $savedNo = (string) $st->fetchColumn();
        }

        require_once app_path('includes/sys_audit_log.php');
        sys_audit_log_sal_delivery($pdo, 'save', $deliveryId);

        if ($wantsJson) {
            json_invoice_save_response(true, [
                'delivery_id' => $deliveryId,
                'delivery_no' => $savedNo,
                'is_posted' => sal_delivery_is_posted($pdo, $deliveryId),
            ]);
        }

        flash_set('success', 'تم حفظ سند التسليم (بدون خصم مخزون — يُخصم عند الترحيل).');
        require_once app_path('includes/nav_helpers.php');
        redirect(app_url('index.php?r=sales_delivery&id=' . $deliveryId . nav_hub_query_for_redirect()));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $msg = $e->getMessage() !== '' ? $e->getMessage() : 'تعذر الحفظ.';
        if ($wantsJson) {
            json_invoice_save_response(false, ['message' => $msg], 500);
        }
        flash_set('error', $msg);
        redirect(app_url('index.php?r=sales_delivery'));
    }
}
