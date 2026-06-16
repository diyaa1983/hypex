<?php
declare(strict_types=1);

require_once app_path('includes/acc_report_vat_jordan.php');
require_once app_path('includes/acc_vat_tax_report.php');
require_once app_path('includes/document_header.php');

$pdo = db();
acc_gl_ensure_schema($pdo);

$dateFrom = parse_date_to_iso(trim((string) ($_GET['date_from'] ?? ''))) ?? app_default_date_from();
$dateTo = parse_date_to_iso(trim((string) ($_GET['date_to'] ?? ''))) ?? app_default_date_to();
$view = (string) ($_GET['view'] ?? 'summary') === 'detail' ? 'detail' : 'summary';

if ($dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$v = acc_report_vat_jordan_summary($pdo, $dateFrom, $dateTo);
$detailRows = $view === 'detail'
    ? acc_vat_report_combined_invoice_tax_lines($pdo, $dateFrom, $dateTo)
    : [];
$detailTotals = $view === 'detail'
    ? acc_vat_report_combined_invoice_tax_totals($detailRows)
    : [];

$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$accCssPath = app_path('assets/css/report-acc.css');
$accCssUrl = app_url('assets/css/report-acc.css') . (is_file($accCssPath) ? '?v=' . (string) filemtime($accCssPath) : '');

$viewLabel = $view === 'detail' ? 'تفصيلي' : 'إجمالي';
$reportTitle = 'صافي الضريبة المستحقة على المبيعات والمشتريات — ' . $viewLabel;

$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');

$queryBase = static function (string $viewMode) use ($dateFrom, $dateTo): string {
    return 'r=report_vat_net_payable&view=' . rawurlencode($viewMode)
        . '&date_from=' . rawurlencode(format_date_dmY($dateFrom))
        . '&date_to=' . rawurlencode(format_date_dmY($dateTo));
};
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($accCssUrl) ?>">
<style><?= document_print_header_css() ?></style>

<div class="card report-sales-page report-vat-net-page"
     data-report-title="<?= esc($reportTitle) ?>"
     data-report-route="report_vat_net_payable"
     data-report-view="<?= esc($view) ?>"
     data-export-label="<?= esc($view === 'detail' ? 'detail' : 'summary') ?>"
     data-from-dmy="<?= esc(format_date_dmY($dateFrom)) ?>"
     data-to-dmy="<?= esc(format_date_dmY($dateTo)) ?>">
    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print">
        <input type="hidden" name="r" value="report_vat_net_payable">
        <div class="form-row">
            <label class="field">
                <span class="field-label">من تاريخ</span>
                <input class="input js-date-dmy" type="text" name="date_from" value="<?= esc(format_date_dmY($dateFrom)) ?>" dir="ltr" autocomplete="off">
            </label>
            <label class="field">
                <span class="field-label">إلى تاريخ</span>
                <input class="input js-date-dmy" type="text" name="date_to" value="<?= esc(format_date_dmY($dateTo)) ?>" dir="ltr" autocomplete="off">
            </label>
            <label class="field">
                <span class="field-label">العرض</span>
                <select class="input" name="view">
                    <option value="summary"<?= $view === 'summary' ? ' selected' : '' ?>>إجمالي</option>
                    <option value="detail"<?= $view === 'detail' ? ' selected' : '' ?>>تفصيلي</option>
                </select>
            </label>
        </div>
        <p class="muted" style="margin:0 0 0.5rem;font-size:0.85rem;">
            للفترة الضريبية (كل شهرين): اختر تاريخ بداية ونهاية الفترة. المستحق = ضريبة المبيعات − ضريبة المشتريات (بعد المردودات).
            <?php if ($view === 'detail'): ?>
                <strong>التفصيلي:</strong> يعرض الإجمالي وجميع فواتير المبيعات والمشتريات المرحّلة في الفترة.
            <?php endif; ?>
        </p>
        <div style="display:flex;flex-wrap:wrap;gap:0.5rem;align-items:center;">
            <button type="submit" class="btn btn-primary btn-sm">عرض</button>
            <?php if ($view !== 'summary'): ?>
                <a class="btn btn-secondary btn-sm" href="<?= esc(app_url('index.php?' . $queryBase('summary'))) ?>">عرض إجمالي</a>
            <?php endif; ?>
            <?php if ($view !== 'detail'): ?>
                <a class="btn btn-secondary btn-sm" href="<?= esc(app_url('index.php?' . $queryBase('detail'))) ?>">عرض تفصيلي</a>
            <?php endif; ?>
        </div>
        <details class="no-print" style="margin-top:0.75rem;font-size:0.85rem;">
            <summary class="muted" style="cursor:pointer;">تفاصيل حسب المردودات (اختياري)</summary>
            <div style="margin-top:0.5rem;display:flex;flex-wrap:wrap;gap:0.5rem;">
                <a class="btn btn-secondary btn-sm" href="<?= esc(app_url('index.php?r=report_vat_return_tax')) ?>">تفصيل مردود البيع</a>
                <a class="btn btn-secondary btn-sm" href="<?= esc(app_url('index.php?r=report_vat_return_tax_purchase')) ?>">تفصيل مردود الشراء</a>
            </div>
        </details>
    </form>

    <div class="report-sales-result report-sales-print-area" style="margin-top:1rem;">
        <?= document_print_header_html($reportTitle, $pdo) ?>
        <p class="party-stmt-report-dates">
            <span>من: <?= esc(format_date_dmY($dateFrom)) ?></span>
            <span> | </span>
            <span>إلى: <?= esc(format_date_dmY($dateTo)) ?></span>
            <span> | </span>
            <span>العرض: <?= esc($viewLabel) ?></span>
        </p>

        <?php if ((int) $v['output_account_id'] < 1 || (int) $v['input_account_id'] < 1): ?>
            <p class="alert alert-error">ربط حسابي <code>vat_output</code> و <code>vat_input</code> غير مكتمل في شاشة ربط الحسابات.</p>
        <?php else: ?>

        <div class="report-vat-net-summary-hero" style="margin:1rem 0;padding:1.25rem;background:#eef6ff;border-radius:8px;text-align:center;">
            <div class="muted report-vat-net-summary-hero__label" style="font-size:0.9rem;margin-bottom:0.35rem;">صافي الضريبة المستحق للدفع (من الدفتر)</div>
            <div class="report-vat-net-summary-hero__amount" style="font-size:1.75rem;font-weight:700;"><?= esc(format_money((float) $v['net_payable'])) ?></div>
            <div class="report-vat-net-summary-formula muted" style="font-size:0.82rem;margin-top:0.5rem;line-height:1.45;">
                <div>ضريبة مخرجات (مبيعات − مردود بيع): <strong><?= esc(format_money((float) $v['output_net'])) ?></strong></div>
                <div>ضريبة مدخلات (مشتريات − مردود شراء): <strong><?= esc(format_money((float) $v['input_net'])) ?></strong></div>
            </div>
        </div>

        <?php if ($v['returns_need_repost']): ?>
            <p class="alert alert-error no-print">
                مردودات قديمة لم تُخصم ضريبتها من حسابي الضريبة — راجع المستندات والدفتر. (المستندات تُظهر
                <strong><?= esc(format_money((float) $v['doc_net_payable'])) ?></strong>
                بينما الدفتر
                <strong><?= esc(format_money((float) $v['net_payable'])) ?></strong>)
            </p>
        <?php elseif ((float) $v['gl_doc_gap'] >= 0.01): ?>
            <p class="alert no-print" style="background:#fff8e6;border:1px solid #e8d48a;padding:0.65rem;border-radius:4px;">
                فرق بسيط بين الدفتر والمستندات:
                <?= esc(format_money((float) $v['gl_doc_gap'])) ?>
                — راجع دفتر حسابي الضريبة.
            </p>
        <?php endif; ?>

        <table class="data-table report-acc-table" style="max-width:40rem;">
            <thead>
            <tr><th colspan="2">ضريبة المبيعات (مخرجات) — <?= esc($v['output_name']) ?></th></tr>
            </thead>
            <tbody>
            <tr>
                <td>فواتير بيع (دائن على حساب الضريبة)</td>
                <td class="col-money" style="text-align:end"><?= esc(format_money((float) $v['sales_tax'])) ?></td>
            </tr>
            <tr>
                <td>مردود بيع (مدين — يخصم من المستحق)</td>
                <td class="col-money" style="text-align:end">− <?= esc(format_money((float) $v['sale_return_tax'])) ?></td>
            </tr>
            <tr class="report-acc-total">
                <td><strong>صافي ضريبة المبيعات</strong></td>
                <td class="col-money" style="text-align:end"><strong><?= esc(format_money((float) $v['output_net'])) ?></strong></td>
            </tr>
            </tbody>
        </table>

        <table class="data-table report-acc-table" style="max-width:40rem;margin-top:1rem;">
            <thead>
            <tr><th colspan="2">ضريبة المشتريات (مدخلات) — <?= esc($v['input_name']) ?></th></tr>
            </thead>
            <tbody>
            <tr>
                <td>فواتير شراء (مدين — تُخصم من المستحق)</td>
                <td class="col-money" style="text-align:end"><?= esc(format_money((float) $v['purchase_tax'])) ?></td>
            </tr>
            <tr>
                <td>مردود شراء (دائن — يقلل المدخلات)</td>
                <td class="col-money" style="text-align:end">− <?= esc(format_money((float) $v['purchase_return_tax'])) ?></td>
            </tr>
            <tr class="report-acc-total">
                <td><strong>صافي ضريبة المشتريات (مستحقة لك)</strong></td>
                <td class="col-money" style="text-align:end"><strong><?= esc(format_money((float) $v['input_net'])) ?></strong></td>
            </tr>
            </tbody>
        </table>

        <?php if ($view === 'detail'): ?>
        <div class="report-sales-table-wrap report-vat-net-detail-section" style="margin-top:1.25rem;">
            <h3 class="report-vat-net-detail-title" style="margin:0 0 0.65rem;font-size:1rem;">تفصيل فواتير المبيعات والمشتريات</h3>
            <p class="muted report-vat-net-detail-meta" style="margin:0 0 0.65rem;font-size:0.85rem;">
                فواتير مرحّلة فقط — بدون مردودات. العدد:
                <?= (int) ($detailTotals['total_docs'] ?? 0) ?>
                (مبيعات: <?= (int) ($detailTotals['sale_docs'] ?? 0) ?> · مشتريات: <?= (int) ($detailTotals['purchase_docs'] ?? 0) ?>)
            </p>
            <table class="data-table report-sales-table report-vat-net-detail-table">
                <colgroup>
                    <col class="col-seq">
                    <col class="col-type">
                    <col class="col-date">
                    <col class="col-inv-no">
                    <col class="col-tax-rate">
                    <col class="col-total">
                    <col class="col-tax-amt">
                </colgroup>
                <thead>
                <tr>
                    <th class="col-seq">#</th>
                    <th>نوع الفاتورة</th>
                    <th class="col-date">التاريخ</th>
                    <th class="col-inv-no">رقم الفاتورة</th>
                    <th class="col-money">نسبة الضريبة</th>
                    <th class="col-money">مجموع الفاتورة</th>
                    <th class="col-money">قيمة الضريبة</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$detailRows): ?>
                    <tr>
                        <td colspan="7" class="muted" style="text-align:center;padding:1.25rem;">
                            لا توجد فواتير مرحّلة في الفترة المحددة.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php $seq = 0; ?>
                    <?php foreach ($detailRows as $row): ?>
                        <?php $seq++; ?>
                        <tr>
                            <td class="col-seq"><?= $seq ?></td>
                            <td><?= esc((string) ($row['doc_type_label'] ?? '')) ?></td>
                            <td class="col-date"><?= esc(format_date_dmY((string) ($row['doc_date'] ?? ''))) ?></td>
                            <td class="col-inv-no"><code><?= esc((string) ($row['doc_no'] ?? '')) ?></code></td>
                            <td class="col-money"><?= esc(number_format((float) ($row['tax_rate_percent'] ?? 0), 2)) ?>%</td>
                            <td class="col-money"><?= esc(format_amount((float) ($row['total'] ?? 0))) ?></td>
                            <td class="col-money"><?= esc(format_amount((float) ($row['tax_amount'] ?? 0))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            <?php if ($detailRows): ?>
            <div class="report-sales-table-wrap report-vat-net-detail-totals-wrap html2pdf-page-break-avoid">
                <table class="data-table report-sales-table report-vat-net-detail-totals-table">
                    <colgroup>
                        <col class="col-seq">
                        <col class="col-type">
                        <col class="col-date">
                        <col class="col-inv-no">
                        <col class="col-tax-rate">
                        <col class="col-total">
                        <col class="col-tax-amt">
                    </colgroup>
                    <tbody>
                    <tr class="report-sales-tfoot">
                        <td colspan="4"><strong>المجموع</strong></td>
                        <td class="col-money">—</td>
                        <td class="col-money"><strong><?= esc(format_amount((float) (($detailTotals['sale_total'] ?? 0) + ($detailTotals['purchase_total'] ?? 0)))) ?></strong></td>
                        <td class="col-money"><strong><?= esc(format_amount((float) (($detailTotals['sale_tax'] ?? 0) + ($detailTotals['purchase_tax'] ?? 0)))) ?></strong></td>
                    </tr>
                    <tr class="report-sales-tfoot">
                        <td colspan="4"><strong>ضريبة المبيعات (فواتير)</strong></td>
                        <td class="col-money">—</td>
                        <td class="col-money"><strong><?= esc(format_amount((float) ($detailTotals['sale_total'] ?? 0))) ?></strong></td>
                        <td class="col-money"><strong><?= esc(format_amount((float) ($detailTotals['sale_tax'] ?? 0))) ?></strong></td>
                    </tr>
                    <tr class="report-sales-tfoot">
                        <td colspan="4"><strong>ضريبة المشتريات (فواتير)</strong></td>
                        <td class="col-money">—</td>
                        <td class="col-money"><strong><?= esc(format_amount((float) ($detailTotals['purchase_total'] ?? 0))) ?></strong></td>
                        <td class="col-money"><strong><?= esc(format_amount((float) ($detailTotals['purchase_tax'] ?? 0))) ?></strong></td>
                    </tr>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <details class="no-print" style="margin-top:1rem;font-size:0.88rem;">
            <summary class="muted" style="cursor:pointer;">مرجع من المستندات (للتحقق)</summary>
            <table class="data-table" style="margin-top:0.5rem;max-width:40rem;">
                <tr><td>ضريبة مبيعات − مردود (مستندات)</td><td style="text-align:end"><?= esc(format_money((float) $v['doc_output_net'])) ?></td></tr>
                <tr><td>ضريبة مشتريات − مردود (مستندات)</td><td style="text-align:end"><?= esc(format_money((float) $v['doc_input_net'])) ?></td></tr>
                <tr><td><strong>صافي مستحق (مستندات)</strong></td><td style="text-align:end"><strong><?= esc(format_money((float) $v['doc_net_payable'])) ?></strong></td></tr>
            </table>
        </details>
        <?php endif; ?>

        <?php endif; ?>
    </div>
</div>

<div id="sales-inv-export-host" class="sales-inv-export-host" aria-hidden="true"></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" defer crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="<?= esc($exportJsUrl) ?>"></script>
