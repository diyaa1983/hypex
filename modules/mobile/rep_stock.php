<?php
declare(strict_types=1);

require_once app_path('includes/mobile_auth.php');
require_once app_path('includes/mobile_rep_custody.php');
require_once app_path('includes/inv_warehouse_items_report.php');
require_once app_path('includes/mobile_icons.php');

$pdo = db();
$ctx = mobile_rep_custody_context($pdo);
$flash = flash_get();
$search = trim((string) ($_GET['q'] ?? ''));
$loadUrl = mobile_url('r=m_rep_load');
$returnUrl = mobile_url('r=m_rep_return');

$lines = [];
$totalQty = 0.0;
if ($ctx !== null) {
    $lines = inv_report_warehouse_items_lines($pdo, (int) $ctx['van_warehouse_id'], true);
    if ($search !== '') {
        $qLower = mb_strtolower($search, 'UTF-8');
        $lines = array_values(array_filter($lines, static function (array $row) use ($qLower): bool {
            $hay = mb_strtolower(
                (string) ($row['item_name'] ?? '') . ' ' . (string) ($row['item_sku'] ?? ''),
                'UTF-8'
            );

            return str_contains($hay, $qLower);
        }));
    }
    foreach ($lines as $row) {
        $totalQty += (float) ($row['qty'] ?? 0);
    }
}
$dp = company_decimal_places($pdo);
$itemCount = count($lines);
?>
<?php if ($flash): ?>
<div class="m-alert m-alert--<?= esc($flash['type'] === 'error' ? 'error' : 'success') ?>">
    <?= esc($flash['message']) ?>
</div>
<?php endif; ?>

<?php if ($ctx === null): ?>
<div class="m-alert m-alert--error">
    حسابك غير مربوط بمندوب نشط أو مستودع عهدة.
</div>
<?php return; endif; ?>

<?php
$pdfApi = app_url('api/mobile_rep_stock_pdf.php');
$pdfFilename = 'رصيد عهدة - ' . (string) $ctx['rep_name'] . '.pdf';
$jsV = is_file(app_path('assets/mobile/rep-stock.js'))
    ? (string) filemtime(app_path('assets/mobile/rep-stock.js'))
    : '';
?>

<div id="m-rep-stock-status" class="m-alert" hidden role="status"></div>

<div class="m-rep-stock-actions">
    <a class="m-btn m-btn--ghost m-btn--sm" href="<?= esc($loadUrl) ?>">تحميل</a>
    <a class="m-btn m-btn--ghost m-btn--sm" href="<?= esc($returnUrl) ?>">إرجاع</a>
    <button type="button" class="m-btn m-btn--pdf m-btn--sm" id="m-rep-stock-pdf">PDF</button>
</div>

<section class="m-card m-rep-stock-head">
    <div class="m-rep-custody-meta-row m-rep-custody-meta-row--inline m-rep-stock-meta">
        <div class="m-rep-stock-meta-item">
            <span class="m-rep-stock-meta-label">المندوب</span>
            <span class="m-rep-stock-meta-val"><?= esc((string) $ctx['rep_name']) ?></span>
        </div>
        <div class="m-rep-stock-meta-item m-rep-stock-meta-item--wh">
            <span class="m-rep-stock-meta-label">المستودع</span>
            <span class="m-rep-stock-meta-val"><?= esc((string) $ctx['van_warehouse_name']) ?></span>
        </div>
    </div>
    <form method="get" action="<?= esc(mobile_url()) ?>" class="m-rep-stock-search">
        <input type="hidden" name="r" value="m_rep_stock">
        <input type="search" class="m-input m-input--xs" name="q" value="<?= esc($search) ?>" placeholder="بحث في الرصيد...">
    </form>
    <p class="m-rep-stock-summary muted">
        <?= (int) $itemCount ?> مادة<?= $search !== '' ? ' (نتائج البحث)' : '' ?>
    </p>
</section>

<section class="m-card m-rep-stock-table-card">
    <div class="m-rep-stock-table-wrap">
        <table class="m-rep-stock-table">
            <thead>
            <tr>
                <th class="m-rep-stock-th m-rep-stock-th--no">#</th>
                <th class="m-rep-stock-th m-rep-stock-th--sku">الرقم</th>
                <th class="m-rep-stock-th m-rep-stock-th--name">المادة</th>
                <th class="m-rep-stock-th m-rep-stock-th--qty">الكمية</th>
                <th class="m-rep-stock-th m-rep-stock-th--unit">الوحدة</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($lines === []): ?>
                <tr>
                    <td class="m-rep-stock-td m-rep-stock-td--empty" colspan="5">
                        <?= $search !== '' ? 'لا توجد مواد مطابقة للبحث.' : 'لا يوجد رصيد في العهدة.' ?>
                    </td>
                </tr>
            <?php endif; ?>
            <?php
            $rowNo = 0;
            foreach ($lines as $row):
                $rowNo++;
                ?>
                <tr class="m-rep-stock-tr">
                    <td class="m-rep-stock-td m-rep-stock-td--no"><?= (int) $rowNo ?></td>
                    <td class="m-rep-stock-td m-rep-stock-td--sku" dir="ltr"><?= esc((string) $row['item_sku']) ?></td>
                    <td class="m-rep-stock-td m-rep-stock-td--name"><?= esc((string) $row['item_name']) ?></td>
                    <td class="m-rep-stock-td m-rep-stock-td--qty" dir="ltr"><?= esc(number_format((float) $row['qty'], $dp, '.', ',')) ?></td>
                    <td class="m-rep-stock-td m-rep-stock-td--unit"><?= esc((string) ($row['unit_name'] ?? '—')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <?php if ($lines !== []): ?>
            <tfoot>
                <tr class="m-rep-stock-tr m-rep-stock-tr--foot">
                    <td class="m-rep-stock-td m-rep-stock-td--foot" colspan="3">الإجمالي</td>
                    <td class="m-rep-stock-td m-rep-stock-td--qty m-rep-stock-td--foot" dir="ltr"><?= esc(number_format($totalQty, $dp, '.', ',')) ?></td>
                    <td class="m-rep-stock-td m-rep-stock-td--foot"></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</section>

<div id="m-rep-pdf-view" class="m-rep-pdf-view" hidden aria-hidden="true">
    <div class="m-rep-pdf-view-head">
        <strong>رصيد العهدة — PDF</strong>
        <button type="button" class="m-rep-pdf-view-close" id="m-rep-pdf-view-close" aria-label="إغلاق">×</button>
    </div>
    <iframe class="m-rep-pdf-view-frame" id="m-rep-pdf-view-frame" title="PDF"></iframe>
    <div class="m-rep-pdf-view-actions">
        <a class="m-btn m-btn--primary m-btn--sm" id="m-rep-pdf-view-dl" href="#" download="رصيد عهدة.pdf">تحميل</a>
    </div>
</div>

<script>
window.MRepStockConfig = {
    pdfApi: <?= json_encode($pdfApi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    pdfFilename: <?= json_encode($pdfFilename, JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="<?= esc(app_url('assets/mobile/rep-stock.js')) ?><?= $jsV !== '' ? '?v=' . esc($jsV) : '' ?>" defer></script>
