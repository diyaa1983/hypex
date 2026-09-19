<?php
declare(strict_types=1);

$pdo = db();
require_once app_path('includes/pur_return_schema.php');
require_once app_path('includes/crm_supplier_ledger.php');
require_once app_path('includes/pur_return_post.php');
require_once app_path('includes/sales_oracle12_ui.php');
require_once app_path('includes/nav_helpers.php');

crm_supplier_ledger_ensure_schema($pdo);
pur_return_ensure_schema($pdo);

$flash = flash_get();
$filter = (string) ($_GET['filter'] ?? 'all');
if (!in_array($filter, ['all', 'unposted', 'posted'], true)) {
    $filter = 'all';
}

$search = trim((string) ($_GET['q'] ?? ''));
$listUrl = app_url('index.php?r=purchase_returns_list');
$newUrl = app_url('index.php?r=purchase_returns');
$viewBase = app_url('index.php?r=purchase_returns&id=');
$apiPost = app_url('api/purchase_return_post.php');
$apiDelete = app_url('api/purchase_return_delete.php');
$csrf = csrf_token();
$exitUrl = nav_exit_url('purchase_returns_list');

$postedExpr = pur_return_sql_is_posted_expr('r');

$sql = "SELECT r.id, r.return_no, r.return_date, r.total, r.status, r.created_at,
               s.name_ar AS supplier_name,
               i.invoice_no,
               ({$postedExpr}) AS is_posted
        FROM pur_return r
        INNER JOIN crm_supplier s ON s.id = r.supplier_id
        INNER JOIN pur_invoice i ON i.id = r.invoice_id
        WHERE r.status = 'confirmed'";
$params = [];

if ($filter === 'unposted') {
    $sql .= ' AND NOT ' . $postedExpr;
} elseif ($filter === 'posted') {
    $sql .= ' AND ' . $postedExpr;
}

if ($search !== '') {
    $sql .= ' AND (r.return_no LIKE ? OR i.invoice_no LIKE ? OR s.name_ar LIKE ? OR s.code LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

require_once app_path('includes/list_pagination.php');

$countSql = "SELECT COUNT(*) FROM pur_return r
        INNER JOIN crm_supplier s ON s.id = r.supplier_id
        INNER JOIN pur_invoice i ON i.id = r.invoice_id
        WHERE r.status = 'confirmed'";
$countParams = [];
if ($filter === 'unposted') {
    $countSql .= ' AND NOT ' . $postedExpr;
} elseif ($filter === 'posted') {
    $countSql .= ' AND ' . $postedExpr;
}
if ($search !== '') {
    $countSql .= ' AND (r.return_no LIKE ? OR i.invoice_no LIKE ? OR s.name_ar LIKE ? OR s.code LIKE ?)';
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
$listPagerUrl = list_pager_base_url('purchase_returns_list', $listPagerQuery);

$sql .= ' ORDER BY r.id DESC' . list_pager_sql_limit($pager);

$rows = [];
$listSchemaError = null;
if (!crm_supplier_ledger_has_table($pdo, true)) {
    $listSchemaError =
        'جدول ذمة المورد (crm_supplier_ledger) غير موجود. نفّذ في phpMyAdmin الملف: database/migrations/015_purchase_returns_supplier_ledger.sql ثم حدّث الصفحة.';
} else {
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll() ?: [];
    } catch (Throwable $e) {
        $listSchemaError = 'تعذر تحميل قائمة المردودات: ' . $e->getMessage();
    }
}

$counts = crm_supplier_ledger_count_unposted($pdo);
$totalRet = 0;
try {
    $totalRet = (int) $pdo->query("SELECT COUNT(*) FROM pur_return WHERE status = 'confirmed'")->fetchColumn();
} catch (Throwable $e) {
    $totalRet = 0;
}

function pur_ret_list_filter_url(string $base, string $f, string $q): string
{
    $url = $base . '&filter=' . rawurlencode($f);
    if ($q !== '') {
        $url .= '&q=' . rawurlencode($q);
    }

    return $url;
}

$jsListPath = app_path('assets/js/purchase-returns-list.js');
$jsList = app_url('assets/js/purchase-returns-list.js') . '?v=' . (is_readable($jsListPath) ? (string) filemtime($jsListPath) : '1');
?>
<?php sales_ora12_enqueue_assets(); ?>

<div class="dashboard-ora sales-ora12-screen sales-ora-list-page sales-ret-list-page"
     data-exit-url="<?= esc($exitUrl) ?>">
    <?php sales_ora12_render_title_bar('ترحيل مردودات المشتريات'); ?>
    <?php sales_ora12_workspace_open(); ?>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> sales-ora-flash"><?= esc($flash['message']) ?></div>
    <?php endif; ?>

    <?php if (!empty($listSchemaError)): ?>
        <div class="alert alert-error sales-ora-flash"><?= esc($listSchemaError) ?></div>
    <?php endif; ?>

    <div class="sales-ora-toolbar toolbar" id="purchase-returns-list-screen"
         data-post-url="<?= esc($apiPost) ?>"
         data-delete-url="<?= esc($apiDelete) ?>"
         data-csrf="<?= esc($csrf) ?>">
        <a class="btn btn-primary btn-sm" href="<?= esc($newUrl) ?>">➕ مردود جديد</a>
        <button type="button" class="btn btn-secondary btn-sm" id="pur-ret-post-selected" disabled>ترحيل المحدد</button>
    </div>

    <p class="sales-ora-info muted">
        عرض مردودات المشتريات وتصفيتها وترحيلها. الحفظ لا يؤثر على المخزون ولا على ذمة المورد؛ عند الترحيل يُصرف المخزون ويُحدَّث حساب المورد.
        غير مرحّلة: <strong id="pur-ret-unposted-count"><?= (int) $counts['returns'] ?></strong>
        — إجمالي المؤكدة: <strong><?= $totalRet ?></strong>
    </p>

    <div class="sales-ora-panel card">
        <form method="get" action="<?= esc(app_url('index.php')) ?>" class="sales-ora-search-form form-row">
            <input type="hidden" name="r" value="purchase_returns_list">
            <input type="hidden" name="filter" value="<?= esc($filter) ?>">
            <label class="field" style="flex:1;min-width:200px;">
                <span class="field-label">بحث (رقم مردود، فاتورة شراء، مورد)</span>
                <input class="input" type="search" name="q" value="<?= esc($search) ?>" placeholder="بحث…">
            </label>
            <button type="submit" class="btn btn-secondary btn-sm">بحث</button>
            <?php if ($search !== ''): ?>
                <a class="btn btn-ghost btn-sm" href="<?= esc(pur_ret_list_filter_url($listUrl, $filter, '')) ?>">مسح</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="sales-ora-tabs sal-inv-list-tabs">
        <a class="btn btn-sm <?= $filter === 'all' ? 'btn-primary' : 'btn-secondary' ?>"
           href="<?= esc(pur_ret_list_filter_url($listUrl, 'all', $search)) ?>">الكل</a>
        <a class="btn btn-sm <?= $filter === 'unposted' ? 'btn-primary' : 'btn-secondary' ?>"
           href="<?= esc(pur_ret_list_filter_url($listUrl, 'unposted', $search)) ?>">غير مرحّلة</a>
        <a class="btn btn-sm <?= $filter === 'posted' ? 'btn-primary' : 'btn-secondary' ?>"
           href="<?= esc(pur_ret_list_filter_url($listUrl, 'posted', $search)) ?>">مرحّلة</a>
    </div>

    <div class="sales-ora-panel card">
    <div class="table-wrap">
        <table class="data-table" id="pur-returns-table">
            <thead>
            <tr>
                <th style="width:2.5rem;"><input type="checkbox" id="pur-ret-check-all" title="تحديد الكل"></th>
                <th>رقم المردود</th>
                <th>التاريخ</th>
                <th>المورد</th>
                <th>فاتورة الشراء</th>
                <th>الإجمالي</th>
                <th>الترحيل</th>
                <th>إجراءات</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="8" class="muted" style="text-align:center;">لا توجد مردودات مطابقة.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $ret): ?>
                <?php $posted = !empty($ret['is_posted']); ?>
                <tr data-return-id="<?= (int) $ret['id'] ?>" data-posted="<?= $posted ? '1' : '0' ?>">
                    <td>
                        <?php if (!$posted): ?>
                            <input type="checkbox" class="pur-ret-row-check" value="<?= (int) $ret['id'] ?>">
                        <?php endif; ?>
                    </td>
                    <td><code><?= esc((string) $ret['return_no']) ?></code></td>
                    <td><?= esc(format_date_dmY((string) ($ret['return_date'] ?? ''))) ?></td>
                    <td><?= esc((string) $ret['supplier_name']) ?></td>
                    <td><code><?= esc((string) $ret['invoice_no']) ?></code></td>
                    <td><?= esc(format_amount((float) $ret['total'])) ?></td>
                    <td>
                        <?php if ($posted): ?>
                            <span class="badge badge-ok">مرحّلة</span>
                        <?php else: ?>
                            <span class="badge badge-warn">غير مرحّلة</span>
                        <?php endif; ?>
                    </td>
                    <td class="row-actions">
                        <a class="btn btn-secondary btn-sm" href="<?= esc($viewBase . (int) $ret['id']) ?>">عرض</a>
                        <?php if (!$posted): ?>
                            <button type="button" class="btn btn-primary btn-sm pur-ret-post-one"
                                    data-id="<?= (int) $ret['id'] ?>">ترحيل</button>
                            <button type="button" class="btn btn-danger btn-sm pur-ret-delete-one"
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

<script src="<?= esc($jsList) ?>" defer></script>
