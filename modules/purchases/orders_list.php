<?php
declare(strict_types=1);

$pdo = db();
require_once app_path('includes/pur_order_schema.php');
require_once app_path('includes/sales_oracle12_ui.php');
require_once app_path('includes/nav_helpers.php');

pur_order_ensure_schema($pdo);

$flash = flash_get();
$filter = (string) ($_GET['filter'] ?? 'pending');
if (!in_array($filter, ['all', 'pending', 'approved', 'open'], true)) {
    $filter = 'pending';
}

$search = trim((string) ($_GET['q'] ?? ''));
$listUrl = app_url('index.php?r=purchase_orders_list');
$newUrl = app_url('index.php?r=purchase_orders');
$viewBase = app_url('index.php?r=purchase_orders&id=');
$apiApprove = app_url('api/purchase_order_approve.php');
$apiDelete = app_url('api/purchase_order_delete.php');
$csrf = csrf_token();
$exitUrl = nav_exit_url('purchase_orders_list');

$cond = match ($filter) {
    'approved' => "o.status IN ('approved','partial','closed')",
    'open' => "o.status IN ('approved','partial','submitted')",
    'pending' => "o.status IN ('draft','submitted')",
    default => "o.status <> 'cancelled'",
};

$sql = "SELECT o.id, o.order_no, o.order_date, o.expected_date, o.total, o.status, o.created_at,
               s.name_ar AS supplier_name
        FROM pur_order o
        INNER JOIN crm_supplier s ON s.id = o.supplier_id
        WHERE {$cond}";
$params = [];

if ($search !== '') {
    $sql .= ' AND (o.order_no LIKE ? OR o.reference_no LIKE ? OR s.name_ar LIKE ? OR s.code LIKE ?)';
    $like = '%' . $search . '%';
    $params = [$like, $like, $like, $like];
}

require_once app_path('includes/list_pagination.php');

$countSql = "SELECT COUNT(*) FROM pur_order o INNER JOIN crm_supplier s ON s.id = o.supplier_id WHERE {$cond}";
$countParams = $params;
if ($search !== '') {
    $countSql .= ' AND (o.order_no LIKE ? OR o.reference_no LIKE ? OR s.name_ar LIKE ? OR s.code LIKE ?)';
}
$stCount = $pdo->prepare($countSql);
$stCount->execute($countParams);
$listTotal = (int) $stCount->fetchColumn();
$pager = list_pager_with_total(list_pager_from_request($pdo), $listTotal);
$listPagerQuery = ['filter' => $filter];
if ($search !== '') {
    $listPagerQuery['q'] = $search;
}
$listPagerUrl = list_pager_base_url('purchase_orders_list', $listPagerQuery);

$sql .= ' ORDER BY o.id DESC' . list_pager_sql_limit($pager);
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll() ?: [];

function pur_order_list_filter_url(string $base, string $f, string $q): string
{
    $url = $base . '&filter=' . rawurlencode($f);
    if ($q !== '') {
        $url .= '&q=' . rawurlencode($q);
    }

    return $url;
}
?>
<?php sales_ora12_enqueue_assets(); ?>

<div class="dashboard-ora sales-ora12-screen sales-ora-list-page sales-inv-list-page pur-invoices-list-page"
     data-exit-url="<?= esc($exitUrl) ?>">
    <?php sales_ora12_render_title_bar('اعتماد طلبات الشراء'); ?>
    <?php sales_ora12_workspace_open(); ?>
    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> sales-ora-flash"><?= esc($flash['message']) ?></div>
    <?php endif; ?>

    <div class="sales-ora-toolbar toolbar">
        <a class="btn btn-primary btn-sm" href="<?= esc($newUrl) ?>">➕ طلب شراء</a>
    </div>

    <div class="sales-ora-panel card">
        <div class="sales-ora-filter-tabs">
            <a class="<?= $filter === 'pending' ? 'is-active' : '' ?>" href="<?= esc(pur_order_list_filter_url($listUrl, 'pending', $search)) ?>">بانتظار الاعتماد</a>
            <a class="<?= $filter === 'open' ? 'is-active' : '' ?>" href="<?= esc(pur_order_list_filter_url($listUrl, 'open', $search)) ?>">مفتوحة</a>
            <a class="<?= $filter === 'approved' ? 'is-active' : '' ?>" href="<?= esc(pur_order_list_filter_url($listUrl, 'approved', $search)) ?>">معتمدة</a>
            <a class="<?= $filter === 'all' ? 'is-active' : '' ?>" href="<?= esc(pur_order_list_filter_url($listUrl, 'all', $search)) ?>">الكل</a>
        </div>
        <form method="get" action="<?= esc(app_url('index.php')) ?>" class="sales-ora-search-form form-row" style="margin-top:.75rem;">
            <input type="hidden" name="r" value="purchase_orders_list">
            <input type="hidden" name="filter" value="<?= esc($filter) ?>">
            <label class="field" style="flex:1;min-width:220px;">
                <span class="field-label">بحث</span>
                <input class="input" type="search" name="q" value="<?= esc($search) ?>" placeholder="رقم طلب، مورد…">
            </label>
            <button type="submit" class="btn btn-primary btn-sm">بحث</button>
        </form>
    </div>

    <div class="sales-ora-panel card">
        <div class="table-wrap">
            <table class="data-table" id="pur-orders-approve-table">
                <thead>
                <tr>
                    <th>رقم الطلب</th>
                    <th>التاريخ</th>
                    <th>المورد</th>
                    <th class="col-money">الإجمالي</th>
                    <th>الحالة</th>
                    <th style="width:8rem;">إجراء</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="6" class="muted" style="text-align:center;padding:1.25rem;">لا توجد طلبات.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row):
                    $stLabel = pur_order_status_label((string) ($row['status'] ?? ''));
                    $canApprove = in_array((string) ($row['status'] ?? ''), ['draft', 'submitted'], true);
                    ?>
                    <tr data-order-id="<?= (int) $row['id'] ?>">
                        <td><a href="<?= esc($viewBase . (int) $row['id']) ?>"><?= esc((string) $row['order_no']) ?></a></td>
                        <td><?= esc(format_date_dmY((string) $row['order_date'])) ?></td>
                        <td><?= esc((string) $row['supplier_name']) ?></td>
                        <td class="col-money"><?= esc(format_amount((float) $row['total'])) ?></td>
                        <td><?= esc($stLabel) ?></td>
                        <td>
                            <?php if ($canApprove && user_can_action('action_approve_purchase_order')): ?>
                                <button type="button" class="btn btn-primary btn-sm js-po-approve"
                                        data-id="<?= (int) $row['id'] ?>">اعتماد</button>
                            <?php else: ?>
                                <a class="btn btn-ghost btn-sm" href="<?= esc($viewBase . (int) $row['id']) ?>">عرض</a>
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

<script>
(function () {
  var api = <?= json_encode($apiApprove, JSON_UNESCAPED_UNICODE) ?>;
  var csrf = <?= json_encode($csrf, JSON_UNESCAPED_UNICODE) ?>;
  document.querySelectorAll('.js-po-approve').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var id = parseInt(btn.getAttribute('data-id') || '0', 10);
      if (id < 1 || !api) return;
      if (!window.AppDialog || !AppDialog.confirm) {
        if (!confirm('اعتماد طلب الشراء؟')) return;
      } else {
        AppDialog.confirm('اعتماد طلب الشراء؟', { title: 'اعتماد', okText: 'اعتماد' }).then(function (ok) {
          if (!ok) return;
          doApprove(id, btn);
        });
        return;
      }
      doApprove(id, btn);
    });
  });
  function doApprove(id, btn) {
    var fd = new FormData();
    fd.append('_csrf', csrf);
    fd.append('order_id', String(id));
    fetch(api, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok) {
          if (window.AppDialog) AppDialog.error((data && data.message) || 'تعذر الاعتماد.');
          else alert((data && data.message) || 'تعذر الاعتماد.');
          return;
        }
        if (window.AppDialog) AppDialog.success(data.message || 'تم الاعتماد.').then(function () { location.reload(); });
        else { alert(data.message || 'تم'); location.reload(); }
      });
  }
})();
</script>
