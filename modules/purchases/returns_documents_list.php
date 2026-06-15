<?php
declare(strict_types=1);

require_once app_path('includes/pur_documents_list.php');
require_once app_path('includes/list_pagination.php');
require_once app_path('includes/sales_oracle12_ui.php');
require_once app_path('includes/nav_helpers.php');

$pdo = db();
pur_invoice_ensure_schema($pdo);
pur_return_ensure_schema($pdo);

$search = trim((string) ($_GET['q'] ?? ''));
$listUrl = app_url('index.php?r=purchase_returns_documents_list');
$newUrl = app_url('index.php?r=purchase_returns');
$exitUrl = nav_exit_url('purchase_returns_documents_list');

$pager = list_pager_from_request($pdo);
$result = pur_returns_documents_list_fetch($pdo, $search, $pager);
$rows = $result['rows'];
$pager = $result['pager'];

$listPagerQuery = [];
if ($search !== '') {
    $listPagerQuery['q'] = $search;
}
$listPagerUrl = list_pager_base_url('purchase_returns_documents_list', $listPagerQuery);

$flash = flash_get();
?>
<?php sales_ora12_enqueue_assets(); ?>

<div class="dashboard-ora sales-ora12-screen sales-ora-list-page sales-inv-list-page sal-documents-list-page pur-returns-list-page"
     data-exit-url="<?= esc($exitUrl) ?>">
    <?php sales_ora12_render_title_bar('قائمة مردودات المشتريات'); ?>
    <?php sales_ora12_workspace_open(); ?>
    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> sales-ora-flash"><?= esc($flash['message']) ?></div>
    <?php endif; ?>

    <div class="sales-ora-toolbar toolbar">
        <a class="btn btn-primary btn-sm" href="<?= esc($newUrl) ?>">➕ مردود مشتريات</a>
    </div>

    <div class="sales-ora-panel card">
        <form method="get" action="<?= esc(app_url('index.php')) ?>" class="sales-ora-search-form form-row">
            <input type="hidden" name="r" value="purchase_returns_documents_list">
            <label class="field" style="flex:1;min-width:220px;">
                <span class="field-label">بحث</span>
                <input class="input" type="search" name="q" value="<?= esc($search) ?>"
                       placeholder="رقم مردود، فاتورة مرجع، مورد…" autocomplete="off">
            </label>
            <button type="submit" class="btn btn-primary btn-sm">بحث</button>
            <?php if ($search !== ''): ?>
                <a class="btn btn-ghost btn-sm" href="<?= esc($listUrl) ?>">مسح</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="sales-ora-panel card">
        <div class="table-wrap">
            <table class="data-table" id="pur-returns-table">
                <thead>
                <tr>
                    <th class="col-seq" style="width:3rem;">تسلسل</th>
                    <th>رقم المردود</th>
                    <th>فاتورة الشراء المرجع منها</th>
                    <th>التاريخ</th>
                    <th>اسم المورد</th>
                    <th class="col-money">الإجمالي (شامل)</th>
                    <th>الترحيل</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="7" class="muted" style="text-align:center;padding:1.25rem;">
                            لا توجد مردودات مطابقة.
                        </td>
                    </tr>
                <?php endif; ?>
                <?php
                $seq = (int) ($pager['offset'] ?? 0);
                foreach ($rows as $row):
                    $seq++;
                    $docId = (int) ($row['doc_id'] ?? 0);
                    $posted = !empty($row['is_posted']);
                    $openUrl = pur_documents_list_return_open_url($docId);
                    $refNo = trim((string) ($row['ref_invoice_no'] ?? ''));
                    ?>
                    <tr class="sal-doc-list-row is-clickable"
                        data-href="<?= esc($openUrl) ?>"
                        tabindex="0"
                        role="link"
                        title="فتح المردود">
                        <td class="col-seq"><?= $seq ?></td>
                        <td><code><?= esc((string) ($row['doc_no'] ?? '')) ?></code></td>
                        <td>
                            <?php if ($refNo !== ''): ?>
                                <code><?= esc($refNo) ?></code>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= esc(format_date_dmY((string) ($row['doc_date'] ?? ''))) ?></td>
                        <td><?= esc((string) ($row['supplier_name'] ?? '')) ?></td>
                        <td class="col-money"><?= esc(format_amount((float) ($row['total'] ?? 0))) ?></td>
                        <td>
                            <?php if ($posted): ?>
                                <span class="badge badge-ok">مرحّلة</span>
                            <?php else: ?>
                                <span class="badge badge-warn">غير مرحّلة</span>
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
    function openRow(tr) {
        var href = tr.getAttribute('data-href');
        if (href) { window.location.href = href; }
    }
    document.querySelectorAll('.sal-doc-list-row.is-clickable').forEach(function (tr) {
        tr.addEventListener('click', function () { openRow(tr); });
        tr.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openRow(tr); }
        });
    });
})();
</script>
