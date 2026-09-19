<?php
declare(strict_types=1);

require_once app_path('includes/crm_customers_by_rep_report.php');
require_once app_path('includes/crm_sales_rep_schema.php');
require_once app_path('includes/document_header.php');

$pdo = db();
crm_sales_rep_ensure_schema($pdo);

$activeOnly = isset($_GET['active_only']) && (string) $_GET['active_only'] === '1';
$salesRepId = (int) ($_GET['sales_rep_id'] ?? 0);
$run = isset($_GET['run']) && (string) $_GET['run'] === '1';

$reps = $pdo->query(
    'SELECT id, code, name_ar FROM crm_sales_rep WHERE is_active = 1 ORDER BY name_ar'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$report = null;
$showResult = false;
$repFilterLabel = 'جميع المندوبين';

if ($run) {
    $showResult = true;
    $report = crm_report_customers_by_rep_build($pdo, $activeOnly, $salesRepId);
    if ($salesRepId > 0) {
        foreach ($reps as $rep) {
            if ((int) ($rep['id'] ?? 0) === $salesRepId) {
                $repFilterLabel = (string) ($rep['name_ar'] ?? '');
                break;
            }
        }
        if ($repFilterLabel === 'جميع المندوبين') {
            $repFilterLabel = 'مندوب #' . $salesRepId;
        }
    }
}

$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');

$reportTitle = 'تقرير العملاء حسب المندوب';

$pageDataAttrs = ' data-report-title="' . esc($reportTitle) . '"';
$pageDataAttrs .= ' data-report-route="report_customers_by_rep"';
if ($showResult) {
    $pageDataAttrs .= ' data-export-label="' . esc($repFilterLabel) . '"';
}
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">

<div class="card report-sales-page report-customers-by-rep-page"<?= $pageDataAttrs ?>>

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print">
        <input type="hidden" name="r" value="report_customers_by_rep">
        <input type="hidden" name="run" value="1">
        <div class="form-row" style="align-items:flex-end;">
            <label class="field" style="flex:1 1 16rem;">
                <span class="field-label">المندوب</span>
                <select class="input" name="sales_rep_id">
                    <option value="0" <?= $salesRepId === 0 ? 'selected' : '' ?>>جميع المندوبين</option>
                    <?php foreach ($reps as $rep): ?>
                        <?php $rid = (int) ($rep['id'] ?? 0); ?>
                        <option value="<?= $rid ?>" <?= $salesRepId === $rid ? 'selected' : '' ?>>
                            <?= esc((string) ($rep['name_ar'] ?? '')) ?>
                            <?php if (trim((string) ($rep['code'] ?? '')) !== ''): ?>
                                (<?= esc((string) $rep['code']) ?>)
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field" style="flex:0 0 auto;">
                <input type="checkbox" name="active_only" value="1" <?= $activeOnly ? 'checked' : '' ?>>
                <span style="margin-right:0.35rem;">العملاء النشطون فقط</span>
            </label>
        </div>
        <div style="margin-top:0.5rem;">
            <button class="btn btn-primary" type="submit">عرض التقرير</button>
        </div>
    </form>

    <?php if ($showResult && $report !== null): ?>
        <div class="report-sales-result report-sales-print-area report-customers-by-rep-print">
            <?= document_print_header_html($reportTitle, $pdo) ?>

            <div class="doc-print-meta">
                <table>
                    <tr>
                        <td>
                            <strong>المندوب:</strong> <?= esc($repFilterLabel) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>عدد المجموعات:</strong> <?= (int) ($report['grand']['rep_count'] ?? 0) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>عدد العملاء:</strong> <?= (int) ($report['grand']['customer_count'] ?? 0) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>نشط:</strong> <?= (int) ($report['grand']['active_count'] ?? 0) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>موقوف:</strong> <?= (int) ($report['grand']['inactive_count'] ?? 0) ?>
                        </td>
                    </tr>
                    <?php if ($activeOnly): ?>
                        <tr>
                            <td class="muted" style="font-size:0.9rem;">يعرض العملاء النشطين فقط.</td>
                        </tr>
                    <?php endif; ?>
                </table>
            </div>

            <?php if (($report['groups'] ?? []) === []): ?>
                <p class="muted" style="text-align:center;padding:1.25rem;">لا يوجد عملاء مطابقون للفلتر.</p>
            <?php endif; ?>

            <?php foreach ($report['groups'] as $block): ?>
                <section style="margin-top:1.1rem;">
                    <h3 style="margin:0 0 0.45rem;font-size:1.05rem;">
                        المندوب: <?= esc((string) ($block['rep_name'] ?? '')) ?>
                        <?php if (trim((string) ($block['rep_code'] ?? '')) !== ''): ?>
                            <span class="muted" style="font-weight:normal;">(<?= esc((string) $block['rep_code']) ?>)</span>
                        <?php endif; ?>
                        <span class="muted" style="font-weight:normal;font-size:0.9rem;">
                            — <?= (int) ($block['customer_count'] ?? 0) ?> عميل
                            (نشط: <?= (int) ($block['active_count'] ?? 0) ?>)
                        </span>
                    </h3>
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
                                <th class="col-status">الحالة</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php $seq = 0; ?>
                            <?php foreach ($block['rows'] as $r): ?>
                                <?php $seq++; ?>
                                <tr>
                                    <td class="col-seq"><?= $seq ?></td>
                                    <td class="col-inv-no"><code><?= esc((string) ($r['customer_code'] ?? '')) ?></code></td>
                                    <td class="col-customer-name"><?= esc((string) ($r['customer_name'] ?? '')) ?></td>
                                    <td class="col-phone"><?= esc((string) ($r['phone'] ?? '')) !== '' ? esc((string) $r['phone']) : '—' ?></td>
                                    <td class="col-email"><?= esc((string) ($r['email'] ?? '')) !== '' ? esc((string) $r['email']) : '—' ?></td>
                                    <td class="col-tax"><?= esc((string) ($r['tax_number'] ?? '')) !== '' ? esc((string) $r['tax_number']) : '—' ?></td>
                                    <td class="col-address"><?= esc((string) ($r['address_ar'] ?? '')) !== '' ? esc((string) $r['address_ar']) : '—' ?></td>
                                    <td class="col-status"><?= (int) ($r['is_active'] ?? 0) === 1 ? 'نشط' : 'موقوف' ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div id="sales-inv-export-host" class="sales-inv-export-host" aria-hidden="true"></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" defer crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="<?= esc($exportJsUrl) ?>" defer></script>
