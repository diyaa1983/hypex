<?php
declare(strict_types=1);

require_once app_path('includes/pur_return_schema.php');
require_once app_path('includes/pur_invoice_post.php');
require_once app_path('includes/pur_return_invoice_lines.php');
require_once app_path('includes/sal_return_invoices.php');

/** @return array<string, mixed>|null */
function pur_return_fetch_invoice(PDO $pdo, int $invoiceId): ?array
{
    try {
        $st = $pdo->prepare(
            'SELECT id, supplier_id, warehouse_id, status, invoice_no, payment_type FROM pur_invoice WHERE id = ? LIMIT 1'
        );
        $st->execute([$invoiceId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $st = $pdo->prepare(
            'SELECT id, supplier_id, warehouse_id, status, invoice_no FROM pur_invoice WHERE id = ? LIMIT 1'
        );
        $st->execute([$invoiceId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $row['payment_type'] = 'credit';
        }
    }

    return is_array($row) ? $row : null;
}

function handle_purchase_return_post(): void
{
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        flash_set('error', 'انتهت صلاحية الجلسة.');
        redirect(app_url('index.php?r=purchase_returns'));
    }

    $pdo = db();
    if (!pur_return_ensure_schema($pdo)) {
        flash_set('error', 'نفّذ ملف الترحيل: database/migrations/015_purchase_returns_supplier_ledger.sql');
        redirect(app_url('index.php?r=purchase_returns'));
    }

    $returnDate = parse_date_to_iso(trim((string) ($_POST['return_date'] ?? ''))) ?? '';
    $supplierId = (int) ($_POST['supplier_id'] ?? 0);
    $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $lines = json_decode((string) ($_POST['lines_json'] ?? '[]'), true);

    $err = '';
    if ($returnDate === '') {
        $err = 'تاريخ الإرجاع غير صالح.';
    } elseif ($supplierId < 1) {
        $err = 'اختر المورد.';
    } elseif ($invoiceId < 1) {
        $err = 'اختر فاتورة الشراء.';
    } elseif (!is_array($lines) || count($lines) < 1) {
        $err = 'أدخل كمية إرجاع لمادة واحدة على الأقل.';
    }

    $invoice = pur_return_fetch_invoice($pdo, $invoiceId);
    if (!$err && !$invoice) {
        $err = 'فاتورة الشراء غير موجودة.';
    } elseif (!$err && (int) $invoice['supplier_id'] !== $supplierId) {
        $err = 'الفاتورة لا تخص المورد المختار.';
    } elseif (!$err && (string) $invoice['status'] !== 'confirmed') {
        $err = 'لا يمكن إرجاع فاتورة غير مؤكدة.';
    } elseif (!$err && !pur_invoice_is_posted($pdo, $invoiceId)) {
        $err = 'لا يمكن إرجاع فاتورة شراء غير مرحّلة. رحّل الفاتورة أولاً من «ترحيل فواتير الشراء».';
    }

    $validLines = [];
    if (!$err) {
        foreach ($lines as $ln) {
            if (!is_array($ln)) {
                $err = 'بيانات الأسطر غير صالحة.';
                break;
            }
            $lineId = (int) ($ln['invoice_line_id'] ?? 0);
            $qty = (float) ($ln['qty'] ?? 0);
            if ($lineId < 1 || $qty <= 0) {
                continue;
            }
            $validLines[] = $ln;
        }
        if (!$err && $validLines === []) {
            $err = 'أدخل كمية إرجاع لمادة واحدة على الأقل.';
        }
    }

    $checkedLines = [];
    if (!$err) {
        foreach ($validLines as $ln) {
            $lineId = (int) $ln['invoice_line_id'];
            $qty = (float) $ln['qty'];

            $st = $pdo->prepare(
                'SELECT il.id, il.item_id, il.qty AS qty_sold, il.unit_price, COALESCE(il.tax_rate_percent, 0) AS tax_rate_percent,
                        COALESCE(SUM(rl.qty), 0) AS qty_returned
                 FROM pur_invoice_line il
                 LEFT JOIN pur_return_line rl ON rl.invoice_line_id = il.id
                 LEFT JOIN pur_return r ON r.id = rl.return_id AND r.status <> ?
                 WHERE il.id = ? AND il.invoice_id = ?
                 GROUP BY il.id'
            );
            $st->execute(['cancelled', $lineId, $invoiceId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $err = 'سطر فاتورة غير صالح.';
                break;
            }
            $remaining = (float) $row['qty_sold'] - (float) $row['qty_returned'];
            if ($qty > $remaining + 0.000001) {
                $err = 'كمية الإرجاع أكبر من الكمية المتبقية للمادة.';
                break;
            }
            $unitPrice = (float) $row['unit_price'];
            $taxRate = (float) $row['tax_rate_percent'];
            $amounts = sal_return_calc_line_amounts($qty, $unitPrice, $taxRate);
            $ln['item_id'] = (int) $row['item_id'];
            $ln['_unit_price'] = $unitPrice;
            $ln['_tax_rate'] = $taxRate;
            $ln['line_subtotal'] = $amounts['line_subtotal'];
            $ln['tax_amount'] = $amounts['tax_amount'];
            $ln['line_gross'] = $amounts['line_gross'];
            $checkedLines[] = $ln;
        }
        $validLines = $checkedLines;
    }

    if ($err !== '') {
        flash_set('error', $err);
        redirect(app_url('index.php?r=purchase_returns'));
    }

    try {
        $pdo->beginTransaction();

        $returnNo = pur_return_generate_next_no($pdo, $returnDate);

        $sumSub = 0.0;
        $sumTax = 0.0;
        $sumGross = 0.0;
        foreach ($validLines as $ln) {
            $sumSub += (float) ($ln['line_subtotal'] ?? 0);
            $sumTax += (float) ($ln['tax_amount'] ?? 0);
            $sumGross += (float) ($ln['line_gross'] ?? 0);
        }

        $uid = (int) (current_user()['id'] ?? 0) ?: null;
        $whId = isset($invoice['warehouse_id']) && $invoice['warehouse_id'] !== null
            ? (int) $invoice['warehouse_id']
            : null;
        if ($whId < 1) {
            $whId = null;
        }

        $pdo->prepare(
            'INSERT INTO pur_return (return_no, return_date, supplier_id, invoice_id, warehouse_id, subtotal, tax_amount, total, status, notes, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $returnNo,
            $returnDate,
            $supplierId,
            $invoiceId,
            $whId,
            round($sumSub, 6),
            round($sumTax, 6),
            round($sumGross, 6),
            'confirmed',
            $notes !== '' ? $notes : null,
            $uid,
        ]);

        $returnId = (int) $pdo->lastInsertId();

        $insLine = $pdo->prepare(
            'INSERT INTO pur_return_line (return_id, invoice_line_id, item_id, qty, unit_price, tax_rate_percent, line_subtotal, tax_amount, line_gross)
             VALUES (?,?,?,?,?,?,?,?,?)'
        );

        foreach ($validLines as $ln) {
            $insLine->execute([
                $returnId,
                (int) $ln['invoice_line_id'],
                (int) ($ln['item_id'] ?? 0),
                round((float) $ln['qty'], 6),
                round((float) ($ln['_unit_price'] ?? $ln['unit_price'] ?? 0), 6),
                round((float) ($ln['_tax_rate'] ?? $ln['tax_rate_percent'] ?? 0), 3),
                round((float) ($ln['line_subtotal'] ?? 0), 6),
                round((float) ($ln['tax_amount'] ?? 0), 6),
                round((float) ($ln['line_gross'] ?? 0), 6),
            ]);
        }

        $pdo->commit();
        flash_set(
            'success',
            'تم حفظ مردود المشتريات. رقم المردود: ' . $returnNo
            . ' — يمكنك ترحيله لاحقاً من «ترحيل مردودات المشتريات» أو زر الترحيل.'
        );
        require_once app_path('includes/nav_helpers.php');
        redirect(app_url('index.php?r=purchase_returns&id=' . $returnId . nav_hub_query_for_redirect()));
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash_set('error', 'تعذر حفظ مردود المشتريات.');
        redirect(app_url('index.php?r=purchase_returns'));
    }
}
