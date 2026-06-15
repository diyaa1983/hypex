<?php
declare(strict_types=1);

require_once app_path('includes/crm_customers_report.php');
require_once app_path('includes/document_header.php');

$pdo = db();

$activeOnly = isset($_GET['active_only']) && (string) $_GET['active_only'] === '1';
$run = isset($_GET['run']) && (string) $_GET['run'] === '1';

$rows = [];
$showResult = false;

if ($run) {
    $showResult = true;
    $rows = crm_report_customers_list($pdo, $activeOnly);
}

$activeCount = 0;
$inactiveCount = 0;
foreach ($rows as $r) {
    if ((int) ($r['is_active'] ?? 0) === 1) {
        $activeCount++;
    } else {
        $inactiveCount++;
    }
}

$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');

$reportTitle = 'تقرير العملاء';

$pageDataAttrs = ' data-report-title="' . esc($reportTitle) . '"';
$pageDataAttrs .= ' data-report-route="report_customers"';
if ($showResult) {
    $pageDataAttrs .= ' data-export-label="customers"';
}
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">

<div class="card report-sales-page report-customers-page"<?= $pageDataAttrs ?>>

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print">
        <input type="hidden" name="r" value="report_customers">
        <input type="hidden" name="run" value="1">
        <div class="form-row" style="align-items:center;">
            <label class="field" style="flex:0 0 auto;">
                <input type="checkbox" name="active_only" value="1" <?= $activeOnly ? 'checked' : '' ?>>
                <span style="margin-right:0.35rem;">العملاء النشطون فقط</span>
            </label>
        </div>
        <div style="margin-top:0.5rem;">
            <button class="btn btn-primary" type="submit">عرض التقرير</button>
        </div>
    </form>

    <?php if ($showResult): ?>
        <div class="report-sales-result report-sales-print-area report-customers-print">
            <?= document_print_header_html($reportTitle, $pdo) ?>

            <div class="doc-print-meta">
                <table>
                    <tr>
                        <td>
                            <strong>عدد العملاء:</strong> <?= count($rows) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>نشط:</strong> <?= $activeCount ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>موقوف:</strong> <?= $inactiveCount ?>
                        </td>
                    </tr>
                    <?php if ($activeOnly): ?>
                        <tr>
                            <td class="muted" style="font-size:0.9rem;">يعرض العملاء النشطين فقط.</td>
                        </tr>
                    <?php endif; ?>
                </table>
            </div>

            <div class="report-sales-table-wrap">
                <table class="report-sales-table report-customers-table">
                    <thead>
                    <tr>
                        <th class="col-seq">#</th>
                        <th class="col-inv-no">رمز العميل</th>
                        <th class="col-customer-name">اسم العميل</th>
                        <th class="col-phone">الهاتف</th>
                        <th class="col-email">البريد</th>
                        <th class="col-tax">الرقم الضريبي</th>
                        <th class="col-address">العنوان</th>
                        <th class="col-rep">المندوب</th>
                        <th class="col-status">الحالة</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="9" class="muted" style="text-align:center;padding:1.25rem;">
                                لا يوجد عملاء مطابقون للفلتر.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php $seq = 0; ?>
                        <?php foreach ($rows as $r): ?>
                            <?php $seq++; ?>
                            <tr>
                                <td class="col-seq"><?= $seq ?></td>
                                <td class="col-inv-no"><code><?= esc((string) ($r['customer_code'] ?? '')) ?></code></td>
                                <td class="col-customer-name"><?= esc((string) ($r['customer_name'] ?? '')) ?></td>
                                <td class="col-phone"><?= esc((string) ($r['phone'] ?? '')) !== '' ? esc((string) $r['phone']) : '—' ?></td>
                                <td class="col-email"><?= esc((string) ($r['email'] ?? '')) !== '' ? esc((string) $r['email']) : '—' ?></td>
                                <td class="col-tax"><?= esc((string) ($r['tax_number'] ?? '')) !== '' ? esc((string) $r['tax_number']) : '—' ?></td>
                                <td class="col-address"><?= esc((string) ($r['address_ar'] ?? '')) !== '' ? esc((string) $r['address_ar']) : '—' ?></td>
                                <td class="col-rep"><?= esc((string) ($r['sales_rep_name'] ?? '')) !== '' ? esc((string) $r['sales_rep_name']) : '—' ?></td>
                                <td class="col-status"><?= (int) ($r['is_active'] ?? 0) === 1 ? 'نشط' : 'موقوف' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<div id="sales-inv-export-host" class="sales-inv-export-host" aria-hidden="true"></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" defer crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="<?= esc($exportJsUrl) ?>" defer></script>
