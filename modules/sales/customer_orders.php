<?php
declare(strict_types=1);
require_once app_path('includes/sal_customer_order.php');
require_once app_path('includes/sales_oracle12_ui.php');
$pdo = db();
sal_customer_order_ensure_schema($pdo);
$q = trim((string) ($_GET['q'] ?? ''));
$rows = sal_customer_order_list_fetch($pdo, $q);
$activeRoute = 'sales_customer_orders';
sales_ora12_enqueue_assets();
?>
<div class="dashboard-ora sales-ora12-screen">
<?php sales_ora12_render_title_bar('طلبات شراء العملاء', '', $activeRoute); ?>
<?php sales_ora12_workspace_open(); ?>
<div class="sales-ora-panel card"><h2>طلبات شراء العملاء</h2>
<form method="get" action="<?= esc(app_url('index.php')) ?>" class="form-row"><input type="hidden" name="r" value="sales_customer_orders"><input class="input" name="q" value="<?= esc($q) ?>" placeholder="رقم الطلب أو العميل أو المندوب"><button class="btn btn-primary">بحث</button></form></div>
<div class="sales-ora-panel card"><div class="table-wrap"><table class="data-table"><thead><tr><th>رقم الطلب</th><th>التاريخ</th><th>العميل</th><th>المندوب</th><th>المستودع</th><th>البنود</th><th>الحالة</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><code><?= esc((string) $r['order_no']) ?></code></td><td><?= esc(format_date_dmY((string) $r['order_date'])) ?></td><td><?= esc((string) $r['customer_name']) ?></td><td><?= esc((string) ($r['sales_rep_name'] ?: '—')) ?></td><td><?= esc((string) $r['warehouse_name']) ?></td><td><?= (int) $r['line_count'] ?></td><td><span class="badge <?= $r['status'] === 'approved' ? 'badge-posted' : 'badge-warn' ?>"><?= esc(sal_customer_order_status_label((string) $r['status'])) ?></span></td><td><a class="btn btn-sm" href="<?= esc(app_url('index.php?r=sales_customer_orders_approve&id=' . (int) $r['id'])) ?>">عرض</a></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="8" class="muted">لا توجد طلبات.</td></tr><?php endif; ?></tbody></table></div></div>
<?php sales_ora12_workspace_close(); ?>
</div>
