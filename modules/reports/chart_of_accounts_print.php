<?php
declare(strict_types=1);

require_once app_path('includes/acc_account_tree.php');
require_once app_path('includes/document_header.php');

$pdo = db();

if (!acc_account_ensure_schema($pdo)) {
    echo '<div class="card"><p class="alert alert-error">تعذر تحميل دليل الحسابات.</p></div>';
    return;
}

$activeOnly = !isset($_GET['all_accounts']) || (string) $_GET['all_accounts'] !== '1';
$run = isset($_GET['run']) && (string) $_GET['run'] === '1';

$rows = [];
$showResult = false;

if ($run) {
    $showResult = true;
    $rows = acc_account_flatten_tree_for_print($pdo, $activeOnly);
}

$mainCount = 0;
$subCount = 0;
foreach ($rows as $r) {
    if (($r['level_label'] ?? '') === 'رئيسي') {
        $mainCount++;
    } else {
        $subCount++;
    }
}

$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$coaCssPath = app_path('assets/css/report-coa-print.css');
$coaCssUrl = app_url('assets/css/report-coa-print.css') . (is_file($coaCssPath) ? '?v=' . (string) filemtime($coaCssPath) : '');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');

$reportTitle = 'طباعة شجرة الحسابات';

$pageDataAttrs = ' data-report-title="' . esc($reportTitle) . '"';
$pageDataAttrs .= ' data-report-route="report_chart_of_accounts"';
if ($showResult) {
    $pageDataAttrs .= ' data-export-label="coa-tree"';
}
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($coaCssUrl) ?>">

<div class="card report-sales-page report-coa-print-page"<?= $pageDataAttrs ?>>

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print">
        <input type="hidden" name="r" value="report_chart_of_accounts">
        <input type="hidden" name="run" value="1">
        <div class="form-row" style="align-items:center;">
            <label class="field" style="flex:0 0 auto;">
                <input type="checkbox" name="all_accounts" value="1" <?= !$activeOnly ? 'checked' : '' ?>>
                <span style="margin-right:0.35rem;">عرض الحسابات الموقوفة أيضاً</span>
            </label>
        </div>
        <div style="margin-top:0.5rem;">
            <button class="btn btn-primary" type="submit">عرض للطباعة</button>
            <a class="btn btn-secondary" href="<?= esc(app_url('index.php?r=chart_of_accounts')) ?>">شجرة الحسابات</a>
        </div>
    </form>

    <?php if ($showResult): ?>
        <div class="report-sales-result report-sales-print-area report-coa-print-area report-coa-print-page">
            <?= document_print_header_html($reportTitle, $pdo) ?>

            <div class="doc-print-meta">
                <table>
                    <tr>
                        <td>
                            <strong>عدد الحسابات:</strong> <?= count($rows) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>رئيسي:</strong> <?= $mainCount ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>فرعي:</strong> <?= $subCount ?>
                        </td>
                    </tr>
                    <?php if ($activeOnly): ?>
                        <tr>
                            <td class="muted" style="font-size:0.9rem;">الحسابات النشطة فقط.</td>
                        </tr>
                    <?php endif; ?>
                </table>
            </div>

            <div class="report-sales-table-wrap">
                <table class="report-sales-table report-coa-print-table">
                    <thead>
                    <tr>
                        <th class="col-seq">ت</th>
                        <th class="col-acc-code">رقم الحساب</th>
                        <th class="col-acc-name">اسم الحساب</th>
                        <th class="col-level">المستوى</th>
                        <th class="col-type">النوع</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="5" class="muted" style="text-align:center;padding:1.25rem;">
                                لا توجد حسابات.
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php
                    $seq = 0;
                    foreach ($rows as $r):
                        $seq += 1;
                        $depth = (int) ($r['depth'] ?? 0);
                        $isMain = ($r['level_label'] ?? '') === 'رئيسي';
                        $levelLabel = (string) ($r['level_label'] ?? '');
                        $rowClass = 'report-coa-row report-coa-depth-' . $depth
                            . ($isMain ? ' report-coa-row--main' : ' report-coa-row--sub')
                            . (!($r['is_active'] ?? true) ? ' report-coa-row--inactive' : '');
                        ?>
                        <tr class="<?= esc($rowClass) ?>">
                            <td class="col-seq"><?= $seq ?></td>
                            <td class="col-acc-code">
                                <span class="report-coa-code<?= $isMain ? ' report-coa-code--main' : '' ?>">
                                    <?= esc((string) $r['code']) ?>
                                </span>
                            </td>
                            <td class="col-acc-name">
                                <span class="report-coa-name-wrap" data-depth="<?= $depth ?>">
                                    <?php if ($isMain): ?>
                                        <span class="report-coa-tree report-coa-tree--main" aria-hidden="true">▸</span>
                                    <?php else: ?>
                                        <span class="report-coa-tree report-coa-tree--branch" aria-hidden="true"></span>
                                    <?php endif; ?>
                                    <span class="report-coa-name">
                                        <?= esc((string) $r['name_ar']) ?>
                                        <?php if (!($r['is_active'] ?? true)): ?>
                                            <span class="report-coa-inactive-tag">موقوف</span>
                                        <?php endif; ?>
                                    </span>
                                </span>
                            </td>
                            <td class="col-level">
                                <span class="report-coa-level report-coa-level--<?= $isMain ? 'main' : 'sub' ?>">
                                    <?= esc($levelLabel) ?>
                                </span>
                            </td>
                            <td class="col-type"><?= esc((string) $r['account_type']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<div id="sales-inv-export-host" class="sales-inv-export-host" aria-hidden="true"></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" defer crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="<?= esc($exportJsUrl) ?>"></script>
