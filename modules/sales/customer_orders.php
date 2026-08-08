<?php
declare(strict_types=1);

require_once app_path('includes/sal_customer_order.php');
require_once app_path('includes/sales_oracle12_ui.php');
require_once app_path('includes/list_pagination.php');
require_once app_path('includes/crm_sales_rep_schema.php');

$pdo = db();
sal_customer_order_ensure_schema($pdo);
crm_sales_rep_ensure_schema($pdo);

$q = trim((string) ($_GET['q'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? 'all'));
if (!in_array($statusFilter, ['all', 'draft', 'approved'], true)) {
    $statusFilter = 'all';
}
$salesRepId = isset($_GET['sales_rep_id']) && $_GET['sales_rep_id'] !== '' ? (int) $_GET['sales_rep_id'] : 0;
$customerId = isset($_GET['customer_id']) && $_GET['customer_id'] !== '' ? (int) $_GET['customer_id'] : 0;
$filterRep = $salesRepId > 0 ? $salesRepId : null;
$filterCust = $customerId > 0 ? $customerId : null;
$status = $statusFilter === 'all' ? null : $statusFilter;

$pager = list_pager_from_request($pdo);
$total = sal_customer_order_list_count($pdo, $q, $filterRep, $status, $filterCust);
$pager = list_pager_with_total($pager, $total);
$rows = sal_customer_order_list_fetch(
    $pdo,
    $q,
    $filterRep,
    $status,
    $filterCust,
    $pager['limit'],
    $pager['offset']
);

$salesReps = $pdo->query(
    'SELECT id, name_ar FROM crm_sales_rep WHERE is_active = 1 ORDER BY name_ar'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$customers = $pdo->query(
    'SELECT id, code, name_ar FROM crm_customer WHERE is_active = 1 ORDER BY name_ar'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$filterQuery = array_filter([
    'q' => $q !== '' ? $q : null,
    'status' => $statusFilter !== 'all' ? $statusFilter : null,
    'sales_rep_id' => $salesRepId > 0 ? $salesRepId : null,
    'customer_id' => $customerId > 0 ? $customerId : null,
], static fn ($v) => $v !== null && $v !== '');
$pagerUrl = list_pager_base_url('sales_customer_orders', $filterQuery);

$activeRoute = 'sales_customer_orders';
$canApprove = user_can('sales_customer_orders_approve');
$canApproved = $canApprove;
$canCreate = sal_customer_order_user_can_edit_drafts();
$newOrderUrl = app_url('index.php?r=sales_customer_order_entry');
sales_ora12_enqueue_assets();
?>
<div class="dashboard-ora sales-ora12-screen">
<?php sales_ora12_render_title_bar('طلبات شراء العملاء', '', $activeRoute); ?>
<?php sales_ora12_workspace_open(); ?>
<div class="sales-ora-panel card">
    <h2>طلبات شراء العملاء</h2>
    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="form-row" style="flex-wrap:wrap;gap:0.5rem;align-items:end;">
        <input type="hidden" name="r" value="sales_customer_orders">
        <div class="field">
            <label>بحث</label>
            <input class="input" name="q" value="<?= esc($q) ?>" placeholder="رقم الطلب أو العميل أو المندوب">
        </div>
        <div class="field">
            <label>الحالة</label>
            <select class="input" name="status">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>الكل</option>
                <option value="draft" <?= $statusFilter === 'draft' ? 'selected' : '' ?>>مسودة</option>
                <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>معتمد</option>
            </select>
        </div>
        <div class="field">
            <label>المندوب</label>
            <select class="input" name="sales_rep_id">
                <option value="">جميع المندوبين</option>
                <?php foreach ($salesReps as $rep): ?>
                    <option value="<?= (int) $rep['id'] ?>" <?= $salesRepId === (int) $rep['id'] ? 'selected' : '' ?>>
                        <?= esc((string) $rep['name_ar']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>العميل / الشركة</label>
            <select class="input" name="customer_id">
                <option value="">جميع العملاء</option>
                <?php foreach ($customers as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= $customerId === (int) $c['id'] ? 'selected' : '' ?>>
                        <?= esc((string) $c['name_ar']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-primary">تصفية</button>
        <?php if ($canCreate): ?>
            <a class="btn btn-primary" href="<?= esc($newOrderUrl) ?>">+ طلب شراء عميل جديد</a>
        <?php endif; ?>
        <?php if ($canApprove): ?>
            <a class="btn btn-secondary" href="<?= esc(app_url('index.php?r=sales_customer_orders_approve')) ?>">اعتماد الطلبات</a>
        <?php endif; ?>
        <?php if ($canApproved): ?>
            <a class="btn btn-secondary" href="<?= esc(app_url('index.php?r=sales_customer_orders_approved')) ?>">الطلبات المعتمدة</a>
        <?php endif; ?>
    </form>
</div>
<div class="sales-ora-panel card">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>رقم الطلب</th>
                <th>التاريخ</th>
                <th>العميل</th>
                <th>المندوب</th>
                <th>المستودع</th>
                <th>البنود</th>
                <th>الحالة</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r):
                $isApproved = (string) $r['status'] === 'approved';
                if ($isApproved) {
                    $viewRoute = 'sales_customer_orders_approved';
                    $viewLabel = 'عرض';
                    $canOpen = $canApproved || user_can('sales_customer_orders');
                    $openOk = $canApproved;
                } else {
                    $viewRoute = 'sales_customer_order_entry';
                    $viewLabel = $canCreate ? 'تعديل' : 'عرض';
                    $canOpen = $canCreate || $canApprove;
                    $openOk = $canOpen;
                    if (!$canCreate && $canApprove) {
                        $viewRoute = 'sales_customer_orders_approve';
                    }
                }
                ?>
                <tr>
                    <td><code><?= esc((string) $r['order_no']) ?></code></td>
                    <td><?= esc(format_date_dmY((string) $r['order_date'])) ?></td>
                    <td><?= esc((string) $r['customer_name']) ?></td>
                    <td><?= esc((string) ($r['sales_rep_name'] ?: '—')) ?></td>
                    <td><?= esc((string) $r['warehouse_name']) ?></td>
                    <td><?= (int) $r['line_count'] ?></td>
                    <td>
                        <span class="badge <?= $isApproved ? 'badge-posted' : 'badge-warn' ?>">
                            <?= esc(sal_customer_order_status_label((string) $r['status'])) ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($openOk): ?>
                            <a class="btn btn-sm" href="<?= esc(app_url('index.php?r=' . $viewRoute . '&id=' . (int) $r['id'])) ?>"><?= esc($viewLabel) ?></a>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="8" class="muted">لا توجد طلبات.<?php if ($canCreate): ?> <a href="<?= esc($newOrderUrl) ?>">إنشاء طلب جديد</a><?php endif; ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php list_pager_render($pager, $pagerUrl); ?>
</div>
<?php sales_ora12_workspace_close(); ?>
</div>
