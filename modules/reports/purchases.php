<?php
declare(strict_types=1);

require_once app_path('includes/pur_purchases_by_supplier_report.php');
require_once app_path('includes/document_header.php');
require_once app_path('includes/supplier_picker.php');

$pdo = db();

$suppliers = crm_suppliers_for_picker($pdo);

$supplierId = isset($_GET['supplier_id']) && $_GET['supplier_id'] !== ''
    ? (int) $_GET['supplier_id']
    : -1;
$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));

if ($from === '') {
    $from = app_default_date_from();
}
if ($to === '') {
    $to = app_default_date_to();
}

$rows = [];
$supplierLabel = '';
$sumSub = 0.0;
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

            if ($supplierId === 0) {
                $supplierLabel = 'جميع الموردين';
                $showResult = true;
            } else {
                $st = $pdo->prepare('SELECT name_ar, code FROM crm_supplier WHERE id = ? LIMIT 1');
                $st->execute([$supplierId]);
                $sup = $st->fetch(PDO::FETCH_ASSOC);

                if (!$sup) {
                    $err = 'المورد غير موجود.';
                } else {
                    $showResult = true;
                    $supplierLabel = (string) ($sup['name_ar'] ?? '');
                }
            }

            if ($showResult) {
                $rows = pur_report_purchases_by_supplier($pdo, $supplierId, $from, $to);
                foreach ($rows as $r) {
                    $sumSub += (float) ($r['subtotal'] ?? 0);
                    $sumTotal += (float) ($r['total'] ?? 0);
                }
            }
        }
    }
}

$showAllSuppliers = $showResult && $supplierId === 0;

$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$invCssPath = app_path('assets/css/sales-invoice.css');
$invCssUrl = app_url('assets/css/sales-invoice.css') . (is_file($invCssPath) ? '?v=' . (string) filemtime($invCssPath) : '');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');
$sortJsPath = app_path('assets/js/report-sales-table-sort.js');
$sortJsUrl = app_url('assets/js/report-sales-table-sort.js') . (is_file($sortJsPath) ? '?v=' . (string) filemtime($sortJsPath) : '');
$defaultSortKey = $showAllSuppliers ? 'supplier_name' : 'invoice_date';

$reportTitle = 'تقرير المشتريات بين تاريخين';

$pageDataAttrs = ' data-report-title="' . esc($reportTitle) . '"';
$pageDataAttrs .= ' data-report-route="report_purchases"';
if ($showAllSuppliers) {
    $pageDataAttrs .= ' data-purchases-all-suppliers="1"';
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
<?php supplier_picker_json_script($suppliers, 'report-purchases-suppliers-json'); ?>

<div class="card report-sales-page"<?= $pageDataAttrs ?>>

    <?php if ($err !== ''): ?>
        <div class="alert alert-error no-print" style="margin-bottom:1rem;"><?= esc($err) ?></div>
    <?php endif; ?>

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print">
        <input type="hidden" name="r" value="report_purchases">
        <div class="form-row">
            <?= supplier_picker_field([
                'id' => 'report_purchases_supp',
                'name' => 'supplier_id',
                'value' => $supplierId >= 0 ? $supplierId : '',
                'label' => 'المورد *',
                'wrapper_class' => 'field',
                'wrapper_style' => 'flex:1 1 16rem',
                'allow_all' => true,
                'json_id' => 'report-purchases-suppliers-json',
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
                        <td><strong>عدد الفواتير:</strong> <?= count($rows) ?></td>
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
                        <col class="col-pay">
                        <col class="col-posted">
                        <col class="col-money">
                        <col class="col-money">
                    </colgroup>
                    <thead>
                    <tr>
                        <th class="col-seq js-sort-th" data-sort="seq" data-sort-type="number" title="ترتيب حسب الرقم">#</th>
                        <th class="col-inv-no js-sort-th" data-sort="invoice_no" data-sort-type="text" title="ترتيب حسب رقم الفاتورة">رقم الفاتورة</th>
                        <?php if ($showAllSuppliers): ?>
                        <th class="col-customer js-sort-th" data-sort="supplier_name" data-sort-type="text" title="ترتيب حسب اسم المورد">اسم المورد</th>
                        <?php endif; ?>
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
                            <td colspan="<?= $showAllSuppliers ? 8 : 7 ?>" class="muted" style="text-align:center;padding:1.25rem;">
                                لا توجد فواتير مشتريات مطابقة في الفترة المحددة.
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php
                    $seq = 0;
                    foreach ($rows as $r):
                        $seq += 1;
                        $invUrl = app_url('index.php?r=purchase_invoices&id=' . (int) $r['id']);
                        ?>
                        <tr data-sort-row="1"
                            data-sort-seq="<?= $seq ?>"
                            data-sort-invoice_no="<?= esc((string) $r['invoice_no']) ?>"
                            <?php if ($showAllSuppliers): ?>
                            data-sort-supplier_name="<?= esc((string) ($r['supplier_name'] ?? '')) ?>"
                            <?php endif; ?>
                            data-sort-invoice_date="<?= esc((string) ($r['invoice_date'] ?? '')) ?>"
                            data-sort-payment_label="<?= esc((string) $r['payment_label']) ?>"
                            data-sort-posted_label="<?= esc((string) $r['posted_label']) ?>"
                            data-sort-total="<?= esc((string) (float) ($r['total'] ?? 0)) ?>"
                            data-sort-subtotal="<?= esc((string) (float) ($r['subtotal'] ?? 0)) ?>">
                            <td class="col-seq"><?= $seq ?></td>
                            <td class="col-inv-no">
                                <code><?= esc((string) $r['invoice_no']) ?></code>
                                <a class="btn btn-ghost btn-sm no-print" style="padding:0 0.35rem;font-size:0.75rem;"
                                   href="<?= esc($invUrl) ?>">عرض</a>
                            </td>
                            <?php if ($showAllSuppliers): ?>
                            <td class="col-customer"><span class="report-sales-party-name"><?= esc((string) ($r['supplier_name'] ?? '')) ?></span></td>
                            <?php endif; ?>
                            <td class="col-date" dir="ltr"><?= esc(format_date_dmY((string) ($r['invoice_date'] ?? ''))) ?></td>
                            <td class="col-pay"><?= esc((string) $r['payment_label']) ?></td>
                            <td class="col-posted"><?= esc((string) $r['posted_label']) ?></td>
                            <td class="col-money"><?= esc(format_money((float) $r['subtotal'])) ?></td>
                            <td class="col-money"><?= esc(format_money((float) $r['total'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php if ($rows): ?>
                    <tfoot>
                    <tr>
                        <td colspan="<?= $showAllSuppliers ? 6 : 5 ?>">الإجمالي</td>
                        <td class="col-money"><?= esc(format_money($sumSub)) ?></td>
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
