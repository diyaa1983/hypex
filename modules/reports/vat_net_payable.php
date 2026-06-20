<?php
declare(strict_types=1);

require_once app_path('includes/acc_report_vat_jordan.php');
require_once app_path('includes/acc_report_ref.php');
require_once app_path('includes/acc_account_tree.php');
require_once app_path('includes/acc_vat_trust_account.php');
require_once app_path('includes/document_header.php');

$pdo = db();
acc_gl_ensure_schema($pdo);

$dateFrom = parse_date_to_iso(trim((string) ($_GET['date_from'] ?? ''))) ?? app_default_date_from();
$dateTo = parse_date_to_iso(trim((string) ($_GET['date_to'] ?? ''))) ?? app_default_date_to();

if ($dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$v = acc_report_vat_jordan_summary($pdo, $dateFrom, $dateTo);

$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$accCssPath = app_path('assets/css/report-acc.css');
$accCssUrl = app_url('assets/css/report-acc.css') . (is_file($accCssPath) ? '?v=' . (string) filemtime($accCssPath) : '');

$reportTitle = ACC_VAT_TRUST_REPORT_TITLE;
$trustAccountId = (int) ($v['trust_account_id'] ?? 0);
$trustAccountLabel = acc_account_format_code((string) ($v['trust_account_code'] ?? ''))
    . ' — ' . (string) ($v['trust_account_name'] ?? ACC_VAT_TRUST_ACCOUNT_NAME);
$accountStatementUrl = $trustAccountId > 0
    ? acc_report_account_statement_url($trustAccountId, $dateFrom, $dateTo)
    : '';

$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($accCssUrl) ?>">
<style><?= document_print_header_css() ?></style>

<div class="card report-sales-page report-vat-net-page"
     data-report-title="<?= esc($reportTitle) ?>"
     data-report-route="report_vat_net_payable"
     data-report-view="summary"
     data-export-label="summary"
     data-from-dmy="<?= esc(format_date_dmY($dateFrom)) ?>"
     data-to-dmy="<?= esc(format_date_dmY($dateTo)) ?>">
    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print">
        <input type="hidden" name="r" value="report_vat_net_payable">
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
        <p class="muted" style="margin:0 0 0.5rem;font-size:0.85rem;">
            للفترة الضريبية (كل شهرين): اختر تاريخ بداية ونهاية الفترة. الرصيد الختامي = نفس رصيد حساب
            <strong><?= esc((string) ($v['trust_account_name'] ?? ACC_VAT_TRUST_ACCOUNT_NAME)) ?></strong>
            في كشف الحساب. لعرض حركة كل قيد استخدم زر «كشف الحساب».
        </p>
        <div style="display:flex;flex-wrap:wrap;gap:0.5rem;align-items:center;">
            <button type="submit" class="btn btn-primary btn-sm">عرض</button>
            <?php if ($accountStatementUrl !== ''): ?>
                <a class="btn btn-secondary btn-sm" href="<?= esc($accountStatementUrl) ?>">كشف حساب <?= esc(acc_account_format_code((string) ($v['trust_account_code'] ?? ''))) ?></a>
            <?php endif; ?>
        </div>
    </form>

    <div class="report-sales-result report-sales-print-area" style="margin-top:1rem;">
        <?= document_print_header_html($reportTitle, $pdo) ?>
        <p class="party-stmt-report-dates">
            <span>من: <?= esc(format_date_dmY($dateFrom)) ?></span>
            <span> | </span>
            <span>إلى: <?= esc(format_date_dmY($dateTo)) ?></span>
        </p>

        <?php if ((int) $v['output_account_id'] < 1 || (int) $v['input_account_id'] < 1): ?>
            <p class="alert alert-error">ربط حسابي <code>vat_output</code> و <code>vat_input</code> غير مكتمل في شاشة ربط الحسابات.</p>
        <?php elseif ($trustAccountId < 1): ?>
            <p class="alert alert-error">حساب <?= esc(ACC_VAT_TRUST_ACCOUNT_CODE) ?> (<?= esc(ACC_VAT_TRUST_ACCOUNT_NAME) ?>) غير موجود — راجع شجرة الحسابات.</p>
        <?php else: ?>

        <div class="report-vat-net-summary-hero" style="margin:1rem 0;padding:1.25rem;background:#eef6ff;border-radius:8px;text-align:center;">
            <div class="muted report-vat-net-summary-hero__label" style="font-size:0.9rem;margin-bottom:0.35rem;">
                رصيد حساب <?= esc($trustAccountLabel) ?> (ختامي)
            </div>
            <div class="report-vat-net-summary-hero__amount" style="font-size:1.75rem;font-weight:700;"><?= esc(format_money((float) $v['gl_closing_balance'])) ?></div>
            <div class="report-vat-net-summary-formula muted" style="font-size:0.82rem;margin-top:0.5rem;line-height:1.45;">
                <div>رصيد افتتاحي: <strong><?= esc(format_money((float) $v['gl_opening_balance'])) ?></strong></div>
            </div>
            <?php if ($accountStatementUrl !== ''): ?>
                <p style="margin:0.75rem 0 0;font-size:0.85rem;">
                    <span class="no-print">
                        <a class="btn btn-secondary btn-sm" href="<?= esc($accountStatementUrl) ?>">كشف حساب — عرض التفاصيل</a>
                    </span>
                    <span class="doc-print-only muted">التفاصيل: كشف حساب <?= esc(acc_account_format_code((string) ($v['trust_account_code'] ?? ''))) ?></span>
                </p>
            <?php endif; ?>
        </div>

        <?php if ($v['returns_need_repost']): ?>
            <p class="alert alert-error no-print">
                مردودات قديمة لم تُخصم ضريبتها من حساب الضريبة — راجع المستندات والدفتر.
            </p>
        <?php elseif ((float) $v['gl_doc_gap'] >= 0.01): ?>
            <p class="alert alert-error no-print">
                فرق بين ضريبة الفواتير في الدفتر والمستندات:
                <?= esc(format_money((float) $v['gl_doc_gap'])) ?>
                — راجع ترحيل الفواتير والمردودات.
            </p>
        <?php endif; ?>

        <?php endif; ?>
    </div>
</div>

<div id="sales-inv-export-host" class="sales-inv-export-host" aria-hidden="true"></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" defer crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="<?= esc($exportJsUrl) ?>"></script>
