<?php
declare(strict_types=1);

require_once app_path('includes/pur_purchase_orders_report.php');
require_once app_path('includes/document_header.php');
require_once app_path('includes/supplier_picker.php');

$pdo = db();
$suppliers = crm_suppliers_for_picker($pdo);

$supplierId = isset($_GET['supplier_id']) && $_GET['supplier_id'] !== '' ? (int) $_GET['supplier_id'] : -1;

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
            $rows = pur_report_purchase_orders_open($pdo, $supplierId);
            foreach ($rows as $r) {
                $sumTotal += (float) ($r['total'] ?? 0);
            }
        }
    }
}

$showAllSuppliers = $showResult && $supplierId === 0;
$reportTitle = 'تقرير طلبات الشراء المفتوحة';

$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$invCssPath = app_path('assets/css/sales-invoice.css');
$invCssUrl = app_url('assets/css/sales-invoice.css') . (is_file($invCssPath) ? '?v=' . (string) filemtime($invCssPath) : '');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');
$sortJsPath = app_path('assets/js/report-sales-table-sort.js');
$sortJsUrl = app_url('assets/js/report-sales-table-sort.js') . (is_file($sortJsPath) ? '?v=' . (string) filemtime($sortJsPath) : '');
$defaultSortKey = $showAllSuppliers ? 'supplier_name' : 'expected_date';

$pageDataAttrs = ' data-report-title="' . esc($reportTitle) . '"';
$pageDataAttrs .= ' data-report-route="report_purchase_orders_open"';
if ($showResult) {
    $pageDataAttrs .= ' data-export-label="' . esc($supplierLabel) . '"';
}
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($invCssUrl) ?>">
<?php supplier_picker_enqueue_assets(); ?>
<?php supplier_picker_json_script($suppliers, 'report-po-open-suppliers-json'); ?>

<div class="card report-sales-page"<?= $pageDataAttrs ?>>

    <?php if ($err !== ''): ?>
        <div class="alert alert-error no-print" style="margin-bottom:1rem;"><?= esc($err) ?></div>
    <?php endif; ?>

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print">
        <input type="hidden" name="r" value="report_purchase_orders_open">
        <div class="form-row">
            <?= supplier_picker_field([
                'id' => 'report_po_open_supp',
                'name' => 'supplier_id',
                'value' => $supplierId >= 0 ? $supplierId : '',
                'label' => 'المورد *',
                'wrapper_class' => 'field',
                'wrapper_style' => 'flex:1 1 16rem',
                'allow_all' => true,
                'json_id' => 'report-po-open-suppliers-json',
            ]) ?>
        </div>
        <p class="muted no-print" style="margin:0.35rem 0 0;font-size:0.78rem;">طلبات بانتظار الاعتماد أو معتمدة ولم تُنفَّذ بالكامل.</p>
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
                        <td><strong>عدد الطلبات المفتوحة:</strong> <?= count($rows) ?></td>
                    </tr>
                </table>
            </div>

            <div class="report-sales-table-wrap">
                <table class="report-sales-table js-sortable-report"
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
                        <col class="col-posted">
                        <col class="col-money">
                        <col class="col-money">
                    </colgroup>
                    <thead>
                    <tr>
                        <th class="col-seq js-sort-th" data-sort="seq" data-sort-type="number">#</th>
                        <th class="col-inv-no js-sort-th" data-sort="order_no" data-sort-type="text">رقم الطلب</th>
                        <?php if ($showAllSuppliers): ?>
                        <th class="col-customer js-sort-th" data-sort="supplier_name" data-sort-type="text">المورد</th>
                        <?php endif; ?>
                        <th class="col-date js-sort-th" data-sort="order_date" data-sort-type="date">تاريخ الطلب</th>
                        <th class="col-date js-sort-th" data-sort="expected_date" data-sort-type="date">التسليم المتوقع</th>
                        <th class="col-posted js-sort-th" data-sort="status_label" data-sort-type="text">الحالة</th>
                        <th class="col-money js-sort-th" data-sort="qty_remaining" data-sort-type="number">كمية متبقية</th>
                        <th class="col-money js-sort-th" data-sort="total" data-sort-type="number">الإجمالي</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="<?= $showAllSuppliers ? 8 : 7 ?>" class="muted" style="text-align:center;padding:1.25rem;">
                                لا توجد طلبات مفتوحة مطابقة.
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
                            data-sort-status_label="<?= esc((string) ($r['status_label'] ?? '')) ?>"
                            data-sort-qty_remaining="<?= esc((string) (float) ($r['qty_remaining'] ?? 0)) ?>"
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
                            <td class="col-posted"><?= esc((string) ($r['status_label'] ?? '')) ?></td>
                            <td class="col-money"><?= esc(format_money((float) $r['qty_remaining'])) ?></td>
                            <td class="col-money"><?= esc(format_money((float) $r['total'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php if ($rows): ?>
                    <tfoot>
                    <tr>
                        <td colspan="<?= $showAllSuppliers ? 7 : 6 ?>">مجموع قيمة الطلبات المفتوحة</td>
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
