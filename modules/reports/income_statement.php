<?php
declare(strict_types=1);

require_once app_path('includes/acc_report.php');
require_once app_path('includes/acc_account_tree.php');
require_once app_path('includes/document_header.php');

$pdo = db();
acc_gl_ensure_schema($pdo);

$dateFrom = parse_date_to_iso(trim((string) ($_GET['date_from'] ?? ''))) ?? app_default_date_from();
$dateTo = parse_date_to_iso(trim((string) ($_GET['date_to'] ?? ''))) ?? app_default_date_to();
$view = (string) ($_GET['view'] ?? 'summary') === 'detailed' ? 'detailed' : 'summary';
$includeMovements = $view === 'detailed' && ($_GET['movements'] ?? '0') === '1';

if ($dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

if ($view === 'detailed') {
    $pl = acc_report_income_statement_detailed($pdo, $dateFrom, $dateTo, $includeMovements);
} else {
    $pl = acc_report_income_statement_summary($pdo, $dateFrom, $dateTo);
}

$viewLabel = $view === 'detailed' ? 'تفصيلي' : 'ملخص';
$reportTitle = 'قائمة الدخل — ' . $viewLabel;

$salesCssPath = app_path('assets/css/report-sales.css');
$salesCssUrl = app_url('assets/css/report-sales.css') . (is_file($salesCssPath) ? '?v=' . (string) filemtime($salesCssPath) : '');
$accCssPath = app_path('assets/css/report-acc.css');
$accCssUrl = app_url('assets/css/report-acc.css') . (is_file($accCssPath) ? '?v=' . (string) filemtime($accCssPath) : '');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');

$pageDataAttrs = ' class="card report-sales-page party-stmt-page report-income-statement-page"'
    . ' data-report-title="' . esc($reportTitle) . '"'
    . ' data-report-route="report_income_statement"'
    . ' data-export-label="' . esc($viewLabel) . '"'
    . ' data-from-dmy="' . esc(format_date_dmY($dateFrom)) . '"'
    . ' data-to-dmy="' . esc(format_date_dmY($dateTo)) . '"';

$queryBase = static function (string $viewMode, bool $withMovements = false) use ($dateFrom, $dateTo): string {
    $q = 'r=report_income_statement&view=' . rawurlencode($viewMode)
        . '&date_from=' . rawurlencode(format_date_dmY($dateFrom))
        . '&date_to=' . rawurlencode(format_date_dmY($dateTo));
    if ($viewMode === 'detailed' && $withMovements) {
        $q .= '&movements=1';
    }

    return $q;
};

$renderSummaryAccountRows = static function (array $rows, string $dateFrom, string $dateTo, bool $asDeduction = false) use ($pl): void {
    foreach ($rows as $r) {
        $aid = (int) ($r['id'] ?? 0);
        $rawAmt = (float) ($r['amount'] ?? 0);
        $displayAmt = $asDeduction ? abs($rawAmt) : abs($rawAmt);
        $isInfo = !empty($r['inventory_info']) && !empty($pl['uses_inventory']);
        ?>
        <tr<?= $isInfo ? ' class="pl-info-row"' : '' ?>>
            <td class="col-seq"><?= (int) ($r['line_no'] ?? 0) ?></td>
            <td class="col-desc">
                <code class="pl-acc-code"><?= esc(acc_account_format_code((string) ($r['code'] ?? ''))) ?></code>
                <?= esc((string) ($r['name_ar'] ?? '')) ?>
                <?php if ($isInfo): ?>
                    <span class="muted pl-info-tag">(معلومات — المخزون)</span>
                <?php endif; ?>
            </td>
            <td class="col-money">
                <?php if ($asDeduction && $displayAmt > 0.000001): ?>
                    <span class="pl-deduction-amount">(<?= esc(format_money($displayAmt)) ?>)</span>
                <?php else: ?>
                    <?= esc(format_money($displayAmt)) ?>
                <?php endif; ?>
            </td>
            <td class="col-pct">—</td>
            <td class="no-print col-act">
                <?php if ($aid > 0 && empty($r['is_synthetic'])): ?>
                    <a class="btn btn-secondary btn-sm" href="<?= esc(acc_report_general_ledger_url($aid, $dateFrom, $dateTo)) ?>">دفتر</a>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }
};

$renderPlLines = static function (array $sectionLines) use ($dateFrom, $dateTo): void {
    foreach ($sectionLines as $row) {
        $kind = (string) ($row['kind'] ?? '');
        if ($kind === 'movement') {
            $refType = (string) ($row['ref_type'] ?? '');
            $refId = (int) ($row['ref_id'] ?? 0);
            $refUrl = ($row['source'] ?? '') === 'auto' && $refType !== '' && $refId > 0
                ? acc_report_ref_url($refType, $refId)
                : null;
            $jid = (int) ($row['journal_id'] ?? 0);
            ?>
            <tr class="pl-movement-row">
                <td class="col-seq muted"><?= (int) ($row['line_no'] ?? 0) ?></td>
                <td class="col-desc">
                    <span class="pl-movement-marker">↳</span>
                    <?= esc(format_date_dmY((string) ($row['entry_date'] ?? ''))) ?>
                    — <code><?= esc((string) ($row['entry_no'] ?? '')) ?></code>
                    <?php if ((string) ($row['description_ar'] ?? '') !== ''): ?>
                        · <?= esc((string) $row['description_ar']) ?>
                    <?php endif; ?>
                    <?php if ((string) ($row['memo'] ?? '') !== ''): ?>
                        <span class="muted"> (<?= esc((string) $row['memo']) ?>)</span>
                    <?php endif; ?>
                </td>
                <td class="col-money"><?= (float) ($row['debit'] ?? 0) > 0 ? esc(format_money((float) $row['debit'])) : '—' ?></td>
                <td class="col-money"><?= (float) ($row['credit'] ?? 0) > 0 ? esc(format_money((float) $row['credit'])) : '—' ?></td>
                <td class="col-money">—</td>
                <td class="col-pct">—</td>
                <td class="no-print col-act">
                    <?php if ($jid > 0): ?>
                        <a class="btn btn-secondary btn-sm" href="<?= esc(acc_report_journal_voucher_url($jid, (string) ($row['entry_no'] ?? ''))) ?>">قيد</a>
                    <?php endif; ?>
                    <?php if ($refUrl): ?>
                        <a class="btn btn-secondary btn-sm" href="<?= esc($refUrl) ?>">مستند</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php
            continue;
        }

        $isGroup = $kind === 'group';
        $aid = (int) ($row['id'] ?? 0);
        $pct = $row['pct'] ?? null;
        ?>
        <tr class="<?= $isGroup ? 'pl-group-row' : '' ?>">
            <td class="col-seq"><?= (int) ($row['line_no'] ?? 0) ?></td>
            <td class="col-desc">
                <code class="pl-acc-code"><?= esc(acc_account_format_code((string) ($row['code'] ?? ''))) ?></code>
                <?= esc((string) ($row['name_ar'] ?? '')) ?>
            </td>
            <td class="col-money"><?= (float) ($row['debit'] ?? 0) > 0 ? esc(format_money((float) $row['debit'])) : '—' ?></td>
            <td class="col-money"><?= (float) ($row['credit'] ?? 0) > 0 ? esc(format_money((float) $row['credit'])) : '—' ?></td>
            <td class="col-money"><strong><?= esc(format_money((float) ($row['net'] ?? 0))) ?></strong></td>
            <td class="col-pct"><?= $pct !== null ? esc(number_format((float) $pct, 2)) . '%' : '—' ?></td>
            <td class="no-print col-act">
                <?php if ($aid > 0): ?>
                    <a class="btn btn-secondary btn-sm" href="<?= esc(acc_report_general_ledger_url($aid, $dateFrom, $dateTo)) ?>">دفتر</a>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }
};
?>
<link rel="stylesheet" href="<?= esc($salesCssUrl) ?>">
<link rel="stylesheet" href="<?= esc($accCssUrl) ?>">
<style><?= document_print_header_css() ?></style>

<div<?= $pageDataAttrs ?>>
    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print" id="pl-filter-form">
        <input type="hidden" name="r" value="report_income_statement">
        <div class="form-row">
            <label class="field">
                <span class="field-label">من تاريخ</span>
                <input class="input js-date-dmy" type="text" name="date_from" value="<?= esc(format_date_dmY($dateFrom)) ?>" dir="ltr" autocomplete="off">
            </label>
            <label class="field">
                <span class="field-label">إلى تاريخ</span>
                <input class="input js-date-dmy" type="text" name="date_to" value="<?= esc(format_date_dmY($dateTo)) ?>" dir="ltr" autocomplete="off">
            </label>
            <label class="field">
                <span class="field-label">نوع التقرير</span>
                <select class="input" name="view" id="pl-view-select">
                    <option value="summary"<?= $view === 'summary' ? ' selected' : '' ?>>ملخص</option>
                    <option value="detailed"<?= $view === 'detailed' ? ' selected' : '' ?>>تفصيلي</option>
                </select>
            </label>
            <label class="field pl-movements-field" id="pl-movements-field"<?= $view !== 'detailed' ? ' hidden' : '' ?>>
                <span class="field-label">&nbsp;</span>
                <label style="display:flex;align-items:center;gap:0.4rem;cursor:pointer;min-height:2.25rem;">
                    <input type="hidden" name="movements" value="0">
                    <input type="checkbox" name="movements" value="1"<?= $includeMovements ? ' checked' : '' ?>>
                    تفاصيل القيود
                </label>
            </label>
        </div>
        <div style="margin-top:0.5rem;display:flex;flex-wrap:wrap;gap:0.5rem;align-items:center;">
            <button type="submit" class="btn btn-primary btn-sm">عرض التقرير</button>
            <button type="button" class="btn btn-secondary btn-sm no-print" id="pl-print-btn" title="أو استخدم زر طباعة في الشريط العلوي">طباعة</button>
            <?php if ($view === 'summary'): ?>
                <a class="btn btn-secondary btn-sm" href="<?= esc(app_url('index.php?' . $queryBase('detailed'))) ?>">عرض تفصيلي</a>
            <?php else: ?>
                <a class="btn btn-secondary btn-sm" href="<?= esc(app_url('index.php?' . $queryBase('summary'))) ?>">عرض ملخص</a>
            <?php endif; ?>
        </div>
    </form>

    <div class="report-sales-result report-sales-print-area">
        <?= document_print_header_html($reportTitle, $pdo) ?>

        <div class="party-stmt-report-head">
            <p class="party-stmt-report-dates">
                <span>من تاريخ: <?= esc(format_date_dmY($dateFrom)) ?></span>
                <span class="party-stmt-report-dates-sep">|</span>
                <span>إلى تاريخ: <?= esc(format_date_dmY($dateTo)) ?></span>
                <span class="party-stmt-report-dates-sep">|</span>
                <span>العرض: <?= esc($viewLabel) ?></span>
            </p>
        </div>

        <div class="report-sales-table-wrap">
            <?php if ($view === 'summary'): ?>
            <table class="data-table report-sales-table pl-report-table">
                <thead>
                <tr>
                    <th class="col-seq">م</th>
                    <th class="col-desc">البيان</th>
                    <th class="col-money">المبلغ</th>
                    <th class="col-pct">% من الإيرادات</th>
                    <th class="no-print col-act">إجراء</th>
                </tr>
                </thead>
                <tbody>
                <tr class="pl-section-row">
                    <td></td>
                    <td colspan="4"><strong>الإيرادات</strong></td>
                </tr>
                <?php if (!$pl['revenue_rows']): ?>
                    <tr><td colspan="5" class="muted" style="text-align:center;padding:1rem;">لا إيرادات في الفترة.</td></tr>
                <?php endif; ?>
                <?php foreach ($pl['revenue_rows'] as $r):
                    $aid = (int) ($r['id'] ?? 0);
                    $amt = (float) ($r['amount'] ?? 0);
                    ?>
                    <tr>
                        <td class="col-seq"><?= (int) ($r['line_no'] ?? 0) ?></td>
                        <td class="col-desc">
                            <code class="pl-acc-code"><?= esc(acc_account_format_code((string) ($r['code'] ?? ''))) ?></code>
                            <?= esc((string) ($r['name_ar'] ?? '')) ?>
                        </td>
                        <td class="col-money"><?= esc(format_money($amt)) ?></td>
                        <td class="col-pct"><?= isset($r['pct']) && $r['pct'] !== null ? esc(number_format((float) $r['pct'], 2)) . '%' : '—' ?></td>
                        <td class="no-print col-act">
                            <a class="btn btn-secondary btn-sm" href="<?= esc(acc_report_general_ledger_url($aid, $dateFrom, $dateTo)) ?>">دفتر</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr class="pl-subtotal-row">
                    <td></td>
                    <td class="col-desc"><strong>إجمالي الإيرادات</strong></td>
                    <td class="col-money"><strong><?= esc(format_money($pl['total_revenue'])) ?></strong></td>
                    <td class="col-pct"><strong>100%</strong></td>
                    <td class="no-print"></td>
                </tr>

                <?php
                $purchaseRows = $pl['purchase_rows'] ?? [];
                $purchaseReturnRows = $pl['purchase_return_rows'] ?? [];
                $cogsRows = $pl['cogs_rows'] ?? [];
                $operatingRows = $pl['operating_rows'] ?? [];
                $usesInventory = !empty($pl['uses_inventory']);
                $totalPurchases = (float) ($pl['total_purchases'] ?? 0);
                $totalPurchaseReturns = (float) ($pl['total_purchase_returns'] ?? 0);
                $totalCogs = (float) ($pl['total_cogs'] ?? 0);
                $totalCostOfSales = (float) ($pl['total_cost_of_sales'] ?? 0);
                $grossProfit = (float) ($pl['gross_profit'] ?? 0);
                $totalOperating = (float) ($pl['total_operating'] ?? 0);
                $grossPct = $pl['total_revenue'] > 0.000001
                    ? round(($grossProfit / $pl['total_revenue']) * 100, 2)
                    : null;
                $hasCostSection = $totalPurchases > 0.0005
                    || $totalPurchaseReturns > 0.0005
                    || $totalCogs > 0.0005
                    || $cogsRows !== []
                    || $purchaseRows !== [];
                ?>

                <?php if ($hasCostSection): ?>
                <tr class="pl-section-row">
                    <td></td>
                    <td colspan="4">
                        <strong>تكلفة المبيعات</strong>
                        <?php if ($usesInventory && $totalPurchases > 0.0005): ?>
                            <span class="muted"> — المشتريات تُسجّل في المخزون</span>
                        <?php endif; ?>
                    </td>
                </tr>

                <?php if ($purchaseRows !== [] || $totalPurchases > 0.0005): ?>
                <tr class="pl-subsection-row">
                    <td></td>
                    <td colspan="4"><strong>المشتريات</strong></td>
                </tr>
                <?php if (!$purchaseRows): ?>
                    <tr><td colspan="5" class="muted" style="text-align:center;padding:0.75rem;">لا مشتريات في الفترة.</td></tr>
                <?php else: ?>
                    <?php $renderSummaryAccountRows($purchaseRows, $dateFrom, $dateTo); ?>
                <?php endif; ?>
                <?php if ($totalPurchases > 0.0005): ?>
                <tr class="pl-subtotal-row">
                    <td></td>
                    <td class="col-desc"><strong>إجمالي المشتريات</strong></td>
                    <td class="col-money"><strong><?= esc(format_money($totalPurchases)) ?></strong></td>
                    <td class="col-pct">—</td>
                    <td class="no-print"></td>
                </tr>
                <?php endif; ?>
                <?php endif; ?>

                <?php if ($purchaseReturnRows !== [] || $totalPurchaseReturns > 0.0005): ?>
                <tr class="pl-subsection-row">
                    <td></td>
                    <td colspan="4"><strong>مردودات المشتريات</strong></td>
                </tr>
                <?php if (!$purchaseReturnRows): ?>
                    <tr><td colspan="5" class="muted" style="text-align:center;padding:0.75rem;">لا مردودات مشتريات.</td></tr>
                <?php else: ?>
                    <?php $renderSummaryAccountRows($purchaseReturnRows, $dateFrom, $dateTo, true); ?>
                <?php endif; ?>
                <?php if ($totalPurchaseReturns > 0.0005): ?>
                <tr class="pl-subtotal-row">
                    <td></td>
                    <td class="col-desc"><strong>إجمالي مردودات المشتريات</strong></td>
                    <td class="col-money">
                        <strong><span class="pl-deduction-amount">(<?= esc(format_money($totalPurchaseReturns)) ?>)</span></strong>
                    </td>
                    <td class="col-pct">—</td>
                    <td class="no-print"></td>
                </tr>
                <?php endif; ?>
                <?php endif; ?>

                <?php if ($cogsRows !== [] || abs($totalCogs) > 0.0005): ?>
                <tr class="pl-subsection-row">
                    <td></td>
                    <td colspan="4"><strong>تكلفة البضاعة المباعة</strong></td>
                </tr>
                <?php if (!$cogsRows): ?>
                    <tr><td colspan="5" class="muted" style="text-align:center;padding:0.75rem;">لا تكلفة بضاعة مباعة.</td></tr>
                <?php else: ?>
                    <?php $renderSummaryAccountRows($cogsRows, $dateFrom, $dateTo); ?>
                <?php endif; ?>
                <?php endif; ?>

                <tr class="pl-subtotal-row">
                    <td></td>
                    <td class="col-desc"><strong>إجمالي تكلفة المبيعات</strong></td>
                    <td class="col-money"><strong><?= esc(format_money(abs($totalCostOfSales))) ?></strong></td>
                    <td class="col-pct">—</td>
                    <td class="no-print"></td>
                </tr>
                <tr class="pl-subtotal-row pl-gross-profit-row">
                    <td></td>
                    <td class="col-desc"><strong>مجمل الربح</strong></td>
                    <td class="col-money"><strong><?= esc(format_money($grossProfit)) ?></strong></td>
                    <td class="col-pct"><strong><?= $grossPct !== null ? esc(number_format($grossPct, 2)) . '%' : '—' ?></strong></td>
                    <td class="no-print"></td>
                </tr>
                <?php endif; ?>

                <tr class="pl-section-row">
                    <td></td>
                    <td colspan="4"><strong>المصروفات التشغيلية</strong></td>
                </tr>
                <?php if (!$operatingRows): ?>
                    <tr><td colspan="5" class="muted" style="text-align:center;padding:1rem;">لا مصروفات تشغيلية في الفترة.</td></tr>
                <?php else: ?>
                    <?php $renderSummaryAccountRows($operatingRows, $dateFrom, $dateTo); ?>
                <?php endif; ?>
                <?php if ($totalOperating > 0.0005 || $operatingRows !== []): ?>
                <tr class="pl-subtotal-row">
                    <td></td>
                    <td class="col-desc"><strong>إجمالي المصروفات التشغيلية</strong></td>
                    <td class="col-money"><strong><?= esc(format_money(abs($totalOperating))) ?></strong></td>
                    <td class="col-pct">—</td>
                    <td class="no-print"></td>
                </tr>
                <?php endif; ?>
                </tbody>
                <tfoot>
                <tr class="pl-net-row <?= $pl['net_is_profit'] ? '' : 'pl-loss' ?>">
                    <td></td>
                    <td class="col-desc"><strong><?= $pl['net_is_profit'] ? 'صافي الربح' : 'صافي الخسارة' ?></strong></td>
                    <td class="col-money"><strong><?= esc(format_money($pl['net_income'])) ?></strong></td>
                    <td class="col-pct">—</td>
                    <td class="no-print"></td>
                </tr>
                </tfoot>
            </table>

            <?php else: ?>
            <table class="data-table report-sales-table pl-report-table">
                <thead>
                <tr>
                    <th class="col-seq">م</th>
                    <th class="col-desc">البيان / الحساب</th>
                    <th class="col-money">مدين</th>
                    <th class="col-money">دائن</th>
                    <th class="col-money">الصافي</th>
                    <th class="col-pct">% من الإيرادات</th>
                    <th class="no-print col-act">إجراء</th>
                </tr>
                </thead>
                <tbody>
                <tr class="pl-section-row">
                    <td></td>
                    <td colspan="6"><strong>أولاً: الإيرادات</strong></td>
                </tr>
                <?php
                if (!$pl['revenue']['lines']) {
                    echo '<tr><td colspan="7" class="muted" style="text-align:center;padding:1rem;">لا إيرادات في الفترة.</td></tr>';
                } else {
                    $renderPlLines($pl['revenue']['lines']);
                }
                ?>
                <tr class="pl-subtotal-row">
                    <td></td>
                    <td class="col-desc"><strong>إجمالي الإيرادات</strong></td>
                    <td class="col-money"><strong><?= esc(format_money((float) $pl['revenue']['total_debit'])) ?></strong></td>
                    <td class="col-money"><strong><?= esc(format_money((float) $pl['revenue']['total_credit'])) ?></strong></td>
                    <td class="col-money"><strong><?= esc(format_money($pl['total_revenue'])) ?></strong></td>
                    <td class="col-pct"><strong>100%</strong></td>
                    <td class="no-print"></td>
                </tr>

                <tr class="pl-section-row">
                    <td></td>
                    <td colspan="6"><strong>ثانياً: المصروفات</strong></td>
                </tr>
                <?php
                if (!$pl['expense']['lines']) {
                    echo '<tr><td colspan="7" class="muted" style="text-align:center;padding:1rem;">لا مصروفات في الفترة.</td></tr>';
                } else {
                    $renderPlLines($pl['expense']['lines']);
                }
                ?>
                <tr class="pl-subtotal-row">
                    <td></td>
                    <td class="col-desc"><strong>إجمالي المصروفات</strong></td>
                    <td class="col-money"><strong><?= esc(format_money((float) $pl['expense']['total_debit'])) ?></strong></td>
                    <td class="col-money"><strong><?= esc(format_money((float) $pl['expense']['total_credit'])) ?></strong></td>
                    <td class="col-money"><strong><?= esc(format_money($pl['total_expenses'])) ?></strong></td>
                    <td class="col-pct">—</td>
                    <td class="no-print"></td>
                </tr>
                </tbody>
                <tfoot>
                <tr class="pl-net-row <?= $pl['net_is_profit'] ? '' : 'pl-loss' ?>">
                    <td></td>
                    <td class="col-desc"><strong><?= $pl['net_is_profit'] ? 'صافي الربح' : 'صافي الخسارة' ?></strong></td>
                    <td class="col-money">—</td>
                    <td class="col-money">—</td>
                    <td class="col-money"><strong><?= esc(format_money($pl['net_income'])) ?></strong></td>
                    <td class="col-pct">—</td>
                    <td class="no-print"></td>
                </tr>
                </tfoot>
            </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<div id="sales-inv-export-host" class="sales-inv-export-host" aria-hidden="true"></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" defer crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="<?= esc($exportJsUrl) ?>"></script>
<script>
(function () {
    var sel = document.getElementById('pl-view-select');
    var mov = document.getElementById('pl-movements-field');
    if (sel && mov) {
        sel.addEventListener('change', function () {
            mov.hidden = sel.value !== 'detailed';
        });
    }
    var printBtn = document.getElementById('pl-print-btn');
    if (printBtn) {
        printBtn.addEventListener('click', function () {
            var tb = document.querySelector('#master-toolbar [data-master-action="print"]');
            if (tb) {
                tb.click();
            } else {
                window.print();
            }
        });
    }
})();
</script>
