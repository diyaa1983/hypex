<?php
declare(strict_types=1);

require_once app_path('includes/document_header.php');
require_once app_path('includes/company_settings.php');
require_once app_path('includes/company_currency.php');
require_once app_path('includes/arabic_tafqit.php');
require_once app_path('includes/mobile_receipt.php');
require_once app_path('includes/fin_voucher_load.php');
require_once app_path('includes/fin_voucher_checks.php');
require_once app_path('includes/mobile_receipt_print_pdf_mobile.php');

/** أنماط سند القبض — مطابقة fin-receipt.js getPrintFrameStyles */
function mobile_receipt_print_receipt_css(): string
{
    return '.rcp-docinfo{width:100%;border-collapse:collapse;margin:0.6rem 0 0.4rem;border:none;}'
        . '.rcp-docinfo-cell{border:1px solid #94a3b8;padding:0.45rem 0.7rem;width:33.33%;text-align:center;background:#f8fafc;vertical-align:middle;}'
        . '.rcp-docinfo-lbl{display:block;font-size:0.78rem;color:#475569;font-weight:700;margin-bottom:2px;}'
        . '.rcp-docinfo-val{display:block;font-size:1rem;font-weight:800;color:#0f172a;letter-spacing:0.5px;}'
        . '.rcp-docinfo-status{vertical-align:middle;}'
        . '.rcp-status{display:inline-block;padding:0.18rem 0.7rem;border-radius:20px;font-size:0.78rem;font-weight:800;border:1.5px solid;}'
        . '.rcp-status-posted{color:#065f46;border-color:#10b981;background:#ecfdf5;}'
        . '.rcp-status-unposted{color:#92400e;border-color:#f59e0b;background:#fffbeb;}'
        . '.rcp-amount-box{display:flex;flex-direction:column;border:2px solid #0f172a;border-radius:6px;padding:0.55rem 1rem;margin:0.6rem 0;background:#fffbeb;}'
        . '.rcp-amount-row{display:flex;align-items:center;justify-content:space-between;width:100%;}'
        . '.rcp-amount-lbl{font-size:0.95rem;font-weight:800;color:#0f172a;}'
        . '.rcp-amount-val{font-size:1.4rem;font-weight:900;color:#0f172a;letter-spacing:1px;direction:ltr;display:inline-block;}'
        . '.rcp-amount-words{margin-top:0.45rem;padding-top:0.4rem;border-top:1px dashed #b45309;font-size:0.9rem;color:#1f2937;line-height:1.55;text-align:start;}'
        . '.rcp-amount-words-lbl{font-weight:800;color:#7c2d12;margin-inline-end:0.25rem;}'
        . '.rcp-amount-words-val{font-weight:700;}'
        . '.rcp-main{width:100%;border-collapse:collapse;margin-top:0.4rem;table-layout:fixed;}'
        . '.rcp-main .rcp-th{background:#f1f5f9;color:#0f172a;font-weight:800;font-size:0.85rem;padding:0.45rem 0.65rem;border:1px solid #94a3b8;text-align:start;width:22%;}'
        . '.rcp-main .rcp-td{padding:0.45rem 0.65rem;border:1px solid #cbd5e1;font-weight:700;font-size:0.92rem;color:#0f172a;text-align:start;}'
        . '.rcp-main .rcp-td-strong{font-size:1rem;font-weight:800;}'
        . '.rcp-main .rcp-td-reason{min-height:2.4rem;line-height:1.6;font-style:italic;color:#1e293b;}'
        . '.rcp-section-title{margin:0.85rem 0 0.3rem;font-weight:800;font-size:0.95rem;color:#0f172a;border-bottom:2px solid #0f172a;padding-bottom:0.2rem;}'
        . '.rcp-checks{width:100%;border-collapse:collapse;margin-top:0.25rem;}'
        . '.rcp-checks .rcp-chk-th{background:#e2e8f0;color:#0f172a;font-weight:800;font-size:0.82rem;padding:0.4rem 0.45rem;border:1px solid #64748b;text-align:center;}'
        . '.rcp-checks .rcp-chk-td{padding:0.4rem 0.45rem;border:1px solid #94a3b8;font-weight:700;font-size:0.9rem;text-align:center;color:#0f172a;}'
        . '.rcp-checks tbody tr:nth-child(even) .rcp-chk-td{background:#f8fafc;}'
        . '.rcp-chk-no-col{width:3rem;}'
        . '.rcp-chk-amount{font-family:Arial,Helvetica,sans-serif;direction:ltr;}'
        . '.rcp-chk-total-label{text-align:end!important;font-weight:800;background:#f1f5f9;}'
        . '.rcp-chk-total-val{font-weight:900;background:#fffbeb;font-size:1rem;}'
        . '.rcp-signs{width:100%;margin-top:1.6rem;border:none;border-collapse:separate;border-spacing:1.5rem 0;}'
        . '.rcp-signs .rcp-sign-cell{border:none!important;text-align:center;padding:0;vertical-align:bottom;width:50%;}'
        . '.rcp-sign-lbl{display:block;font-size:0.85rem;font-weight:800;color:#0f172a;margin-bottom:1.6rem;}'
        . '.rcp-sign-line{display:block;border-top:1.5px solid #0f172a;width:90%;margin:0 auto;}'
        . '.rcp-print-root{position:relative;}'
        . '.rcp-print-root .doc-print-watermark--overlay{position:absolute;inset:0;z-index:0;display:flex;align-items:center;justify-content:center;pointer-events:none;}'
        . '.rcp-print-root .doc-print-watermark--overlay img{width:min(72%,460px);max-width:460px;max-height:400px;object-fit:contain;opacity:0.12;filter:grayscale(0.12) contrast(0.92);}'
        . '.rcp-print-root .doc-print-header,.rcp-print-root .rcp-docinfo,.rcp-print-root .rcp-amount-box,'
        . '.rcp-print-root .rcp-main,.rcp-print-root .rcp-checks,.rcp-print-root .rcp-signs{position:relative;z-index:1;}';
}

/** أنماط مضغوطة لتصدير PDF على الموبايل — سند كامل في صفحة واحدة */
function mobile_receipt_print_receipt_pdf_compact_css(): string
{
    $s = '.rcp-print-root--pdf,.rcp-print-root--pdf *{box-sizing:border-box;}'
        . '.rcp-print-root--pdf{font-size:12px;line-height:1.4;}'
        . '.rcp-print-root--pdf .doc-print-header-title{font-size:0.95rem!important;padding-top:0.25rem!important;}'
        . '.rcp-print-root--pdf .doc-print-header-co{font-size:0.9rem!important;}'
        . '.rcp-print-root--pdf .doc-print-header-top{padding-bottom:0.3rem!important;margin-bottom:0.25rem!important;}'
        . '.rcp-print-root--pdf .rcp-docinfo{margin:0.35rem 0 0.3rem!important;}'
        . '.rcp-print-root--pdf .rcp-docinfo-cell{padding:3px 5px!important;}'
        . '.rcp-print-root--pdf .rcp-docinfo-lbl{font-size:0.68rem!important;margin-bottom:1px!important;}'
        . '.rcp-print-root--pdf .rcp-docinfo-val{font-size:0.82rem!important;}'
        . '.rcp-print-root--pdf .rcp-status{font-size:0.65rem!important;padding:0.1rem 0.45rem!important;}'
        . '.rcp-print-root--pdf .rcp-amount-box{padding:6px 8px!important;margin:0.35rem 0!important;border-width:1.5px!important;}'
        . '.rcp-print-root--pdf .rcp-amount-words{margin-top:4px!important;padding-top:4px!important;font-size:0.78rem!important;line-height:1.4!important;}'
        . '.rcp-print-root--pdf .rcp-main{margin-top:0.25rem!important;}'
        . '.rcp-print-root--pdf .rcp-main .rcp-th{padding:3px 5px!important;font-size:0.72rem!important;width:20%!important;}'
        . '.rcp-print-root--pdf .rcp-main .rcp-td{padding:3px 5px!important;font-size:0.78rem!important;word-wrap:break-word;overflow-wrap:break-word;}'
        . '.rcp-print-root--pdf .rcp-main .rcp-td-strong{font-size:0.82rem!important;}'
        . '.rcp-print-root--pdf .rcp-main .rcp-td-reason{min-height:1.5rem!important;line-height:1.35!important;font-size:0.78rem!important;}'
        . '.rcp-print-root--pdf .rcp-section-title{margin:0.45rem 0 0.2rem!important;font-size:0.82rem!important;padding-bottom:2px!important;}'
        . '.rcp-print-root--pdf .rcp-checks .rcp-chk-th{padding:3px 4px!important;font-size:0.7rem!important;}'
        . '.rcp-print-root--pdf .rcp-checks .rcp-chk-td{padding:3px 4px!important;font-size:0.75rem!important;}'
        . '.rcp-print-root--pdf .rcp-chk-total-val{font-size:0.82rem!important;}'
        . '.rcp-print-root--pdf .rcp-signs{margin-top:0.75rem!important;}'
        . '.rcp-print-root--pdf .rcp-sign-lbl{margin-bottom:0.85rem!important;font-size:0.78rem!important;}'
        . '#pdf-export-root .rcp-print-root--pdf,#m-rc-list-pdf-preview .rcp-print-root--pdf,#m-rc-pdf-preview .rcp-print-root--pdf{font-size:12px!important;}';

    return $s;
}

function mobile_receipt_print_styles(?PDO $pdo = null): string
{
    return document_print_watermark_root_css($pdo)
        . document_print_header_css()
        . document_print_watermark_css()
        . mobile_receipt_print_receipt_css();
}

function mobile_receipt_print_page_css(): string
{
    return 'body{font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;color:#0f172a;margin:6mm 12mm;direction:rtl;}'
        . 'body.doc-print-standalone.has-doc-watermark{-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
        . '.rcp-print-root .doc-print-watermark--overlay{display:none!important;}'
        . '@media print{body.doc-print-standalone{margin:6mm 12mm;}.rcp-print-root .doc-print-watermark--overlay{display:none!important;}}';
}

function mobile_receipt_print_pdf_frame_css(?PDO $pdo = null): string
{
    $h = min(72, (int) DOCUMENT_HEADER_LOGO_MAX_HEIGHT);
    $w = min(100, (int) DOCUMENT_HEADER_LOGO_MAX_WIDTH);

    return 'html,body{margin:0;padding:6px 8px;background:#fff;direction:rtl;'
        . 'font-family:Arial,Helvetica,sans-serif;font-size:11px;font-weight:700;color:#0f172a;}'
        . '#pdf-export-root{position:relative;box-sizing:border-box;width:680px;max-width:680px;min-height:1px;overflow:visible;}'
        . '#pdf-export-root .rcp-print-root{position:relative;box-sizing:border-box;width:100%;overflow:visible;}'
        . '#pdf-export-root .doc-print-header-top{padding-bottom:0.3rem;border-bottom:1px solid #cbd5e1;}'
        . '#pdf-export-root .doc-print-header-brand{display:table;width:100%;table-layout:fixed;border-collapse:collapse;}'
        . '#pdf-export-root .doc-print-header-co{display:table-cell;width:62%;vertical-align:middle;text-align:right;font-weight:800;font-size:0.9rem;}'
        . '#pdf-export-root .doc-print-header-logo{display:table-cell;width:38%;vertical-align:middle;text-align:left;}'
        . '#pdf-export-root .doc-print-header-logo img{max-height:' . min(56, $h) . 'px;max-width:' . min(80, $w) . 'px;display:block;margin-left:auto;}'
        . '#pdf-export-root .doc-print-header-title{text-align:center;font-weight:700;font-size:0.95rem;padding-top:0.25rem;margin:0;}'
        . '#pdf-export-root .doc-print-watermark--overlay{position:absolute;inset:0;z-index:0;display:flex;align-items:center;justify-content:center;pointer-events:none;min-height:0!important;height:auto!important;max-height:none!important;overflow:visible!important;}'
        . '#pdf-export-root .doc-print-watermark--overlay img{width:min(65%,380px);max-width:380px;max-height:320px;height:auto;object-fit:contain;opacity:0.1;}'
        . '#pdf-export-root .doc-print-header,#pdf-export-root .rcp-docinfo,#pdf-export-root .rcp-amount-box,'
        . '#pdf-export-root .rcp-main,#pdf-export-root .rcp-checks,#pdf-export-root .rcp-signs{position:relative;z-index:1;}'
        . mobile_receipt_print_receipt_css()
        . mobile_receipt_print_receipt_pdf_compact_css();
}

function mobile_receipt_print_header_html(string $title, ?PDO $pdo, bool $forPdf): string
{
    $brand = document_header_brand($pdo);
    $company = esc($brand['company_name_ar']);
    $titleEsc = esc($title);
    $logoInner = '';
    if ($brand['logo_url'] !== null) {
        $lh = min(72, (int) DOCUMENT_HEADER_LOGO_MAX_HEIGHT);
        $lw = min(100, (int) DOCUMENT_HEADER_LOGO_MAX_WIDTH);
        $logoInner = '<img src="' . esc($brand['logo_url']) . '" alt="" style="max-height:' . $lh
            . 'px;max-width:' . $lw . 'px;width:auto;height:auto;object-fit:contain;display:block;">';
    }

    if ($forPdf) {
        return '<header class="doc-print-header">'
            . '<div class="doc-print-header-top">'
            . '<div class="doc-print-header-brand" style="display:table;width:100%;table-layout:fixed;border-collapse:collapse;">'
            . '<div class="doc-print-header-co" style="display:table-cell;width:62%;vertical-align:middle;text-align:right;font-weight:800;">'
            . $company . '</div>'
            . '<div class="doc-print-header-logo" style="display:table-cell;width:38%;vertical-align:middle;text-align:left;">'
            . $logoInner . '</div></div></div>'
            . '<div class="doc-print-header-title" style="text-align:center;font-weight:700;font-size:1.1rem;padding-top:0.4rem;">'
            . $titleEsc . '</div></header>';
    }

    return '<header class="doc-print-header" role="banner">'
        . '<div class="doc-print-header-top">'
        . '<div class="doc-print-header-brand">'
        . '<div class="doc-print-header-co">' . $company . '</div>'
        . '<div class="doc-print-header-logo">' . $logoInner . '</div>'
        . '</div></div>'
        . '<div class="doc-print-header-title">' . $titleEsc . '</div>'
        . '</header>';
}

function mobile_receipt_print_watermark_overlay(?PDO $pdo): string
{
    $url = document_print_watermark_logo_url($pdo);
    if ($url === null) {
        return '';
    }

    return '<div class="doc-print-watermark doc-print-watermark--overlay" aria-hidden="true">'
        . '<img src="' . esc($url) . '" alt=""></div>';
}

/**
 * @param array<string, mixed> $v
 * @param list<array<string, mixed>> $checks
 */
function mobile_receipt_print_inner_html(PDO $pdo, array $v, array $checks, bool $forPdf = false): string
{
    $v = mobile_receipt_enrich_display($pdo, $v);
    $dp = company_decimal_places($pdo);
    $fmt = static fn (float $n): string => number_format(round($n, $dp), $dp, '.', ',');

    $date = (string) ($v['voucher_date'] ?? '');
    if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = format_date_dmY($date);
    }
    $no = (string) ($v['voucher_no'] ?? '—');
    $cust = (string) ($v['customer_name'] ?? '');
    $code = (string) ($v['customer_code'] ?? '');
    if ($code !== '') {
        $cust .= ' (' . $code . ')';
    }
    $rep = trim((string) ($v['sales_rep_name'] ?? ''));
    $pay = fin_voucher_pay_method_label((string) ($v['pay_method'] ?? 'cash'));
    $amount = (float) ($v['amount'] ?? 0);
    $amountFmt = $fmt($amount);
    $amountWords = arabic_tafqit_amount($amount, $pdo);
    $notes = trim((string) ($v['description'] ?? $v['notes'] ?? ''));
    $posted = !empty($v['is_posted']);
    $postedTag = $posted
        ? '<span class="rcp-status rcp-status-posted">مرحّل</span>'
        : '<span class="rcp-status rcp-status-unposted">غير مرحّل</span>';

    if ($forPdf) {
        $cell = 'border:1px solid #94a3b8;padding:3px 5px;text-align:center;background:#f8fafc;vertical-align:middle;';
        $docMargin = 'margin:0.35rem 0 0.3rem;';
        $amtBoxStyle = 'display:block;border:1.5px solid #0f172a;border-radius:4px;padding:6px 8px;margin:0.35rem 0;background:#fffbeb;';
        $amtLblFs = 'font-size:0.82rem;';
        $amtValFs = 'font-size:1.05rem;';
        $amtWordsStyle = 'margin-top:4px;padding-top:4px;border-top:1px dashed #b45309;font-size:0.78rem;line-height:1.4;text-align:start;';
        $th = 'style="background:#f1f5f9;padding:3px 5px;border:1px solid #94a3b8;text-align:right;font-weight:800;width:20%;font-size:0.72rem;"';
        $td = 'style="padding:3px 5px;border:1px solid #cbd5e1;text-align:right;font-weight:700;font-size:0.78rem;"';
        $tdStrong = 'style="padding:3px 5px;border:1px solid #cbd5e1;text-align:right;font-weight:800;font-size:0.82rem;"';
        $tdReason = 'style="padding:3px 5px;border:1px solid #cbd5e1;text-align:right;font-weight:700;font-style:italic;line-height:1.35;min-height:1.5rem;font-size:0.78rem;"';
        $mainMargin = 'margin-top:0.25rem;';
        $chkTh = 'style="background:#e2e8f0;border:1px solid #64748b;padding:3px 4px;text-align:center;font-weight:800;font-size:0.7rem;"';
        $chkTd = 'style="border:1px solid #94a3b8;padding:3px 4px;text-align:center;font-weight:700;font-size:0.75rem;"';
        $secTitleStyle = 'margin:0.45rem 0 0.2rem;font-weight:800;border-bottom:1.5px solid #0f172a;padding-bottom:2px;font-size:0.82rem;';
        $signMargin = 'margin-top:0.75rem;';
        $signLblMb = 'margin-bottom:0.85rem;';
        $signLblFs = 'font-size:0.78rem;';
    } else {
        $cell = 'border:1px solid #94a3b8;padding:8px;text-align:center;background:#f8fafc;vertical-align:middle;';
        $docMargin = 'margin:0.6rem 0 0.4rem;';
        $amtBoxStyle = 'display:block;border:2px solid #0f172a;border-radius:6px;padding:10px 14px;margin:8px 0;background:#fffbeb;';
        $amtLblFs = 'font-size:0.95rem;';
        $amtValFs = 'font-size:1.35rem;';
        $amtWordsStyle = 'margin-top:8px;padding-top:8px;border-top:1px dashed #b45309;font-size:0.9rem;line-height:1.55;text-align:start;';
        $th = 'style="background:#f1f5f9;padding:8px;border:1px solid #94a3b8;text-align:right;font-weight:800;width:22%;font-size:0.85rem;"';
        $td = 'style="padding:8px;border:1px solid #cbd5e1;text-align:right;font-weight:700;font-size:0.92rem;"';
        $tdStrong = 'style="padding:8px;border:1px solid #cbd5e1;text-align:right;font-weight:800;font-size:1rem;"';
        $tdReason = 'style="padding:8px;border:1px solid #cbd5e1;text-align:right;font-weight:700;font-style:italic;line-height:1.6;min-height:2.4rem;"';
        $mainMargin = 'margin-top:6px;';
        $chkTh = 'style="background:#e2e8f0;border:1px solid #64748b;padding:6px;text-align:center;font-weight:800;font-size:0.82rem;"';
        $chkTd = 'style="border:1px solid #94a3b8;padding:6px;text-align:center;font-weight:700;font-size:0.9rem;"';
        $secTitleStyle = 'margin:12px 0 4px;font-weight:800;border-bottom:2px solid #0f172a;padding-bottom:4px;';
        $signMargin = 'margin-top:1.6rem;';
        $signLblMb = 'margin-bottom:1.6rem;';
        $signLblFs = '';
    }

    $docInfo = '<table class="rcp-docinfo" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;table-layout:fixed;' . $docMargin . '">'
        . '<tr>'
        . '<td class="rcp-docinfo-cell" style="' . $cell . 'width:33%;">'
        . '<span class="rcp-docinfo-lbl">رقم السند</span><span class="rcp-docinfo-val">' . esc($no) . '</span></td>'
        . '<td class="rcp-docinfo-cell" style="' . $cell . 'width:33%;">'
        . '<span class="rcp-docinfo-lbl">التاريخ</span><span class="rcp-docinfo-val">' . esc($date) . '</span></td>'
        . '<td class="rcp-docinfo-cell rcp-docinfo-status" style="' . $cell . 'width:34%;">' . $postedTag . '</td>'
        . '</tr></table>';

    $amountBox = '<div class="rcp-amount-box" style="' . $amtBoxStyle . '">'
        . '<table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;border:none;">'
        . '<tr><td style="border:none;text-align:right;font-weight:800;' . $amtLblFs . 'width:42%;">مبلغ وقدره</td>'
        . '<td style="border:none;text-align:center;font-weight:900;' . $amtValFs . 'direction:ltr;">' . esc($amountFmt) . '</td></tr>'
        . '</table>'
        . '<div class="rcp-amount-words" style="' . $amtWordsStyle . '">'
        . '<span class="rcp-amount-words-lbl" style="font-weight:800;color:#7c2d12;">تفقيطاً:</span> '
        . '<span class="rcp-amount-words-val" style="font-weight:700;">' . esc($amountWords) . '</span></div></div>';

    $details = '<table class="rcp-main" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;table-layout:fixed;' . $mainMargin . '"><tbody>'
        . '<tr><th class="rcp-th" ' . $th . '>استلمنا من السيد/السادة</th>'
        . '<td class="rcp-td rcp-td-strong" colspan="3" ' . $tdStrong . '>' . esc($cust) . '</td></tr>'
        . '<tr><th class="rcp-th" ' . $th . '>طريقة الدفع</th><td class="rcp-td" ' . $td . '>' . esc($pay) . '</td>'
        . '<th class="rcp-th" ' . $th . '>المندوب</th><td class="rcp-td" ' . $td . '>' . esc($rep !== '' ? $rep : '—') . '</td></tr>'
        . '<tr><th class="rcp-th" ' . $th . '>وذلك عن</th>'
        . '<td class="rcp-td rcp-td-reason" colspan="3" ' . $tdReason . '>' . esc($notes !== '' ? $notes : '—') . '</td></tr>'
        . '</tbody></table>';

    $checksHtml = '';
    if ($pay === 'شيك' && $checks !== []) {
        $checksHtml = '<div class="rcp-section-title" style="' . $secTitleStyle . '">تفاصيل الشيكات</div>'
            . '<table class="rcp-checks" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;table-layout:fixed;">'
            . '<thead><tr>'
            . '<th class="rcp-chk-th rcp-chk-no-col" ' . $chkTh . '>#</th>'
            . '<th class="rcp-chk-th" ' . $chkTh . '>رقم الشيك</th>'
            . '<th class="rcp-chk-th" ' . $chkTh . '>المبلغ</th>'
            . '<th class="rcp-chk-th" ' . $chkTh . '>البنك</th>'
            . '<th class="rcp-chk-th" ' . $chkTh . '>تاريخ الاستحقاق</th>'
            . '</tr></thead><tbody>';
        $total = 0.0;
        $i = 0;
        foreach ($checks as $chk) {
            $i++;
            $total += (float) ($chk['check_amount'] ?? 0);
            $due = (string) ($chk['due_date'] ?? '');
            if ($due !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
                $due = format_date_dmY($due);
            }
            $bg = $i % 2 === 0 ? 'background:#f8fafc;' : 'background:#fff;';
            $checksHtml .= '<tr>'
                . '<td class="rcp-chk-td rcp-chk-no-col" ' . $chkTd . ' style="' . $bg . '">' . $i . '</td>'
                . '<td class="rcp-chk-td" ' . $chkTd . ' style="' . $bg . '">' . esc((string) ($chk['check_no'] ?? '—')) . '</td>'
                . '<td class="rcp-chk-td rcp-chk-amount" ' . $chkTd . ' style="' . $bg . 'direction:ltr;">' . esc($fmt((float) ($chk['check_amount'] ?? 0))) . '</td>'
                . '<td class="rcp-chk-td" ' . $chkTd . ' style="' . $bg . '">' . esc((string) ($chk['bank_name'] ?? '—')) . '</td>'
                . '<td class="rcp-chk-td" ' . $chkTd . ' style="' . $bg . 'direction:ltr;">' . esc($due !== '' ? $due : '—') . '</td>'
                . '</tr>';
        }
        $checksHtml .= '</tbody><tfoot><tr>'
            . '<td class="rcp-chk-td rcp-chk-total-label" colspan="2" ' . $chkTd . ' style="text-align:end;font-weight:800;background:#f1f5f9;">إجمالي الشيكات</td>'
            . '<td class="rcp-chk-td rcp-chk-amount rcp-chk-total-val" ' . $chkTd . ' style="font-weight:900;background:#fffbeb;direction:ltr;">'
            . esc($fmt($total)) . '</td>'
            . '<td class="rcp-chk-td" colspan="2" ' . $chkTd . '></td></tr></tfoot></table>';
    }

    $signTd = 'style="border:none;text-align:center;vertical-align:bottom;width:50%;padding:0;"';
    $signLblStyle = 'display:block;font-weight:800;' . $signLblMb . ($signLblFs !== '' ? $signLblFs : '');
    $signatures = '<table class="rcp-signs" cellpadding="0" cellspacing="0" style="width:100%;' . $signMargin . 'border:none;border-collapse:separate;border-spacing:16px 0;">'
        . '<tr>'
        . '<td class="rcp-sign-cell" ' . $signTd . '>'
        . '<span class="rcp-sign-lbl" style="' . $signLblStyle . '">اسم المستلم</span>'
        . '<span class="rcp-sign-line" style="display:block;border-top:1.5px solid #0f172a;width:90%;margin:0 auto;"></span></td>'
        . '<td class="rcp-sign-cell" ' . $signTd . '>'
        . '<span class="rcp-sign-lbl" style="' . $signLblStyle . '">التوقيع</span>'
        . '<span class="rcp-sign-line" style="display:block;border-top:1.5px solid #0f172a;width:90%;margin:0 auto;"></span></td>'
        . '</tr></table>';

    $content = mobile_receipt_print_header_html('سند قبض', $pdo, $forPdf)
        . $docInfo
        . $amountBox
        . $details
        . $checksHtml
        . $signatures;

    $wm = $forPdf ? mobile_receipt_print_watermark_overlay($pdo) : '';
    $scopeClass = $forPdf ? ' doc-print-watermark-scope rcp-print-root--pdf' : '';

    return '<div class="rcp-print-root' . $scopeClass . '">' . $wm . $content . '</div>';
}

function mobile_receipt_print_full_html(PDO $pdo, string $inner, bool $forPdf): string
{
    if ($forPdf) {
        return '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>سند قبض</title>'
            . '<style>' . mobile_receipt_print_pdf_frame_css($pdo) . '</style></head><body>'
            . '<div id="pdf-export-root">' . $inner . '</div></body></html>';
    }

    $styles = mobile_receipt_print_styles($pdo) . mobile_receipt_print_page_css();
    $logoUrl = document_print_watermark_logo_url($pdo);
    $bodyClass = 'doc-print-standalone';
    if ($logoUrl !== null) {
        $bodyClass .= ' has-doc-watermark';
    }

    return '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>سند قبض</title>'
        . '<style>' . $styles . '</style></head><body class="' . $bodyClass . '">' . $inner . '</body></html>';
}

/** @return array{styles: string, styles_pdf: string, inner: string, inner_pdf: string, html: string, html_pdf: string}|null */
function mobile_receipt_print_document(PDO $pdo, int $voucherId): ?array
{
    $row = fin_voucher_fetch_by_id($pdo, $voucherId, 'receipt');
    if (!$row) {
        return null;
    }
    $checks = [];
    if ((string) ($row['pay_method'] ?? '') === 'check') {
        $checks = fin_voucher_checks_load($pdo, $voucherId);
    }
    $row['is_posted'] = fin_voucher_is_posted($pdo, $voucherId);

    $inner = mobile_receipt_print_inner_html($pdo, $row, $checks, false);
    $innerPdf = mobile_receipt_print_inner_html_mobile_pdf($pdo, $row, $checks);
    $html = mobile_receipt_print_full_html($pdo, $inner, false);
    $htmlPdf = mobile_receipt_print_full_html_mobile_pdf($pdo, $innerPdf);

    return [
        'styles' => mobile_receipt_print_styles($pdo) . mobile_receipt_print_page_css(),
        'styles_pdf' => mobile_receipt_print_mobile_pdf_css($pdo),
        'inner' => $inner,
        'inner_pdf' => $innerPdf,
        'html' => $html,
        'html_pdf' => $htmlPdf,
        'mobile_pdf' => true,
        'pdf_download_url' => app_url('api/mobile_receipt_pdf.php?id=' . $voucherId),
    ];
}
