<?php
declare(strict_types=1);

require_once app_path('includes/sal_sales_by_customer_report.php');
require_once app_path('includes/document_header.php');

$pdo = db();

$customers = $pdo->query(
    'SELECT id, code, name_ar FROM crm_customer WHERE is_active = 1 ORDER BY name_ar'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$customerId = isset($_GET['customer_id']) && $_GET['customer_id'] !== ''
    ? (int) $_GET['customer_id']
    : -1;

$monthNames = [
    1 => 'يناير',
    2 => 'فبراير',
    3 => 'مارس',
    4 => 'أبريل',
    5 => 'مايو',
    6 => 'يونيو',
    7 => 'يوليو',
    8 => 'أغسطس',
    9 => 'سبتمبر',
    10 => 'أكتوبر',
    11 => 'نوفمبر',
    12 => 'ديسمبر',
];

$currentYear = (int) date('Y');
$currentMonth = (int) date('n');
$year = isset($_GET['year']) ? (int) $_GET['year'] : $currentYear;
$month = isset($_GET['month']) ? (int) $_GET['month'] : $currentMonth; // 0 = كل الأشهر

$periodFromTo = static function (int $yr, int $mon): array {
    if ($mon === 0) {
        return [
            sprintf('%04d-01-01', $yr),
            sprintf('%04d-12-31', $yr),
        ];
    }
    $start = sprintf('%04d-%02d-01', $yr, $mon);

    return [
        $start,
        sprintf('%04d-%02d-%02d', $yr, $mon, (int) date('t', strtotime($start))),
    ];
};

$yearMin = $currentYear - 5;
$yearMax = $currentYear + 1;
try {
    $yearBounds = $pdo->query(
        "SELECT MIN(y) AS min_year, MAX(y) AS max_year
         FROM (
           SELECT YEAR(invoice_date) AS y FROM sal_invoice
           UNION ALL
           SELECT YEAR(return_date) AS y FROM sal_return
         ) t"
    )->fetch(PDO::FETCH_ASSOC) ?: [];

    $minYear = (int) ($yearBounds['min_year'] ?? 0);
    $maxYear = (int) ($yearBounds['max_year'] ?? 0);
    if ($minYear > 0 && $maxYear > 0) {
        $yearMin = min($yearMin, $minYear);
        $yearMax = max($yearMax, $maxYear);
    }
} catch (Throwable $e) {
    // fallback to default range
}

$yearOptions = [];
for ($y = $yearMax; $y >= $yearMin; $y--) {
    $yearOptions[] = $y;
}
if (!in_array($year, $yearOptions, true)) {
    $yearOptions[] = $year;
    rsort($yearOptions);
}

$rows = [];
$customerLabel = '';
$sumSub = 0.0;
$sumTotal = 0.0;
$showResult = false;
$invoiceDocCount = 0;
$returnDocCount = 0;
$err = '';
$monthLabel = $month === 0 ? 'كل الأشهر' : ($monthNames[$month] ?? 'غير محدد');
[$from, $to] = $periodFromTo($year, ($month >= 0 && $month <= 12) ? $month : $currentMonth);

$submitted = isset($_GET['customer_id']) && $_GET['customer_id'] !== '';
if ($submitted) {
    if ($customerId < 0) {
        $err = 'اختر العميل أو «جميع العملاء».';
    } elseif ($year < 2000 || $year > 2100) {
        $err = 'السنة غير صالحة.';
    } elseif ($month < 0 || $month > 12) {
        $err = 'الشهر غير صالح.';
    } else {
        [$from, $to] = $periodFromTo($year, $month);
        $monthLabel = $month === 0 ? 'كل الأشهر' : ($monthNames[$month] ?? 'غير محدد');

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
            $rows = sal_report_sales_by_customer($pdo, $customerId, $from, $to);
            $rowTotals = sal_report_sales_rows_totals($rows);
            $sumSub = (float) ($rowTotals['subtotal'] ?? 0);
            $sumTotal = (float) ($rowTotals['total'] ?? 0);
            $invoiceDocCount = (int) ($rowTotals['invoice_count'] ?? 0);
            $returnDocCount = (int) ($rowTotals['return_count'] ?? 0);
        }
    }
}

$showAllCustomers = $showResult && $customerId === 0;

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

$reportTitle = 'تقرير المبيعات الشهري حسب العميل';

$pageDataAttrs = ' data-report-title="' . esc($reportTitle) . '"';
$pageDataAttrs .= ' data-report-route="report_sales"';
if ($showAllCustomers) {
    $pageDataAttrs .= ' data-sales-all-customers="1"';
}
if ($showResult) {
    $pageDataAttrs .= ' data-export-label="' . esc($customerLabel) . '"';
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

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print">
        <input type="hidden" name="r" value="report_sales">

        <div class="form-row">
            <?= customer_picker_field([
                'id' => 'report_sales_cust',
                'name' => 'customer_id',
                'value' => $customerId >= 0 ? $customerId : '',
                'label' => 'العميل *',
                'wrapper_class' => 'field',
                'wrapper_style' => 'flex:1 1 16rem',
                'allow_all' => true,
                'json_id' => 'report-sales-customers-json',
            ]) ?>

            <label class="field">
                <span class="field-label">السنة *</span>
                <select class="input" name="year" required>
                    <?php foreach ($yearOptions as $optYear): ?>
                        <option value="<?= (int) $optYear ?>" <?= $year === (int) $optYear ? 'selected' : '' ?>>
                            <?= (int) $optYear ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="field">
                <span class="field-label">الشهر *</span>
                <select class="input" name="month" required>
                    <option value="0" <?= $month === 0 ? 'selected' : '' ?>>كل الأشهر</option>
                    <?php foreach ($monthNames as $monthNo => $monthName): ?>
                        <option value="<?= (int) $monthNo ?>" <?= $month === (int) $monthNo ? 'selected' : '' ?>>
                            <?= esc($monthName) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <p class="field-hint" style="margin:0.5rem 0 0;">
            عند اختيار «كل الأشهر» يعرض التقرير كامل السنة المختارة.
        </p>

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
                        <td><strong>العميل:</strong> <span class="doc-print-meta-value doc-print-meta-value--party"><?= esc($customerLabel) ?></span></td>
                    </tr>
                    <tr>
                        <td>
                            <strong>السنة:</strong> <?= (int) $year ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>الشهر:</strong> <?= esc($monthLabel) ?>
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
                                لا توجد فواتير مبيعات مطابقة للفترة المحددة.
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
