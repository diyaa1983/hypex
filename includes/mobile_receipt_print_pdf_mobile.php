<?php
declare(strict_types=1);

/**
 * تصميم سند قبض PDF للموبايل — بلوكات فقط (بدون table/table-cell) لتفادي تشويه العربية في html2canvas.
 */

function mobile_receipt_print_mobile_pdf_css(?PDO $pdo = null): string
{
    $h = min(64, (int) DOCUMENT_HEADER_LOGO_MAX_HEIGHT);
    $w = min(96, (int) DOCUMENT_HEADER_LOGO_MAX_WIDTH);

    return 'html,body{margin:0;padding:0;background:#fff;direction:rtl;}'
        . '#pdf-export-root,#m-rc-pdf-preview,#m-rc-list-pdf-preview{'
        . 'box-sizing:border-box;width:400px;max-width:400px;min-width:400px;margin:0;padding:14px 12px 16px;'
        . 'font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.55;font-weight:700;'
        . 'color:#0f172a;background:#fff;direction:rtl;text-align:right;}'
        . '.m-rc-pdf-sheet{width:100%;box-sizing:border-box;direction:rtl;}'
        . '.m-rc-pdf-head{border-bottom:2px solid #0f172a;padding-bottom:10px;margin-bottom:12px;}'
        . '.m-rc-pdf-head-row{display:flex;flex-direction:row;align-items:flex-start;justify-content:space-between;'
        . 'gap:12px;width:100%;direction:rtl;min-height:' . ($h + 4) . 'px;}'
        . '.m-rc-pdf-co{flex:1 1 auto;min-width:0;font-size:16px;font-weight:800;line-height:1.35;text-align:right;'
        . 'padding-top:10px;padding-inline-end:4px;}'
        . '.m-rc-pdf-logo{flex:0 0 auto;text-align:left;align-self:flex-start;}'
        . '.m-rc-pdf-logo img{max-height:' . $h . 'px;max-width:' . $w . 'px;width:auto;height:auto;display:block;margin:0;}'
        . '.m-rc-pdf-title{margin:8px 0 0;text-align:center;font-size:17px;font-weight:800;width:100%;}'
        . '.m-rc-pdf-meta{width:100%;margin-bottom:12px;border:1px solid #94a3b8;border-radius:6px;overflow:hidden;background:#f8fafc;}'
        . '.m-rc-pdf-meta-item{display:block;width:100%;padding:8px 12px;text-align:right;'
        . 'border-bottom:1px solid #cbd5e1;box-sizing:border-box;}'
        . '.m-rc-pdf-meta-item:last-child{border-bottom:none;text-align:center;}'
        . '.m-rc-pdf-meta-lbl{display:block;font-size:10px;color:#475569;font-weight:700;margin-bottom:4px;}'
        . '.m-rc-pdf-meta-val{display:block;font-size:13px;font-weight:800;word-wrap:break-word;}'
        . '.m-rc-pdf-badge{display:inline-block;padding:3px 10px;border-radius:14px;font-size:11px;font-weight:800;border:1px solid;}'
        . '.m-rc-pdf-badge--posted{color:#065f46;border-color:#10b981;background:#ecfdf5;}'
        . '.m-rc-pdf-badge--draft{color:#92400e;border-color:#f59e0b;background:#fffbeb;}'
        . '.m-rc-pdf-amount{border:2px solid #0f172a;border-radius:6px;background:#fffbeb;padding:10px 12px;margin-bottom:12px;}'
        . '.m-rc-pdf-amount-row{display:block;width:100%;overflow:hidden;}'
        . '.m-rc-pdf-amount-lbl{display:block;font-size:13px;font-weight:800;text-align:right;margin-bottom:6px;}'
        . '.m-rc-pdf-amount-num{display:block;font-size:22px;font-weight:900;direction:ltr;unicode-bidi:embed;text-align:center;}'
        . '.m-rc-pdf-amount-words{margin-top:8px;padding-top:8px;border-top:1px dashed #b45309;font-size:12px;line-height:1.5;font-weight:700;}'
        . '.m-rc-pdf-amount-words strong{color:#7c2d12;}'
        . '.m-rc-pdf-fields{border:1px solid #cbd5e1;border-radius:6px;margin-bottom:12px;overflow:hidden;}'
        . '.m-rc-pdf-field{padding:10px 12px;border-bottom:1px solid #e2e8f0;background:#fff;}'
        . '.m-rc-pdf-field:last-child{border-bottom:none;}'
        . '.m-rc-pdf-field-lbl{display:block;font-size:11px;font-weight:800;color:#475569;margin-bottom:4px;}'
        . '.m-rc-pdf-field-val{display:block;font-size:14px;font-weight:700;line-height:1.45;word-wrap:break-word;overflow-wrap:break-word;}'
        . '.m-rc-pdf-section{font-size:13px;font-weight:800;margin:12px 0 8px;padding-bottom:4px;border-bottom:2px solid #0f172a;}'
        . '.m-rc-pdf-check{border:1px solid #94a3b8;border-radius:6px;margin-bottom:8px;padding:10px 12px;background:#fafafa;}'
        . '.m-rc-pdf-check-hd{font-size:12px;font-weight:800;margin-bottom:6px;}'
        . '.m-rc-pdf-check-line{display:block;font-size:12px;line-height:1.5;margin:3px 0;}'
        . '.m-rc-pdf-check-line b{font-weight:800;}'
        . '.m-rc-pdf-check-total{padding:10px;background:#fffbeb;border:1px solid #f59e0b;border-radius:6px;font-size:13px;font-weight:800;text-align:center;}'
        . '.m-rc-pdf-signs{display:block;width:100%;margin-top:16px;}'
        . '.m-rc-pdf-sign{display:block;width:100%;text-align:center;margin-bottom:12px;}'
        . '.m-rc-pdf-sign:last-child{margin-bottom:0;}'
        . '.m-rc-pdf-sign-lbl{display:block;font-size:12px;font-weight:800;margin-bottom:32px;}'
        . '.m-rc-pdf-sign-line{display:block;border-top:1.5px solid #0f172a;width:90%;margin:0 auto;}';
}

/**
 * @param array<string, mixed> $v
 * @param list<array<string, mixed>> $checks
 */
function mobile_receipt_print_inner_html_mobile_pdf(PDO $pdo, array $v, array $checks): string
{
    $v = mobile_receipt_enrich_display($pdo, $v);
    $dp = company_decimal_places($pdo);
    $fmt = static fn (float $n): string => number_format(round($n, $dp), $dp, '.', ',');

    $brand = document_header_brand($pdo);
    $company = esc($brand['company_name_ar']);
    $logoHtml = '';
    if ($brand['logo_url'] !== null) {
        $logoHtml = '<div class="m-rc-pdf-logo"><img src="' . esc($brand['logo_url']) . '" alt=""></div>';
    }

    $date = (string) ($v['voucher_date'] ?? '');
    if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = format_date_dmY($date);
    }
    $no = esc((string) ($v['voucher_no'] ?? '—'));
    $cust = esc((string) ($v['customer_name'] ?? ''));
    $code = (string) ($v['customer_code'] ?? '');
    if ($code !== '') {
        $cust .= ' (' . esc($code) . ')';
    }
    $rep = trim((string) ($v['sales_rep_name'] ?? ''));
    $pay = fin_voucher_pay_method_label((string) ($v['pay_method'] ?? 'cash'));
    $amount = (float) ($v['amount'] ?? 0);
    $amountFmt = esc($fmt($amount));
    $amountWords = esc(arabic_tafqit_amount($amount, $pdo));
    $notes = trim((string) ($v['description'] ?? $v['notes'] ?? ''));
    $posted = !empty($v['is_posted']);
    $badge = $posted
        ? '<span class="m-rc-pdf-badge m-rc-pdf-badge--posted">مرحّل</span>'
        : '<span class="m-rc-pdf-badge m-rc-pdf-badge--draft">غير مرحّل</span>';

    $head = '<header class="m-rc-pdf-head">'
        . '<div class="m-rc-pdf-head-row">'
        . '<div class="m-rc-pdf-co">' . $company . '</div>'
        . $logoHtml
        . '</div>'
        . '<h1 class="m-rc-pdf-title">سند قبض</h1></header>';

    $meta = '<div class="m-rc-pdf-meta">'
        . '<div class="m-rc-pdf-meta-item"><span class="m-rc-pdf-meta-lbl">رقم السند</span>'
        . '<span class="m-rc-pdf-meta-val">' . $no . '</span></div>'
        . '<div class="m-rc-pdf-meta-item"><span class="m-rc-pdf-meta-lbl">التاريخ</span>'
        . '<span class="m-rc-pdf-meta-val" dir="ltr" style="direction:ltr;unicode-bidi:embed;">'
        . esc($date !== '' ? $date : '—') . '</span></div>'
        . '<div class="m-rc-pdf-meta-item">' . $badge . '</div>'
        . '</div>';

    $amountBox = '<div class="m-rc-pdf-amount">'
        . '<div class="m-rc-pdf-amount-row">'
        . '<span class="m-rc-pdf-amount-lbl">مبلغ وقدره</span>'
        . '<span class="m-rc-pdf-amount-num">' . $amountFmt . '</span></div>'
        . '<div class="m-rc-pdf-amount-words"><strong>تفقيطاً:</strong> ' . $amountWords . '</div></div>';

    $field = static function (string $label, string $value): string {
        return '<div class="m-rc-pdf-field">'
            . '<span class="m-rc-pdf-field-lbl">' . esc($label) . '</span>'
            . '<span class="m-rc-pdf-field-val">' . $value . '</span></div>';
    };

    $details = '<div class="m-rc-pdf-fields">'
        . $field('استلمنا من السيد/السادة', $cust !== '' ? $cust : '—')
        . $field('طريقة الدفع', esc($pay))
        . $field('المندوب', esc($rep !== '' ? $rep : '—'))
        . $field('وذلك عن', esc($notes !== '' ? $notes : '—'))
        . '</div>';

    $checksHtml = '';
    if ($pay === 'شيك' && $checks !== []) {
        $checksHtml = '<div class="m-rc-pdf-section">تفاصيل الشيكات</div>';
        $total = 0.0;
        $i = 0;
        foreach ($checks as $chk) {
            $i++;
            $amt = (float) ($chk['check_amount'] ?? 0);
            $total += $amt;
            $due = (string) ($chk['due_date'] ?? '');
            if ($due !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
                $due = format_date_dmY($due);
            }
            $checksHtml .= '<div class="m-rc-pdf-check">'
                . '<div class="m-rc-pdf-check-hd">شيك ' . $i . '</div>'
                . '<span class="m-rc-pdf-check-line"><b>رقم الشيك:</b> '
                . esc((string) ($chk['check_no'] ?? '—')) . '</span>'
                . '<span class="m-rc-pdf-check-line"><b>المبلغ:</b> <span dir="ltr" style="direction:ltr;">'
                . esc($fmt($amt)) . '</span></span>'
                . '<span class="m-rc-pdf-check-line"><b>البنك:</b> '
                . esc((string) ($chk['bank_name'] ?? '—')) . '</span>'
                . '<span class="m-rc-pdf-check-line"><b>الاستحقاق:</b> <span dir="ltr" style="direction:ltr;">'
                . esc($due !== '' ? $due : '—') . '</span></span></div>';
        }
        $checksHtml .= '<div class="m-rc-pdf-check-total">إجمالي الشيكات: <span dir="ltr" style="direction:ltr;">'
            . esc($fmt($total)) . '</span></div>';
    }

    $signs = '<div class="m-rc-pdf-signs">'
        . '<div class="m-rc-pdf-sign"><span class="m-rc-pdf-sign-lbl">اسم المستلم</span>'
        . '<span class="m-rc-pdf-sign-line"></span></div>'
        . '<div class="m-rc-pdf-sign"><span class="m-rc-pdf-sign-lbl">التوقيع</span>'
        . '<span class="m-rc-pdf-sign-line"></span></div>'
        . '</div>';

    return '<div class="m-rc-pdf-sheet" dir="rtl">' . $head . $meta . $amountBox . $details . $checksHtml . $signs . '</div>';
}

function mobile_receipt_print_full_html_mobile_pdf(PDO $pdo, string $inner): string
{
    return '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8">'
        . '<title>سند قبض</title><style>' . mobile_receipt_print_mobile_pdf_css($pdo) . '</style></head><body dir="rtl">'
        . '<div id="pdf-export-root" dir="rtl">' . $inner . '</div></body></html>';
}
