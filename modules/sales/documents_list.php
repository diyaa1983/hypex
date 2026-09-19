<?php
declare(strict_types=1);

require_once app_path('includes/sal_documents_list.php');
require_once app_path('includes/sal_einvoice_tracking.php');
require_once app_path('includes/list_pagination.php');
require_once app_path('includes/sales_oracle12_ui.php');
require_once app_path('includes/nav_helpers.php');

$pdo = db();
sal_invoice_ensure_schema($pdo);
sal_return_ensure_schema($pdo);

$search = trim((string) ($_GET['q'] ?? ''));
$listUrl = app_url('index.php?r=sales_documents_list');
$newInvoiceUrl = app_url('index.php?r=sales_invoices');
$exitUrl = nav_exit_url('sales_documents_list');

$pager = list_pager_from_request($pdo);
$result = sal_invoices_list_fetch($pdo, $search, $pager);
$rows = $result['rows'];
$pager = $result['pager'];

$listPagerQuery = [];
if ($search !== '') {
    $listPagerQuery['q'] = $search;
}
$listPagerUrl = list_pager_base_url('sales_documents_list', $listPagerQuery);

$flash = flash_get();

?>
<?php sales_ora12_enqueue_assets(); ?>

<div class="dashboard-ora sales-ora12-screen sales-ora-list-page sales-inv-list-page sal-documents-list-page sal-invoices-list-page"
     data-exit-url="<?= esc($exitUrl) ?>">
    <?php sales_ora12_render_title_bar('قائمة فواتير المبيعات'); ?>
    <?php sales_ora12_workspace_open(); ?>
    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> sales-ora-flash"><?= esc($flash['message']) ?></div>
    <?php endif; ?>

    <div class="sales-ora-toolbar toolbar">
        <a class="btn btn-primary btn-sm" href="<?= esc($newInvoiceUrl) ?>">➕ فاتورة مبيعات</a>
    </div>

    <div class="sales-ora-panel card">
        <form method="get" action="<?= esc(app_url('index.php')) ?>" class="sales-ora-search-form form-row">
            <input type="hidden" name="r" value="sales_documents_list">
            <label class="field" style="flex:1;min-width:220px;">
                <span class="field-label">بحث</span>
                <input class="input" type="search" name="q" value="<?= esc($search) ?>"
                       placeholder="رقم فاتورة، عميل، كود عميل، مندوب…" autocomplete="off">
            </label>
            <button type="submit" class="btn btn-primary btn-sm">بحث</button>
            <?php if ($search !== ''): ?>
                <a class="btn btn-ghost btn-sm" href="<?= esc($listUrl) ?>">مسح</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="sales-ora-panel card">
        <div class="table-wrap">
            <table class="data-table" id="sal-invoices-table">
                <thead>
                <tr>
                    <th class="col-seq" style="width:3rem;">تسلسل</th>
                    <th>رقم الفاتورة</th>
                    <th>التاريخ</th>
                    <th>اسم العميل</th>
                    <th>اسم المندوب</th>
                    <th class="col-money">الإجمالي (شامل)</th>
                    <th>الترحيل</th>
                    <th>الضريبة</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="8" class="muted" style="text-align:center;padding:1.25rem;">
                            لا توجد فواتير مطابقة.
                        </td>
                    </tr>
                <?php endif; ?>
                <?php
                $seq = (int) ($pager['offset'] ?? 0);
                foreach ($rows as $row):
                    $seq++;
                    $docId = (int) ($row['doc_id'] ?? 0);
                    $posted = !empty($row['is_posted']);
                    $einvSent = !empty($row['einv_sent']);
                    $einvTracking = sal_einvoice_doc_date_requires_tracking((string) ($row['doc_date'] ?? ''));
                    $returnCount = (int) ($row['return_count'] ?? 0);
                    $returnNos = trim((string) ($row['return_nos'] ?? ''));
                    $openUrl = sal_documents_list_invoice_open_url($docId);
                    ?>
                    <tr class="sal-doc-list-row is-clickable"
                        data-href="<?= esc($openUrl) ?>"
                        tabindex="0"
                        role="link"
                        title="فتح الفاتورة">
                        <td class="col-seq"><?= $seq ?></td>
                        <td>
                            <code><?= esc((string) ($row['doc_no'] ?? '')) ?></code>
                            <?php if ($returnCount > 0): ?>
                                <span class="badge badge-warn sal-inv-has-return">مرتجعة</span>
                                <?php if ($returnNos !== ''): ?>
                                    <div class="muted sal-return-nos-hint">
                                        مرتجع: <code><?= esc($returnNos) ?></code>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td><?= esc(format_date_dmY((string) ($row['doc_date'] ?? ''))) ?></td>
                        <td><?= esc((string) ($row['customer_name'] ?? '')) ?></td>
                        <td><?= esc(trim((string) ($row['sales_rep_name'] ?? '')) !== '' ? (string) $row['sales_rep_name'] : '—') ?></td>
                        <td class="col-money"><?= esc(format_amount((float) ($row['total'] ?? 0))) ?></td>
                        <td>
                            <?php if ($posted): ?>
                                <span class="badge badge-posted">مرحّلة</span>
                            <?php else: ?>
                                <span class="badge badge-warn">غير مرحّلة</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$einvTracking): ?>
                                <span class="muted">—</span>
                            <?php elseif ($einvSent): ?>
                                <span class="badge badge-einv-sent">مرسلة</span>
                            <?php else: ?>
                                <span class="badge badge-off">غير مرسلة</span>
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
