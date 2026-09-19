<?php
declare(strict_types=1);

require_once app_path('includes/pur_purchase_orders_report.php');
require_once app_path('includes/pur_order_schema.php');
require_once app_path('includes/document_header.php');
require_once app_path('includes/supplier_picker.php');

$pdo = db();
$suppliers = crm_suppliers_for_picker($pdo);

$supplierId = isset($_GET['supplier_id']) && $_GET['supplier_id'] !== '' ? (int) $_GET['supplier_id'] : -1;
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
$supplierLabel = '';
$sumTotal = 0.0;
$showResult = false;
$err = '';
$submitted = isset($_GET['supplier_id']) && $_GET['supplier_id'] !== '';

if ($submitted) {
    if ($supplierId < 0) {
        $err = 'اختر المورد أو «جميع الموردين».';
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
            $supplierLabel = $supplierId === 0 ? 'جميع الموردين' : '';
            if ($supplierId > 0) {
                $st = $pdo->prepare('SELECT name_ar FROM crm_supplier WHERE id = ? LIMIT 1');
                $st->execute([$supplierId]);
                $sup = $st->fetch(PDO::FETCH_ASSOC);
                if (!$sup) {
                    $err = 'المورد غير موجود.';
                } else {
                    $supplierLabel = (string) ($sup['name_ar'] ?? '');
                }
            }
            if ($err === '') {
                $showResult = true;
                $rows = pur_report_purchase_orders($pdo, $supplierId, $from, $to, $statusFilter);
                foreach ($rows as $r) {
                    $sumTotal += (float) ($r['total'] ?? 0);
                }
            }
        }
    }
}

$statusFilterLabel = match ($statusFilter) {
    'open' => 'مفتوحة',
    'approved' => 'معتمدة',
    'all' => 'الكل',
    default => in_array($statusFilter, pur_order_valid_statuses(), true)
        ? pur_order_status_label($statusFilter)
        : 'الكل',
};

$showAllSuppliers = $showResult && $supplierId === 0;
$reportTitle = 'تقرير طلبات الشراء';

$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$invCssPath = app_path('assets/css/sales-invoice.css');
$invCssUrl = app_url('assets/css/sales-invoice.css') . (is_file($invCssPath) ? '?v=' . (string) filemtime($invCssPath) : '');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');
$sortJsPath = app_path('assets/js/report-sales-table-sort.js');
$sortJsUrl = app_url('assets/js/report-sales-table-sort.js') . (is_file($sortJsPath) ? '?v=' . (string) filemtime($sortJsPath) : '');
$defaultSortKey = $showAllSuppliers ? 'supplier_name' : 'order_date';

$pageDataAttrs = ' data-report-title="' . esc($reportTitle) . '"';
$pageDataAttrs .= ' data-report-route="report_purchase_orders"';
if ($showAllSuppliers) {
    $pageDataAttrs .= ' data-po-all-suppliers="1"';
}
if ($showResult) {
    $pageDataAttrs .= ' data-export-label="' . esc($supplierLabel) . '"';
    $pageDataAttrs .= ' data-from-dmy="' . esc(format_date_dmY($from)) . '"';
    $pageDataAttrs .= ' data-to-dmy="' . esc(format_date_dmY($to)) . '"';
}
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($invCssUrl) ?>">
<?php supplier_picker_enqueue_assets(); ?>
<?php supplier_picker_json_script($suppliers, 'report-po-suppliers-json'); ?>

<div class="card report-sales-page"<?= $pageDataAttrs ?>>

    <?php if ($err !== ''): ?>
        <div class="alert alert-error no-print" style="margin-bottom:1rem;"><?= esc($err) ?></div>
    <?php endif; ?>

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print">
        <input type="hidden" name="r" value="report_purchase_orders">
        <div class="form-row">
            <?= supplier_picker_field([
                'id' => 'report_po_supp',
                'name' => 'supplier_id',
                'value' => $supplierId >= 0 ? $supplierId : '',
                'label' => 'المورد *',
                'wrapper_class' => 'field',
                'wrapper_style' => 'flex:1 1 16rem',
                'allow_all' => true,
                'json_id' => 'report-po-suppliers-json',
            ]) ?>
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
                <span class="field-label">الحالة</span>
                <select class="input" name="status">
                    <option value="all"<?= $statusFilter === 'all' ? ' selected' : '' ?>>الكل</option>
                    <option value="open"<?= $statusFilter === 'open' ? ' selected' : '' ?>>مفتوحة</option>
                    <option value="approved"<?= $statusFilter === 'approved' ? ' selected' : '' ?>>معتمدة</option>
                    <?php foreach (pur_order_valid_statuses() as $st): ?>
                        <option value="<?= esc($st) ?>"<?= $statusFilter === $st ? ' selected' : '' ?>><?= esc(pur_order_status_label($st)) ?></option>
                    <?php endforeach; ?>
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
                        <td><strong>المورد:</strong> <span class="doc-print-meta-value doc-print-meta-value--party"><?= esc($supplierLabel) ?></span></td>
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
                            <strong>عدد الطلبات:</strong> <?= count($rows) ?>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="report-sales-table-wrap">
                <table class="report-sales-table js-sortable-report<?= $showAllSuppliers ? ' report-sales-table--all-suppliers' : '' ?>"
                       data-default-sort="<?= esc($defaultSortKey) ?>"
                       data-default-dir="asc">
                    <colgroup>
                        <col class="col-seq">
                        <col class="col-inv-no">
                        <?php if ($showAllSuppliers): ?>
                        <col class="col-customer">
                        <?php endif; ?>
                        <col class="col-date">
                        <col class="col-date">
                        <col class="col-pay">
                        <col class="col-posted">
                        <col class="col-inv-no">
                        <col class="col-money">
                    </colgroup>
                    <thead>
                    <tr>
                        <th class="col-seq js-sort-th" data-sort="seq" data-sort-type="number" title="ترتيب">#</th>
                        <th class="col-inv-no js-sort-th" data-sort="order_no" data-sort-type="text" title="ترتيب حسب رقم الطلب">رقم الطلب</th>
                        <?php if ($showAllSuppliers): ?>
                        <th class="col-customer js-sort-th" data-sort="supplier_name" data-sort-type="text" title="ترتيب حسب المورد">المورد</th>
                        <?php endif; ?>
                        <th class="col-date js-sort-th" data-sort="order_date" data-sort-type="date" title="ترتيب حسب التاريخ">تاريخ الطلب</th>
                        <th class="col-date js-sort-th" data-sort="expected_date" data-sort-type="date" title="ترتيب حسب التسليم">التسليم المتوقع</th>
                        <th class="col-pay js-sort-th" data-sort="payment_label" data-sort-type="text" title="ترتيب حسب الدفع">الدفع</th>
                        <th class="col-posted js-sort-th" data-sort="status_label" data-sort-type="text" title="ترتيب حسب الحالة">الحالة</th>
                        <th class="col-inv-no js-sort-th" data-sort="reference_no" data-sort-type="text" title="ترتيب حسب المرجع">المرجع</th>
                        <th class="col-money js-sort-th" data-sort="total" data-sort-type="number" title="ترتيب حسب الإجمالي">الإجمالي</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="<?= $showAllSuppliers ? 9 : 8 ?>" class="muted" style="text-align:center;padding:1.25rem;">
                                لا توجد طلبات شراء مطابقة في الفترة المحددة.
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php
                    $seq = 0;
                    foreach ($rows as $r):
                        $seq += 1;
                        $poUrl = app_url('index.php?r=purchase_orders&id=' . (int) $r['id']);
                        ?>
                        <tr data-sort-row="1"
                            data-sort-seq="<?= $seq ?>"
                            data-sort-order_no="<?= esc((string) $r['order_no']) ?>"
                            <?php if ($showAllSuppliers): ?>
                            data-sort-supplier_name="<?= esc((string) ($r['supplier_name'] ?? '')) ?>"
                            <?php endif; ?>
                            data-sort-order_date="<?= esc((string) ($r['order_date'] ?? '')) ?>"
                            data-sort-expected_date="<?= esc((string) ($r['expected_date'] ?? '')) ?>"
                            data-sort-payment_label="<?= esc((string) ($r['payment_label'] ?? '')) ?>"
                            data-sort-status_label="<?= esc((string) ($r['status_label'] ?? '')) ?>"
                            data-sort-reference_no="<?= esc((string) ($r['reference_no'] ?? '')) ?>"
                            data-sort-total="<?= esc((string) (float) ($r['total'] ?? 0)) ?>">
                            <td class="col-seq"><?= $seq ?></td>
                            <td class="col-inv-no">
                                <code><?= esc((string) $r['order_no']) ?></code>
                                <a class="btn btn-ghost btn-sm no-print" style="padding:0 0.35rem;font-size:0.75rem;"
                                   href="<?= esc($poUrl) ?>">عرض</a>
                            </td>
                            <?php if ($showAllSuppliers): ?>
                            <td class="col-customer"><span class="report-sales-party-name"><?= esc((string) ($r['supplier_name'] ?? '')) ?></span></td>
                            <?php endif; ?>
                            <td class="col-date" dir="ltr"><?= esc(format_date_dmY((string) ($r['order_date'] ?? ''))) ?></td>
                            <td class="col-date" dir="ltr"><?= ($r['expected_date'] ?? '') !== '' ? esc(format_date_dmY((string) $r['expected_date'])) : '—' ?></td>
                            <td class="col-pay"><?= esc((string) ($r['payment_label'] ?? '')) ?></td>
                            <td class="col-posted"><?= esc((string) ($r['status_label'] ?? '')) ?></td>
                            <td><?= esc((string) ($r['reference_no'] ?? '')) !== '' ? esc((string) $r['reference_no']) : '—' ?></td>
                            <td class="col-money"><?= esc(format_money((float) $r['total'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php if ($rows): ?>
                    <tfoot>
                    <tr>
                        <td colspan="<?= $showAllSuppliers ? 8 : 7 ?>">الإجمالي</td>
                        <td class="col-money"><?= esc(format_money($sumTotal)) ?></td>
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
