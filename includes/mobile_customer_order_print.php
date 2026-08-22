<?php
declare(strict_types=1);

require_once app_path('includes/document_header.php');

/**
 * @param array<string,mixed> $order
 */
function mobile_customer_order_print_html(PDO $pdo, array $order): string
{
    $lines = is_array($order['lines'] ?? null) ? $order['lines'] : [];
    $orderNo = esc((string) ($order['order_no'] ?? ''));
    $customer = esc((string) ($order['customer_name'] ?? ''));
    $date = esc(format_date_dmY((string) ($order['order_date'] ?? '')));
    $rep = esc((string) ($order['sales_rep_name'] ?? ''));
    $warehouse = esc((string) ($order['warehouse_name'] ?? ''));
    $subtotal = number_format((float) ($order['subtotal'] ?? 0), 2, '.', ',');
    $discount = number_format((float) ($order['discount_total'] ?? 0), 2, '.', ',');
    $tax = number_format((float) ($order['tax_total'] ?? 0), 2, '.', ',');
    $grand = number_format((float) ($order['grand_total'] ?? 0), 2, '.', ',');

    $header = document_print_header_html('طلب شراء عميل', $pdo);
    $rowsHtml = '';
    $i = 0;
    foreach ($lines as $ln) {
        if (!is_array($ln)) {
            continue;
        }
        $i++;
        $rowsHtml .= '<tr>'
            . '<td>' . $i . '</td>'
            . '<td style="text-align:right">' . esc((string) ($ln['item_name'] ?? '')) . '</td>'
            . '<td>' . esc((string) ($ln['unit_name'] ?? $ln['unit_code'] ?? '')) . '</td>'
            . '<td dir="ltr">' . esc(number_format((float) ($ln['qty'] ?? 0), 3, '.', '')) . '</td>'
            . '<td dir="ltr">' . esc(number_format((float) ($ln['unit_price'] ?? 0), 2, '.', ',')) . '</td>'
            . '<td dir="ltr">' . esc(number_format((float) ($ln['line_total'] ?? 0), 2, '.', ',')) . '</td>'
            . '</tr>';
    }
    if ($rowsHtml === '') {
        $rowsHtml = '<tr><td colspan="6" style="text-align:center;padding:1rem">لا توجد بنود.</td></tr>';
    }

    $styles = document_print_header_css()
        . 'body{font-family:Arial,Helvetica,sans-serif;direction:rtl;margin:8mm;color:#0f172a;font-size:12px}'
        . 'table{width:100%;border-collapse:collapse;margin-top:10px}'
        . 'th,td{border:1px solid #94a3b8;padding:6px;text-align:center}'
        . 'th{background:#f1f5f9;font-weight:800}'
        . '.meta{margin:8px 0;line-height:1.6}'
        . '.totals{margin-top:12px;width:50%;margin-inline-start:auto}'
        . '.totals td{border:none;padding:4px 8px}'
        . '.totals .lbl{text-align:right;font-weight:700}'
        . '.totals .val{text-align:left;font-weight:800;direction:ltr}';

    return '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><style>' . $styles . '</style></head><body>'
        . $header
        . '<div class="meta">'
        . '<div><strong>رقم الطلب:</strong> ' . $orderNo . '</div>'
        . '<div><strong>التاريخ:</strong> ' . $date . '</div>'
        . '<div><strong>العميل:</strong> ' . $customer . '</div>'
        . ($rep !== '' ? '<div><strong>المندوب:</strong> ' . $rep . '</div>' : '')
        . ($warehouse !== '' ? '<div><strong>المستودع:</strong> ' . $warehouse . '</div>' : '')
        . '</div>'
        . '<table><thead><tr>'
        . '<th>#</th><th>المادة</th><th>الوحدة</th><th>الكمية</th><th>السعر</th><th>الإجمالي</th>'
        . '</tr></thead><tbody>' . $rowsHtml . '</tbody></table>'
        . '<table class="totals"><tr><td class="lbl">المجموع</td><td class="val">' . $subtotal . '</td></tr>'
        . '<tr><td class="lbl">الخصم</td><td class="val">' . $discount . '</td></tr>'
        . '<tr><td class="lbl">الضريبة</td><td class="val">' . $tax . '</td></tr>'
        . '<tr><td class="lbl">الصافي</td><td class="val">' . $grand . '</td></tr></table>'
        . '</body></html>';
}
