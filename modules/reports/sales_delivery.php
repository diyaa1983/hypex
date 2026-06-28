<?php
declare(strict_types=1);

require_once app_path('includes/sal_delivery_report.php');
require_once app_path('includes/document_header.php');
require_once app_path('includes/customer_picker.php');

$pdo = db();

$customers = $pdo->query(
    'SELECT id, code, name_ar FROM crm_customer WHERE is_active = 1 ORDER BY name_ar'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$customerId = isset($_GET['customer_id']) && $_GET['customer_id'] !== ''
    ? (int) $_GET['customer_id']
    : -1;
$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? 'all'));

if ($from === '') {
    $from = app_default_date_from();
}
if ($to === '') {
    $to = app_default_date_to();
}

$rows = [];
$customerLabel = '';
$showResult = false;
$err = '';
$sumLineCount = 0;
$sumQty = 0.0;

$submitted = isset($_GET['customer_id']) && $_GET['customer_id'] !== '';

if ($submitted) {
    if ($customerId < 0) {
        $err = 'اختر العميل أو «جميع العملاء».';
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
                $rows = sal_report_deliveries($pdo, $customerId, $from, $to, $statusFilter);
                foreach ($rows as $r) {
                    $sumLineCount += (int) ($r['line_count'] ?? 0);
                    $sumQty += (float) ($r['total_qty'] ?? 0);
                }
            }
        }
    }
}

$statusFilterLabel = sal_delivery_report_status_filter_label($statusFilter);
$showAllCustomers = $showResult && $customerId === 0;
$reportTitle = 'تقرير سندات البضاعة';

$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$invCssPath = app_path('assets/css/sales-invoice.css');
$invCssUrl = app_url('assets/css/sales-invoice.css') . (is_file($invCssPath) ? '?v=' . (string) filemtime($invCssPath) : '');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');
$sortJsPath = app_path('assets/js/report-sales-table-sort.js');
$sortJsUrl = app_url('assets/js/report-sales-table-sort.js') . (is_file($sortJsPath) ? '?v=' . (string) filemtime($sortJsPath) : '');
$defaultSortKey = $showAllCustomers ? 'customer_name' : 'delivery_date';

$pageDataAttrs = ' data-report-title="' . esc($reportTitle) . '"';
$pageDataAttrs .= ' data-report-route="report_sales_delivery"';
if ($showAllCustomers) {
    $pageDataAttrs .= ' data-delivery-all-customers="1"';
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
<?php customer_picker_json_script($customers, 'report-sales-delivery-customers-json'); ?>

<div class="card report-sales-page"<?= $pageDataAttrs ?>>

    <?php if ($err !== ''): ?>
        <div class="alert alert-error no-print" style="margin-bottom:1rem;"><?= esc($err) ?></div>
    <?php endif; ?>

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters report-sales-filters--inline no-print">
        <input type="hidden" name="r" value="report_sales_delivery">

        <div class="report-sales-filters-row">
            <?= customer_picker_field([
                'id' => 'report_sales_delivery_cust',
                'name' => 'customer_id',
                'value' => $customerId >= 0 ? $customerId : '',
                'label' => 'العميل *',
                'wrapper_class' => 'field report-sales-filter-field report-sales-filter-field--customer',
                'allow_all' => true,
                'compact' => true,
                'json_id' => 'report-sales-delivery-customers-json',
            ]) ?>

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

            <label class="field report-sales-filter-field report-sales-filter-field--rep">
                <span class="field-label">الحالة</span>
                <select class="input" name="status">
                    <option value="all"<?= $statusFilter === 'all' ? ' selected' : '' ?>>الكل</option>
                    <option value="posted"<?= $statusFilter === 'posted' ? ' selected' : '' ?>>مرحّل</option>
                    <option value="unposted"<?= $statusFilter === 'unposted' ? ' selected' : '' ?>>غير مرحّل</option>
                    <option value="draft"<?= $statusFilter === 'draft' ? ' selected' : '' ?>>مسودة</option>
                    <option value="cancelled"<?= $statusFilter === 'cancelled' ? ' selected' : '' ?>>ملغى</option>
                </select>
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
                        <td>
                            <strong>من تاريخ:</strong> <?= esc(format_date_dmY($from)) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>إلى تاريخ:</strong> <?= esc(format_date_dmY($to)) ?>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <strong>الحالة:</strong> <?= esc($statusFilterLabel) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>عدد السندات:</strong> <?= count($rows) ?>
                        </td>
                    </tr>
                </table>
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
                        <col class="col-date">
                        <col class="col-customer">
                        <col class="col-posted">
                        <col class="col-seq">
                        <col class="col-money">
                        <col class="col-inv-no">
                        <col class="col-customer">
                    </colgroup>
                    <thead>
                    <tr>
                        <th class="col-seq js-sort-th" data-sort="seq" data-sort-type="number" title="ترتيب">#</th>
                        <th class="col-inv-no js-sort-th" data-sort="delivery_no" data-sort-type="text" title="ترتيب حسب رقم السند">رقم السند</th>
                        <?php if ($showAllCustomers): ?>
                        <th class="col-customer js-sort-th" data-sort="customer_name" data-sort-type="text" title="ترتيب حسب العميل">العميل</th>
                        <?php endif; ?>
                        <th class="col-date js-sort-th" data-sort="delivery_date" data-sort-type="date" title="ترتيب حسب التاريخ">التاريخ</th>
                        <th class="col-customer js-sort-th" data-sort="warehouse_name" data-sort-type="text" title="ترتيب حسب المستودع">المستودع</th>
                        <th class="col-posted js-sort-th" data-sort="status_label" data-sort-type="text" title="ترتيب حسب الحالة">الحالة</th>
                        <th class="col-seq js-sort-th" data-sort="line_count" data-sort-type="number" title="ترتيب حسب البنود">البنود</th>
                        <th class="col-money js-sort-th" data-sort="total_qty" data-sort-type="number" title="ترتيب حسب الكمية">الكمية</th>
                        <th class="col-inv-no js-sort-th" data-sort="linked_invoice_no" data-sort-type="text" title="ترتيب حسب الفاتورة">فاتورة مرتبطة</th>
                        <th class="col-customer js-sort-th" data-sort="notes" data-sort-type="text" title="ترتيب حسب الملاحظات">ملاحظات</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="<?= $showAllCustomers ? 10 : 9 ?>" class="muted" style="text-align:center;padding:1.25rem;">
                                لا توجد سندات بضاعة مطابقة في الفترة المحددة.
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php
                    $seq = 0;
                    foreach ($rows as $r):
                        $seq += 1;
                        $dlvUrl = app_url('index.php?r=sales_delivery&id=' . (int) $r['id']);
                        $invUrl = (int) ($r['linked_invoice_id'] ?? 0) > 0
                            ? app_url('index.php?r=sales_invoices&id=' . (int) $r['linked_invoice_id'])
                            : '';
                        ?>
                        <tr data-sort-row="1"
                            data-sort-seq="<?= $seq ?>"
                            data-sort-delivery_no="<?= esc((string) $r['delivery_no']) ?>"
                            <?php if ($showAllCustomers): ?>
                            data-sort-customer_name="<?= esc((string) ($r['customer_name'] ?? '')) ?>"
                            <?php endif; ?>
                            data-sort-delivery_date="<?= esc((string) ($r['delivery_date'] ?? '')) ?>"
                            data-sort-warehouse_name="<?= esc((string) ($r['warehouse_name'] ?? '')) ?>"
                            data-sort-status_label="<?= esc((string) ($r['status_label'] ?? '')) ?>"
                            data-sort-line_count="<?= esc((string) (int) ($r['line_count'] ?? 0)) ?>"
                            data-sort-total_qty="<?= esc((string) (float) ($r['total_qty'] ?? 0)) ?>"
                            data-sort-linked_invoice_no="<?= esc((string) ($r['linked_invoice_no'] ?? '')) ?>"
                            data-sort-notes="<?= esc((string) ($r['notes'] ?? '')) ?>">
                            <td class="col-seq"><?= $seq ?></td>
                            <td class="col-inv-no">
                                <code><?= esc((string) $r['delivery_no']) ?></code>
                                <a class="btn btn-ghost btn-sm no-print" style="padding:0 0.35rem;font-size:0.75rem;"
                                   href="<?= esc($dlvUrl) ?>">عرض</a>
                            </td>
                            <?php if ($showAllCustomers): ?>
                            <td class="col-customer"><span class="report-sales-party-name"><?= esc((string) ($r['customer_name'] ?? '')) ?></span></td>
                            <?php endif; ?>
                            <td class="col-date" dir="ltr"><?= esc(format_date_dmY((string) ($r['delivery_date'] ?? ''))) ?></td>
                            <td><?= ($r['warehouse_name'] ?? '') !== '' ? esc((string) $r['warehouse_name']) : '—' ?></td>
                            <td class="col-posted"><?= esc((string) ($r['status_label'] ?? '')) ?></td>
                            <td class="col-seq"><?= (int) ($r['line_count'] ?? 0) ?></td>
                            <td class="col-money"><?= esc(format_amount((float) ($r['total_qty'] ?? 0), 3)) ?></td>
                            <td class="col-inv-no">
                                <?php if ($invUrl !== ''): ?>
                                    <code><?= esc((string) $r['linked_invoice_no']) ?></code>
                                    <a class="btn btn-ghost btn-sm no-print" style="padding:0 0.35rem;font-size:0.75rem;"
                                       href="<?= esc($invUrl) ?>">عرض</a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td><?= ($r['notes'] ?? '') !== '' ? esc((string) $r['notes']) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php if ($rows): ?>
                    <tfoot>
                    <tr>
                        <td colspan="<?= $showAllCustomers ? 6 : 5 ?>">الإجمالي</td>
                        <td class="col-seq"><?= $sumLineCount ?></td>
                        <td class="col-money"><?= esc(format_amount($sumQty, 3)) ?></td>
                        <td colspan="2"></td>
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
