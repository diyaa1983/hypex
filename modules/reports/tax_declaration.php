<?php
declare(strict_types=1);

require_once app_path('includes/acc_report_tax_declaration.php');
require_once app_path('includes/document_header.php');

$pdo = db();
acc_gl_ensure_schema($pdo);

$dateFrom = parse_date_to_iso(trim((string) ($_GET['date_from'] ?? ''))) ?? app_default_date_from();
$dateTo = parse_date_to_iso(trim((string) ($_GET['date_to'] ?? ''))) ?? app_default_date_to();

if ($dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$decl = acc_report_tax_declaration($pdo, $dateFrom, $dateTo);
$vat = $decl['vat'] ?? [];
$lines = $decl['lines'] ?? [];
$counts = $decl['counts'] ?? [];
$taxByDate = $decl['tax_by_date'] ?? [];

$reportTitle = 'الإقرار الضريبي';

$salesCssPath = app_path('assets/css/report-sales.css');
$salesCssUrl = app_url('assets/css/report-sales.css') . (is_file($salesCssPath) ? '?v=' . (string) filemtime($salesCssPath) : '');
$accCssPath = app_path('assets/css/report-acc.css');
$accCssUrl = app_url('assets/css/report-acc.css') . (is_file($accCssPath) ? '?v=' . (string) filemtime($accCssPath) : '');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');

$vatDetailUrl = app_url(
    'index.php?r=report_vat_net_payable&date_from=' . rawurlencode(format_date_dmY($dateFrom))
    . '&date_to=' . rawurlencode(format_date_dmY($dateTo))
);
?>
<link rel="stylesheet" href="<?= esc($salesCssUrl) ?>">
<link rel="stylesheet" href="<?= esc($accCssUrl) ?>">
<style><?= document_print_header_css() ?></style>

<div class="card report-sales-page report-tax-declaration-page"
     data-report-title="<?= esc($reportTitle) ?>"
     data-report-route="report_tax_declaration"
     data-export-label="إقرار"
     data-from-dmy="<?= esc(format_date_dmY($dateFrom)) ?>"
     data-to-dmy="<?= esc(format_date_dmY($dateTo)) ?>">

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print">
        <input type="hidden" name="r" value="report_tax_declaration">
        <div class="form-row">
            <label class="field">
                <span class="field-label">من تاريخ</span>
                <input class="input js-date-dmy" type="text" name="date_from" value="<?= esc(format_date_dmY($dateFrom)) ?>" dir="ltr" autocomplete="off">
            </label>
            <label class="field">
                <span class="field-label">إلى تاريخ</span>
                <input class="input js-date-dmy" type="text" name="date_to" value="<?= esc(format_date_dmY($dateTo)) ?>" dir="ltr" autocomplete="off">
            </label>
        </div>
        <p class="muted tax-decl-hint">اختر فترة الإقرار (شهر أو شهرين حسب الدورة الضريبية).</p>
        <div style="display:flex;flex-wrap:wrap;gap:0.5rem;align-items:center;">
            <button type="submit" class="btn btn-primary btn-sm">عرض الإقرار</button>
            <button type="button" class="btn btn-secondary btn-sm" id="tax-decl-print-btn">طباعة</button>
            <a class="btn btn-secondary btn-sm" href="<?= esc($vatDetailUrl) ?>">تفاصيل الضريبة</a>
        </div>
    </form>

    <div class="report-sales-result report-sales-print-area">
        <?= document_print_header_html($reportTitle, $pdo) ?>

        <div class="tax-decl-meta">
            <div class="tax-decl-meta__row">
                <span class="tax-decl-meta__label">اسم المنشأة</span>
                <strong><?= esc((string) ($decl['company_name'] ?? '—')) ?></strong>
            </div>
            <?php if (trim((string) ($decl['trade_name'] ?? '')) !== ''): ?>
            <div class="tax-decl-meta__row">
                <span class="tax-decl-meta__label">الاسم التجاري</span>
                <span><?= esc((string) $decl['trade_name']) ?></span>
            </div>
            <?php endif; ?>
            <div class="tax-decl-meta__row">
                <span class="tax-decl-meta__label">الرقم الضريبي</span>
                <strong dir="ltr"><?= esc((string) (($decl['tax_id'] ?? '') !== '' ? $decl['tax_id'] : '—')) ?></strong>
            </div>
            <div class="tax-decl-meta__row">
                <span class="tax-decl-meta__label">فترة الإقرار</span>
                <span>من <?= esc(format_date_dmY($dateFrom)) ?> إلى <?= esc(format_date_dmY($dateTo)) ?></span>
            </div>
            <div class="tax-decl-meta__row">
                <span class="tax-decl-meta__label">نسبة الضريبة المعتمدة</span>
                <span><?= esc(number_format((float) ($decl['tax_rate_percent'] ?? 0), 2)) ?>%</span>
            </div>
        </div>

        <?php if ((int) ($vat['output_account_id'] ?? 0) < 1 || (int) ($vat['input_account_id'] ?? 0) < 1): ?>
        <p class="alert alert-error">ربط حسابي <code>vat_output</code> و <code>vat_input</code> غير مكتمل في شاشة ربط الحسابات.</p>
        <?php else: ?>

        <div class="tax-decl-net-hero<?= !empty($decl['is_payable']) ? '' : ' tax-decl-net-hero--refund' ?>">
            <span class="tax-decl-net-hero__label"><?= esc((string) ($decl['net_label'] ?? '')) ?></span>
            <strong class="tax-decl-net-hero__amount"><?= esc(format_money(abs((float) ($decl['net_payable'] ?? 0)))) ?></strong>
        </div>

        <?php if (!empty($decl['returns_need_repost'])): ?>
        <p class="alert alert-error no-print">
            مردودات قديمة لم تُخصم ضريبتها من الدفتر — راجع القيود المحاسبية للمردودات.
        </p>
        <?php elseif ((float) ($decl['gl_doc_gap'] ?? 0) >= 0.01): ?>
        <p class="alert no-print tax-decl-gap-warn">
            فرق بين ضريبة الفواتير في الدفتر والمستندات: <?= esc(format_money((float) $decl['gl_doc_gap'])) ?>
        </p>
        <?php endif; ?>

        <div class="report-sales-table-wrap">
            <table class="data-table report-sales-table tax-decl-table">
                <thead>
                <tr>
                    <th class="col-desc">البند</th>
                    <th class="col-money">القيمة (بدون ضريبة)</th>
                    <th class="col-money">قيمة الضريبة</th>
                </tr>
                </thead>
                <tbody>
                <?php
                $currentSection = '';
                foreach ($lines as $line):
                    $section = (string) ($line['section'] ?? '');
                    if ($section !== $currentSection):
                        $currentSection = $section;
                        if ($section === 'sales') {
                            $sectionTitle = 'أولاً: المبيعات (ضريبة المخرجات)';
                        } elseif ($section === 'purchases') {
                            $sectionTitle = 'ثانياً: المشتريات (ضريبة المدخلات)';
                        } else {
                            $sectionTitle = 'ثالثاً: التسوية والتوريد للضريبة';
                        }
                        ?>
                        <tr class="tax-decl-section-row">
                            <td colspan="3"><strong><?= esc($sectionTitle) ?></strong></td>
                        </tr>
                    <?php endif;
                    $isDeduction = !empty($line['is_deduction']);
                    $isSubtotal = !empty($line['is_subtotal']);
                    $rowClass = 'tax-decl-row';
                    if ($isSubtotal) {
                        $rowClass .= ' tax-decl-row--subtotal';
                    } elseif ($isDeduction) {
                        $rowClass .= ' tax-decl-row--deduction';
                    }
                    $base = $line['taxable_base'] ?? null;
                    $tax = (float) ($line['tax_amount'] ?? 0);
                    ?>
                    <tr class="<?= esc($rowClass) ?>">
                        <td class="col-desc"><?= esc((string) ($line['label'] ?? '')) ?></td>
                        <td class="col-money">
                            <?php if ($base === null): ?>
                                —
                            <?php elseif ($isDeduction && abs((float) $base) > 0.000001): ?>
                                <span class="tax-decl-deduction">(<?= esc(format_money(abs((float) $base))) ?>)</span>
                            <?php else: ?>
                                <?= esc(format_money((float) $base)) ?>
                            <?php endif; ?>
                        </td>
                        <td class="col-money">
                            <?php if ($isDeduction && abs($tax) > 0.000001): ?>
                                <span class="tax-decl-deduction">(<?= esc(format_money(abs($tax))) ?>)</span>
                            <?php else: ?>
                                <?= esc(format_money($tax)) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                <tr class="tax-decl-net-row">
                    <td class="col-desc"><strong><?= esc((string) ($decl['net_label'] ?? '')) ?></strong></td>
                    <td class="col-money">—</td>
                    <td class="col-money"><strong><?= esc(format_money(abs((float) ($decl['net_payable'] ?? 0)))) ?></strong></td>
                </tr>
                </tfoot>
            </table>
        </div>

        <?php if (is_array($taxByDate) && $taxByDate !== []): ?>
        <h3 class="tax-decl-bydate-title">تفصيل الضريبة حسب التاريخ</h3>
        <div class="report-sales-table-wrap">
            <table class="data-table report-sales-table tax-decl-bydate-table">
                <thead>
                <tr>
                    <th class="col-date">التاريخ</th>
                    <th class="col-money">ضريبة المبيعات</th>
                    <th class="col-money">مردود بيع</th>
                    <th class="col-money">ضريبة المشتريات</th>
                    <th class="col-money">مردود شراء</th>
                    <th class="col-money">التوريد للضريبة</th>
                    <th class="col-money">صافي اليوم</th>
                </tr>
                </thead>
                <tbody>
                <?php
                $sumSales = 0.0;
                $sumSaleRet = 0.0;
                $sumPur = 0.0;
                $sumPurRet = 0.0;
                $sumRemit = 0.0;
                $sumDay = 0.0;
                foreach ($taxByDate as $day):
                    $dSales = (float) ($day['sales_tax'] ?? 0);
                    $dSaleRet = (float) ($day['sale_return_tax'] ?? 0);
                    $dPur = (float) ($day['purchase_tax'] ?? 0);
                    $dPurRet = (float) ($day['purchase_return_tax'] ?? 0);
                    $dRemit = (float) ($day['remittance'] ?? 0);
                    $dNet = (float) ($day['day_net'] ?? 0);
                    $sumSales += $dSales;
                    $sumSaleRet += $dSaleRet;
                    $sumPur += $dPur;
                    $sumPurRet += $dPurRet;
                    $sumRemit += $dRemit;
                    $sumDay += $dNet;
                    ?>
                    <tr>
                        <td class="col-date" dir="ltr"><?= esc(format_date_dmY((string) ($day['entry_date'] ?? ''))) ?></td>
                        <td class="col-money"><?= esc(format_money($dSales)) ?></td>
                        <td class="col-money">
                            <?php if (abs($dSaleRet) > 0.000001): ?>
                                <span class="tax-decl-deduction">(<?= esc(format_money(abs($dSaleRet))) ?>)</span>
                            <?php else: ?>
                                <?= esc(format_money(0)) ?>
                            <?php endif; ?>
                        </td>
                        <td class="col-money"><?= esc(format_money($dPur)) ?></td>
                        <td class="col-money">
                            <?php if (abs($dPurRet) > 0.000001): ?>
                                <span class="tax-decl-deduction">(<?= esc(format_money(abs($dPurRet))) ?>)</span>
                            <?php else: ?>
                                <?= esc(format_money(0)) ?>
                            <?php endif; ?>
                        </td>
                        <td class="col-money">
                            <?php if (abs($dRemit) > 0.000001): ?>
                                <span class="tax-decl-deduction">(<?= esc(format_money(abs($dRemit))) ?>)</span>
                            <?php else: ?>
                                <?= esc(format_money(0)) ?>
                            <?php endif; ?>
                        </td>
                        <td class="col-money"><strong><?= esc(format_money($dNet)) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                <tr class="tax-decl-row--subtotal">
                    <td class="col-date"><strong>الإجمالي</strong></td>
                    <td class="col-money"><strong><?= esc(format_money($sumSales)) ?></strong></td>
                    <td class="col-money"><strong><span class="tax-decl-deduction">(<?= esc(format_money(abs($sumSaleRet))) ?>)</span></strong></td>
                    <td class="col-money"><strong><?= esc(format_money($sumPur)) ?></strong></td>
                    <td class="col-money"><strong><span class="tax-decl-deduction">(<?= esc(format_money(abs($sumPurRet))) ?>)</span></strong></td>
                    <td class="col-money"><strong><span class="tax-decl-deduction">(<?= esc(format_money(abs($sumRemit))) ?>)</span></strong></td>
                    <td class="col-money"><strong><?= esc(format_money($sumDay)) ?></strong></td>
                </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>

        <div class="tax-decl-counts muted no-print">
            <span>فواتير بيع: <?= (int) ($counts['sale_invoices'] ?? 0) ?></span>
            <span>·</span>
            <span>مردود بيع: <?= (int) ($counts['sale_returns'] ?? 0) ?></span>
            <span>·</span>
            <span>فواتير شراء: <?= (int) ($counts['purchase_invoices'] ?? 0) ?></span>
            <span>·</span>
            <span>مردود شراء: <?= (int) ($counts['purchase_returns'] ?? 0) ?></span>
            <?php if ((float) ($decl['remittance_tax'] ?? 0) >= 0.01): ?>
            <span>·</span>
            <span>توريدات الضريبة: <?= esc(format_money((float) $decl['remittance_tax'])) ?></span>
            <?php endif; ?>
        </div>

        <p class="muted tax-decl-footnote">
            تُحسب الضريبة حسب تاريخ القيود المرحّلة ضمن الفترة المحددة.
            صافي المبيعات/المشتريات من الفواتير فقط، ويُخصم بند «التوريد للضريبة» (المدفوعات على حساب أمانات الضريبة) منفصلاً.
        </p>

        <?php endif; ?>
    </div>
</div>

<div id="sales-inv-export-host" class="sales-inv-export-host" aria-hidden="true"></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" defer crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="<?= esc($exportJsUrl) ?>"></script>
<script>
(function () {
    var printBtn = document.getElementById('tax-decl-print-btn');
    if (printBtn) {
        printBtn.addEventListener('click', function () {
            var tb = document.querySelector('#master-toolbar [data-master-action="print"]');
            if (tb) {
                tb.click();
            } else {
                window.print();
            }
        });
    }
})();
</script>
