<?php
declare(strict_types=1);

require_once app_path('includes/inv_item_display.php');
require_once app_path('includes/inv_item_barcode.php');
require_once app_path('includes/inv_item_stock_ledger.php');
require_once app_path('includes/document_header.php');
require_once app_path('includes/item_picker.php');
require_once app_path('includes/nav_helpers.php');

$pdo = db();

$warehouses = $pdo->query(
    'SELECT id, code, name_ar FROM inv_warehouse WHERE is_active = 1 ORDER BY name_ar'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$itemId = isset($_GET['item_id']) && $_GET['item_id'] !== '' ? (int) $_GET['item_id'] : 0;
$warehouseId = isset($_GET['warehouse_id']) && $_GET['warehouse_id'] !== '' ? (int) $_GET['warehouse_id'] : 0;

$rows = [];
$itemLabel = '';
$itemSku = '';
$warehouseLabel = '';
$onHand = null;
$showResult = false;
$err = '';

$submitted = isset($_GET['run']) && (string) $_GET['run'] === '1';

if ($submitted) {
    if ($itemId < 1) {
        $err = 'اختر المادة من القائمة.';
    } elseif ($warehouseId < 1) {
        $err = 'اختر المستودع.';
    } else {
        $barcodeCol = inv_item_has_barcode_column($pdo) ? ', barcode' : '';
        $stItem = $pdo->prepare('SELECT name_ar, sku' . $barcodeCol . ' FROM inv_item WHERE id = ? LIMIT 1');
        $stItem->execute([$itemId]);
        $itRow = $stItem->fetch(PDO::FETCH_ASSOC);

        $stWh = $pdo->prepare('SELECT code, name_ar FROM inv_warehouse WHERE id = ? AND is_active = 1 LIMIT 1');
        $stWh->execute([$warehouseId]);
        $whRow = $stWh->fetch(PDO::FETCH_ASSOC);

        if (!$itRow) {
            $err = 'المادة غير موجودة.';
        } elseif (!$whRow) {
            $err = 'المستودع غير موجود.';
        } else {
            try {
                $itemLabel = (string) ($itRow['name_ar'] ?? '');
                $itemSku = inv_item_material_number_digits(
                    (string) ($itRow['barcode'] ?? ''),
                    (string) ($itRow['sku'] ?? '')
                );
                $whName = trim((string) ($whRow['name_ar'] ?? ''));
                $whCode = trim((string) ($whRow['code'] ?? ''));
                $warehouseLabel = $whName !== '' ? $whName : $whCode;
                if ($whCode !== '' && $whName !== '') {
                    $warehouseLabel = $whCode . ' — ' . $whName;
                }
                $onHand = inv_item_stock_ledger_qty_on_hand($pdo, $itemId, $warehouseId);
                $rows = inv_item_stock_ledger_lines($pdo, $itemId, $warehouseId);
                $showResult = true;
            } catch (Throwable $e) {
                $err = (defined('APP_DEBUG') && APP_DEBUG)
                    ? 'تعذر تحميل حركات المادة: ' . $e->getMessage()
                    : 'تعذر تحميل حركات المادة. راجع سجل الأخطاء أو جرّب ?debug=1';
            }
        }
    }
}

$itemPickLabel = 'اضغط لاختيار المادة';
$itemPickPlaceholder = true;
if ($itemId > 0) {
    $stPick = $pdo->prepare('SELECT name_ar FROM inv_item WHERE id = ? AND is_active = 1 LIMIT 1');
    $stPick->execute([$itemId]);
    $pickRow = $stPick->fetch(PDO::FETCH_ASSOC);
    if ($pickRow) {
        $itemPickLabel = (string) ($pickRow['name_ar'] ?? '');
        $itemPickPlaceholder = $itemPickLabel === '';
    }
}

$apiItems = app_url('api/items_search.php');
$screenTitle = 'كشف حركات مادة';
$reportCssPath = app_path('assets/css/report-sales.css');
$reportCssUrl = app_url('assets/css/report-sales.css') . (is_file($reportCssPath) ? '?v=' . (string) filemtime($reportCssPath) : '');
$cssInvPath = app_path('assets/css/sales-invoice.css');
$cssInvUrl = app_url('assets/css/sales-invoice.css') . (is_file($cssInvPath) ? '?v=' . (string) filemtime($cssInvPath) : '');
$cssPath = app_path('assets/css/item-stock-ledger.css');
$cssUrl = app_url('assets/css/item-stock-ledger.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$jsPath = app_path('assets/js/item-stock-ledger.js');
$jsUrl = app_url('assets/js/item-stock-ledger.js') . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : '');
$pickerJsPath = app_path('assets/js/item-stock-ledger-picker.js');
$pickerJsUrl = app_url('assets/js/item-stock-ledger-picker.js') . (is_file($pickerJsPath) ? '?v=' . (string) filemtime($pickerJsPath) : '');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');
$html2pdfPath = app_path('assets/js/html2pdf.bundle.min.js');
$html2pdfUrl = app_url('assets/js/html2pdf.bundle.min.js')
    . (is_file($html2pdfPath) ? '?v=' . (string) filemtime($html2pdfPath) : '');

$pageDataAttrs = ' data-report-title="' . esc($screenTitle) . '"';
$pageDataAttrs .= ' data-report-route="item_stock_movements"';
if ($showResult && $err === '') {
    $pageDataAttrs .= ' data-export-label="' . esc($itemSku !== '' ? $itemSku . ' — ' . $itemLabel : $itemLabel) . '"';
    $pageDataAttrs .= ' data-warehouse-label="' . esc($warehouseLabel) . '"';
}

$itemIdValue = $itemId > 0 ? (string) $itemId : '';
?>
<link rel="stylesheet" href="<?= esc($reportCssUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssInvUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<?php item_picker_enqueue_assets(); ?>

<div class="card item-stock-ledger-page report-sales-page master-page"<?= $pageDataAttrs ?>>

    <?php if ($err !== ''): ?>
        <div class="alert alert-error no-print" style="margin-bottom:1rem;"><?= esc($err) ?></div>
    <?php endif; ?>

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="item-stock-ledger-filters no-print" id="item-stock-ledger-form"
          data-api-items="<?= esc($apiItems) ?>">
        <input type="hidden" name="r" value="item_stock_movements">
        <input type="hidden" name="run" value="1">
        <input type="hidden" name="item_id" id="item-stock-item-id" value="<?= esc($itemIdValue) ?>">
        <div class="form-row">
            <label class="field item-stock-ledger-item-field" style="flex:1 1 18rem;">
                <span class="field-label">المادة *</span>
                <button type="button" class="sales-inv-item-pick" id="item-stock-pick-btn" title="اختيار مادة">
                    <span id="item-stock-item-display" class="js-name sales-inv-item-name<?= $itemPickPlaceholder ? ' is-placeholder' : '' ?>"><?= esc($itemPickLabel) ?></span>
                </button>
            </label>
            <label class="field" style="flex:1 1 14rem;">
                <span class="field-label">المستودع *</span>
                <select class="input" name="warehouse_id" id="item-stock-wh" required>
                    <option value="">— اختر المستودع —</option>
                    <?php foreach ($warehouses as $wh): ?>
                        <option value="<?= (int) $wh['id'] ?>" <?= (int) $wh['id'] === $warehouseId ? 'selected' : '' ?>>
                            <?= esc((string) ($wh['name_ar'] ?? '')) ?>
                            <?php if (($wh['code'] ?? '') !== ''): ?>
                                (<?= esc((string) $wh['code']) ?>)
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field" style="flex:0 0 10rem;">
                <span class="field-label">الرصيد المستودعي الحالي</span>
                <input class="input" type="text" id="item-stock-on-hand" readonly dir="ltr"
                       value="<?= $onHand !== null ? esc(format_amount($onHand)) : '' ?>"
                       placeholder="—" title="يُعرض بعد البحث">
            </label>
        </div>
        <div class="item-stock-ledger-actions">
            <button class="btn btn-primary" type="submit">بحث</button>
        </div>
    </form>

    <?php if ($showResult && $err === ''): ?>
        <div class="item-stock-ledger-toolbar no-print">
            <label class="item-stock-ledger-search field">
                <span class="field-label">بحث في الجدول</span>
                <input type="search" class="input" id="item-stock-table-search" placeholder="رقم الحركة، النوع، التاريخ…" autocomplete="off">
            </label>
        </div>

        <div class="report-sales-result report-sales-print-area item-stock-ledger-result" id="item-stock-ledger-print">
            <?= document_print_header_html($screenTitle, $pdo) ?>

            <div class="party-stmt-report-head">
                <p class="party-stmt-report-customer"><?= esc($itemSku !== '' ? $itemSku . ' — ' . $itemLabel : $itemLabel) ?></p>
                <p class="party-stmt-report-dates">
                    <span>المستودع: <?= esc($warehouseLabel) ?></span>
                    <span class="party-stmt-report-dates-sep">|</span>
                    <span>الرصيد: <?= esc(format_amount((float) $onHand)) ?></span>
                    <span class="party-stmt-report-dates-sep">|</span>
                    <span>عدد الحركات: <?= count($rows) ?></span>
                </p>
            </div>

            <div class="report-sales-table-wrap item-stock-ledger-table-wrap">
                <table class="data-table report-sales-table item-stock-ledger-table" id="item-stock-ledger-table" dir="rtl">
                    <colgroup>
                        <col class="col-seq">
                        <col class="col-inv-no">
                        <col class="col-item-name">
                        <col class="col-mov-type">
                        <col class="col-party">
                        <col class="col-date-invoice">
                        <col class="col-datetime">
                        <col class="col-doc-no">
                        <col class="col-qty">
                        <col class="col-unit-price">
                        <col class="col-line-total">
                        <col class="col-balance">
                    </colgroup>
                    <thead>
                    <tr>
                        <th class="col-seq">تسلسل</th>
                        <th class="col-inv-no">الباركود</th>
                        <th class="col-item-name">اسم المادة</th>
                        <th class="col-mov-type">نوع الحركة</th>
                        <th class="col-party">العميل / المورد</th>
                        <th class="col-date-invoice">تاريخ الفاتورة</th>
                        <th class="col-datetime">تاريخ تسجيل</th>
                        <th class="col-doc-no">رقم الحركة</th>
                        <th class="col-qty">الكمية</th>
                        <th class="col-unit-price" title="سعر الوحدة غير شامل الضريبة">سعر الوحدة</th>
                        <th class="col-line-total">المبلغ الإجمالي</th>
                        <th class="col-balance">الرصيد</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr class="item-stock-ledger-empty">
                            <td colspan="12" class="muted" style="text-align:center;padding:1.25rem;">
                                لا توجد حركات مخزنية مسجّلة لهذه المادة في هذا المستودع.
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php
                    $seq = 0;
                    foreach ($rows as $r):
                        $seq += 1;
                        $openUrl = $r['open_url'] ?? null;
                        if ($openUrl !== null && $openUrl !== '' && $itemId > 0 && $warehouseId > 0) {
                            $openUrl = nav_append_ledger_return_query($openUrl, $itemId, $warehouseId);
                        }
                        $rowClass = $openUrl ? 'item-stock-ledger-row is-clickable' : 'item-stock-ledger-row';
                        $partyName = trim((string) ($r['party_name'] ?? ''));
                        ?>
                        <tr class="<?= esc($rowClass) ?>"
                            <?= $openUrl ? ' data-href="' . esc((string) $openUrl) . '" tabindex="0" role="link"' : '' ?>
                            title="<?= $openUrl ? 'فتح المستند الأصلي' : '' ?>">
                            <td class="col-seq"><?= $seq ?></td>
                            <td class="col-inv-no"><?= esc((string) $r['item_sku']) ?></td>
                            <td class="col-item-name"><?= esc((string) $r['item_name']) ?></td>
                            <td class="col-mov-type"><?= esc((string) $r['mov_type_label']) ?></td>
                            <td class="col-party"<?= $partyName !== '' ? ' title="' . esc($partyName) . '"' : '' ?>>
                                <?php
                                if ($partyName !== '') {
                                    echo '<span class="item-stock-ledger-party">' . esc($partyName) . '</span>';
                                } else {
                                    echo '<span class="muted">—</span>';
                                }
                                ?>
                            </td>
                            <td class="col-date-invoice" dir="ltr"><?= esc((string) ($r['invoice_date_display'] ?? '—')) ?></td>
                            <td class="col-datetime" dir="ltr"><span class="ledger-datetime"><?= esc((string) ($r['move_at_display'] ?? '—')) ?></span></td>
                            <td class="col-doc-no" dir="ltr"><?= esc((string) ($r['document_no'] !== '' ? $r['document_no'] : '—')) ?></td>
                            <td class="col-qty"><?= esc((string) ($r['qty_display'] ?? '—')) ?></td>
                            <td class="col-unit-price"><?= esc((string) ($r['unit_price_excl_display'] ?? '—')) ?></td>
                            <td class="col-line-total"><?= esc((string) ($r['line_total_display'] ?? '—')) ?></td>
                            <td class="col-balance"><?= esc(format_amount((float) $r['balance_after'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<div id="sales-inv-export-host" class="sales-inv-export-host" aria-hidden="true"></div>

<script src="<?= esc($html2pdfUrl) ?>"></script>
<script src="<?= esc($pickerJsUrl) ?>" defer></script>
<script src="<?= esc($jsUrl) ?>" defer></script>
<script src="<?= esc($exportJsUrl) ?>" defer></script>
