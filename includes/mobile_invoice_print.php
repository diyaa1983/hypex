<?php
declare(strict_types=1);

/**
 * طباعة/PDF فواتير الموبايل فقط — لا يُستدعى من index.php أو شاشات الويندوز.
 * التصميم: assets/mobile/* و #m-inv-pdf-preview — منفصل عن sales-invoice.css و sales-invoice.js.
 * يقرأ بيانات الشركة عبر document_header.php دون تعديل واجهة سطح المكتب.
 */

require_once app_path('includes/document_header.php');
require_once app_path('includes/mobile_invoice.php');
require_once app_path('includes/company_settings.php');
require_once app_path('includes/mobile_invoice_print_pdf_mobile.php');

const EINV_PRINT_QR_IMG_PX = 136;
const EINV_PRINT_QR_BOX_PX = 148;
const EINV_PRINT_QR_SRC_PX = 384;
const EINV_PRINT_QR_HEADER_COL_PX = 158;

function einv_print_qr_css(): string
{
    $img = EINV_PRINT_QR_IMG_PX;
    $box = EINV_PRINT_QR_BOX_PX;
    $col = EINV_PRINT_QR_HEADER_COL_PX;

    return '.inv-print-header-row td.inv-print-header-qr{width:' . $col . 'px;padding-inline-start:8px!important;text-align:center;}'
        . '.inv-print-qr-wrap{width:' . $box . 'px;text-align:center;margin-inline-start:auto;}'
        . '.inv-print-qr-box{border:2px solid #0f172a;border-radius:10px;padding:4px;background:#fff;width:' . $box . 'px;height:' . $box . 'px;box-sizing:border-box;text-align:center;}'
        . '.inv-print-qr-img{display:inline-block;width:' . $img . 'px;height:' . $img . 'px;vertical-align:middle;}'
        . '.inv-print-qr-placeholder{display:inline-block;width:' . $img . 'px;height:' . $img . 'px;background:#f1f5f9;border-radius:6px;vertical-align:middle;}'
        . '.inv-print-qr-caption{font-size:0.62rem;color:#94a3b8;margin-top:3px;letter-spacing:0.3px;font-weight:500;}'
        . '@media print{.inv-print-qr-box{width:' . $box . 'px!important;height:' . $box . 'px!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
        . '.inv-print-qr-img{width:' . $img . 'px!important;height:' . $img . 'px!important;max-width:none!important;max-height:none!important;}}';
}

/** أنماط الطباعة — نفس فاتورة المبيعات على سطح المكتب. */
function mobile_invoice_print_styles(?PDO $pdo = null): string
{
    $logoUrl = document_print_watermark_logo_url($pdo);

    return document_print_watermark_root_css($pdo)
        . document_print_header_css()
        . document_print_watermark_css()
        . mobile_invoice_print_table_css()
        . mobile_invoice_print_meta_grid_css()
        . '.doc-print-meta-value--party{font-weight:800;font-size:1.12em;color:#0f172a;}'
        . '.inv-print-header-row{width:100%;border-collapse:collapse;margin:0.3rem 0 0.6rem;direction:rtl;}'
        . '.inv-print-header-row td{border:none!important;padding:0!important;vertical-align:top;}'
        . '.inv-print-header-row td.inv-print-header-meta{width:auto;}'
        . einv_print_qr_css()
        . '.sales-inv-print-tot{margin-top:0.75rem;text-align:left;max-width:280px;margin-right:0;margin-left:auto;}'
        . '.sales-inv-print-tot div{display:flex;justify-content:space-between;padding:0.25rem 0;border-bottom:1px solid #e2e8f0;font-weight:700;}'
        . '.sales-inv-print-tot .g{font-weight:800;font-size:1.05rem;border-top:2px solid #334155;margin-top:0.35rem;padding-top:0.45rem;}'
        . 'body,table,th,td,.doc-print-meta,.doc-print-meta td,.sales-inv-print-tot,.sales-inv-print-tot div,.sales-inv-print-tot span{font-weight:700;}'
        . '.doc-print-header-co{font-weight:800;}.doc-print-header-title,.sales-inv-print-tot .g{font-weight:800;}'
        . mobile_invoice_print_recipient_signature_css()
        . (mobile_invoice_print_watermark_wrap_css($logoUrl));
}

/** جدول بيانات الفاتورة (عمودان: التسمية | القيمة) — يعمل مع html2pdf دون bidi معطوب */
function mobile_invoice_print_meta_grid_css(): string
{
    return '.doc-print-meta{margin:0.35rem 0 0.65rem;text-align:start;direction:rtl;}'
        . '.doc-print-meta-grid{width:100%;border-collapse:collapse;direction:rtl;table-layout:fixed;}'
        . '.doc-print-meta-grid td{border:none!important;vertical-align:top;padding:0.2rem 0;text-align:start!important;}'
        . '.doc-print-meta-label{font-weight:700;white-space:nowrap;width:32%;max-width:38%;padding-left:0.65rem;}'
        . '.doc-print-meta-value{font-weight:700;}'
        . '.doc-print-meta-row-wh .doc-print-meta-value{padding-bottom:0.35rem;border-bottom:1px solid #e2e8f0;}'
        . '.doc-print-meta-grid,.doc-print-meta-grid tr,.doc-print-meta-grid td{list-style:none!important;}'
        . '.doc-print-meta-grid td::before,.doc-print-meta-grid td::marker{content:none!important;display:none!important;}';
}

function mobile_invoice_print_meta_row(string $label, string $value, bool $party = false, string $rowClass = ''): string
{
    if (trim($value) === '') {
        return '';
    }
    $rowCls = trim($rowClass) !== '' ? ' class="' . esc(trim($rowClass)) . '"' : '';
    $valCls = 'doc-print-meta-value' . ($party ? ' doc-print-meta-value--party' : '');

    return '<tr' . $rowCls . '>'
        . '<td class="doc-print-meta-label">' . esc($label) . ':</td>'
        . '<td class="' . esc($valCls) . '">' . esc($value) . '</td>'
        . '</tr>';
}

/** @return list<array{label: string, value: string, emphasis?: bool, row_class?: string}> */
function mobile_invoice_print_meta_rows(array $inv, bool $withCustomerCode = true): array
{
    require_once app_path('includes/helpers.php');
    $date = (string) ($inv['invoice_date'] ?? '');
    if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = format_date_dmY($date);
    }
    $cust = (string) ($inv['customer_name'] ?? '');
    if ($withCustomerCode) {
        $code = (string) ($inv['customer_code'] ?? '');
        if ($code !== '') {
            $cust .= ' (' . $code . ')';
        }
    }
    $pay = (string) ($inv['payment_label'] ?? '');
    if ($pay === '') {
        $pay = (($inv['payment_type'] ?? '') === 'credit') ? 'ذمم' : 'نقدي';
    } elseif (($inv['payment_type'] ?? '') === 'credit' && $pay === 'ذمة') {
        $pay = 'ذمم';
    }

    $rows = [
        ['label' => 'رقم الفاتورة', 'value' => (string) ($inv['invoice_no'] ?? '')],
        ['label' => 'التاريخ', 'value' => $date],
        ['label' => 'العميل', 'value' => $cust, 'emphasis' => true],
        ['label' => 'النوع', 'value' => $pay],
    ];
    $rep = trim((string) ($inv['sales_rep_name'] ?? ''));
    if ($rep !== '' && $rep !== '—') {
        $rows[] = ['label' => 'المندوب', 'value' => $rep];
    }
    $wh = trim((string) ($inv['warehouse_name'] ?? ''));
    if ($wh !== '') {
        $rows[] = ['label' => 'المستودع', 'value' => $wh, 'row_class' => 'doc-print-meta-row-wh'];
    }

    return $rows;
}

function mobile_invoice_print_watermark_wrap_css(?string $logoUrl): string
{
    if ($logoUrl === null || $logoUrl === '') {
        return '';
    }

    return '@media print{body.has-doc-watermark::after{display:block!important;position:fixed!important;inset:0!important;'
        . 'width:100%!important;height:100%!important;z-index:9999;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
        . 'body.doc-print-standalone::after{display:block!important;}}';
}

/** تنسيق مخصّص لتصدير PDF على الموبايل (html2canvas) — مُحَدَّد بـ #m-inv-pdf-preview فقط */
function mobile_invoice_print_pdf_overrides_css(): string
{
    return '#m-inv-pdf-preview{box-sizing:border-box;width:100%;max-width:190mm;margin:0 auto;padding:8mm 6mm;background:#fff;'
        . 'color:#0f172a;direction:rtl;font-family:Arial,Helvetica,sans-serif;font-size:11px;font-weight:700;}'
        . '#m-inv-pdf-preview .doc-print-header-brand{display:table!important;width:100%!important;table-layout:fixed!important;border-collapse:collapse!important;}'
        . '#m-inv-pdf-preview .doc-print-header-co{display:table-cell!important;float:none!important;width:62%!important;vertical-align:middle!important;text-align:right!important;}'
        . '#m-inv-pdf-preview .doc-print-header-logo{display:table-cell!important;float:none!important;width:38%!important;vertical-align:middle!important;text-align:left!important;}'
        . '#m-inv-pdf-preview .doc-print-header-top{overflow:visible!important;padding-bottom:0.5rem!important;}'
        . '#m-inv-pdf-preview .doc-print-header-title{clear:both!important;text-align:center!important;margin-top:0.35rem!important;}'
        . '#m-inv-pdf-preview .doc-print-header-logo img{max-height:72px!important;max-width:100px!important;display:block!important;margin-left:auto!important;}'
        . '#m-inv-pdf-preview .m-pdf-meta-tbl{width:100%!important;table-layout:fixed!important;border-collapse:collapse!important;direction:rtl!important;}'
        . '#m-inv-pdf-preview .m-pdf-meta-tbl td{border:none!important;vertical-align:top!important;font-size:10px!important;line-height:1.4!important;}'
        . '#m-inv-pdf-preview .m-pdf-meta-lbl{width:32%!important;text-align:right!important;padding:2px 4px 3px 0!important;white-space:nowrap!important;}'
        . '#m-inv-pdf-preview .m-pdf-meta-val{width:68%!important;text-align:right!important;padding:2px 0 3px 4px!important;word-break:break-word!important;}'
        . '#m-inv-pdf-preview .m-pdf-meta-val--party{font-weight:800!important;font-size:10.5px!important;}'
        . '#m-inv-pdf-preview .m-pdf-header-split{width:100%!important;table-layout:fixed!important;border-collapse:collapse!important;direction:rtl!important;margin:0.35rem 0 0.5rem!important;}'
        . '#m-inv-pdf-preview .m-pdf-header-split td{border:none!important;vertical-align:top!important;}'
        . '#m-inv-pdf-preview .m-pdf-header-split-meta{width:68%!important;padding:0!important;}'
        . '#m-inv-pdf-preview .m-pdf-header-split-qr{width:32%!important;padding:0!important;text-align:center!important;}'
        . '#m-inv-pdf-preview .m-pdf-tot-tbl{width:100%!important;max-width:270px!important;margin:12px 0 0 auto!important;border-collapse:collapse!important;direction:rtl!important;table-layout:fixed!important;}'
        . '#m-inv-pdf-preview .m-pdf-tot-tbl td{border:none!important;padding:4px 6px!important;font-size:10px!important;font-weight:700!important;vertical-align:top!important;}'
        . '#m-inv-pdf-preview .m-pdf-tot-tbl .m-pdf-tot-lbl{text-align:right!important;width:58%!important;border-bottom:1px solid #e2e8f0!important;}'
        . '#m-inv-pdf-preview .m-pdf-tot-tbl .m-pdf-tot-val{text-align:left!important;width:42%!important;border-bottom:1px solid #e2e8f0!important;}'
        . '#m-inv-pdf-preview .m-pdf-tot-tbl .m-pdf-tot-grand td{border-top:2px solid #334155!important;border-bottom:none!important;padding-top:6px!important;font-size:11px!important;font-weight:800!important;}'
        . '#m-inv-pdf-preview table.inv-print-lines{table-layout:fixed!important;width:100%!important;border-collapse:collapse!important;}'
        . '#m-inv-pdf-preview table.inv-print-lines th,#m-inv-pdf-preview table.inv-print-lines td{box-sizing:border-box!important;vertical-align:middle!important;}'
        . '#m-inv-pdf-preview table.inv-print-lines th{white-space:nowrap!important;font-size:8px!important;line-height:1.25!important;padding:4px 3px!important;}'
        . '#m-inv-pdf-preview table.inv-print-lines td{font-size:9px!important;line-height:1.3!important;padding:4px 3px!important;}'
        . '#m-inv-pdf-preview table.inv-print-lines td:not(.inv-print-cell-item){white-space:nowrap!important;}'
        . '#m-inv-pdf-preview table.inv-print-lines td.inv-print-cell-item{white-space:normal!important;word-break:break-word!important;text-align:start!important;}'
        . '#m-inv-pdf-preview table.inv-print-header-row{table-layout:fixed!important;width:100%!important;}'
        . '#m-inv-pdf-preview table.inv-print-header-row td.inv-print-header-meta{width:72%!important;}'
        . '#m-inv-pdf-preview table.inv-print-header-row td.inv-print-header-qr{width:28%!important;vertical-align:top!important;}'
        . '#m-inv-pdf-preview .doc-print-signature-block{clear:both!important;page-break-inside:avoid!important;margin-top:1rem!important;}'
        . '#m-inv-pdf-preview .m-pdf-notes{margin:0.6rem 0 0!important;font-size:10px!important;line-height:1.45!important;text-align:right!important;direction:rtl!important;}';
}

/** يمنع تطبيق أنماط الطباعة على body الصفحة الرئيسية أثناء html2pdf */
function mobile_invoice_print_scope_css_for_pdf(string $css): string
{
    return preg_replace('/\bbody\b/', '#m-inv-pdf-preview', $css) ?? $css;
}

function mobile_invoice_print_styles_pdf(?PDO $pdo = null): string
{
    return mobile_invoice_print_scope_css_for_pdf(mobile_invoice_print_styles($pdo))
        . mobile_invoice_print_pdf_overrides_css();
}

function mobile_invoice_print_table_css(): string
{
    return 'body{font-family:Arial,Helvetica,sans-serif;font-size:11px;font-weight:700;color:#0f172a;margin:6mm 10mm 10mm;direction:rtl;}'
        . 'table.inv-print-lines{border-collapse:collapse;width:100%;margin-top:0.5rem;font-size:10px;}'
        . 'table.inv-print-lines th{background:#f1f5f9;padding:0.28rem 0.35rem;border:1px solid #94a3b8;font-size:10px;font-weight:400!important;color:#475569;}'
        . 'table.inv-print-lines td{padding:0.28rem 0.35rem;border:1px solid #cbd5e1;text-align:center;font-size:10px;font-weight:700!important;color:#0f172a;}'
        . 'table.inv-print-lines td.inv-print-cell-item{text-align:start;}'
        . 'table.inv-print-lines .inv-print-cell-sku{font-family:Arial,Helvetica,sans-serif;}'
        . 'table.inv-print-lines .inv-print-cell-disc{color:#b45309;}'
        . 'table.inv-print-lines .inv-print-cell-tax-pct{font-size:9px;}';
}

/** @param array<string, mixed> $inv */
function mobile_invoice_print_layout(array $inv): array
{
    $showQtyExtra = false;
    $showDiscount = false;
    $sumDisc = 0.0;
    foreach ($inv['lines'] ?? [] as $ln) {
        if (!is_array($ln)) {
            continue;
        }
        if ((float) ($ln['qty_extra'] ?? 0) > 0.000001) {
            $showQtyExtra = true;
        }
        $discAmt = (float) ($ln['discount_amount'] ?? 0);
        $discInp = trim((string) ($ln['line_discount_input'] ?? ''));
        if ($discAmt > 0.000001 || $discInp !== '') {
            $showDiscount = true;
        }
        $sumDisc += $discAmt;
    }
    $hdrDisc = trim((string) ($inv['invoice_discount_input'] ?? ''));

    return [
        'show_qty_extra' => $showQtyExtra,
        'show_discount' => $showDiscount,
        'sum_line_discount' => $sumDisc,
        'invoice_discount_label' => $hdrDisc,
    ];
}

/** أعمدة الجدول بنسب ثابتة — html2pdf لا يحترم flex جيداً */
function mobile_invoice_print_colgroup(bool $showQtyExtra, bool $showDiscount): string
{
    $w = [5, 8, 24, 7];
    if ($showQtyExtra) {
        $w[2] = 18;
        $w[] = 6;
    }
    $w[] = 9;
    if ($showDiscount) {
        $w[2] = max(14, $w[2] - 2);
        $w[] = 7;
    }
    $w = array_merge($w, [10, 9, 6, 11]);
    $sum = array_sum($w);
    if ($sum !== 100) {
        $w[count($w) - 1] += 100 - $sum;
    }
    $html = '<colgroup>';
    foreach ($w as $pct) {
        $html .= '<col style="width:' . (int) $pct . '%">';
    }

    return $html . '</colgroup>';
}

function mobile_invoice_print_thead_row(bool $showQtyExtra, bool $showDiscount): string
{
    $h = '<tr><th>تسلسل</th><th>رقم المادة</th><th>اسم المادة</th><th>الكمية</th>';
    if ($showQtyExtra) {
        $h .= '<th>الكمية الإضافية</th>';
    }
    $h .= '<th>السعر الإفرادي</th>';
    if ($showDiscount) {
        $h .= '<th>الخصم</th>';
    }
    $h .= '<th>السعر الإجمالي</th><th>مبلغ الضريبة</th><th>نسبة الضريبة</th><th>الإجمالي مع الضريبة</th></tr>';

    return $h;
}

function mobile_invoice_print_fmt(float $n, int $dp): string
{
    return number_format(round($n, $dp), $dp, '.', ',');
}

function mobile_invoice_print_discount_cell(array $ln, int $dp): string
{
    $inp = trim((string) ($ln['line_discount_input'] ?? ''));
    if ($inp !== '') {
        return esc($inp);
    }
    $amt = (float) ($ln['discount_amount'] ?? 0);
    if ($amt > 0.000001) {
        return esc(mobile_invoice_print_fmt($amt, $dp));
    }

    return '—';
}

/** @param array<string, mixed> $ln */
function mobile_invoice_print_line_row(
    array $ln,
    int $seq,
    bool $showQtyExtra,
    bool $showDiscount,
    int $amountDp,
    ?int $unitPriceDp = null
): string
{
    $unitDp = $unitPriceDp ?? $amountDp;
    $taxPct = (float) ($ln['tax_rate_percent'] ?? 0);
    $taxLab = rtrim(rtrim(number_format($taxPct, 2, '.', ''), '0'), '.') . '%';
    $name = (string) ($ln['name_ar'] ?? $ln['line_desc'] ?? '');
    $sku = (string) ($ln['barcode'] ?? $ln['sku'] ?? '');
    $sub = (float) ($ln['line_subtotal'] ?? $ln['line_total'] ?? 0);
    $gross = (float) ($ln['line_gross'] ?? $sub);

    $html = '<tr>';
    $html .= '<td>' . $seq . '</td>';
    $html .= '<td class="inv-print-cell-sku">' . esc($sku) . '</td>';
    $html .= '<td class="inv-print-cell-item">' . esc($name) . '</td>';
    $html .= '<td>' . esc(mobile_invoice_print_fmt((float) ($ln['qty'] ?? 0), $amountDp)) . '</td>';
    if ($showQtyExtra) {
        $html .= '<td>' . esc(mobile_invoice_print_fmt((float) ($ln['qty_extra'] ?? 0), $amountDp)) . '</td>';
    }
    $html .= '<td>' . esc(mobile_invoice_print_fmt((float) ($ln['unit_price'] ?? 0), $unitDp)) . '</td>';
    if ($showDiscount) {
        $html .= '<td class="inv-print-cell-disc">' . mobile_invoice_print_discount_cell($ln, $amountDp) . '</td>';
    }
    $html .= '<td>' . esc(mobile_invoice_print_fmt($sub, $amountDp)) . '</td>';
    $html .= '<td>' . esc(mobile_invoice_print_fmt((float) ($ln['tax_amount'] ?? 0), $amountDp)) . '</td>';
    $html .= '<td class="inv-print-cell-tax-pct">' . esc($taxLab) . '</td>';
    $html .= '<td>' . esc(mobile_invoice_print_fmt($gross, $amountDp)) . '</td>';
    $html .= '</tr>';

    return $html;
}

function mobile_invoice_print_einv_qr_img_src(?string $qrSrc): ?string
{
    $qrSrc = trim((string) $qrSrc);
    if ($qrSrc === '') {
        return null;
    }
    if (str_starts_with($qrSrc, 'data:') || str_starts_with($qrSrc, 'http')) {
        return $qrSrc;
    }
    if (str_starts_with($qrSrc, 'iVBORw0KGgo')) {
        return 'data:image/png;base64,' . $qrSrc;
    }
    if (str_starts_with($qrSrc, '/9j/')) {
        return 'data:image/jpeg;base64,' . $qrSrc;
    }

    return 'https://api.qrserver.com/v1/create-qr-code/?size=' . EINV_PRINT_QR_SRC_PX . 'x' . EINV_PRINT_QR_SRC_PX . '&format=png&margin=4&ecc=H&data=' . rawurlencode($qrSrc);
}

/**
 * صورة QR كـ data URI مضمّنة (مطلوبة لـ mPDF/Dompdf حتى يظهر QR في PDF).
 */
function mobile_invoice_print_einv_qr_data_uri(?string $qrSrc): ?string
{
    $src = mobile_invoice_print_einv_qr_img_src($qrSrc);
    if ($src === null) {
        return null;
    }
    if (str_starts_with($src, 'data:')) {
        return $src;
    }
    if (str_starts_with($src, 'http://') || str_starts_with($src, 'https://')) {
        $ctx = stream_context_create([
            'http' => ['timeout' => 10, 'follow_location' => 1],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $bin = @file_get_contents($src, false, $ctx);
        if (is_string($bin) && $bin !== '') {
            $mime = (str_starts_with($bin, "\xFF\xD8\xFF")) ? 'image/jpeg' : 'image/png';

            return 'data:' . $mime . ';base64,' . base64_encode($bin);
        }
    }

    return $src;
}

/** نص/حمولة QR للفاتورة — einv_qr أو بديل رقم الفاتورة. */
function mobile_invoice_print_qr_payload(array $inv): string
{
    $qr = trim((string) ($inv['einv_qr'] ?? ''));
    if ($qr !== '') {
        return $qr;
    }
    $uuid = trim((string) ($inv['einv_inv_uuid'] ?? $inv['invoice_uuid'] ?? ''));
    if ($uuid !== '') {
        return $uuid;
    }
    $no = trim((string) ($inv['invoice_no'] ?? ''));
    if ($no !== '') {
        return 'INV:' . $no;
    }
    $id = (int) ($inv['id'] ?? 0);

    return $id > 0 ? 'INV-ID:' . $id : '';
}

function mobile_invoice_print_einv_qr_box(?string $qrSrc): string
{
    $src = mobile_invoice_print_einv_qr_data_uri($qrSrc);
    if ($src === null) {
        return '';
    }
    $img = EINV_PRINT_QR_IMG_PX;
    $box = EINV_PRINT_QR_BOX_PX;
    $print = EINV_PRINT_QR_IMG_PX;
    $imgTag = '<img src="' . esc($src) . '" alt="QR" class="inv-print-qr-img" width="' . $print . '" height="' . $print . '" style="width:' . $img . 'px;height:' . $img . 'px;display:inline-block;vertical-align:middle;">';

    return '<div class="inv-print-qr-wrap" style="width:' . $box . 'px;text-align:center;margin:0;">'
        . '<div class="inv-print-qr-box" style="border:2px solid #0f172a;border-radius:10px;padding:4px;background:#fff;width:' . $box . 'px;height:' . $box . 'px;box-sizing:border-box;text-align:center;line-height:0;display:block;">'
        . $imgTag
        . '</div>'
        . '<div class="inv-print-qr-caption" style="font-size:9px;color:#64748b;margin-top:3px;font-weight:600;">Please Check In</div>'
        . '</div>';
}

function mobile_invoice_print_meta_table(array $inv): string
{
    $html = '<div class="doc-print-meta"><table class="doc-print-meta-grid" cellpadding="0" cellspacing="0" style="width:100%;table-layout:fixed;border-collapse:collapse;direction:rtl;">'
        . '<colgroup><col style="width:32%"><col style="width:68%"></colgroup>';
    foreach (mobile_invoice_print_meta_rows($inv) as $row) {
        $html .= mobile_invoice_print_meta_row(
            (string) $row['label'],
            (string) ($row['value'] ?? ''),
            !empty($row['emphasis']),
            (string) ($row['row_class'] ?? '')
        );
    }
    $html .= '</table></div>';

    return $html;
}

/** بيانات الفاتورة خارج الجدول — تنسيق ثابت لـ html2pdf على الموبايل */
function mobile_invoice_print_meta_pdf(array $inv): string
{
    $tbl = 'width:100%;table-layout:fixed;border-collapse:collapse;direction:rtl;margin:0;';
    $lbl = 'width:32%;text-align:right;vertical-align:top;padding:2px 6px 3px 0;white-space:nowrap;'
        . 'font-size:10px;font-weight:700;border:none;line-height:1.4;';
    $val = 'width:68%;text-align:right;vertical-align:top;padding:2px 0 3px 6px;word-break:break-word;'
        . 'font-size:10px;font-weight:700;border:none;line-height:1.4;';
    $valParty = $val . 'font-weight:800;font-size:10.5px;';

    $html = '<div class="doc-print-meta m-pdf-meta"><table class="m-pdf-meta-tbl" cellpadding="0" cellspacing="0" style="' . $tbl . '">'
        . '<colgroup><col style="width:32%"><col style="width:68%"></colgroup>';
    foreach (mobile_invoice_print_meta_rows($inv) as $row) {
        $value = trim((string) ($row['value'] ?? ''));
        if ($value === '') {
            continue;
        }
        $valCls = 'm-pdf-meta-val' . (!empty($row['emphasis']) ? ' m-pdf-meta-val--party' : '');
        $valStyle = !empty($row['emphasis']) ? $valParty : $val;
        $rowStyle = ($row['row_class'] ?? '') === 'doc-print-meta-row-wh'
            ? ' style="border-bottom:1px solid #e2e8f0;"'
            : '';
        $html .= '<tr' . $rowStyle . '>'
            . '<td class="m-pdf-meta-lbl" style="' . $lbl . '">' . esc((string) $row['label']) . ':</td>'
            . '<td class="' . esc($valCls) . '" style="' . $valStyle . '">' . esc($value) . '</td>'
            . '</tr>';
    }
    $html .= '</table></div>';

    return $html;
}

function mobile_invoice_print_header_block(string $metaHtml, string $einvBox, bool $forPdf): string
{
    if ($einvBox === '') {
        return $metaHtml;
    }
    if ($forPdf) {
        $split = 'width:100%;table-layout:fixed;border-collapse:collapse;direction:rtl;margin:0.35rem 0 0.5rem;';

        return '<table class="m-pdf-header-split" cellpadding="0" cellspacing="0" style="' . $split . '">'
            . '<colgroup><col style="width:68%"><col style="width:32%"></colgroup>'
            . '<tr><td class="m-pdf-header-split-meta" style="width:68%;vertical-align:top;padding:0;border:none;">' . $metaHtml . '</td>'
            . '<td class="m-pdf-header-split-qr" style="width:32%;vertical-align:top;padding:0;border:none;text-align:center;">' . $einvBox . '</td></tr></table>';
    }

    return '<table class="inv-print-header-row" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;margin:0.3rem 0 0.6rem;direction:rtl;table-layout:fixed;">'
        . '<tr><td class="inv-print-header-meta" style="border:none;padding:0;vertical-align:top;">' . $metaHtml . '</td>'
        . '<td class="inv-print-header-qr" style="border:none;padding:0;vertical-align:top;width:' . EINV_PRINT_QR_HEADER_COL_PX . 'px;text-align:center;">' . $einvBox . '</td></tr></table>';
}

/** @return list<array{label: string, value: string, grand?: bool}> */
function mobile_invoice_print_totals_rows(array $inv, array $layout, int $dp): array
{
    $showDiscTotal = ($layout['show_discount'] || $layout['invoice_discount_label'] !== '')
        && ((float) ($layout['sum_line_discount'] ?? 0) > 0.000001);
    $rows = [];
    if ($layout['invoice_discount_label'] !== '') {
        $rows[] = ['label' => 'خصم الفاتورة', 'value' => $layout['invoice_discount_label']];
    }
    if ($showDiscTotal) {
        $rows[] = [
            'label' => 'مجموع الخصم',
            'value' => mobile_invoice_print_fmt((float) $layout['sum_line_discount'], $dp),
        ];
    }
    $rows[] = [
        'label' => 'المجموع بدون ضريبة',
        'value' => mobile_invoice_print_fmt((float) ($inv['subtotal'] ?? 0), $dp),
    ];
    $rows[] = [
        'label' => 'مجموع الضريبة',
        'value' => mobile_invoice_print_fmt((float) ($inv['tax_amount'] ?? 0), $dp),
    ];
    $rows[] = [
        'label' => 'الإجمالي',
        'value' => mobile_invoice_print_fmt((float) ($inv['total'] ?? 0), $dp),
        'grand' => true,
    ];

    return $rows;
}

function mobile_invoice_print_totals(array $inv, array $layout, int $dp): string
{
    $html = '<div class="sales-inv-print-tot">';
    foreach (mobile_invoice_print_totals_rows($inv, $layout, $dp) as $row) {
        $cls = !empty($row['grand']) ? ' class="g"' : '';
        $html .= '<div' . $cls . '><span>' . esc($row['label']) . '</span><span>' . esc($row['value']) . '</span></div>';
    }
    $html .= '</div>';

    return $html;
}

function mobile_invoice_print_totals_pdf(array $inv, array $layout, int $dp): string
{
    $tbl = 'width:100%;max-width:270px;margin:12px 0 0 auto;table-layout:fixed;border-collapse:collapse;direction:rtl;';
    $lbl = 'width:58%;text-align:right;padding:4px 6px;font-size:10px;font-weight:700;vertical-align:top;'
        . 'border-bottom:1px solid #e2e8f0;border-top:none;border-left:none;border-right:none;';
    $val = 'width:42%;text-align:left;padding:4px 6px;font-size:10px;font-weight:700;vertical-align:top;'
        . 'border-bottom:1px solid #e2e8f0;border-top:none;border-left:none;border-right:none;';
    $grandLbl = 'width:58%;text-align:right;padding:6px;font-size:11px;font-weight:800;border:none;';
    $grandVal = 'width:42%;text-align:left;padding:6px;font-size:11px;font-weight:800;border:none;';

    $html = '<table class="m-pdf-tot-tbl" cellpadding="0" cellspacing="0" style="' . $tbl . '">'
        . '<colgroup><col style="width:58%"><col style="width:42%"></colgroup>';
    foreach (mobile_invoice_print_totals_rows($inv, $layout, $dp) as $row) {
        if (!empty($row['grand'])) {
            $html .= '<tr class="m-pdf-tot-grand" style="border-top:2px solid #334155;">'
                . '<td class="m-pdf-tot-lbl" style="' . $grandLbl . '">' . esc($row['label']) . '</td>'
                . '<td class="m-pdf-tot-val" style="' . $grandVal . '">' . esc($row['value']) . '</td>'
                . '</tr>';
            continue;
        }
        $html .= '<tr>'
            . '<td class="m-pdf-tot-lbl" style="' . $lbl . '">' . esc($row['label']) . '</td>'
            . '<td class="m-pdf-tot-val" style="' . $val . '">' . esc($row['value']) . '</td>'
            . '</tr>';
    }
    $html .= '</table>';

    return $html;
}

/**
 * محتوى HTML للطباعة (نفس هيكل سطح المكتب).
 *
 * @param array<string, mixed> $inv
 * @param bool $forPdf عند true لا تُضمَّن العلامة المائية (تسبب صفحة زائدة في html2pdf)
 */
function mobile_invoice_print_inner_html(PDO $pdo, array $inv, bool $forPdf = false): string
{
    $inv = mobile_invoice_enrich_display($pdo, $inv);
    company_settings_ensure_invoice_print_decimal_places_columns($pdo);
    $amountDp = company_invoice_print_decimal_places($pdo);
    $unitPriceDp = company_invoice_print_unit_price_decimal_places($pdo);
    $layout = mobile_invoice_print_layout($inv);
    $showQtyExtra = $layout['show_qty_extra'];
    $showDiscount = $layout['show_discount'];
    $colspan = 9 + ($showQtyExtra ? 1 : 0) + ($showDiscount ? 1 : 0);

    $linesHtml = '';
    $seq = 0;
    foreach ($inv['lines'] ?? [] as $ln) {
        if (!is_array($ln)) {
            continue;
        }
        $seq++;
        $linesHtml .= mobile_invoice_print_line_row($ln, $seq, $showQtyExtra, $showDiscount, $amountDp, $unitPriceDp);
    }
    if ($linesHtml === '') {
        $linesHtml = '<tr><td colspan="' . $colspan . '" style="padding:1rem;text-align:center;color:#64748b;">لا توجد بنود</td></tr>';
    }

    $metaBlock = $forPdf ? mobile_invoice_print_meta_pdf($inv) : mobile_invoice_print_meta_table($inv);
    $einvBox = mobile_invoice_print_einv_qr_box(mobile_invoice_print_qr_payload($inv));
    $headerBlock = mobile_invoice_print_header_block($metaBlock, $einvBox, $forPdf);

    $notes = trim((string) ($inv['notes'] ?? ''));
    $notesBlock = $notes !== ''
        ? ($forPdf
            ? '<p class="m-pdf-notes"><strong>ملاحظات:</strong> ' . esc($notes) . '</p>'
            : '<p style="margin:0.75rem 0 0;font-size:0.88rem;direction:rtl;unicode-bidi:isolate;"><strong>ملاحظات:</strong> <bdi>' . esc($notes) . '</bdi></p>')
        : '';

    $totalsBlock = $forPdf
        ? mobile_invoice_print_totals_pdf($inv, $layout, $amountDp)
        : mobile_invoice_print_totals($inv, $layout, $amountDp);

    $inner = document_print_header_html('فاتورة مبيعات', $pdo)
        . $headerBlock
        . '<table class="inv-print-lines">' . mobile_invoice_print_colgroup($showQtyExtra, $showDiscount)
        . '<thead>' . mobile_invoice_print_thead_row($showQtyExtra, $showDiscount) . '</thead><tbody>'
        . $linesHtml
        . '</tbody></table>'
        . $totalsBlock
        . $notesBlock
        . mobile_invoice_print_recipient_signature_html();

    $logoUrl = document_print_watermark_logo_url($pdo);
    if ($logoUrl !== null && !$forPdf) {
        return '<div class="doc-print-watermark-root">'
            . document_print_watermark_html($pdo)
            . $inner
            . '</div>';
    }

    return $inner;
}

/** أنماط PDF داخل iframe التصدير (#pdf-export-root). */
function mobile_invoice_print_styles_pdf_iframe(?PDO $pdo = null): string
{
    $css = mobile_invoice_print_styles($pdo);
    $css = preg_replace('/\bbody\b/', '#pdf-export-root', $css) ?? $css;
    $overrides = str_replace('#m-inv-pdf-preview', '#pdf-export-root', mobile_invoice_print_pdf_overrides_css());

    return $css . $overrides
        . '#pdf-export-root{box-sizing:border-box;width:680px;max-width:680px;margin:0;padding:10px 12px;background:#fff;}';
}

function mobile_invoice_print_full_html_pdf(PDO $pdo, string $innerPdf): string
{
    return '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>فاتورة</title>'
        . '<style>' . mobile_invoice_print_styles_pdf_iframe($pdo) . '</style></head><body>'
        . '<div id="pdf-export-root">' . $innerPdf . '</div></body></html>';
}

/** مستند HTML كامل للإطار / PDF. */
function mobile_invoice_print_document(PDO $pdo, array $inv): array
{
    $styles = mobile_invoice_print_styles($pdo);
    $inner = mobile_invoice_print_inner_html($pdo, $inv, false);
    $innerPdf = mobile_invoice_print_inner_html_mobile_pdf($pdo, $inv);
    $logoUrl = document_print_watermark_logo_url($pdo);
    $bodyClass = $logoUrl !== null ? ' class="has-doc-watermark doc-print-standalone"' : '';
    $full = '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>فاتورة</title>'
        . '<style>' . $styles . '</style></head><body' . $bodyClass . '>' . $inner . '</body></html>';
    $htmlPdf = mobile_invoice_print_full_html_mobile_pdf($pdo, $innerPdf);
    $stylesPdf = mobile_invoice_print_mobile_pdf_css($pdo);

    $invoiceId = (int) ($inv['id'] ?? 0);

    return [
        'styles' => $styles,
        'styles_pdf' => $stylesPdf,
        'inner' => $inner,
        'inner_pdf' => $innerPdf,
        'html' => $full,
        'html_pdf' => $htmlPdf,
        'mobile_pdf' => true,
        'pdf_download_url' => $invoiceId > 0
            ? app_url('api/mobile_invoice_pdf.php?id=' . $invoiceId)
            : '',
    ];
}
