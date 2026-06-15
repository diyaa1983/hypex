<?php
declare(strict_types=1);

require_once app_path('includes/acc_vat_tax_report.php');
require_once app_path('includes/document_header.php');

$pdo = db();

$routeCode = (string) ($r ?? 'report_vat_return_tax');
$vatKindLocked = isset($reportVatKind) && (string) $reportVatKind !== '';
$kind = $vatKindLocked
    ? acc_vat_tax_normalize_kind((string) $reportVatKind)
    : acc_vat_tax_normalize_kind((string) ($_GET['kind'] ?? 'sale'));

$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));

if ($from === '') {
    $from = app_default_date_from();
}
if ($to === '') {
    $to = app_default_date_to();
}

$rows = [];
$sumTotal = 0.0;
$sumTax = 0.0;
$totals = ['sale_return_tax' => 0.0, 'purchase_return_tax' => 0.0, 'net' => 0.0, 'total_docs' => 0];
$showResult = false;
$err = '';

$kindLabels = [
    'sale' => 'مردود بيع',
    'purchase' => 'مردود شراء',
];

if (isset($_GET['run']) && (string) $_GET['run'] === '1') {
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
        $rows = acc_vat_report_return_tax_lines($pdo, $from, $to, $kind);
        $totals = acc_vat_report_return_tax_totals($rows, $kind);
        foreach ($rows as $r) {
            $sumTotal += (float) ($r['total'] ?? 0);
            $sumTax += (float) ($r['tax_amount'] ?? 0);
        }
    }
}

$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');

$reportTitle = trim((string) ($pageTitle ?? ''));
if ($reportTitle === '') {
    $reportTitle = $kind === 'purchase'
        ? 'الضريبة المستحقة على مردود الشراء'
        : 'الضريبة المستحقة على مردود البيع';
}

$pageDataAttrs = ' data-report-title="' . esc($reportTitle) . '"';
$pageDataAttrs .= ' data-report-route="' . esc($routeCode) . '"';
if ($showResult && $err === '') {
    $pageDataAttrs .= ' data-export-label="vat-return-tax"';
    $pageDataAttrs .= ' data-from-dmy="' . esc(format_date_dmY($from)) . '"';
    $pageDataAttrs .= ' data-to-dmy="' . esc(format_date_dmY($to)) . '"';
}
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">

<div class="card report-sales-page"<?= $pageDataAttrs ?>>

    <?php if ($err !== ''): ?>
        <div class="alert alert-error no-print" style="margin-bottom:1rem;"><?= esc($err) ?></div>
    <?php endif; ?>

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print">
        <input type="hidden" name="r" value="<?= esc($routeCode) ?>">
        <input type="hidden" name="run" value="1">
        <?php if ($vatKindLocked): ?>
            <input type="hidden" name="kind" value="<?= esc($kind) ?>">
        <?php endif; ?>
        <div class="form-row">
            <label class="field">
                <span class="field-label">من تاريخ *</span>
                <input class="input js-date-dmy" type="text" name="from" value="<?= esc(format_date_dmY($from)) ?>" dir="ltr" autocomplete="off" required>
            </label>
            <label class="field">
                <span class="field-label">إلى تاريخ *</span>
                <input class="input js-date-dmy" type="text" name="to" value="<?= esc(format_date_dmY($to)) ?>" dir="ltr" autocomplete="off" required>
            </label>
        </div>
        <p class="muted" style="margin:0 0 0.5rem;font-size:0.85rem;">
            مردود <?= $kind === 'sale' ? 'بيع' : 'شراء' ?> مرحّل محاسبياً — ضريبة المردود فقط.
        </p>
        <div style="margin-top:0.5rem;display:flex;flex-wrap:wrap;gap:0.5rem;">
            <button class="btn btn-primary" type="submit">عرض التقرير</button>
            <?php if ($kind === 'sale'): ?>
                <a class="btn btn-secondary" href="<?= esc(app_url('index.php?r=report_vat_return_tax_purchase')) ?>">ضريبة مردود الشراء</a>
                <a class="btn btn-secondary" href="<?= esc(app_url('index.php?r=report_invoice_tax')) ?>">ضريبة فواتير البيع</a>
            <?php else: ?>
                <a class="btn btn-secondary" href="<?= esc(app_url('index.php?r=report_vat_return_tax')) ?>">ضريبة مردود البيع</a>
                <a class="btn btn-secondary" href="<?= esc(app_url('index.php?r=report_invoice_tax_purchase')) ?>">ضريبة فواتير الشراء</a>
            <?php endif; ?>
            <a class="btn btn-secondary" href="<?= esc(app_url('index.php?r=report_vat_net_payable')) ?>">← التقرير الرئيسي (الصافي)</a>
        </div>
    </form>

    <?php if ($showResult && $err === ''): ?>
        <div class="report-sales-result report-sales-print-area">
            <?= document_print_header_html($reportTitle, $pdo) ?>

            <div class="doc-print-meta">
                <table>
                    <tr>
                        <td>
                            <strong>من:</strong> <?= esc(format_date_dmY($from)) ?>
                            &nbsp;|&nbsp;
                            <strong>إلى:</strong> <?= esc(format_date_dmY($to)) ?>
                            &nbsp;|&nbsp;
                            <strong>النوع:</strong> <?= esc($kindLabels[$kind] ?? $kind) ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>عدد المردودات:</strong> <?= count($rows) ?></td>
                    </tr>
                </table>
            </div>

            <div class="report-sales-table-wrap">
                <table class="report-sales-table">
                    <thead>
                    <tr>
                        <th class="col-seq">#</th>
                        <th>رقم المردود</th>
                        <th>التاريخ</th>
                        <th>فاتورة أصلية</th>
                        <th><?= $kind === 'sale' ? 'العميل' : 'المورد' ?></th>
                        <th class="col-money">الإجمالي</th>
                        <th class="col-money">ضريبة المردود</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="7" class="muted" style="text-align:center;padding:1.25rem;">لا مردودات في الفترة.</td>
                        </tr>
                    <?php else: ?>
                        <?php $seq = 0; ?>
                        <?php foreach ($rows as $r): ?>
                            <?php $seq++; ?>
                            <tr>
                                <td class="col-seq"><?= $seq ?></td>
                                <td><code><?= esc((string) ($r['doc_no'] ?? '')) ?></code></td>
                                <td><?= esc(format_date_dmY((string) ($r['doc_date'] ?? ''))) ?></td>
                                <td><code><?= esc((string) ($r['source_no'] ?? '')) ?></code></td>
                                <td><?= esc((string) ($r['party_name'] ?? '')) ?></td>
                                <td class="col-money"><?= esc(format_amount((float) ($r['total'] ?? 0))) ?></td>
                                <td class="col-money"><?= esc(format_amount((float) ($r['tax_amount'] ?? 0))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                    <?php if ($rows): ?>
                        <tfoot>
                        <tr class="report-sales-tfoot">
                            <td colspan="5"><strong><?= $kind === 'sale' ? 'مجموع ضريبة مردود البيع' : 'مجموع ضريبة مردود الشراء' ?></strong></td>
                            <td class="col-money"><strong><?= esc(format_amount($sumTotal)) ?></strong></td>
                            <td class="col-money"><strong><?= esc(format_amount($sumTax)) ?></strong></td>
                        </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" defer crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="<?= esc($exportJsUrl) ?>" defer></script>
