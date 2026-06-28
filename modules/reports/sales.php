<?php
declare(strict_types=1);

require_once app_path('includes/sal_sales_by_customer_report.php');
require_once app_path('includes/document_header.php');
require_once app_path('includes/crm_sales_rep_schema.php');

$pdo = db();
crm_sales_rep_ensure_schema($pdo);

$customers = $pdo->query(
    'SELECT id, code, name_ar FROM crm_customer WHERE is_active = 1 ORDER BY name_ar'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$salesReps = $pdo->query(
    'SELECT id, name_ar FROM crm_sales_rep WHERE is_active = 1 ORDER BY name_ar'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$customerId = isset($_GET['customer_id']) && $_GET['customer_id'] !== ''
    ? (int) $_GET['customer_id']
    : -1;
$salesRepId = isset($_GET['sales_rep_id']) && $_GET['sales_rep_id'] !== ''
    ? (int) $_GET['sales_rep_id']
    : 0;

$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
if ($from === '') {
    $from = app_default_date_from();
}
if ($to === '') {
    $to = app_default_date_to();
}

$rows = [];
$customerLabel = '';
$salesRepLabel = '';
$sumSub = 0.0;
$sumTotal = 0.0;
$showResult = false;
$invoiceDocCount = 0;
$returnDocCount = 0;
$err = '';

$submitted = isset($_GET['customer_id']) && $_GET['customer_id'] !== '';
if ($submitted) {
    if ($customerId < 0) {
        $err = 'اختر العميل أو «جميع العملاء».';
    } elseif ($salesRepId < 0) {
        $err = 'اختر المندوب أو «جميع المندوبين».';
    } else {
        $fromIso = parse_date_to_iso($from);
        $toIso = parse_date_to_iso($to);
        if ($fromIso === null || $toIso === null) {
            $err = 'تاريخ البداية والنهاية غير صالحين (يوم-شهر-سنة).';
        } elseif ($fromIso > $toIso) {
            $err = 'تاريخ البداية يجب أن يكون قبل أو يساوي تاريخ النهاية.';
        } else {
            $from = $fromIso;
            $to = $toIso;

            if ($customerId === 0) {
                $customerLabel = 'جميع العملاء';
                $showResult = true;
            } else {
                $st = $pdo->prepare('SELECT name_ar FROM crm_customer WHERE id = ? LIMIT 1');
                $st->execute([$customerId]);
                $cust = $st->fetch(PDO::FETCH_ASSOC);
                if (!$cust) {
                    $err = 'العميل غير موجود.';
                } else {
                    $showResult = true;
                    $customerLabel = (string) ($cust['name_ar'] ?? '');
                }
            }

            if ($showResult) {
                if ($salesRepId === 0) {
                    $salesRepLabel = 'جميع المندوبين';
                } else {
                    $stRep = $pdo->prepare('SELECT name_ar FROM crm_sales_rep WHERE id = ? LIMIT 1');
                    $stRep->execute([$salesRepId]);
                    $rep = $stRep->fetch(PDO::FETCH_ASSOC);
                    if (!$rep) {
                        $showResult = false;
                        $err = 'المندوب غير موجود.';
                    } else {
                        $salesRepLabel = (string) ($rep['name_ar'] ?? '');
                    }
                }
            }

            if ($showResult) {
                $rows = sal_report_sales_by_customer($pdo, $customerId, $from, $to, $salesRepId);
                $rowTotals = sal_report_sales_rows_totals($rows);
                $sumSub = (float) ($rowTotals['subtotal'] ?? 0);
                $sumTotal = (float) ($rowTotals['total'] ?? 0);
                $invoiceDocCount = (int) ($rowTotals['invoice_count'] ?? 0);
                $returnDocCount = (int) ($rowTotals['return_count'] ?? 0);
            }
        }
    }
}

$showAllCustomers = $showResult && $customerId === 0;
$reportTitle = 'تقرير المبيعات بين تاريخين';

$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
require_once app_path('includes/customer_picker.php');

$invCssPath = app_path('assets/css/sales-invoice.css');
$invCssUrl = app_url('assets/css/sales-invoice.css') . (is_file($invCssPath) ? '?v=' . (string) filemtime($invCssPath) : '');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');
$sortJsPath = app_path('assets/js/report-sales-table-sort.js');
$sortJsUrl = app_url('assets/js/report-sales-table-sort.js') . (is_file($sortJsPath) ? '?v=' . (string) filemtime($sortJsPath) : '');
$filterJsPath = app_path('assets/js/report-sales-item-filter.js');
$filterJsUrl = app_url('assets/js/report-sales-item-filter.js') . (is_file($filterJsPath) ? '?v=' . (string) filemtime($filterJsPath) : '');
$defaultSortKey = $showAllCustomers ? 'customer_name' : 'invoice_date';

$exportLabel = $customerLabel;
if ($salesRepLabel !== '' && $salesRepId > 0) {
    $exportLabel .= ' - ' . $salesRepLabel;
}

$pageDataAttrs = ' data-report-title="' . esc($reportTitle) . '"';
$pageDataAttrs .= ' data-report-route="report_sales_between_dates"';
if ($showAllCustomers) {
    $pageDataAttrs .= ' data-sales-all-customers="1"';
}
if ($showResult) {
    $pageDataAttrs .= ' data-export-label="' . esc($exportLabel) . '"';
    $pageDataAttrs .= ' data-from-dmy="' . esc(format_date_dmY($from)) . '"';
    $pageDataAttrs .= ' data-to-dmy="' . esc(format_date_dmY($to)) . '"';
}
?>

<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($invCssUrl) ?>">
<?php customer_picker_enqueue_assets(); ?>
<?php customer_picker_json_script($customers, 'report-sales-customers-json'); ?>

<div class="card report-sales-page"<?= $pageDataAttrs ?>>
    <?php if ($err !== ''): ?>
        <div class="alert alert-error no-print" style="margin-bottom:1rem;"><?= esc($err) ?></div>
    <?php endif; ?>

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters report-sales-filters--inline no-print">
        <input type="hidden" name="r" value="report_sales_between_dates">

        <div class="report-sales-filters-row">
            <?= customer_picker_field([
                'id' => 'report_sales_cust',
                'name' => 'customer_id',
                'value' => $customerId >= 0 ? $customerId : '',
                'label' => 'العميل *',
                'wrapper_class' => 'field report-sales-filter-field report-sales-filter-field--customer',
                'allow_all' => true,
                'compact' => true,
                'json_id' => 'report-sales-customers-json',
            ]) ?>

            <label class="field report-sales-filter-field report-sales-filter-field--rep">
                <span class="field-label">المندوب *</span>
                <select class="input" name="sales_rep_id" required>
                    <option value="0" <?= $salesRepId === 0 ? 'selected' : '' ?>>جميع المندوبين</option>
                    <?php foreach ($salesReps as $rep): ?>
                        <option value="<?= (int) $rep['id'] ?>" <?= $salesRepId === (int) $rep['id'] ? 'selected' : '' ?>>
                            <?= esc((string) ($rep['name_ar'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="field report-sales-filter-field report-sales-filter-field--date">
                <span class="field-label">من تاريخ *</span>
                <input class="input js-date-dmy" type="text" name="from" value="<?= esc(format_date_dmY($from)) ?>"
                       placeholder="يوم-شهر-سنة" dir="ltr" autocomplete="off" inputmode="numeric" required>
            </label>

            <label class="field report-sales-filter-field report-sales-filter-field--date">
                <span class="field-label">إلى تاريخ *</span>
                <input class="input js-date-dmy" type="text" name="to" value="<?= esc(format_date_dmY($to)) ?>"
                       placeholder="يوم-شهر-سنة" dir="ltr" autocomplete="off" inputmode="numeric" required>
            </label>

            <div class="field report-sales-filter-field report-sales-filter-field--submit">
                <span class="field-label" aria-hidden="true">&nbsp;</span>
                <button class="btn btn-primary" type="submit">عرض التقرير</button>
            </div>
        </div>
    </form>

    <?php if ($showResult): ?>
        <div class="report-sales-result report-sales-print-area">
            <?= document_print_header_html($reportTitle, $pdo) ?>

            <div class="doc-print-meta">
                <table>
                    <tr>
                        <td><strong>العميل:</strong> <span class="doc-print-meta-value doc-print-meta-value--party"><?= esc($customerLabel) ?></span></td>
                    </tr>
                    <tr>
                        <td><strong>المندوب:</strong> <?= esc($salesRepLabel) ?></td>
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
                            <strong>عدد الفواتير:</strong> <?= (int) ($invoiceDocCount ?? 0) ?>
                            <?php if (($returnDocCount ?? 0) > 0): ?>
                                &nbsp;&nbsp;|&nbsp;&nbsp;
                                <strong>مرتجعات:</strong> <?= (int) $returnDocCount ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="report-sales-item-filter no-print" aria-label="بحث عن مادة في نتائج التقرير">
                <label class="report-sales-item-filter-field">
                    <span class="field-label">بحث عن مادة</span>
                    <div class="report-sales-item-filter-row">
                        <input type="search" class="input js-report-item-filter-inp"
                               placeholder="اسم المادة، SKU، أو Barcode…"
                               autocomplete="off" spellcheck="false" dir="rtl">
                        <button type="button" class="btn btn-ghost btn-sm js-report-item-filter-clear">مسح</button>
                    </div>
                </label>
                <p class="report-sales-item-filter-hint js-report-item-filter-hint" hidden></p>
            </div>

            <div class="report-sales-table-wrap">
                <table class="report-sales-table js-sortable-report<?= $showAllCustomers ? ' report-sales-table--all-customers' : '' ?>"
                       data-default-sort="<?= esc($defaultSortKey) ?>"
                       data-default-dir="asc">
                    <colgroup>
                        <col class="col-seq">
                        <col class="col-inv-no">
                        <?php if ($showAllCustomers): ?>
                        <col class="col-customer">
                        <?php endif; ?>
                        <col class="col-rep">
                        <col class="col-date">
                        <col class="col-pay">
                        <col class="col-posted">
                        <col class="col-money">
                        <col class="col-money">
                    </colgroup>

                    <thead>
                    <tr>
                        <th class="col-seq js-sort-th" data-sort="seq" data-sort-type="number" title="ترتيب حسب الرقم">#</th>
                        <th class="col-inv-no js-sort-th" data-sort="invoice_no" data-sort-type="text" title="ترتيب حسب رقم الفاتورة">رقم الفاتورة</th>
                        <?php if ($showAllCustomers): ?>
                        <th class="col-customer js-sort-th" data-sort="customer_name" data-sort-type="text" title="ترتيب حسب اسم العميل">اسم العميل</th>
                        <?php endif; ?>
                        <th class="col-rep js-sort-th" data-sort="sales_rep_name" data-sort-type="text" title="ترتيب حسب المندوب">المندوب</th>
                        <th class="col-date js-sort-th" data-sort="invoice_date" data-sort-type="date" title="ترتيب حسب التاريخ">تاريخ الفاتورة</th>
                        <th class="col-pay js-sort-th" data-sort="payment_label" data-sort-type="text" title="ترتيب حسب نوع الفاتورة">نوع الفاتورة</th>
                        <th class="col-posted js-sort-th" data-sort="posted_label" data-sort-type="text" title="ترتيب حسب الترحيل">الترحيل</th>
                        <th class="col-money js-sort-th" data-sort="subtotal" data-sort-type="number" title="ترتيب حسب فاتورة غ ش">فاتورة غ ش</th>
                        <th class="col-money js-sort-th" data-sort="total" data-sort-type="number" title="ترتيب حسب فاتورة ش">فاتورة ش</th>
                    </tr>
                    </thead>

                    <tbody>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="<?= $showAllCustomers ? 8 : 7 ?>" class="muted" style="text-align:center;padding:1.25rem;">
                                لا توجد فواتير مبيعات مطابقة في الفترة المحددة.
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php
                    $seq = 0;
                    foreach ($rows as $r):
                        $seq += 1;
                        $isReturn = ($r['doc_kind'] ?? '') === 'return';
                        $invUrl = $isReturn
                            ? app_url('index.php?r=sales_returns&id=' . (int) $r['id'])
                            : app_url('index.php?r=sales_invoices&id=' . (int) $r['id']);
                    ?>
                        <tr data-sort-row="1"<?= $isReturn ? ' class="report-sales-row--return"' : '' ?>
                            data-sort-seq="<?= $seq ?>"
                            data-sort-invoice_no="<?= esc((string) $r['invoice_no']) ?>"
                            <?php if ($showAllCustomers): ?>
                            data-sort-customer_name="<?= esc((string) ($r['customer_name'] ?? '')) ?>"
                            <?php endif; ?>
                            data-sort-sales_rep_name="<?= esc((string) ($r['sales_rep_name'] ?? '')) ?>"
                            data-sort-invoice_date="<?= esc((string) ($r['invoice_date'] ?? '')) ?>"
                            data-sort-payment_label="<?= esc((string) $r['payment_label']) ?>"
                            data-sort-posted_label="<?= esc((string) $r['posted_label']) ?>"
                            data-sort-total="<?= esc((string) (float) ($r['total'] ?? 0)) ?>"
                            data-sort-subtotal="<?= esc((string) (float) ($r['subtotal'] ?? 0)) ?>"
                            data-filter-items="<?= esc((string) ($r['items_search_text'] ?? '')) ?>">
                            <td class="col-seq"><?= $seq ?></td>
                            <td class="col-inv-no">
                                <code><?= esc((string) $r['invoice_no']) ?></code>
                                <a class="btn btn-ghost btn-sm no-print" style="padding:0 0.35rem;font-size:0.75rem;"
                                   href="<?= esc($invUrl) ?>">عرض</a>
                            </td>
                            <?php if ($showAllCustomers): ?>
                            <td class="col-customer"><span class="report-sales-party-name"><?= esc((string) ($r['customer_name'] ?? '')) ?></span></td>
                            <?php endif; ?>
                            <td class="col-rep"><?= esc((string) ($r['sales_rep_name'] ?? '')) ?></td>
                            <td class="col-date" dir="ltr"><?= esc(format_date_dmY((string) ($r['invoice_date'] ?? ''))) ?></td>
                            <td class="col-pay"><?= esc((string) $r['payment_label']) ?></td>
                            <td class="col-posted"><?= esc((string) $r['posted_label']) ?></td>
                            <td class="col-money"><?= esc(format_money((float) $r['subtotal'])) ?></td>
                            <td class="col-money"><?= esc(format_money((float) $r['total'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($rows): ?>
            <div class="report-sales-table-wrap report-sales-grand-total-wrap">
                <table class="report-sales-table report-sales-grand-total-table<?= $showAllCustomers ? ' report-sales-table--all-customers' : '' ?>">
                    <colgroup>
                        <col class="col-seq">
                        <col class="col-inv-no">
                        <?php if ($showAllCustomers): ?>
                        <col class="col-customer">
                        <?php endif; ?>
                        <col class="col-rep">
                        <col class="col-date">
                        <col class="col-pay">
                        <col class="col-posted">
                        <col class="col-money">
                        <col class="col-money">
                    </colgroup>
                    <tbody>
                    <tr class="report-sales-grand-total-row">
                        <td colspan="<?= $showAllCustomers ? 7 : 6 ?>">الإجمالي</td>
                        <td class="col-money"><?= esc(format_money($sumSub)) ?></td>
                        <td class="col-money"><?= esc(format_money($sumTotal)) ?></td>
                    </tr>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<div id="sales-inv-export-host" class="sales-inv-export-host" aria-hidden="true"></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" defer crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<?php if ($showResult && $rows): ?>
<script src="<?= esc($sortJsUrl) ?>" defer></script>
<script src="<?= esc($filterJsUrl) ?>" defer></script>
<?php endif; ?>
<script src="<?= esc($exportJsUrl) ?>"></script>
