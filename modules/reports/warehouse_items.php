<?php
declare(strict_types=1);

require_once app_path('includes/inv_warehouse_items_report.php');
require_once app_path('includes/document_header.php');

$pdo = db();

$warehouses = $pdo->query(
    'SELECT id, code, name_ar FROM inv_warehouse WHERE is_active = 1 ORDER BY name_ar'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$warehouseId = (int) ($_GET['warehouse_id'] ?? 0);
$positiveQtyOnly = isset($_GET['positive_qty']) && (string) $_GET['positive_qty'] === '1';

$rows = [];
$warehouseLabel = '';
$showResult = false;
$err = '';

if (isset($_GET['run']) && (string) $_GET['run'] === '1') {
    if ($warehouseId < 1) {
        $err = 'اختر المستودع.';
    } else {
        $stWh = $pdo->prepare('SELECT code, name_ar FROM inv_warehouse WHERE id = ? AND is_active = 1 LIMIT 1');
        $stWh->execute([$warehouseId]);
        $whRow = $stWh->fetch(PDO::FETCH_ASSOC);
        if (!$whRow) {
            $err = 'المستودع غير موجود.';
        } else {
            $whName = trim((string) ($whRow['name_ar'] ?? ''));
            $whCode = trim((string) ($whRow['code'] ?? ''));
            $warehouseLabel = $whName !== '' ? $whName : $whCode;
            if ($whCode !== '' && $whName !== '') {
                $warehouseLabel = $whCode . ' — ' . $whName;
            }
            $showResult = true;
            $rows = inv_report_warehouse_items_lines($pdo, $warehouseId, $positiveQtyOnly);
        }
    }
}

$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$invCssPath = app_path('assets/css/sales-invoice.css');
$invCssUrl = app_url('assets/css/sales-invoice.css') . (is_file($invCssPath) ? '?v=' . (string) filemtime($invCssPath) : '');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');
$html2pdfPath = app_path('assets/js/html2pdf.bundle.min.js');
$html2pdfUrl = app_url('assets/js/html2pdf.bundle.min.js')
    . (is_file($html2pdfPath) ? '?v=' . (string) filemtime($html2pdfPath) : '');

$reportTitle = 'تقرير المواد';

$pageDataAttrs = ' data-report-title="' . esc($reportTitle) . '"';
$pageDataAttrs .= ' data-report-route="report_warehouse_items"';
if ($showResult && $err === '') {
    $pageDataAttrs .= ' data-export-label="' . esc($warehouseLabel) . '"';
}
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($invCssUrl) ?>">

<div class="card report-sales-page report-warehouse-items-page"<?= $pageDataAttrs ?>>

    <?php if ($err !== ''): ?>
        <div class="alert alert-error no-print" style="margin-bottom:1rem;"><?= esc($err) ?></div>
    <?php endif; ?>

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print">
        <input type="hidden" name="r" value="report_warehouse_items">
        <input type="hidden" name="run" value="1">
        <div class="form-row">
            <label class="field" style="flex:1 1 18rem;">
                <span class="field-label">المستودع *</span>
                <select class="input" name="warehouse_id" required>
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
            <label class="field" style="flex:0 0 auto;align-self:flex-end;">
                <input type="checkbox" name="positive_qty" value="1" <?= $positiveQtyOnly ? 'checked' : '' ?>>
                <span style="margin-right:0.35rem;">المواد ذات رصيد فقط</span>
            </label>
        </div>
        <div style="margin-top:0.5rem;">
            <button class="btn btn-primary" type="submit">عرض التقرير</button>
        </div>
    </form>

    <?php if ($showResult && $err === ''): ?>
        <div class="report-sales-result report-sales-print-area report-warehouse-items-print">
            <?= document_print_header_html($reportTitle, $pdo) ?>

            <div class="party-stmt-report-head">
                <p class="party-stmt-report-customer"><?= esc($warehouseLabel) ?></p>
                <p class="party-stmt-report-dates">
                    <span>عدد المواد: <?= count($rows) ?></span>
                    <?php if ($positiveQtyOnly): ?>
                        <span class="party-stmt-report-dates-sep">|</span>
                        <span>مواد ذات رصيد فقط</span>
                    <?php endif; ?>
                </p>
            </div>

            <div class="report-sales-table-wrap">
                <table class="data-table report-sales-table report-warehouse-items-table">
                    <thead>
                    <tr>
                        <th class="col-seq">#</th>
                        <th class="col-inv-no">رقم المادة</th>
                        <th class="col-item-name">اسم المادة</th>
                        <th class="col-qty">الكمية</th>
                        <th class="col-category">الفئة</th>
                        <th class="col-unit">الوحدة</th>
                        <th class="col-price">سعر التكلفة</th>
                        <th class="col-price">سعر البيع</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="8" class="muted" style="text-align:center;padding:1.25rem;">
                                لا توجد مواد مطابقة للفلتر في هذا المستودع.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php $seq = 0; ?>
                        <?php foreach ($rows as $r): ?>
                            <?php $seq++; ?>
                            <tr>
                                <td class="col-seq"><?= $seq ?></td>
                                <td class="col-inv-no"><code><?= esc((string) ($r['item_sku'] ?? '')) ?></code></td>
                                <td class="col-item-name"><?= esc((string) ($r['item_name'] ?? '')) ?></td>
                                <td class="col-qty"><?= esc(format_amount((float) ($r['qty'] ?? 0))) ?></td>
                                <td class="col-category"><?= esc((string) ($r['category_name'] ?? '')) !== '' ? esc((string) $r['category_name']) : '—' ?></td>
                                <td class="col-unit"><?= esc((string) ($r['unit_name'] ?? '')) !== '' ? esc((string) $r['unit_name']) : '—' ?></td>
                                <td class="col-price"><?= esc(format_amount((float) ($r['cost_price'] ?? 0))) ?></td>
                                <td class="col-price"><?= esc(format_amount((float) ($r['sale_price'] ?? 0))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<div id="sales-inv-export-host" class="sales-inv-export-host" aria-hidden="true"></div>

<script src="<?= esc($html2pdfUrl) ?>"></script>
<script src="<?= esc($exportJsUrl) ?>" defer></script>
