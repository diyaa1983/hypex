<?php
declare(strict_types=1);

/**
 * تصميم فاتورة PDF للموبايل (mPDF) — جدول بنود مثل الويندوز، Arial، بدون علامة مائية.
 */

function mobile_invoice_print_recipient_signature_css(): string
{
    return '.m-inv-sign-center-wrap{width:100%;margin-top:14px;text-align:center;direction:rtl;page-break-inside:avoid;}'
        . '.m-inv-sign-center{display:inline-block;width:44%;max-width:210px;min-width:130px;text-align:center;}'
        . '.m-inv-sign-center .m-inv-sign-lbl{display:block;font-size:10px;font-weight:900;margin:0 0 1.35rem;color:#000;}'
        . '.m-inv-sign-center .m-inv-sign-line{display:block;width:100%;height:0;margin:0 auto;'
        . 'border:none;border-bottom:1.5px dotted #334155;-webkit-print-color-adjust:exact;print-color-adjust:exact;}';
}

function mobile_invoice_print_recipient_signature_html(): string
{
    return '<div class="m-inv-sign-center-wrap" role="group" aria-label="توقيع المستلم">'
        . '<div class="m-inv-sign-center">'
        . '<span class="m-inv-sign-lbl">توقيع المستلم</span>'
        . '<span class="m-inv-sign-line" aria-hidden="true"></span>'
        . '</div></div>';
}

function mobile_invoice_print_mobile_pdf_css(?PDO $pdo = null): string
{
    $h = min(64, (int) DOCUMENT_HEADER_LOGO_MAX_HEIGHT);
    $w = min(100, (int) DOCUMENT_HEADER_LOGO_MAX_WIDTH);

    $s = 'html,body{margin:0;padding:0;background:#fff;direction:rtl;}'
        . '#pdf-export-root,#m-inv-pdf-preview{'
        . 'box-sizing:border-box;width:100%;max-width:100%;margin:0;padding:10px 12px 14px;'
        . 'font-family:Arial,Helvetica,sans-serif;font-size:11px;line-height:1.45;font-weight:800;'
        . 'color:#000;background:#fff;direction:rtl;}'
        . '.m-inv-pdf-sheet{width:100%;box-sizing:border-box;direction:rtl;font-weight:800;color:#000;}'
        . '.m-inv-pdf-head{border:none;padding-bottom:4px;margin-bottom:10px;}'
        . '.m-inv-pdf-head-top{width:100%;padding-bottom:6px;border-bottom:1px solid #cbd5e1;margin-bottom:6px;}'
        . '.m-inv-pdf-head-brand{width:100%;border-collapse:collapse;table-layout:fixed;direction:rtl;}'
        . '.m-inv-pdf-head-brand td{border:none;vertical-align:middle;padding:0;}'
        . '.m-inv-pdf-head-co{width:62%;text-align:right;font-size:15px;font-weight:900;line-height:1.4;color:#000;}'
        . '.m-inv-pdf-head-logo{width:38%;text-align:left;vertical-align:middle;}'
        . '.m-inv-pdf-head-logo img{max-height:' . $h . 'px;max-width:' . $w . 'px;display:block;margin-left:0;margin-right:auto;}'
        . '.m-inv-pdf-title{margin:0;font-size:16px;font-weight:900;text-align:center;padding-top:4px;color:#000;'
        . 'border:none;border-bottom:none;outline:none;text-decoration:none;}'
        . '.m-inv-pdf-sheet .m-pdf-meta-tbl{width:248px;border-collapse:collapse;direction:rtl;margin:0;}'
        . '.m-inv-pdf-sheet .m-pdf-meta-tbl td{border:none;vertical-align:top;font-size:10px;line-height:1.4;'
        . 'padding:2px 0;text-align:right;font-weight:800;color:#000;}'
        . '.m-inv-pdf-sheet .m-pdf-meta-lbl{font-weight:800;color:#1e293b;}'
        . '.m-inv-pdf-sheet .m-pdf-meta-val{font-weight:800;word-break:break-word;color:#000;}'
        . '.m-inv-pdf-sheet .m-pdf-meta-val--party{font-weight:900;font-size:11px;}'
        . '.m-inv-pdf-sheet .m-pdf-header-split{width:100%;border-collapse:collapse;direction:rtl;margin:0 0 8px;'
        . 'table-layout:fixed;}'
        . '.m-inv-pdf-sheet .m-pdf-header-split td{border:none;vertical-align:top;padding:0;}'
        . '.m-inv-pdf-sheet .m-pdf-header-split-meta{width:248px;}'
        . '.m-inv-pdf-sheet .m-pdf-header-split-qr{width:100px;text-align:center;vertical-align:top;}'
        . '.m-inv-pdf-sheet .m-inv-pdf-qr-block{margin:0 auto;border-collapse:collapse;}'
        . '.m-inv-pdf-sheet .m-inv-pdf-qr-block td{border:none;vertical-align:middle;}'
        . '.m-inv-pdf-sheet table.inv-print-lines{border-collapse:collapse;width:100%;margin-top:6px;'
        . 'table-layout:fixed;direction:rtl;font-size:8px;}'
        . '.m-inv-pdf-sheet table.inv-print-lines th{background:#f1f5f9;padding:4px 3px;border:1px solid #94a3b8;'
        . 'text-align:center;font-weight:800;font-size:7.5px;color:#1e293b;white-space:nowrap;line-height:1.2;}'
        . '.m-inv-pdf-sheet table.inv-print-lines td{padding:4px 3px;border:1px solid #cbd5e1;text-align:center;'
        . 'font-size:8px;font-weight:800;color:#000;vertical-align:middle;line-height:1.25;}'
        . '.m-inv-pdf-sheet table.inv-print-lines td.inv-print-cell-item{text-align:right;word-break:break-word;white-space:normal;}'
        . '.m-inv-pdf-sheet table.inv-print-lines td.inv-print-cell-sku{font-family:Arial,Helvetica,sans-serif;}'
        . '.m-inv-pdf-sheet table.inv-print-lines td.inv-print-cell-disc{color:#b45309;}'
        . '.m-inv-pdf-sheet table.inv-print-lines td.inv-print-cell-tax-pct{font-size:7.5px;}'
        . '.m-inv-pdf-sheet table.inv-print-lines td:not(.inv-print-cell-item){white-space:nowrap;}'
        . '.m-inv-pdf-tot-wrap{text-align:right;margin:14px 0 0;direction:rtl;}'
        . '.m-inv-pdf-sheet table.inv-print-lines{margin-bottom:4px;}'
        . '.m-inv-pdf-sheet .m-pdf-tot-tbl{width:236px;border-collapse:collapse;table-layout:fixed;direction:rtl;margin:0;}'
        . '.m-inv-pdf-sheet .m-pdf-tot-tbl td{border:none;font-size:10px;font-weight:800;vertical-align:middle;'
        . 'border-bottom:1px solid #e2e8f0;color:#000;}'
        . '.m-inv-pdf-sheet .m-pdf-tot-tbl .m-pdf-tot-lbl{text-align:right;padding:5px 10px 5px 0;color:#1e293b;font-weight:800;}'
        . '.m-inv-pdf-sheet .m-pdf-tot-tbl .m-pdf-tot-val{text-align:left;padding:5px 0 5px 4px;}'
        . '.m-inv-pdf-sheet .m-pdf-tot-tbl .m-pdf-tot-val span{display:inline-block;direction:ltr;}'
        . '.m-inv-pdf-sheet .m-pdf-tot-tbl .m-pdf-tot-grand td{border-top:2px solid #334155;border-bottom:none;'
        . 'font-size:11px;font-weight:900;color:#000;}'
        . '.m-inv-pdf-sheet .m-pdf-tot-tbl .m-pdf-tot-grand .m-pdf-tot-lbl{padding-top:7px;}'
        . '.m-inv-pdf-sheet .m-pdf-tot-tbl .m-pdf-tot-grand .m-pdf-tot-val{padding-top:7px;}'
        . '.m-inv-pdf-sheet .m-pdf-notes{margin:8px 0 0;font-size:9px;line-height:1.4;text-align:right;font-weight:800;color:#000;}'
        . mobile_invoice_print_recipient_signature_css();

    return $s;
}

/** بيانات الفاتورة — عرض ثابت (التسمية بجانب القيمة) */
function mobile_invoice_print_meta_mobile_pdf(array $inv): string
{
    $tbl = 'width:248px;border-collapse:collapse;direction:rtl;margin:0;';
    $td = 'text-align:right;padding:2px 0;font-size:10px;line-height:1.4;vertical-align:top;border:none;';

    $html = '<div class="doc-print-meta m-pdf-meta"><table class="m-pdf-meta-tbl" cellpadding="0" cellspacing="0" style="' . $tbl . '">';
    foreach (mobile_invoice_print_meta_rows($inv, false) as $row) {
        $value = trim((string) ($row['value'] ?? ''));
        if ($value === '') {
            continue;
        }
        $valCls = 'm-pdf-meta-val' . (!empty($row['emphasis']) ? ' m-pdf-meta-val--party' : '');
        $rowStyle = ($row['row_class'] ?? '') === 'doc-print-meta-row-wh'
            ? ' style="border-bottom:1px solid #e2e8f0;"'
            : '';
        $html .= '<tr' . $rowStyle . '><td style="' . $td . '">'
            . '<span class="m-pdf-meta-lbl">' . esc((string) $row['label']) . ':</span> '
            . '<span class="' . esc($valCls) . '">' . esc($value) . '</span></td></tr>';
    }
    $html .= '</table></div>';

    return $html;
}

function mobile_invoice_print_header_block_mobile_pdf(string $metaHtml, string $einvBox): string
{
    if ($einvBox === '') {
        return $metaHtml;
    }

    $split = 'width:100%;border-collapse:collapse;direction:rtl;margin:0 0 8px;table-layout:fixed;';

    return '<table class="m-pdf-header-split" cellpadding="0" cellspacing="0" style="' . $split . '">'
        . '<colgroup><col style="width:248px"><col style="width:100px"></colgroup>'
        . '<tr><td class="m-pdf-header-split-meta" style="vertical-align:top;padding:0;border:none;">' . $metaHtml . '</td>'
        . '<td class="m-pdf-header-split-qr" style="vertical-align:top;padding:0;border:none;text-align:center;">' . $einvBox . '</td>'
        . '</tr></table>';
}

/** مجاميع — تباعد بسيط بين التسمية والمبلغ */
function mobile_invoice_print_totals_mobile_pdf(array $inv, array $layout, int $dp): string
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
    foreach (mobile_invoice_print_totals_rows($inv, $layout, $dp) as $row) {
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

function mobile_invoice_print_head_mobile_pdf(?PDO $pdo): string
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
        . '<div class="m-inv-pdf-title" role="heading" aria-level="1">فاتورة مبيعات</div></header>';
}

function mobile_invoice_print_einv_qr_mobile(?string $qrSrc): string
{
    $src = mobile_invoice_print_einv_qr_img_src($qrSrc);
    if ($src === null) {
        return '';
    }

    $img = EINV_PRINT_QR_IMG_PX;

    $wrap = 'margin:0 auto;border-collapse:collapse;border:none;background:transparent;';
    $qrBox = 'margin:0 auto;border-collapse:collapse;border:2px solid #0f172a;background:#fff;';
    $imgCell = 'padding:5px;text-align:center;vertical-align:middle;border:none;';
    $capCell = 'padding:6px 0 0;text-align:center;vertical-align:top;border:none;';
    $cap = 'font-size:8px;color:#64748b;font-weight:600;letter-spacing:0.2px;line-height:1.2;';

    return '<table class="m-inv-pdf-qr-block" cellpadding="0" cellspacing="0" style="' . $wrap . '">'
        . '<tr><td style="' . $imgCell . '">'
        . '<table cellpadding="0" cellspacing="0" style="' . $qrBox . '">'
        . '<tr><td style="padding:5px;text-align:center;border:none;">'
        . '<img src="' . esc($src) . '" alt="" width="' . $img . '" height="' . $img . '" style="width:' . $img . 'px;height:' . $img . 'px;display:block;margin:0 auto;">'
        . '</td></tr></table>'
        . '</td></tr>'
        . '<tr><td style="' . $capCell . '"><span style="' . $cap . '">Please Check In</span></td></tr>'
        . '</table>';
}

/** @param array<string, mixed> $inv */
function mobile_invoice_print_inner_html_mobile_pdf(PDO $pdo, array $inv): string
{
    $inv = mobile_invoice_enrich_display($pdo, $inv);
    $dp = (int) ($inv['amount_decimals'] ?? company_decimal_places($pdo));
    $layout = mobile_invoice_print_layout($inv);
    $showQtyExtra = $layout['show_qty_extra'];
    $showDiscount = $layout['show_discount'];
    $colspan = 9 + ($showQtyExtra ? 1 : 0) + ($showDiscount ? 1 : 0);

    $head = mobile_invoice_print_head_mobile_pdf($pdo);

    $metaBlock = mobile_invoice_print_meta_mobile_pdf($inv);
    $einvBox = mobile_invoice_print_einv_qr_mobile((string) ($inv['einv_qr'] ?? ''));
    $headerBlock = mobile_invoice_print_header_block_mobile_pdf($metaBlock, $einvBox);

    $linesHtml = '';
    $seq = 0;
    foreach ($inv['lines'] ?? [] as $ln) {
        if (!is_array($ln)) {
            continue;
        }
        $seq++;
        $linesHtml .= mobile_invoice_print_line_row($ln, $seq, $showQtyExtra, $showDiscount, $dp);
    }
    if ($linesHtml === '') {
        $linesHtml = '<tr><td colspan="' . $colspan . '" style="padding:10px;text-align:center;color:#64748b;">لا توجد بنود</td></tr>';
    }

    $tableHtml = '<table class="inv-print-lines" cellpadding="0" cellspacing="0">'
        . mobile_invoice_print_colgroup($showQtyExtra, $showDiscount)
        . '<thead>' . mobile_invoice_print_thead_row($showQtyExtra, $showDiscount) . '</thead><tbody>'
        . $linesHtml
        . '</tbody></table>';

    $totalsBlock = mobile_invoice_print_totals_mobile_pdf($inv, $layout, $dp);

    $notes = trim((string) ($inv['notes'] ?? ''));
    $notesHtml = $notes !== ''
        ? '<p class="m-pdf-notes"><strong>ملاحظات:</strong> ' . esc($notes) . '</p>'
        : '';

    $sign = mobile_invoice_print_recipient_signature_html();

    return '<div class="m-inv-pdf-sheet" dir="rtl">'
        . $head
        . $headerBlock
        . $tableHtml
        . $totalsBlock
        . $notesHtml
        . $sign
        . '</div>';
}

function mobile_invoice_print_full_html_mobile_pdf(PDO $pdo, string $inner): string
{
    return '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8">'
        . '<title>فاتورة مبيعات</title><style>' . mobile_invoice_print_mobile_pdf_css($pdo) . '</style></head>'
        . '<body dir="rtl"><div id="pdf-export-root" dir="rtl">' . $inner . '</div></body></html>';
}
