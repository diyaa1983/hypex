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

            <div class="ora-inv-meta">
                <div class="ora-inv-meta__cell">
                    <span class="ora-inv-meta__lab">رقم الفاتورة</span>
                    <span class="ora-inv-meta__val" dir="ltr"><?= (int) ($header['v_num'] ?? 0) ?> / <?= (int) ($header['vyear'] ?? 0) ?></span>
                </div>
                <div class="ora-inv-meta__cell">
                    <span class="ora-inv-meta__lab">التاريخ</span>
                    <span class="ora-inv-meta__val"><?= esc($fmtDate((string) ($header['vdate'] ?? ''))) ?></span>
                </div>
                <div class="ora-inv-meta__cell">
                    <span class="ora-inv-meta__lab">المستودع</span>
                    <span class="ora-inv-meta__val" dir="ltr"><?= (int) ($header['store'] ?? 0) ?></span>
                </div>
                <div class="ora-inv-meta__cell">
                    <span class="ora-inv-meta__lab">رقم العميل</span>
                    <span class="ora-inv-meta__val" dir="ltr"><?= esc((string) ($header['cust_acc'] ?? '')) ?></span>
                </div>
                <div class="ora-inv-meta__cell ora-inv-meta__cell--wide">
                    <span class="ora-inv-meta__lab">اسم العميل</span>
                    <span class="ora-inv-meta__val"><?= esc((string) ($header['customer_name'] ?? '')) ?: '—' ?></span>
                </div>
                <div class="ora-inv-meta__cell ora-inv-meta__cell--wide">
                    <span class="ora-inv-meta__lab">البائع (من بطاقة العميل)</span>
                    <span class="ora-inv-meta__val">
                        <?php if (!empty($header['salesman_no']) || ($header['salesman_name'] ?? '') !== ''): ?>
                            <span dir="ltr"><?= (int) ($header['salesman_no'] ?? 0) ?></span>
                            <?php if (($header['salesman_name'] ?? '') !== ''): ?>
                                — <?= esc((string) $header['salesman_name']) ?>
                            <?php endif; ?>
                        <?php else: ?>—<?php endif; ?>
                    </span>
                </div>
            </div>

            <div class="report-sales-table-wrap">
                <table class="report-sales-table">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>المادة</th>
                        <th>البيان</th>
                        <th>الفئة</th>
                        <th>التشغيلة</th>
                        <th>الوحدة</th>
                        <th>الكمية</th>
                        <th>بونص</th>
                        <th>السعر</th>
                        <th>ض%</th>
                        <th>الإجمالي</th>
                        <th>الضريبة</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$lines): ?>
                        <tr><td colspan="12" class="muted" style="text-align:center;padding:1rem;">لا بنود</td></tr>
                    <?php endif; ?>
                    <?php
                    $seq = 0;
                    foreach ($lines as $ln):
                        $seq++;
                        ?>
                        <tr>
                            <td><?= $seq ?></td>
                            <td dir="ltr"><code><?= esc((string) ($ln['item'] ?? '')) ?></code></td>
                            <td><?= esc((string) ($ln['item_name'] ?? '—')) ?></td>
                            <td dir="ltr"><?= esc((string) ($ln['cat'] ?? '')) ?></td>
                            <td dir="ltr"><?= esc((string) ($ln['batch'] ?? '')) ?></td>
                            <td dir="ltr"><?= esc((string) ($ln['unit_label'] ?? '—')) ?></td>
                            <td dir="ltr"><?= esc($fmtAmt((float) ($ln['qty'] ?? 0))) ?></td>
                            <td dir="ltr"><?= esc($fmtAmt((float) ($ln['bonus'] ?? 0))) ?></td>
                            <td dir="ltr"><?= esc($fmtAmt((float) ($ln['sell'] ?? 0))) ?></td>
                            <td dir="ltr"><?= esc(rtrim(rtrim(number_format((float) ($ln['tax_pct'] ?? 0), 2, '.', ''), '0'), '.') ?: '0') ?>%</td>
                            <td dir="ltr"><?= esc($fmtAmt((float) ($ln['line_gross'] ?? 0))) ?></td>
                            <td dir="ltr"><?= esc($fmtAmt((float) ($ln['vou_tax'] ?? 0))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php if ($lines): ?>
                    <tfoot>
                    <tr>
                        <td colspan="6">مجموع البنود</td>
                        <td dir="ltr"><?= esc($fmtAmt((float) ($header['qty_sum'] ?? 0))) ?></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td dir="ltr"><?= esc($fmtAmt((float) ($header['gross'] ?? 0))) ?></td>
                        <td dir="ltr"><?= esc($fmtAmt((float) ($header['tax_sum'] ?? 0))) ?></td>
                    </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>

            <?php if ($lines): ?>
            <div class="ora-inv-totals">
                <table class="ora-inv-totals__table">
                    <tr>
                        <th>مجموع الفاتورة</th>
                        <td dir="ltr"><?= esc($fmtAmt((float) ($header['gross'] ?? 0))) ?></td>
                    </tr>
                    <tr>
                        <th>خصم نسبة <?php
                            $pd = (float) ($header['per_disc'] ?? 0);
                            echo $pd > 0 ? esc(rtrim(rtrim(number_format($pd * 100, 2, '.', ''), '0'), '.') . '%') : '';
                        ?></th>
                        <td dir="ltr"><?= esc($fmtAmt((float) ($header['vou_disc'] ?? 0))) ?></td>
                    </tr>
                    <tr>
                        <th>الصافي قبل الضريبة</th>
                        <td dir="ltr"><?= esc($fmtAmt((float) ($header['net'] ?? 0))) ?></td>
                    </tr>
                    <tr>
                        <th>قيمة الضريبة</th>
                        <td dir="ltr"><?= esc($fmtAmt((float) ($header['tax_sum'] ?? 0))) ?></td>
                    </tr>
                    <tr class="ora-inv-totals__grand">
                        <th>الإجمالي النهائي</th>
                        <td dir="ltr"><?= esc($fmtAmt((float) ($header['total'] ?? 0))) ?></td>
                    </tr>
                </table>
            </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<style>
.ora-inv-meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(11rem,1fr));gap:.5rem .9rem;
  border:1px solid #d8dee9;border-radius:.5rem;padding:.7rem .9rem;margin-bottom:.85rem}
.ora-inv-meta__cell{display:flex;flex-direction:column;gap:.15rem;font-size:.86rem}
.ora-inv-meta__lab{color:#6b7280;font-size:.76rem}
.ora-inv-meta__val{font-weight:700}
.ora-inv-meta__cell--wide{grid-column:span 2}
.ora-inv-totals{display:flex;justify-content:flex-start;margin-top:.85rem}
.ora-inv-totals__table{border-collapse:collapse;min-width:19rem;font-size:.88rem}
.ora-inv-totals__table th,
.ora-inv-totals__table td{border:1px solid #d8dee9;padding:.35rem .7rem}
.ora-inv-totals__table th{background:#f4f6fa;text-align:right;font-weight:600;white-space:nowrap}
.ora-inv-totals__table td{text-align:left;font-family:ui-monospace,Consolas,monospace;min-width:7.5rem}
.ora-inv-totals__grand th,
.ora-inv-totals__grand td{background:#eef2ff;font-weight:800;font-size:.95rem}
@media print{
  .ora-inv-totals{page-break-inside:avoid}
  .ora-inv-meta{page-break-inside:avoid}
}
</style>

<div id="sales-inv-export-host" class="sales-inv-export-host" aria-hidden="true"></div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" defer crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="<?= esc($exportJsUrl) ?>" defer></script>
