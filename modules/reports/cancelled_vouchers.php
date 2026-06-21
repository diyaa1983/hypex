<?php
declare(strict_types=1);

require_once app_path('includes/fin_cancelled_vouchers_report.php');
require_once app_path('includes/document_header.php');

$pdo = db();

$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
$docKind = (string) ($_GET['kind'] ?? 'all');
if (!in_array($docKind, ['all', 'receipt', 'payment', 'journal'], true)) {
    $docKind = 'all';
}

if ($from === '') {
    $from = app_default_date_from();
}
if ($to === '') {
    $to = app_default_date_to();
}

$rows = [];
$sumAmount = 0.0;
$err = '';
$showResult = false;
$submitted = isset($_GET['from']) || isset($_GET['to']) || isset($_GET['kind']);

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
        try {
            $rows = fin_cancelled_vouchers_report_fetch($pdo, $from, $to, $docKind);
            foreach ($rows as $r) {
                $sumAmount += (float) ($r['amount'] ?? 0);
            }
            $showResult = true;
        } catch (Throwable $e) {
            $err = 'تعذر تحميل التقرير: ' . $e->getMessage();
        }
    }
}

$kindLabels = [
    'all' => 'الكل',
    'receipt' => 'سند قبض',
    'payment' => 'سند صرف',
    'journal' => 'سند قيد',
];
$kindLabel = $kindLabels[$docKind] ?? 'الكل';

$reportTitle = 'قائمة السندات الملغاة';

$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$invCssPath = app_path('assets/css/sales-invoice.css');
$invCssUrl = app_url('assets/css/sales-invoice.css') . (is_file($invCssPath) ? '?v=' . (string) filemtime($invCssPath) : '');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');
$sortJsPath = app_path('assets/js/report-sales-table-sort.js');
$sortJsUrl = app_url('assets/js/report-sales-table-sort.js') . (is_file($sortJsPath) ? '?v=' . (string) filemtime($sortJsPath) : '');

$pageDataAttrs = ' data-report-title="' . esc($reportTitle) . '"';
$pageDataAttrs .= ' data-report-route="report_cancelled_vouchers"';
if ($showResult) {
    $pageDataAttrs .= ' data-export-label="' . esc($kindLabel) . '"';
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
        <input type="hidden" name="r" value="report_cancelled_vouchers">
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
            <label class="field" style="flex:0 1 11rem;">
                <span class="field-label">نوع السند</span>
                <select class="input" name="kind">
                    <option value="all"<?= $docKind === 'all' ? ' selected' : '' ?>>الكل</option>
                    <option value="receipt"<?= $docKind === 'receipt' ? ' selected' : '' ?>>سند قبض</option>
                    <option value="payment"<?= $docKind === 'payment' ? ' selected' : '' ?>>سند صرف</option>
                    <option value="journal"<?= $docKind === 'journal' ? ' selected' : '' ?>>سند قيد</option>
                </select>
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
                            <strong>نوع السند:</strong> <?= esc($kindLabel) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>من تاريخ:</strong> <?= esc(format_date_dmY($from)) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>إلى تاريخ:</strong> <?= esc(format_date_dmY($to)) ?>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <strong>عدد السندات الملغاة:</strong> <?= count($rows) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>مجموع المبالغ:</strong> <?= esc(format_money($sumAmount)) ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="muted" style="font-size:0.85rem;">
                            السندات المرحّلة ثم المُلغاة تبقى في السجل برقمها للحفاظ على التسلسل.
                        </td>
                    </tr>
                </table>
            </div>

            <div class="report-sales-table-wrap">
                <table class="report-sales-table js-sortable-report"
                       data-default-sort="cancelled_at"
                       data-default-dir="desc">
                    <colgroup>
                        <col class="col-seq">
                        <col class="col-pay">
                        <col class="col-inv-no">
                        <col class="col-date">
                        <col class="col-customer">
                        <col class="col-pay">
                        <col class="col-money">
                        <col class="col-date">
                        <col class="col-customer">
                        <col class="col-act">
                    </colgroup>
                    <thead>
                    <tr>
                        <th class="col-seq js-sort-th" data-sort="seq" data-sort-type="number" title="ترتيب">#</th>
                        <th class="col-pay js-sort-th" data-sort="doc_kind_label" data-sort-type="text" title="ترتيب حسب النوع">نوع السند</th>
                        <th class="col-inv-no js-sort-th" data-sort="doc_no" data-sort-type="text" title="ترتيب حسب الرقم">رقم السند</th>
                        <th class="col-date js-sort-th" data-sort="doc_date" data-sort-type="date" title="ترتيب حسب تاريخ السند">تاريخ السند</th>
                        <th class="col-customer js-sort-th" data-sort="party_detail" data-sort-type="text" title="ترتيب حسب الطرف">الطرف / الوصف</th>
                        <th class="col-pay js-sort-th" data-sort="pay_method_label" data-sort-type="text" title="ترتيب حسب الدفع">الدفع</th>
                        <th class="col-money js-sort-th" data-sort="amount" data-sort-type="number" title="ترتيب حسب المبلغ">المبلغ</th>
                        <th class="col-date js-sort-th" data-sort="cancelled_at" data-sort-type="date" title="ترتيب حسب تاريخ الإلغاء">تاريخ الإلغاء</th>
                        <th class="col-customer js-sort-th" data-sort="cancelled_by_name" data-sort-type="text" title="ترتيب حسب المستخدم">أُلغي بواسطة</th>
                        <th class="col-act"></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="10" class="muted" style="text-align:center;padding:1.25rem;">
                                لا توجد سندات ملغاة في الفترة المحددة.
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php
                    $seq = 0;
                    foreach ($rows as $row):
                        $seq += 1;
                        $kind = (string) ($row['doc_kind'] ?? '');
                        $viewUrl = fin_cancelled_voucher_view_url($kind, (int) ($row['id'] ?? 0));
                        $party = trim((string) ($row['party_name'] ?? ''));
                        $desc = trim((string) ($row['description'] ?? ''));
                        $detail = $party !== '' ? $party : ($desc !== '' ? $desc : '—');
                        $cancelledAt = (string) ($row['cancelled_at'] ?? '');
                        $cancelledAtIso = $cancelledAt !== '' ? substr($cancelledAt, 0, 10) : '';
                        $payLabel = trim((string) ($row['pay_method_label'] ?? ''));
                        ?>
                        <tr data-sort-row="1"
                            data-sort-seq="<?= $seq ?>"
                            data-sort-doc_kind_label="<?= esc((string) ($row['doc_kind_label'] ?? '')) ?>"
                            data-sort-doc_no="<?= esc((string) ($row['doc_no'] ?? '')) ?>"
                            data-sort-doc_date="<?= esc((string) ($row['doc_date'] ?? '')) ?>"
                            data-sort-party_detail="<?= esc($detail) ?>"
                            data-sort-pay_method_label="<?= esc($payLabel !== '' ? $payLabel : '—') ?>"
                            data-sort-amount="<?= esc((string) (float) ($row['amount'] ?? 0)) ?>"
                            data-sort-cancelled_at="<?= esc($cancelledAtIso) ?>"
                            data-sort-cancelled_by_name="<?= esc((string) ($row['cancelled_by_name'] ?? '—')) ?>">
                            <td class="col-seq"><?= $seq ?></td>
                            <td class="col-pay">
                                <span class="badge badge-cancelled-voucher"><?= esc((string) ($row['doc_kind_label'] ?? '')) ?></span>
                            </td>
                            <td class="col-inv-no">
                                <code class="voucher-no-is-cancelled"><?= esc((string) ($row['doc_no'] ?? '')) ?></code>
                                <a class="btn btn-ghost btn-sm no-print" style="padding:0 0.35rem;font-size:0.75rem;"
                                   href="<?= esc($viewUrl) ?>">عرض</a>
                            </td>
                            <td class="col-date" dir="ltr"><?= esc(format_date_dmY((string) ($row['doc_date'] ?? ''))) ?></td>
                            <td class="col-customer"><span class="report-sales-party-name"><?= esc($detail) ?></span></td>
                            <td class="col-pay"><?= esc($payLabel !== '' ? $payLabel : '—') ?></td>
                            <td class="col-money"><?= esc(format_money((float) ($row['amount'] ?? 0))) ?></td>
                            <td class="col-date" dir="ltr"><?= $cancelledAtIso !== '' ? esc(format_date_dmY($cancelledAtIso)) : '—' ?></td>
                            <td class="col-customer"><?= esc((string) ($row['cancelled_by_name'] ?? '—')) ?></td>
                            <td class="col-act no-print">
                                <a class="btn btn-ghost btn-sm" href="<?= esc($viewUrl) ?>">عرض</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php if ($rows): ?>
                    <tfoot>
                    <tr>
                        <td colspan="6">الإجمالي — <?= count($rows) ?> سند ملغى</td>
                        <td class="col-money"><?= esc(format_money($sumAmount)) ?></td>
                        <td colspan="3"></td>
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
