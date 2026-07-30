<?php
declare(strict_types=1);

require_once app_path('includes/mobile_rep_custody.php');
require_once app_path('includes/inv_warehouse_items_report.php');
require_once app_path('includes/document_header.php');
require_once app_path('includes/mobile_invoice_print_pdf_mobile.php');

/**
 * @return list<array<string, mixed>>
 */
function mobile_rep_stock_filter_lines(array $lines, string $search): array
{
    $search = trim($search);
    if ($search === '') {
        return $lines;
    }

    $qLower = mb_strtolower($search, 'UTF-8');

    return array_values(array_filter($lines, static function (array $row) use ($qLower): bool {
        $hay = mb_strtolower(
            (string) ($row['item_name'] ?? '') . ' ' . (string) ($row['item_sku'] ?? ''),
            'UTF-8'
        );

        return str_contains($hay, $qLower);
    }));
}

function mobile_rep_stock_print_head_mobile_pdf(?PDO $pdo): string
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

    $brandRow = '<table cellpadding="0" cellspacing="0" '
        . 'style="width:100%;border-collapse:collapse;table-layout:fixed;direction:rtl;">'
        . '<tr>'
        . '<td style="width:62%;text-align:right;vertical-align:middle;font-size:15px;font-weight:900;'
        . 'line-height:1.4;border:none;color:#000;">' . $company . '</td>'
        . '<td style="width:38%;text-align:left;vertical-align:middle;border:none;">' . $logoCell . '</td>'
        . '</tr></table>';

    return '<header class="m-inv-pdf-head">'
        . '<div class="m-inv-pdf-head-top">' . $brandRow . '</div>'
        . '<div class="m-inv-pdf-title" role="heading" aria-level="1">رصيد عهدة مندوب</div>'
        . '</header>';
}

function mobile_rep_stock_print_meta_mobile_pdf(array $ctx, int $itemCount, string $search): string
{
    $tbl = 'width:100%;max-width:320px;border-collapse:collapse;direction:rtl;margin:0;';
    $td = 'text-align:right;padding:2px 0;font-size:10px;line-height:1.45;vertical-align:top;border:none;color:#000;';

    $rows = [
        ['label' => 'المندوب', 'value' => trim((string) ($ctx['rep_name'] ?? '')), 'emphasis' => true],
        ['label' => 'المستودع', 'value' => trim((string) ($ctx['van_warehouse_name'] ?? '')), 'emphasis' => true],
        ['label' => 'التاريخ', 'value' => format_date_dmY(date('Y-m-d'))],
        ['label' => 'عدد المواد', 'value' => (string) $itemCount],
    ];
    if (trim($search) !== '') {
        $rows[] = ['label' => 'بحث', 'value' => trim($search)];
    }

    $html = '<div class="doc-print-meta m-pdf-meta"><table cellpadding="0" cellspacing="0" style="' . $tbl . '">';
    foreach ($rows as $row) {
        $value = trim((string) ($row['value'] ?? ''));
        if ($value === '') {
            continue;
        }
        $valWeight = !empty($row['emphasis']) ? '900' : '800';
        $html .= '<tr><td style="' . $td . '">'
            . '<span style="font-weight:800;color:#1e293b;">' . esc((string) $row['label']) . ':</span> '
            . '<span style="font-weight:' . $valWeight . ';">' . esc($value) . '</span></td></tr>';
    }
    $html .= '</table></div>';

    return $html;
}

/**
 * @param list<array<string, mixed>> $lines
 */
function mobile_rep_stock_print_lines_table_mobile_pdf(array $lines, int $dp, float $totalQty): string
{
    $th = 'background:#f1f5f9;padding:5px 4px;border:1px solid #94a3b8;text-align:center;'
        . 'font-weight:800;font-size:9px;color:#1e293b;';
    $td = 'padding:5px 4px;border:1px solid #cbd5e1;text-align:center;font-size:9px;'
        . 'font-weight:800;color:#000;vertical-align:middle;line-height:1.3;';
    $tdName = $td . 'text-align:right;word-break:break-word;';
    $tdSku = $td . 'font-family:Arial,Helvetica,sans-serif;';

    $body = '';
    foreach ($lines as $i => $row) {
        $body .= '<tr>'
            . '<td style="' . $td . 'width:7%;">' . ((int) $i + 1) . '</td>'
            . '<td style="' . $tdSku . 'width:18%;">' . esc((string) ($row['item_sku'] ?? '')) . '</td>'
            . '<td style="' . $tdName . '">' . esc((string) ($row['item_name'] ?? '')) . '</td>'
            . '<td style="' . $td . 'width:14%;">' . esc(format_amount((float) ($row['qty'] ?? 0), $dp)) . '</td>'
            . '<td style="' . $td . 'width:14%;">' . esc((string) ($row['unit_name'] ?? '—')) . '</td>'
            . '</tr>';
    }
    if ($body === '') {
        $body = '<tr><td colspan="5" style="padding:12px;text-align:center;color:#64748b;font-size:10px;">'
            . 'لا يوجد رصيد في العهدة.</td></tr>';
    } else {
        $body .= '<tr>'
            . '<td colspan="3" style="' . $td . 'text-align:right;font-weight:900;background:#f8fafc;">الإجمالي</td>'
            . '<td style="' . $td . 'font-weight:900;background:#f8fafc;">' . esc(format_amount($totalQty, $dp)) . '</td>'
            . '<td style="' . $td . 'background:#f8fafc;"></td>'
            . '</tr>';
    }

    return '<table class="inv-print-lines" cellpadding="0" cellspacing="0" '
        . 'style="border-collapse:collapse;width:100%;margin-top:8px;table-layout:fixed;direction:rtl;">'
        . '<thead><tr>'
        . '<th style="' . $th . 'width:7%;">#</th>'
        . '<th style="' . $th . 'width:18%;">الرقم</th>'
        . '<th style="' . $th . '">المادة</th>'
        . '<th style="' . $th . 'width:14%;">الكمية</th>'
        . '<th style="' . $th . 'width:14%;">الوحدة</th>'
        . '</tr></thead><tbody>' . $body . '</tbody></table>';
}

/**
 * @param list<array<string, mixed>> $lines
 */
function mobile_rep_stock_print_inner_html_mobile_pdf(
    PDO $pdo,
    array $ctx,
    array $lines,
    string $search
): string {
    $dp = company_decimal_places($pdo);
    $totalQty = 0.0;
    foreach ($lines as $row) {
        $totalQty += (float) ($row['qty'] ?? 0);
    }

    return '<div class="m-inv-pdf-sheet" dir="rtl">'
        . mobile_rep_stock_print_head_mobile_pdf($pdo)
        . mobile_rep_stock_print_meta_mobile_pdf($ctx, count($lines), $search)
        . mobile_rep_stock_print_lines_table_mobile_pdf($lines, $dp, $totalQty)
        . '</div>';
}

/**
 * @return array{html:string, html_pdf:string, title:string}|null
 */
function mobile_rep_stock_print_document(PDO $pdo, array $ctx, string $search = ''): ?array
{
    $lines = inv_report_warehouse_items_lines($pdo, (int) $ctx['van_warehouse_id'], true);
    $lines = mobile_rep_stock_filter_lines($lines, $search);

    foreach ($lines as &$row) {
        $row['qty'] = company_round_amount((float) ($row['qty'] ?? 0));
    }
    unset($row);

    $repName = trim((string) ($ctx['rep_name'] ?? ''));
    $whName = trim((string) ($ctx['van_warehouse_name'] ?? ''));
    $title = 'رصيد المستودع'
        . ($whName !== '' ? ' — ' . $whName : '')
        . ($repName !== '' ? ' — ' . $repName : '');

    $innerPdf = mobile_rep_stock_print_inner_html_mobile_pdf($pdo, $ctx, $lines, $search);
    $htmlPdf = mobile_invoice_print_full_html_mobile_pdf($pdo, $innerPdf);

    $fullHtml = '<!DOCTYPE html><html dir="rtl" lang="ar"><head><meta charset="utf-8">'
        . '<title>' . esc($title) . '</title>'
        . '<style>' . mobile_invoice_print_mobile_pdf_css($pdo) . '</style>'
        . '</head><body dir="rtl">' . $innerPdf . '</body></html>';

    return [
        'html' => $fullHtml,
        'html_pdf' => $htmlPdf,
        'title' => $title,
        'mobile_pdf' => true,
    ];
}

function mobile_rep_stock_print_pdf_filename(array $ctx): string
{
    $wh = trim((string) ($ctx['van_warehouse_name'] ?? ''));
    $rep = trim((string) ($ctx['rep_name'] ?? ''));
    if ($wh !== '') {
        return 'رصيد مستودع - ' . $wh . '.pdf';
    }
    if ($rep !== '') {
        return 'رصيد مستودع - ' . $rep . '.pdf';
    }

    return 'رصيد مستودع.pdf';
}
