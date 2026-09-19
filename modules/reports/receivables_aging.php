<?php
declare(strict_types=1);

require_once app_path('includes/sal_receivables_aging_report.php');
require_once app_path('includes/document_header.php');
require_once app_path('includes/crm_sales_rep_schema.php');
require_once app_path('includes/customer_picker.php');

$pdo = db();
crm_sales_rep_ensure_schema($pdo);
crm_ledger_ensure_schema($pdo);

$customers = $pdo->query(
    'SELECT id, code, name_ar FROM crm_customer WHERE is_active = 1 ORDER BY name_ar'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$reps = $pdo->query(
    'SELECT id, code, name_ar FROM crm_sales_rep WHERE is_active = 1 ORDER BY name_ar'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$customerId = (int) ($_GET['customer_id'] ?? 0);
$salesRepId = (int) ($_GET['sales_rep_id'] ?? 0);
$mode = strtolower(trim((string) ($_GET['mode'] ?? 'summary')));
if ($mode !== 'detail') {
    $mode = 'summary';
}

$asOf = trim((string) ($_GET['as_of'] ?? ''));
if ($asOf === '') {
    $asOf = date('Y-m-d');
}

$reportTitle = 'أعمار الذمم';
$routeKey = 'report_receivables_aging';
$bucketLabels = sal_receivables_aging_bucket_labels();
$showResult = false;
$err = '';
$built = sal_report_receivables_aging_build($pdo, [
    'customer_id' => 0,
    'sales_rep_id' => 0,
    'as_of' => '',
    'mode' => $mode,
]);

if (isset($_GET['run'])) {
    $asOfIso = parse_date_to_iso($asOf);
    if ($asOfIso === null) {
        $err = 'تاريخ التقرير غير صالح (يوم-شهر-سنة).';
    } elseif ($customerId > 0) {
        $st = $pdo->prepare('SELECT id FROM crm_customer WHERE id = ? AND is_active = 1 LIMIT 1');
        $st->execute([$customerId]);
        if (!$st->fetch()) {
            $err = 'العميل غير موجود.';
        }
    }

    if ($err === '' && $salesRepId > 0) {
        $st = $pdo->prepare('SELECT id FROM crm_sales_rep WHERE id = ? AND is_active = 1 LIMIT 1');
        $st->execute([$salesRepId]);
        if (!$st->fetch()) {
            $err = 'المندوب غير موجود.';
        }
    }

    if ($err === '') {
        $asOf = $asOfIso;
        $showResult = true;
        $built = sal_report_receivables_aging_build($pdo, [
            'customer_id' => $customerId,
            'sales_rep_id' => $salesRepId,
            'as_of' => $asOf,
            'mode' => $mode,
        ]);
        $mode = $built['mode'];
    }
}

$customerLabel = 'جميع العملاء';
if ($customerId > 0) {
    foreach ($customers as $c) {
        if ((int) ($c['id'] ?? 0) === $customerId) {
            $customerLabel = (string) ($c['name_ar'] ?? '');
            if (($c['code'] ?? '') !== '') {
                $customerLabel .= ' (' . $c['code'] . ')';
            }
            break;
        }
    }
}

$repLabel = 'جميع المندوبين';
if ($salesRepId > 0) {
    foreach ($reps as $r) {
        if ((int) ($r['id'] ?? 0) === $salesRepId) {
            $repLabel = (string) ($r['name_ar'] ?? '');
            break;
        }
    }
}

$modeLabel = $mode === 'summary' ? 'إجمالي' : 'تفصيلي';
$totals = $built['totals'];

$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$invCssPath = app_path('assets/css/sales-invoice.css');
$invCssUrl = app_url('assets/css/sales-invoice.css') . (is_file($invCssPath) ? '?v=' . (string) filemtime($invCssPath) : '');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');
$html2pdfPath = app_path('assets/js/html2pdf.bundle.min.js');
$html2pdfUrl = app_url('assets/js/html2pdf.bundle.min.js')
    . (is_file($html2pdfPath) ? '?v=' . (string) filemtime($html2pdfPath) : '');

$pageDataAttrs = ' data-report-title="' . esc($reportTitle) . '"';
$pageDataAttrs .= ' data-report-route="' . esc($routeKey) . '"';
$pageDataAttrs .= ' data-receivables-mode="' . esc($mode) . '"';
$pageDataAttrs .= ' data-receivables-aging-mode="' . esc($mode) . '"';
if ($showResult) {
    $pageDataAttrs .= ' data-export-label="' . esc($modeLabel . '-' . $customerLabel) . '"';
    $pageDataAttrs .= ' data-as-of-dmy="' . esc(format_date_dmY($asOf)) . '"';
}

$totalTdStyle = 'background-color:#e2e8f0;-webkit-print-color-adjust:exact;print-color-adjust:exact;';
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($invCssUrl) ?>">
<?php customer_picker_enqueue_assets(); ?>
<?php customer_picker_json_script($customers, 'report-receivables-aging-customers-json'); ?>

<div class="card report-sales-page report-receivables-page report-receivables-aging-page"<?= $pageDataAttrs ?>>

    <?php if ($err !== ''): ?>
        <div class="alert alert-error no-print" style="margin-bottom:1rem;"><?= esc($err) ?></div>
    <?php endif; ?>

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print">
        <input type="hidden" name="r" value="report_receivables_aging">
        <input type="hidden" name="run" value="1">
        <div class="form-row">
            <?= customer_picker_field([
                'id' => 'report_receivables_aging_cust',
                'name' => 'customer_id',
                'value' => $customerId,
                'label' => 'العميل',
                'wrapper_class' => 'field',
                'wrapper_style' => 'flex:1 1 16rem',
                'allow_all' => true,
                'json_id' => 'report-receivables-aging-customers-json',
            ]) ?>
            <label class="field" style="flex:1 1 14rem;">
                <span class="field-label">المندوب</span>
                <select class="input" name="sales_rep_id">
                    <option value="0" <?= $salesRepId === 0 ? 'selected' : '' ?>>جميع المندوبين</option>
                    <?php foreach ($reps as $r): ?>
                        <option value="<?= (int) $r['id'] ?>" <?= (int) $r['id'] === $salesRepId ? 'selected' : '' ?>>
                            <?= esc((string) ($r['name_ar'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span class="field-label">حتى تاريخ *</span>
                <input class="input js-date-dmy" type="text" name="as_of" value="<?= esc(format_date_dmY($asOf)) ?>"
                       placeholder="يوم-شهر-سنة" dir="ltr" autocomplete="off" inputmode="numeric" required>
            </label>
        </div>
        <div class="form-row" style="margin-top:0.35rem;">
            <span class="field-label" style="align-self:center;margin-left:0.5rem;">نوع العرض</span>
            <label class="field" style="flex:0 0 auto;">
                <input type="radio" name="mode" value="summary" <?= $mode === 'summary' ? 'checked' : '' ?>>
                إجمالي
            </label>
            <label class="field" style="flex:0 0 auto;">
                <input type="radio" name="mode" value="detail" <?= $mode === 'detail' ? 'checked' : '' ?>>
                تفصيلي
            </label>
        </div>
        <p class="muted no-print" style="margin:0.35rem 0 0;font-size:0.9rem;">
            يُحسب عمر الذمة من تاريخ الفاتورة/الحركة حتى تاريخ التقرير، مع خصم التحصيلات والمرتجعات (FIFO).
            عمود «الذمم المستحقة» يطابق كشف ذمم العملاء لنفس التاريخ.
        </p>
        <div style="margin-top:0.5rem;">
            <button class="btn btn-primary" type="submit">عرض التقرير</button>
        </div>
    </form>

    <?php if ($showResult): ?>
        <div class="report-sales-result report-sales-print-area report-receivables-print report-receivables-aging-print<?= $mode === 'summary' ? ' report-receivables-print--summary' : '' ?>">
            <?= document_print_header_html($reportTitle, $pdo) ?>

            <div class="party-stmt-report-head">
                <p class="party-stmt-report-customer"><?= esc($customerLabel) ?></p>
                <p class="party-stmt-report-dates">
                    <span>حتى تاريخ: <?= esc(format_date_dmY($asOf)) ?></span>
                    <span class="party-stmt-report-dates-sep">|</span>
                    <span>نوع العرض: <?= esc($modeLabel) ?></span>
                    <span class="party-stmt-report-dates-sep">|</span>
                    <span>المندوب: <?= esc($repLabel) ?></span>
                    <span class="party-stmt-report-dates-sep">|</span>
                    <span>عدد العملاء: <?= (int) ($built['customer_count'] ?? 0) ?></span>
                </p>
            </div>

            <?php if ($mode === 'summary'): ?>
                <div class="report-sales-table-wrap report-receivables-summary-table-wrap">
                    <table class="report-sales-table report-receivables-table report-receivables-summary-table report-receivables-aging-summary-table">
                        <colgroup>
                            <col class="col-seq">
                            <col class="col-customer">
                            <col class="col-rep">
                            <col class="col-money">
                            <col class="col-money">
                            <col class="col-money">
                            <col class="col-money">
                            <col class="col-money">
                        </colgroup>
                        <thead>
                        <tr>
                            <th class="col-seq">#</th>
                            <th class="col-customer">اسم العميل</th>
                            <th class="col-rep">المندوب</th>
                            <th class="col-money"><?= esc($bucketLabels['d0_30']) ?></th>
                            <th class="col-money"><?= esc($bucketLabels['d31_60']) ?></th>
                            <th class="col-money"><?= esc($bucketLabels['d61_90']) ?></th>
                            <th class="col-money"><?= esc($bucketLabels['d90_plus']) ?></th>
                            <th class="col-money report-receivables-col-balance">الذمم المستحقة</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!$built['summary_rows']): ?>
                            <tr>
                                <td colspan="8" class="muted" style="text-align:center;padding:1.25rem;">
                                    لا توجد ذمم مستحقة للتاريخ والفلاتر المحددة.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php $seq = 0; ?>
                            <?php foreach ($built['summary_rows'] as $row): ?>
                                <?php $seq++; ?>
                                <tr class="report-receivables-customer-row">
                                    <td class="col-seq"><?= $seq ?></td>
                                    <td class="col-customer">
                                        <span class="report-receivables-party-name"><?= esc((string) ($row['customer_name'] ?? '')) ?></span>
                                    </td>
                                    <td class="col-rep">
                                        <span class="report-receivables-rep-name"><?= esc((string) ($row['sales_rep_name'] ?? 'غير معروف')) ?></span>
                                    </td>
                                    <td class="col-money"><?= (float) ($row['d0_30'] ?? 0) > 0 ? esc(format_amount((float) $row['d0_30'])) : '—' ?></td>
                                    <td class="col-money"><?= (float) ($row['d31_60'] ?? 0) > 0 ? esc(format_amount((float) $row['d31_60'])) : '—' ?></td>
                                    <td class="col-money"><?= (float) ($row['d61_90'] ?? 0) > 0 ? esc(format_amount((float) $row['d61_90'])) : '—' ?></td>
                                    <td class="col-money"><?= (float) ($row['d90_plus'] ?? 0) > 0 ? esc(format_amount((float) $row['d90_plus'])) : '—' ?></td>
                                    <td class="col-money report-receivables-customer-balance"><strong><?= esc(format_amount((float) ($row['total'] ?? 0))) ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                        <?php if ($built['summary_rows']): ?>
                        <tfoot>
                        <tr class="report-sales-tfoot">
                            <td colspan="3" class="report-receivables-total-cell" style="<?= esc($totalTdStyle) ?>"><strong>الإجمالي</strong></td>
                            <td class="col-money report-receivables-total-cell" style="<?= esc($totalTdStyle) ?>"><strong><?= esc(format_amount((float) ($totals['d0_30'] ?? 0))) ?></strong></td>
                            <td class="col-money report-receivables-total-cell" style="<?= esc($totalTdStyle) ?>"><strong><?= esc(format_amount((float) ($totals['d31_60'] ?? 0))) ?></strong></td>
                            <td class="col-money report-receivables-total-cell" style="<?= esc($totalTdStyle) ?>"><strong><?= esc(format_amount((float) ($totals['d61_90'] ?? 0))) ?></strong></td>
                            <td class="col-money report-receivables-total-cell" style="<?= esc($totalTdStyle) ?>"><strong><?= esc(format_amount((float) ($totals['d90_plus'] ?? 0))) ?></strong></td>
                            <td class="col-money report-receivables-total-cell" style="<?= esc($totalTdStyle) ?>"><strong><?= esc(format_amount((float) ($totals['balance_due'] ?? $totals['total'] ?? 0))) ?></strong></td>
                        </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            <?php else: ?>
                <div class="report-receivables-detail-groups">
                    <?php if (!$built['detail_groups']): ?>
                        <p class="muted" style="text-align:center;padding:1.25rem;">لا توجد ذمم مستحقة.</p>
                    <?php else: ?>
                        <?php foreach ($built['detail_groups'] as $grp): ?>
                            <section class="report-receivables-customer-block">
                                <h3 class="report-receivables-customer-title"><?= esc((string) ($grp['customer_display'] ?? '')) ?></h3>
                                <div class="report-sales-table-wrap">
                                    <table class="report-sales-table party-stmt-table report-receivables-table report-receivables-aging-detail-table">
                                        <colgroup>
                                            <col class="col-date">
                                            <col class="col-doc">
                                            <col class="col-desc">
                                            <col class="col-seq">
                                            <col>
                                            <col class="col-money">
                                        </colgroup>
                                        <thead>
                                        <tr>
                                            <th class="col-date">التاريخ</th>
                                            <th class="col-doc">الرقم</th>
                                            <th class="col-desc">البيان</th>
                                            <th class="col-seq">العمر (يوم)</th>
                                            <th>الفترة</th>
                                            <th class="col-money report-receivables-col-balance">المبلغ المستحق</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($grp['lines'] as $ln): ?>
                                            <tr>
                                                <td class="col-date"><?= esc(format_date_dmY((string) ($ln['date'] ?? ''))) ?></td>
                                                <td class="col-doc"><?= esc((string) ($ln['ref_no'] ?? '')) ?></td>
                                                <td class="col-desc"><?= esc((string) ($ln['description'] ?? '')) ?></td>
                                                <td class="col-seq"><?= (int) ($ln['days'] ?? 0) ?></td>
                                                <td><?= esc((string) ($ln['bucket_label'] ?? '')) ?></td>
                                                <td class="col-money"><?= esc(format_amount((float) ($ln['amount'] ?? 0))) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                        <tfoot>
                                        <tr class="report-sales-tfoot report-receivables-customer-total">
                                            <td colspan="5" class="report-receivables-total-cell" style="<?= esc($totalTdStyle) ?>"><strong>ذمم العميل المستحقة</strong></td>
                                            <td class="col-money report-receivables-total-cell" style="<?= esc($totalTdStyle) ?>"><strong><?= esc(format_amount((float) ($grp['total'] ?? 0))) ?></strong></td>
                                        </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </section>
                        <?php endforeach; ?>
                        <?php if ($built['detail_groups']): ?>
                        <div class="report-sales-table-wrap report-receivables-grand-total-wrap" style="margin-top:1rem;">
                            <table class="report-sales-table report-receivables-table report-receivables-aging-summary-table">
                                <thead>
                                <tr>
                                    <th colspan="3">الإجمالي العام — ذمم مستحقة</th>
                                    <th class="col-money"><?= esc($bucketLabels['d0_30']) ?></th>
                                    <th class="col-money"><?= esc($bucketLabels['d31_60']) ?></th>
                                    <th class="col-money"><?= esc($bucketLabels['d61_90']) ?></th>
                                    <th class="col-money"><?= esc($bucketLabels['d90_plus']) ?></th>
                                    <th class="col-money report-receivables-col-balance">الذمم المستحقة</th>
                                </tr>
                                </thead>
                                <tbody>
                                <tr class="report-sales-tfoot">
                                    <td colspan="3" class="report-receivables-total-cell" style="<?= esc($totalTdStyle) ?>"><strong>جميع العملاء</strong></td>
                                    <td class="col-money report-receivables-total-cell" style="<?= esc($totalTdStyle) ?>"><strong><?= esc(format_amount((float) ($totals['d0_30'] ?? 0))) ?></strong></td>
                                    <td class="col-money report-receivables-total-cell" style="<?= esc($totalTdStyle) ?>"><strong><?= esc(format_amount((float) ($totals['d31_60'] ?? 0))) ?></strong></td>
                                    <td class="col-money report-receivables-total-cell" style="<?= esc($totalTdStyle) ?>"><strong><?= esc(format_amount((float) ($totals['d61_90'] ?? 0))) ?></strong></td>
                                    <td class="col-money report-receivables-total-cell" style="<?= esc($totalTdStyle) ?>"><strong><?= esc(format_amount((float) ($totals['d90_plus'] ?? 0))) ?></strong></td>
                                    <td class="col-money report-receivables-total-cell" style="<?= esc($totalTdStyle) ?>"><strong><?= esc(format_amount((float) ($totals['balance_due'] ?? $totals['total'] ?? 0))) ?></strong></td>
                                </tr>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<div id="sales-inv-export-host" class="sales-inv-export-host" aria-hidden="true"></div>

<script src="<?= esc($html2pdfUrl) ?>"></script>
<script src="<?= esc($exportJsUrl) ?>" defer></script>
