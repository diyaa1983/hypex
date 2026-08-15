<?php
declare(strict_types=1);

/**
 * استعلام فاتورة بيع Oracle برقم الفاتورة (MAS.DAILY / TYPE=9)
 */

require_once app_path('includes/oracle_sales_invoice.php');
require_once app_path('includes/document_header.php');

$routeKey = 'report_oracle_sales_invoice';
$reportTitle = 'فاتورة بيع Oracle';

$invoiceNo = (int) ($_GET['invoice_no'] ?? $_GET['v_num'] ?? 0);
$year = (int) ($_GET['year'] ?? $_GET['vyear'] ?? 0);
$submitted = isset($_GET['invoice_no']) || isset($_GET['v_num']) || isset($_GET['run']);

$result = null;
$err = '';
$showResult = false;

if ($submitted) {
    if ($invoiceNo < 1) {
        $err = 'أدخل رقم الفاتورة.';
    } else {
        $result = oracle_fetch_sales_invoice_by_no($invoiceNo, $year);
        if (empty($result['ok'])) {
            $err = (string) ($result['message'] ?? 'تعذر الاستعلام.');
        } else {
            $showResult = true;
            if (($result['message'] ?? '') !== '' && empty($result['header']) && empty($result['matches'])) {
                $err = (string) $result['message'];
            }
        }
    }
}

$fmtAmt = static function (float $n): string {
    return number_format(round($n, 3), 3, '.', ',');
};
$fmtDate = static function (string $iso): string {
    if ($iso === '') {
        return '—';
    }
    if (function_exists('format_date_dmY')) {
        $d = format_date_dmY($iso);

        return $d !== '' ? $d : $iso;
    }

    return $iso;
};

$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$invCssPath = app_path('assets/css/sales-invoice.css');
$invCssUrl = app_url('assets/css/sales-invoice.css') . (is_file($invCssPath) ? '?v=' . (string) filemtime($invCssPath) : '');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');

$header = is_array($result['header'] ?? null) ? $result['header'] : null;
$lines = is_array($result['lines'] ?? null) ? $result['lines'] : [];
$matches = is_array($result['matches'] ?? null) ? $result['matches'] : [];

$pageDataAttrs = ' data-report-title="' . esc($reportTitle) . '"';
$pageDataAttrs .= ' data-report-route="' . esc($routeKey) . '"';
if ($header) {
    $pageDataAttrs .= ' data-export-label="' . esc('فاتورة ' . ($header['v_num'] ?? '') . '-' . ($header['vyear'] ?? '')) . '"';
}
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($invCssUrl) ?>">

<div class="card report-sales-page"<?= $pageDataAttrs ?>>

    <?php if ($err !== ''): ?>
        <div class="alert alert-error no-print" style="margin-bottom:1rem;"><?= esc($err) ?></div>
    <?php endif; ?>

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print">
        <input type="hidden" name="r" value="<?= esc($routeKey) ?>">
        <input type="hidden" name="run" value="1">
        <div class="form-row">
            <label class="field" style="flex:1 1 12rem">
                <span class="field-label">رقم الفاتورة *</span>
                <input class="input" type="text" name="invoice_no" value="<?= $invoiceNo > 0 ? (int) $invoiceNo : '' ?>"
                       inputmode="numeric" dir="ltr" placeholder="مثال: 2842" required autocomplete="off">
            </label>
            <label class="field" style="flex:0 1 10rem">
                <span class="field-label">السنة (اختياري)</span>
                <input class="input" type="text" name="year" value="<?= $year > 0 ? (int) $year : '' ?>"
                       inputmode="numeric" dir="ltr" placeholder="2026" autocomplete="off">
            </label>
        </div>
        <div style="margin-top:0.5rem;">
            <button class="btn btn-primary" type="submit">عرض الفاتورة</button>
        </div>
    </form>

    <?php if ($showResult && $matches && !$header): ?>
        <div class="report-sales-result">
            <p class="muted" style="margin-bottom:.75rem;"><?= esc((string) ($result['message'] ?? 'اختر السنة')) ?></p>
            <div class="report-sales-table-wrap">
                <table class="report-sales-table">
                    <thead>
                    <tr>
                        <th>رقم الفاتورة</th>
                        <th>السنة</th>
                        <th>التاريخ</th>
                        <th>العميل</th>
                        <th>المستودع</th>
                        <th>الإجمالي</th>
                        <th class="no-print"></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($matches as $m): ?>
                        <tr>
                            <td dir="ltr"><code><?= (int) ($m['v_num'] ?? 0) ?></code></td>
                            <td dir="ltr"><?= (int) ($m['vyear'] ?? 0) ?></td>
                            <td dir="ltr"><?= esc($fmtDate((string) ($m['vdate'] ?? ''))) ?></td>
                            <td dir="ltr"><?= esc((string) ($m['cust_acc'] ?? '')) ?></td>
                            <td dir="ltr"><?= (int) ($m['store'] ?? 0) ?></td>
                            <td dir="ltr"><?= esc($fmtAmt((float) ($m['gross'] ?? 0))) ?></td>
                            <td class="no-print">
                                <a class="btn btn-primary" style="padding:.25rem .7rem;font-size:.8rem"
                                   href="<?= esc(app_url('index.php?r=' . $routeKey
                                       . '&run=1&invoice_no=' . (int) ($m['v_num'] ?? 0)
                                       . '&year=' . (int) ($m['vyear'] ?? 0))) ?>">فتح</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($showResult && $header): ?>
        <div class="report-sales-result report-sales-print-area">
            <?= document_print_header_html($reportTitle, db()) ?>

            <div class="doc-print-meta">
                <table>
                    <tr>
                        <td><strong>رقم الفاتورة:</strong>
                            <span class="doc-print-meta-value" dir="ltr"><?= (int) ($header['v_num'] ?? 0) ?> / <?= (int) ($header['vyear'] ?? 0) ?></span>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>التاريخ:</strong> <?= esc($fmtDate((string) ($header['vdate'] ?? ''))) ?>
                            &nbsp;|&nbsp; <strong>المستودع:</strong> <?= (int) ($header['store'] ?? 0) ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>العميل:</strong>
                            <span dir="ltr"><?= esc((string) ($header['cust_acc'] ?? '')) ?></span>
                            <?php if (($header['customer_name'] ?? '') !== ''): ?>
                                — <?= esc((string) $header['customer_name']) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <strong>الإجمالي قبل الخصم:</strong> <?= esc($fmtAmt((float) ($header['gross'] ?? 0))) ?>
                            &nbsp;|&nbsp; <strong>الخصم:</strong> <?= esc($fmtAmt((float) ($header['vou_disc'] ?? 0))) ?>
                            &nbsp;|&nbsp; <strong>الضريبة:</strong> <?= esc($fmtAmt((float) ($header['tax_sum'] ?? 0))) ?>
                            &nbsp;|&nbsp; <strong>الصافي مع الضريبة:</strong> <?= esc($fmtAmt((float) ($header['total'] ?? 0))) ?>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="report-sales-table-wrap">
                <table class="report-sales-table">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>المادة</th>
                        <th>الفئة</th>
                        <th>التشغيلة</th>
                        <th>الكمية</th>
                        <th>بونص</th>
                        <th>السعر</th>
                        <th>الإجمالي</th>
                        <th>الضريبة</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$lines): ?>
                        <tr><td colspan="9" class="muted" style="text-align:center;padding:1rem;">لا بنود</td></tr>
                    <?php endif; ?>
                    <?php
                    $seq = 0;
                    foreach ($lines as $ln):
                        $seq++;
                        ?>
                        <tr>
                            <td><?= $seq ?></td>
                            <td dir="ltr"><code><?= esc((string) ($ln['item'] ?? '')) ?></code></td>
                            <td dir="ltr"><?= esc((string) ($ln['cat'] ?? '')) ?></td>
                            <td dir="ltr"><?= esc((string) ($ln['batch'] ?? '')) ?></td>
                            <td dir="ltr"><?= esc($fmtAmt((float) ($ln['qty'] ?? 0))) ?></td>
                            <td dir="ltr"><?= esc($fmtAmt((float) ($ln['bonus'] ?? 0))) ?></td>
                            <td dir="ltr"><?= esc($fmtAmt((float) ($ln['sell'] ?? 0))) ?></td>
                            <td dir="ltr"><?= esc($fmtAmt((float) ($ln['line_gross'] ?? 0))) ?></td>
                            <td dir="ltr"><?= esc($fmtAmt((float) ($ln['vou_tax'] ?? 0))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php if ($lines): ?>
                    <tfoot>
                    <tr>
                        <td colspan="4">الإجمالي</td>
                        <td dir="ltr"><?= esc($fmtAmt((float) ($header['qty_sum'] ?? 0))) ?></td>
                        <td></td>
                        <td></td>
                        <td dir="ltr"><?= esc($fmtAmt((float) ($header['gross'] ?? 0))) ?></td>
                        <td dir="ltr"><?= esc($fmtAmt((float) ($header['tax_sum'] ?? 0))) ?></td>
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
<script src="<?= esc($exportJsUrl) ?>" defer></script>
