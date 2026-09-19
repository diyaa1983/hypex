<?php
declare(strict_types=1);

require_once app_path('includes/sal_receivables_report.php');
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
$mode = strtolower(trim((string) ($_GET['mode'] ?? 'detail')));
if ($mode !== 'summary') {
    $mode = 'detail';
}

$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
if ($from === '') {
    $from = app_default_date_from();
}
if ($to === '') {
    $to = app_default_date_to();
}

$reportTitle = 'كشف ذمم العملاء';
$routeKey = 'report_receivables';
$showResult = false;
$err = '';
$built = [
    'mode' => $mode,
    'detail_rows' => [],
    'detail_groups' => [],
    'summary_rows' => [],
    'totals' => [
        'sales_total' => 0.0,
        'sales_total_all' => 0.0,
        'collections_total' => 0.0,
        'balance_due' => 0.0,
        'collection_pct' => '—',
        'balance_pct' => '—',
        'invoice_count' => 0,
        'customer_count' => 0,
    ],
];

$submitted = isset($_GET['run']);

if ($submitted) {
    $fromIso = parse_date_to_iso($from);
    $toIso = parse_date_to_iso($to);
    if ($fromIso === null || $toIso === null) {
        $err = 'تاريخ البداية والنهاية غير صالحين (يوم-شهر-سنة).';
    } elseif ($fromIso > $toIso) {
        $err = 'تاريخ البداية يجب أن يكون قبل أو يساوي تاريخ النهاية.';
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
        $from = $fromIso;
        $to = $toIso;
        $showResult = true;
        $built = sal_report_receivables_build($pdo, [
            'customer_id' => $customerId,
            'sales_rep_id' => $salesRepId,
            'from' => $from,
            'to' => $to,
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

$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$invCssPath = app_path('assets/css/sales-invoice.css');
$invCssUrl = app_url('assets/css/sales-invoice.css') . (is_file($invCssPath) ? '?v=' . (string) filemtime($invCssPath) : '');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');

$pageDataAttrs = ' data-report-title="' . esc($reportTitle) . '"';
$pageDataAttrs .= ' data-report-route="' . esc($routeKey) . '"';
$pageDataAttrs .= ' data-receivables-mode="' . esc($mode) . '"';
if ($showResult) {
    $pageDataAttrs .= ' data-export-label="' . esc($modeLabel . '-' . $customerLabel) . '"';
    $pageDataAttrs .= ' data-from-dmy="' . esc(format_date_dmY($from)) . '"';
    $pageDataAttrs .= ' data-to-dmy="' . esc(format_date_dmY($to)) . '"';
}

$totals = $built['totals'];
$receivablesTotalTdStyle = 'background-color:#e2e8f0;-webkit-print-color-adjust:exact;print-color-adjust:exact;';

$renderGrandTotal = static function (array $totals, string $to, bool $withPct = false, int $summaryLeadColspan = 0) use ($receivablesTotalTdStyle): void {
    $summaryAlign = $summaryLeadColspan > 0;
    $tableClass = 'report-sales-table report-receivables-table report-receivables-grand-total-table'
        . ($summaryAlign ? ' report-receivables-grand-total-table--summary' : '');
    ?>
    <div class="report-sales-table-wrap report-receivables-grand-total-wrap">
        <table class="<?= esc($tableClass) ?>">
            <?php if ($summaryAlign && $summaryLeadColspan === 3): ?>
            <colgroup>
                <col class="col-seq">
                <col class="col-customer">
                <col class="col-rep">
                <col class="col-money">
                <col class="col-money">
                <col class="col-pct">
                <col class="col-money">
                <col class="col-pct">
            </colgroup>
            <?php endif; ?>
            <thead>
            <tr>
                <?php if ($summaryAlign): ?>
                    <th colspan="<?= (int) $summaryLeadColspan ?>" class="report-receivables-total-label">البيان</th>
                <?php else: ?>
                    <th>البيان</th>
                <?php endif; ?>
                <th class="col-money">مجموع المبيعات</th>
                <?php if ($withPct): ?>
                    <th class="col-money">مجموع التحصيل</th>
                    <th class="col-pct">نسبة التحصيل</th>
                <?php endif; ?>
                <th class="col-money report-receivables-col-balance">الذمم المستحقة</th>
                <?php if ($withPct): ?>
                    <th class="col-pct">نسبة الذمم</th>
                <?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php
            $salesAll = (float) ($totals['sales_total_all'] ?? 0);
            $salesReceivable = (float) ($totals['sales_total'] ?? 0);
            $eps = crm_party_statement_amount_epsilon();
            $labelColspan = $summaryAlign ? ' colspan="' . (int) $summaryLeadColspan . '"' : '';
            ?>
            <tr class="report-sales-tfoot">
                <td<?= $labelColspan ?> class="report-receivables-total-cell report-receivables-total-label" style="<?= esc($receivablesTotalTdStyle) ?>">
                    <strong>إجمالي عملاء الذمة — حتى <?= esc(format_date_dmY($to)) ?></strong>
                </td>
                <td class="col-money report-receivables-total-cell" style="<?= esc($receivablesTotalTdStyle) ?>">
                    <strong><?= esc(format_amount($salesReceivable)) ?></strong>
                </td>
                <?php if ($withPct): ?>
                    <td class="col-money report-receivables-total-cell" style="<?= esc($receivablesTotalTdStyle) ?>">
                        <strong><?= esc(format_amount((float) ($totals['collections_total'] ?? 0))) ?></strong>
                    </td>
                    <td class="col-pct report-receivables-total-cell" style="<?= esc($receivablesTotalTdStyle) ?>">
                        <strong><?= esc((string) ($totals['collection_pct'] ?? '—')) ?></strong>
                    </td>
                <?php endif; ?>
                <td class="col-money report-receivables-total-cell" style="<?= esc($receivablesTotalTdStyle) ?>">
                    <strong><?= esc(format_amount((float) ($totals['balance_due'] ?? 0))) ?></strong>
                </td>
                <?php if ($withPct): ?>
                    <td class="col-pct report-receivables-total-cell" style="<?= esc($receivablesTotalTdStyle) ?>">
                        <strong><?= esc((string) ($totals['balance_pct'] ?? '—')) ?></strong>
                    </td>
                <?php endif; ?>
            </tr>
            <?php if ($salesAll > $eps): ?>
            <tr class="report-sales-tfoot report-receivables-sales-all-row">
                <td<?= $labelColspan ?> class="report-receivables-total-cell report-receivables-total-label" style="<?= esc($receivablesTotalTdStyle) ?>">
                    <strong>مبيعات الفترة — جميع العملاء</strong>
                    <span class="report-receivables-sub-hint">(يطابق تقرير المبيعات)</span>
                </td>
                <td class="col-money report-receivables-total-cell" style="<?= esc($receivablesTotalTdStyle) ?>">
                    <strong><?= esc(format_amount($salesAll)) ?></strong>
                </td>
                <?php if ($withPct): ?>
                    <td class="col-money report-receivables-total-cell" style="<?= esc($receivablesTotalTdStyle) ?>">—</td>
                    <td class="col-pct report-receivables-total-cell" style="<?= esc($receivablesTotalTdStyle) ?>">—</td>
                <?php endif; ?>
                <td class="col-money report-receivables-total-cell" style="<?= esc($receivablesTotalTdStyle) ?>">—</td>
                <?php if ($withPct): ?>
                    <td class="col-pct report-receivables-total-cell" style="<?= esc($receivablesTotalTdStyle) ?>">—</td>
                <?php endif; ?>
            </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
};

/**
 * @param array<string, mixed> $metrics
 */
$renderCustomerMetrics = static function (array $metrics) use ($receivablesTotalTdStyle): void {
    ?>
    <div class="report-sales-table-wrap report-receivables-customer-metrics-wrap">
        <table class="report-sales-table report-receivables-table report-receivables-metrics-table">
            <thead>
            <tr>
                <th class="col-money">مجموع المبيعات</th>
                <th class="col-money">مجموع التحصيل</th>
                <th class="col-pct">نسبة التحصيل</th>
                <th class="col-money report-receivables-col-balance">الذمم المستحقة</th>
                <th class="col-pct">نسبة الذمم</th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td class="col-money report-receivables-total-cell" style="<?= esc($receivablesTotalTdStyle) ?>">
                    <strong><?= esc(format_amount((float) ($metrics['sales_total'] ?? 0))) ?></strong>
                </td>
                <td class="col-money report-receivables-total-cell" style="<?= esc($receivablesTotalTdStyle) ?>">
                    <strong><?= esc(format_amount((float) ($metrics['collections_total'] ?? 0))) ?></strong>
                </td>
                <td class="col-pct report-receivables-total-cell" style="<?= esc($receivablesTotalTdStyle) ?>">
                    <strong><?= esc((string) ($metrics['collection_pct'] ?? '—')) ?></strong>
                </td>
                <td class="col-money report-receivables-total-cell" style="<?= esc($receivablesTotalTdStyle) ?>">
                    <strong><?= esc(format_amount((float) ($metrics['balance_due'] ?? 0))) ?></strong>
                </td>
                <td class="col-pct report-receivables-total-cell" style="<?= esc($receivablesTotalTdStyle) ?>">
                    <strong><?= esc((string) ($metrics['balance_pct'] ?? '—')) ?></strong>
                </td>
            </tr>
            </tbody>
        </table>
    </div>
    <?php
};
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($invCssUrl) ?>">
<?php customer_picker_enqueue_assets(); ?>
<?php customer_picker_json_script($customers, 'report-receivables-customers-json'); ?>

<div class="card report-sales-page report-receivables-page"<?= $pageDataAttrs ?>>

    <?php if ($err !== ''): ?>
        <div class="alert alert-error no-print" style="margin-bottom:1rem;"><?= esc($err) ?></div>
    <?php endif; ?>

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print">
        <input type="hidden" name="r" value="report_receivables">
        <input type="hidden" name="run" value="1">
        <div class="form-row">
            <?= customer_picker_field([
                'id' => 'report_receivables_cust',
                'name' => 'customer_id',
                'value' => $customerId,
                'label' => 'العميل',
                'wrapper_class' => 'field',
                'wrapper_style' => 'flex:1 1 16rem',
                'allow_all' => true,
                'json_id' => 'report-receivables-customers-json',
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
        <div class="form-row" style="margin-top:0.35rem;">
            <span class="field-label" style="align-self:center;margin-left:0.5rem;">نوع العرض</span>
            <label class="field" style="flex:0 0 auto;">
                <input type="radio" name="mode" value="detail" <?= $mode === 'detail' ? 'checked' : '' ?>>
                تفصيلي
            </label>
            <label class="field" style="flex:0 0 auto;">
                <input type="radio" name="mode" value="summary" <?= $mode === 'summary' ? 'checked' : '' ?>>
                إجمالي
            </label>
        </div>
        <div style="margin-top:0.5rem;">
            <button class="btn btn-primary" type="submit">عرض التقرير</button>
        </div>
    </form>

    <?php if ($showResult): ?>
        <div class="report-sales-result report-sales-print-area report-receivables-print<?= $mode === 'summary' ? ' report-receivables-print--summary' : '' ?>">
            <?= document_print_header_html($reportTitle, $pdo) ?>

            <div class="doc-print-meta">
                <table>
                    <tr>
                        <td><strong>نوع العرض:</strong> <?= esc($modeLabel) ?></td>
                    </tr>
                    <tr>
                        <td><strong>العميل:</strong> <span class="doc-print-meta-value doc-print-meta-value--party"><?= esc($customerLabel) ?></span></td>
                    </tr>
                    <tr>
                        <td><strong>المندوب:</strong> <?= esc($repLabel) ?></td>
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
                            <strong>عدد العملاء:</strong> <?= (int) ($totals['customer_count'] ?? 0) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>عدد فواتير البيع (تفصيلي):</strong> <?= (int) ($totals['invoice_count'] ?? 0) ?>
                        </td>
                    </tr>
                </table>
            </div>

            <?php if ($mode === 'summary'): ?>
            <div class="report-sales-table-wrap report-receivables-summary-table-wrap">
                    <table class="report-sales-table report-receivables-table report-receivables-summary-table">
                        <colgroup>
                            <col class="col-seq">
                            <col class="col-customer">
                            <col class="col-rep">
                            <col class="col-money">
                            <col class="col-money">
                            <col class="col-pct">
                            <col class="col-money">
                            <col class="col-pct">
                        </colgroup>
                        <thead>
                        <tr>
                            <th class="col-seq">#</th>
                            <th class="col-customer">اسم العميل</th>
                            <th class="col-rep">المندوب</th>
                            <th class="col-money">مجموع المبيعات</th>
                            <th class="col-money">مجموع التحصيل</th>
                            <th class="col-pct">نسبة التحصيل</th>
                            <th class="col-money report-receivables-col-balance">الذمم المستحقة</th>
                            <th class="col-pct">نسبة الذمم</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!$built['summary_rows']): ?>
                            <tr>
                                <td colspan="8" class="muted" style="text-align:center;padding:1.25rem;">
                                    لا توجد بيانات للفترة والفلاتر المحددة.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php $seq = 0; ?>
                            <?php foreach ($built['summary_rows'] as $row): ?>
                                <?php $seq++; ?>
                                <tr class="report-receivables-customer-row">
                                    <td class="col-seq"><?= $seq ?></td>
                                    <td class="col-customer"><span class="report-receivables-party-name"><?= esc((string) ($row['customer_name'] ?? '')) ?></span></td>
                                    <td class="col-rep"><span class="report-receivables-party-name report-receivables-rep-name"><?= esc((string) ($row['sales_rep_name'] ?? 'غير معروف')) ?></span></td>
                                    <td class="col-money"><?= esc(format_amount((float) ($row['sales_total'] ?? 0))) ?></td>
                                    <td class="col-money"><?= esc(format_amount((float) ($row['collections_total'] ?? 0))) ?></td>
                                    <td class="col-pct"><?= esc((string) ($row['collection_pct'] ?? '—')) ?></td>
                                    <td class="col-money report-receivables-customer-balance"><strong><?= esc(format_amount((float) ($row['balance_due'] ?? 0))) ?></strong></td>
                                    <td class="col-pct"><?= esc((string) ($row['balance_pct'] ?? '—')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
            </div>
                    <?php if ($built['summary_rows'] || (float) ($totals['sales_total_all'] ?? 0) > crm_party_statement_amount_epsilon()): ?>
                        <?php $renderGrandTotal($totals, $to, true, 3); ?>
                    <?php endif; ?>
            <?php else: ?>
                    <div class="report-receivables-detail-groups">
                        <?php if (!$built['detail_groups']): ?>
                            <p class="muted" style="text-align:center;padding:1.25rem;">
                                لا توجد حركات ذمم في الفترة المحددة.
                            </p>
                        <?php else: ?>
                            <?php foreach ($built['detail_groups'] as $grp): ?>
                                <section class="report-receivables-customer-block">
                                    <h3 class="report-receivables-customer-title">
                                        <?= esc((string) ($grp['customer_display'] ?? sal_report_receivables_customer_with_rep(
                                            (string) ($grp['customer_name'] ?? ''),
                                            (string) ($grp['sales_rep_name'] ?? 'غير معروف')
                                        ))) ?>
                                    </h3>
                                    <div class="report-sales-table-wrap">
                                        <table class="report-sales-table party-stmt-table report-receivables-table report-receivables-customer-table">
                                            <thead>
                                            <tr>
                                                <th class="col-date">التاريخ</th>
                                                <th class="col-desc">الوصف</th>
                                                <th class="col-doc">الرقم</th>
                                                <th class="col-money">مدين</th>
                                                <th class="col-money">دائن</th>
                                                <th class="col-money report-receivables-col-balance">الرصيد / الذمم</th>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach ($grp['rows'] as $row): ?>
                                                <?php
                                                $rowType = (string) ($row['row_type'] ?? '');
                                                if ($rowType === 'customer_subtotal'):
                                                    $invCnt = (int) ($row['invoice_count'] ?? 0);
                                                    ?>
                                                    <tr class="report-sales-group-total report-receivables-customer-total">
                                                        <td colspan="3" class="report-receivables-total-cell" style="<?= esc($receivablesTotalTdStyle) ?>"><strong>إجمالي ذمم العميل</strong><?= $invCnt > 0 ? ' <span class="report-receivables-sub-hint">(' . $invCnt . ' فاتورة ذمم في الفترة)</span>' : '' ?></td>
                                                        <td class="col-money report-receivables-total-cell" style="<?= esc($receivablesTotalTdStyle) ?>"><strong><?= (float) ($row['debit'] ?? 0) > 0 ? esc(format_money((float) $row['debit'])) : '—' ?></strong></td>
                                                        <td class="col-money report-receivables-total-cell" style="<?= esc($receivablesTotalTdStyle) ?>"><strong><?= (float) ($row['credit'] ?? 0) > 0 ? esc(format_money((float) $row['credit'])) : '—' ?></strong></td>
                                                        <td class="col-money report-receivables-customer-balance report-receivables-total-cell" style="<?= esc($receivablesTotalTdStyle) ?>"><strong><?= esc(format_money((float) ($row['balance'] ?? 0))) ?></strong></td>
                                                    </tr>
                                                <?php elseif ($rowType === 'opening'): ?>
                                                    <tr class="party-stmt-opening">
                                                        <td class="col-date"><?= esc(format_date_dmY((string) ($row['date'] ?? ''))) ?></td>
                                                        <td colspan="2"><em><?= esc((string) ($row['description'] ?? 'رصيد افتتاحي')) ?></em></td>
                                                        <td class="col-money"><?= (float) ($row['debit'] ?? 0) > 0 ? esc(format_money((float) $row['debit'])) : '—' ?></td>
                                                        <td class="col-money"><?= (float) ($row['credit'] ?? 0) > 0 ? esc(format_money((float) $row['credit'])) : '—' ?></td>
                                                        <td class="col-money"><strong><?= esc(format_money((float) ($row['balance'] ?? 0))) ?></strong></td>
                                                    </tr>
                                                <?php else: ?>
                                                    <tr>
                                                        <td class="col-date"><?= esc(format_date_dmY((string) ($row['date'] ?? ''))) ?></td>
                                                        <td class="col-desc"><?= esc((string) ($row['description'] ?? '')) ?></td>
                                                        <td class="col-doc"><?= esc((string) ($row['ref_no'] ?? '')) ?></td>
                                                        <td class="col-money"><?= (float) ($row['debit'] ?? 0) > 0 ? esc(format_money((float) $row['debit'])) : '—' ?></td>
                                                        <td class="col-money"><?= (float) ($row['credit'] ?? 0) > 0 ? esc(format_money((float) $row['credit'])) : '—' ?></td>
                                                        <td class="col-money"><?= esc(format_money((float) ($row['balance'] ?? 0))) ?></td>
                                                    </tr>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php
                                    $grpMetrics = $grp['metrics'] ?? [];
                                    if (is_array($grpMetrics) && $grpMetrics !== []) {
                                        $renderCustomerMetrics($grpMetrics);
                                    }
                                    ?>
                                </section>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <?php if ($built['summary_rows'] || (float) ($totals['sales_total_all'] ?? 0) > crm_party_statement_amount_epsilon()): ?>
                        <section class="report-receivables-detail-grand-section" aria-label="إجمالي التقرير">
                            <?php $renderGrandTotal($totals, $to, true); ?>
                        </section>
                    <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script src="<?= esc($exportJsUrl) ?>" defer></script>
