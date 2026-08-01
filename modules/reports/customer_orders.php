<?php
declare(strict_types=1);

require_once app_path('includes/sal_customer_orders_report.php');
require_once app_path('includes/document_header.php');
require_once app_path('includes/crm_sales_rep_schema.php');
require_once app_path('includes/customer_picker.php');
require_once app_path('includes/item_picker.php');

$pdo = db();
sal_customer_order_ensure_schema($pdo);
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
$itemId = isset($_GET['item_id']) && $_GET['item_id'] !== ''
    ? (int) $_GET['item_id']
    : 0;
$statusFilter = trim((string) ($_GET['status'] ?? 'all'));
if (!in_array($statusFilter, ['all', 'draft', 'approved'], true)) {
    $statusFilter = 'all';
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
$customerLabel = '';
$salesRepLabel = '';
$itemLabel = '';
$itemDisplayName = '';
$sumQty = 0.0;
$sumQtyBase = 0.0;
$showResult = false;
$err = '';

$submitted = isset($_GET['customer_id']) && $_GET['customer_id'] !== '';

if ($submitted) {
    if ($customerId < 0) {
        $err = 'اختر العميل أو «جميع العملاء».';
    } elseif ($salesRepId < 0) {
        $err = 'اختر المندوب أو «جميع المندوبين».';
    } elseif ($itemId < 0) {
        $err = 'اختر المادة أو «جميع المواد».';
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
            } else {
                $st = $pdo->prepare('SELECT name_ar FROM crm_customer WHERE id = ? LIMIT 1');
                $st->execute([$customerId]);
                $cust = $st->fetch(PDO::FETCH_ASSOC);
                if (!$cust) {
                    $err = 'العميل غير موجود.';
                } else {
                    $customerLabel = (string) ($cust['name_ar'] ?? '');
                }
            }

            if ($err === '') {
                if ($salesRepId === 0) {
                    $salesRepLabel = 'جميع المندوبين';
                } else {
                    $stRep = $pdo->prepare('SELECT name_ar FROM crm_sales_rep WHERE id = ? LIMIT 1');
                    $stRep->execute([$salesRepId]);
                    $rep = $stRep->fetch(PDO::FETCH_ASSOC);
                    if (!$rep) {
                        $err = 'المندوب غير موجود.';
                    } else {
                        $salesRepLabel = (string) ($rep['name_ar'] ?? '');
                    }
                }
            }

            if ($err === '') {
                if ($itemId === 0) {
                    $itemLabel = 'جميع المواد';
                    $itemDisplayName = 'جميع المواد';
                } else {
                    $stItem = $pdo->prepare('SELECT name_ar FROM inv_item WHERE id = ? LIMIT 1');
                    $stItem->execute([$itemId]);
                    $itRow = $stItem->fetch(PDO::FETCH_ASSOC);
                    if (!$itRow) {
                        $err = 'المادة غير موجودة.';
                    } else {
                        $itemLabel = (string) ($itRow['name_ar'] ?? '');
                        $itemDisplayName = $itemLabel;
                    }
                }
            }

            if ($err === '') {
                $showResult = true;
                $rows = sal_report_customer_orders(
                    $pdo,
                    $customerId,
                    $salesRepId,
                    $itemId,
                    $from,
                    $to,
                    $statusFilter
                );
                foreach ($rows as $r) {
                    $sumQty += (float) ($r['qty'] ?? 0);
                    $sumQtyBase += (float) ($r['qty_base'] ?? ((float) ($r['qty'] ?? 0) * (float) ($r['unit_factor'] ?? 1)));
                }
            }
        }
    }
} elseif ($itemId > 0) {
    $stItem = $pdo->prepare('SELECT name_ar FROM inv_item WHERE id = ? LIMIT 1');
    $stItem->execute([$itemId]);
    $itRow = $stItem->fetch(PDO::FETCH_ASSOC);
    if ($itRow) {
        $itemDisplayName = (string) ($itRow['name_ar'] ?? '');
    }
}

$statusFilterLabel = match ($statusFilter) {
    'draft' => 'مسودة',
    'approved' => 'معتمد',
    default => 'الكل',
};

$showAllCustomers = $showResult && $customerId === 0;
$reportTitle = 'تقرير طلبات الشراء';

$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$invCssPath = app_path('assets/css/sales-invoice.css');
$invCssUrl = app_url('assets/css/sales-invoice.css') . (is_file($invCssPath) ? '?v=' . (string) filemtime($invCssPath) : '');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');
$sortJsPath = app_path('assets/js/report-sales-table-sort.js');
$sortJsUrl = app_url('assets/js/report-sales-table-sort.js') . (is_file($sortJsPath) ? '?v=' . (string) filemtime($sortJsPath) : '');
$apiItems = app_url('api/items_search.php');
$defaultSortKey = $showAllCustomers ? 'customer_name' : 'order_date';

$pageDataAttrs = ' data-report-title="' . esc($reportTitle) . '"';
$pageDataAttrs .= ' data-report-route="report_customer_orders"';
if ($showResult) {
    $pageDataAttrs .= ' data-export-label="' . esc($customerLabel) . '"';
    $pageDataAttrs .= ' data-from-dmy="' . esc(format_date_dmY($from)) . '"';
    $pageDataAttrs .= ' data-to-dmy="' . esc(format_date_dmY($to)) . '"';
}

$colspanEmpty = 11 + ($showAllCustomers ? 1 : 0);
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($invCssUrl) ?>">
<?php
customer_picker_enqueue_assets();
item_picker_enqueue_assets();
customer_picker_json_script($customers, 'report-customer-orders-customers-json');
?>

<div class="card report-sales-page"<?= $pageDataAttrs ?>>

    <?php if ($err !== ''): ?>
        <div class="alert alert-error no-print" style="margin-bottom:1rem;"><?= esc($err) ?></div>
    <?php endif; ?>

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print">
        <input type="hidden" name="r" value="report_customer_orders">
        <div class="form-row">
            <?= customer_picker_field([
                'id' => 'report_co_cust',
                'name' => 'customer_id',
                'value' => $customerId >= 0 ? $customerId : '',
                'label' => 'العميل *',
                'wrapper_class' => 'field',
                'wrapper_style' => 'flex:1 1 14rem',
                'allow_all' => true,
                'json_id' => 'report-customer-orders-customers-json',
            ]) ?>
            <label class="field" style="flex:0 1 12rem;">
                <span class="field-label">المندوب</span>
                <select class="input" name="sales_rep_id">
                    <option value="0"<?= $salesRepId === 0 ? ' selected' : '' ?>>جميع المندوبين</option>
                    <?php foreach ($salesReps as $rep): ?>
                        <option value="<?= (int) $rep['id'] ?>"<?= $salesRepId === (int) $rep['id'] ? ' selected' : '' ?>>
                            <?= esc((string) $rep['name_ar']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?= item_picker_single_field([
                'id' => 'report_co_item',
                'name' => 'item_id',
                'value' => $itemId > 0 ? $itemId : ($submitted ? 0 : ''),
                'display_text' => $itemDisplayName,
                'label' => 'المادة',
                'wrapper_class' => 'field',
                'wrapper_style' => 'flex:1 1 14rem',
                'warehouse_id' => 0,
                'api_items' => $apiItems,
                'allow_all' => true,
            ]) ?>
        </div>
        <div class="form-row" style="margin-top:0.5rem;">
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
                    <option value="draft"<?= $statusFilter === 'draft' ? ' selected' : '' ?>>مسودة</option>
                    <option value="approved"<?= $statusFilter === 'approved' ? ' selected' : '' ?>>معتمد</option>
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
                        <td><strong>العميل:</strong> <span class="doc-print-meta-value doc-print-meta-value--party"><?= esc($customerLabel) ?></span></td>
                    </tr>
                    <tr>
                        <td><strong>المندوب:</strong> <?= esc($salesRepLabel) ?></td>
                    </tr>
                    <tr>
                        <td><strong>المادة:</strong> <?= esc($itemLabel) ?></td>
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
                            <strong>عدد البنود:</strong> <?= count($rows) ?>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="report-sales-table-wrap">
                <table class="report-sales-table js-sortable-report"
                       data-default-sort="<?= esc($defaultSortKey) ?>"
                       data-default-dir="asc">
                    <thead>
                    <tr>
                        <th class="col-seq js-sort-th" data-sort="seq" data-sort-type="number">#</th>
                        <th class="col-inv-no js-sort-th" data-sort="order_no" data-sort-type="text">رقم الطلب</th>
                        <th class="col-date js-sort-th" data-sort="order_date" data-sort-type="date">التاريخ</th>
                        <?php if ($showAllCustomers): ?>
                        <th class="col-customer js-sort-th" data-sort="customer_name" data-sort-type="text">العميل</th>
                        <?php endif; ?>
                        <th class="col-rep js-sort-th" data-sort="sales_rep_name" data-sort-type="text">المندوب</th>
                        <th class="col-item js-sort-th" data-sort="item_name" data-sort-type="text">المادة</th>
                        <th class="js-sort-th" data-sort="unit_name" data-sort-type="text">الوحدة</th>
                        <th class="col-money js-sort-th" data-sort="unit_factor" data-sort-type="number">التعبئة</th>
                        <th class="col-money js-sort-th" data-sort="qty" data-sort-type="number">الكمية</th>
                        <th class="col-money js-sort-th" data-sort="qty_base" data-sort-type="number">العدد</th>
                        <th class="col-posted js-sort-th" data-sort="status_label" data-sort-type="text">الحالة</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="<?= (int) $colspanEmpty ?>" class="muted" style="text-align:center;padding:1.25rem;">
                                لا توجد طلبات شراء مطابقة في الفترة المحددة.
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php
                    $seq = 0;
                    foreach ($rows as $r):
                        $seq += 1;
                        $viewUrl = app_url('index.php?r=sales_customer_orders_approve&id=' . (int) $r['id']);
                        ?>
                        <tr data-sort-row="1"
                            data-sort-seq="<?= $seq ?>"
                            data-sort-order_no="<?= esc((string) $r['order_no']) ?>"
                            data-sort-order_date="<?= esc((string) ($r['order_date'] ?? '')) ?>"
                            <?php if ($showAllCustomers): ?>
                            data-sort-customer_name="<?= esc((string) ($r['customer_name'] ?? '')) ?>"
                            <?php endif; ?>
                            data-sort-sales_rep_name="<?= esc((string) ($r['sales_rep_name'] ?? '')) ?>"
                            data-sort-item_name="<?= esc((string) ($r['item_name'] ?? '')) ?>"
                            data-sort-unit_name="<?= esc((string) ($r['unit_name'] ?? '')) ?>"
                            data-sort-unit_factor="<?= esc((string) (float) ($r['unit_factor'] ?? 1)) ?>"
                            data-sort-qty="<?= esc((string) (float) ($r['qty'] ?? 0)) ?>"
                            data-sort-qty_base="<?= esc((string) (float) ($r['qty_base'] ?? 0)) ?>"
                            data-sort-status_label="<?= esc((string) ($r['status_label'] ?? '')) ?>">
                            <td class="col-seq"><?= $seq ?></td>
                            <td class="col-inv-no">
                                <code><?= esc((string) $r['order_no']) ?></code>
                                <a class="btn btn-ghost btn-sm no-print" style="padding:0 0.35rem;font-size:0.75rem;"
                                   href="<?= esc($viewUrl) ?>">عرض</a>
                            </td>
                            <td class="col-date" dir="ltr"><?= esc(format_date_dmY((string) ($r['order_date'] ?? ''))) ?></td>
                            <?php if ($showAllCustomers): ?>
                            <td class="col-customer"><span class="report-sales-party-name"><?= esc((string) ($r['customer_name'] ?? '')) ?></span></td>
                            <?php endif; ?>
                            <td class="col-rep"><?= esc((string) ($r['sales_rep_name'] ?? '')) !== '' ? esc((string) $r['sales_rep_name']) : '—' ?></td>
                            <td class="col-item"><?= esc((string) ($r['item_name'] ?? '')) ?></td>
                            <td><?= esc((string) ($r['unit_name'] ?? '')) !== '' ? esc((string) $r['unit_name']) : '—' ?></td>
                            <td class="col-money" dir="ltr"><?= esc(format_amount((float) ($r['unit_factor'] ?? 1))) ?></td>
                            <td class="col-money"><?= esc(format_amount((float) ($r['qty'] ?? 0))) ?></td>
                            <td class="col-money"><?= esc(format_amount((float) ($r['qty_base'] ?? ((float) ($r['qty'] ?? 0) * (float) ($r['unit_factor'] ?? 1))))) ?></td>
                            <td class="col-posted"><?= esc((string) ($r['status_label'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php if ($rows): ?>
                    <tfoot>
                    <tr>
                        <td colspan="<?= $showAllCustomers ? 8 : 7 ?>">إجمالي الكميات / العدد</td>
                        <td class="col-money"><?= esc(format_amount($sumQty)) ?></td>
                        <td class="col-money"><?= esc(format_amount($sumQtyBase ?? $sumQty)) ?></td>
                        <td></td>
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
