<?php
declare(strict_types=1);

$pdo = db();
require_once app_path('includes/sal_return_schema.php');
require_once app_path('includes/crm_customer_ledger.php');
require_once app_path('includes/sal_return_post.php');
require_once app_path('includes/sales_oracle12_ui.php');
require_once app_path('includes/nav_helpers.php');

sal_return_ensure_schema($pdo);
crm_ledger_ensure_schema($pdo);

$flash = flash_get();
$filter = (string) ($_GET['filter'] ?? 'all');
if (!in_array($filter, ['all', 'unposted', 'posted'], true)) {
    $filter = 'all';
}

$search = trim((string) ($_GET['q'] ?? ''));
$listUrl = app_url('index.php?r=sales_returns_list');
$newUrl = app_url('index.php?r=sales_returns');
$viewBase = app_url('index.php?r=sales_returns&id=');
$apiPost = app_url('api/sales_return_post.php');
$apiDelete = app_url('api/sales_return_delete.php');
$csrf = csrf_token();
$exitUrl = nav_exit_url('sales_returns_list');

$postedExpr = sal_return_sql_is_posted_expr('r');

$sql = "SELECT r.id, r.return_no, r.return_date, r.total, r.status, r.created_at,
               c.name_ar AS customer_name,
               i.invoice_no,
               ({$postedExpr}) AS is_posted
        FROM sal_return r
        INNER JOIN crm_customer c ON c.id = r.customer_id
        INNER JOIN sal_invoice i ON i.id = r.invoice_id
        WHERE r.status <> 'cancelled'";
$params = [];

if ($filter === 'unposted') {
    $sql .= ' AND NOT ' . $postedExpr;
} elseif ($filter === 'posted') {
    $sql .= ' AND ' . $postedExpr;
}

if ($search !== '') {
    $sql .= ' AND (r.return_no LIKE ? OR i.invoice_no LIKE ? OR c.name_ar LIKE ? OR c.code LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

require_once app_path('includes/list_pagination.php');

$countSql = "SELECT COUNT(*) FROM sal_return r
        INNER JOIN crm_customer c ON c.id = r.customer_id
        INNER JOIN sal_invoice i ON i.id = r.invoice_id
        WHERE r.status <> 'cancelled'";
$countParams = [];
if ($filter === 'unposted') {
    $countSql .= ' AND NOT ' . $postedExpr;
} elseif ($filter === 'posted') {
    $countSql .= ' AND ' . $postedExpr;
}
if ($search !== '') {
    $countSql .= ' AND (r.return_no LIKE ? OR i.invoice_no LIKE ? OR c.name_ar LIKE ? OR c.code LIKE ?)';
    $countParams = [$like, $like, $like, $like];
}
$stCount = $pdo->prepare($countSql);
$stCount->execute($countParams);
$listTotal = (int) $stCount->fetchColumn();
$pager = list_pager_with_total(list_pager_from_request($pdo), $listTotal);
$listPagerQuery = [];
if ($filter !== 'all') {
    $listPagerQuery['filter'] = $filter;
}
if ($search !== '') {
    $listPagerQuery['q'] = $search;
}
$listPagerUrl = list_pager_base_url('sales_returns_list', $listPagerQuery);

$sql .= ' ORDER BY r.id DESC' . list_pager_sql_limit($pager);

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll() ?: [];

function sal_ret_list_filter_url(string $base, string $f, string $q): string
{
    $url = $base . '&filter=' . rawurlencode($f);
    if ($q !== '') {
        $url .= '&q=' . rawurlencode($q);
    }

    return $url;
}

?>
<?php sales_ora12_enqueue_assets(); ?>

<div class="dashboard-ora sales-ora12-screen sales-ora-list-page sales-ret-list-page"
     data-exit-url="<?= esc($exitUrl) ?>">
    <?php sales_ora12_render_title_bar('ترحيل مرتجعات المبيعات'); ?>
    <?php sales_ora12_workspace_open(); ?>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> sales-ora-flash"><?= esc($flash['message']) ?></div>
    <?php endif; ?>

    <div class="sales-ora-toolbar toolbar" id="sales-returns-list-screen"
         data-post-url="<?= esc($apiPost) ?>"
         data-delete-url="<?= esc($apiDelete) ?>"
         data-csrf="<?= esc($csrf) ?>">
        <a class="btn btn-primary btn-sm" href="<?= esc($newUrl) ?>">➕ مرتجع جديد</a>
        <button type="button" class="btn btn-secondary btn-sm" id="sal-ret-post-selected" disabled>ترحيل المحدد</button>
    </div>

    <div class="sales-ora-panel card">
        <form method="get" action="<?= esc(app_url('index.php')) ?>" class="sales-ora-search-form form-row">
            <input type="hidden" name="r" value="sales_returns_list">
            <input type="hidden" name="filter" value="<?= esc($filter) ?>">
            <label class="field" style="flex:1;min-width:200px;">
                <span class="field-label">بحث (رقم مرتجع، فاتورة، عميل)</span>
                <input class="input" type="search" name="q" value="<?= esc($search) ?>" placeholder="بحث…">
            </label>
            <button type="submit" class="btn btn-secondary btn-sm">بحث</button>
            <?php if ($search !== ''): ?>
                <a class="btn btn-ghost btn-sm" href="<?= esc(sal_ret_list_filter_url($listUrl, $filter, '')) ?>">مسح</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="sales-ora-tabs sal-inv-list-tabs">
        <a class="btn btn-sm <?= $filter === 'all' ? 'btn-primary' : 'btn-secondary' ?>"
           href="<?= esc(sal_ret_list_filter_url($listUrl, 'all', $search)) ?>">الكل</a>
        <a class="btn btn-sm <?= $filter === 'unposted' ? 'btn-primary' : 'btn-secondary' ?>"
           href="<?= esc(sal_ret_list_filter_url($listUrl, 'unposted', $search)) ?>">غير مرحّلة</a>
        <a class="btn btn-sm <?= $filter === 'posted' ? 'btn-primary' : 'btn-secondary' ?>"
           href="<?= esc(sal_ret_list_filter_url($listUrl, 'posted', $search)) ?>">مرحّلة</a>
    </div>

    <div class="sales-ora-panel card">
        <div class="table-wrap">
            <table class="data-table" id="sal-returns-table">
                <thead>
                <tr>
                    <th style="width:2.5rem;"><input type="checkbox" id="sal-ret-check-all" title="تحديد الكل"></th>
                    <th>رقم المرتجع</th>
                    <th>التاريخ</th>
                    <th>العميل</th>
                    <th>فاتورة البيع</th>
                    <th>الإجمالي</th>
                    <th>الترحيل</th>
                    <th>إجراءات</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="8" class="muted" style="text-align:center;">لا توجد مرتجعات مطابقة.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $ret): ?>
                    <?php $posted = !empty($ret['is_posted']); ?>
                    <tr data-return-id="<?= (int) $ret['id'] ?>" data-posted="<?= $posted ? '1' : '0' ?>">
                        <td>
                            <?php if (!$posted): ?>
                                <input type="checkbox" class="sal-ret-row-check" value="<?= (int) $ret['id'] ?>">
                            <?php endif; ?>
                        </td>
                        <td><code><?= esc((string) $ret['return_no']) ?></code></td>
                        <td><?= esc(format_date_dmY((string) ($ret['return_date'] ?? ''))) ?></td>
                        <td><?= esc((string) $ret['customer_name']) ?></td>
                        <td><code><?= esc((string) $ret['invoice_no']) ?></code></td>
                        <td><?= esc(format_amount((float) $ret['total'])) ?></td>
                        <td>
                            <?php if ($posted): ?>
                                <span class="badge badge-posted">مرحّلة</span>
                            <?php else: ?>
                                <span class="badge badge-warn">غير مرحّلة</span>
                            <?php endif; ?>
                        </td>
                        <td class="row-actions">
                            <a class="btn btn-secondary btn-sm" href="<?= esc($viewBase . (int) $ret['id']) ?>">عرض</a>
                            <?php if (!$posted): ?>
                                <button type="button" class="btn btn-primary btn-sm sal-ret-post-one"
                                        data-id="<?= (int) $ret['id'] ?>">ترحيل</button>
                                <button type="button" class="btn btn-danger btn-sm sal-ret-delete-one"
                                        data-id="<?= (int) $ret['id'] ?>"
                                        data-no="<?= esc((string) $ret['return_no']) ?>">حذف</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php list_pager_render($pager, $listPagerUrl); ?>
    </div>
    <?php sales_ora12_workspace_close(); ?>
</div>

<script src="<?= esc(app_url('assets/js/sales-returns-list.js')) ?>" defer></script>
