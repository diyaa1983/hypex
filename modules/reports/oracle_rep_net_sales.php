<?php
declare(strict_types=1);

/**
 * تقرير صافي فواتير مبيعات المندوب من Oracle (قراءة فقط)
 */

require_once app_path('includes/oracle_rep_net_sales.php');
require_once app_path('includes/document_header.php');

$routeKey = 'report_oracle_rep_net_sales';
$reportTitle = 'صافي فواتير مبيعات المندوب (Oracle)';

$cfg = oracle_rep_net_sales_cfg();

$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
$store = (int) ($_GET['store'] ?? $cfg['default_store']);
$repFromRaw = trim((string) ($_GET['rep_from'] ?? ''));
$repToRaw = trim((string) ($_GET['rep_to'] ?? ''));
$repFrom = $repFromRaw !== '' ? (int) $repFromRaw : null;
$repTo = $repToRaw !== '' ? (int) $repToRaw : null;

if ($from === '') {
    $from = date('Y') . '-01-01';
}
if ($to === '') {
    $to = function_exists('app_default_date_to') ? app_default_date_to() : date('Y-m-d');
}
if ($store < 1) {
    $store = (int) $cfg['default_store'];
}

$fmtAmt = static function (float $n): string {
    return number_format(round($n, 3), 3, '.', ',');
};

$result = null;
$err = '';
$showResult = false;
$rows = [];
$totals = ['inv_cnt' => 0, 'net' => 0.0, 'cost' => 0.0, 'profit' => 0.0, 'profit_pct' => 0.0];

$submitted = isset($_GET['run']) || isset($_GET['from']) || isset($_GET['to']);

if ($submitted) {
    $fromIso = function_exists('parse_date_to_iso') ? parse_date_to_iso($from) : null;
    $toIso = function_exists('parse_date_to_iso') ? parse_date_to_iso($to) : null;
    if ($fromIso === null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $fromIso = $from;
    }
    if ($toIso === null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $toIso = $to;
    }
    if ($fromIso === null && preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $from, $m)) {
        $fromIso = $m[3] . '-' . $m[2] . '-' . $m[1];
    }
    if ($toIso === null && preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $to, $m)) {
        $toIso = $m[3] . '-' . $m[2] . '-' . $m[1];
    }

    if ($fromIso === null || $toIso === null) {
        $err = 'تاريخ البداية والنهاية غير صالحين.';
    } elseif ($fromIso > $toIso) {
        $err = 'تاريخ البداية يجب أن يكون قبل أو يساوي تاريخ النهاية.';
    } else {
        $from = $fromIso;
        $to = $toIso;
        $result = oracle_fetch_rep_net_sales($from, $to, $store, $repFrom, $repTo);
        if (empty($result['ok'])) {
            $err = (string) ($result['message'] ?? 'تعذر الاستعلام من Oracle.');
        } else {
            $showResult = true;
            $rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
            $totals = is_array($result['totals'] ?? null) ? $result['totals'] : $totals;
            if ($rows === [] && ($result['message'] ?? '') !== '') {
                $err = (string) $result['message'];
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

$pageDataAttrs = ' data-report-title="' . esc($reportTitle) . '"';
$pageDataAttrs .= ' data-report-route="' . esc($routeKey) . '"';
if ($showResult) {
    $pageDataAttrs .= ' data-from-dmy="' . esc(format_date_dmY($from)) . '"';
    $pageDataAttrs .= ' data-to-dmy="' . esc(format_date_dmY($to)) . '"';
    $pageDataAttrs .= ' data-export-label="مستودع ' . (int) $store . '"';
}
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($invCssUrl) ?>">

<div class="card report-sales-page dashboard-ora" data-exit-guard="off"<?= $pageDataAttrs ?>>

    <?php if ($err !== ''): ?>
        <div class="alert alert-error no-print" style="margin-bottom:1rem;"><?= esc($err) ?></div>
    <?php endif; ?>

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print no-exit-guard">
        <input type="hidden" name="r" value="<?= esc($routeKey) ?>">
        <input type="hidden" name="run" value="1">
        <div class="form-row">
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
            <label class="field">
                <span class="field-label">المستودع</span>
                <input class="input" type="number" name="store" value="<?= (int) $store ?>" min="1" dir="ltr">
            </label>
            <label class="field">
                <span class="field-label">مندوب من</span>
                <input class="input" type="number" name="rep_from" value="<?= $repFrom !== null ? (int) $repFrom : '' ?>"
                       placeholder="الكل" dir="ltr" min="1">
            </label>
            <label class="field">
                <span class="field-label">مندوب إلى</span>
                <input class="input" type="number" name="rep_to" value="<?= $repTo !== null ? (int) $repTo : '' ?>"
                       placeholder="الكل" dir="ltr" min="1">
            </label>
        </div>
        <div style="margin-top:0.5rem;">
            <button class="btn btn-primary" type="submit">عرض التقرير</button>
        </div>
    </form>

    <?php if ($showResult): ?>
        <div class="report-sales-result report-sales-print-area">
            <?= document_print_header_html($reportTitle, db()) ?>

            <div class="doc-print-meta">
                <table>
                    <tr>
                        <td>
                            <strong>من تاريخ:</strong> <?= esc(format_date_dmY($from)) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>إلى تاريخ:</strong> <?= esc(format_date_dmY($to)) ?>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <strong>المستودع:</strong> <?= (int) $store ?>
                            <?php if ($repFrom !== null || $repTo !== null): ?>
                                &nbsp;&nbsp;|&nbsp;&nbsp;
                                <strong>المندوب:</strong>
                                <?= $repFrom !== null ? (int) $repFrom : '…' ?>
                                —
                                <?= $repTo !== null ? (int) $repTo : '…' ?>
                            <?php endif; ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>المصدر:</strong> Oracle MAS.DAILY (TYPE=<?= (int) $cfg['sale_type'] ?>)
                        </td>
                    </tr>
                </table>
            </div>

            <div class="report-sales-table-wrap">
                <table class="report-sales-table" data-export-table>
                    <thead>
                        <tr>
                            <th>رقم المندوب</th>
                            <th>اسم المندوب</th>
                            <th>عدد الفواتير</th>
                            <th>الصافي</th>
                            <th>التكلفة</th>
                            <th>الربح</th>
                            <th>الربح %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($rows === []): ?>
                            <tr>
                                <td colspan="7" style="text-align:center;">لا توجد بيانات.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td dir="ltr"><?= (int) ($r['rep_no'] ?? 0) ?></td>
                                    <td><?= esc((string) ($r['rep_name'] ?? '')) ?></td>
                                    <td dir="ltr"><?= (int) ($r['inv_cnt'] ?? 0) ?></td>
                                    <td dir="ltr"><?= esc($fmtAmt((float) ($r['net'] ?? 0))) ?></td>
                                    <td dir="ltr"><?= esc($fmtAmt((float) ($r['cost'] ?? 0))) ?></td>
                                    <td dir="ltr"><?= esc($fmtAmt((float) ($r['profit'] ?? 0))) ?></td>
                                    <td dir="ltr"><?= esc($fmtAmt((float) ($r['profit_pct'] ?? 0))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if ($rows !== []): ?>
                        <tfoot>
                            <tr>
                                <th colspan="2">الإجمالي</th>
                                <th dir="ltr"><?= (int) ($totals['inv_cnt'] ?? 0) ?></th>
                                <th dir="ltr"><?= esc($fmtAmt((float) ($totals['net'] ?? 0))) ?></th>
                                <th dir="ltr"><?= esc($fmtAmt((float) ($totals['cost'] ?? 0))) ?></th>
                                <th dir="ltr"><?= esc($fmtAmt((float) ($totals['profit'] ?? 0))) ?></th>
                                <th dir="ltr"><?= esc($fmtAmt((float) ($totals['profit_pct'] ?? 0))) ?></th>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<script src="<?= esc($exportJsUrl) ?>" defer></script>
