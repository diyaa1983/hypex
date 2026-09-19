<?php
declare(strict_types=1);

require_once app_path('includes/sal_customer_purchases_by_item_report.php');
require_once app_path('includes/document_header.php');
require_once app_path('includes/customer_picker.php');

$pdo = db();

$customers = crm_customers_for_picker($pdo, false);
$warehouses = $pdo->query(
    'SELECT id, code, name_ar FROM inv_warehouse WHERE is_active = 1 ORDER BY name_ar'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$customerId = isset($_GET['customer_id']) && $_GET['customer_id'] !== ''
    ? (int) $_GET['customer_id']
    : 0;
$warehouseId = isset($_GET['warehouse_id']) && $_GET['warehouse_id'] !== ''
    ? (int) $_GET['warehouse_id']
    : 0;
$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
$showDetails = !isset($_GET['summary_only']) || (string) $_GET['summary_only'] !== '1';

if ($from === '') {
    $from = app_default_date_from();
}
if ($to === '') {
    $to = app_default_date_to();
}

$report = null;
$customerName = '';
$warehouseLabel = 'جميع المستودعات';
$showResult = false;
$err = '';

$run = isset($_GET['run']) && (string) $_GET['run'] === '1';

if ($run) {
    if ($customerId < 1) {
        $err = 'اختر العميل.';
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

            $st = $pdo->prepare('SELECT name_ar, code FROM crm_customer WHERE id = ? LIMIT 1');
            $st->execute([$customerId]);
            $cust = $st->fetch(PDO::FETCH_ASSOC);
            if (!$cust) {
                $err = 'العميل غير موجود.';
            } else {
                $customerName = (string) ($cust['name_ar'] ?? '');
                $code = trim((string) ($cust['code'] ?? ''));
                if ($code !== '') {
                    $customerName .= ' (' . $code . ')';
                }
            }

            if ($err === '' && $warehouseId > 0) {
                $foundWh = false;
                foreach ($warehouses as $wh) {
                    if ((int) ($wh['id'] ?? 0) === $warehouseId) {
                        $warehouseLabel = (string) ($wh['name_ar'] ?? '');
                        $foundWh = true;
                        break;
                    }
                }
                if (!$foundWh) {
                    $err = 'المستودع غير موجود.';
                }
            }

            if ($err === '') {
                $showResult = true;
                $report = sal_report_customer_purchases_by_item($pdo, $customerId, $from, $to, $warehouseId);
            }
        }
    }
}

$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$invCssPath = app_path('assets/css/sales-invoice.css');
$invCssUrl = app_url('assets/css/sales-invoice.css') . (is_file($invCssPath) ? '?v=' . (string) filemtime($invCssPath) : '');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');

$reportTitle = 'تقرير مشتريات العميل حسب المادة';

$pageDataAttrs = ' data-report-title="' . esc($reportTitle) . '"';
$pageDataAttrs .= ' data-report-route="report_customer_purchases_by_item"';
if ($showResult) {
    $pageDataAttrs .= ' data-export-label="' . esc($customerName) . '"';
    $pageDataAttrs .= ' data-from-dmy="' . esc(format_date_dmY($from)) . '"';
    $pageDataAttrs .= ' data-to-dmy="' . esc(format_date_dmY($to)) . '"';
}
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($invCssUrl) ?>">
<?php
customer_picker_enqueue_assets();
customer_picker_json_script($customers, 'report-cust-purch-items-json');
?>

<div class="card report-sales-page report-cust-purch-item-page"<?= $pageDataAttrs ?>>

    <?php if ($err !== ''): ?>
        <div class="alert alert-error no-print" style="margin-bottom:1rem;"><?= esc($err) ?></div>
    <?php endif; ?>

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print">
        <input type="hidden" name="r" value="report_customer_purchases_by_item">
        <input type="hidden" name="run" value="1">
        <div class="form-row">
            <?= customer_picker_field([
                'id' => 'report_cust_purch_item_cust',
                'name' => 'customer_id',
                'value' => $customerId > 0 ? $customerId : '',
                'label' => 'العميل *',
                'wrapper_class' => 'field',
                'wrapper_style' => 'flex:1 1 16rem',
                'allow_all' => false,
                'json_id' => 'report-cust-purch-items-json',
            ]) ?>
            <label class="field" style="flex:1 1 12rem;">
                <span class="field-label">المستودع</span>
                <select class="input" name="warehouse_id">
                    <option value="0" <?= $warehouseId === 0 ? 'selected' : '' ?>>جميع المستودعات</option>
                    <?php foreach ($warehouses as $wh): ?>
                        <?php $wid = (int) ($wh['id'] ?? 0); ?>
                        <option value="<?= $wid ?>" <?= $warehouseId === $wid ? 'selected' : '' ?>>
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
            <label class="field" style="flex:0 0 auto;">
                <input type="checkbox" name="summary_only" value="1" <?= !$showDetails ? 'checked' : '' ?>>
                <span style="margin-right:0.35rem;">عرض الملخص فقط (بدون تفصيل الفواتير)</span>
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
                        <td><strong>العميل:</strong> <span class="doc-print-meta-value doc-print-meta-value--party"><?= esc($customerName) ?></span></td>
                    </tr>
                    <tr>
                        <td><strong>المستودع:</strong> <?= esc($warehouseLabel) ?></td>
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
                            <strong>عدد المواد:</strong> <?= (int) ($totals['item_count'] ?? 0) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>عدد البنود:</strong> <?= (int) ($totals['line_count'] ?? 0) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>إجمالي الكمية:</strong> <?= esc(format_amount((float) ($totals['qty'] ?? 0))) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>الإجمالي شامل الضريبة:</strong> <?= esc(format_money((float) ($totals['line_gross'] ?? 0))) ?>
                        </td>
                    </tr>
                </table>
            </div>

            <h3 style="margin:1rem 0 0.4rem;font-size:1.05rem;">ملخص المواد المشتراة</h3>
            <div class="report-sales-table-wrap">
                <table class="report-sales-table">
                    <thead>
                    <tr>
                        <th class="col-seq">#</th>
                        <th class="col-inv-no">رمز المادة</th>
                        <th class="col-item">اسم المادة</th>
                        <th class="col-qty">الكمية</th>
                        <th class="col-money">الإجمالي بدون ضريبة</th>
                        <th class="col-money">الإجمالي شامل الضريبة</th>
                        <th class="col-seq">عدد الفواتير</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($summary === []): ?>
                        <tr>
                            <td colspan="7" class="muted" style="text-align:center;padding:1.25rem;">
                                لا توجد مشتريات (فواتير بيع مرحّلة) لهذا العميل في الفترة المحددة.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php $seq = 0; ?>
                        <?php foreach ($summary as $r): ?>
                            <?php $seq++; ?>
                            <tr>
                                <td class="col-seq"><?= $seq ?></td>
                                <td class="col-inv-no"><code><?= esc((string) ($r['item_sku'] ?? '')) !== '' ? esc((string) $r['item_sku']) : '—' ?></code></td>
                                <td class="col-item"><?= esc((string) ($r['item_name'] ?? '')) ?></td>
                                <td class="col-qty num" dir="ltr"><?= esc(format_amount((float) ($r['qty'] ?? 0))) ?></td>
                                <td class="col-money num" dir="ltr"><?= esc(format_money((float) ($r['line_total'] ?? 0))) ?></td>
                                <td class="col-money num" dir="ltr"><?= esc(format_money((float) ($r['line_gross'] ?? 0))) ?></td>
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
                            <td class="num" dir="ltr"><strong><?= esc(format_money((float) ($totals['line_gross'] ?? 0))) ?></strong></td>
                            <td></td>
                        </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>

            <?php if ($showDetails && $details !== []): ?>
                <h3 style="margin:1.25rem 0 0.4rem;font-size:1.05rem;">تفاصيل الفواتير</h3>
                <div class="report-sales-table-wrap">
                    <table class="report-sales-table">
                        <thead>
                        <tr>
                            <th class="col-seq">#</th>
                            <th class="col-inv-no">رقم الفاتورة</th>
                            <th class="col-date">التاريخ</th>
                            <th class="col-rep">المستودع</th>
                            <th class="col-inv-no">رمز المادة</th>
                            <th class="col-item">اسم المادة</th>
                            <th class="col-qty">الكمية</th>
                            <th class="col-money">سعر الوحدة</th>
                            <th class="col-money">الإجمالي شامل</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php $seq = 0; ?>
                        <?php foreach ($details as $r): ?>
                            <?php
                            $seq++;
                            $invUrl = app_url('index.php?r=sales_invoices&id=' . (int) ($r['invoice_id'] ?? 0));
                            ?>
                            <tr>
                                <td class="col-seq"><?= $seq ?></td>
                                <td class="col-inv-no">
                                    <code><?= esc((string) ($r['invoice_no'] ?? '')) ?></code>
                                    <a class="btn btn-ghost btn-sm no-print" style="padding:0 0.35rem;font-size:0.75rem;"
                                       href="<?= esc($invUrl) ?>">عرض</a>
                                </td>
                                <td class="col-date" dir="ltr"><?= esc(format_date_dmY((string) ($r['invoice_date'] ?? ''))) ?></td>
                                <td class="col-rep"><?= esc((string) ($r['warehouse_name'] ?? '')) !== '' ? esc((string) $r['warehouse_name']) : '—' ?></td>
                                <td class="col-inv-no"><code><?= esc((string) ($r['item_sku'] ?? '')) !== '' ? esc((string) $r['item_sku']) : '—' ?></code></td>
                                <td class="col-item"><?= esc((string) ($r['item_name'] ?? '')) ?></td>
                                <td class="col-qty num" dir="ltr"><?= esc(format_amount((float) ($r['qty'] ?? 0))) ?></td>
                                <td class="col-money num" dir="ltr"><?= esc(format_money((float) ($r['unit_price'] ?? 0))) ?></td>
                                <td class="col-money num" dir="ltr"><?= esc(format_money((float) ($r['line_gross'] ?? 0))) ?></td>
                            </tr>
                        <?php endforeach; ?>
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
