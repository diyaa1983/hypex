<?php
declare(strict_types=1);

require_once app_path('includes/einvoice_settings.php');
require_once app_path('includes/einvoice_ubl.php');
require_once app_path('includes/einvoice_schema.php');

/**
 * استخراج TaxInclusiveAmount من XML الموقَّع المُسَجَّل (للفواتير المُرسَلة سابقاً).
 * نَستخدمه كأدق مصدر لقيمة الفاتورة الأصلية المُسَجَّلة في JoFotara.
 */
function einvoice_extract_taxinclusive_from_signed(PDO $pdo, int $invoiceId): float
{
    if ($invoiceId < 1 || !einvoice_column_exists($pdo, 'sal_invoice', 'einv_signed_invoice')) {
        return 0.0;
    }
    try {
        $st = $pdo->prepare('SELECT einv_signed_invoice FROM sal_invoice WHERE id = ? LIMIT 1');
        $st->execute([$invoiceId]);
        $signed = (string) ($st->fetchColumn() ?: '');
    } catch (Throwable $e) {
        return 0.0;
    }
    if ($signed === '') {
        return 0.0;
    }
    // JoFotara قد تُعيد signed invoice كـ XML نصّي أو مُرمَّز Base64.
    if (!str_contains($signed, '<')) {
        $decoded = base64_decode($signed, true);
        if ($decoded !== false && str_contains($decoded, '<')) {
            $signed = $decoded;
        }
    }
    if (preg_match('/<cbc:TaxInclusiveAmount\s+[^>]*>([0-9.]+)<\/cbc:TaxInclusiveAmount>/i', $signed, $m)) {
        return (float) $m[1];
    }
    return 0.0;
}

/**
 * حساب TaxInclusiveAmount للفاتورة الأصلية بنفس صيغة UBL XML المُستخدمة في الإرسال للفوترة.
 * يَضمن أن DocumentDescription في BillingReference يُطابق ما تَتوقَّعه JoFotara بدقة.
 *
 * الصيغة (مَطابقة لـ einvoice_ubl_totals_sales):
 *   lineExt = round(qty * unit_price - itemDiscount, dp)
 *   lineTax = round(lineExt * rate / 100, dp)
 *   total = sum(lineExt) + sum(lineTax)
 */
function einvoice_compute_invoice_xml_total(PDO $pdo, int $invoiceId, int $dp = 3): float
{
    if ($invoiceId < 1) {
        return 0.0;
    }
    // نُثَبِّت 3 خانات لِيَتطابق مع XML المُرسَل (تَستخدم JoFotara 3 خانات للدينار).
    $dp = 3;

    try {
        $st = $pdo->prepare(
            'SELECT qty, unit_price, line_total, tax_rate_percent, tax_amount
             FROM sal_invoice_line WHERE invoice_id = ? ORDER BY id ASC'
        );
        $st->execute([$invoiceId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return 0.0;
    }

    $taxExclusive = 0.0;
    $taxAmount = 0.0;
    foreach ($rows as $row) {
        $qty = round((float) $row['qty'], 3);
        $unitPrice = round((float) $row['unit_price'], $dp);
        $lineSub = round((float) $row['line_total'], $dp);

        // نَستنتج خصم السطر من الفرق بين qty*unitPrice و line_total المخزَّن.
        // tolerance = 0.01 لتَجاهُل فُروقات تَقريب dp=2 الموروثة في الفواتير القديمة.
        $rawDiff = ($qty * $unitPrice) - $lineSub;
        $itemDiscount = $rawDiff > 0.01 ? round($rawDiff, $dp) : 0.0;

        $lineExt = round($qty * $unitPrice - $itemDiscount, $dp);
        if ($lineExt < 0) {
            $lineExt = 0.0;
        }
        $rate = (float) ($row['tax_rate_percent'] ?? 0);
        $lineTax = $rate > 0 ? round($lineExt * $rate / 100, $dp) : 0.0;

        $taxExclusive = round($taxExclusive + $lineExt, $dp);
        $taxAmount = round($taxAmount + $lineTax, $dp);
    }

    return round($taxExclusive + $taxAmount, $dp);
}

/**
 * هل أُرسل إشعار دائن (إرجاع) للفوترة لهذا المرتجع؟
 */
function einvoice_return_is_sent(PDO $pdo, int $returnId): bool
{
    if ($returnId < 1 || !einvoice_column_exists($pdo, 'sal_return', 'einv_qr')) {
        return false;
    }
    $st = $pdo->prepare('SELECT einv_qr FROM sal_return WHERE id = ? LIMIT 1');
    $st->execute([$returnId]);
    $qr = $st->fetchColumn();

    return is_string($qr) && trim($qr) !== '';
}

/** @return array<string, mixed>|null */
function einvoice_return_status_row(PDO $pdo, int $returnId): ?array
{
    if ($returnId < 1 || !einvoice_column_exists($pdo, 'sal_return', 'einv_qr')) {
        return null;
    }
    $st = $pdo->prepare(
        'SELECT invoice_uuid, einv_status, einv_results, einv_signed_invoice,
                einv_qr, einv_num, einv_inv_uuid, einv_sent_at, reason_return
         FROM sal_return WHERE id = ? LIMIT 1'
    );
    $st->execute([$returnId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * تحميل حمولة مرتجع البيع بشكل متوافق مع `einvoice_load_sale_payload`.
 *
 * @return array{biller:object, customer:object, inv:object, lines:list<object>, raw:array<string,mixed>}|null
 */
function einvoice_load_sale_return_payload(PDO $pdo, int $returnId): ?array
{
    // نَجلب i.einv_total_amount (إن وُجد) لاستخدامه كـ DocumentDescription في XML المرتجع،
    // بحيث يَتطابق مع القيمة الفعلية المُسَجَّلة للفاتورة الأصلية في JoFotara.
    $hasEinvTotal = einvoice_column_exists($pdo, 'sal_invoice', 'einv_total_amount');
    $einvTotalCol = $hasEinvTotal ? ', i.einv_total_amount AS orig_invoice_einv_total' : '';
    $st = $pdo->prepare(
        'SELECT r.*, c.name_ar AS customer_name, c.tax_number AS customer_vat,
                i.invoice_no AS orig_invoice_no, i.invoice_uuid AS orig_invoice_uuid,
                i.total AS orig_invoice_total, i.payment_type AS orig_payment_type' . $einvTotalCol . '
         FROM sal_return r
         INNER JOIN crm_customer c ON c.id = r.customer_id
         INNER JOIN sal_invoice  i ON i.id = r.invoice_id
         WHERE r.id = ? LIMIT 1'
    );
    $st->execute([$returnId]);
    $ret = $st->fetch(PDO::FETCH_ASSOC);
    if (!$ret) {
        return null;
    }

    // أعمدة سطر المرتجع (sal_return_line) مختلفة قليلاً عن sal_invoice_line.
    $lnSt = $pdo->prepare(
        'SELECT rl.qty, rl.unit_price, rl.tax_rate_percent, rl.tax_amount,
                rl.line_subtotal, rl.line_gross, rl.invoice_line_id,
                it.name_ar AS product_name,
                il.qty AS orig_qty_sold, il.line_total AS orig_line_total,
                COALESCE(il.discount_amount, 0) AS orig_discount_amount,
                il.discount_pct AS orig_discount_pct
         FROM sal_return_line rl
         INNER JOIN inv_item it ON it.id = rl.item_id
         LEFT JOIN sal_invoice_line il ON il.id = rl.invoice_line_id
         WHERE rl.return_id = ? ORDER BY rl.id ASC'
    );
    $lnSt->execute([$returnId]);
    $lines = [];
    $totalDisc = 0.0;
    $origInvId = (int) ($ret['invoice_id'] ?? 0);
    $origLineSeq = einvoice_invoice_line_seq_map($pdo, $origInvId);
    foreach ($lnSt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $qty = (float) $row['qty'];
        $unitPrice = (float) ($row['unit_price'] ?? 0);
        $taxAmt = (float) ($row['tax_amount'] ?? 0);
        $lineSub = (float) ($row['line_subtotal'] ?? 0);   // base بعد الخصم قبل الضريبة
        $lineGross = (float) ($row['line_gross'] ?? ($lineSub + $taxAmt));
        $rate = (float) ($row['tax_rate_percent'] ?? 0);
        $taxId = $rate <= 0.0001 ? 1 : 2;
        $origQtySold = (float) ($row['orig_qty_sold'] ?? 0);
        $origDiscAmt = (float) ($row['orig_discount_amount'] ?? 0);
        if ($origQtySold > 0.000001 && $origDiscAmt > 0.01) {
            $itemDiscount = round(($origDiscAmt / $origQtySold) * $qty, 3);
        } else {
            // استنتاج من الفرق بين qty*unit_price و line_subtotal (بعد خصم الفاتورة/البند)
            $diff = ($qty * $unitPrice) - $lineSub;
            $itemDiscount = $diff > 0.01 ? round($diff, 3) : 0.0;
        }
        $totalDisc += $itemDiscount;
        $invoiceLineId = (int) ($row['invoice_line_id'] ?? 0);
        $lines[] = (object) [
            'orig_line_no' => $origLineSeq[$invoiceLineId] ?? 0,
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'line_total' => $lineSub,
            'line_gross' => $lineGross,
            'subtotal' => $lineGross,
            'item_tax' => $taxAmt,
            'item_discount' => $itemDiscount,
            'product_name' => (string) ($row['product_name'] ?? ''),
            'tax' => einvoice_format_decimal($rate, 2) . '%',
            'tax_rate' => $rate,
            'tax_rate_id' => $taxId,
            'real_unit_price' => $qty > 0 ? $lineSub / $qty : 0,
        ];
    }

    $settings = einvoice_settings_get($pdo);
    $biller = (object) [
        'name' => (string) ($settings['company_name'] ?? ''),
        'company' => (string) ($settings['trade_name'] ?? $settings['company_name'] ?? ''),
        'vat_no' => (string) ($settings['vat_no'] ?? ''),
        'gst_no' => (string) ($settings['gst_no'] ?? ''),
    ];
    $customer = (object) [
        'name' => (string) ($ret['customer_name'] ?? ''),
        'vat_no' => (string) ($ret['customer_vat'] ?? ''),
    ];

    // دقة التقريب الفعلية للمرتجع (مماثلة لـ amount_decimals في الفاتورة)
    $retDecimals = isset($ret['amount_decimals']) ? max(0, min(3, (int) $ret['amount_decimals'])) : 3;

    // ترتيب اختيار قيمة DocumentDescription للفاتورة الأصلية:
    //   1) einv_total_amount المخزَّن وقت الإرسال (الأدق — نفس ما تَستلمه JoFotara)
    //   2) استخراج TaxInclusive من einv_signed_invoice (للفواتير المُرسَلة سابقاً)
    //   3) حساب TaxInclusiveAmount بنفس صيغة XML الحالية من البنود
    //   4) sal_invoice.total كملاذ أخير
    $origTotal = 0.0;
    if (isset($ret['orig_invoice_einv_total']) && (float) $ret['orig_invoice_einv_total'] > 0.0001) {
        $origTotal = (float) $ret['orig_invoice_einv_total'];
    }
    if ($origTotal <= 0.0001) {
        $origTotal = einvoice_extract_taxinclusive_from_signed($pdo, $origInvId);
    }
    if ($origTotal <= 0.0001) {
        $origTotal = einvoice_compute_invoice_xml_total($pdo, $origInvId, $retDecimals);
    }
    if ($origTotal <= 0.0001) {
        $origTotal = (float) ($ret['orig_invoice_total'] ?? 0);
    }

    $invObj = (object) [
        'reference_no' => (string) $ret['return_no'],
        'uuid' => (string) ($ret['invoice_uuid'] ?? ''),
        'date' => (string) $ret['return_date'],
        'payment_type' => (string) ($ret['orig_payment_type'] ?? 'cash'),
        'subtotal' => (float) ($ret['subtotal'] ?? 0),
        'grand_total' => (float) $ret['total'],
        'total_discount' => $totalDisc,
        'total_tax' => (float) ($ret['tax_amount'] ?? 0),
        'icv' => (string) ($ret['id'] ?? '1'),
        'amount_decimals' => $retDecimals,
        // علامات إشعار دائن:
        'is_credit_note' => true,
        'original_invoice_no' => (string) ($ret['orig_invoice_no'] ?? ''),
        'original_invoice_uuid' => (string) ($ret['orig_invoice_uuid'] ?? ''),
        'original_full_amount' => $origTotal,
        'return_reason' => (string) ($ret['reason_return'] ?? ($ret['notes'] ?? '')),
    ];

    return ['biller' => $biller, 'customer' => $customer, 'inv' => $invObj, 'lines' => $lines, 'raw' => $ret];
}

/**
 * إرسال إشعار دائن (إرجاع) للفوترة الإلكترونية.
 *
 * @return array{ok:bool, skipped:bool, error:?string, message:?string, http_code:?int, response:mixed}
 */
function einvoice_send_sale_return(PDO $pdo, int $returnId, string $reason = ''): array
{
    $out = [
        'ok' => false,
        'skipped' => false,
        'error' => null,
        'message' => null,
        'http_code' => null,
        'response' => null,
    ];
    einvoice_ensure_schema($pdo);

    if (einvoice_return_is_sent($pdo, $returnId)) {
        $out['ok'] = true;
        $out['skipped'] = true;
        $out['message'] = 'تم إرسال هذا الإرجاع مسبقًا.';

        return $out;
    }

    $settings = einvoice_settings_get($pdo);
    if ((int) ($settings['taxes_type'] ?? 0) < 1) {
        $out['error'] = 'حدد نوع الضريبة في إعدادات الفوترة.';

        return $out;
    }
    $valErrors = einvoice_settings_validation_errors($settings);
    if ($valErrors !== []) {
        $out['error'] = implode(' ', $valErrors);

        return $out;
    }

    // تحميل البيانات الأساسية للمرتجع.
    $stRet = $pdo->prepare('SELECT id, invoice_id, status FROM sal_return WHERE id = ? LIMIT 1');
    $stRet->execute([$returnId]);
    $retRow = $stRet->fetch(PDO::FETCH_ASSOC);
    if (!$retRow) {
        $out['error'] = 'المرتجع غير موجود.';

        return $out;
    }

    // الشرط الأساسي: الفاتورة الأصلية مُرسلة هنا، أو قبل نطاق المتابعة (أُرسلت عبر النظام السابق).
    $invoiceId = (int) $retRow['invoice_id'];
    require_once app_path('includes/sal_einvoice_tracking.php');
    if (!sal_einvoice_invoice_allows_return_send($pdo, $invoiceId)) {
        $out['error'] = 'لا يمكن إرسال الإرجاع للفوترة قبل إرسال الفاتورة الأصلية لها.';

        return $out;
    }

    require_once app_path('includes/sal_return_post.php');
    if (!sal_return_is_posted($pdo, $returnId)) {
        $out['error'] = 'يجب ترحيل المرتجع قبل إرساله للفوترة.';

        return $out;
    }

    // حفظ السبب إن أُرسل من المستخدم.
    $reasonClean = trim($reason);
    if ($reasonClean !== '') {
        try {
            $pdo->prepare('UPDATE sal_return SET reason_return = ? WHERE id = ?')->execute([$reasonClean, $returnId]);
        } catch (Throwable $e) {
            // ignore
        }
    }

    $payload = einvoice_load_sale_return_payload($pdo, $returnId);
    if ($payload === null) {
        $out['error'] = 'تعذر تحميل بيانات المرتجع.';

        return $out;
    }

    // التحقق من وجود سبب (إلزامي على JoFotara).
    if (trim((string) ($payload['inv']->return_reason ?? '')) === '') {
        $out['error'] = 'سبب الإرجاع مطلوب لإرسال إشعار دائن لنظام الفوترة.';

        return $out;
    }

    // UUID فريد للإرجاع.
    $uuid = trim((string) ($payload['raw']['invoice_uuid'] ?? ''));
    if ($uuid === '') {
        $uuid = einvoice_generate_uuid();
        try {
            $pdo->prepare('UPDATE sal_return SET invoice_uuid = ? WHERE id = ?')->execute([$uuid, $returnId]);
        } catch (Throwable $e) {
            // ignore
        }
        $payload['inv']->uuid = $uuid;
    }

    $code = einvoice_resolve_type_code($settings, (string) ($payload['raw']['orig_payment_type'] ?? 'cash'));
    $taxesType = (int) ($settings['taxes_type'] ?? 2);

    set_time_limit(300);
    $xml = einvoice_generate_ubl_invoice($payload['inv'], $payload['lines'], $payload['customer'], $payload['biller'], $code, $taxesType);

    // حفظ نسخة من XML للتشخيص.
    try {
        $logDir = app_path('logs');
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $logFile = $logDir . DIRECTORY_SEPARATOR . 'einvoice-return-last-' . $returnId . '.xml';
        @file_put_contents($logFile, $xml);
    } catch (Throwable $e) {
        // ignore
    }

    $encoded = base64_encode($xml);
    $result = einvoice_send_request_to_api($pdo, ['invoice' => $encoded], $returnId, 'sal_return');

    if (is_array($result) && isset($result['error'])) {
        $out['error'] = (string) $result['error'];
        $out['http_code'] = isset($result['http_code']) ? (int) $result['http_code'] : null;
        $out['response'] = $result['response'] ?? null;

        return $out;
    }

    if (!einvoice_return_is_sent($pdo, $returnId)) {
        $out['error'] = 'لم يُستلم رمز QR من نظام الفوترة. راجع einv_results.';

        return $out;
    }

    $out['ok'] = true;
    $row = einvoice_return_status_row($pdo, $returnId);
    $out['message'] = 'تم إرسال الإرجاع لنظام الفوترة بنجاح.'
        . ($row && !empty($row['einv_num']) ? ' رقم الإرجاع في الفوترة: ' . $row['einv_num'] : '');

    return $out;
}
