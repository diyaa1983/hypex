<?php
declare(strict_types=1);

require_once app_path('includes/sal_invoice_discount_report.php');
require_once app_path('includes/document_header.php');

$pdo = db();

$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));

if ($from === '') {
    $from = app_default_date_from();
}
if ($to === '') {
    $to = app_default_date_to();
}

$rows = [];
$totals = ['grand_total' => 0.0, 'invoice_count' => 0, 'by_invoice' => []];
$showResult = false;
$err = '';

$submitted = isset($_GET['from']) && isset($_GET['to']);

if ($submitted) {
    $fromIso = parse_date_to_iso($from);
    $toIso = parse_date_to_iso($to);
    if ($fromIso === null || $toIso === null) {
        $err = 'تاريخ البداية والنهاية غير صالحين (يوم-شهر-سنة).';
    } elseif ($fromIso > $toIso) {
        $err = 'تاريخ البداية يجب أن يكون قبل أو يساوي تاريخ النهاية.';
    } else {
        $from = $fromIso;
        $to = $toIso;
        $showResult = true;
        $rows = sal_report_invoice_discount_lines($pdo, $from, $to);
        $totals = sal_report_invoice_discount_totals($rows);
    }
}

$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$invCssPath = app_path('assets/css/sales-invoice.css');
$invCssUrl = app_url('assets/css/sales-invoice.css') . (is_file($invCssPath) ? '?v=' . (string) filemtime($invCssPath) : '');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');

$reportTitle = 'الخصم على الفواتير';

$pageDataAttrs = ' data-report-title="' . esc($reportTitle) . '"';
$pageDataAttrs .= ' data-report-route="report_sales_invoice_discount"';
if ($showResult && $err === '') {
    $pageDataAttrs .= ' data-export-label="' . esc($reportTitle) . '"';
    $pageDataAttrs .= ' data-from-dmy="' . esc(format_date_dmY($from)) . '"';
    $pageDataAttrs .= ' data-to-dmy="' . esc(format_date_dmY($to)) . '"';
}
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($invCssUrl) ?>">

<div class="card report-sales-page"<?= $pageDataAttrs ?>>

    <?php if ($err !== ''): ?>
        <div class="alert alert-error no-print" style="margin-bottom:1rem;"><?= esc($err) ?></div>
    <?php endif; ?>

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print">
        <input type="hidden" name="r" value="report_sales_invoice_discount">
        <div class="form-row">
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
        <div style="margin-top:0.5rem;">
            <button class="btn btn-primary" type="submit">عرض التقرير</button>
        </div>
    </form>

    <?php if ($showResult && $err === ''): ?>
        <div class="report-sales-result report-sales-print-area">
            <?= document_print_header_html($reportTitle, $pdo) ?>

            <div class="doc-print-meta">
                <table>
                    <tr>
                        <td>
                            <strong>من تاريخ:</strong> <?= esc(format_date_dmY($from)) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>إلى تاريخ:</strong> <?= esc(format_date_dmY($to)) ?>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <strong>عدد الفواتير:</strong> <?= (int) ($totals['invoice_count'] ?? 0) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>عدد البنود المخصومة:</strong> <?= count($rows) ?>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="report-sales-table-wrap">
                <table class="report-sales-table report-sales-table--invoice-discount">
                    <colgroup>
                        <col class="col-seq">
                        <col class="col-inv-no">
                        <col class="col-item">
                        <col class="col-pct">
                        <col class="col-money">
                    </colgroup>
                    <thead>
                    <tr>
                        <th class="col-seq">تسلسل</th>
                        <th class="col-inv-no">رقم الفاتورة</th>
                        <th class="col-item">اسم المادة</th>
                        <th class="col-pct">الخصم %</th>
                        <th class="col-money">الخصم (مبلغ)</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="5" class="muted" style="text-align:center;padding:1.25rem;">
                                لا توجد فواتير بخصم في الفترة المحددة.
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php
                    $seq = 0;
                    $prevInvoiceId = 0;
                    $prevInvoiceNo = '';
                    $invoiceSubtotal = 0.0;
                    foreach ($rows as $r):
                        $invId = (int) ($r['invoice_id'] ?? 0);
                        $invNo = (string) ($r['invoice_no'] ?? '');
                        if ($prevInvoiceId > 0 && $invId !== $prevInvoiceId):
                            ?>
                            <tr class="report-sales-discount-invoice-total">
                                <td colspan="4" class="report-sales-discount-invoice-total-label">
                                    مجموع خصم الفاتورة <?= esc($prevInvoiceNo) ?>
                                </td>
                                <td class="col-money"><?= esc(format_money($invoiceSubtotal)) ?></td>
                            </tr>
                            <?php
                            $invoiceSubtotal = 0.0;
                        endif;

                        $seq += 1;
                        $discAmt = (float) ($r['discount_amount'] ?? 0);
                        $invoiceSubtotal += $discAmt;
                        $prevInvoiceId = $invId;
                        $prevInvoiceNo = $invNo;
                        $invUrl = app_url('index.php?r=sales_invoices&id=' . $invId);
                        ?>
                        <tr>
                            <td class="col-seq"><?= $seq ?></td>
                            <td class="col-inv-no">
                                <code><?= esc($invNo) ?></code>
                                <a class="btn btn-ghost btn-sm no-print" style="padding:0 0.35rem;font-size:0.75rem;"
                                   href="<?= esc($invUrl) ?>">عرض</a>
                            </td>
                            <td class="col-item"><span class="report-sales-item-name"><?= esc((string) $r['item_name']) ?></span></td>
                            <td class="col-pct"><?= esc(sal_report_format_discount_pct((float) ($r['discount_pct'] ?? 0))) ?></td>
                            <td class="col-money"><?= esc(format_money($discAmt)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($rows && $prevInvoiceId > 0): ?>
                        <tr class="report-sales-discount-invoice-total">
                            <td colspan="4" class="report-sales-discount-invoice-total-label">
                                مجموع خصم الفاتورة <?= esc($prevInvoiceNo) ?>
                            </td>
                            <td class="col-money"><?= esc(format_money($invoiceSubtotal)) ?></td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                    <?php if ($rows): ?>
                    <tfoot>
                    <tr>
                        <td colspan="4">إجمالي الخصم</td>
                        <td class="col-money"><?= esc(format_money((float) ($totals['grand_total'] ?? 0))) ?></td>
                    </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<div id="sales-inv-export-host" class="sales-inv-export-host" aria-hidden="true"></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" defer crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="<?= esc($exportJsUrl) ?>"></script>
