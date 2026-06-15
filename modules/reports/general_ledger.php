<?php
declare(strict_types=1);

/** @var array{route:string, title:string, subtitle:string, empty_hint:string} $ledgerReport */
$ledgerReport = array_merge([
    'route' => 'report_general_ledger',
    'title' => 'دفتر الأستاذ العام',
    'subtitle' => 'حركة حساب واحد: رصيد افتتاحي، قيود مرحّلة، رصيد ختامي.',
    'empty_hint' => 'اختر حساباً وحدّد الفترة ثم اضغط عرض.',
], $GLOBALS['acc_ledger_report_config'] ?? []);

require_once app_path('includes/acc_report.php');
require_once app_path('includes/acc_account_tree.php');
require_once app_path('includes/account_picker.php');
require_once app_path('includes/document_header.php');

$pdo = db();
acc_gl_ensure_schema($pdo);

$dateFrom = parse_date_to_iso(trim((string) ($_GET['date_from'] ?? ''))) ?? app_default_date_from();
$dateTo = parse_date_to_iso(trim((string) ($_GET['date_to'] ?? ''))) ?? app_default_date_to();
$accountId = (int) ($_GET['account_id'] ?? 0);
$searchQ = trim((string) ($_GET['q'] ?? ''));

if ($dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$accounts = acc_report_leaf_accounts_picker($pdo, $accountId > 0 ? [$accountId] : []);

$account = null;
$pack = null;
if ($accountId > 0) {
    $account = acc_account_get($pdo, $accountId);
    if ($account) {
        $pack = acc_report_general_ledger_pack($pdo, $accountId, $dateFrom, $dateTo);
    }
}

$reportTitle = (string) $ledgerReport['title'];
$accountLabel = $account
    ? acc_account_format_code((string) $account['code']) . ' — ' . (string) $account['name_ar']
    : '';

$salesCssPath = app_path('assets/css/report-sales.css');
$salesCssUrl = app_url('assets/css/report-sales.css') . (is_file($salesCssPath) ? '?v=' . (string) filemtime($salesCssPath) : '');
$cssPath = app_path('assets/css/report-acc.css');
$cssUrl = app_url('assets/css/report-acc.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');

$pageDataAttrs = ' class="card report-sales-page report-gl-page"'
    . ' data-report-title="' . esc($reportTitle) . '"'
    . ' data-report-route="' . esc((string) $ledgerReport['route']) . '"'
    . ' data-export-label="' . esc($accountLabel !== '' ? $accountLabel : 'ledger') . '"'
    . ' data-from-dmy="' . esc(format_date_dmY($dateFrom)) . '"'
    . ' data-to-dmy="' . esc(format_date_dmY($dateTo)) . '"';
?>
<link rel="stylesheet" href="<?= esc($salesCssUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<style><?= document_print_header_css() ?></style>
<script src="<?= esc($exportJsUrl) ?>"></script>

<div<?= $pageDataAttrs ?>>
    <p class="muted report-acc-sub no-print" style="margin:0 0 0.75rem;"><?= esc((string) $ledgerReport['subtitle']) ?></p>

    <?php account_picker_enqueue_assets(); ?>
    <?php account_picker_json_script($accounts, 'report-gl-accounts-json'); ?>
    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="form-row report-acc-filters no-print">
        <input type="hidden" name="r" value="<?= esc((string) $ledgerReport['route']) ?>">
        <label class="field field-grow">
            <span class="field-label">الحساب *</span>
            <?= account_picker_field([
                'id' => 'account_id',
                'name' => 'account_id',
                'value' => $accountId,
                'placeholder' => 'اضغط لاختيار حساب',
                'json_id' => 'report-gl-accounts-json',
                'search_with_movements' => true,
            ]) ?>
        </label>
        <label class="field">
            <span class="field-label">من</span>
            <input class="input js-date-dmy" type="text" name="date_from" value="<?= esc(format_date_dmY($dateFrom)) ?>" dir="ltr">
        </label>
        <label class="field">
            <span class="field-label">إلى</span>
            <input class="input js-date-dmy" type="text" name="date_to" value="<?= esc(format_date_dmY($dateTo)) ?>" dir="ltr">
        </label>
        <?php if ($accountId > 0): ?>
        <label class="field field-grow">
            <span class="field-label">بحث</span>
            <input class="input" type="search" name="q" value="<?= esc($searchQ) ?>"
                   placeholder="المبلغ، الاسم، رقم القيد، البيان، التاريخ…"
                   style="min-width:min(240px, 100%);">
        </label>
        <?php endif; ?>
        <div class="field" style="align-self:flex-end;">
            <button type="submit" class="btn btn-primary btn-sm">عرض</button>
        </div>
    </form>

    <?php if ($accountId > 0 && !$account): ?>
        <p class="alert alert-error no-print">الحساب غير موجود.</p>
    <?php elseif ($pack && $account): ?>
        <?php
        $displayLines = acc_report_general_ledger_filter_lines($pack['lines'], $searchQ);
        $openSplit = acc_report_split_balance($pack['opening']['balance']);
        $periodDebit = 0.0;
        $periodCredit = 0.0;
        foreach ($displayLines as $ln) {
            $periodDebit += (float) ($ln['debit'] ?? 0);
            $periodCredit += (float) ($ln['credit'] ?? 0);
        }
        $footerDebit = round($openSplit['debit'] + $periodDebit, 6);
        $footerCredit = round($openSplit['credit'] + $periodCredit, 6);
        $footerBalance = (float) $pack['closing_balance'];
        ?>
        <div class="report-sales-result report-sales-print-area">
            <?= document_print_header_html($reportTitle, $pdo) ?>

            <div class="party-stmt-report-head">
                <p class="party-stmt-report-customer"><?= esc($accountLabel) ?></p>
                <p class="party-stmt-report-dates">
                    <span>من تاريخ: <?= esc(format_date_dmY($dateFrom)) ?></span>
                    <span class="party-stmt-report-dates-sep">|</span>
                    <span>إلى تاريخ: <?= esc(format_date_dmY($dateTo)) ?></span>
                    <?php if ($searchQ !== ''): ?>
                        <span class="party-stmt-report-dates-sep">|</span>
                        <span>بحث: <?= esc($searchQ) ?></span>
                    <?php endif; ?>
                </p>
            </div>

            <div class="report-acc-summary-grid">
                <div class="report-acc-summary-card">
                    <span class="muted">رصيد افتتاحي</span>
                    <strong><?= esc(format_money($pack['opening']['balance'])) ?></strong>
                </div>
                <div class="report-acc-summary-card">
                    <span class="muted">رصيد ختامي</span>
                    <strong><?= esc(format_money($pack['closing_balance'])) ?></strong>
                </div>
            </div>

            <?php if ($searchQ !== ''): ?>
                <p class="muted no-print" style="margin:0 0 0.75rem;">
                    نتائج البحث — <?= count($displayLines) ?> من <?= count($pack['lines']) ?> حركة
                    <?php if ($displayLines === []): ?>
                        (لا توجد مطابقات)
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <div class="report-sales-table-wrap">
                <table class="data-table report-sales-table report-acc-table report-gl-table">
                    <thead>
                    <tr>
                        <th>التاريخ</th>
                        <th>رقم القيد</th>
                        <th class="col-desc">البيان</th>
                        <th class="col-money">مدين</th>
                        <th class="col-money">دائن</th>
                        <th class="col-money">الرصيد</th>
                        <th class="no-print col-act"></th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr class="report-acc-opening-row">
                        <td colspan="3"><strong>رصيد افتتاحي قبل <?= esc(format_date_dmY($dateFrom)) ?></strong></td>
                        <td class="col-money"><?= $openSplit['debit'] > 0 ? esc(format_money($openSplit['debit'])) : '—' ?></td>
                        <td class="col-money"><?= $openSplit['credit'] > 0 ? esc(format_money($openSplit['credit'])) : '—' ?></td>
                        <td class="col-money"><strong><?= esc(format_money($pack['opening']['balance'])) ?></strong></td>
                        <td class="no-print"></td>
                    </tr>
                    <?php if (!$pack['lines']): ?>
                        <tr><td colspan="7" class="muted" style="text-align:center;">لا حركة في الفترة.</td></tr>
                    <?php elseif ($searchQ !== '' && $displayLines === []): ?>
                        <tr><td colspan="7" class="muted" style="text-align:center;">لا توجد حركات تطابق البحث.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($displayLines as $ln):
                        $jid = (int) ($ln['journal_id'] ?? 0);
                        $refType = (string) ($ln['ref_type'] ?? '');
                        $refId = (int) ($ln['ref_id'] ?? 0);
                        $refUrl = ($ln['source'] ?? '') === 'auto' && $refType !== '' && $refId > 0
                            ? acc_report_ref_url($refType, $refId)
                            : null;
                        ?>
                        <tr>
                            <td><?= esc(format_date_dmY((string) $ln['entry_date'])) ?></td>
                            <td><code><?= esc((string) $ln['entry_no']) ?></code></td>
                            <td class="col-desc">
                                <?= esc((string) ($ln['description_ar'] ?? '')) ?>
                                <?php if (($ln['memo'] ?? '') !== ''): ?>
                                    <span class="muted"> — <?= esc((string) $ln['memo']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="col-money"><?= (float) $ln['debit'] > 0 ? esc(format_money((float) $ln['debit'])) : '—' ?></td>
                            <td class="col-money"><?= (float) $ln['credit'] > 0 ? esc(format_money((float) $ln['credit'])) : '—' ?></td>
                            <td class="col-money"><?= esc(format_money((float) $ln['running_balance'])) ?></td>
                            <td class="no-print report-acc-links col-act">
                                <a class="btn btn-secondary btn-sm" href="<?= esc(acc_report_journal_voucher_url($jid, (string) $ln['entry_no'])) ?>">القيد</a>
                                <?php if ($refUrl): ?>
                                    <a class="btn btn-secondary btn-sm" href="<?= esc($refUrl) ?>"><?= esc(acc_report_ref_type_label($refType)) ?></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot class="report-acc-tfoot">
                    <tr class="report-acc-totals report-sales-group-total">
                        <td colspan="3"><strong>المجموع</strong></td>
                        <td class="col-money"><strong><?= esc(format_money($footerDebit)) ?></strong></td>
                        <td class="col-money"><strong><?= esc(format_money($footerCredit)) ?></strong></td>
                        <td class="col-money"><strong><?= esc(format_money($footerBalance)) ?></strong></td>
                        <td class="no-print"></td>
                    </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    <?php elseif ($accountId < 1): ?>
        <p class="muted no-print"><?= esc((string) $ledgerReport['empty_hint']) ?></p>
    <?php endif; ?>
</div>
