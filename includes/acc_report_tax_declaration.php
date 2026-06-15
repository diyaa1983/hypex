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

    $netPayable = (float) ($vat['net_payable'] ?? 0);
    $isPayable = $netPayable >= 0;

    $lines = [
        [
            'section' => 'sales',
            'label' => 'المبيعات الخاضعة للضريبة',
            'taxable_base' => $salesBase,
            'tax_amount' => (float) ($vat['sales_tax'] ?? 0),
            'is_deduction' => false,
        ],
        [
            'section' => 'sales',
            'label' => 'مردودات المبيعات',
            'taxable_base' => $salesReturnBase,
            'tax_amount' => (float) ($vat['sale_return_tax'] ?? 0),
            'is_deduction' => true,
        ],
        [
            'section' => 'sales',
            'label' => 'صافي المبيعات الخاضعة للضريبة',
            'taxable_base' => $netSalesBase,
            'tax_amount' => (float) ($vat['output_net'] ?? 0),
            'is_subtotal' => true,
        ],
        [
            'section' => 'purchases',
            'label' => 'المشتريات الخاضعة للضريبة',
            'taxable_base' => $purBase,
            'tax_amount' => (float) ($vat['purchase_tax'] ?? 0),
            'is_deduction' => false,
        ],
        [
            'section' => 'purchases',
            'label' => 'مردودات المشتريات',
            'taxable_base' => $purReturnBase,
            'tax_amount' => (float) ($vat['purchase_return_tax'] ?? 0),
            'is_deduction' => true,
        ],
        [
            'section' => 'purchases',
            'label' => 'صافي المشتريات الخاضعة للضريبة',
            'taxable_base' => $netPurBase,
            'tax_amount' => (float) ($vat['input_net'] ?? 0),
            'is_subtotal' => true,
        ],
    ];

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
        'counts' => [
            'sale_invoices' => count($saleInv),
            'sale_returns' => count($saleRet),
            'purchase_invoices' => count($purInv),
            'purchase_returns' => count($purRet),
        ],
        'net_payable' => round($netPayable, 6),
        'is_payable' => $isPayable,
        'net_label' => $isPayable ? 'صافي الضريبة المستحقة للدفع' : 'صافي الضريبة المستردة',
        'doc_net_payable' => (float) ($vat['doc_net_payable'] ?? 0),
        'returns_need_repost' => !empty($vat['returns_need_repost']),
        'gl_doc_gap' => (float) ($vat['gl_doc_gap'] ?? 0),
    ];
}
