<?php
declare(strict_types=1);

require_once app_path('includes/fin_vouchers_report.php');
require_once app_path('includes/document_header.php');

$pdo = db();

$voucherType = (string) ($_GET['type'] ?? 'receipt');
if (!in_array($voucherType, ['receipt', 'payment'], true)) {
    $voucherType = 'receipt';
}

$payFilter = (string) ($_GET['pay'] ?? 'both');
if (!in_array($payFilter, ['cash', 'check', 'both'], true)) {
    $payFilter = 'both';
}

$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));

if ($from === '') {
    $from = app_default_date_from();
}
if ($to === '') {
    $to = app_default_date_to();
}

$rows = [];
$err = '';
$showResult = false;
$sumAmount = 0.0;

$submitted = isset($_GET['from']) || isset($_GET['to']) || isset($_GET['pay']) || isset($_GET['type']);

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
        $rows = fin_vouchers_report_fetch($pdo, $voucherType, $payFilter, $from, $to);
        foreach ($rows as $r) {
            $sumAmount += (float) ($r['amount'] ?? 0);
        }
        $showResult = true;
    }
}

$reportTitle = $voucherType === 'payment' ? 'تقرير سندات الصرف' : 'تقرير سندات القبض';
$voucherEditBase = $voucherType === 'payment'
    ? app_url('index.php?r=cash_payment&id=')
    : app_url('index.php?r=cash_receipt&id=');

$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$invCssPath = app_path('assets/css/sales-invoice.css');
$invCssUrl = app_url('assets/css/sales-invoice.css') . (is_file($invCssPath) ? '?v=' . (string) filemtime($invCssPath) : '');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');
$sortJsPath = app_path('assets/js/report-sales-table-sort.js');
$sortJsUrl = app_url('assets/js/report-sales-table-sort.js') . (is_file($sortJsPath) ? '?v=' . (string) filemtime($sortJsPath) : '');

$pageDataAttrs = ' data-report-title="' . esc($reportTitle) . '"';
$pageDataAttrs .= ' data-report-route="report_vouchers"';
if ($showResult) {
    $payLabel = $payFilter === 'cash' ? 'نقد' : ($payFilter === 'check' ? 'شيك' : 'الكل');
    $pageDataAttrs .= ' data-export-label="' . esc($payLabel) . '"';
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
        <input type="hidden" name="r" value="report_vouchers">
        <div class="form-row">
            <label class="field" style="flex:1 1 12rem;">
                <span class="field-label">نوع السند</span>
                <select class="input" name="type">
                    <option value="receipt" <?= $voucherType === 'receipt' ? 'selected' : '' ?>>سندات قبض</option>
                    <option value="payment" <?= $voucherType === 'payment' ? 'selected' : '' ?>>سندات صرف</option>
                </select>
            </label>
            <label class="field" style="flex:1 1 12rem;">
                <span class="field-label">طريقة الدفع</span>
                <select class="input" name="pay">
                    <option value="both" <?= $payFilter === 'both' ? 'selected' : '' ?>>الاثنين معاً (نقد + شيك)</option>
                    <option value="cash" <?= $payFilter === 'cash' ? 'selected' : '' ?>>نقد فقط</option>
                    <option value="check" <?= $payFilter === 'check' ? 'selected' : '' ?>>شيك فقط</option>
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
        <div style="margin-top:0.5rem;">
            <button class="btn btn-primary" type="submit">عرض التقرير</button>
        </div>
    </form>

    <?php if ($showResult): ?>
        <div class="report-sales-result report-sales-print-area">
            <?= document_print_header_html($reportTitle, $pdo) ?>

            <div class="doc-print-meta">
                <table>
                    <tr>
                        <td>
                            <strong>نوع السند:</strong>
                            <?= $voucherType === 'payment' ? 'سندات الصرف' : 'سندات القبض' ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>طريقة الدفع:</strong>
                            <?= $payFilter === 'cash' ? 'نقد' : ($payFilter === 'check' ? 'شيك' : 'نقد + شيك') ?>
                        </td>
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
                            <strong>عدد السندات:</strong> <?= count($rows) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>مجموع المبالغ:</strong> <?= esc(format_amount($sumAmount)) ?>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="report-sales-table-wrap">
                <table class="report-sales-table js-sortable-report"
                       data-default-sort="voucher_date"
                       data-default-dir="asc">
                    <thead>
                    <tr>
                        <th class="col-seq js-sort-th" data-sort="seq" data-sort-type="number" title="ترتيب حسب التسلسل">تسلسل</th>
                        <th class="col-inv-no js-sort-th" data-sort="voucher_no" data-sort-type="text" title="ترتيب حسب رقم السند">رقم السند</th>
                        <th class="col-date js-sort-th" data-sort="voucher_date" data-sort-type="date" title="ترتيب حسب التاريخ">تاريخ السند</th>
                        <th class="col-customer js-sort-th" data-sort="party_name" data-sort-type="text" title="ترتيب حسب الطرف">الطرف</th>
                        <th class="col-pay js-sort-th" data-sort="pay_method" data-sort-type="text" title="ترتيب حسب طريقة الدفع">الدفع</th>
                        <th class="col-customer js-sort-th" data-sort="account_label" data-sort-type="text" title="ترتيب حسب الحساب">الحساب</th>
                        <th class="col-customer js-sort-th" data-sort="banks_label" data-sort-type="text" title="ترتيب حسب البنك">البنك</th>
                        <th class="col-posted js-sort-th" data-sort="posted_label" data-sort-type="text" title="ترتيب حسب حالة الترحيل">الترحيل</th>
                        <th class="col-money js-sort-th" data-sort="amount" data-sort-type="number" title="ترتيب حسب المبلغ">المبلغ</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="9" class="muted" style="text-align:center;padding:1.25rem;">
                                لا توجد سندات مطابقة للفلتر المحدد.
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php
                    $seq = 0;
                    foreach ($rows as $r):
                        $seq += 1;
                        $payMethod = (string) $r['pay_method'];
                        $payLabel = fin_vouchers_report_pay_method_label($payMethod);
                        $postedLabel = $r['is_posted'] ? 'مرحّل' : 'غير مرحّل';
                        $editUrl = $voucherEditBase . (int) $r['id'];
                        ?>
                        <tr data-sort-row="1"
                            data-sort-seq="<?= $seq ?>"
                            data-sort-voucher_no="<?= esc((string) $r['voucher_no']) ?>"
                            data-sort-voucher_date="<?= esc((string) ($r['voucher_date'] ?? '')) ?>"
                            data-sort-party_name="<?= esc((string) ($r['party_name'] ?? '')) ?>"
                            data-sort-pay_method="<?= esc($payLabel) ?>"
                            data-sort-account_label="<?= esc((string) $r['account_label']) ?>"
                            data-sort-banks_label="<?= esc((string) $r['banks_label']) ?>"
                            data-sort-posted_label="<?= esc($postedLabel) ?>"
                            data-sort-amount="<?= esc((string) (float) $r['amount']) ?>">
                            <td class="col-seq"><?= $seq ?></td>
                            <td class="col-inv-no">
                                <code><?= esc((string) $r['voucher_no']) ?></code>
                                <a class="btn btn-ghost btn-sm no-print" style="padding:0 0.35rem;font-size:0.75rem;"
                                   href="<?= esc($editUrl) ?>">عرض</a>
                            </td>
                            <td class="col-date"><?= esc(format_date_dmY((string) ($r['voucher_date'] ?? ''))) ?></td>
                            <td class="col-customer"><?= esc((string) ($r['party_name'] ?? '—')) ?></td>
                            <td class="col-pay">
                                <span class="badge <?= $payMethod === 'check' ? 'badge-warn' : 'badge-ok' ?>">
                                    <?= esc($payLabel) ?>
                                </span>
                            </td>
                            <td class="col-customer"><?= esc((string) $r['account_label']) ?></td>
                            <td class="col-customer"><?= esc((string) $r['banks_label']) ?></td>
                            <td class="col-posted">
                                <?php if ($r['is_posted']): ?>
                                    <span class="badge badge-ok">مرحّل</span>
                                <?php else: ?>
                                    <span class="badge badge-warn">غير مرحّل</span>
                                <?php endif; ?>
                            </td>
                            <td class="col-money"><?= esc(format_amount((float) $r['amount'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php if ($rows): ?>
                    <tfoot>
                    <tr>
                        <td colspan="8">الإجمالي</td>
                        <td class="col-money"><?= esc(format_amount($sumAmount)) ?></td>
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
<?php if ($showResult && $rows): ?>
<script src="<?= esc($sortJsUrl) ?>" defer></script>
<?php endif; ?>
<script src="<?= esc($exportJsUrl) ?>" defer></script>
