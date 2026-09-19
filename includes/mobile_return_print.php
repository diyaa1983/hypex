<?php
declare(strict_types=1);

/**
 * طباعة/PDF مرتجع المبيعات على الموبايل — منفصل عن sales-return.js على سطح المكتب.
 */

require_once app_path('includes/document_header.php');
require_once app_path('includes/company_settings.php');
require_once app_path('includes/mobile_invoice_print.php');
require_once app_path('includes/sal_return_load.php');

/** @return list<array{label: string, value: string, emphasis?: bool}> */
function mobile_return_print_meta_rows(array $ret): array
{
    require_once app_path('includes/helpers.php');
    $date = (string) ($ret['return_date'] ?? '');
    if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = format_date_dmY($date);
    }
    $cust = trim((string) ($ret['customer_name'] ?? ''));
    $inv = trim((string) ($ret['invoice_no'] ?? ''));

    return [
        ['label' => 'رقم المرتجع', 'value' => (string) ($ret['return_no'] ?? '')],
        ['label' => 'التاريخ', 'value' => $date],
        ['label' => 'العميل', 'value' => $cust, 'emphasis' => true],
        ['label' => 'فاتورة البيع', 'value' => $inv],
    ];
}

function mobile_return_print_meta_table(array $ret): string
{
    $html = '<div class="doc-print-meta"><table class="doc-print-meta-grid" cellpadding="0" cellspacing="0" style="width:100%;table-layout:fixed;border-collapse:collapse;direction:rtl;">'
        . '<colgroup><col style="width:32%"><col style="width:68%"></colgroup>';
    foreach (mobile_return_print_meta_rows($ret) as $row) {
        $html .= mobile_invoice_print_meta_row(
            (string) $row['label'],
            (string) ($row['value'] ?? ''),
            !empty($row['emphasis'])
        );
    }
    $html .= '</table></div>';

    return $html;
}

function mobile_return_print_meta_pdf(array $ret): string
{
    $tbl = 'width:100%;table-layout:fixed;border-collapse:collapse;direction:rtl;margin:0;';
    $lbl = 'width:32%;text-align:right;vertical-align:top;padding:2px 6px 3px 0;white-space:nowrap;'
        . 'font-size:10px;font-weight:700;border:none;line-height:1.4;';
    $val = 'width:68%;text-align:right;vertical-align:top;padding:2px 0 3px 6px;word-break:break-word;'
        . 'font-size:10px;font-weight:700;border:none;line-height:1.4;';
    $valParty = $val . 'font-weight:800;font-size:10.5px;';

    $html = '<div class="doc-print-meta m-pdf-meta"><table class="m-pdf-meta-tbl" cellpadding="0" cellspacing="0" style="' . $tbl . '">'
        . '<colgroup><col style="width:32%"><col style="width:68%"></colgroup>';
    foreach (mobile_return_print_meta_rows($ret) as $row) {
        $value = trim((string) ($row['value'] ?? ''));
        if ($value === '') {
            continue;
        }
        $valCls = 'm-pdf-meta-val' . (!empty($row['emphasis']) ? ' m-pdf-meta-val--party' : '');
        $valStyle = !empty($row['emphasis']) ? $valParty : $val;
        $html .= '<tr>'
            . '<td class="m-pdf-meta-lbl" style="' . $lbl . '">' . esc((string) $row['label']) . ':</td>'
            . '<td class="' . esc($valCls) . '" style="' . $valStyle . '">' . esc($value) . '</td>'
            . '</tr>';
    }
    $html .= '</table></div>';

    return $html;
}

function mobile_return_print_meta_mobile_pdf(array $ret): string
{
    $tbl = 'width:248px;border-collapse:collapse;direction:rtl;margin:0;';
    $td = 'text-align:right;padding:2px 0;font-size:10px;line-height:1.4;vertical-align:top;border:none;';

    $html = '<div class="doc-print-meta m-pdf-meta"><table class="m-pdf-meta-tbl" cellpadding="0" cellspacing="0" style="' . $tbl . '">';
    foreach (mobile_return_print_meta_rows($ret) as $row) {
        $value = trim((string) ($row['value'] ?? ''));
        if ($value === '') {
            continue;
        }
        $valCls = 'm-pdf-meta-val' . (!empty($row['emphasis']) ? ' m-pdf-meta-val--party' : '');
        $html .= '<tr><td style="' . $td . '">'
            . '<span class="m-pdf-meta-lbl">' . esc((string) $row['label']) . ':</span> '
            . '<span class="' . esc($valCls) . '">' . esc($value) . '</span></td></tr>';
    }
    $html .= '</table></div>';

    return $html;
}

function mobile_return_print_colgroup(): string
{
    $w = [12, 5, 28, 10, 11, 12, 10, 12];
    $html = '<colgroup>';
    foreach ($w as $pct) {
        $html .= '<col style="width:' . (int) $pct . '%">';
    }

    return $html . '</colgroup>';
}

function mobile_return_print_thead_row(): string
{
    return '<tr>'
        . '<th>Barcode</th><th>#</th><th>المادة</th><th>كمية الإرجاع</th>'
        . '<th>سعر الوحدة</th><th>قبل الضريبة</th><th>الضريبة</th><th>الإجمالي</th>'
        . '</tr>';
}

/** @param array<string, mixed> $ln */
function mobile_return_print_line_row(array $ln, int $seq, int $dp): string
{
    $name = (string) ($ln['name_ar'] ?? $ln['line_desc'] ?? '');
    $sku = (string) ($ln['barcode'] ?? '—');
    $qty = (float) ($ln['qty'] ?? 0);
    $sub = (float) ($ln['line_subtotal'] ?? 0);
    $tax = (float) ($ln['tax_amount'] ?? 0);
    $gross = (float) ($ln['line_gross'] ?? $sub + $tax);

    return '<tr>'
        . '<td class="inv-print-cell-sku">' . esc($sku) . '</td>'
        . '<td>' . $seq . '</td>'
        . '<td class="inv-print-cell-item">' . esc($name) . '</td>'
        . '<td>' . esc(mobile_invoice_print_fmt($qty, $dp)) . '</td>'
        . '<td>' . esc(mobile_invoice_print_fmt((float) ($ln['unit_price'] ?? 0), $dp)) . '</td>'
        . '<td>' . esc(mobile_invoice_print_fmt($sub, $dp)) . '</td>'
        . '<td>' . esc(mobile_invoice_print_fmt($tax, $dp)) . '</td>'
        . '<td>' . esc(mobile_invoice_print_fmt($gross, $dp)) . '</td>'
        . '</tr>';
}

/** @return list<array{label: string, value: string, grand?: bool}> */
function mobile_return_print_totals_rows(array $ret, int $dp): array
{
    return [
        [
            'label' => 'المجموع بدون ضريبة',
            'value' => mobile_invoice_print_fmt((float) ($ret['subtotal'] ?? 0), $dp),
        ],
        [
            'label' => 'مجموع الضريبة',
            'value' => mobile_invoice_print_fmt((float) ($ret['tax_amount'] ?? 0), $dp),
        ],
        [
            'label' => 'الإجمالي',
            'value' => mobile_invoice_print_fmt((float) ($ret['total'] ?? 0), $dp),
            'grand' => true,
        ],
    ];
}

function mobile_return_print_totals(array $ret, int $dp): string
{
    $html = '<div class="sales-inv-print-tot">';
    foreach (mobile_return_print_totals_rows($ret, $dp) as $row) {
        $cls = !empty($row['grand']) ? ' class="g"' : '';
        $html .= '<div' . $cls . '><span>' . esc($row['label']) . '</span><span>' . esc($row['value']) . '</span></div>';
    }
    $html .= '</div>';

    return $html;
}

function mobile_return_print_totals_pdf(array $ret, int $dp): string
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
    foreach (mobile_return_print_totals_rows($ret, $dp) as $row) {
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

function mobile_return_print_totals_mobile_pdf(array $ret, int $dp): string
{
    $wrap = 'text-align:right;margin:10px 0 0;direction:rtl;';
    $tbl = 'width:236px;border-collapse:collapse;table-layout:fixed;direction:rtl;margin:0;';
    $lbl = 'text-align:right;padding:5px 10px 5px 0;font-size:10px;font-weight:700;color:#475569;'
        . 'vertical-align:middle;border-bottom:1px solid #e2e8f0;border-top:none;';
    $val = 'text-align:left;padding:5px 0 5px 4px;font-size:10px;font-weight:700;vertical-align:middle;'
        . 'border-bottom:1px solid #e2e8f0;border-top:none;';
    $grandLbl = 'text-align:right;padding:7px 10px 7px 0;font-size:11px;font-weight:800;border:none;';
    $grandVal = 'text-align:left;padding:7px 0 7px 4px;font-size:11px;font-weight:800;border:none;';

    $html = '<div class="m-inv-pdf-tot-wrap" style="' . $wrap . '">'
        . '<table class="m-pdf-tot-tbl" cellpadding="0" cellspacing="0" style="' . $tbl . '">'
        . '<colgroup><col style="width:130px"><col style="width:106px"></colgroup>';
    foreach (mobile_return_print_totals_rows($ret, $dp) as $row) {
        $valInner = '<span dir="ltr" style="direction:ltr;unicode-bidi:embed;">' . esc($row['value']) . '</span>';
        if (!empty($row['grand'])) {
            $html .= '<tr class="m-pdf-tot-grand" style="border-top:2px solid #334155;">'
                . '<td class="m-pdf-tot-lbl" style="' . $grandLbl . '">' . esc($row['label']) . '</td>'
                . '<td class="m-pdf-tot-val" style="' . $grandVal . '">' . $valInner . '</td>'
                . '</tr>';
            continue;
        }
        $html .= '<tr>'
            . '<td class="m-pdf-tot-lbl" style="' . $lbl . '">' . esc($row['label']) . '</td>'
            . '<td class="m-pdf-tot-val" style="' . $val . '">' . $valInner . '</td>'
            . '</tr>';
    }
    $html .= '</table></div>';

    return $html;
}

function mobile_return_print_head_mobile_pdf(?PDO $pdo): string
{
    $brand = document_header_brand($pdo);
    $company = esc($brand['company_name_ar']);
    $h = min(64, (int) DOCUMENT_HEADER_LOGO_MAX_HEIGHT);
    $w = min(100, (int) DOCUMENT_HEADER_LOGO_MAX_WIDTH);
    $logoCell = '';
    if ($brand['logo_url'] !== null) {
        $logoCell = '<img src="' . esc($brand['logo_url']) . '" alt="" '
            . 'style="max-height:' . $h . 'px;max-width:' . $w . 'px;display:block;margin-right:auto;">';
    }

    $brandRow = '<table class="m-inv-pdf-head-brand" cellpadding="0" cellspacing="0" '
        . 'style="width:100%;border-collapse:collapse;table-layout:fixed;direction:rtl;">'
        . '<tr>'
        . '<td class="m-inv-pdf-head-co" style="width:62%;text-align:right;vertical-align:middle;'
        . 'font-size:15px;font-weight:900;line-height:1.4;border:none;color:#000;">' . $company . '</td>'
        . '<td class="m-inv-pdf-head-logo" style="width:38%;text-align:left;vertical-align:middle;border:none;">'
        . $logoCell . '</td>'
        . '</tr></table>';

    return '<header class="m-inv-pdf-head">'
        . '<div class="m-inv-pdf-head-top">' . $brandRow . '</div>'
        . '<div class="m-inv-pdf-title" role="heading" aria-level="1">مرتجع مبيعات</div></header>';
}

/**
 * @param array<string, mixed> $ret
 */
function mobile_return_print_inner_html(PDO $pdo, array $ret, bool $forPdf = false): string
{
    $dp = company_decimal_places($pdo);
    $colspan = 8;

    $linesHtml = '';
    $seq = 0;
    foreach ($ret['lines'] ?? [] as $ln) {
        if (!is_array($ln)) {
            continue;
        }
        $qty = (float) ($ln['qty'] ?? 0);
        if ($qty <= 0.000001) {
            continue;
        }
        $seq++;
        $linesHtml .= mobile_return_print_line_row($ln, $seq, $dp);
    }
    if ($linesHtml === '') {
        $linesHtml = '<tr><td colspan="' . $colspan . '" style="padding:1rem;text-align:center;color:#64748b;">لا توجد بنود</td></tr>';
    }

    $metaBlock = $forPdf ? mobile_return_print_meta_pdf($ret) : mobile_return_print_meta_table($ret);
    $einvBox = $forPdf
        ? mobile_invoice_print_einv_qr_mobile((string) ($ret['einv_qr'] ?? ''))
        : mobile_invoice_print_einv_qr_box((string) ($ret['einv_qr'] ?? ''));
    $headerBlock = mobile_invoice_print_header_block($metaBlock, $einvBox, $forPdf);

    $notes = trim((string) ($ret['notes'] ?? ''));
    $notesBlock = $notes !== ''
        ? ($forPdf
            ? '<p class="m-pdf-notes"><strong>ملاحظات:</strong> ' . esc($notes) . '</p>'
            : '<p style="margin:0.75rem 0 0;font-size:0.88rem;direction:rtl;unicode-bidi:isolate;"><strong>ملاحظات:</strong> <bdi>'
                . esc($notes) . '</bdi></p>')
        : '';

    $totalsBlock = $forPdf
        ? mobile_return_print_totals_pdf($ret, $dp)
        : mobile_return_print_totals($ret, $dp);

    $inner = document_print_header_html('مرتجع مبيعات', $pdo)
        . $headerBlock
        . '<table class="inv-print-lines">' . mobile_return_print_colgroup()
        . '<thead>' . mobile_return_print_thead_row() . '</thead><tbody>'
        . $linesHtml
        . '</tbody></table>'
        . $totalsBlock
        . $notesBlock
        . document_print_recipient_signature_html();

    $logoUrl = document_print_watermark_logo_url($pdo);
    if ($logoUrl !== null && !$forPdf) {
        return '<div class="doc-print-watermark-root">'
            . document_print_watermark_html($pdo)
            . $inner
            . '</div>';
    }

    return $inner;
}

/** @param array<string, mixed> $ret */
function mobile_return_print_inner_html_mobile_pdf(PDO $pdo, array $ret): string
{
    require_once app_path('includes/mobile_invoice_print_pdf_mobile.php');
    $dp = company_decimal_places($pdo);
    $colspan = 8;

    $head = mobile_return_print_head_mobile_pdf($pdo);
    $metaBlock = mobile_return_print_meta_mobile_pdf($ret);
    $einvBox = mobile_invoice_print_einv_qr_mobile((string) ($ret['einv_qr'] ?? ''));
    $headerBlock = mobile_invoice_print_header_block_mobile_pdf($metaBlock, $einvBox);

    $linesHtml = '';
    $seq = 0;
    foreach ($ret['lines'] ?? [] as $ln) {
        if (!is_array($ln)) {
            continue;
        }
        $qty = (float) ($ln['qty'] ?? 0);
        if ($qty <= 0.000001) {
            continue;
        }
        $seq++;
        $linesHtml .= mobile_return_print_line_row($ln, $seq, $dp);
    }
    if ($linesHtml === '') {
        $linesHtml = '<tr><td colspan="' . $colspan . '" style="padding:10px;text-align:center;color:#64748b;">لا توجد بنود</td></tr>';
    }

    $tableHtml = '<table class="inv-print-lines" cellpadding="0" cellspacing="0">'
        . mobile_return_print_colgroup()
        . '<thead>' . mobile_return_print_thead_row() . '</thead><tbody>'
        . $linesHtml
        . '</tbody></table>';

    $totalsBlock = mobile_return_print_totals_mobile_pdf($ret, $dp);

    $notes = trim((string) ($ret['notes'] ?? ''));
    $notesHtml = $notes !== ''
        ? '<p class="m-pdf-notes"><strong>ملاحظات:</strong> ' . esc($notes) . '</p>'
        : '';

    $sign = '<table class="m-inv-pdf-sign-wrap" cellpadding="0" cellspacing="0"><tr>'
        . '<td style="width:52%;border:none;"></td>'
        . '<td style="width:48%;border:none;text-align:left;vertical-align:top;">'
        . '<div class="m-inv-pdf-sign">'
        . '<span class="m-inv-pdf-sign-lbl">توقيع المستلم</span>'
        . '<span class="m-inv-pdf-sign-line"></span></div></td></tr></table>';

    return '<div class="m-inv-pdf-sheet" dir="rtl">'
        . $head
        . $headerBlock
        . $tableHtml
        . $totalsBlock
        . $notesHtml
        . $sign
        . '</div>';
}

/** @param array<string, mixed> $ret */
function mobile_return_print_document(PDO $pdo, array $ret): array
{
    require_once app_path('includes/mobile_invoice_print_pdf_mobile.php');

    $styles = mobile_invoice_print_styles($pdo);
    $inner = mobile_return_print_inner_html($pdo, $ret, false);
    $innerPdf = mobile_return_print_inner_html_mobile_pdf($pdo, $ret);
    $logoUrl = document_print_watermark_logo_url($pdo);
    $bodyClass = $logoUrl !== null ? ' class="has-doc-watermark doc-print-standalone"' : '';
    $full = '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>مرتجع مبيعات</title>'
        . '<style>' . $styles . '</style></head><body' . $bodyClass . '>' . $inner . '</body></html>';
    $htmlPdf = mobile_invoice_print_full_html_mobile_pdf($pdo, $innerPdf);
    $stylesPdf = mobile_invoice_print_mobile_pdf_css($pdo);

    return [
        'styles' => $styles,
        'styles_pdf' => $stylesPdf,
        'inner' => $inner,
        'inner_pdf' => $innerPdf,
        'html' => $full,
        'html_pdf' => $htmlPdf,
        'mobile_pdf' => true,
        'pdf_download_url' => '',
    ];
}
