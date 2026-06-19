<?php
declare(strict_types=1);

require_once app_path('includes/document_header.php');
require_once app_path('includes/crm_party_statement.php');

function mobile_party_statement_print_styles(?PDO $pdo = null): string
{
    return document_print_watermark_root_css($pdo)
        . document_print_header_css()
        . document_print_watermark_css()
        . mobile_party_statement_print_report_css();
}

function mobile_party_statement_print_page_css(): string
{
    $h = min(64, (int) DOCUMENT_HEADER_LOGO_MAX_HEIGHT);
    $w = min(96, (int) DOCUMENT_HEADER_LOGO_MAX_WIDTH);

    return 'body{font-family:Arial,Helvetica,sans-serif;font-size:12px;font-weight:700;color:#0f172a;margin:6mm 10mm;direction:rtl;}'
        . 'body.doc-print-standalone.has-doc-watermark{-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
        . '.party-stmt-page .doc-print-header-top{padding-bottom:0.45rem;border-bottom:1px solid #cbd5e1;}'
        . '.party-stmt-page .doc-print-header-brand{display:table !important;width:100%;table-layout:fixed;border-collapse:collapse;direction:ltr;}'
        . '.party-stmt-page .doc-print-header-logo{display:table-cell !important;width:26%;vertical-align:middle;text-align:left;padding:0 0 0 0;}'
        . '.party-stmt-page .doc-print-header-co{display:table-cell !important;width:74%;vertical-align:middle;text-align:right !important;'
        . 'direction:rtl;font-weight:800;font-size:1.05rem;padding:0 0 0 0.35rem;line-height:1.35;}'
        . '.party-stmt-page .doc-print-header-logo img{max-height:' . $h . 'px;max-width:' . $w . 'px;width:auto;height:auto;display:block;margin:0;}'
        . '.party-stmt-page .doc-print-header-title{text-align:center;font-weight:700;font-size:1.1rem;padding-top:0.4rem;margin:0;}';
}

/** أنماط كشف الحساب — مطابقة report-sales.css / ويندوز */
function mobile_party_statement_print_report_css(): string
{
    return '.party-stmt-page .doc-print-header-title,.report-sales-print-area .doc-print-header-title{'
        . 'text-align:center;font-size:1.35rem;font-weight:800;padding-top:0.5rem;margin-bottom:0.15rem;}'
        . '.party-stmt-report-head{text-align:center;margin:0.25rem 0 1rem;padding:0;}'
        . '.party-stmt-report-customer{margin:0 0 0.4rem;font-size:1.12rem;font-weight:800;color:#0f172a;line-height:1.35;}'
        . '.party-stmt-report-dates{margin:0;font-size:0.95rem;font-weight:600;color:#334155;line-height:1.5;}'
        . '.party-stmt-report-dates-sep{margin:0 0.5rem;color:#94a3b8;}'
        . '.doc-print-watermark-scope{position:relative;}'
        . '.report-sales-table,.party-stmt-table{width:100%;border-collapse:collapse;direction:rtl;table-layout:fixed;font-size:11px;font-weight:700;}'
        . '.report-sales-table th,.party-stmt-table th{background:#f1f5f9;padding:0.4rem 0.35rem;border:1px solid #94a3b8;text-align:center;font-weight:700;font-size:9px;color:#475569;}'
        . '.report-sales-table td,.party-stmt-table td{padding:0.38rem 0.35rem;border:1px solid #cbd5e1;text-align:center;vertical-align:middle;background:#fff;font-weight:700;font-size:9px;}'
        . '.party-stmt-table tbody tr:nth-child(even) td{background:#fafbff;}'
        . '.party-stmt-table .col-date{width:10%;font-variant-numeric:tabular-nums;white-space:nowrap;}'
        . '.party-stmt-table .col-desc{width:22%;text-align:start;}'
        . '.party-stmt-table td.col-desc{text-align:start;word-break:break-word;}'
        . '.party-stmt-table .col-doc{width:16%;}'
        . '.party-stmt-table .col-money{width:13%;font-variant-numeric:tabular-nums;}'
        . '.party-stmt-table td.col-money{text-align:center;}'
        . '.party-stmt-doc-cell{text-align:start;vertical-align:middle;}'
        . '.party-stmt-doc-kind{display:block;font-size:0.72rem;color:#64748b;font-weight:600;margin-bottom:0.12rem;}'
        . '.party-stmt-doc-no-wrap{display:block;line-height:1.35;}'
        . '.party-stmt-doc-no{display:inline;font-size:0.88rem;font-weight:700;font-family:Arial,Helvetica,sans-serif;}'
        . '.party-stmt-doc-hint{display:block;margin-top:0.1rem;font-size:0.72rem;font-weight:500;color:#64748b;line-height:1.3;}'
        . '.party-stmt-opening td{background:#fafbff!important;}'
        . '.party-stmt-tfoot .party-stmt-totals td{background:#e8edf5;border-top:2px solid #94a3b8;padding:0.5rem 0.35rem;vertical-align:middle;}'
        . '.party-stmt-foot-label{display:block;font-size:0.72rem;font-weight:600;color:#475569;margin-bottom:0.15rem;}'
        . '.party-stmt-foot-value{display:block;font-size:0.95rem;font-variant-numeric:tabular-nums;}'
        . '.party-stmt-tfoot td.col-money{text-align:center;}'
        . '.report-sales-table-wrap{width:100%;overflow:visible;}';
}

function mobile_party_statement_print_pdf_frame_css(?PDO $pdo = null): string
{
    $h = min(72, (int) DOCUMENT_HEADER_LOGO_MAX_HEIGHT);
    $w = min(100, (int) DOCUMENT_HEADER_LOGO_MAX_WIDTH);

    return 'html,body{margin:0;padding:10px 12px;background:#fff;direction:rtl;'
        . 'font-family:Arial,Helvetica,sans-serif;font-size:11px;font-weight:700;color:#0f172a;}'
        . '#pdf-export-root{position:relative;box-sizing:border-box;width:680px;max-width:680px;}'
        . '#pdf-export-root .doc-print-header{margin:0 0 0.5rem;}'
        . '#pdf-export-root .doc-print-header-top{padding-bottom:0.45rem;border-bottom:1px solid #cbd5e1;}'
        . '#pdf-export-root .doc-print-header-brand{display:table;width:100%;table-layout:fixed;border-collapse:collapse;}'
        . '#pdf-export-root .doc-print-header-co{display:table-cell;width:62%;vertical-align:middle;text-align:right;font-weight:800;font-size:1.05rem;}'
        . '#pdf-export-root .doc-print-header-logo{display:table-cell;width:38%;vertical-align:middle;text-align:left;}'
        . '#pdf-export-root .doc-print-header-logo img{max-height:' . $h . 'px;max-width:' . $w . 'px;display:block;margin-left:auto;}'
        . '#pdf-export-root .doc-print-header-title{text-align:center;font-weight:700;font-size:1.1rem;padding-top:0.4rem;margin:0;}'
        . '#pdf-export-root{overflow:hidden;}'
        . '#pdf-export-root .doc-print-watermark--overlay{position:absolute;inset:0;z-index:0;display:flex;align-items:center;justify-content:center;pointer-events:none;min-height:0!important;height:100%;max-height:100%;overflow:hidden;}'
        . '#pdf-export-root .doc-print-watermark--overlay img{width:min(72%,460px);max-width:460px;max-height:400px;height:auto;object-fit:contain;opacity:0.12;}'
        . '#pdf-export-root .party-stmt-report-head,#pdf-export-root .report-sales-table-wrap{position:relative;z-index:1;}'
        . mobile_party_statement_print_report_css();
}

function mobile_party_statement_print_header_html(string $title, ?PDO $pdo, bool $forPdf): string
{
    $brand = document_header_brand($pdo);
    $company = esc($brand['company_name_ar']);
    $titleEsc = esc($title);
    $logoInner = '';
    if ($brand['logo_url'] !== null) {
        $h = min(64, (int) DOCUMENT_HEADER_LOGO_MAX_HEIGHT);
        $lw = min(96, (int) DOCUMENT_HEADER_LOGO_MAX_WIDTH);
        $logoInner = '<img src="' . esc($brand['logo_url']) . '" alt="" style="max-height:' . $h
            . 'px;max-width:' . $lw . 'px;width:auto;height:auto;object-fit:contain;display:block;margin:0;">';
    }

    // شعار أقصى اليسار واسم الشركة أقصى اليمين (صف LTR داخل صفحة RTL)
    return '<header class="doc-print-header">'
        . '<div class="doc-print-header-top">'
        . '<div class="doc-print-header-brand" style="display:table;width:100%;table-layout:fixed;border-collapse:collapse;direction:ltr;">'
        . '<div class="doc-print-header-logo" style="display:table-cell;width:26%;vertical-align:middle;text-align:left;padding:0;">'
        . $logoInner . '</div>'
        . '<div class="doc-print-header-co" style="display:table-cell;width:74%;vertical-align:middle;text-align:right;direction:rtl;'
        . 'font-weight:800;font-size:1.05rem;padding:0 0 0 0.35rem;line-height:1.35;">'
        . $company . '</div></div></div>'
        . '<div class="doc-print-header-title" style="text-align:center;font-weight:700;font-size:1.1rem;padding-top:0.4rem;">'
        . $titleEsc . '</div></header>';
}

function mobile_party_statement_print_watermark_overlay(?PDO $pdo): string
{
    $url = document_print_watermark_logo_url($pdo);
    if ($url === null) {
        return '';
    }

    return '<div class="doc-print-watermark doc-print-watermark--overlay" aria-hidden="true">'
        . '<img src="' . esc($url) . '" alt=""></div>';
}

function mobile_party_statement_print_report_head(string $partyName, string $fromDmy, string $toDmy): string
{
    return '<div class="party-stmt-report-head">'
        . '<p class="party-stmt-report-customer">' . esc($partyName) . '</p>'
        . '<p class="party-stmt-report-dates">'
        . '<span>من تاريخ: ' . esc($fromDmy) . '</span>'
        . '<span class="party-stmt-report-dates-sep"> | </span>'
        . '<span>إلى تاريخ: ' . esc($toDmy) . '</span>'
        . '</p></div>';
}

/** @param array<string, mixed> $r */
function mobile_party_statement_print_doc_cell(array $r, bool $forPdf): string
{
    $ref = (string) ($r['ref_no'] ?? '');
    if ($ref === '') {
        return '—';
    }
    $label = (string) ($r['doc_label'] ?? 'رقم المستند');
    $hint = trim((string) ($r['doc_hint'] ?? ''));
    $html = '<span class="party-stmt-doc-kind">' . esc($label) . '</span>'
        . '<span class="party-stmt-doc-no-wrap"><code class="party-stmt-doc-no">' . esc($ref) . '</code>';
    if ($hint !== '') {
        $html .= '<span class="party-stmt-doc-hint">' . esc($hint) . '</span>';
    }

    return $html . '</span></span>';
}

function mobile_party_statement_print_money_cell(float $n, bool $showDashWhenZero = false): string
{
    if ($showDashWhenZero && abs($n) < 0.000001) {
        return '—';
    }

    return esc(format_money($n));
}

/**
 * @param list<array<string, mixed>> $rows
 * @param array{opening_balance: float, opening_debit: float, opening_credit: float, total_debit: float, total_credit: float, closing_balance: float} $totals
 */
function mobile_party_statement_print_lines_table(
    array $rows,
    array $totals,
    string $fromIso,
    bool $forPdf
): string {
    $fromDmy = format_date_dmY($fromIso);
    $ob = (float) ($totals['opening_balance'] ?? 0);
    $od = (float) ($totals['opening_debit'] ?? 0);
    $oc = (float) ($totals['opening_credit'] ?? 0);
    $showOpen = $fromIso !== '' && (abs($ob) >= 0.000001 || $od > 0 || $oc > 0);

    $thStyle = $forPdf
        ? ' style="background:#f1f5f9;padding:6px 4px;border:1px solid #94a3b8;text-align:center;font-weight:700;font-size:8px;color:#475569;"'
        : '';
    $tdBase = $forPdf
        ? ' style="padding:6px 4px;border:1px solid #cbd5e1;text-align:center;font-weight:700;font-size:9px;background:#fff;vertical-align:middle;"'
        : '';
    $tdDesc = $forPdf
        ? ' style="padding:6px 4px;border:1px solid #cbd5e1;text-align:right;font-weight:700;font-size:9px;background:#fff;word-break:break-word;vertical-align:middle;"'
        : ' class="col-desc"';
    $tdDoc = $forPdf
        ? ' style="padding:6px 4px;border:1px solid #cbd5e1;text-align:right;font-weight:700;font-size:9px;background:#fff;vertical-align:middle;"'
        : ' class="party-stmt-doc-cell"';
    $tdOpen = $forPdf ? $tdBase : '';

    $tblAttr = 'class="data-table report-sales-table party-stmt-table" cellpadding="0" cellspacing="0"'
        . ($forPdf ? ' style="width:100%;border-collapse:collapse;table-layout:fixed;direction:rtl;"' : '');

    $html = '<div class="report-sales-table-wrap"><table ' . $tblAttr . '><thead><tr>'
        . '<th class="col-date"' . $thStyle . '>التاريخ</th>'
        . '<th class="col-desc"' . $thStyle . '>الوصف</th>'
        . '<th class="col-doc"' . $thStyle . '>الرقم</th>'
        . '<th class="col-money"' . $thStyle . '>مدين</th>'
        . '<th class="col-money"' . $thStyle . '>دائن</th>'
        . '<th class="col-money"' . $thStyle . '>الرصيد</th>'
        . '</tr></thead><tbody>';

    if ($showOpen) {
        $html .= '<tr class="party-stmt-opening">'
            . '<td class="col-date"' . $tdBase . '>' . esc($fromDmy) . '</td>'
            . '<td' . ($forPdf ? $tdDesc : ' class="col-desc"') . '><em>رصيد افتتاحي</em></td>'
            . '<td class="col-doc"' . $tdOpen . '>—</td>'
            . '<td class="col-money"' . $tdBase . '>' . mobile_party_statement_print_money_cell($od, true) . '</td>'
            . '<td class="col-money"' . $tdBase . '>' . mobile_party_statement_print_money_cell($oc, true) . '</td>'
            . '<td class="col-money"' . $tdBase . '><strong>' . mobile_party_statement_print_money_cell($ob) . '</strong></td></tr>';
    }

    if ($rows === []) {
        $html .= '<tr><td colspan="6" class="muted" style="padding:1rem;text-align:center;">لا توجد حركات في هذه الفترة.</td></tr>';
    }

    $rowIdx = 0;
    foreach ($rows as $r) {
        $rowIdx++;
        $debit = (float) ($r['debit'] ?? 0);
        $credit = (float) ($r['credit'] ?? 0);
        $tdRow = $tdBase;
        if ($forPdf && $rowIdx % 2 === 0) {
            $tdRow = str_replace('background:#fff', 'background:#fafbff', $tdBase);
        }
        $html .= '<tr>'
            . '<td class="col-date"' . $tdRow . '>' . esc(format_date_dmY((string) ($r['date'] ?? ''))) . '</td>'
            . '<td' . ($forPdf ? $tdDesc : ' class="col-desc"') . '>' . esc((string) ($r['description'] ?? '')) . '</td>'
            . '<td' . ($forPdf ? $tdDoc : ' class="col-doc party-stmt-doc-cell"') . '>'
            . mobile_party_statement_print_doc_cell($r, $forPdf) . '</td>'
            . '<td class="col-money"' . $tdRow . '>' . mobile_party_statement_print_money_cell($debit, true) . '</td>'
            . '<td class="col-money"' . $tdRow . '>' . mobile_party_statement_print_money_cell($credit, true) . '</td>'
            . '<td class="col-money"' . $tdRow . ' style="font-weight:800;">'
            . mobile_party_statement_print_money_cell((float) ($r['balance'] ?? 0)) . '</td>'
            . '</tr>';
    }

    $footerDebit = $od + (float) ($totals['total_debit'] ?? 0);
    $footerCredit = $oc + (float) ($totals['total_credit'] ?? 0);
    $closing = (float) ($totals['closing_balance'] ?? 0);

    $footTd = $forPdf
        ? ' style="background:#e8edf5;border:1px solid #94a3b8;padding:8px 4px;text-align:center;vertical-align:middle;"'
        : '';

    $html .= '</tbody><tfoot class="party-stmt-tfoot"><tr class="party-stmt-totals">'
        . '<td colspan="3"' . $footTd . ' style="text-align:right;"><strong>المجموع</strong></td>'
        . '<td class="col-money"' . $footTd . '><span class="party-stmt-foot-label">مجموع المدين</span>'
        . '<strong class="party-stmt-foot-value">' . mobile_party_statement_print_money_cell($footerDebit) . '</strong></td>'
        . '<td class="col-money"' . $footTd . '><span class="party-stmt-foot-label">مجموع الدائن</span>'
        . '<strong class="party-stmt-foot-value">' . mobile_party_statement_print_money_cell($footerCredit) . '</strong></td>'
        . '<td class="col-money"' . $footTd . '><span class="party-stmt-foot-label">الرصيد النهائي</span>'
        . '<strong class="party-stmt-foot-value">' . mobile_party_statement_print_money_cell($closing) . '</strong></td>'
        . '</tr></tfoot></table></div>';

    return $html;
}

/**
 * @param list<array<string, mixed>> $rows
 * @param array<string, mixed> $built
 */
function mobile_party_statement_print_inner_html(
    PDO $pdo,
    string $partyType,
    string $partyName,
    string $partyCode,
    string $fromIso,
    string $toIso,
    array $rows,
    array $built,
    bool $forPdf = false
): string {
    $partyTypeLabel = $partyType === 'supplier' ? 'مورد' : 'عميل';
    $title = 'كشف حساب ' . $partyTypeLabel;
    $fromDmy = format_date_dmY($fromIso);
    $toDmy = format_date_dmY($toIso);
    $totals = [
        'opening_balance' => (float) ($built['opening_balance'] ?? 0),
        'opening_debit' => (float) ($built['opening_debit'] ?? 0),
        'opening_credit' => (float) ($built['opening_credit'] ?? 0),
        'total_debit' => (float) ($built['total_debit'] ?? 0),
        'total_credit' => (float) ($built['total_credit'] ?? 0),
        'closing_balance' => (float) ($built['closing_balance'] ?? 0),
    ];

    $content = mobile_party_statement_print_header_html($title, $pdo, $forPdf)
        . mobile_party_statement_print_report_head($partyName, $fromDmy, $toDmy)
        . mobile_party_statement_print_lines_table($rows, $totals, $fromIso, $forPdf);

    $wm = mobile_party_statement_print_watermark_overlay($pdo);
    $scopeClass = 'report-sales-print-area party-stmt-page doc-print-watermark-scope';

    if ($forPdf) {
        return '<div id="pdf-export-root" class="' . $scopeClass . '">' . $wm . $content . '</div>';
    }

    return '<div class="' . $scopeClass . '">' . $wm . $content . '</div>';
}

function mobile_party_statement_print_full_html(PDO $pdo, string $inner, bool $forPdf): string
{
    $styles = mobile_party_statement_print_styles($pdo) . mobile_party_statement_print_page_css();
    if ($forPdf) {
        $styles = mobile_party_statement_print_pdf_frame_css($pdo);
    }
    $logoUrl = document_print_watermark_logo_url($pdo);
    $bodyClass = 'doc-print-standalone';
    if ($logoUrl !== null) {
        $bodyClass .= ' has-doc-watermark';
    }

    return '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>كشف حساب</title>'
        . '<style>' . $styles . '</style></head><body class="' . $bodyClass . '">' . $inner
        . document_print_user_footer_html() . '</body></html>';
}

/**
 * @param list<array<string, mixed>> $rows
 * @param array<string, mixed> $built
 * @return array{styles: string, styles_pdf: string, inner: string, inner_pdf: string, html: string, html_pdf: string}
 */
function mobile_party_statement_print_document(
    PDO $pdo,
    string $partyType,
    int $partyId,
    string $partyName,
    string $partyCode,
    string $fromIso,
    string $toIso,
    array $rows,
    array $built
): array {
    $inner = mobile_party_statement_print_inner_html($pdo, $partyType, $partyName, $partyCode, $fromIso, $toIso, $rows, $built, false);
    $styles = mobile_party_statement_print_styles($pdo) . mobile_party_statement_print_page_css();
    $html = mobile_party_statement_print_full_html($pdo, $inner, false);
    // PDF الموبايل = نفس تصميم الطباعة (ليس إطار html_pdf المنفصل)
    $htmlPdf = $html;
    $innerPdf = $inner;

    $pdfQuery = http_build_query([
        'party_type' => $partyType,
        'party_id' => $partyId,
        'from' => format_date_dmY($fromIso),
        'to' => format_date_dmY($toIso),
    ], '', '&', PHP_QUERY_RFC3986);

    return [
        'styles' => $styles,
        'styles_pdf' => $styles,
        'inner' => $inner,
        'inner_pdf' => $innerPdf,
        'html' => $html,
        'html_pdf' => $htmlPdf,
        'mobile_pdf' => true,
        'pdf_download_url' => app_url('api/mobile_party_statement_pdf.php?' . $pdfQuery),
    ];
}
