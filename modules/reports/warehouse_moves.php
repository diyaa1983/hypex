<?php
declare(strict_types=1);

require_once app_path('includes/inv_warehouse_moves_report.php');
require_once app_path('includes/inv_movement_type_schema.php');
require_once app_path('includes/inv_item_display.php');
require_once app_path('includes/document_header.php');

$pdo = db();
inv_movement_type_ensure_schema($pdo);

$warehouses = $pdo->query(
    'SELECT id, code, name_ar FROM inv_warehouse WHERE is_active = 1 ORDER BY name_ar'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$movementTypes = inv_movement_types_all($pdo, true);

$warehouseId = isset($_GET['warehouse_id']) ? (int) $_GET['warehouse_id'] : 0;
$movementTypeCode = trim((string) ($_GET['movement_type_code'] ?? ''));
$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
if ($from === '') {
    $from = app_default_date_from();
}
if ($to === '') {
    $to = app_default_date_to();
}

$rows = [];
$showResult = false;
$err = '';
$warehouseLabel = 'جميع المستودعات';
$movementTypeLabel = 'جميع أنواع الحركات';

if (isset($_GET['run']) && (string) $_GET['run'] === '1') {
    $fromIso = parse_date_to_iso($from);
    $toIso = parse_date_to_iso($to);
    if ($fromIso === null || $toIso === null) {
        $err = 'تاريخ البداية والنهاية غير صالحين (يوم-شهر-سنة).';
    } elseif ($fromIso > $toIso) {
        $err = 'تاريخ البداية يجب أن يكون قبل أو يساوي تاريخ النهاية.';
    } else {
        $from = $fromIso;
        $to = $toIso;

        if ($warehouseId > 0) {
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
            }
        }

        if ($err === '' && $movementTypeCode !== '' && $movementTypeCode !== '0') {
            $stType = $pdo->prepare('SELECT name_ar FROM inv_movement_type WHERE code = ? LIMIT 1');
            $stType->execute([$movementTypeCode]);
            $typeName = trim((string) ($stType->fetchColumn() ?: ''));
            if ($typeName === '') {
                $err = 'نوع الحركة غير موجود.';
            } else {
                $movementTypeLabel = $typeName;
            }
        }

        if ($err === '') {
            $showResult = true;
            $rows = inv_report_warehouse_moves_lines($pdo, $from, $to, $warehouseId, $movementTypeCode);
        }
    }
}

$docsCount = 0;
$qtyTotal = 0.0;
$qtyEffectTotal = 0.0;
if ($rows !== []) {
    $docSet = [];
    foreach ($rows as $r) {
        $docSet[(int) ($r['id'] ?? 0)] = true;
        $qtyTotal += (float) ($r['qty'] ?? 0);
        $qtyEffectTotal += (float) ($r['qty_effect'] ?? 0);
    }
    $docsCount = count($docSet);
}

$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');
$html2pdfPath = app_path('assets/js/html2pdf.bundle.min.js');
$html2pdfUrl = app_url('assets/js/html2pdf.bundle.min.js')
    . (is_file($html2pdfPath) ? '?v=' . (string) filemtime($html2pdfPath) : '');

$reportTitle = 'تقرير حركات المستودعات';
$pageDataAttrs = ' data-report-title="' . esc($reportTitle) . '"';
$pageDataAttrs .= ' data-report-route="report_warehouse_moves"';
if ($showResult && $err === '') {
    $pageDataAttrs .= ' data-export-label="' . esc($warehouseLabel . ' — ' . $movementTypeLabel) . '"';
    $pageDataAttrs .= ' data-from-dmy="' . esc(format_date_dmY($from)) . '"';
    $pageDataAttrs .= ' data-to-dmy="' . esc(format_date_dmY($to)) . '"';
}
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">

<div class="card report-sales-page report-warehouse-items-page"<?= $pageDataAttrs ?>>
    <?php if ($err !== ''): ?>
        <div class="alert alert-error no-print" style="margin-bottom:1rem;"><?= esc($err) ?></div>
    <?php endif; ?>

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print">
        <input type="hidden" name="r" value="report_warehouse_moves">
        <input type="hidden" name="run" value="1">
        <div class="form-row">
            <label class="field">
                <span class="field-label">من تاريخ *</span>
                <input class="input js-date-dmy" type="text" name="from"
                       value="<?= esc(format_date_dmY($from)) ?>"
                       placeholder="يوم-شهر-سنة" dir="ltr" autocomplete="off" inputmode="numeric" required>
            </label>
            <label class="field">
                <span class="field-label">إلى تاريخ *</span>
                <input class="input js-date-dmy" type="text" name="to"
                       value="<?= esc(format_date_dmY($to)) ?>"
                       placeholder="يوم-شهر-سنة" dir="ltr" autocomplete="off" inputmode="numeric" required>
            </label>
            <label class="field" style="flex:1 1 16rem;">
                <span class="field-label">المستودع</span>
                <select class="input" name="warehouse_id">
                    <option value="0">جميع المستودعات</option>
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
            <label class="field" style="flex:1 1 16rem;">
                <span class="field-label">نوع الحركة</span>
                <select class="input" name="movement_type_code">
                    <option value="0">جميع أنواع الحركات</option>
                    <?php foreach ($movementTypes as $type): ?>
                        <?php $code = (string) ($type['code'] ?? ''); ?>
                        <option value="<?= esc($code) ?>" <?= $movementTypeCode === $code ? 'selected' : '' ?>>
                            <?= esc((string) ($type['name_ar'] ?? $code)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <div style="margin-top:0.5rem;">
            <button class="btn btn-primary" type="submit">عرض التقرير</button>
        </div>
    </form>

    <?php if ($showResult && $err === ''): ?>
        <div class="report-sales-result report-sales-print-area report-warehouse-items-print">
            <?= document_print_header_html($reportTitle, $pdo) ?>

            <div class="doc-print-meta">
                <table>
                    <tr>
                        <td>
                            <strong>الفترة:</strong>
                            من <?= esc(format_date_dmY($from)) ?> إلى <?= esc(format_date_dmY($to)) ?>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <strong>المستودع:</strong> <?= esc($warehouseLabel) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>نوع الحركة:</strong> <?= esc($movementTypeLabel) ?>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <strong>عدد الحركات:</strong> <?= $docsCount ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>عدد السطور:</strong> <?= count($rows) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>إجمالي الكمية:</strong> <?= esc(format_amount($qtyTotal)) ?>
                            <?php if ($warehouseId > 0): ?>
                                &nbsp;&nbsp;|&nbsp;&nbsp;
                                <strong>صافي التأثير:</strong> <?= esc(format_amount($qtyEffectTotal)) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="report-sales-table-wrap">
                <table class="report-sales-table report-warehouse-items-table">
                    <thead>
                    <tr>
                        <th class="col-seq">#</th>
                        <th class="col-inv-no">رقم الحركة</th>
                        <th class="col-date">التاريخ</th>
                        <th>نوع الحركة</th>
                        <th>الاتجاه</th>
                        <th>المستودع</th>
                        <th>المستودع المستهدف</th>
                        <th class="col-item-name">المادة</th>
                        <th class="col-qty">الكمية</th>
                        <th>الحالة</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="10" class="muted" style="text-align:center;padding:1.25rem;">
                                لا توجد حركات مستودع مطابقة للفلاتر المحددة.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php $seq = 0; ?>
                        <?php foreach ($rows as $r): ?>
                            <?php
                            $seq++;
                            $moveUrl = app_url('index.php?r=warehouse_moves&id=' . (int) ($r['id'] ?? 0));
                            $itemNo = inv_item_material_number_digits(
                                (string) ($r['barcode'] ?? ''),
                                (string) ($r['sku'] ?? '')
                            );
                            ?>
                            <tr>
                                <td class="col-seq"><?= $seq ?></td>
                                <td class="col-inv-no">
                                    <code><?= esc((string) ($r['move_no'] ?? '')) ?></code>
                                    <a class="btn btn-ghost btn-sm no-print" style="padding:0 0.35rem;font-size:0.75rem;"
                                       href="<?= esc($moveUrl) ?>">عرض</a>
                                </td>
                                <td class="col-date" dir="ltr"><?= esc(format_date_dmY((string) ($r['move_date'] ?? ''))) ?></td>
                                <td><?= esc((string) ($r['movement_type_name'] ?? '')) ?></td>
                                <td><?= esc((string) ($r['direction_label'] ?? '—')) ?></td>
                                <td><?= esc((string) ($r['warehouse_name'] ?? '')) ?></td>
                                <td><?= esc((string) ($r['warehouse_to_name'] ?? '')) !== '' ? esc((string) $r['warehouse_to_name']) : '—' ?></td>
                                <td class="col-item-name">
                                    <code><?= esc($itemNo) ?></code>
                                    — <?= esc((string) ($r['item_name'] ?? '')) ?>
                                </td>
                                <td class="col-qty"><?= esc(format_amount((float) ($r['qty'] ?? 0))) ?></td>
                                <td><?= esc((string) ($r['status_label'] ?? '')) ?></td>
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
