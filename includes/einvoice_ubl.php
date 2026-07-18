<?php
declare(strict_types=1);

require_once app_path('includes/einvoice_settings.php');

function einvoice_format_decimal(float|string $n, int $decimals = 3): string
{
    return number_format((float) $n, $decimals, '.', '');
}

function einvoice_generate_uuid(): string
{
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0x0fff) | 0x4000,
        random_int(0, 0x3fff) | 0x8000,
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0xffff)
    );
}

/**
 * ترتيب بنود الفاتورة الأصلية كما أُرسِلت للفوترة (1، 2، 3… حسب id ASC).
 *
 * @return array<int, int> invoice_line_id => seq (1-based)
 */
function einvoice_invoice_line_seq_map(PDO $pdo, int $invoiceId): array
{
    if ($invoiceId < 1) {
        return [];
    }
    $st = $pdo->prepare('SELECT id FROM sal_invoice_line WHERE invoice_id = ? ORDER BY id ASC');
    $st->execute([$invoiceId]);
    $map = [];
    $seq = 1;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $lineId) {
        $map[(int) $lineId] = $seq++;
    }

    return $map;
}

/** @return array{biller:object, customer:object, inv:array, lines:list<array>} */
function einvoice_load_sale_payload(PDO $pdo, int $invoiceId): ?array
{
    $st = $pdo->prepare(
        'SELECT i.*, c.name_ar AS customer_name, c.tax_number AS customer_vat
         FROM sal_invoice i
         INNER JOIN crm_customer c ON c.id = i.customer_id
         WHERE i.id = ? LIMIT 1'
    );
    $st->execute([$invoiceId]);
    $inv = $st->fetch(PDO::FETCH_ASSOC);
    if (!$inv) {
        return null;
    }

    $hasLineTax = einvoice_column_exists($pdo, 'sal_invoice_line', 'tax_rate_percent');
    $hasDiscPct = einvoice_column_exists($pdo, 'sal_invoice_line', 'discount_pct');
    $cols = 'il.qty, il.unit_price, il.line_desc, il.line_total, i.name_ar AS product_name';
    if ($hasDiscPct) {
        $cols .= ', COALESCE(il.discount_pct, 0) AS discount_pct';
    }
    if (einvoice_column_exists($pdo, 'sal_invoice_line', 'discount_amount')) {
        $cols .= ', COALESCE(il.discount_amount, 0) AS discount_amount';
    }
    if (einvoice_column_exists($pdo, 'sal_invoice_line', 'qty_extra')) {
        $cols .= ', COALESCE(il.qty_extra, 0) AS qty_extra';
    }
    if ($hasLineTax) {
        $cols .= ', il.tax_rate_percent, il.tax_amount, il.line_gross';
    }
    $lnSt = $pdo->prepare(
        "SELECT {$cols} FROM sal_invoice_line il
         INNER JOIN inv_item i ON i.id = il.item_id
         WHERE il.invoice_id = ? ORDER BY il.id ASC"
    );
    $lnSt->execute([$invoiceId]);
    $lines = [];
    $totalDisc = 0.0;
    foreach ($lnSt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $qty = (float) $row['qty'];
        $qtyExtra = (float) ($row['qty_extra'] ?? 0);
        $unitPrice = (float) ($row['unit_price'] ?? 0);
        // كمية الفوترة: الكمية الإضافية عند كمية صفر (هدايا/عينات بإجمالي صفر).
        $einvoiceQty = $qty > 0.000001 ? $qty : $qtyExtra;
        $isBonusStockLine = $qty <= 0.000001 && $qtyExtra > 0.000001;
        $lineGross = (float) ($row['line_gross'] ?? $row['line_total'] ?? 0);
        $taxAmt = (float) ($row['tax_amount'] ?? 0);
        $lineTotal = (float) ($row['line_total'] ?? ($lineGross - $taxAmt));   // base بعد الخصم قبل الضريبة
        $rate = (float) ($row['tax_rate_percent'] ?? 0);
        $taxId = $rate <= 0.0001 ? 1 : 2;
        // خصم السطر: نُفضّل الحقول الصريحة (discount_amount أو discount_pct)
        // ولا نستنتج الخصم من الفرق بين qty*unit_price و line_total لأن هذا الفرق
        // قد يكون مجرد تقريب رقمي (≤ 0.005) وليس خصمًا فعليًا، مما يُربك JoFotara
        // في معادلة: TaxExclusive = sum(LineExtension) - AllowanceTotal.
        $discountFromCols = (float) ($row['discount_amount'] ?? 0);
        $discountFromPct = isset($row['discount_pct']) ? round(($qty * $unitPrice) * ((float) $row['discount_pct'] / 100), 3) : 0.0;
        if ($isBonusStockLine) {
            $itemDiscount = 0.0;
            $lineTotal = 0.0;
            $lineGross = 0.0;
            $taxAmt = 0.0;
            $unitPriceForEinv = 0.0;
        } elseif ($discountFromCols > 0.0001) {
            $itemDiscount = $discountFromCols;
            $unitPriceForEinv = $unitPrice;
        } elseif ($discountFromPct > 0.0001) {
            $itemDiscount = $discountFromPct;
            $unitPriceForEinv = $unitPrice;
        } else {
            $itemDiscount = 0.0;
            $unitPriceForEinv = $unitPrice;
        }
        $totalDisc += $itemDiscount;
        $lines[] = (object) [
            'quantity' => $einvoiceQty,
            'unit_price' => $unitPriceForEinv,
            'line_total' => $lineTotal,                 // LineExtensionAmount (بعد الخصم، قبل الضريبة)
            'line_gross' => $lineGross,                 // RoundingAmount (بعد الخصم + الضريبة)
            'subtotal' => $lineGross,                   // للتوافق مع الكود القديم
            'item_tax' => $taxAmt,
            'item_discount' => $itemDiscount,
            'product_name' => (string) ($row['product_name'] ?? $row['line_desc'] ?? ''),
            'tax' => einvoice_format_decimal($rate, 2) . '%',
            'tax_rate' => $rate,
            'tax_rate_id' => $taxId,
            'real_unit_price' => $qty > 0 ? $lineTotal / $qty : 0,
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
        'name' => (string) ($inv['customer_name'] ?? ''),
        'vat_no' => (string) ($inv['customer_vat'] ?? ''),
    ];

    // عدد الخانات العشرية الفعلية للفاتورة (0..8). إذا لم يوجد العمود، نستخدم 3
    // لأن JoFotara نفسه يستخدم 3 خانات. هذا التعريف يضمن أن XML يَستخدم نفس
    // التقريب الذي يظهره النظام، فيتطابق المجموع تمامًا مع ما يخزّنه النظام.
    $invDecimals = isset($inv['amount_decimals']) ? max(0, min(3, (int) $inv['amount_decimals'])) : 3;
    $invObj = (object) [
        'reference_no' => (string) $inv['invoice_no'],
        'uuid' => (string) ($inv['invoice_uuid'] ?? ''),
        'date' => (string) $inv['invoice_date'],
        'payment_type' => (string) ($inv['payment_type'] ?? 'cash'),
        'subtotal' => (float) ($inv['subtotal'] ?? 0),
        'grand_total' => (float) $inv['total'],
        'total_discount' => $totalDisc,
        'total_tax' => (float) ($inv['tax_amount'] ?? 0),
        'icv' => (string) ($inv['id'] ?? '1'),
        'amount_decimals' => $invDecimals,
    ];

    return ['biller' => $biller, 'customer' => $customer, 'inv' => $invObj, 'lines' => $lines, 'raw' => $inv];
}

function einvoice_ubl_supplier_party(object $biller): string
{
  $trade = ($biller->company ?? '') !== '' && ($biller->company ?? '') !== '-' ? $biller->company : $biller->name;

    return '<cac:AccountingSupplierParty><cac:Party><cac:PostalAddress><cac:Country><cbc:IdentificationCode>JO</cbc:IdentificationCode></cac:Country></cac:PostalAddress>'
        . '<cac:PartyTaxScheme><cbc:CompanyID>' . htmlspecialchars((string) ($biller->vat_no ?? ''), ENT_XML1) . '</cbc:CompanyID>'
        . '<cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:PartyTaxScheme>'
        . '<cac:PartyLegalEntity><cbc:RegistrationName>' . htmlspecialchars((string) $trade, ENT_XML1) . '</cbc:RegistrationName></cac:PartyLegalEntity>'
        . '</cac:Party></cac:AccountingSupplierParty>';
}

function einvoice_ubl_customer_party(object $customer): string
{
    // أنواع تعريف العميل المقبولة في JoFotara: NIN (رقم وطني), PN (جواز سفر), TIN (رقم ضريبي).
    $vatNo = trim((string) ($customer->vat_no ?? ''));
    $name = trim((string) ($customer->name ?? ''));
    $scheme = $vatNo !== '' ? 'TIN' : 'NIN';
    $idValue = $vatNo !== '' ? $vatNo : '';

    $xml = '<cac:AccountingCustomerParty><cac:Party>';
    $xml .= '<cac:PartyIdentification><cbc:ID schemeID="' . $scheme . '">' . htmlspecialchars($idValue, ENT_XML1) . '</cbc:ID></cac:PartyIdentification>';
    if ($vatNo !== '') {
        $xml .= '<cac:PartyTaxScheme><cbc:CompanyID>' . htmlspecialchars($vatNo, ENT_XML1) . '</cbc:CompanyID>'
            . '<cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:PartyTaxScheme>';
    }
    if ($name !== '') {
        $xml .= '<cac:PartyLegalEntity><cbc:RegistrationName>' . htmlspecialchars($name, ENT_XML1) . '</cbc:RegistrationName></cac:PartyLegalEntity>';
    }
    $xml .= '</cac:Party></cac:AccountingCustomerParty>';

    return $xml;
}

function einvoice_ubl_seller_party(object $biller): string
{
    return '<cac:SellerSupplierParty><cac:Party><cac:PartyIdentification><cbc:ID>'
        . htmlspecialchars((string) ($biller->gst_no ?? ''), ENT_XML1)
        . '</cbc:ID></cac:PartyIdentification></cac:Party></cac:SellerSupplierParty>';
}

/**
 * يولّد قسم الإجماليات بنفس بنية JoFotara/UBL2.1 المطلوبة:
 * - AllowanceCharge (اختياري على مستوى الفاتورة عند وجود خصم كلي)
 * - TaxTotal بدون TaxSubtotal على مستوى الفاتورة
 * - LegalMonetaryTotal بدون LineExtensionAmount
 * @param list<object> $items
 */
function einvoice_ubl_totals_sales(object $inv, array $items): string
{
    // المجاميع تُحسب من البنود بنفس الصيغة المستخدمة في XML السطور لضمان تطابق JoFotara.
    // JoFotara تتحقق من invariants صارمة:
    //   - TaxAmount(header) == Sum(line.TaxAmount)
    //   - TaxExclusiveAmount == Sum(line.LineExtensionAmount) - AllowanceTotal
    //   - line.TaxAmount == round(line.LineExtensionAmount * rate / 100, dp)
    //   - TaxAmount(TaxSubtotal) ≈ round(TaxableAmount * Percent / 100, dp)  ← الأهم
    // لذا لا نُجبر القيم لتطابق DB (يَكسر الـ invariants)، بل نُحسب lineTax من lineExt مباشرة.
    //
    // ملاحظة مُهمَّة: نُثَبِّت دقة 3 خانات في XML بَغض النظر عن amount_decimals
    // (الدينار الأردني يَستخدم 3 خانات داخلياً، و JoFotara تَرفض dp=2 لأنه يَكسر
    //  invariant generalInvoiceCalculations مَع نِسَب ضريبة مثل 5%).
    $dp = 3;
    $taxExclusive = 0.0;
    $taxAmount = 0.0;
    // تجميع الـ TaxSubtotals حسب نسبة الضريبة (تَحتاج JoFotara TaxSubtotal لكل rate في رأس الفاتورة).
    $taxByRate = [];

    foreach ($items as $line) {
        $qty = round((float) ($line->quantity ?? 0), 3);
        $unitPrice = round((float) ($line->unit_price ?? 0), $dp);
        $lineDiscount = round((float) ($line->item_discount ?? 0), $dp);
        $lineExt = round($qty * $unitPrice - $lineDiscount, $dp);
        if ($lineExt < 0) {
            $lineExt = 0.0;
        }
        // lineTax مُعاد حسابه من lineExt كي يَتطابق مع توقّع JoFotara بدقة:
        //   lineTax = round(lineExt * rate / 100, dp)
        // بدلاً من قراءة item_tax المخزَّن (قد يَختلف بسبب rounding مختلف).
        $rate = (float) ($line->tax_rate ?? 0);
        $lineTax = $rate > 0 ? round($lineExt * $rate / 100, $dp) : 0.0;

        $taxExclusive = round($taxExclusive + $lineExt, $dp);
        $taxAmount = round($taxAmount + $lineTax, $dp);

        $rateKey = number_format($rate, 3, '.', '');
        if (!isset($taxByRate[$rateKey])) {
            $taxByRate[$rateKey] = ['rate' => $rate, 'taxable' => 0.0, 'tax' => 0.0];
        }
        $taxByRate[$rateKey]['taxable'] = round($taxByRate[$rateKey]['taxable'] + $lineExt, $dp);
        $taxByRate[$rateKey]['tax'] = round($taxByRate[$rateKey]['tax'] + $lineTax, $dp);
    }

    $taxInclusive = round($taxExclusive + $taxAmount, $dp);
    $payable = $taxInclusive;

    // ملاحظة هامّة: لا نُولّد AllowanceCharge ولا AllowanceTotalAmount > 0 على مستوى الفاتورة،
    // لأن خصومات السطور (item_discount) مَطروحة بالفعل داخل LineExtensionAmount لكل سطر.
    // وضع AllowanceTotalAmount > 0 سيَكسر الـ invariant:
    //   TaxExclusiveAmount = Sum(line.LineExtensionAmount) - AllowanceTotal
    //   ⇒ JoFotara تَرفض بـ "Error in general tax amount calculation".
    $sumLineExt = $taxExclusive;

    // TaxTotal على مستوى الفاتورة مع TaxSubtotal لكل نسبة ضريبة.
    $xml = '<cac:TaxTotal>'
        . '<cbc:TaxAmount currencyID="JOD">' . einvoice_format_decimal($taxAmount) . '</cbc:TaxAmount>';
    foreach ($taxByRate as $bucket) {
        $rate = (float) $bucket['rate'];
        $taxableAmt = (float) $bucket['taxable'];
        $taxAmt = (float) $bucket['tax'];
        $taxId = ($taxAmt <= 0.0001 || $rate <= 0.0001) ? 'Z' : 'S';
        $xml .= '<cac:TaxSubtotal>'
            . '<cbc:TaxableAmount currencyID="JOD">' . einvoice_format_decimal($taxableAmt) . '</cbc:TaxableAmount>'
            . '<cbc:TaxAmount currencyID="JOD">' . einvoice_format_decimal($taxAmt) . '</cbc:TaxAmount>'
            . '<cac:TaxCategory>'
            . '<cbc:ID schemeAgencyID="6" schemeID="UN/ECE 5305">' . $taxId . '</cbc:ID>'
            . '<cbc:Percent>' . einvoice_format_decimal($rate > 0 ? $rate : 0.0) . '</cbc:Percent>'
            . '<cac:TaxScheme><cbc:ID schemeAgencyID="6" schemeID="UN/ECE 5153">VAT</cbc:ID></cac:TaxScheme>'
            . '</cac:TaxCategory>'
            . '</cac:TaxSubtotal>';
    }
    $xml .= '</cac:TaxTotal>';

    $xml .= '<cac:LegalMonetaryTotal>'
        . '<cbc:LineExtensionAmount currencyID="JOD">' . einvoice_format_decimal($sumLineExt) . '</cbc:LineExtensionAmount>'
        . '<cbc:TaxExclusiveAmount currencyID="JOD">' . einvoice_format_decimal($taxExclusive) . '</cbc:TaxExclusiveAmount>'
        . '<cbc:TaxInclusiveAmount currencyID="JOD">' . einvoice_format_decimal($taxInclusive) . '</cbc:TaxInclusiveAmount>'
        . '<cbc:AllowanceTotalAmount currencyID="JOD">' . einvoice_format_decimal(0) . '</cbc:AllowanceTotalAmount>'
        . '<cbc:PayableAmount currencyID="JOD">' . einvoice_format_decimal($payable) . '</cbc:PayableAmount>'
        . '</cac:LegalMonetaryTotal>';

    return $xml;
}

/**
 * يولّد سطور الفاتورة بنفس بنية JoFotara/UBL2.1.
 * نقرّب جميع المبالغ بدقة الفاتورة (amount_decimals) لضمان توافق مجموع XML
 * مع ما يَعرضه النظام (لا تظهر قيم مثل 216.014 على الفوترة بينما النظام يَعرض 216).
 * @param list<object> $items
 */
function einvoice_ubl_lines_sales(array $items, int $dp = 3): string
{
    // نُثَبِّت دقة 3 خانات في XML بَغض النظر عما يُمَرَّر، لأن JoFotara تَستخدم 3 خانات
    // للدينار الأردني داخلياً، وَأي تَقليل لـ dp (مثل 2) يَكسر invariant:
    //   TaxAmount(TaxSubtotal) = round(TaxableAmount * Percent / 100, dp)
    // ويُسَبِّب خَطَأ "Error in general tax amount calculation".
    $dp = 3;
    $xml = '';
    $i = 1;
    foreach ($items as $line) {
        $qty = round(abs((float) $line->quantity), 3);
        $unitPrice = round(abs((float) ($line->unit_price ?? 0)), $dp);
        $lineDiscount = round(abs((float) ($line->item_discount ?? 0)), $dp);
        // LineExtensionAmount يجب أن يطابق ما يحسبه JoFotara:
        //   LineExtensionAmount = qty * unit_price - line_discount
        // ولا نأخذها من line_total المخزن لتفادي فروق التقريب بين القيم المخزنة والمُرسلة.
        $lineExt = round($qty * $unitPrice - $lineDiscount, $dp);
        if ($lineExt < 0) {
            $lineExt = 0.0;
        }
        $rate = (float) ($line->tax_rate ?? 0);
        // lineTax مُعاد حسابه من lineExt كي تَتطابق invariants JoFotara:
        //   lineTax (XML) == round(lineExt * rate / 100, dp)
        // ومجموع line tax = TaxAmount (header) بدون فروقات.
        $lineTax = $rate > 0 ? round($lineExt * $rate / 100, $dp) : 0.0;
        $lineGross = round($lineExt + $lineTax, $dp);

        // تحديد فئة الضريبة:
        // S = قياسي 16% أو نسبة موجبة
        // Z = معفى (0%) — في الكود القديم tax_rate_id=1 = صفر
        $taxId = ($lineTax <= 0.0001 || $rate <= 0.0001) ? 'Z' : 'S';
        $ratePercent = $rate > 0 ? $rate : 0.0;

        $lineId = (int) ($line->orig_line_no ?? 0);
        if ($lineId < 1) {
            $lineId = $i;
        }

        $xml .= '<cac:InvoiceLine>'
            . '<cbc:ID>' . $lineId . '</cbc:ID>'
            . '<cbc:InvoicedQuantity unitCode="PCE">' . einvoice_format_decimal($qty) . '</cbc:InvoicedQuantity>'
            . '<cbc:LineExtensionAmount currencyID="JOD">' . einvoice_format_decimal($lineExt) . '</cbc:LineExtensionAmount>'
            . '<cac:TaxTotal>'
            . '<cbc:TaxAmount currencyID="JOD">' . einvoice_format_decimal($lineTax) . '</cbc:TaxAmount>'
            . '<cbc:RoundingAmount currencyID="JOD">' . einvoice_format_decimal($lineGross) . '</cbc:RoundingAmount>'
            . '<cac:TaxSubtotal>'
            . '<cbc:TaxAmount currencyID="JOD">' . einvoice_format_decimal($lineTax) . '</cbc:TaxAmount>'
            . '<cac:TaxCategory>'
            . '<cbc:ID schemeAgencyID="6" schemeID="UN/ECE 5305">' . $taxId . '</cbc:ID>'
            . '<cbc:Percent>' . einvoice_format_decimal($ratePercent) . '</cbc:Percent>'
            . '<cac:TaxScheme><cbc:ID schemeAgencyID="6" schemeID="UN/ECE 5153">VAT</cbc:ID></cac:TaxScheme>'
            . '</cac:TaxCategory>'
            . '</cac:TaxSubtotal>'
            . '</cac:TaxTotal>'
            . '<cac:Item><cbc:Name>' . htmlspecialchars((string) $line->product_name, ENT_XML1) . '</cbc:Name></cac:Item>'
            . '<cac:Price>'
            . '<cbc:PriceAmount currencyID="JOD">' . einvoice_format_decimal($unitPrice) . '</cbc:PriceAmount>'
            . '<cac:AllowanceCharge>'
            . '<cbc:ChargeIndicator>false</cbc:ChargeIndicator>'
            . '<cbc:AllowanceChargeReason>DISCOUNT</cbc:AllowanceChargeReason>'
            . '<cbc:Amount currencyID="JOD">' . einvoice_format_decimal($lineDiscount) . '</cbc:Amount>'
            . '</cac:AllowanceCharge>'
            . '</cac:Price>'
            . '</cac:InvoiceLine>';
        $i++;
    }

    return $xml;
}

/**
 * يولّد UBL لفاتورة مبيعات (388) أو إشعار دائن/إرجاع (381).
 *
 * يتم تفعيل إشعار دائن عند تمرير `$inv->is_credit_note = true`، ويُتوقع وجود:
 *   - $inv->original_invoice_no
 *   - $inv->original_invoice_uuid
 *   - $inv->original_full_amount
 *   - $inv->return_reason
 *
 * @param list<object> $items
 */
function einvoice_generate_ubl_invoice(object $inv, array $items, object $customer, object $biller, string $code, int $taxesType): string
{
    $uuid = ($inv->uuid ?? '') !== '' ? $inv->uuid : einvoice_generate_uuid();
    $inv->uuid = $uuid;
    $invoiceCode = trim($code);     // 011/012/013/021/022/023 — Payment Method + Invoice Type
    $isCreditNote = !empty($inv->is_credit_note);
    $invoiceTypeCode = $isCreditNote ? '381' : '388'; // 388 = فاتورة مبيعات, 381 = إشعار دائن (إرجاع)
    $icv = (string) ($inv->icv ?? '1');

    $xml = '<?xml version="1.0" encoding="UTF-8"?>';
    $xml .= '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2" xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2">';
    $xml .= '<cbc:UBLVersionID>2.1</cbc:UBLVersionID>';
    $xml .= '<cbc:ID>' . htmlspecialchars((string) $inv->reference_no, ENT_XML1) . '</cbc:ID>';
    $xml .= '<cbc:UUID>' . htmlspecialchars((string) $uuid, ENT_XML1) . '</cbc:UUID>';
    $issueDate = date('Y-m-d', strtotime((string) $inv->date) ?: time());
    $xml .= '<cbc:IssueDate>' . $issueDate . '</cbc:IssueDate>';
    $xml .= '<cbc:InvoiceTypeCode name="' . htmlspecialchars($invoiceCode, ENT_XML1) . '">' . $invoiceTypeCode . '</cbc:InvoiceTypeCode>';
    $invoiceNote = trim((string) ($inv->note ?? ''));
    if ($invoiceNote !== '') {
        $xml .= '<cbc:Note>' . htmlspecialchars($invoiceNote, ENT_XML1) . '</cbc:Note>';
    }
    $xml .= '<cbc:DocumentCurrencyCode>JOD</cbc:DocumentCurrencyCode>';
    $xml .= '<cbc:TaxCurrencyCode>JOD</cbc:TaxCurrencyCode>';
    if ($isCreditNote) {
        // BillingReference يربط إشعار الدائن بالفاتورة الأصلية في نظام الفوترة.
        // نَستخدم نَفس عدد الخانات العشرية المُستخدمة في XML (مَأخوذة من amount_decimals)
        // ليَتطابق DocumentDescription مع TaxInclusiveAmount المُسَجَّل في JoFotara بدقة.
        $origNo = (string) ($inv->original_invoice_no ?? '');
        $origUuid = (string) ($inv->original_invoice_uuid ?? '');
        $origFull = (float) ($inv->original_full_amount ?? 0);
        $xml .= '<cac:BillingReference><cac:InvoiceDocumentReference>'
            . '<cbc:ID>' . htmlspecialchars($origNo, ENT_XML1) . '</cbc:ID>'
            . '<cbc:UUID>' . htmlspecialchars($origUuid, ENT_XML1) . '</cbc:UUID>'
            . '<cbc:DocumentDescription>' . einvoice_format_decimal($origFull) . '</cbc:DocumentDescription>'
            . '</cac:InvoiceDocumentReference></cac:BillingReference>';
    }
    $xml .= '<cac:AdditionalDocumentReference><cbc:ID>ICV</cbc:ID><cbc:UUID>' . htmlspecialchars($icv, ENT_XML1) . '</cbc:UUID></cac:AdditionalDocumentReference>';
    $xml .= einvoice_ubl_supplier_party($biller);
    $xml .= einvoice_ubl_customer_party($customer);
    $xml .= einvoice_ubl_seller_party($biller);
    // سبب الإرجاع لإشعار الدائن: يُمرَّر عبر cac:PaymentMeans/InstructionNote حسب توصيات JoFotara.
    if ($isCreditNote) {
        $reason = trim((string) ($inv->return_reason ?? ''));
        if ($reason !== '') {
            $xml .= '<cac:PaymentMeans>'
                . '<cbc:PaymentMeansCode listID="UN/ECE 4461">10</cbc:PaymentMeansCode>'
                . '<cbc:InstructionNote>' . htmlspecialchars($reason, ENT_XML1) . '</cbc:InstructionNote>'
                . '</cac:PaymentMeans>';
        }
    }
    if ($taxesType === 2) {
        // نَستخدم 3 خانات عَشرية دائماً في XML (الدينار الأردني يَستخدم 3 خانات في JoFotara).
        $xml .= einvoice_ubl_totals_sales($inv, $items);
        $xml .= einvoice_ubl_lines_sales($items, 3);
    }
    $xml .= '</Invoice>';

    return $xml;
}

/**
 * @param array{invoice:string} $requestData
 * @return array<string, mixed>|false
 */
function einvoice_send_request_to_api(PDO $pdo, array $requestData, int $refId, string $table = 'sal_invoice'): array|false
{
    $settings = einvoice_settings_get($pdo);
    $apiUrl = rtrim((string) ($settings['jofotara_api_url'] ?? 'https://backend.jofotara.gov.jo/core/invoices/'), '/') . '/';
    $clientId = trim((string) ($settings['client_id'] ?? ''));
    $secretKey = trim((string) ($settings['secret_key'] ?? ''));
    if ($clientId === '' || $secretKey === '') {
        return ['error' => 'بيانات الاعتماد غير مكتملة.'];
    }

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($requestData, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Client-Id: ' . $clientId,
            'Secret-Key: ' . $secretKey,
        ],
        CURLOPT_CONNECTTIMEOUT => 60,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    $curlNo = curl_errno($ch);
    curl_close($ch);

    if ($curlNo) {
        $msg = 'cURL Error (' . $curlNo . '): ' . $curlErr;
        $pdo->prepare("UPDATE {$table} SET einv_results = ? WHERE id = ?")->execute([$msg, $refId]);

        return ['error' => $msg];
    }

    // عند HTTP 400/422: JoFotara عادة يرجّع JSON يحوي تفاصيل الخطأ — حاول استخراجها.
    if ($httpCode >= 400) {
        $parsed = json_decode((string) $response, true);
        $detail = einvoice_extract_jofotara_error(is_array($parsed) ? $parsed : null);
        $msg = $detail !== '' ? $detail : ('HTTP Error ' . $httpCode);
        $rawSave = is_string($response) && $response !== ''
            ? substr($response, 0, 4000)
            : ('HTTP ' . $httpCode);
        try {
            $pdo->prepare("UPDATE {$table} SET einv_results = ? WHERE id = ?")->execute([$rawSave, $refId]);
        } catch (Throwable $e) {
            // ignore — قد لا يكون العمود موجوداً في بعض الجداول
        }

        return [
            'error' => $msg,
            'http_code' => $httpCode,
            'response' => substr((string) $response, 0, 4000),
        ];
    }

    $res = json_decode((string) $response, true);
    if (!is_array($res)) {
        return ['error' => 'استجابة غير صالحة من نظام الفوترة.'];
    }

    // قد يأتي HTTP 200 ولكن مع EINV_STATUS='NOT_SUBMITTED' وأخطاء داخل EINV_RESULTS.
    $einvStatusEarly = (string) ($res['EINV_STATUS'] ?? '');
    if ($einvStatusEarly !== '' && $einvStatusEarly !== 'SUBMITTED') {
        $detail = einvoice_extract_jofotara_error($res);
        if ($detail !== '') {
            try {
                $pdo->prepare("UPDATE {$table} SET einv_results = ? WHERE id = ?")
                    ->execute([substr((string) $response, 0, 4000), $refId]);
            } catch (Throwable $e) {
                // ignore
            }

            return [
                'error' => $detail,
                'http_code' => $httpCode,
                'response' => substr((string) $response, 0, 4000),
            ];
        }
    }

    $update = [
        'einv_results' => is_array($res['EINV_RESULTS'] ?? null) ? ($res['EINV_RESULTS']['status'] ?? json_encode($res['EINV_RESULTS'], JSON_UNESCAPED_UNICODE)) : (string) ($res['EINV_RESULTS'] ?? ''),
        'einv_status' => (string) ($res['EINV_STATUS'] ?? ''),
        'einv_signed_invoice' => (string) ($res['EINV_SINGED_INVOICE'] ?? $res['EINV_SIGNED_INVOICE'] ?? ''),
        'einv_qr' => (string) ($res['EINV_QR'] ?? ''),
        'einv_num' => (string) ($res['EINV_NUM'] ?? ''),
        'einv_inv_uuid' => (string) ($res['EINV_INV_UUID'] ?? ''),
        'einv_sent_at' => date('Y-m-d H:i:s'),
    ];

    if (($res['EINV_STATUS'] ?? '') === 'SUBMITTED') {
        $st = $pdo->prepare("SELECT reference_status FROM {$table} WHERE id = ? LIMIT 1");
        $st->execute([$refId]);
        $cur = $st->fetchColumn();
        if ($cur !== 'FINAL') {
            $update['reference_status'] = 'FINAL';
        }
    }

    $sets = [];
    $vals = [];
    foreach ($update as $col => $val) {
        if (!einvoice_column_exists($pdo, $table, $col)) {
            continue;
        }
        $sets[] = "`{$col}` = ?";
        $vals[] = $val;
    }
    if ($sets !== []) {
        $vals[] = $refId;
        $pdo->prepare('UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
    }

    if (isset($res['error'])) {
        return $res;
    }
    if (trim((string) ($update['einv_qr'] ?? '')) === '' && ($res['EINV_STATUS'] ?? '') !== 'SUBMITTED') {
        return ['error' => (string) ($update['einv_results'] ?: 'فشل الإرسال للفوترة.'), 'response' => $res];
    }

    return $res;
}

/**
 * يستخرج رسالة خطأ مفهومة من ردّ JoFotara.
 *
 * أمثلة على الأشكال:
 * - {"EINV_RESULTS":{"status":"ERROR","ERRORS":[{"EINV_CODE":"XSD_INVALID","EINV_MESSAGE":"Schema validation failed; ..."}]}, "EINV_STATUS":"NOT_SUBMITTED"}
 * - {"errors":[{"detail":"..."}]}
 * - {"message":"..."}
 *
 * @param array<string, mixed>|null $body
 */
function einvoice_extract_jofotara_error(?array $body): string
{
    if (!is_array($body)) {
        return '';
    }

    $parts = [];

    // EINV_RESULTS.ERRORS[]
    if (isset($body['EINV_RESULTS']) && is_array($body['EINV_RESULTS'])) {
        $r = $body['EINV_RESULTS'];
        $errs = is_array($r['ERRORS'] ?? null) ? $r['ERRORS'] : [];
        foreach ($errs as $e) {
            if (!is_array($e)) {
                continue;
            }
            $code = trim((string) ($e['EINV_CODE'] ?? $e['code'] ?? ''));
            $cat = trim((string) ($e['EINV_CATEGORY'] ?? ''));
            $msgEn = trim((string) ($e['EINV_MESSAGE'] ?? $e['message'] ?? ''));
            $pieces = array_filter([$code, $cat, $msgEn], static fn($s) => $s !== '');
            if ($pieces) {
                $parts[] = implode(' — ', $pieces);
            }
        }
        if ($parts === [] && isset($r['status'])) {
            $parts[] = (string) $r['status'];
        }
    }

    // errors: [{detail|message|...}]
    if (isset($body['errors']) && is_array($body['errors'])) {
        foreach ($body['errors'] as $e) {
            if (is_string($e) && $e !== '') {
                $parts[] = $e;

                continue;
            }
            if (is_array($e)) {
                foreach (['detail', 'message', 'title', 'code'] as $k) {
                    if (!empty($e[$k])) {
                        $parts[] = (string) $e[$k];
                        break;
                    }
                }
            }
        }
    }

    // top-level message / error / detail
    foreach (['message', 'error', 'detail', 'title'] as $k) {
        if (!empty($body[$k]) && (is_string($body[$k]) || is_numeric($body[$k]))) {
            $parts[] = (string) $body[$k];
            break;
        }
    }

    $parts = array_values(array_unique(array_filter(array_map('trim', $parts), static fn($s) => $s !== '')));

    return $parts === [] ? '' : implode("\n", array_slice($parts, 0, 5));
}

function einvoice_resolve_type_code(array $settings, string $paymentType): string
{
    $isCash = $paymentType !== 'credit';
    $taxesType = (int) ($settings['taxes_type'] ?? 2);

    // الأكواد القياسية بحسب JoFotara:
    //   Income (بدون ضريبة عامة): 011 نقدي / 021 آجل
    //   General Sales (مع ضريبة عامة 16%): 012 نقدي / 022 آجل
    //   Special Sales (ضريبة خاصة):       013 نقدي / 023 آجل
    $defaults = [
        1 => ['cash' => '011', 'credit' => '021'], // income
        2 => ['cash' => '012', 'credit' => '022'], // general sales (VAT)
        3 => ['cash' => '013', 'credit' => '023'], // special sales
    ];

    $code = trim((string) ($isCash ? ($settings['invoice_cash'] ?? '') : ($settings['invoice_debit'] ?? '')));
    $expected = $defaults[$taxesType] ?? $defaults[2];
    $validForType = $isCash ? [$expected['cash']] : [$expected['credit']];

    // فرض الكود الصحيح إذا كان فارغاً أو مخالفاً لنوع الضريبة في الفاتورة.
    if ($code === '' || !in_array($code, $validForType, true)) {
        $code = $isCash ? $expected['cash'] : $expected['credit'];
    }

    return $code;
}

/**
 * إرسال فاتورة بيع — نفس منطق admin Sales::send_to_tax + Site::send_ubl_invoice_api
 *
 * @return array{ok:bool, skipped:bool, error:?string, message:?string}
 */
function einvoice_ubl_send_sale_invoice(PDO $pdo, int $invoiceId): array
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

    if (einvoice_sale_is_sent($pdo, $invoiceId)) {
        $out['ok'] = true;
        $out['skipped'] = true;
        $out['message'] = 'تم إرسال هذه الفاتورة مسبقًا.';

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

    $payload = einvoice_load_sale_payload($pdo, $invoiceId);
    if ($payload === null) {
        $out['error'] = 'الفاتورة غير موجودة.';

        return $out;
    }
    $raw = $payload['raw'];
    if (($raw['status'] ?? '') !== 'confirmed') {
        $out['error'] = 'الفاتورة غير مؤكدة.';

        return $out;
    }

    require_once app_path('includes/sal_invoice_post.php');
    if (!sal_invoice_is_posted($pdo, $invoiceId)) {
        $out['error'] = 'يجب ترحيل الفاتورة قبل إرسالها للفوترة.';

        return $out;
    }

    $uuid = trim((string) ($raw['invoice_uuid'] ?? ''));
    if ($uuid === '') {
        $uuid = einvoice_generate_uuid();
        $pdo->prepare('UPDATE sal_invoice SET invoice_uuid = ? WHERE id = ?')->execute([$uuid, $invoiceId]);
        $payload['inv']->uuid = $uuid;
    }

    $code = einvoice_resolve_type_code($settings, (string) ($raw['payment_type'] ?? 'cash'));
    $taxesType = (int) ($settings['taxes_type'] ?? 2);

    set_time_limit(300);
    $xml = einvoice_generate_ubl_invoice($payload['inv'], $payload['lines'], $payload['customer'], $payload['biller'], $code, $taxesType);

    // حفظ نسخة من XML للتشخيص في حال فشل الإرسال.
    try {
        $logDir = app_path('logs');
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $logFile = $logDir . DIRECTORY_SEPARATOR . 'einvoice-last-' . $invoiceId . '.xml';
        @file_put_contents($logFile, $xml);
    } catch (Throwable $e) {
        // ignore
    }

    // استخراج TaxInclusiveAmount من XML المُولَّد، وحفظه في sal_invoice.einv_total_amount.
    // نَستخدمه لاحقًا كـ DocumentDescription عند إرسال إشعارات الدائن (المرتجعات)
    // لضمان مطابقة القيمة المُسَجَّلة في JoFotara بدقة.
    try {
        if (preg_match('/<cbc:TaxInclusiveAmount\s+currencyID="JOD">([0-9.]+)<\/cbc:TaxInclusiveAmount>/i', $xml, $m)) {
            $totalAmount = (float) $m[1];
            if (einvoice_column_exists($pdo, 'sal_invoice', 'einv_total_amount')) {
                $pdo->prepare('UPDATE sal_invoice SET einv_total_amount = ? WHERE id = ?')
                    ->execute([round($totalAmount, 3), $invoiceId]);
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    $encoded = base64_encode($xml);
    $result = einvoice_send_request_to_api($pdo, ['invoice' => $encoded], $invoiceId, 'sal_invoice');

    if (is_array($result) && isset($result['error'])) {
        $out['error'] = (string) $result['error'];
        $out['http_code'] = isset($result['http_code']) ? (int) $result['http_code'] : null;
        $out['response'] = $result['response'] ?? null;

        return $out;
    }

    if (!einvoice_sale_is_sent($pdo, $invoiceId)) {
        $out['error'] = 'لم يُستلم رمز QR من نظام الفوترة. راجع einv_results.';

        return $out;
    }

    $out['ok'] = true;
    $row = einvoice_sale_status_row($pdo, $invoiceId);
    $out['message'] = 'تم إرسال الفاتورة للفوترة بنجاح.'
        . ($row && !empty($row['einv_num']) ? ' رقم الفوترة: ' . $row['einv_num'] : '');

    return $out;
}
