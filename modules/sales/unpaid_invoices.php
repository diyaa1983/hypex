<?php
declare(strict_types=1);

require_once app_path('includes/sal_unpaid_invoices.php');
require_once app_path('includes/sales_oracle12_ui.php');
require_once app_path('includes/nav_helpers.php');
require_once app_path('includes/list_pagination.php');

$pdo = db();
$search = trim((string) ($_GET['q'] ?? ''));
$listUrl = app_url('index.php?r=sales_unpaid_invoices');
$exitUrl = nav_exit_url('sales_unpaid_invoices');

$allRows = sal_unpaid_invoices_list($pdo);
if ($search !== '') {
    $qLower = mb_strtolower($search, 'UTF-8');
    $allRows = array_values(array_filter(
        $allRows,
        static function (array $row) use ($qLower): bool {
            $hay = mb_strtolower(
                trim(
                    (string) ($row['invoice_no'] ?? '') . ' '
                    . (string) ($row['customer_name'] ?? '') . ' '
                    . (string) ($row['customer_code'] ?? '')
                ),
                'UTF-8'
            );

            return $qLower === '' || mb_strpos($hay, $qLower) !== false;
        }
    ));
}

$listTotal = count($allRows);
$pager = list_pager_with_total(list_pager_from_request($pdo), $listTotal);
$listPagerQuery = [];
if ($search !== '') {
    $listPagerQuery['q'] = $search;
}
$listPagerUrl = list_pager_base_url('sales_unpaid_invoices', $listPagerQuery);

$page = max(1, (int) ($pager['page'] ?? 1));
$perPage = max(1, (int) ($pager['per_page'] ?? 25));
$offset = (int) ($pager['offset'] ?? (($page - 1) * $perPage));
$rows = array_slice($allRows, $offset, $perPage);

$totalRemaining = 0.0;
foreach ($allRows as $r) {
    $totalRemaining += (float) ($r['remaining'] ?? 0);
}

$viewBase = app_url('index.php?r=sales_invoices&id=');
?>
<?php sales_ora12_enqueue_assets(); ?>

<div class="dashboard-ora sales-ora12-screen sales-ora-list-page sales-inv-list-page"
     data-exit-url="<?= esc($exitUrl) ?>">
    <?php sales_ora12_render_title_bar('فواتير البيع غير المسددة'); ?>
    <?php sales_ora12_workspace_open(); ?>

    <div class="sales-ora-panel card">
        <form method="get" action="<?= esc(app_url('index.php')) ?>" class="sales-ora-search-form form-row">
            <input type="hidden" name="r" value="sales_unpaid_invoices">
            <label class="field" style="flex:1;min-width:220px;">
                <span class="field-label">بحث</span>
                <input class="input" type="search" name="q" value="<?= esc($search) ?>"
                       placeholder="رقم فاتورة، عميل، كود عميل…" autocomplete="off">
            </label>
            <button type="submit" class="btn btn-primary btn-sm">بحث</button>
            <?php if ($search !== ''): ?>
                <a class="btn btn-ghost btn-sm" href="<?= esc($listUrl) ?>">مسح</a>
            <?php endif; ?>
        </form>
        <p class="muted" style="margin:0.65rem 0 0;font-size:0.85rem;">
            <?= (int) $listTotal ?> فاتورة غير مسددة
            · إجمالي المتبقي:
            <strong dir="ltr" style="color:#dc2626;"><?= esc(format_money($totalRemaining)) ?></strong>
        </p>
    </div>

    <div class="sales-ora-panel card">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th style="width:3rem;">#</th>
                    <th>رقم الفاتورة</th>
                    <th>التاريخ</th>
                    <th>العميل</th>
                    <th class="col-money">أصل الفاتورة</th>
                    <th class="col-money">المتبقي</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="6" class="muted" style="text-align:center;padding:1.25rem;">
                            لا توجد فواتير غير مسددة.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $i => $row): ?>
                        <?php
                        $seq = $offset + $i + 1;
                        $invId = (int) ($row['invoice_id'] ?? 0);
                        $custLabel = trim((string) ($row['customer_code'] ?? ''));
                        $custLabel = $custLabel !== ''
                            ? $custLabel . ' — ' . (string) ($row['customer_name'] ?? '')
                            : (string) ($row['customer_name'] ?? '');
                        ?>
                        <tr>
                            <td dir="ltr"><?= (int) $seq ?></td>
                            <td>
                                <?php if ($invId > 0): ?>
                                    <a href="<?= esc($viewBase . $invId) ?>"><?= esc((string) ($row['invoice_no'] ?? '')) ?></a>
                                <?php else: ?>
                                    <?= esc((string) ($row['invoice_no'] ?? '')) ?>
                                <?php endif; ?>
                            </td>
                            <td dir="ltr"><?= esc(format_date_dmY((string) ($row['invoice_date'] ?? ''))) ?></td>
                            <td><?= esc($custLabel) ?></td>
                            <td class="col-money" dir="ltr"><?= esc(format_money((float) ($row['original'] ?? 0))) ?></td>
                            <td class="col-money" dir="ltr" style="color:#dc2626;font-weight:800;">
                                <?= esc(format_money((float) ($row['remaining'] ?? 0))) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
                <?php if ($rows !== []): ?>
                <tfoot>
                    <tr>
                        <td colspan="5" style="text-align:start;font-weight:800;">إجمالي المتبقي (الصفحة / الكل)</td>
                        <td class="col-money" dir="ltr" style="color:#dc2626;font-weight:800;">
                            <?php
                            $pageRem = 0.0;
                            foreach ($rows as $r) {
                                $pageRem += (float) ($r['remaining'] ?? 0);
                            }
                            echo esc(format_money($pageRem));
                            if ($listTotal > count($rows)) {
                                echo ' / ' . esc(format_money($totalRemaining));
                            }
                            ?>
                        </td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
        <?php if ($listTotal > $perPage): ?>
            <?php list_pager_render($pager, $listPagerUrl); ?>
        <?php endif; ?>
    </div>

    <?php sales_ora12_workspace_close(); ?>
</div>
