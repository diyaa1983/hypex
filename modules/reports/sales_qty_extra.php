<?php
declare(strict_types=1);

require_once app_path('includes/inv_item_display.php');
require_once app_path('includes/sal_invoice_qty_extra_report.php');
require_once app_path('includes/document_header.php');
require_once app_path('includes/item_picker.php');

$pdo = db();

$rawItemId = array_key_exists('item_id', $_GET) ? trim((string) $_GET['item_id']) : '';
if ($rawItemId === '0' || strcasecmp($rawItemId, 'all') === 0) {
    $itemId = 0;
} elseif ($rawItemId !== '' && preg_match('/^\d+$/', $rawItemId)) {
    $itemId = (int) $rawItemId;
} else {
    $itemId = -1;
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
$itemLabel = '';
$itemDisplayName = '';
$showResult = false;
$err = '';

$submitted = $rawItemId !== '' && isset($_GET['from'], $_GET['to']);

if ($submitted) {
    if ($itemId < 0) {
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

            if ($itemId === 0) {
                $itemLabel = 'جميع المواد';
                $itemDisplayName = 'جميع المواد';
                $showResult = true;
                $rows = sal_report_invoice_qty_extra_lines($pdo, 0, $from, $to);
            } else {
                $barcodeCol = inv_item_has_barcode_column($pdo) ? ', barcode' : '';
                $stItem = $pdo->prepare('SELECT name_ar, sku' . $barcodeCol . ' FROM inv_item WHERE id = ? AND is_active = 1 LIMIT 1');
                $stItem->execute([$itemId]);
                $itRow = $stItem->fetch(PDO::FETCH_ASSOC);
                if (!$itRow) {
                    $err = 'المادة غير موجودة.';
                } else {
                    $itemLabel = inv_item_picker_label(
                        (string) ($itRow['name_ar'] ?? ''),
                        (string) ($itRow['barcode'] ?? ''),
                        (string) ($itRow['sku'] ?? '')
                    );
                    $itemDisplayName = $itemLabel;
                    $showResult = true;
                    $rows = sal_report_invoice_qty_extra_lines($pdo, $itemId, $from, $to);
                }
            }
        }
    }
} elseif ($itemId === 0) {
    $itemDisplayName = 'جميع المواد';
} elseif ($itemId > 0) {
    $barcodeCol = inv_item_has_barcode_column($pdo) ? ', barcode' : '';
    $stItem = $pdo->prepare('SELECT name_ar, sku' . $barcodeCol . ' FROM inv_item WHERE id = ? AND is_active = 1 LIMIT 1');
    $stItem->execute([$itemId]);
    $itRow = $stItem->fetch(PDO::FETCH_ASSOC);
    if ($itRow) {
        $itemDisplayName = inv_item_picker_label(
            (string) ($itRow['name_ar'] ?? ''),
            (string) ($itRow['barcode'] ?? ''),
            (string) ($itRow['sku'] ?? '')
        );
    }
}

$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$itemPickerJsPath = app_path('assets/js/report-sales-by-item.js');
$itemPickerJsUrl = app_url('assets/js/report-sales-by-item.js') . (is_file($itemPickerJsPath) ? '?v=' . (string) filemtime($itemPickerJsPath) : '');
$invCssPath = app_path('assets/css/sales-invoice.css');
$invCssUrl = app_url('assets/css/sales-invoice.css') . (is_file($invCssPath) ? '?v=' . (string) filemtime($invCssPath) : '');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');

$reportTitle = 'تقرير الكميات الإضافية على الفواتير';

$defaultWarehouseId = item_picker_default_warehouse_id($pdo);

$pageDataAttrs = ' data-report-title="' . esc($reportTitle) . '"';
$pageDataAttrs .= ' data-report-route="report_sales_qty_extra"';
$pageDataAttrs .= ' data-default-warehouse-id="' . (int) $defaultWarehouseId . '"';
if ($showResult && $err === '') {
    $pageDataAttrs .= ' data-export-label="' . esc($itemLabel) . '"';
    $pageDataAttrs .= ' data-from-dmy="' . esc(format_date_dmY($from)) . '"';
    $pageDataAttrs .= ' data-to-dmy="' . esc(format_date_dmY($to)) . '"';
}

$apiItems = app_url('api/items_search.php');
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($invCssUrl) ?>">
<?php item_picker_enqueue_assets(); ?>

<div class="card report-sales-page"<?= $pageDataAttrs ?>>

    <?php if ($err !== ''): ?>
        <div class="alert alert-error no-print" style="margin-bottom:1rem;"><?= esc($err) ?></div>
    <?php endif; ?>

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print">
        <input type="hidden" name="r" value="report_sales_qty_extra">
        <div class="form-row">
            <?= item_picker_single_field([
                'id' => 'report_sales_qty_extra_item',
                'name' => 'item_id',
                'value' => $itemId >= 0 ? $itemId : '',
                'display_text' => $itemDisplayName,
                'label' => 'المادة *',
                'wrapper_class' => 'field',
                'wrapper_style' => 'flex:1 1 18rem',
                'allow_all' => true,
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

    <?php if ($showResult && $err === ''): ?>
        <div class="report-sales-result report-sales-print-area">
            <?= document_print_header_html($reportTitle, $pdo) ?>

            <div class="doc-print-meta">
                <table>
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
                        <td><strong>عدد السطور:</strong> <?= count($rows) ?></td>
                    </tr>
                    <tr>
                        <td class="muted" style="font-size:0.9rem;">
                            يشمل الكمية الإضافية المسجّلة، وبنود الفاتورة التي سعرها صفر (تُحسب كمية السطر ككمية إضافية).
                        </td>
                    </tr>
                </table>
            </div>

            <div class="report-sales-table-wrap">
                <table class="report-sales-table">
                    <thead>
                    <tr>
                        <th class="col-seq">تسلسل</th>
                        <th class="col-inv-no">الباركود</th>
                        <th class="col-item">اسم المادة</th>
                        <th class="col-inv-no">رقم الفاتورة</th>
                        <th class="col-date">تاريخ الفاتورة</th>
                        <th class="col-money">الكمية الإضافية</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="6" class="muted" style="text-align:center;padding:1.25rem;">
                                <?php if ($itemId === 0): ?>
                                    لا توجد فواتير بيع مؤكّدة بكمية إضافية أو بمواد بسعر صفر لأي مادة في الفترة المحددة.
                                <?php else: ?>
                                    لا توجد فواتير بيع مؤكّدة بكمية إضافية أو بمواد بسعر صفر لهذه المادة في الفترة المحددة.
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php
                    $seq = 0;
                    foreach ($rows as $r):
                        $seq += 1;
                        ?>
                        <tr>
                            <td class="col-seq"><?= $seq ?></td>
                            <td class="col-inv-no"><?= esc((string) $r['item_sku']) ?></td>
                            <td class="col-item"><?= esc((string) $r['item_name']) ?></td>
                            <td class="col-inv-no"><code><?= esc((string) ($r['invoice_no'] ?? '')) ?></code></td>
                            <td class="col-date"><?= esc(format_date_dmY((string) ($r['invoice_date'] ?? ''))) ?></td>
                            <td class="col-money"><?= esc(format_amount((float) $r['qty_extra'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<div id="sales-inv-export-host" class="sales-inv-export-host" aria-hidden="true"></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" defer crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="<?= esc($itemPickerJsUrl) ?>" defer></script>
<script src="<?= esc($exportJsUrl) ?>"></script>
