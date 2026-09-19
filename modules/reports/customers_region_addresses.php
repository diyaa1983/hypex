<?php
declare(strict_types=1);

require_once app_path('includes/crm_region_addresses_report.php');
require_once app_path('includes/crm_region.php');
require_once app_path('includes/document_header.php');

$pdo = db();
crm_region_ensure_schema($pdo);

$activeOnly = isset($_GET['active_only']) && (string) $_GET['active_only'] === '1';
$regionId = (int) ($_GET['region_id'] ?? 0);
$run = isset($_GET['run']) && (string) $_GET['run'] === '1';

$regions = [];
try {
    $regions = $pdo->query(
        'SELECT id, code, name_ar FROM crm_region WHERE is_active = 1 ORDER BY sort_order, name_ar'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $regions = [];
}

$rows = [];
$showResult = false;
$regionFilterLabel = 'كل المناطق';

if ($run) {
    $showResult = true;
    $rows = crm_report_region_addresses($pdo, $activeOnly, $regionId);
    if ($regionId > 0) {
        foreach ($regions as $rg) {
            if ((int) ($rg['id'] ?? 0) === $regionId) {
                $regionFilterLabel = (string) ($rg['name_ar'] ?? '');
                break;
            }
        }
        if ($regionFilterLabel === 'كل المناطق') {
            $regionFilterLabel = 'منطقة #' . $regionId;
        }
    }
}

$groups = [];
foreach ($rows as $r) {
    $rid = (int) ($r['region_id'] ?? 0);
    if (!isset($groups[$rid])) {
        $groups[$rid] = [
            'region_name' => (string) ($r['region_name'] ?? ''),
            'region_code' => (string) ($r['region_code'] ?? ''),
            'region_active' => (int) ($r['region_active'] ?? 0),
            'rows' => [],
        ];
    }
    $groups[$rid]['rows'][] = $r;
}

$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$reportTitle = 'تقرير العناوين والمنطقة';
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">

<div class="card report-sales-page" data-report-title="<?= esc($reportTitle) ?>" data-report-route="report_customers_region_addresses">

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print">
        <input type="hidden" name="r" value="report_customers_region_addresses">
        <input type="hidden" name="run" value="1">
        <div class="form-row" style="align-items:flex-end;">
            <label class="field" style="flex:1 1 16rem;">
                <span class="field-label">المنطقة</span>
                <select class="input" name="region_id">
                    <option value="0" <?= $regionId === 0 ? 'selected' : '' ?>>كل المناطق</option>
                    <?php foreach ($regions as $rg): ?>
                        <?php $rid = (int) ($rg['id'] ?? 0); ?>
                        <option value="<?= $rid ?>" <?= $regionId === $rid ? 'selected' : '' ?>>
                            <?= esc((string) ($rg['name_ar'] ?? '')) ?>
                            <?php if (trim((string) ($rg['code'] ?? '')) !== ''): ?>
                                (<?= esc((string) $rg['code']) ?>)
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field" style="flex:0 0 auto;">
                <input type="checkbox" name="active_only" value="1" <?= $activeOnly ? 'checked' : '' ?>>
                <span style="margin-right:0.35rem;">النشطة فقط</span>
            </label>
        </div>
        <div style="margin-top:0.5rem;">
            <button class="btn btn-primary" type="submit">عرض التقرير</button>
        </div>
    </form>

    <?php if ($showResult): ?>
        <div class="report-sales-result report-sales-print-area">
            <?= document_print_header_html($reportTitle, $pdo) ?>

            <div class="doc-print-meta">
                <table>
                    <tr>
                        <td>
                            <strong>المنطقة:</strong> <?= esc($regionFilterLabel) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>عدد المناطق:</strong> <?= count($groups) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>عدد الصفوف:</strong> <?= count($rows) ?>
                        </td>
                    </tr>
                </table>
            </div>

            <?php if ($groups === []): ?>
                <p class="muted">لا توجد مناطق مطابقة.</p>
            <?php else: ?>
                <?php foreach ($groups as $g): ?>
                    <?php
                    $codePart = trim((string) ($g['region_code'] ?? '')) !== ''
                        ? ' (' . (string) $g['region_code'] . ')'
                        : '';
                    $statusPart = ((int) ($g['region_active'] ?? 0) === 1) ? '' : ' — موقوف';
                    $groupTitle = 'المنطقة: ' . (string) ($g['region_name'] ?? '') . $codePart . $statusPart;
                    ?>
                    <h3 style="margin:1rem 0 .4rem;font-size:1rem;"><?= esc($groupTitle) ?></h3>
                    <div class="table-wrap">
                        <table class="table report-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>العنوان</th>
                                    <th>المندوب</th>
                                    <th>عملاء</th>
                                    <th>حالة العنوان</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($g['rows'] as $i => $row): ?>
                                    <?php
                                    $addr = trim((string) ($row['address_name'] ?? ''));
                                    $addrLabel = $addr !== '' ? $addr : '— بدون عنوان —';
                                    $rep = trim((string) ($row['sales_rep_name'] ?? ''));
                                    $aid = (int) ($row['address_id'] ?? 0);
                                    $addrStatus = $aid > 0
                                        ? (((int) ($row['address_active'] ?? 0) === 1) ? 'نشط' : 'موقوف')
                                        : '—';
                                    ?>
                                    <tr>
                                        <td dir="ltr"><?= $i + 1 ?></td>
                                        <td><?= esc($addrLabel) ?></td>
                                        <td><?= esc($rep !== '' ? $rep : '—') ?></td>
                                        <td dir="ltr"><?= (int) ($row['customer_count'] ?? 0) ?></td>
                                        <td><?= esc($addrStatus) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
