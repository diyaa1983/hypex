<?php
declare(strict_types=1);

require_once app_path('includes/acc_report_vat_jordan.php');
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

$reportTitle = 'صافي الضريبة المستحقة على المبيعات والمشتريات';

$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($accCssUrl) ?>">
<style><?= document_print_header_css() ?></style>

<div class="card report-sales-page report-vat-net-page"
     data-report-title="<?= esc($reportTitle) ?>"
     data-report-route="report_vat_net_payable"
     data-export-label="vat-net"
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
            للفترة الضريبية (كل شهرين): اختر تاريخ بداية ونهاية الفترة. المستحق = ضريبة المبيعات − ضريبة المشتريات (بعد المردودات).
        </p>
        <div style="display:flex;flex-wrap:wrap;gap:0.5rem;align-items:center;">
            <button type="submit" class="btn btn-primary btn-sm">عرض</button>
        </div>
        <details class="no-print" style="margin-top:0.75rem;font-size:0.85rem;">
            <summary class="muted" style="cursor:pointer;">تفاصيل حسب الفواتير والمردودات (اختياري)</summary>
            <div style="margin-top:0.5rem;display:flex;flex-wrap:wrap;gap:0.5rem;">
                <a class="btn btn-secondary btn-sm" href="<?= esc(app_url('index.php?r=report_invoice_tax')) ?>">تفصيل فواتير البيع</a>
                <a class="btn btn-secondary btn-sm" href="<?= esc(app_url('index.php?r=report_invoice_tax_purchase')) ?>">تفصيل فواتير الشراء</a>
                <a class="btn btn-secondary btn-sm" href="<?= esc(app_url('index.php?r=report_vat_return_tax')) ?>">تفصيل مردود البيع</a>
                <a class="btn btn-secondary btn-sm" href="<?= esc(app_url('index.php?r=report_vat_return_tax_purchase')) ?>">تفصيل مردود الشراء</a>
            </div>
        </details>
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
        <?php else: ?>

        <div style="margin:1rem 0;padding:1.25rem;background:#eef6ff;border-radius:8px;text-align:center;">
            <div class="muted" style="font-size:0.9rem;margin-bottom:0.35rem;">صافي الضريبة المستحق للدفع (من الدفتر)</div>
            <div style="font-size:1.75rem;font-weight:700;"><?= esc(format_money((float) $v['net_payable'])) ?></div>
            <div class="muted" style="font-size:0.82rem;margin-top:0.5rem;">
                = ضريبة مخرجات (مبيعات − مردود بيع)
                <strong><?= esc(format_money((float) $v['output_net'])) ?></strong>
                − ضريبة مدخلات (مشتريات − مردود شراء)
                <strong><?= esc(format_money((float) $v['input_net'])) ?></strong>
            </div>
        </div>

        <?php if ($v['returns_need_repost']): ?>
            <p class="alert alert-error no-print">
                مردودات قديمة لم تُخصم ضريبتها من حسابي الضريبة — راجع المستندات والدفتر. (المستندات تُظهر
                <strong><?= esc(format_money((float) $v['doc_net_payable'])) ?></strong>
                بينما الدفتر
                <strong><?= esc(format_money((float) $v['net_payable'])) ?></strong>)
            </p>
        <?php elseif ((float) $v['gl_doc_gap'] >= 0.01): ?>
            <p class="alert no-print" style="background:#fff8e6;border:1px solid #e8d48a;padding:0.65rem;border-radius:4px;">
                فرق بسيط بين الدفتر والمستندات:
                <?= esc(format_money((float) $v['gl_doc_gap'])) ?>
                — راجع دفتر حسابي الضريبة.
            </p>
        <?php endif; ?>

        <table class="data-table report-acc-table" style="max-width:40rem;">
            <thead>
            <tr><th colspan="2">ضريبة المبيعات (مخرجات) — <?= esc($v['output_name']) ?></th></tr>
            </thead>
            <tbody>
            <tr>
                <td>فواتير بيع (دائن على حساب الضريبة)</td>
                <td class="col-money" style="text-align:end"><?= esc(format_money((float) $v['sales_tax'])) ?></td>
            </tr>
            <tr>
                <td>مردود بيع (مدين — يخصم من المستحق)</td>
                <td class="col-money" style="text-align:end">− <?= esc(format_money((float) $v['sale_return_tax'])) ?></td>
            </tr>
            <tr class="report-acc-total">
                <td><strong>صافي ضريبة المبيعات</strong></td>
                <td class="col-money" style="text-align:end"><strong><?= esc(format_money((float) $v['output_net'])) ?></strong></td>
            </tr>
            </tbody>
        </table>

        <table class="data-table report-acc-table" style="max-width:40rem;margin-top:1rem;">
            <thead>
            <tr><th colspan="2">ضريبة المشتريات (مدخلات) — <?= esc($v['input_name']) ?></th></tr>
            </thead>
            <tbody>
            <tr>
                <td>فواتير شراء (مدين — تُخصم من المستحق)</td>
                <td class="col-money" style="text-align:end"><?= esc(format_money((float) $v['purchase_tax'])) ?></td>
            </tr>
            <tr>
                <td>مردود شراء (دائن — يقلل المدخلات)</td>
                <td class="col-money" style="text-align:end">− <?= esc(format_money((float) $v['purchase_return_tax'])) ?></td>
            </tr>
            <tr class="report-acc-total">
                <td><strong>صافي ضريبة المشتريات (مستحقة لك)</strong></td>
                <td class="col-money" style="text-align:end"><strong><?= esc(format_money((float) $v['input_net'])) ?></strong></td>
            </tr>
            </tbody>
        </table>

        <details class="no-print" style="margin-top:1rem;font-size:0.88rem;">
            <summary class="muted" style="cursor:pointer;">مرجع من المستندات (للتحقق)</summary>
            <table class="data-table" style="margin-top:0.5rem;max-width:40rem;">
                <tr><td>ضريبة مبيعات − مردود (مستندات)</td><td style="text-align:end"><?= esc(format_money((float) $v['doc_output_net'])) ?></td></tr>
                <tr><td>ضريبة مشتريات − مردود (مستندات)</td><td style="text-align:end"><?= esc(format_money((float) $v['doc_input_net'])) ?></td></tr>
                <tr><td><strong>صافي مستحق (مستندات)</strong></td><td style="text-align:end"><strong><?= esc(format_money((float) $v['doc_net_payable'])) ?></strong></td></tr>
            </table>
        </details>

        <?php endif; ?>
    </div>
</div>

<div id="sales-inv-export-host" class="sales-inv-export-host" aria-hidden="true"></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" defer crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="<?= esc($exportJsUrl) ?>"></script>
