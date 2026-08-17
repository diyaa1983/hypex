<?php
declare(strict_types=1);

require_once app_path('includes/sal_sales_detailed_report.php');
require_once app_path('includes/document_header.php');
require_once app_path('includes/customer_picker.php');
require_once app_path('includes/item_picker.php');
require_once app_path('includes/crm_region.php');
require_once app_path('includes/inv_item_schema.php');

$pdo = db();
crm_region_ensure_schema($pdo);
inv_item_ensure_extended_schema($pdo);

$customers = crm_customers_for_picker($pdo, true);
$reps = $pdo->query(
    'SELECT id, code, name_ar FROM crm_sales_rep WHERE is_active = 1 ORDER BY name_ar'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$regions = crm_region_load_active($pdo);
$categories = inv_item_load_categories($pdo);
$warehouses = $pdo->query(
    'SELECT id, code, name_ar FROM inv_warehouse WHERE is_active = 1 ORDER BY name_ar'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
if ($from === '') {
    $from = app_default_date_from();
}
if ($to === '') {
    $to = app_default_date_to();
}

$customerId = isset($_GET['customer_id']) && $_GET['customer_id'] !== ''
    ? (int) $_GET['customer_id']
    : 0;
$salesRepId = isset($_GET['sales_rep_id']) && $_GET['sales_rep_id'] !== ''
    ? (int) $_GET['sales_rep_id']
    : 0;
$regionId = isset($_GET['region_id']) && $_GET['region_id'] !== ''
    ? (int) $_GET['region_id']
    : 0;
$categoryId = isset($_GET['category_id']) && $_GET['category_id'] !== ''
    ? (int) $_GET['category_id']
    : 0;
$itemId = isset($_GET['item_id']) && $_GET['item_id'] !== ''
    ? (int) $_GET['item_id']
    : 0;
$warehouseId = isset($_GET['warehouse_id']) && $_GET['warehouse_id'] !== ''
    ? (int) $_GET['warehouse_id']
    : 0;
$paymentType = strtolower(trim((string) ($_GET['payment_type'] ?? '')));
$postedOnly = isset($_GET['posted_only']) && (string) $_GET['posted_only'] === '1';
$groupBy = sal_report_sales_detailed_normalize_group_by(trim((string) ($_GET['group_by'] ?? 'customer')));
$tab = (string) ($_GET['tab'] ?? 'summary') === 'detail' ? 'detail' : 'summary';

$report = null;
$showResult = false;
$err = '';
$run = isset($_GET['run']) && (string) $_GET['run'] === '1';

if ($run) {
    $fromIso = parse_date_to_iso($from);
    $toIso = parse_date_to_iso($to);
    if ($fromIso === null || $toIso === null) {
        $err = 'تاريخ البداية والنهاية غير صالحين (يوم-شهر-سنة).';
    } elseif ($fromIso > $toIso) {
        $err = 'تاريخ البداية يجب أن يكون قبل أو يساوي تاريخ النهاية.';
    } else {
        $from = $fromIso;
        $to = $toIso;
        $showResult = true;
        $report = sal_report_sales_detailed($pdo, [
            'from' => $from,
            'to' => $to,
            'customer_id' => $customerId,
            'sales_rep_id' => $salesRepId,
            'region_id' => $regionId,
            'category_id' => $categoryId,
            'item_id' => $itemId,
            'warehouse_id' => $warehouseId,
            'payment_type' => $paymentType,
            'posted_only' => $postedOnly,
            'group_by' => $groupBy,
        ]);
    }
}

$itemLabel = '';
if ($itemId > 0) {
    $stItem = $pdo->prepare('SELECT name_ar, sku, barcode FROM inv_item WHERE id = ? LIMIT 1');
    $stItem->execute([$itemId]);
    $it = $stItem->fetch(PDO::FETCH_ASSOC);
    if ($it) {
        $code = trim((string) (($it['barcode'] ?? '') !== '' ? $it['barcode'] : ($it['sku'] ?? '')));
        $itemLabel = ($code !== '' ? $code . ' — ' : '') . (string) ($it['name_ar'] ?? '');
    }
}

$reportTitle = 'تقرير المبيعات التفصيلي';
$groupLabel = sal_report_sales_detailed_group_label($groupBy);

$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$invCssPath = app_path('assets/css/sales-invoice.css');
$invCssUrl = app_url('assets/css/sales-invoice.css') . (is_file($invCssPath) ? '?v=' . (string) filemtime($invCssPath) : '');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');

$queryBase = static function (string $tabMode) use (
    $from,
    $to,
    $customerId,
    $salesRepId,
    $regionId,
    $categoryId,
    $itemId,
    $warehouseId,
    $paymentType,
    $postedOnly,
    $groupBy
): string {
    $q = [
        'r' => 'report_sales_detailed',
        'run' => '1',
        'tab' => $tabMode,
        'from' => format_date_dmY($from),
        'to' => format_date_dmY($to),
        'customer_id' => (string) $customerId,
        'sales_rep_id' => (string) $salesRepId,
        'region_id' => (string) $regionId,
        'category_id' => (string) $categoryId,
        'item_id' => (string) $itemId,
        'warehouse_id' => (string) $warehouseId,
        'group_by' => $groupBy,
    ];
    if ($paymentType !== '') {
        $q['payment_type'] = $paymentType;
    }
    if ($postedOnly) {
        $q['posted_only'] = '1';
    }

    return app_url('index.php?' . http_build_query($q));
};

$pageDataAttrs = ' data-report-title="' . esc($reportTitle) . '"';
$pageDataAttrs .= ' data-report-route="report_sales_detailed"';
if ($showResult && $report !== null) {
    $pageDataAttrs .= ' data-from-dmy="' . esc(format_date_dmY($from)) . '"';
    $pageDataAttrs .= ' data-to-dmy="' . esc(format_date_dmY($to)) . '"';
}
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($invCssUrl) ?>">
<?php
customer_picker_enqueue_assets();
customer_picker_json_script($customers, 'report-sales-detailed-customers-json');
item_picker_enqueue_assets();
?>

<div class="card report-sales-page report-sales-detailed-page"<?= $pageDataAttrs ?>>

    <?php if ($err !== ''): ?>
        <div class="alert alert-error no-print" style="margin-bottom:1rem;"><?= esc($err) ?></div>
    <?php endif; ?>

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print">
        <input type="hidden" name="r" value="report_sales_detailed">
        <input type="hidden" name="run" value="1">
        <input type="hidden" name="tab" value="<?= esc($tab) ?>">

        <div class="form-row">
            <?= customer_picker_field([
                'id' => 'report_sales_detailed_cust',
                'name' => 'customer_id',
                'value' => $customerId,
                'label' => 'العميل',
                'wrapper_class' => 'field',
                'wrapper_style' => 'flex:1 1 14rem',
                'allow_all' => true,
                'json_id' => 'report-sales-detailed-customers-json',
            ]) ?>

            <label class="field" style="flex:1 1 11rem;">
                <span class="field-label">المندوب</span>
                <select class="input" name="sales_rep_id">
                    <option value="0" <?= $salesRepId === 0 ? 'selected' : '' ?>>جميع المندوبين</option>
                    <?php foreach ($reps as $rep): ?>
                        <option value="<?= (int) $rep['id'] ?>" <?= $salesRepId === (int) $rep['id'] ? 'selected' : '' ?>>
                            <?= esc((string) ($rep['name_ar'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="field" style="flex:1 1 11rem;">
                <span class="field-label">المنطقة</span>
                <select class="input" name="region_id">
                    <option value="0" <?= $regionId === 0 ? 'selected' : '' ?>>جميع المناطق</option>
                    <?php foreach ($regions as $rg): ?>
                        <option value="<?= (int) $rg['id'] ?>" <?= $regionId === (int) $rg['id'] ? 'selected' : '' ?>>
                            <?= esc((string) ($rg['name_ar'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="field" style="flex:1 1 11rem;">
                <span class="field-label">فئة المادة</span>
                <select class="input" name="category_id">
                    <option value="0" <?= $categoryId === 0 ? 'selected' : '' ?>>جميع الفئات</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= (int) $cat['id'] ?>" <?= $categoryId === (int) $cat['id'] ? 'selected' : '' ?>>
                            <?= esc((string) ($cat['name_ar'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <div class="form-row" style="margin-top:0.35rem;">
            <?= item_picker_single_field([
                'id' => 'report-detailed-item-id',
                'name' => 'item_id',
                'value' => $itemId,
                'display_text' => $itemLabel,
                'label' => 'المادة',
                'placeholder' => 'جميع المواد — اضغط للاختيار',
                'allow_all' => true,
                'all_label' => 'جميع المواد',
                'wrapper_style' => 'flex:1 1 16rem',
            ]) ?>

            <label class="field" style="flex:1 1 11rem;">
                <span class="field-label">المستودع</span>
                <select class="input" name="warehouse_id">
                    <option value="0" <?= $warehouseId === 0 ? 'selected' : '' ?>>جميع المستودعات</option>
                    <?php foreach ($warehouses as $wh): ?>
                        <option value="<?= (int) $wh['id'] ?>" <?= $warehouseId === (int) $wh['id'] ? 'selected' : '' ?>>
                            <?= esc((string) ($wh['name_ar'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

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

        <div class="form-row" style="margin-top:0.35rem;align-items:center;">
            <label class="field" style="flex:1 1 10rem;">
                <span class="field-label">نوع الدفع</span>
                <select class="input" name="payment_type">
                    <option value="" <?= $paymentType === '' ? 'selected' : '' ?>>الكل</option>
                    <option value="cash" <?= $paymentType === 'cash' ? 'selected' : '' ?>>نقد</option>
                    <option value="credit" <?= $paymentType === 'credit' ? 'selected' : '' ?>>آجل</option>
                </select>
            </label>

            <label class="field" style="flex:1 1 12rem;">
                <span class="field-label">تجميع الملخص</span>
                <select class="input" name="group_by">
                    <?php foreach (['customer', 'sales_rep', 'region', 'category', 'item', 'invoice_date', 'warehouse', 'payment_type'] as $gb): ?>
                        <option value="<?= esc($gb) ?>" <?= $groupBy === $gb ? 'selected' : '' ?>>
                            <?= esc(sal_report_sales_detailed_group_label($gb)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="field" style="flex:0 0 auto;">
                <input type="checkbox" name="posted_only" value="1" <?= $postedOnly ? 'checked' : '' ?>>
                <span style="margin-right:0.35rem;">مرحّل فقط</span>
            </label>
        </div>

        <div style="margin-top:0.5rem;">
            <button class="btn btn-primary" type="submit">عرض التقرير</button>
        </div>
    </form>

    <?php if ($showResult && $report !== null): ?>
        <?php
        $totals = $report['totals'];
        $summary = $report['summary'];
        $details = $report['details'];
        ?>
        <div class="report-sales-result report-sales-print-area">
            <?= document_print_header_html($reportTitle, $pdo) ?>

            <div class="doc-print-meta">
                <table>
                    <tr>
                        <td>
                            <strong>من تاريخ:</strong> <?= esc(format_date_dmY($from)) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>إلى تاريخ:</strong> <?= esc(format_date_dmY($to)) ?>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <strong>عدد الفواتير:</strong> <?= (int) ($totals['invoice_count'] ?? 0) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>عدد البنود:</strong> <?= (int) ($totals['line_count'] ?? 0) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>الكمية:</strong> <?= esc(format_amount((float) ($totals['qty'] ?? 0))) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>الإجمالي شامل:</strong> <?= esc(format_money((float) ($totals['line_gross'] ?? 0))) ?>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="no-print" style="display:flex;gap:.4rem;flex-wrap:wrap;margin:0.75rem 0;">
                <a class="btn <?= $tab === 'summary' ? 'btn-primary' : 'btn-secondary' ?>"
                   href="<?= esc($queryBase('summary')) ?>">ملخص</a>
                <a class="btn <?= $tab === 'detail' ? 'btn-primary' : 'btn-secondary' ?>"
                   href="<?= esc($queryBase('detail')) ?>">تفصيل البنود</a>
                <button type="button" class="btn btn-secondary" onclick="window.print()">🖨 طباعة</button>
            </div>

            <?php if ($tab === 'summary'): ?>
                <h3 style="margin:0.5rem 0 0.4rem;font-size:1.05rem;">ملخص حسب <?= esc($groupLabel) ?></h3>
                <div class="report-sales-table-wrap">
                    <table class="report-sales-table">
                        <thead>
                        <tr>
                            <th class="col-seq">#</th>
                            <th><?= esc($groupLabel) ?></th>
                            <th class="col-inv-no">الرمز</th>
                            <th class="col-qty">الكمية</th>
                            <th class="col-money">بدون ضريبة</th>
                            <th class="col-money">الضريبة</th>
                            <th class="col-money">شامل الضريبة</th>
                            <th class="col-seq">بنود</th>
                            <th class="col-seq">فواتير</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($summary === []): ?>
                            <tr><td colspan="9" class="muted" style="text-align:center;padding:1.25rem;">لا توجد مبيعات في الفترة المحددة.</td></tr>
                        <?php else: ?>
                            <?php $seq = 0; ?>
                            <?php foreach ($summary as $r): ?>
                                <?php $seq++; ?>
                                <tr>
                                    <td class="col-seq"><?= $seq ?></td>
                                    <td><?= esc((string) ($r['label'] ?? '')) ?></td>
                                    <td class="col-inv-no"><code><?= esc((string) (($r['code'] ?? '') !== '' ? $r['code'] : '—')) ?></code></td>
                                    <td class="col-qty num" dir="ltr"><?= esc(format_amount((float) ($r['qty'] ?? 0))) ?></td>
                                    <td class="col-money num" dir="ltr"><?= esc(format_money((float) ($r['line_total'] ?? 0))) ?></td>
                                    <td class="col-money num" dir="ltr"><?= esc(format_money((float) ($r['tax_amount'] ?? 0))) ?></td>
                                    <td class="col-money num" dir="ltr"><?= esc(format_money((float) ($r['line_gross'] ?? 0))) ?></td>
                                    <td class="col-seq"><?= (int) ($r['line_count'] ?? 0) ?></td>
                                    <td class="col-seq"><?= (int) ($r['invoice_count'] ?? 0) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                        <?php if ($summary !== []): ?>
                            <tfoot>
                            <tr>
                                <td colspan="3"><strong>الإجمالي</strong></td>
                                <td class="num" dir="ltr"><strong><?= esc(format_amount((float) ($totals['qty'] ?? 0))) ?></strong></td>
                                <td class="num" dir="ltr"><strong><?= esc(format_money((float) ($totals['line_total'] ?? 0))) ?></strong></td>
                                <td class="num" dir="ltr"><strong><?= esc(format_money((float) ($totals['tax_amount'] ?? 0))) ?></strong></td>
                                <td class="num" dir="ltr"><strong><?= esc(format_money((float) ($totals['line_gross'] ?? 0))) ?></strong></td>
                                <td colspan="2"></td>
                            </tr>
                            </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            <?php else: ?>
                <h3 style="margin:0.5rem 0 0.4rem;font-size:1.05rem;">تفصيل بنود الفواتير</h3>
                <div class="report-sales-table-wrap">
                    <table class="report-sales-table">
                        <thead>
                        <tr>
                            <th class="col-seq">#</th>
                            <th class="col-inv-no">فاتورة</th>
                            <th class="col-date">التاريخ</th>
                            <th>العميل</th>
                            <th>المندوب</th>
                            <th>المنطقة</th>
                            <th class="col-inv-no">المادة</th>
                            <th>اسم المادة</th>
                            <th>الفئة</th>
                            <th>المستودع</th>
                            <th class="col-qty">الكمية</th>
                            <th class="col-money">السعر</th>
                            <th class="col-money">بدون ض.</th>
                            <th class="col-money">شامل</th>
                            <th>دفع</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($details === []): ?>
                            <tr><td colspan="15" class="muted" style="text-align:center;padding:1.25rem;">لا توجد بنود في الفترة المحددة.</td></tr>
                        <?php else: ?>
                            <?php $seq = 0; ?>
                            <?php foreach ($details as $r): ?>
                                <?php $seq++; ?>
                                <tr>
                                    <td class="col-seq"><?= $seq ?></td>
                                    <td class="col-inv-no"><code><?= esc((string) ($r['invoice_no'] ?? '')) ?></code></td>
                                    <td class="col-date" dir="ltr"><?= esc(format_date_dmY((string) ($r['invoice_date'] ?? ''))) ?></td>
                                    <td><?= esc((string) ($r['customer_name'] ?? '')) ?></td>
                                    <td><?= esc((string) (($r['sales_rep_name'] ?? '') !== '' ? $r['sales_rep_name'] : '—')) ?></td>
                                    <td><?= esc((string) (($r['region_name'] ?? '') !== '' ? $r['region_name'] : '—')) ?></td>
                                    <td class="col-inv-no"><code><?= esc((string) (($r['item_sku'] ?? '') !== '' ? $r['item_sku'] : '—')) ?></code></td>
                                    <td><?= esc((string) ($r['item_name'] ?? '')) ?></td>
                                    <td><?= esc((string) (($r['category_name'] ?? '') !== '' ? $r['category_name'] : '—')) ?></td>
                                    <td><?= esc((string) (($r['warehouse_name'] ?? '') !== '' ? $r['warehouse_name'] : '—')) ?></td>
                                    <td class="col-qty num" dir="ltr"><?= esc(format_amount((float) ($r['qty'] ?? 0))) ?></td>
                                    <td class="col-money num" dir="ltr"><?= esc(format_money((float) ($r['unit_price'] ?? 0))) ?></td>
                                    <td class="col-money num" dir="ltr"><?= esc(format_money((float) ($r['line_total'] ?? 0))) ?></td>
                                    <td class="col-money num" dir="ltr"><?= esc(format_money((float) ($r['line_gross'] ?? 0))) ?></td>
                                    <td><?= esc(sal_report_sales_detailed_payment_label((string) ($r['payment_type'] ?? ''))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<div id="sales-inv-export-host" class="sales-inv-export-host" aria-hidden="true"></div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" defer crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="<?= esc($exportJsUrl) ?>" defer></script>
