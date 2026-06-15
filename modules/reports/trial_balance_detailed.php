<?php
declare(strict_types=1);

require_once app_path('includes/acc_report.php');
require_once app_path('includes/acc_report_ref.php');
require_once app_path('includes/acc_account_tree.php');
require_once app_path('includes/acc_report_tb_detail.php');
require_once app_path('includes/document_header.php');

$pdo = db();
acc_gl_ensure_schema($pdo);

$dateFrom = parse_date_to_iso(trim((string) ($_GET['date_from'] ?? ''))) ?? app_default_date_from();
$dateTo = parse_date_to_iso(trim((string) ($_GET['date_to'] ?? ''))) ?? app_default_date_to();

if ($dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$detailLeafRows = acc_report_trial_balance_full($pdo, $dateFrom, $dateTo);
$rows = acc_report_trial_balance_detailed($pdo, $dateFrom, $dateTo);
$totals = acc_report_trial_balance_totals($detailLeafRows);

$reportTitle = 'ميزان مراجعة تفصيلي';

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

$balanceDiff = abs($totals['closing_debit'] - $totals['closing_credit']);
$trialBalanceUrl = app_url(
    'index.php?r=report_trial_balance&date_from=' . rawurlencode(format_date_dmY($dateFrom))
    . '&date_to=' . rawurlencode(format_date_dmY($dateTo))
);

$pageDataAttrs = ' class="card report-acc-wrap report-sales-page report-trial-balance-page report-tb-detail-page"'
    . ' data-report-title="' . esc($reportTitle) . '"'
    . ' data-report-route="report_trial_balance_detailed"'
    . ' data-export-label="تفصيلي"'
    . ' data-from-dmy="' . esc(format_date_dmY($dateFrom)) . '"'
    . ' data-to-dmy="' . esc(format_date_dmY($dateTo)) . '"'
    . ' data-from-iso="' . esc($dateFrom) . '"'
    . ' data-to-iso="' . esc($dateTo) . '"'
    . ' data-tb-detail-api="' . esc($tbDetailApiUrl) . '"';
?>
<link rel="stylesheet" href="<?= esc($salesCssUrl) ?>">
<link rel="stylesheet" href="<?= esc($accCssUrl) ?>">
<style><?= document_print_header_css() ?></style>
<script src="<?= esc($exportJsUrl) ?>"></script>
<script src="<?= esc($tbFilterJsUrl) ?>" defer></script>
<script src="<?= esc($tbExpandJsUrl) ?>" defer></script>

<div<?= $pageDataAttrs ?>>
    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print">
        <input type="hidden" name="r" value="report_trial_balance_detailed">
        <div class="form-row">
            <label class="field">
                <span class="field-label">من تاريخ</span>
                <input class="input js-date-dmy" type="text" name="date_from" value="<?= esc(format_date_dmY($dateFrom)) ?>" dir="ltr" autocomplete="off">
            </label>
            <label class="field">
                <span class="field-label">إلى تاريخ</span>
                <input class="input js-date-dmy" type="text" name="date_to" value="<?= esc(format_date_dmY($dateTo)) ?>" dir="ltr" autocomplete="off">
            </label>
        </div>
        <div style="margin-top:0.5rem;display:flex;flex-wrap:wrap;gap:0.5rem;">
            <button type="submit" class="btn btn-primary btn-sm">عرض التقرير</button>
            <a class="btn btn-secondary btn-sm" href="<?= esc($trialBalanceUrl) ?>">ميزان المراجعة (إجمالي / نهائي)</a>
        </div>
        <?php if ($rows): ?>
        <p class="muted no-print" style="margin:0.35rem 0 0;font-size:0.85rem;">
            اضغط على أي <strong>حساب نهائي</strong> لعرض تفصيل حركته (يظهر أيضاً في الطباعة).
        </p>
        <div class="no-print" style="margin-top:0.35rem;display:flex;flex-wrap:wrap;gap:0.35rem;">
            <button type="button" class="btn btn-ghost btn-sm" id="tb-expand-all">توسيع كل التفاصيل</button>
            <button type="button" class="btn btn-ghost btn-sm" id="tb-collapse-all">طي التفاصيل</button>
        </div>
        <?php endif; ?>
    </form>

    <div class="report-sales-result report-sales-print-area">
        <?= document_print_header_html($reportTitle, $pdo) ?>

        <div class="party-stmt-report-head">
            <p class="party-stmt-report-dates">
                <span>من تاريخ: <?= esc(format_date_dmY($dateFrom)) ?></span>
                <span class="party-stmt-report-dates-sep">|</span>
                <span>إلى تاريخ: <?= esc(format_date_dmY($dateTo)) ?></span>
            </p>
            <p class="muted report-acc-sub" style="margin:0.35rem 0 0;font-size:0.85rem;">
                عرض هرمي: حسابات المجموعات بمجاميع فروعها، ثم الحسابات النهائية تحتها. المجموع في الأسفل من الحسابات النهائية فقط.
            </p>
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
            <table class="data-table report-acc-table report-acc-grid-table report-trial-balance-table" data-detail-colspan="10">
                <thead>
                <tr>
                    <th rowspan="2" class="col-acc-code">رقم الحساب</th>
                    <th rowspan="2" class="col-acc-name">اسم الحساب</th>
                    <th rowspan="2" class="col-acc-type">النوع</th>
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
                    <tr><td colspan="10" class="muted" style="text-align:center;padding:1rem;">لا حركات مرحّلة في الفترة — راجع «ربط الحسابات» وترحيل المستندات.</td></tr>
                <?php else: ?>
                    <tr class="report-tb-filter-empty no-print" hidden>
                        <td colspan="10" class="muted" style="text-align:center;padding:1rem;">لا يوجد حساب يطابق البحث.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($rows as $r):
                    $aid = (int) ($r['id'] ?? 0);
                    $depth = (int) ($r['depth'] ?? 0);
                    $isGroup = !empty($r['is_group']);
                    $ledgerUrl = !$isGroup && $aid > 0 ? acc_report_general_ledger_url($aid, $dateFrom, $dateTo) : null;
                    $namePad = 0.35 + $depth * 1.15;
                    $rowClass = $isGroup ? 'tb-detail-group-row' : 'tb-detail-leaf-row tb-expandable-row';
                    $leafAttrs = !$isGroup && $aid > 0 ? ' data-account-id="' . (int) $aid . '"' : '';
                    $tbSearchHay = mb_strtolower(
                        trim(
                            preg_replace('/\D/', '', (string) $r['code']) . ' '
                            . (string) $r['code'] . ' '
                            . (string) $r['name_ar']
                        ),
                        'UTF-8'
                    );
                    ?>
                    <tr class="<?= esc($rowClass) ?> tb-data-row" data-tb-search="<?= esc($tbSearchHay) ?>"<?= $leafAttrs ?>>
                        <td class="col-acc-code"><code><?= esc(acc_account_format_code((string) $r['code'])) ?></code></td>
                        <td class="col-acc-name tb-expand-cell" style="padding-inline-start:<?= esc((string) $namePad) ?>rem">
                            <?php if ($isGroup): ?>
                                <span class="tb-detail-group-mark" aria-hidden="true">▸ </span>
                            <?php else: ?>
                                <span class="tb-expand-mark" aria-hidden="true">▸</span>
                            <?php endif; ?>
                            <?= esc((string) $r['name_ar']) ?>
                            <?php if (!$isGroup): ?>
                                <span class="muted tb-expand-hint">اضغط لعرض التفصيل</span>
                            <?php endif; ?>
                        </td>
                        <td class="col-acc-type muted"><?= esc(acc_account_type_label((string) ($r['account_type'] ?? ''))) ?></td>
                        <td class="col-money"><?= (float) $r['opening_debit'] > 0 ? esc(format_money((float) $r['opening_debit'])) : '—' ?></td>
                        <td class="col-money"><?= (float) $r['opening_credit'] > 0 ? esc(format_money((float) $r['opening_credit'])) : '—' ?></td>
                        <td class="col-money"><?= (float) $r['period_debit'] > 0 ? esc(format_money((float) $r['period_debit'])) : '—' ?></td>
                        <td class="col-money"><?= (float) $r['period_credit'] > 0 ? esc(format_money((float) $r['period_credit'])) : '—' ?></td>
                        <td class="col-money"><?= (float) $r['closing_debit'] > 0 ? esc(format_money((float) $r['closing_debit'])) : '—' ?></td>
                        <td class="col-money"><?= (float) $r['closing_credit'] > 0 ? esc(format_money((float) $r['closing_credit'])) : '—' ?></td>
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
                    <td colspan="3"><strong>المجموع (حسابات نهائية)</strong></td>
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
