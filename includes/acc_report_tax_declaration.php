<?php
declare(strict_types=1);

require_once app_path('includes/acc_report_vat_jordan.php');
require_once app_path('includes/acc_vat_tax_report.php');
require_once app_path('includes/company_settings.php');
require_once app_path('includes/einvoice_settings.php');

/** @param list<array<string, mixed>> $rows */
function acc_tax_decl_sum_taxable_base(array $rows): float
{
    $sum = 0.0;
    foreach ($rows as $row) {
        $total = (float) ($row['total'] ?? 0);
        $tax = (float) ($row['tax_amount'] ?? 0);
        $sum += max(0.0, $total - $tax);
    }

    return round($sum, 6);
}

/** @param list<array<string, mixed>> $rows */
function acc_tax_decl_sum_tax(array $rows): float
{
    $sum = 0.0;
    foreach ($rows as $row) {
        $sum += (float) ($row['tax_amount'] ?? 0);
    }

    return round($sum, 6);
}

/**
 * بيانات الإقرار الضريبي للفترة (ضريبة المبيعات والمشتريات — الأردن).
 * الضريبة تُحسب حسب تاريخ القيود/المستندات، ويُخصم التوريد للضريبة منفصلاً.
 *
 * @return array<string, mixed>
 */
function acc_report_tax_declaration(PDO $pdo, string $dateFrom, string $dateTo): array
{
    $vat = acc_report_vat_jordan_summary($pdo, $dateFrom, $dateTo);

    $saleInv = acc_vat_report_sale_invoice_tax_lines($pdo, $dateFrom, $dateTo);
    $saleRet = acc_vat_report_sale_return_tax_lines($pdo, $dateFrom, $dateTo);
    $purInv = acc_vat_report_purchase_invoice_tax_lines($pdo, $dateFrom, $dateTo);
    $purRet = acc_vat_report_purchase_return_tax_lines($pdo, $dateFrom, $dateTo);

    $salesBase = acc_tax_decl_sum_taxable_base($saleInv);
    $salesReturnBase = acc_tax_decl_sum_taxable_base($saleRet);
    $purBase = acc_tax_decl_sum_taxable_base($purInv);
    $purReturnBase = acc_tax_decl_sum_taxable_base($purRet);

    $netSalesBase = round($salesBase - $salesReturnBase, 6);
    $netPurBase = round($purBase - $purReturnBase, 6);

    $salesTax = (float) ($vat['sales_tax'] ?? 0);
    $saleReturnTax = (float) ($vat['sale_return_tax'] ?? 0);
    $purTax = (float) ($vat['purchase_tax'] ?? 0);
    $purReturnTax = (float) ($vat['purchase_return_tax'] ?? 0);
    // صافي المخرجات/المدخلات من الفواتير فقط (بدون خلط توريد الضريبة).
    $outputNet = round($salesTax - $saleReturnTax, 6);
    $inputNet = round($purTax - $purReturnTax, 6);
    $invoiceNet = round($outputNet - $inputNet, 6);

    $remittance = (float) ($vat['remittance_tax'] ?? $vat['gl_other_debit'] ?? 0);
    $otherCredit = (float) ($vat['gl_other_credit'] ?? 0);
    // بعد خصم التوريد للضريبة خلال الفترة.
    $netAfterRemittance = round($invoiceNet - $remittance + $otherCredit, 6);

    $company = company_settings($pdo);
    $einv = einvoice_settings_get($pdo);
    $taxRate = (float) ($company['tax_rate_percent'] ?? 16.0);

    $companyName = trim((string) ($einv['company_name'] ?? ''));
    if ($companyName === '') {
        $companyName = trim((string) ($company['company_name_ar'] ?? ''));
    }
    $tradeName = trim((string) ($einv['trade_name'] ?? ''));
    $vatNo = trim((string) ($einv['vat_no'] ?? ''));
    $gstNo = trim((string) ($einv['gst_no'] ?? ''));
    $taxId = $vatNo !== '' ? $vatNo : $gstNo;

    $isPayable = $netAfterRemittance >= 0;

    $lines = [
        [
            'section' => 'sales',
            'label' => 'المبيعات الخاضعة للضريبة',
            'taxable_base' => $salesBase,
            'tax_amount' => $salesTax,
            'is_deduction' => false,
        ],
        [
            'section' => 'sales',
            'label' => 'مردودات المبيعات',
            'taxable_base' => $salesReturnBase,
            'tax_amount' => $saleReturnTax,
            'is_deduction' => true,
        ],
        [
            'section' => 'sales',
            'label' => 'صافي المبيعات الخاضعة للضريبة',
            'taxable_base' => $netSalesBase,
            'tax_amount' => $outputNet,
            'is_subtotal' => true,
        ],
        [
            'section' => 'purchases',
            'label' => 'المشتريات الخاضعة للضريبة',
            'taxable_base' => $purBase,
            'tax_amount' => $purTax,
            'is_deduction' => false,
        ],
        [
            'section' => 'purchases',
            'label' => 'مردودات المشتريات',
            'taxable_base' => $purReturnBase,
            'tax_amount' => $purReturnTax,
            'is_deduction' => true,
        ],
        [
            'section' => 'purchases',
            'label' => 'صافي المشتريات الخاضعة للضريبة',
            'taxable_base' => $netPurBase,
            'tax_amount' => $inputNet,
            'is_subtotal' => true,
        ],
        [
            'section' => 'settlement',
            'label' => 'صافي ضريبة الفترة (مخرجات − مدخلات)',
            'taxable_base' => null,
            'tax_amount' => $invoiceNet,
            'is_subtotal' => true,
        ],
        [
            'section' => 'settlement',
            'label' => 'التوريد للضريبة خلال الفترة',
            'taxable_base' => null,
            'tax_amount' => $remittance,
            'is_deduction' => true,
        ],
    ];

    if (abs($otherCredit) >= 0.000001) {
        $lines[] = [
            'section' => 'settlement',
            'label' => 'تسويات دائنة على حساب الضريبة',
            'taxable_base' => null,
            'tax_amount' => $otherCredit,
            'is_deduction' => false,
        ];
    }

    return [
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'company_name' => $companyName,
        'trade_name' => $tradeName,
        'tax_id' => $taxId,
        'vat_no' => $vatNo,
        'gst_no' => $gstNo,
        'tax_rate_percent' => $taxRate,
        'lines' => $lines,
        'vat' => $vat,
        'tax_by_date' => $vat['tax_by_date'] ?? [],
        'counts' => [
            'sale_invoices' => count($saleInv),
            'sale_returns' => count($saleRet),
            'purchase_invoices' => count($purInv),
            'purchase_returns' => count($purRet),
        ],
        'invoice_net_payable' => $invoiceNet,
        'remittance_tax' => $remittance,
        'net_payable' => $netAfterRemittance,
        'is_payable' => $isPayable,
        'net_label' => $isPayable ? 'صافي الضريبة المستحقة للدفع' : 'صافي الضريبة المستردة',
        'doc_net_payable' => (float) ($vat['doc_net_payable'] ?? 0),
        'returns_need_repost' => !empty($vat['returns_need_repost']),
        'gl_doc_gap' => (float) ($vat['gl_doc_gap'] ?? 0),
    ];
}
