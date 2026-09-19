<?php
declare(strict_types=1);

require_once app_path('includes/pur_purchase_orders_report.php');
require_once app_path('includes/document_header.php');
require_once app_path('includes/item_picker.php');

$pdo = db();
$itemId = isset($_GET['item_id']) && $_GET['item_id'] !== '' ? (int) $_GET['item_id'] : -1;
$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));

if ($from === '') {
    $from = app_default_date_from();
}
if ($to === '') {
    $to = app_default_date_to();
}

$rows = [];
$itemDisplayName = '';
$sumLineGross = 0.0;
$showResult = false;
$err = '';
$submitted = isset($_GET['item_id']) && $_GET['item_id'] !== '';

if ($submitted) {
    if ($itemId < 1) {
        $err = 'اختر المادة.';
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
            $st = $pdo->prepare('SELECT name_ar FROM inv_item WHERE id = ? LIMIT 1');
            $st->execute([$itemId]);
            $it = $st->fetch(PDO::FETCH_ASSOC);
            if (!$it) {
                $err = 'المادة غير موجودة.';
            } else {
                $itemDisplayName = (string) ($it['name_ar'] ?? '');
                $showResult = true;
                $rows = pur_report_purchase_orders_by_item($pdo, $itemId, $from, $to);
                foreach ($rows as $r) {
                    $sumLineGross += (float) ($r['line_gross'] ?? 0);
                }
            }
        }
    }
} elseif ($itemId > 0) {
    $st = $pdo->prepare('SELECT name_ar FROM inv_item WHERE id = ? LIMIT 1');
    $st->execute([$itemId]);
    $it = $st->fetch(PDO::FETCH_ASSOC);
    if ($it) {
        $itemDisplayName = (string) ($it['name_ar'] ?? '');
    }
}

$reportTitle = 'تقرير طلبات الشراء حسب المادة';
$apiItems = app_url('api/items_search.php');

$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$invCssPath = app_path('assets/css/sales-invoice.css');
$invCssUrl = app_url('assets/css/sales-invoice.css') . (is_file($invCssPath) ? '?v=' . (string) filemtime($invCssPath) : '');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');
$sortJsPath = app_path('assets/js/report-sales-table-sort.js');
$sortJsUrl = app_url('assets/js/report-sales-table-sort.js') . (is_file($sortJsPath) ? '?v=' . (string) filemtime($sortJsPath) : '');

$pageDataAttrs = ' data-report-title="' . esc($reportTitle) . '"';
$pageDataAttrs .= ' data-report-route="report_purchase_orders_by_item"';
if ($showResult) {
    $pageDataAttrs .= ' data-export-label="' . esc($itemDisplayName) . '"';
    $pageDataAttrs .= ' data-from-dmy="' . esc(format_date_dmY($from)) . '"';
    $pageDataAttrs .= ' data-to-dmy="' . esc(format_date_dmY($to)) . '"';
}
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($invCssUrl) ?>">
<?php item_picker_enqueue_assets(); ?>

<div class="card report-sales-page"<?= $pageDataAttrs ?>>

    <?php if ($err !== ''): ?>
        <div class="alert alert-error no-print" style="margin-bottom:1rem;"><?= esc($err) ?></div>
    <?php endif; ?>

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print">
        <input type="hidden" name="r" value="report_purchase_orders_by_item">
        <div class="form-row">
            <?= item_picker_single_field([
                'id' => 'report_po_item_id',
                'name' => 'item_id',
                'value' => $itemId >= 0 ? $itemId : '',
                'display_text' => $itemDisplayName,
                'label' => 'المادة *',
                'wrapper_class' => 'field',
                'wrapper_style' => 'flex:1 1 16rem',
                'api_items' => $apiItems,
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
                        <td><strong>المادة:</strong> <span class="doc-print-meta-value"><?= esc($itemDisplayName) ?></span></td>
                    </tr>
                    <tr>
                        <td>
                            <strong>من تاريخ:</strong> <?= esc(format_date_dmY($from)) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>إلى تاريخ:</strong> <?= esc(format_date_dmY($to)) ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>عدد الأسطر:</strong> <?= count($rows) ?></td>
                    </tr>
                </table>
            </div>

            <div class="report-sales-table-wrap">
                <table class="report-sales-table js-sortable-report"
                       data-default-sort="order_date"
                       data-default-dir="asc">
                    <colgroup>
                        <col class="col-seq">
                        <col class="col-inv-no">
                        <col class="col-date">
                        <col class="col-customer">
                        <col class="col-money">
                        <col class="col-money">
                        <col class="col-money">
                        <col class="col-money">
                        <col class="col-posted">
                    </colgroup>
                    <thead>
                    <tr>
                        <th class="col-seq js-sort-th" data-sort="seq" data-sort-type="number">#</th>
                        <th class="col-inv-no js-sort-th" data-sort="order_no" data-sort-type="text">رقم الطلب</th>
                        <th class="col-date js-sort-th" data-sort="order_date" data-sort-type="date">التاريخ</th>
                        <th class="col-customer js-sort-th" data-sort="supplier_name" data-sort-type="text">المورد</th>
                        <th class="col-money js-sort-th" data-sort="qty_ordered" data-sort-type="number">الكمية المطلوبة</th>
                        <th class="col-money js-sort-th" data-sort="qty_invoiced" data-sort-type="number">المُفوَّت</th>
                        <th class="col-money js-sort-th" data-sort="unit_price" data-sort-type="number">السعر</th>
                        <th class="col-money js-sort-th" data-sort="line_gross" data-sort-type="number">الإجمالي</th>
                        <th class="col-posted js-sort-th" data-sort="status_label" data-sort-type="text">الحالة</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="9" class="muted" style="text-align:center;padding:1.25rem;">
                                لا توجد طلبات شراء لهذه المادة في الفترة المحددة.
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php
                    $seq = 0;
                    foreach ($rows as $r):
                        $seq += 1;
                        ?>
                        <tr data-sort-row="1"
                            data-sort-seq="<?= $seq ?>"
                            data-sort-order_no="<?= esc((string) $r['order_no']) ?>"
                            data-sort-order_date="<?= esc((string) ($r['order_date'] ?? '')) ?>"
                            data-sort-supplier_name="<?= esc((string) ($r['supplier_name'] ?? '')) ?>"
                            data-sort-qty_ordered="<?= esc((string) (float) ($r['qty_ordered'] ?? 0)) ?>"
                            data-sort-qty_invoiced="<?= esc((string) (float) ($r['qty_invoiced'] ?? 0)) ?>"
                            data-sort-unit_price="<?= esc((string) (float) ($r['unit_price'] ?? 0)) ?>"
                            data-sort-line_gross="<?= esc((string) (float) ($r['line_gross'] ?? 0)) ?>"
                            data-sort-status_label="<?= esc((string) ($r['status_label'] ?? '')) ?>">
                            <td class="col-seq"><?= $seq ?></td>
                            <td class="col-inv-no"><code><?= esc((string) $r['order_no']) ?></code></td>
                            <td class="col-date" dir="ltr"><?= esc(format_date_dmY((string) ($r['order_date'] ?? ''))) ?></td>
                            <td class="col-customer"><span class="report-sales-party-name"><?= esc((string) ($r['supplier_name'] ?? '')) ?></span></td>
                            <td class="col-money"><?= esc(format_money((float) $r['qty_ordered'])) ?></td>
                            <td class="col-money"><?= esc(format_money((float) $r['qty_invoiced'])) ?></td>
                            <td class="col-money"><?= esc(format_money((float) $r['unit_price'])) ?></td>
                            <td class="col-money"><?= esc(format_money((float) $r['line_gross'])) ?></td>
                            <td class="col-posted"><?= esc((string) ($r['status_label'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php if ($rows): ?>
                    <tfoot>
                    <tr>
                        <td colspan="7">الإجمالي</td>
                        <td class="col-money"><?= esc(format_money($sumLineGross)) ?></td>
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
<?php item_picker_modal_once(); ?>
