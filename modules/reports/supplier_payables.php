<?php
declare(strict_types=1);

require_once app_path('includes/pur_payables_report.php');
require_once app_path('includes/document_header.php');

$pdo = db();
crm_supplier_ledger_ensure_schema($pdo);

$suppliers = $pdo->query(
    'SELECT id, code, name_ar FROM crm_supplier WHERE is_active = 1 ORDER BY name_ar'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$supplierId = (int) ($_GET['supplier_id'] ?? 0);
$mode = strtolower(trim((string) ($_GET['mode'] ?? 'detail')));
if ($mode !== 'summary') {
    $mode = 'detail';
}

$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
if ($from === '') {
    $from = app_default_date_from();
}
if ($to === '') {
    $to = app_default_date_to();
}

$reportTitle = 'كشف ذمم الموردين';
$routeKey = 'report_supplier_payables';
$showResult = false;
$err = '';
$built = [
    'mode' => $mode,
    'detail_rows' => [],
    'detail_groups' => [],
    'summary_rows' => [],
    'totals' => [
        'purchases_total' => 0.0,
        'payments_total' => 0.0,
        'balance_due' => 0.0,
        'payment_pct' => '—',
        'balance_pct' => '—',
        'invoice_count' => 0,
        'supplier_count' => 0,
    ],
];

$submitted = isset($_GET['run']);

if ($submitted) {
    $fromIso = parse_date_to_iso($from);
    $toIso = parse_date_to_iso($to);
    if ($fromIso === null || $toIso === null) {
        $err = 'تاريخ البداية والنهاية غير صالحين (يوم-شهر-سنة).';
    } elseif ($fromIso > $toIso) {
        $err = 'تاريخ البداية يجب أن يكون قبل أو يساوي تاريخ النهاية.';
    } elseif ($supplierId > 0) {
        $st = $pdo->prepare('SELECT id FROM crm_supplier WHERE id = ? AND is_active = 1 LIMIT 1');
        $st->execute([$supplierId]);
        if (!$st->fetch()) {
            $err = 'المورد غير موجود.';
        }
    }

    if ($err === '') {
        $from = $fromIso;
        $to = $toIso;
        $showResult = true;
        $built = pur_report_payables_build($pdo, [
            'supplier_id' => $supplierId,
            'from' => $from,
            'to' => $to,
            'mode' => $mode,
        ]);
        $mode = $built['mode'];
    }
}

$supplierLabel = 'جميع الموردين';
if ($supplierId > 0) {
    foreach ($suppliers as $s) {
        if ((int) ($s['id'] ?? 0) === $supplierId) {
            $supplierLabel = (string) ($s['name_ar'] ?? '');
            if (($s['code'] ?? '') !== '') {
                $supplierLabel .= ' (' . $s['code'] . ')';
            }
            break;
        }
    }
}

$modeLabel = $mode === 'summary' ? 'إجمالي' : 'تفصيلي';

$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$invCssPath = app_path('assets/css/sales-invoice.css');
$invCssUrl = app_url('assets/css/sales-invoice.css') . (is_file($invCssPath) ? '?v=' . (string) filemtime($invCssPath) : '');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');

$pageDataAttrs = ' data-report-title="' . esc($reportTitle) . '"';
$pageDataAttrs .= ' data-report-route="' . esc($routeKey) . '"';
$pageDataAttrs .= ' data-receivables-mode="' . esc($mode) . '"';
if ($showResult) {
    $pageDataAttrs .= ' data-export-label="' . esc($modeLabel . '-' . $supplierLabel) . '"';
    $pageDataAttrs .= ' data-from-dmy="' . esc(format_date_dmY($from)) . '"';
    $pageDataAttrs .= ' data-to-dmy="' . esc(format_date_dmY($to)) . '"';
}

$totals = $built['totals'];
$receivablesTotalTdStyle = 'background-color:#e2e8f0;-webkit-print-color-adjust:exact;print-color-adjust:exact;';

$renderGrandTotal = static function (array $totals, string $to, bool $withPct = false, int $summaryLeadColspan = 0) use ($receivablesTotalTdStyle): void {
    $summaryAlign = $summaryLeadColspan > 0;
    $tableClass = 'report-sales-table report-receivables-table report-receivables-grand-total-table'
        . ($summaryAlign ? ' report-receivables-grand-total-table--summary report-receivables-grand-total-table--summary-7' : '');
    $labelColspan = $summaryAlign ? ' colspan="' . (int) $summaryLeadColspan . '"' : '';
    ?>
    <div class="report-sales-table-wrap report-receivables-grand-total-wrap">
        <table class="<?= esc($tableClass) ?>">
            <?php if ($summaryAlign && $summaryLeadColspan === 2): ?>
            <colgroup>
                <col class="col-seq">
                <col class="col-customer">
                <col class="col-money">
                <col class="col-money">
                <col class="col-pct">
                <col class="col-money">
                <col class="col-pct">
            </colgroup>
            <?php endif; ?>
            <thead>
            <tr>
                <?php if ($summaryAlign): ?>
                    <th colspan="<?= (int) $summaryLeadColspan ?>" class="report-receivables-total-label">البيان</th>
                <?php else: ?>
                    <th>البيان</th>
                <?php endif; ?>
                <th class="col-money">مجموع المشتريات</th>
                <?php if ($withPct): ?>
                    <th class="col-money">مجموع المدفوعات</th>
                    <th class="col-pct">نسبة السداد</th>
                <?php endif; ?>
                <th class="col-money report-receivables-col-balance">الذمم المستحقة</th>
                <?php if ($withPct): ?>
                    <th class="col-pct">نسبة الذمم</th>
                <?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <tr class="report-sales-tfoot">
                <td<?= $labelColspan ?> class="report-receivables-total-cell report-receivables-total-label" style="<?= esc($receivablesTotalTdStyle) ?>">
                    <strong>إجمالي التقرير — حتى <?= esc(format_date_dmY($to)) ?></strong>
                </td>
                <td class="col-money report-receivables-total-cell" style="<?= esc($receivablesTotalTdStyle) ?>">
                    <strong><?= esc(format_amount((float) ($totals['purchases_total'] ?? 0))) ?></strong>
                </td>
                <?php if ($withPct): ?>
                    <td class="col-money report-receivables-total-cell" style="<?= esc($receivablesTotalTdStyle) ?>">
                        <strong><?= esc(format_amount((float) ($totals['payments_total'] ?? 0))) ?></strong>
                    </td>
                    <td class="col-pct report-receivables-total-cell" style="<?= esc($receivablesTotalTdStyle) ?>">
                        <strong><?= esc((string) ($totals['payment_pct'] ?? '—')) ?></strong>
                    </td>
                <?php endif; ?>
                <td class="col-money report-receivables-total-cell" style="<?= esc($receivablesTotalTdStyle) ?>">
                    <strong><?= esc(format_amount((float) ($totals['balance_due'] ?? 0))) ?></strong>
                </td>
                <?php if ($withPct): ?>
                    <td class="col-pct report-receivables-total-cell" style="<?= esc($receivablesTotalTdStyle) ?>">
                        <strong><?= esc((string) ($totals['balance_pct'] ?? '—')) ?></strong>
                    </td>
                <?php endif; ?>
            </tr>
            </tbody>
        </table>
    </div>
    <?php
};

/**
 * @param array<string, mixed> $metrics
 */
$renderSupplierMetrics = static function (array $metrics) use ($receivablesTotalTdStyle): void {
    ?>
    <div class="report-sales-table-wrap report-receivables-customer-metrics-wrap">
        <table class="report-sales-table report-receivables-table report-receivables-metrics-table">
            <thead>
            <tr>
                <th class="col-money">مجموع المشتريات</th>
                <th class="col-money">مجموع المدفوعات</th>
                <th class="col-pct">نسبة السداد</th>
                <th class="col-money report-receivables-col-balance">الذمم المستحقة</th>
                <th class="col-pct">نسبة الذمم</th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td class="col-money report-receivables-total-cell" style="<?= esc($receivablesTotalTdStyle) ?>">
                    <strong><?= esc(format_amount((float) ($metrics['purchases_total'] ?? 0))) ?></strong>
                </td>
                <td class="col-money report-receivables-total-cell" style="<?= esc($receivablesTotalTdStyle) ?>">
                    <strong><?= esc(format_amount((float) ($metrics['payments_total'] ?? 0))) ?></strong>
                </td>
                <td class="col-pct report-receivables-total-cell" style="<?= esc($receivablesTotalTdStyle) ?>">
                    <strong><?= esc((string) ($metrics['payment_pct'] ?? '—')) ?></strong>
                </td>
                <td class="col-money report-receivables-total-cell" style="<?= esc($receivablesTotalTdStyle) ?>">
                    <strong><?= esc(format_amount((float) ($metrics['balance_due'] ?? 0))) ?></strong>
                </td>
                <td class="col-pct report-receivables-total-cell" style="<?= esc($receivablesTotalTdStyle) ?>">
                    <strong><?= esc((string) ($metrics['balance_pct'] ?? '—')) ?></strong>
                </td>
            </tr>
            </tbody>
        </table>
    </div>
    <?php
};
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($invCssUrl) ?>">

<div class="card report-sales-page report-receivables-page"<?= $pageDataAttrs ?>>

    <?php if ($err !== ''): ?>
        <div class="alert alert-error no-print" style="margin-bottom:1rem;"><?= esc($err) ?></div>
    <?php endif; ?>

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print">
        <input type="hidden" name="r" value="report_supplier_payables">
        <input type="hidden" name="run" value="1">
        <div class="form-row">
            <label class="field" style="flex:1 1 14rem;">
                <span class="field-label">المورد</span>
                <select class="input" name="supplier_id">
                    <option value="0" <?= $supplierId === 0 ? 'selected' : '' ?>>جميع الموردين</option>
                    <?php foreach ($suppliers as $s): ?>
                        <option value="<?= (int) $s['id'] ?>" <?= (int) $s['id'] === $supplierId ? 'selected' : '' ?>>
                            <?= esc((string) ($s['name_ar'] ?? '')) ?>
                            <?php if (($s['code'] ?? '') !== ''): ?>
                                (<?= esc((string) $s['code']) ?>)
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span class="field-label">من تاريخ *</span>
                <input class="input js-date-dmy" type="text" name="from" value="<?= esc(format_date_dmY($from)) ?>"
                       placeholder="يوم-شهر-سنة" dir="ltr" autocomplete="off" inputmode="numeric" required>
            </label>
            <label class="field">
                <span class="field-label">إلى تاريخ *</span>
                <input class="input js-date-dmy" type="text" name="to" value="<?= esc(format_date_dmY($to)) ?>"
                       placeholder="يوم-شهر-سنة" dir="ltr" autocomplete="off" inputmode="numeric" required>
            </label>
        </div>
        <div class="form-row" style="margin-top:0.35rem;">
            <span class="field-label" style="align-self:center;margin-left:0.5rem;">نوع العرض</span>
            <label class="field" style="flex:0 0 auto;">
                <input type="radio" name="mode" value="detail" <?= $mode === 'detail' ? 'checked' : '' ?>>
                تفصيلي
            </label>
            <label class="field" style="flex:0 0 auto;">
                <input type="radio" name="mode" value="summary" <?= $mode === 'summary' ? 'checked' : '' ?>>
                إجمالي
            </label>
        </div>
        <div style="margin-top:0.5rem;">
            <button class="btn btn-primary" type="submit">عرض التقرير</button>
        </div>
    </form>

    <?php if ($showResult): ?>
        <div class="report-sales-result report-sales-print-area report-receivables-print<?= $mode === 'summary' ? ' report-receivables-print--summary' : '' ?>">
            <?= document_print_header_html($reportTitle, $pdo) ?>

            <div class="doc-print-meta">
                <table>
                    <tr>
                        <td><strong>نوع العرض:</strong> <?= esc($modeLabel) ?></td>
                    </tr>
                    <tr>
                        <td><strong>المورد:</strong> <span class="doc-print-meta-value doc-print-meta-value--party"><?= esc($supplierLabel) ?></span></td>
                    </tr>
                    <tr>
                        <td>
                            <strong>من تاريخ:</strong> <?= esc(format_date_dmY($from)) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>إلى تاريخ:</strong> <?= esc(format_date_dmY($to)) ?>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <strong>عدد الموردين:</strong> <?= (int) ($totals['supplier_count'] ?? 0) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>عدد فواتير الشراء (تفصيلي):</strong> <?= (int) ($totals['invoice_count'] ?? 0) ?>
                        </td>
                    </tr>
                </table>
            </div>

            <?php if ($mode === 'summary'): ?>
            <div class="report-sales-table-wrap report-receivables-summary-table-wrap">
                    <table class="report-sales-table report-receivables-table report-receivables-summary-table">
                        <colgroup>
                            <col class="col-seq">
                            <col class="col-customer">
                            <col class="col-money">
                            <col class="col-money">
                            <col class="col-pct">
                            <col class="col-money">
                            <col class="col-pct">
                        </colgroup>
                        <thead>
                        <tr>
                            <th class="col-seq">#</th>
                            <th class="col-customer">اسم المورد</th>
                            <th class="col-money">مجموع المشتريات</th>
                            <th class="col-money">مجموع المدفوعات</th>
                            <th class="col-pct">نسبة السداد</th>
                            <th class="col-money report-receivables-col-balance">الذمم المستحقة</th>
                            <th class="col-pct">نسبة الذمم</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!$built['summary_rows']): ?>
                            <tr>
                                <td colspan="7" class="muted" style="text-align:center;padding:1.25rem;">
                                    لا توجد بيانات للفترة والفلاتر المحددة.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php $seq = 0; ?>
                            <?php foreach ($built['summary_rows'] as $row): ?>
                                <?php $seq++; ?>
                                <tr class="report-receivables-customer-row">
                                    <td class="col-seq"><?= $seq ?></td>
                                    <td class="col-customer"><span class="report-receivables-party-name"><?= esc((string) ($row['supplier_name'] ?? '')) ?></span></td>
                                    <td class="col-money"><?= esc(format_amount((float) ($row['purchases_total'] ?? 0))) ?></td>
                                    <td class="col-money"><?= esc(format_amount((float) ($row['payments_total'] ?? 0))) ?></td>
                                    <td class="col-pct"><?= esc((string) ($row['payment_pct'] ?? '—')) ?></td>
                                    <td class="col-money report-receivables-customer-balance"><strong><?= esc(format_amount((float) ($row['balance_due'] ?? 0))) ?></strong></td>
                                    <td class="col-pct"><?= esc((string) ($row['balance_pct'] ?? '—')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
            </div>
                    <?php if ($built['summary_rows']): ?>
                        <?php $renderGrandTotal($totals, $to, true, 2); ?>
                    <?php endif; ?>
            <?php else: ?>
                    <div class="report-receivables-detail-groups">
                        <?php if (!$built['detail_groups']): ?>
                            <p class="muted" style="text-align:center;padding:1.25rem;">
                                لا توجد حركات ذمم في الفترة المحددة.
                            </p>
                        <?php else: ?>
                            <?php foreach ($built['detail_groups'] as $grp): ?>
                                <section class="report-receivables-customer-block">
                                    <h3 class="report-receivables-customer-title">
                                        <?= esc((string) ($grp['supplier_display'] ?? $grp['supplier_name'] ?? '')) ?>
                                    </h3>
                                    <div class="report-sales-table-wrap">
                                        <table class="report-sales-table party-stmt-table report-receivables-table report-receivables-customer-table">
                                            <thead>
                                            <tr>
                                                <th class="col-date">التاريخ</th>
                                                <th class="col-desc">الوصف</th>
                                                <th class="col-doc">الرقم</th>
                                                <th class="col-money">مدين</th>
                                                <th class="col-money">دائن</th>
                                                <th class="col-money report-receivables-col-balance">الرصيد / الذمم</th>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach ($grp['rows'] as $row): ?>
                                                <?php
                                                $rowType = (string) ($row['row_type'] ?? '');
                                                if ($rowType === 'supplier_subtotal'):
                                                    $invCnt = (int) ($row['invoice_count'] ?? 0);
                                                    ?>
                                                    <tr class="report-sales-group-total report-receivables-customer-total">
                                                        <td colspan="3" class="report-receivables-total-cell" style="<?= esc($receivablesTotalTdStyle) ?>"><strong>إجمالي ذمة المورد</strong><?= $invCnt > 0 ? ' <span class="report-receivables-sub-hint">(' . $invCnt . ' فاتورة ذمم في الفترة)</span>' : '' ?></td>
                                                        <td class="col-money report-receivables-total-cell" style="<?= esc($receivablesTotalTdStyle) ?>"><strong><?= (float) ($row['debit'] ?? 0) > 0 ? esc(format_money((float) $row['debit'])) : '—' ?></strong></td>
                                                        <td class="col-money report-receivables-total-cell" style="<?= esc($receivablesTotalTdStyle) ?>"><strong><?= (float) ($row['credit'] ?? 0) > 0 ? esc(format_money((float) $row['credit'])) : '—' ?></strong></td>
                                                        <td class="col-money report-receivables-customer-balance report-receivables-total-cell" style="<?= esc($receivablesTotalTdStyle) ?>"><strong><?= esc(format_money((float) ($row['balance'] ?? 0))) ?></strong></td>
                                                    </tr>
                                                <?php elseif ($rowType === 'opening'): ?>
                                                    <tr class="party-stmt-opening">
                                                        <td class="col-date"><?= esc(format_date_dmY((string) ($row['date'] ?? ''))) ?></td>
                                                        <td colspan="2"><em><?= esc((string) ($row['description'] ?? 'رصيد افتتاحي')) ?></em></td>
                                                        <td class="col-money"><?= (float) ($row['debit'] ?? 0) > 0 ? esc(format_money((float) $row['debit'])) : '—' ?></td>
                                                        <td class="col-money"><?= (float) ($row['credit'] ?? 0) > 0 ? esc(format_money((float) $row['credit'])) : '—' ?></td>
                                                        <td class="col-money"><strong><?= esc(format_money((float) ($row['balance'] ?? 0))) ?></strong></td>
                                                    </tr>
                                                <?php else: ?>
                                                    <tr>
                                                        <td class="col-date"><?= esc(format_date_dmY((string) ($row['date'] ?? ''))) ?></td>
                                                        <td class="col-desc"><?= esc((string) ($row['description'] ?? '')) ?></td>
                                                        <td class="col-doc"><?= esc((string) ($row['ref_no'] ?? '')) ?></td>
                                                        <td class="col-money"><?= (float) ($row['debit'] ?? 0) > 0 ? esc(format_money((float) $row['debit'])) : '—' ?></td>
                                                        <td class="col-money"><?= (float) ($row['credit'] ?? 0) > 0 ? esc(format_money((float) $row['credit'])) : '—' ?></td>
                                                        <td class="col-money"><?= esc(format_money((float) ($row['balance'] ?? 0))) ?></td>
                                                    </tr>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php
                                    $grpMetrics = $grp['metrics'] ?? [];
                                    if (is_array($grpMetrics) && $grpMetrics !== []) {
                                        $renderSupplierMetrics($grpMetrics);
                                    }
                                    ?>
                                </section>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <?php if ($built['summary_rows']): ?>
                        <section class="report-receivables-detail-grand-section" aria-label="إجمالي التقرير">
                            <?php $renderGrandTotal($totals, $to, true); ?>
                        </section>
                    <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script src="<?= esc($exportJsUrl) ?>" defer></script>
