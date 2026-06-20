<?php
declare(strict_types=1);

require_once app_path('includes/acc_gl.php');
require_once app_path('includes/acc_report.php');

/**
 * @return array<string, array{debit: float, credit: float}>
 */
function acc_report_vat_by_ref_type(PDO $pdo, int $accountId, string $dateFrom, string $dateTo): array
{
    if ($accountId < 1 || !acc_journal_has_tables($pdo)) {
        return [];
    }

    $st = $pdo->prepare(
        'SELECT e.ref_type,
                COALESCE(SUM(l.debit), 0) AS sum_debit,
                COALESCE(SUM(l.credit), 0) AS sum_credit
         FROM acc_journal_line l
         INNER JOIN acc_journal_entry e ON e.id = l.journal_id
         WHERE l.account_id = ?
           AND e.status = \'posted\'
           AND e.entry_date >= ?
           AND e.entry_date <= ?
         GROUP BY e.ref_type'
    );
    $st->execute([$accountId, $dateFrom, $dateTo]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $out[(string) ($row['ref_type'] ?? '')] = [
            'debit' => (float) ($row['sum_debit'] ?? 0),
            'credit' => (float) ($row['sum_credit'] ?? 0),
        ];
    }

    return $out;
}

/** صافي ضريبة مخرجات (مبيعات − مردود بيع) من الدفتر. */
function acc_report_vat_output_net(PDO $pdo, int $accountId, string $dateFrom, string $dateTo): float
{
    $byRef = acc_report_vat_by_ref_type($pdo, $accountId, $dateFrom, $dateTo);
    $sales = (float) ($byRef['sale_invoice']['credit'] ?? 0);
    $returns = (float) ($byRef['sale_return']['debit'] ?? 0);
    $otherCr = 0.0;
    $otherDr = 0.0;
    foreach ($byRef as $ref => $sums) {
        if ($ref === 'sale_invoice' || $ref === 'sale_return') {
            continue;
        }
        if (in_array($ref, ['purchase_invoice', 'purchase_return'], true)) {
            continue;
        }
        $otherCr += (float) ($sums['credit'] ?? 0);
        $otherDr += (float) ($sums['debit'] ?? 0);
    }

    return round($sales - $returns + $otherCr - $otherDr, 6);
}

/** معرّفات حسابي ضريبة المبيعات والمشتريات من الربط. */
function acc_report_vat_account_ids(PDO $pdo): array
{
    $settings = acc_gl_load_settings($pdo);

    return [
        'output' => (int) ($settings['vat_output']['account_id'] ?? 0),
        'input' => (int) ($settings['vat_input']['account_id'] ?? 0),
    ];
}

/**
 * تفصيل عرض الميزان: إجمالي الضريبة ثم مردود ثم صافي (مثل ضريبة المشتريات).
 *
 * @return array{
 *   gross: float,
 *   return_amount: float,
 *   net: float,
 *   gross_label: string,
 *   return_label: string,
 *   net_label: string,
 *   is_output: bool
 * }|null
 */
function acc_report_vat_tb_period_detail(
    PDO $pdo,
    int $accountId,
    string $dateFrom,
    string $dateTo,
    bool $isOutput
): ?array {
    if ($accountId < 1 || !acc_journal_has_tables($pdo)) {
        return null;
    }

    $byRef = acc_report_vat_by_ref_type($pdo, $accountId, $dateFrom, $dateTo);

    if ($isOutput) {
        $gross = round((float) ($byRef['sale_invoice']['credit'] ?? 0), 6);
        $returns = round((float) ($byRef['sale_return']['debit'] ?? 0), 6);
        $net = acc_report_vat_output_net($pdo, $accountId, $dateFrom, $dateTo);

        return [
            'gross' => $gross,
            'return_amount' => $returns,
            'net' => $net,
            'gross_label' => 'ضريبة فواتير البيع (كامل)',
            'return_label' => 'ضريبة مردودات البيع (خصم)',
            'net_label' => 'صافي الضريبة المستحقة على المبيعات',
            'is_output' => true,
        ];
    }

    $gross = round((float) ($byRef['purchase_invoice']['debit'] ?? 0), 6);
    $returns = round((float) ($byRef['purchase_return']['credit'] ?? 0), 6);
    $net = acc_report_vat_input_net($pdo, $accountId, $dateFrom, $dateTo);

    return [
        'gross' => $gross,
        'return_amount' => $returns,
        'net' => $net,
        'gross_label' => 'ضريبة فواتير الشراء (كامل)',
        'return_label' => 'ضريبة مردودات الشراء (خصم)',
        'net_label' => 'صافي الضريبة المستحقة على المشتريات',
        'is_output' => false,
    ];
}

/** صافي ضريبة مدخلات (مشتريات − مردود شراء) من الدفتر. */
function acc_report_vat_input_net(PDO $pdo, int $accountId, string $dateFrom, string $dateTo): float
{
    $byRef = acc_report_vat_by_ref_type($pdo, $accountId, $dateFrom, $dateTo);
    $purchases = (float) ($byRef['purchase_invoice']['debit'] ?? 0);
    $returns = (float) ($byRef['purchase_return']['credit'] ?? 0);
    $otherDr = 0.0;
    $otherCr = 0.0;
    foreach ($byRef as $ref => $sums) {
        if ($ref === 'purchase_invoice' || $ref === 'purchase_return') {
            continue;
        }
        if (in_array($ref, ['sale_invoice', 'sale_return'], true)) {
            continue;
        }
        $otherDr += (float) ($sums['debit'] ?? 0);
        $otherCr += (float) ($sums['credit'] ?? 0);
    }

    return round($purchases - $returns + $otherDr - $otherCr, 6);
}

/** ضريبة من المستندات المرحّلة (للمقارنة قبل/بعد إعادة ترحيل المردودات). */
function acc_report_vat_document_tax_sum(
    PDO $pdo,
    string $docTable,
    string $dateFrom,
    string $dateTo,
    bool $isReturn
): float {
    $dateCol = $isReturn ? 'return_date' : 'invoice_date';
    $statusOk = "r.status = 'confirmed'";

    try {
        if ($docTable === 'sal') {
            $sql = "SELECT COALESCE(SUM(r.tax_amount), 0)
                    FROM sal_return r
                    WHERE {$statusOk}
                      AND r.{$dateCol} >= ? AND r.{$dateCol} <= ?
                      AND EXISTS (
                          SELECT 1 FROM acc_journal_entry e
                          WHERE e.ref_type = 'sale_return' AND e.ref_id = r.id AND e.source = 'auto'
                      )";
            if (!$isReturn) {
                $sql = "SELECT COALESCE(SUM(i.tax_amount), 0)
                        FROM sal_invoice i
                        WHERE i.status = 'confirmed'
                          AND i.invoice_date >= ? AND i.invoice_date <= ?
                          AND EXISTS (
                              SELECT 1 FROM acc_journal_entry e
                              WHERE e.ref_type = 'sale_invoice' AND e.ref_id = i.id AND e.source = 'auto'
                          )";
            }
        } else {
            require_once app_path('includes/pur_invoice_schema.php');
            pur_invoice_ensure_schema($pdo);
            $sql = $isReturn
                ? "SELECT COALESCE(SUM(r.tax_amount), 0)
                   FROM pur_return r
                   WHERE r.status = 'confirmed'
                     AND r.return_date >= ? AND r.return_date <= ?
                     AND EXISTS (
                         SELECT 1 FROM acc_journal_entry e
                         WHERE e.ref_type = 'purchase_return' AND e.ref_id = r.id AND e.source = 'auto'
                     )"
                : "SELECT COALESCE(SUM(i.tax_amount), 0)
                   FROM pur_invoice i
                   WHERE i.status = 'confirmed'
                     AND i.invoice_date >= ? AND i.invoice_date <= ?
                     AND EXISTS (
                         SELECT 1 FROM crm_supplier_ledger l
                         WHERE l.txn_type = 'purchase_invoice' AND l.ref_id = i.id
                     )";
        }
        $st = $pdo->prepare($sql);
        $st->execute([$dateFrom, $dateTo]);

        return round(max(0, (float) $st->fetchColumn()), 6);
    } catch (Throwable $e) {
        return 0.0;
    }
}

/**
 * ملخص الضريبة الأردنية: مستحق = مخرجات − مدخلات (للفترة).
 *
 * @return array<string, mixed>
 */
function acc_report_vat_jordan_summary(PDO $pdo, string $dateFrom, string $dateTo): array
{
    $settings = acc_gl_load_settings($pdo);
    $outId = (int) ($settings['vat_output']['account_id'] ?? 0);
    $inId = (int) ($settings['vat_input']['account_id'] ?? 0);

    if ($outId > 0 && $outId === $inId) {
        $unifiedByRef = acc_report_vat_by_ref_type($pdo, $outId, $dateFrom, $dateTo);
        $outByRef = $unifiedByRef;
        $inByRef = $unifiedByRef;
    } else {
        $outByRef = $outId > 0 ? acc_report_vat_by_ref_type($pdo, $outId, $dateFrom, $dateTo) : [];
        $inByRef = $inId > 0 ? acc_report_vat_by_ref_type($pdo, $inId, $dateFrom, $dateTo) : [];
    }

    $salesTax = (float) ($outByRef['sale_invoice']['credit'] ?? 0);
    $saleReturnTax = (float) ($outByRef['sale_return']['debit'] ?? 0);
    $purTax = (float) ($inByRef['purchase_invoice']['debit'] ?? 0);
    $purReturnTax = (float) ($inByRef['purchase_return']['credit'] ?? 0);

    $outputNet = $outId > 0 ? acc_report_vat_output_net($pdo, $outId, $dateFrom, $dateTo) : 0.0;
    $inputNet = $inId > 0 ? acc_report_vat_input_net($pdo, $inId, $dateFrom, $dateTo) : 0.0;
    $netPayable = round($outputNet - $inputNet, 6);

    $docSales = acc_report_vat_document_tax_sum($pdo, 'sal', $dateFrom, $dateTo, false);
    $docSaleRet = acc_report_vat_document_tax_sum($pdo, 'sal', $dateFrom, $dateTo, true);
    $docPur = acc_report_vat_document_tax_sum($pdo, 'pur', $dateFrom, $dateTo, false);
    $docPurRet = acc_report_vat_document_tax_sum($pdo, 'pur', $dateFrom, $dateTo, true);
    $docOutputNet = round($docSales - $docSaleRet, 6);
    $docInputNet = round($docPur - $docPurRet, 6);
    $docNetPayable = round($docOutputNet - $docInputNet, 6);

    $outAcc = $outId > 0 ? $pdo->prepare('SELECT code, name_ar FROM acc_account WHERE id = ?') : null;
    $outName = '';
    $outCode = '';
    if ($outAcc) {
        $outAcc->execute([$outId]);
        $a = $outAcc->fetch(PDO::FETCH_ASSOC) ?: [];
        $outName = (string) ($a['name_ar'] ?? '');
        $outCode = (string) ($a['code'] ?? '');
    }

    $inName = '';
    $inCode = '';
    if ($inId > 0) {
        $st = $pdo->prepare('SELECT code, name_ar FROM acc_account WHERE id = ?');
        $st->execute([$inId]);
        $a = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $inName = (string) ($a['name_ar'] ?? '');
        $inCode = (string) ($a['code'] ?? '');
    }

    $glGap = round(abs($netPayable - $docNetPayable), 6);
    $returnsNeedRepost = $glGap >= 0.01
        && (
            abs($saleReturnTax) < 0.01 && $docSaleRet > 0.01
            || abs($purReturnTax) < 0.01 && $docPurRet > 0.01
        );

    return [
        'output_account_id' => $outId,
        'input_account_id' => $inId,
        'output_code' => $outCode,
        'output_name' => $outName,
        'input_code' => $inCode,
        'input_name' => $inName,
        'sales_tax' => round($salesTax, 6),
        'sale_return_tax' => round($saleReturnTax, 6),
        'output_net' => $outputNet,
        'purchase_tax' => round($purTax, 6),
        'purchase_return_tax' => round($purReturnTax, 6),
        'input_net' => $inputNet,
        'net_payable' => $netPayable,
        'doc_sales_tax' => $docSales,
        'doc_sale_return_tax' => $docSaleRet,
        'doc_output_net' => $docOutputNet,
        'doc_purchase_tax' => $docPur,
        'doc_purchase_return_tax' => $docPurRet,
        'doc_input_net' => $docInputNet,
        'doc_net_payable' => $docNetPayable,
        'gl_doc_gap' => $glGap,
        'returns_need_repost' => $returnsNeedRepost,
    ];
}
