<?php
declare(strict_types=1);

require_once app_path('includes/acc_report.php');
require_once app_path('includes/acc_account_tree.php');
require_once app_path('includes/acc_report_vat_jordan.php');
require_once app_path('includes/acc_report_inventory.php');
require_once app_path('includes/acc_report_tb_detail.php');
require_once app_path('includes/document_header.php');

$pdo = db();
acc_gl_ensure_schema($pdo);

$dateFrom = parse_date_to_iso(trim((string) ($_GET['date_from'] ?? $_POST['date_from'] ?? '')))
    ?? app_default_date_from();
$dateTo = parse_date_to_iso(trim((string) ($_GET['date_to'] ?? $_POST['date_to'] ?? '')))
    ?? app_default_date_to();

$view = (string) ($_GET['view'] ?? 'summary') === 'detail' ? 'detail' : 'summary';

if ($dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$detailRows = acc_report_trial_balance_full($pdo, $dateFrom, $dateTo);
$totals = acc_report_trial_balance_totals($detailRows);
/** إجمالي وتفصيلي: حسابات نهائية — يختلفان في عرض الضريبة والمخزون فقط */
$rows = $detailRows;

$viewLabel = $view === 'summary' ? 'إجمالي' : 'تفصيلي';
$reportTitle = 'ميزان المراجعة — ' . $viewLabel;

$salesCssPath = app_path('assets/css/report-sales.css');
$salesCssUrl = app_url('assets/css/report-sales.css') . (is_file($salesCssPath) ? '?v=' . (string) filemtime($salesCssPath) : '');
$accCssPath = app_path('assets/css/report-acc.css');
$accCssUrl = app_url('assets/css/report-acc.css') . (is_file($accCssPath) ? '?v=' . (string) filemtime($accCssPath) : '');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');
$tbFilterJsPath = app_path('assets/js/report-trial-balance-filter.js');
$tbFilterJsUrl = app_url('assets/js/report-trial-balance-filter.js') . (is_file($tbFilterJsPath) ? '?v=' . (string) filemtime($tbFilterJsPath) : '');
$tbExpandJsPath = app_path('assets/js/report-trial-balance-expand.js');
$tbExpandJsUrl = app_url('assets/js/report-trial-balance-expand.js') . (is_file($tbExpandJsPath) ? '?v=' . (string) filemtime($tbExpandJsPath) : '');
$tbDetailApiUrl = app_url('api/trial_balance_account_detail.php');

$queryBase = static function (string $viewMode) use ($dateFrom, $dateTo): string {
    return 'r=report_trial_balance&view=' . rawurlencode($viewMode)
        . '&date_from=' . rawurlencode(format_date_dmY($dateFrom))
        . '&date_to=' . rawurlencode(format_date_dmY($dateTo));
};

$pageDataAttrs = ' class="card report-acc-wrap report-sales-page report-trial-balance-page"'
    . ' data-report-title="' . esc($reportTitle) . '"'
    . ' data-report-route="report_trial_balance"'
    . ' data-export-label="' . esc($viewLabel) . '"'
    . ' data-from-dmy="' . esc(format_date_dmY($dateFrom)) . '"'
    . ' data-to-dmy="' . esc(format_date_dmY($dateTo)) . '"'
    . ' data-from-iso="' . esc($dateFrom) . '"'
    . ' data-to-iso="' . esc($dateTo) . '"'
    . ' data-tb-detail-api="' . esc($tbDetailApiUrl) . '"';

$balanceDiff = abs($totals['closing_debit'] - $totals['closing_credit']);

$vatIds = acc_report_vat_account_ids($pdo);
$vatInId = (int) ($vatIds['input'] ?? 0);
$vatOutId = (int) ($vatIds['output'] ?? 0);
$vatInDetail = $vatInId > 0
    ? acc_report_vat_tb_period_detail($pdo, $vatInId, $dateFrom, $dateTo, false)
    : null;
$vatOutDetail = $vatOutId > 0
    ? acc_report_vat_tb_period_detail($pdo, $vatOutId, $dateFrom, $dateTo, true)
    : null;

$purchasesTbDetail = acc_report_tb_purchases_period_detail($pdo, $dateFrom, $dateTo);
$purchasesTbAccountId = (int) ($purchasesTbDetail['account_id'] ?? 0);

/** @param array<string, mixed> $vatDetail */
$tbApplyVatCompactColumns = static function (array $vatDetail): array {
    if (!empty($vatDetail['is_output'])) {
        return [
            'period_debit' => (float) $vatDetail['return_amount'],
            'period_credit' => (float) $vatDetail['gross'],
            'closing_debit' => 0.0,
            'closing_credit' => (float) $vatDetail['net'],
        ];
    }

    return [
        'period_debit' => (float) $vatDetail['gross'],
        'period_credit' => (float) $vatDetail['return_amount'],
        'closing_debit' => (float) $vatDetail['net'],
        'closing_credit' => 0.0,
    ];
};

/** @param array<string, mixed> $purchDetail */
$tbApplyPurchasesCompactColumns = static function (array $purchDetail): array {
    return [
        'period_debit' => (float) $purchDetail['gross'],
        'period_credit' => (float) $purchDetail['return_amount'],
    ];
};
?>
<link rel="stylesheet" href="<?= esc($salesCssUrl) ?>">
<link rel="stylesheet" href="<?= esc($accCssUrl) ?>">
<style><?= document_print_header_css() ?></style>
<script src="<?= esc($exportJsUrl) ?>"></script>
<script src="<?= esc($tbFilterJsUrl) ?>" defer></script>
<script src="<?= esc($tbExpandJsUrl) ?>" defer></script>

<div<?= $pageDataAttrs ?>>
    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print" id="tb-filter-form">
        <input type="hidden" name="r" value="report_trial_balance">
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
                <span class="field-label">نوع العرض</span>
                <select class="input" name="view" id="tb-view-select">
                    <option value="summary"<?= $view === 'summary' ? ' selected' : '' ?>>إجمالي</option>
                    <option value="detail"<?= $view === 'detail' ? ' selected' : '' ?>>تفصيلي</option>
                </select>
            </label>
        </div>
        <p class="muted no-print" style="margin:0.35rem 0 0;font-size:0.85rem;">
            <strong>إجمالي:</strong> الضريبة والمشتريات في صف واحد (مدين كامل، دائن مردود).
            <strong>تفصيلي:</strong> اضغط على أي حساب لعرض تفصيل حركته (يظهر أيضاً في الطباعة).
        </p>
        <?php if ($rows): ?>
        <div class="no-print" style="margin-top:0.35rem;display:flex;flex-wrap:wrap;gap:0.35rem;">
            <button type="button" class="btn btn-ghost btn-sm" id="tb-expand-all">توسيع كل التفاصيل</button>
            <button type="button" class="btn btn-ghost btn-sm" id="tb-collapse-all">طي التفاصيل</button>
        </div>
        <?php endif; ?>
        <div style="margin-top:0.5rem;display:flex;flex-wrap:wrap;gap:0.5rem;">
            <button type="submit" class="btn btn-primary btn-sm">عرض التقرير</button>
            <?php if ($view === 'summary'): ?>
                <a class="btn btn-secondary btn-sm" href="<?= esc(app_url('index.php?' . $queryBase('detail'))) ?>">عرض تفصيلي</a>
            <?php else: ?>
                <a class="btn btn-secondary btn-sm" href="<?= esc(app_url('index.php?' . $queryBase('summary'))) ?>">عرض إجمالي</a>
            <?php endif; ?>
            <a class="btn btn-secondary btn-sm" href="<?= esc(app_url('index.php?r=report_trial_balance_detailed&date_from=' . rawurlencode(format_date_dmY($dateFrom)) . '&date_to=' . rawurlencode(format_date_dmY($dateTo)))) ?>">ميزان تفصيلي (شجرة)</a>
        </div>
    </form>

    <div class="report-sales-result report-sales-print-area">
        <?= document_print_header_html('ميزان المراجعة', $pdo) ?>

        <div class="party-stmt-report-head">
            <p class="party-stmt-report-dates">
                <span>من تاريخ: <?= esc(format_date_dmY($dateFrom)) ?></span>
                <span class="party-stmt-report-dates-sep">|</span>
                <span>إلى تاريخ: <?= esc(format_date_dmY($dateTo)) ?></span>
                <span class="party-stmt-report-dates-sep">|</span>
                <span>العرض: <?= esc($viewLabel) ?></span>
            </p>
            <p class="muted report-acc-sub" style="margin:0.35rem 0 0;font-size:0.85rem;">
                رصيد افتتاحي + حركة الفترة + رصيد ختامي من القيود المرحّلة (مبيعات، مشتريات، سندات، يدوية).
            </p>
        </div>

        <div class="report-acc-summary-grid no-print">
            <div class="report-acc-summary-card">
                <span class="muted">إجمالي مدين (ختامي)</span>
                <strong><?= esc(format_money($totals['closing_debit'])) ?></strong>
            </div>
            <div class="report-acc-summary-card">
                <span class="muted">إجمالي دائن (ختامي)</span>
                <strong><?= esc(format_money($totals['closing_credit'])) ?></strong>
            </div>
            <?php if ($balanceDiff >= 0.01): ?>
            <div class="report-acc-summary-card">
                <span class="muted">فرق التوازن</span>
                <strong style="color:var(--danger,#ef4444);"><?= esc(format_money($balanceDiff)) ?></strong>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($rows): ?>
        <div class="report-tb-account-filter report-sales-item-filter no-print" aria-label="بحث في الحسابات">
            <label class="report-sales-item-filter-field">
                <span class="field-label">بحث برقم أو اسم الحساب</span>
                <div class="report-sales-item-filter-row">
                    <input type="search" class="input js-report-tb-filter-inp"
                           placeholder="مثال: 1001004 أو مخزون" autocomplete="off" spellcheck="false"
                           aria-label="بحث برقم أو اسم الحساب">
                    <button type="button" class="btn btn-ghost btn-sm js-report-tb-filter-clear">مسح</button>
                </div>
            </label>
            <p class="report-sales-item-filter-hint js-report-tb-filter-hint" hidden></p>
        </div>
        <?php endif; ?>

        <div class="report-acc-grid-wrap">
            <table class="data-table report-acc-table report-acc-grid-table report-trial-balance-table" data-detail-colspan="9">
                <thead>
                <tr>
                    <th rowspan="2" class="col-acc-code">رقم الحساب</th>
                    <th rowspan="2" class="col-acc-name">اسم الحساب</th>
                    <th colspan="2" style="text-align:center;">افتتاحي</th>
                    <th colspan="2" style="text-align:center;">حركة الفترة</th>
                    <th colspan="2" style="text-align:center;">ختامي</th>
                    <th rowspan="2" class="no-print col-act">إجراء</th>
                </tr>
                <tr>
                    <th class="col-money">مدين</th>
                    <th class="col-money">دائن</th>
                    <th class="col-money">مدين</th>
                    <th class="col-money">دائن</th>
                    <th class="col-money">مدين</th>
                    <th class="col-money">دائن</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="9" class="muted" style="text-align:center;padding:1rem;">لا حركات مرحّلة — راجع «ربط الحسابات» وترحيل المستندات.</td></tr>
                <?php else: ?>
                    <tr class="report-tb-filter-empty no-print" hidden>
                        <td colspan="9" class="muted" style="text-align:center;padding:1rem;">لا يوجد حساب يطابق البحث.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($rows as $r):
                    $aid = (int) ($r['id'] ?? 0);
                    $isCompactView = $view === 'summary';
                    $ledgerUrl = acc_report_general_ledger_url($aid, $dateFrom, $dateTo);
                    $isVatInRow = $vatInDetail !== null && $aid === $vatInId;
                    $isVatOutRow = $vatOutDetail !== null && $aid === $vatOutId;
                    $vatDetail = $isVatInRow ? $vatInDetail : ($isVatOutRow ? $vatOutDetail : null);
                    $isPurchasesRow = $purchasesTbDetail !== null && $aid === $purchasesTbAccountId;

                    $dispPeriodDebit = (float) ($r['period_debit'] ?? 0);
                    $dispPeriodCredit = (float) ($r['period_credit'] ?? 0);
                    $dispClosingDebit = (float) ($r['closing_debit'] ?? 0);
                    $dispClosingCredit = (float) ($r['closing_credit'] ?? 0);

                    if ($isCompactView && $vatDetail !== null) {
                        $vatCols = $tbApplyVatCompactColumns($vatDetail);
                        $dispPeriodDebit = $vatCols['period_debit'];
                        $dispPeriodCredit = $vatCols['period_credit'];
                        $dispClosingDebit = $vatCols['closing_debit'];
                        $dispClosingCredit = $vatCols['closing_credit'];
                    } elseif ($isCompactView && $isPurchasesRow && $purchasesTbDetail !== null) {
                        $purchCols = $tbApplyPurchasesCompactColumns($purchasesTbDetail);
                        $dispPeriodDebit = $purchCols['period_debit'];
                        $dispPeriodCredit = $purchCols['period_credit'];
                    }
                    $tbSearchHay = mb_strtolower(
                        trim(
                            preg_replace('/\D/', '', (string) $r['code']) . ' '
                            . (string) $r['code'] . ' '
                            . (string) $r['name_ar']
                        ),
                        'UTF-8'
                    );
                    ?>
                    <tr class="tb-data-row tb-expandable-row" data-account-id="<?= (int) $aid ?>" data-tb-search="<?= esc($tbSearchHay) ?>">
                        <td class="col-acc-code"><code><?= esc(acc_account_format_code((string) $r['code'])) ?></code></td>
                        <td class="col-acc-name tb-expand-cell">
                            <span class="tb-expand-mark" aria-hidden="true">▸</span>
                            <?= esc((string) $r['name_ar']) ?>
                            <?php if ($isCompactView && $vatDetail !== null): ?>
                                <span class="muted" style="display:block;font-size:0.75rem;font-weight:normal;">
                                    <?= !empty($vatDetail['is_output'])
                                        ? 'دائن: ضريبة بيع كاملة — مدين: مردود بيع — ختامي: صافي دائن'
                                        : 'مدين: ضريبة شراء كاملة — دائن: مردود شراء — ختامي: صافي مدين' ?>
                                </span>
                            <?php elseif ($isCompactView && $isPurchasesRow && $purchasesTbDetail !== null): ?>
                                <span class="muted" style="display:block;font-size:0.75rem;font-weight:normal;">
                                    <?= ($purchasesTbDetail['kind'] ?? '') === 'inventory'
                                        ? 'مدين: مشتريات — دائن: مردود شراء — ختامي: رصيد المخزون'
                                        : 'مدين: مشتريات — دائن: مردود شراء' ?>
                                </span>
                            <?php else: ?>
                                <span class="muted tb-expand-hint">اضغط لعرض التفصيل</span>
                            <?php endif; ?>
                        </td>
                        <td class="col-money"><?= (float) $r['opening_debit'] > 0 ? esc(format_money((float) $r['opening_debit'])) : '—' ?></td>
                        <td class="col-money"><?= (float) $r['opening_credit'] > 0 ? esc(format_money((float) $r['opening_credit'])) : '—' ?></td>
                        <td class="col-money"><?= $dispPeriodDebit > 0 ? esc(format_money($dispPeriodDebit)) : '—' ?></td>
                        <td class="col-money"><?= $dispPeriodCredit > 0 ? esc(format_money($dispPeriodCredit)) : '—' ?></td>
                        <td class="col-money"><?= $dispClosingDebit > 0 ? esc(format_money($dispClosingDebit)) : '—' ?></td>
                        <td class="col-money"><?= $dispClosingCredit > 0 ? esc(format_money($dispClosingCredit)) : '—' ?></td>
                        <td class="no-print col-act">
                            <?php if ($ledgerUrl): ?>
                                <a class="btn btn-secondary btn-sm" href="<?= esc($ledgerUrl) ?>">دفتر</a>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                <tr class="report-acc-total report-sales-group-total">
                    <td colspan="2"><strong>المجموع</strong></td>
                    <td class="col-money"><strong><?= esc(format_money($totals['opening_debit'])) ?></strong></td>
                    <td class="col-money"><strong><?= esc(format_money($totals['opening_credit'])) ?></strong></td>
                    <td class="col-money"><strong><?= esc(format_money($totals['period_debit'])) ?></strong></td>
                    <td class="col-money"><strong><?= esc(format_money($totals['period_credit'])) ?></strong></td>
                    <td class="col-money"><strong><?= esc(format_money($totals['closing_debit'])) ?></strong></td>
                    <td class="col-money"><strong><?= esc(format_money($totals['closing_credit'])) ?></strong></td>
                    <td class="no-print"></td>
                </tr>
                </tfoot>
            </table>
        </div>

        <?php if ($balanceDiff >= 0.01): ?>
            <p class="alert alert-error no-print" style="margin-top:0.75rem;">تحذير: الرصيد الختامي غير متوازن (فرق <?= esc(format_money($balanceDiff)) ?>).</p>
        <?php endif; ?>

    </div>
</div>
