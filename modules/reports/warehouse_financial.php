<?php
declare(strict_types=1);

require_once app_path('includes/inv_warehouse_financial_report.php');
require_once app_path('includes/document_header.php');

$pdo = db();

$warehouses = $pdo->query(
    'SELECT id, code, name_ar FROM inv_warehouse WHERE is_active = 1 ORDER BY name_ar'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$warehouseId = (int) ($_GET['warehouse_id'] ?? 0);
$includeZero = isset($_GET['include_zero']) && (string) $_GET['include_zero'] === '1';
$positiveQtyOnly = !$includeZero;

$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
if ($from === '') {
    $from = app_default_date_from();
}
if ($to === '') {
    $to = app_default_date_to();
}

$rows = [];
$warehouseLabel = '';
$showResult = false;
$err = '';

if (isset($_GET['run']) && (string) $_GET['run'] === '1') {
    if ($warehouseId < 1) {
        $err = 'اختر المستودع.';
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
                $rows = inv_report_warehouse_financial_lines($pdo, $warehouseId, $positiveQtyOnly, $to, $from);
            }
        }
    }
}

$totalQty = 0.0;
$totalValue = 0.0;
foreach ($rows as $r) {
    $totalQty += (float) $r['qty'];
    $totalValue += (float) $r['total_value'];
}

$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');
$html2pdfPath = app_path('assets/js/html2pdf.bundle.min.js');
$html2pdfUrl = app_url('assets/js/html2pdf.bundle.min.js')
    . (is_file($html2pdfPath) ? '?v=' . (string) filemtime($html2pdfPath) : '');

$reportTitle = 'تقرير أرصدة المستودع المالية';

$pageDataAttrs = ' data-report-title="' . esc($reportTitle) . '"';
$pageDataAttrs .= ' data-report-route="report_warehouse_financial"';
if ($showResult && $err === '') {
    $pageDataAttrs .= ' data-export-label="' . esc($warehouseLabel) . '"';
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
        <input type="hidden" name="r" value="report_warehouse_financial">
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
                <input type="checkbox" name="include_zero" value="1" <?= $includeZero ? 'checked' : '' ?>>
                <span style="margin-right:0.35rem;">شامل المواد ذات الرصيد صفر</span>
            </label>
        </div>
        <p class="muted no-print" style="margin:0.35rem 0 0;font-size:0.85rem;">
            الكميات حتى <strong>تاريخ النهاية</strong>؛ التكلفة = آخر شراء مرحّل حتى ذلك التاريخ.
            لمقارنة الميزان: نفس تاريخ النهاية، ومجموع القيم من <strong>كل المستودعات</strong> (الميزان يستخدم الإجمالي).
        </p>
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
                            من <?= esc(format_date_dmY($from)) ?>
                            إلى <?= esc(format_date_dmY($to)) ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>المستودع:</strong> <?= esc($warehouseLabel) ?></td>
                    </tr>
                    <tr>
                        <td><strong>عدد المواد:</strong> <?= count($rows) ?></td>
                    </tr>
                    <tr>
                        <td><strong>إجمالي قيمة المستودع:</strong> <?= esc(format_amount($totalValue)) ?></td>
                    </tr>
                </table>
            </div>

            <div class="report-sales-table-wrap">
                <table class="report-sales-table report-warehouse-items-table report-warehouse-financial-table">
                    <thead>
                    <tr>
                        <th class="col-seq">تسلسل</th>
                        <th class="col-inv-no">رقم المادة</th>
                        <th class="col-item-name">اسم المادة</th>
                        <th class="col-qty">الكمية</th>
                        <th class="col-unit">الوحدة</th>
                        <th class="col-price">التكلفة</th>
                        <th class="col-price">المجموع</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="7" class="muted" style="text-align:center;padding:1.25rem;">
                                لا توجد مواد ذات أرصدة في هذا المستودع.
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
                                <td class="col-unit"><?= esc((string) ($r['unit_name'] ?? '')) !== '' ? esc((string) $r['unit_name']) : '—' ?></td>
                                <td class="col-price"><?= esc(format_amount((float) ($r['cost_price'] ?? 0))) ?></td>
                                <td class="col-price"><?= esc(format_amount((float) ($r['total_value'] ?? 0))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                    <?php if ($rows): ?>
                    <tfoot>
                        <tr>
                            <td colspan="3" style="text-align:end;font-weight:700;">الإجمالي</td>
                            <td class="col-qty" style="font-weight:700;"><?= esc(format_amount($totalQty)) ?></td>
                            <td></td>
                            <td></td>
                            <td class="col-price" style="font-weight:700;"><?= esc(format_amount($totalValue)) ?></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<div id="sales-inv-export-host" class="sales-inv-export-host" aria-hidden="true"></div>

<script src="<?= esc($html2pdfUrl) ?>"></script>
<script src="<?= esc($exportJsUrl) ?>" defer></script>
