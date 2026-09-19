<?php
declare(strict_types=1);

$pdo = db();
require_once app_path('includes/fin_voucher_schema.php');
require_once app_path('includes/crm_customer_ledger.php');
require_once app_path('includes/fin_voucher_list.php');
require_once app_path('includes/sales_oracle12_ui.php');
require_once app_path('includes/nav_helpers.php');

fin_voucher_ensure_schema_full($pdo);
crm_ledger_ensure_schema($pdo);

$flash = flash_get();
$filter = (string) ($_GET['filter'] ?? 'all');
$search = trim((string) ($_GET['q'] ?? ''));

$list = fin_voucher_list_fetch($pdo, 'receipt', $filter, $search);
$rows = $list['rows'];
$pager = $list['pager'];
$listPagerQuery = [];
if ($filter !== 'all') {
    $listPagerQuery['filter'] = $filter;
}
if ($search !== '') {
    $listPagerQuery['q'] = $search;
}
$listPagerUrl = list_pager_base_url('cash_receipts_list', $listPagerQuery);
$totalRc = $list['total'];
$filteredRc = (int) ($list['filtered_total'] ?? 0);
$sumAmount = (float) ($list['sum_amount'] ?? 0);
$unpostedRc = $list['unposted'];

$listUrl = app_url('index.php?r=cash_receipts_list');
$newUrl = app_url('index.php?r=cash_receipt');
$viewBase = app_url('index.php?r=cash_receipt&id=');
$apiPost = app_url('api/fin_receipt_post.php');
$apiDelete = app_url('api/fin_receipt_delete.php');
$csrf = csrf_token();
$exitUrl = nav_exit_url('cash_receipts_list');

function fin_rc_list_filter_url(string $base, string $f, string $q): string
{
    $url = $base . '&filter=' . rawurlencode($f);
    if ($q !== '') {
        $url .= '&q=' . rawurlencode($q);
    }

    return $url;
}
?>
<?php sales_ora12_enqueue_assets(); ?>

<div class="dashboard-ora sales-ora12-screen sales-ora-list-page sales-inv-list-page"
     data-exit-url="<?= esc($exitUrl) ?>">
    <?php sales_ora12_render_title_bar('ترحيل سندات القبض'); ?>
    <?php sales_ora12_workspace_open(); ?>

    <div class="sales-ora-toolbar toolbar" id="fin-receipts-list-screen"
         data-post-url="<?= esc($apiPost) ?>"
         data-delete-url="<?= esc($apiDelete) ?>"
         data-csrf="<?= esc($csrf) ?>">
        <a class="btn btn-primary btn-sm" href="<?= esc($newUrl) ?>">➕ سند قبض جديد</a>
        <button type="button" class="btn btn-secondary btn-sm" id="fin-rc-post-selected" disabled>ترحيل المحدد</button>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> sales-ora-flash"><?= esc($flash['message']) ?></div>
    <?php endif; ?>

    <p class="sales-ora-info muted">
    عرض سندات القبض وتصفيتها وترحيلها. الحفظ لا يؤثر على حساب العميل؛ عند الترحيل يُسجَّل قيد دائن على حساب العميل (دفعة).
    غير مرحّلة: <strong id="fin-rc-unposted-count"><?= (int) $unpostedRc ?></strong>
    — عدد السندات (حسب التصفية): <strong><?= (int) $filteredRc ?></strong>
    — مجموع المبالغ (حسب التصفية): <strong><?= esc(format_amount($sumAmount)) ?></strong>
    </p>

    <div class="sales-ora-panel card">
        <form method="get" action="<?= esc(app_url('index.php')) ?>" class="sales-ora-search-form form-row">
        <input type="hidden" name="r" value="cash_receipts_list">
        <input type="hidden" name="filter" value="<?= esc($filter) ?>">
        <label class="field" style="flex:1;min-width:200px;margin:0;">
            <span class="field-label">بحث (رقم سند، عميل)</span>
            <input class="input" type="search" name="q" value="<?= esc($search) ?>" placeholder="بحث…">
        </label>
        <button type="submit" class="btn btn-secondary btn-sm">بحث</button>
        <?php if ($search !== ''): ?>
            <a class="btn btn-ghost btn-sm" href="<?= esc(fin_rc_list_filter_url($listUrl, $filter, '')) ?>">مسح</a>
        <?php endif; ?>
        </form>
    </div>

    <div class="sales-ora-tabs sal-inv-list-tabs">
    <a class="btn btn-sm <?= $filter === 'all' ? 'btn-primary' : 'btn-secondary' ?>"
       href="<?= esc(fin_rc_list_filter_url($listUrl, 'all', $search)) ?>">الكل</a>
    <a class="btn btn-sm <?= $filter === 'unposted' ? 'btn-primary' : 'btn-secondary' ?>"
       href="<?= esc(fin_rc_list_filter_url($listUrl, 'unposted', $search)) ?>">غير مرحّلة</a>
    <a class="btn btn-sm <?= $filter === 'posted' ? 'btn-primary' : 'btn-secondary' ?>"
       href="<?= esc(fin_rc_list_filter_url($listUrl, 'posted', $search)) ?>">مرحّلة</a>
    </div>

    <div class="sales-ora-panel card">
    <div class="table-wrap">
        <table class="data-table" id="fin-receipts-table">
            <thead>
            <tr>
                <th style="width:2.5rem;"><input type="checkbox" id="fin-rc-check-all" title="تحديد الكل"></th>
                <th>رقم السند</th>
                <th>التاريخ</th>
                <th>العميل</th>
                <th>الدفع</th>
                <th>المبلغ</th>
                <th>الترحيل</th>
                <th>إجراءات</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="8" class="muted" style="text-align:center;">لا توجد سندات مطابقة.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $v): ?>
                <?php
                $posted = !empty($v['is_posted']);
                $cancelled = !empty($v['is_cancelled']);
                $pay = fin_voucher_pay_method_label((string) ($v['pay_method'] ?? 'cash'));
                $canPost = (string) ($v['party_type'] ?? '') === 'customer' && (int) ($v['party_id'] ?? 0) > 0;
                ?>
                <tr data-voucher-id="<?= (int) $v['id'] ?>" data-posted="<?= $posted ? '1' : '0' ?>">
                    <td>
                        <?php if (!$posted && !$cancelled && $canPost): ?>
                            <input type="checkbox" class="fin-rc-row-check" value="<?= (int) $v['id'] ?>">
                        <?php endif; ?>
                    </td>
                    <td><code class="<?= $cancelled ? 'voucher-no-is-cancelled' : '' ?>"><?= esc((string) $v['voucher_no']) ?></code></td>
                    <td><?= esc(format_date_dmY((string) ($v['voucher_date'] ?? ''))) ?></td>
                    <td><?= esc((string) ($v['party_name'] ?? '—')) ?></td>
                    <td><?= esc($pay) ?></td>
                    <td><?= esc(format_amount((float) $v['amount'])) ?></td>
                    <td>
                        <?php if ($cancelled): ?>
                            <span class="badge badge-cancelled-voucher">ملغى</span>
                        <?php elseif ($posted): ?>
                            <span class="badge badge-ok">مرحّل</span>
                        <?php else: ?>
                            <span class="badge badge-warn">غير مرحّل</span>
                        <?php endif; ?>
                    </td>
                    <td class="row-actions">
                        <a class="btn btn-secondary btn-sm" href="<?= esc($viewBase . (int) $v['id']) ?>">عرض</a>
                        <?php if (!$posted && !$cancelled && $canPost): ?>
                            <button type="button" class="btn btn-primary btn-sm fin-rc-post-one"
                                    data-id="<?= (int) $v['id'] ?>">ترحيل</button>
                            <button type="button" class="btn btn-danger btn-sm fin-rc-delete-one"
                                    data-id="<?= (int) $v['id'] ?>"
                                    data-no="<?= esc((string) $v['voucher_no']) ?>">حذف</button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <?php if ($rows): ?>
            <tfoot>
            <tr class="fin-voucher-list-total">
                <td colspan="5"><strong>المجموع</strong>
                    <span class="muted" style="font-weight:400;"> — <?= (int) $filteredRc ?> سند</span>
                </td>
                <td class="col-money"><strong><?= esc(format_amount($sumAmount)) ?></strong></td>
                <td colspan="2"></td>
            </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
    <?php list_pager_render($pager, $listPagerUrl); ?>
    </div>
    <?php sales_ora12_workspace_close(); ?>
</div>

<?php
$jsPath = app_path('assets/js/fin-receipts-list.js');
$jsUrl = app_url('assets/js/fin-receipts-list.js') . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : '');
?>
<script src="<?= esc($jsUrl) ?>" defer></script>
