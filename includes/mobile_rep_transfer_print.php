<?php
declare(strict_types=1);

require_once app_path('includes/mobile_rep_custody.php');
require_once app_path('includes/inv_wh_move_schema.php');
require_once app_path('includes/inv_item_display.php');
require_once app_path('includes/document_header.php');
require_once app_path('includes/mobile_invoice_print_pdf_mobile.php');

function mobile_rep_transfer_print_screen_title(string $direction): string
{
    return $direction === 'return' ? 'إرجاع عهدة مندوب' : 'تحميل عهدة مندوب';
}

function mobile_rep_transfer_print_head_mobile_pdf(?PDO $pdo, string $title): string
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
        . '<div class="m-inv-pdf-title" role="heading" aria-level="1">' . esc($title) . '</div>'
        . '</header>';
}

/** @return list<array{label:string, value:string, emphasis?:bool}> */
function mobile_rep_transfer_print_meta_rows(
    array $move,
    array $ctx,
    string $whFrom,
    string $whTo
): array {
    $rows = [
        ['label' => 'رقم السند', 'value' => mobile_rep_custody_format_move_no((string) ($move['move_no'] ?? ''))],
        ['label' => 'التاريخ', 'value' => format_date_dmY((string) ($move['move_date'] ?? ''))],
        ['label' => 'المندوب', 'value' => trim((string) ($ctx['rep_name'] ?? '')), 'emphasis' => true],
    ];

    if ($whFrom !== '') {
        $rows[] = ['label' => 'من مستودع', 'value' => $whFrom, 'emphasis' => true];
    }
    if ($whTo !== '') {
        $rows[] = ['label' => 'إلى مستودع', 'value' => $whTo, 'emphasis' => true];
    }

    $notes = trim((string) ($move['notes'] ?? ''));
    if ($notes !== '') {
        $rows[] = ['label' => 'ملاحظات', 'value' => $notes];
    }

    return $rows;
}

function mobile_rep_transfer_print_meta_mobile_pdf(array $move, array $ctx, string $whFrom, string $whTo): string
{
    $tbl = 'width:100%;max-width:320px;border-collapse:collapse;direction:rtl;margin:0;';
    $td = 'text-align:right;padding:2px 0;font-size:10px;line-height:1.45;vertical-align:top;border:none;color:#000;';

    $html = '<div class="doc-print-meta m-pdf-meta"><table cellpadding="0" cellspacing="0" style="' . $tbl . '">';
    foreach (mobile_rep_transfer_print_meta_rows($move, $ctx, $whFrom, $whTo) as $row) {
        $value = trim((string) ($row['value'] ?? ''));
        if ($value === '') {
            continue;
        }
        $valWeight = !empty($row['emphasis']) ? '900' : '800';
        $html .= '<tr><td style="' . $td . '">'
            . '<span class="m-pdf-meta-lbl" style="font-weight:800;color:#1e293b;">'
            . esc((string) $row['label']) . ':</span> '
            . '<span class="m-pdf-meta-val" style="font-weight:' . $valWeight . ';">'
            . esc($value) . '</span></td></tr>';
    }
    $html .= '</table></div>';

    return $html;
}

/**
 * @param list<array<string, mixed>> $lines
 */
function mobile_rep_transfer_print_lines_table_mobile_pdf(array $lines, string $qtyHdr, int $dp): string
{
    $th = 'background:#f1f5f9;padding:5px 4px;border:1px solid #94a3b8;text-align:center;'
        . 'font-weight:800;font-size:9px;color:#1e293b;';
    $td = 'padding:5px 4px;border:1px solid #cbd5e1;text-align:center;font-size:9px;'
        . 'font-weight:800;color:#000;vertical-align:middle;line-height:1.3;';
    $tdName = $td . 'text-align:right;word-break:break-word;';
    $tdSku = $td . 'font-family:Arial,Helvetica,sans-serif;';

    $body = '';
    foreach ($lines as $i => $ln) {
        $sku = inv_item_material_number_digits(
            (string) ($ln['barcode'] ?? ''),
            (string) ($ln['sku'] ?? '')
        );
        $body .= '<tr>'
            . '<td style="' . $td . 'width:8%;">' . ((int) $i + 1) . '</td>'
            . '<td style="' . $tdSku . 'width:22%;">' . esc($sku) . '</td>'
            . '<td style="' . $tdName . '">' . esc((string) ($ln['item_name'] ?? '')) . '</td>'
            . '<td style="' . $td . 'width:16%;">' . esc(format_amount((float) ($ln['qty'] ?? 0), $dp)) . '</td>'
            . '</tr>';
    }
    if ($body === '') {
        $body = '<tr><td colspan="4" style="padding:12px;text-align:center;color:#64748b;font-size:10px;">'
            . 'لا توجد مواد</td></tr>';
    }

    return '<table class="inv-print-lines" cellpadding="0" cellspacing="0" '
        . 'style="border-collapse:collapse;width:100%;margin-top:8px;table-layout:fixed;direction:rtl;">'
        . '<thead><tr>'
        . '<th style="' . $th . 'width:8%;">#</th>'
        . '<th style="' . $th . 'width:22%;">رقم المادة</th>'
        . '<th style="' . $th . '">المادة</th>'
        . '<th style="' . $th . 'width:16%;">' . esc($qtyHdr) . '</th>'
        . '</tr></thead><tbody>' . $body . '</tbody></table>';
}

/**
 * @param list<array<string, mixed>> $lines
 */
function mobile_rep_transfer_print_inner_html_mobile_pdf(
    PDO $pdo,
    array $move,
    array $lines,
    array $ctx,
    string $direction,
    string $whFrom,
    string $whTo
): string {
    $screenTitle = mobile_rep_transfer_print_screen_title($direction);
    $typeCode = (string) ($move['movement_type_code'] ?? 'transfer');
    $qtyHdr = $typeCode === 'transfer' ? 'كمية النقل' : 'الكمية';
    $dp = company_decimal_places($pdo);

    $head = mobile_rep_transfer_print_head_mobile_pdf($pdo, $screenTitle);
    $metaBlock = mobile_rep_transfer_print_meta_mobile_pdf($move, $ctx, $whFrom, $whTo);
    $tableHtml = mobile_rep_transfer_print_lines_table_mobile_pdf($lines, $qtyHdr, $dp);
    $sign = mobile_invoice_print_recipient_signature_html();

    return '<div class="m-inv-pdf-sheet" dir="rtl">'
        . $head
        . $metaBlock
        . $tableHtml
        . $sign
        . '</div>';
}

/**
 * @return array{html:string, html_pdf:string, title:string, move_no:string}|null
 */
function mobile_rep_transfer_print_document(
    PDO $pdo,
    int $moveId,
    array $ctx,
    string $direction
): ?array {
    $move = inv_wh_move_by_id($pdo, $moveId);
    if ($move === null) {
        return null;
    }

    if ((string) ($move['status'] ?? '') !== 'posted') {
        return null;
    }

    $lines = inv_wh_move_lines($pdo, $moveId);

    $st = $pdo->prepare('SELECT name_ar FROM inv_warehouse WHERE id = ? LIMIT 1');
    $st->execute([(int) ($move['warehouse_id'] ?? 0)]);
    $whFrom = (string) ($st->fetchColumn() ?: '');
    $whTo = '';
    if ((int) ($move['warehouse_to_id'] ?? 0) > 0) {
        $st->execute([(int) $move['warehouse_to_id']]);
        $whTo = (string) ($st->fetchColumn() ?: '');
    }

    $screenTitle = mobile_rep_transfer_print_screen_title($direction);
    $moveNo = mobile_rep_custody_format_move_no((string) ($move['move_no'] ?? ''));
    $title = $screenTitle . ($moveNo !== '' ? ' — ' . $moveNo : '');

    $innerPdf = mobile_rep_transfer_print_inner_html_mobile_pdf(
        $pdo,
        $move,
        $lines,
        $ctx,
        $direction,
        $whFrom,
        $whTo
    );
    $htmlPdf = mobile_invoice_print_full_html_mobile_pdf($pdo, $innerPdf);

    $fullHtml = '<!DOCTYPE html><html dir="rtl" lang="ar"><head><meta charset="utf-8">'
        . '<title>' . esc($title) . '</title>'
        . '<style>' . mobile_invoice_print_mobile_pdf_css($pdo) . '</style>'
        . '</head><body dir="rtl">' . $innerPdf . '</body></html>';

    return [
        'html' => $fullHtml,
        'html_pdf' => $htmlPdf,
        'title' => $title,
        'move_no' => $moveNo,
        'mobile_pdf' => true,
    ];
}

function mobile_rep_transfer_print_pdf_filename(string $direction, string $moveNo): string
{
    $label = $direction === 'return' ? 'إرجاع عهدة' : 'تحميل عهدة';
    $no = trim($moveNo);

    return $no !== '' ? $label . ' - ' . $no . '.pdf' : $label . '.pdf';
}
