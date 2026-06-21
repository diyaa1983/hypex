<?php
declare(strict_types=1);

require_once app_path('includes/pur_order_schema.php');
require_once app_path('includes/pur_order_load.php');
require_once app_path('includes/pur_invoice_schema.php');

/**
 * تحويل طلب شراء معتمد إلى فاتورة شراء (غير مرحّلة).
 *
 * @return array{ok:bool, invoice_id?:int, invoice_no?:string, message?:string}
 */
function pur_order_convert_to_invoice(PDO $pdo, int $orderId): array
{
    if ($orderId < 1) {
        return ['ok' => false, 'message' => 'معرّف الطلب غير صالح.'];
    }

    pur_order_ensure_schema($pdo);
    pur_invoice_ensure_schema($pdo);

    $order = pur_order_fetch_by_id($pdo, $orderId);
    if (!$order) {
        return ['ok' => false, 'message' => 'طلب الشراء غير موجود.'];
    }

    $status = (string) ($order['status'] ?? '');
    if (!in_array($status, ['approved', 'partial'], true)) {
        return ['ok' => false, 'message' => 'يمكن تحويل الطلبات المعتمدة أو المنفَّذة جزئياً فقط.'];
    }

    $lines = $order['lines'] ?? [];
    if (!is_array($lines) || count($lines) < 1) {
        return ['ok' => false, 'message' => 'لا توجد بنود في الطلب.'];
    }

    $invoiceLines = [];
    foreach ($lines as $ln) {
        require_once app_path('includes/inv_invoice_line_qty.php');
        $ordered = inv_invoice_line_stock_qty_sum((float) ($ln['qty'] ?? 0), (float) ($ln['qty_extra'] ?? 0));
        $invoiced = (float) ($ln['qty_invoiced'] ?? 0);
        $remaining = $ordered - $invoiced;
        if ($remaining <= 0.000001) {
            continue;
        }
        $ratio = $ordered > 0 ? ($remaining / $ordered) : 1;
        $invoiceLines[] = [
            'item_id' => (int) ($ln['item_id'] ?? 0),
            'line_desc' => $ln['line_desc'] ?? null,
            'qty' => (float) ($ln['qty'] ?? 0) * $ratio,
            'qty_extra' => (float) ($ln['qty_extra'] ?? 0) * $ratio,
            'unit_price' => (float) ($ln['unit_price'] ?? 0),
            'discount_pct' => (float) ($ln['discount_pct'] ?? 0),
            'discount_amount' => (float) ($ln['discount_amount'] ?? 0) * $ratio,
            'line_total' => (float) ($ln['line_total'] ?? 0) * $ratio,
            'tax_rate_percent' => (float) ($ln['tax_rate_percent'] ?? 0),
            'tax_amount' => (float) ($ln['tax_amount'] ?? 0) * $ratio,
            'line_gross' => (float) ($ln['line_gross'] ?? 0) * $ratio,
            '_order_line_id' => (int) ($ln['line_id'] ?? 0),
            '_qty_to_invoice' => $remaining,
        ];
    }

    if (count($invoiceLines) < 1) {
        return ['ok' => false, 'message' => 'جميع بنود الطلب مُفوَّتة بالكامل.'];
    }

    require_once app_path('includes/invoice_amount_decimals.php');
    $amountDecimals = (int) ($order['amount_decimals'] ?? company_decimal_places($pdo));
    $invoiceLines = invoice_normalize_lines_array($invoiceLines, $amountDecimals);

    $sumSub = 0.0;
    $sumTax = 0.0;
    $sumGross = 0.0;
    foreach ($invoiceLines as $ln) {
        $sumSub += (float) ($ln['line_subtotal'] ?? $ln['line_total'] ?? 0);
        $sumTax += (float) ($ln['tax_amount'] ?? 0);
        $sumGross += (float) ($ln['line_gross'] ?? 0);
    }

    $invoiceDate = (string) ($order['order_date'] ?? date('Y-m-d'));
    $uid = (int) (current_user()['id'] ?? 0) ?: null;

    try {
        $pdo->beginTransaction();

        $invoiceNo = pur_invoice_generate_next_no($pdo, $invoiceDate);
        pur_invoice_insert_header(
            $pdo,
            $invoiceNo,
            $invoiceDate,
            (int) ($order['supplier_id'] ?? 0),
            isset($order['warehouse_id']) ? (int) $order['warehouse_id'] : null,
            (string) ($order['payment_type'] ?? 'credit'),
            $sumSub,
            $sumTax,
            $sumGross,
            'من طلب شراء ' . (string) ($order['order_no'] ?? ''),
            $uid,
            null
        );
        $invoiceId = (int) $pdo->lastInsertId();

        if (pur_invoice_has_order_id($pdo)) {
            $pdo->prepare('UPDATE pur_invoice SET order_id = ? WHERE id = ?')->execute([$orderId, $invoiceId]);
        }

        foreach ($invoiceLines as $ln) {
            pur_invoice_insert_line($pdo, $invoiceId, $ln, $amountDecimals);
            $orderLineId = (int) ($ln['_order_line_id'] ?? 0);
            $qtyInv = (float) ($ln['_qty_to_invoice'] ?? 0);
            if ($orderLineId > 0 && $qtyInv > 0) {
                $pdo->prepare(
                    'UPDATE pur_order_line SET qty_invoiced = qty_invoiced + ? WHERE id = ? AND order_id = ?'
                )->execute([$qtyInv, $orderLineId, $orderId]);
            }
        }

        pur_invoice_persist_normalized($pdo, $invoiceId, $amountDecimals);
        pur_order_recalc_fulfillment_status($pdo, $orderId);

        $pdo->commit();

        return ['ok' => true, 'invoice_id' => $invoiceId, 'invoice_no' => $invoiceNo];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'message' => 'تعذر التحويل: ' . $e->getMessage()];
    }
}
