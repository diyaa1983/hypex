<?php
declare(strict_types=1);

$pdo = db();
require_once app_path('includes/pur_invoice_schema.php');
require_once app_path('includes/crm_supplier_ledger.php');
require_once app_path('includes/pur_invoice_post.php');
require_once app_path('includes/sales_oracle12_ui.php');
require_once app_path('includes/nav_helpers.php');

pur_invoice_ensure_schema($pdo);
crm_supplier_ledger_ensure_schema($pdo);

$flash = flash_get();
$filter = (string) ($_GET['filter'] ?? 'all');
if (!in_array($filter, ['all', 'unposted', 'posted'], true)) {
    $filter = 'all';
}

$search = trim((string) ($_GET['q'] ?? ''));
$listUrl = app_url('index.php?r=purchase_invoices_list');
$newUrl = app_url('index.php?r=purchase_invoices');
$viewBase = app_url('index.php?r=purchase_invoices&id=');
$apiPost = app_url('api/purchase_invoice_post.php');
$apiDelete = app_url('api/purchase_invoice_delete.php');
$csrf = csrf_token();
$exitUrl = nav_exit_url('purchase_invoices_list');

$postedExpr = pur_invoice_sql_is_posted_expr('i');

$sql = "SELECT i.id, i.invoice_no, i.invoice_date, i.total, i.payment_type, i.status, i.created_at,
               s.name_ar AS supplier_name,
               ({$postedExpr}) AS is_posted
        FROM pur_invoice i
        INNER JOIN crm_supplier s ON s.id = i.supplier_id
        WHERE i.status = 'confirmed'";
$params = [];

if ($filter === 'unposted') {
    $sql .= ' AND NOT ' . $postedExpr;
} elseif ($filter === 'posted') {
    $sql .= ' AND ' . $postedExpr;
}

if ($search !== '') {
    $sql .= ' AND (i.invoice_no LIKE ? OR s.name_ar LIKE ? OR s.code LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

require_once app_path('includes/list_pagination.php');

$countSql = "SELECT COUNT(*) FROM pur_invoice i INNER JOIN crm_supplier s ON s.id = i.supplier_id WHERE i.status = 'confirmed'";
$countParams = [];
if ($filter === 'unposted') {
    $countSql .= ' AND NOT ' . $postedExpr;
} elseif ($filter === 'posted') {
    $countSql .= ' AND ' . $postedExpr;
}
if ($search !== '') {
    $countSql .= ' AND (i.invoice_no LIKE ? OR s.name_ar LIKE ? OR s.code LIKE ?)';
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
$listPagerUrl = list_pager_base_url('purchase_invoices_list', $listPagerQuery);

$sql .= ' ORDER BY i.id DESC' . list_pager_sql_limit($pager);

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll() ?: [];

try {
    $notPostedPur = 'NOT ' . pur_invoice_sql_is_posted_expr('i');
    $purchaseUnposted = (int) $pdo->query(
        "SELECT COUNT(*) FROM pur_invoice i WHERE i.status = 'confirmed' AND {$notPostedPur}"
    )->fetchColumn();
} catch (Throwable $e) {
    $purchaseUnposted = 0;
}

$totalInv = (int) $pdo->query("SELECT COUNT(*) FROM pur_invoice WHERE status = 'confirmed'")->fetchColumn();

function pur_inv_list_filter_url(string $base, string $f, string $q): string
{
    $url = $base . '&filter=' . rawurlencode($f);
    if ($q !== '') {
        $url .= '&q=' . rawurlencode($q);
    }

    return $url;
}

$jsListPath = app_path('assets/js/purchase-invoices-list.js');
$jsList = app_url('assets/js/purchase-invoices-list.js') . '?v=' . (is_readable($jsListPath) ? (string) filemtime($jsListPath) : '1');
?>
<?php sales_ora12_enqueue_assets(); ?>

<div class="dashboard-ora sales-ora12-screen sales-ora-list-page sales-inv-list-page"
     data-exit-url="<?= esc($exitUrl) ?>">
    <?php sales_ora12_render_title_bar('ترحيل فواتير الشراء'); ?>
    <?php sales_ora12_workspace_open(); ?>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> sales-ora-flash"><?= esc($flash['message']) ?></div>
    <?php endif; ?>

    <div class="sales-ora-toolbar toolbar" id="purchase-invoices-list-screen"
         data-post-url="<?= esc($apiPost) ?>"
         data-delete-url="<?= esc($apiDelete) ?>"
         data-csrf="<?= esc($csrf) ?>">
        <a class="btn btn-primary btn-sm" href="<?= esc($newUrl) ?>">➕ فاتورة جديدة</a>
        <button type="button" class="btn btn-secondary btn-sm" id="sal-inv-post-selected" disabled>ترحيل المحدد</button>
    </div>

    <p class="sales-ora-info muted">
        عرض فواتير الشراء وتصفيتها وترحيلها. الحفظ لا يؤثر على المخزون ولا على ذمة المورد؛ عند الترحيل يُدخل المخزون ويُحدَّث حساب المورد.
        غير مرحّلة: <strong id="sal-inv-unposted-count"><?= (int) $purchaseUnposted ?></strong>
        — إجمالي المؤكدة: <strong><?= $totalInv ?></strong>
    </p>

    <div class="sales-ora-panel card">
        <form method="get" action="<?= esc(app_url('index.php')) ?>" class="sales-ora-search-form form-row">
            <input type="hidden" name="r" value="purchase_invoices_list">
            <input type="hidden" name="filter" value="<?= esc($filter) ?>">
            <label class="field" style="flex:1;min-width:200px;">
                <span class="field-label">بحث (رقم فاتورة، مورد)</span>
                <input class="input" type="search" name="q" value="<?= esc($search) ?>" placeholder="بحث…">
            </label>
            <button type="submit" class="btn btn-secondary btn-sm">بحث</button>
            <?php if ($search !== ''): ?>
                <a class="btn btn-ghost btn-sm" href="<?= esc(pur_inv_list_filter_url($listUrl, $filter, '')) ?>">مسح</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="sales-ora-tabs sal-inv-list-tabs">
        <a class="btn btn-sm <?= $filter === 'all' ? 'btn-primary' : 'btn-secondary' ?>"
           href="<?= esc(pur_inv_list_filter_url($listUrl, 'all', $search)) ?>">الكل</a>
        <a class="btn btn-sm <?= $filter === 'unposted' ? 'btn-primary' : 'btn-secondary' ?>"
           href="<?= esc(pur_inv_list_filter_url($listUrl, 'unposted', $search)) ?>">غير مرحّلة</a>
        <a class="btn btn-sm <?= $filter === 'posted' ? 'btn-primary' : 'btn-secondary' ?>"
           href="<?= esc(pur_inv_list_filter_url($listUrl, 'posted', $search)) ?>">مرحّلة</a>
    </div>

    <div class="sales-ora-panel card">
    <div class="table-wrap">
        <table class="data-table" id="sal-invoices-table">
            <thead>
            <tr>
                <th style="width:2.5rem;"><input type="checkbox" id="sal-inv-check-all" title="تحديد الكل"></th>
                <th>رقم الفاتورة</th>
                <th>التاريخ</th>
                <th>المورد</th>
                <th>النوع</th>
                <th>الإجمالي</th>
                <th>الترحيل</th>
                <th>إجراءات</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="8" class="muted" style="text-align:center;">لا توجد فواتير مطابقة.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $inv): ?>
                <?php
                $posted = !empty($inv['is_posted']);
                $pay = ($inv['payment_type'] ?? 'cash') === 'credit' ? 'ذمم' : 'نقدي';
                ?>
                <tr data-invoice-id="<?= (int) $inv['id'] ?>" data-posted="<?= $posted ? '1' : '0' ?>">
                    <td>
                        <?php if (!$posted): ?>
                            <input type="checkbox" class="sal-inv-row-check" value="<?= (int) $inv['id'] ?>">
                        <?php endif; ?>
                    </td>
                    <td><code><?= esc((string) $inv['invoice_no']) ?></code></td>
                    <td><?= esc(format_date_dmY((string) ($inv['invoice_date'] ?? ''))) ?></td>
                    <td><?= esc((string) $inv['supplier_name']) ?></td>
                    <td><?= esc($pay) ?></td>
                    <td><?= esc(format_amount((float) $inv['total'])) ?></td>
                    <td>
                        <?php if ($posted): ?>
                            <span class="badge badge-ok">مرحّلة</span>
                        <?php else: ?>
                            <span class="badge badge-warn">غير مرحّلة</span>
                        <?php endif; ?>
                    </td>
                    <td class="row-actions">
                        <a class="btn btn-secondary btn-sm" href="<?= esc($viewBase . (int) $inv['id']) ?>">عرض</a>
                        <?php if (!$posted): ?>
                            <button type="button" class="btn btn-primary btn-sm sal-inv-post-one"
                                    data-id="<?= (int) $inv['id'] ?>">ترحيل</button>
                            <button type="button" class="btn btn-danger btn-sm sal-inv-delete-one"
                                    data-id="<?= (int) $inv['id'] ?>"
                                    data-no="<?= esc((string) $inv['invoice_no']) ?>">حذف</button>
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

<script src="<?= esc($jsList) ?>" defer></script>
