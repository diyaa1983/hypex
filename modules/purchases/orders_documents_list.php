<?php
declare(strict_types=1);

require_once app_path('includes/pur_orders_list.php');
require_once app_path('includes/list_pagination.php');
require_once app_path('includes/sales_oracle12_ui.php');
require_once app_path('includes/nav_helpers.php');

$pdo = db();
pur_order_ensure_schema($pdo);

$search = trim((string) ($_GET['q'] ?? ''));
$listUrl = app_url('index.php?r=purchase_orders_documents_list');
$newUrl = app_url('index.php?r=purchase_orders');
$exitUrl = nav_exit_url('purchase_orders_documents_list');

$pager = list_pager_from_request($pdo);
$result = pur_orders_documents_list_fetch($pdo, $search, $pager);
$rows = $result['rows'];
$pager = $result['pager'];

$listPagerQuery = [];
if ($search !== '') {
    $listPagerQuery['q'] = $search;
}
$listPagerUrl = list_pager_base_url('purchase_orders_documents_list', $listPagerQuery);

$flash = flash_get();
$viewBase = app_url('index.php?r=purchase_orders&id=');
?>
<?php sales_ora12_enqueue_assets(); ?>

<div class="dashboard-ora sales-ora12-screen sales-ora-list-page sales-inv-list-page pur-invoices-list-page"
     data-exit-url="<?= esc($exitUrl) ?>">
    <?php sales_ora12_render_title_bar('قائمة طلبات الشراء'); ?>
    <?php sales_ora12_workspace_open(); ?>
    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> sales-ora-flash"><?= esc($flash['message']) ?></div>
    <?php endif; ?>

    <div class="sales-ora-toolbar toolbar">
        <a class="btn btn-primary btn-sm" href="<?= esc($newUrl) ?>">➕ طلب شراء</a>
    </div>

    <div class="sales-ora-panel card">
        <form method="get" action="<?= esc(app_url('index.php')) ?>" class="sales-ora-search-form form-row">
            <input type="hidden" name="r" value="purchase_orders_documents_list">
            <label class="field" style="flex:1;min-width:220px;">
                <span class="field-label">بحث</span>
                <input class="input" type="search" name="q" value="<?= esc($search) ?>"
                       placeholder="رقم طلب، مرجع، مورد…" autocomplete="off">
            </label>
            <button type="submit" class="btn btn-primary btn-sm">بحث</button>
            <?php if ($search !== ''): ?>
                <a class="btn btn-ghost btn-sm" href="<?= esc($listUrl) ?>">مسح</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="sales-ora-panel card">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th class="col-seq" style="width:3rem;">#</th>
                    <th>رقم الطلب</th>
                    <th>التاريخ</th>
                    <th>التسليم المتوقع</th>
                    <th>المورد</th>
                    <th class="col-money">الإجمالي</th>
                    <th>الحالة</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="7" class="muted" style="text-align:center;padding:1.25rem;">لا توجد طلبات مطابقة.</td>
                    </tr>
                <?php endif; ?>
                <?php
                $seq = (int) ($pager['offset'] ?? 0);
                foreach ($rows as $row):
                    $seq++;
                    ?>
                    <tr>
                        <td class="col-seq"><?= (int) $seq++ ?></td>
                        <td><a href="<?= esc($viewBase . (int) $row['id']) ?>"><?= esc((string) $row['order_no']) ?></a></td>
                        <td><?= esc(format_date_dmY((string) $row['order_date'])) ?></td>
                        <td><?= ($row['expected_date'] ?? '') !== '' ? esc(format_date_dmY((string) $row['expected_date'])) : '—' ?></td>
                        <td><?= esc((string) $row['supplier_name']) ?></td>
                        <td class="col-money"><?= esc(format_amount((float) $row['total'])) ?></td>
                        <td><span class="badge"><?= esc((string) ($row['status_label'] ?? '')) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php list_pager_render($pager, $listPagerUrl); ?>
    </div>
    <?php sales_ora12_workspace_close(); ?>
</div>
