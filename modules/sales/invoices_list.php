<?php
declare(strict_types=1);

$pdo = db();
require_once app_path('includes/sal_invoice_schema.php');
require_once app_path('includes/crm_customer_ledger.php');
require_once app_path('includes/sal_invoice_post.php');
require_once app_path('includes/sales_oracle12_ui.php');
require_once app_path('includes/nav_helpers.php');

sal_invoice_ensure_schema($pdo);
crm_ledger_ensure_schema($pdo);

$flash = flash_get();
$filter = (string) ($_GET['filter'] ?? 'all');
if (!in_array($filter, ['all', 'unposted', 'posted'], true)) {
    $filter = 'all';
}

$search = trim((string) ($_GET['q'] ?? ''));
$listUrl = app_url('index.php?r=sales_invoices_list');
$newUrl = app_url('index.php?r=sales_invoices');
$viewBase = app_url('index.php?r=sales_invoices&id=');
$apiPost = app_url('api/sales_invoice_post.php');
$apiDelete = app_url('api/sales_invoice_delete.php');
$csrf = csrf_token();
$exitUrl = nav_exit_url('sales_invoices_list');

$postedExpr = sal_invoice_sql_is_posted_expr('i');

$sql = "SELECT i.id, i.invoice_no, i.invoice_date, i.total, i.payment_type, i.status, i.created_at,
               c.name_ar AS customer_name,
               ({$postedExpr}) AS is_posted
        FROM sal_invoice i
        INNER JOIN crm_customer c ON c.id = i.customer_id
        WHERE i.status = 'confirmed'";
$params = [];

if ($filter === 'unposted') {
    $sql .= ' AND NOT ' . $postedExpr;
} elseif ($filter === 'posted') {
    $sql .= ' AND ' . $postedExpr;
}

if ($search !== '') {
    $sql .= ' AND (i.invoice_no LIKE ? OR c.name_ar LIKE ? OR c.code LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

require_once app_path('includes/list_pagination.php');

$countSql = "SELECT COUNT(*) FROM sal_invoice i INNER JOIN crm_customer c ON c.id = i.customer_id WHERE i.status = 'confirmed'";
$countParams = [];
if ($filter === 'unposted') {
    $countSql .= ' AND NOT ' . $postedExpr;
} elseif ($filter === 'posted') {
    $countSql .= ' AND ' . $postedExpr;
}
if ($search !== '') {
    $countSql .= ' AND (i.invoice_no LIKE ? OR c.name_ar LIKE ? OR c.code LIKE ?)';
    $countParams = [$like, $like, $like];
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
$listPagerUrl = list_pager_base_url('sales_invoices_list', $listPagerQuery);

$sql .= ' ORDER BY i.id DESC' . list_pager_sql_limit($pager);

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll() ?: [];

// Counts for explorer (all / unposted / posted) — independent of search for folder badges
$cntAll = 0;
$cntUnposted = 0;
$cntPosted = 0;
try {
    $cntAll = (int) $pdo->query(
        "SELECT COUNT(*) FROM sal_invoice i WHERE i.status = 'confirmed'"
    )->fetchColumn();
    $cntUnposted = (int) $pdo->query(
        "SELECT COUNT(*) FROM sal_invoice i WHERE i.status = 'confirmed' AND NOT (" . $postedExpr . ')'
    )->fetchColumn();
    $cntPosted = (int) $pdo->query(
        "SELECT COUNT(*) FROM sal_invoice i WHERE i.status = 'confirmed' AND (" . $postedExpr . ')'
    )->fetchColumn();
} catch (Throwable $e) {
    $cntAll = $listTotal;
}

function sal_inv_list_filter_url(string $base, string $f, string $q): string
{
    $url = $base . '&filter=' . rawurlencode($f);
    if ($q !== '') {
        $url .= '&q=' . rawurlencode($q);
    }

    return $url;
}

$canPost = user_can_action('action_post_sales_invoice');
$canDelete = user_can_action('action_delete_sales_invoice');
$filterLabel = match ($filter) {
    'unposted' => 'Unposted',
    'posted' => 'Posted',
    default => 'All Invoices',
};

?>
<?php sales_invoices_list_ssms_enqueue_assets(); ?>

<div class="dashboard-ora sales-ora12-screen rg-ssms si-ssms-list sales-inv-list-page"
     data-exit-url="<?= esc($exitUrl) ?>"
     id="sales-invoices-list-screen"
     data-post-url="<?= esc($apiPost) ?>"
     data-delete-url="<?= esc($apiDelete) ?>"
     data-csrf="<?= esc($csrf) ?>"
     data-can-post="<?= $canPost ? '1' : '0' ?>"
     data-can-delete="<?= $canDelete ? '1' : '0' ?>">
    <header class="dashboard-ora-screen-title no-print" role="banner">
        <h1 class="dashboard-ora-screen-title__text">ترحيل فواتير المبيعات</h1>
        <span class="dashboard-ora-screen-title__meta"><?= (int) $listTotal ?> صف</span>
        <?php nav_render_screen_close('sales_invoices_list'); ?>
    </header>

    <div class="dashboard-ora-workspace rg-ssms-workspace">
        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> rg-ssms-flash"><?= esc($flash['message']) ?></div>
        <?php endif; ?>

        <div class="rg-ssms-toolbar" role="toolbar">
            <a class="rg-tb rg-tb--primary" href="<?= esc($newUrl) ?>"><span class="rg-tb-ico">＋</span> فاتورة جديدة</a>
            <span class="rg-tb-sep"></span>
            <button type="button" class="rg-tb" id="sal-inv-post-selected" disabled>ترحيل المحدد</button>
            <span class="rg-tb-sep"></span>
            <a class="rg-tb" href="<?= esc($newUrl) ?>">Entry</a>
            <span class="rg-tb-grow"></span>
            <span class="rg-tb-hint" dir="ltr">dbo.sal_invoice · filter=<?= esc($filter) ?></span>
        </div>

        <div class="rg-ssms-split">
            <aside class="rg-ssms-explorer" aria-label="مستكشف الفواتير">
                <div class="rg-ssms-pane-title">
                    <span class="rg-ssms-folder">🗀</span> Object Explorer
                </div>
                <div class="rg-ssms-tree-head">
                    <span class="rg-ssms-server">■ Sales → Invoices</span>
                </div>
                <ul class="rg-ssms-tree">
                    <li class="<?= $filter === 'all' ? 'is-selected' : '' ?>">
                        <a class="rg-ssms-node" href="<?= esc(sal_inv_list_filter_url($listUrl, 'all', $search)) ?>">
                            <span class="rg-ssms-icon">▣</span>
                            <span class="rg-ssms-node-name">All Invoices</span>
                            <span class="rg-ssms-badge" dir="ltr"><?= (int) $cntAll ?></span>
                        </a>
                    </li>
                    <li class="<?= $filter === 'unposted' ? 'is-selected' : '' ?>">
                        <a class="rg-ssms-node" href="<?= esc(sal_inv_list_filter_url($listUrl, 'unposted', $search)) ?>">
                            <span class="rg-ssms-icon">▤</span>
                            <span class="rg-ssms-node-name">Unposted</span>
                            <span class="rg-ssms-badge" dir="ltr"><?= (int) $cntUnposted ?></span>
                        </a>
                    </li>
                    <li class="<?= $filter === 'posted' ? 'is-selected' : '' ?>">
                        <a class="rg-ssms-node" href="<?= esc(sal_inv_list_filter_url($listUrl, 'posted', $search)) ?>">
                            <span class="rg-ssms-icon">▥</span>
                            <span class="rg-ssms-node-name">Posted</span>
                            <span class="rg-ssms-badge" dir="ltr"><?= (int) $cntPosted ?></span>
                        </a>
                    </li>
                </ul>
            </aside>

            <main class="rg-ssms-results">
                <div class="rg-ssms-pane-title">
                    <span class="rg-ssms-folder">▦</span>
                    Results
                    <span class="rg-ssms-muted">— <?= esc($filterLabel) ?></span>
                </div>

                <div class="rg-ssms-grid-bar">
                    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="rg-ssms-grid-add">
                        <input type="hidden" name="r" value="sales_invoices_list">
                        <input type="hidden" name="filter" value="<?= esc($filter) ?>">
                        <span class="rg-ssms-muted">Filter:</span>
                        <input class="rg-ssms-input rg-ssms-input--wide" type="search" name="q" value="<?= esc($search) ?>"
                               placeholder="Invoice No / Customer / Code" autocomplete="off" spellcheck="false">
                        <button class="rg-tb rg-tb--primary" type="submit">Execute</button>
                        <?php if ($search !== ''): ?>
                            <a class="rg-tb" href="<?= esc(sal_inv_list_filter_url($listUrl, $filter, '')) ?>">Clear</a>
                        <?php endif; ?>
                    </form>
                </div>

                <div class="rg-ssms-grid-wrap">
                    <table class="rg-ssms-grid" id="sal-invoices-table">
                        <thead>
                        <tr>
                            <th class="si-check"><input type="checkbox" id="sal-inv-check-all" title="تحديد الكل"></th>
                            <th class="col-id">ID</th>
                            <th>Invoice No</th>
                            <th>Date</th>
                            <th class="col-name">Customer</th>
                            <th>Type</th>
                            <th>Total</th>
                            <th class="col-status">Post</th>
                            <th class="col-act">Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!$rows): ?>
                            <tr class="rg-ssms-empty-row">
                                <td colspan="9">
                                    <?= $search !== '' ? 'No rows matching filter.' : 'No rows — (0 row(s) returned)' ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($rows as $inv): ?>
                            <?php
                            $posted = !empty($inv['is_posted']);
                            $pay = ($inv['payment_type'] ?? 'cash') === 'credit' ? 'ذمم' : 'نقدي';
                            $invId = (int) $inv['id'];
                            ?>
                            <tr data-invoice-id="<?= $invId ?>" data-posted="<?= $posted ? '1' : '0' ?>">
                                <td class="si-check">
                                    <?php if (!$posted): ?>
                                        <input type="checkbox" class="sal-inv-row-check" value="<?= $invId ?>">
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td class="col-id" dir="ltr"><?= $invId ?></td>
                                <td class="si-list-code" dir="ltr">
                                    <a href="<?= esc($viewBase . $invId) ?>"><?= esc((string) $inv['invoice_no']) ?></a>
                                </td>
                                <td dir="ltr"><?= esc(format_date_dmY((string) ($inv['invoice_date'] ?? ''))) ?></td>
                                <td class="col-name"><?= esc((string) $inv['customer_name']) ?></td>
                                <td><?= esc($pay) ?></td>
                                <td dir="ltr"><?= esc(format_amount((float) $inv['total'])) ?></td>
                                <td class="col-status">
                                    <span class="rg-status <?= $posted ? 'on' : 'off' ?>">
                                        <?= $posted ? 'Posted' : 'Unposted' ?>
                                    </span>
                                </td>
                                <td class="col-act">
                                    <a href="<?= esc($viewBase . $invId) ?>">Open</a>
                                    <?php if (!$posted): ?>
                                        <button type="button" class="sal-inv-post-one" data-id="<?= $invId ?>">Post</button>
                                        <button type="button" class="sal-inv-delete-one danger"
                                                data-id="<?= $invId ?>"
                                                data-no="<?= esc((string) $inv['invoice_no']) ?>">Delete</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="rg-ssms-status-bar">
                    <span><?= count($rows) ?> row(s) displayed · <?= (int) $listTotal ?> total</span>
                    <span dir="ltr">SELECT * FROM sal_invoice WHERE filter=<?= esc($filter) ?></span>
                    <span>Query executed successfully</span>
                </div>
                <div class="si-list-pager">
                    <?php list_pager_render($pager, $listPagerUrl); ?>
                </div>
            </main>
        </div>
    </div>
</div>

<script src="<?= esc(app_url('assets/js/sales-invoices-list.js')) ?>" defer></script>
