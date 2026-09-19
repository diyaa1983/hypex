<?php
declare(strict_types=1);

/** @var string $finVoucherType receipt|payment */
/** @var string $finVoucherRoute */
/** @var string $finVoucherTitle */

require_once app_path('includes/fin_voucher.php');
require_once app_path('includes/acc_journal.php');

$listUrl = app_url('index.php?r=' . rawurlencode($finVoucherRoute));
$pdo = db();

if (!fin_voucher_ensure_schema($pdo)) {
    echo '<div class="card"><p class="alert alert-error">تعذر إنشاء جدول السندات. نفّذ <code>database/migrations/027_fin_voucher.sql</code> ثم حدّث الصفحة.</p></div>';
    return;
}

$cashAccounts = fin_voucher_load_cash_accounts($pdo);
$hasCheckNo = fin_voucher_has_check_no($pdo);

$customers = $pdo->query(
    'SELECT id, code, name_ar FROM crm_customer WHERE is_active = 1 ORDER BY name_ar'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$suppliers = $pdo->query(
    'SELECT id, code, name_ar FROM crm_supplier WHERE is_active = 1 ORDER BY name_ar'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        flash_set('error', 'جلسة غير صالحة، أعد المحاولة.');
        redirect($listUrl);
    }

    $act = (string) ($_POST['_action'] ?? '');
    try {
        if ($act === 'save') {
            $id = (int) ($_POST['id'] ?? 0);
            $voucherDate = parse_date_to_iso(trim((string) ($_POST['voucher_date'] ?? ''))) ?? '';
            if ($voucherDate === '') {
                throw new RuntimeException('تاريخ السند غير صالح.');
            }
            fin_voucher_save(
                $pdo,
                $finVoucherType,
                $id,
                trim((string) ($_POST['voucher_no'] ?? '')),
                $voucherDate,
                (float) ($_POST['amount'] ?? 0),
                trim((string) ($_POST['description'] ?? '')),
                trim((string) ($_POST['check_no'] ?? '')),
                trim((string) ($_POST['party_type'] ?? 'other')),
                (int) ($_POST['party_id'] ?? 0),
                (int) ($_POST['cash_account_id'] ?? 0)
            );
            flash_set('success', $id > 0 ? 'تم تحديث السند.' : 'تم حفظ السند.');
        } elseif ($act === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            fin_voucher_delete($pdo, $id, $finVoucherType);
            flash_set('success', 'تم حذف السند.');
        } else {
            throw new RuntimeException('إجراء غير معروف.');
        }
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
    } catch (Throwable $e) {
        flash_set('error', 'تعذر تنفيذ العملية.');
    }

    redirect($listUrl);
}

$flash = flash_get();
$action = (string) ($_GET['action'] ?? 'list');
$defaultParty = $finVoucherType === 'payment' ? 'supplier' : 'customer';

if ($action === 'add' || $action === 'edit') {
    if (!$cashAccounts) {
        echo '<div class="card"><p class="alert alert-error">لا توجد حسابات صندوق/بنك في دليل الحسابات. نفّذ ترحيل <code>026_acc_journal_tables.sql</code> أولاً.</p></div>';
        return;
    }

    $row = [
        'id' => 0,
        'voucher_no' => fin_voucher_next_no($pdo, $finVoucherType, date('Y-m-d')),
        'voucher_date' => date('Y-m-d'),
        'amount' => '',
        'description' => '',
        'check_no' => '',
        'party_type' => $defaultParty,
        'party_id' => 0,
        'cash_account_id' => (int) ($cashAccounts[0]['id'] ?? 0),
    ];

    if ($action === 'edit') {
        $id = (int) ($_GET['id'] ?? 0);
        $dbRow = fin_voucher_load($pdo, $id, $finVoucherType);
        if (!$dbRow) {
            flash_set('error', 'السند غير موجود.');
            redirect($listUrl);
        }
        $row = array_merge($row, $dbRow);
    }

    $partyIdVal = (int) ($row['party_id'] ?? 0);
    $partyTypeVal = (string) ($row['party_type'] ?? $defaultParty);
    $custPickVal = $partyTypeVal === 'customer' && $partyIdVal > 0 ? $partyIdVal : '';
    $suppPickVal = $partyTypeVal === 'supplier' && $partyIdVal > 0 ? $partyIdVal : '';

    $jsPath = app_path('assets/js/fin-voucher.js');
    $jsUrl = app_url('assets/js/fin-voucher.js') . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : '');
    require_once app_path('includes/customer_picker.php');
    customer_picker_enqueue_assets();
    customer_picker_json_script($customers, 'fin-customers-json');
    ?>
    <link rel="stylesheet" href="<?= esc(app_url('assets/css/report-sales.css')) ?>">
    <div class="toolbar">
        <h2 style="margin:0;font-size:1.05rem;"><?= esc($action === 'add' ? 'إضافة ' . $finVoucherTitle : 'تعديل ' . $finVoucherTitle) ?></h2>
        <a class="btn btn-secondary btn-sm" href="<?= esc($listUrl) ?>">رجوع</a>
    </div>
    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>"><?= esc($flash['message']) ?></div>
    <?php endif; ?>
    <div class="card fin-voucher-form-card">
        <form method="post" action="<?= esc($listUrl) ?>" id="fin-voucher-form" class="fin-voucher-form">
            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="_action" value="save">
            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
            <input type="hidden" name="party_id" id="fin-party-id" value="<?= $partyIdVal > 0 ? $partyIdVal : '' ?>">

            <div class="form-row">
                <label class="field">
                    <span class="field-label">رقم السند</span>
                    <input class="input" name="voucher_no" value="<?= esc((string) $row['voucher_no']) ?>">
                </label>
                <label class="field">
                    <span class="field-label">التاريخ *</span>
                    <input class="input js-date-dmy" type="text" name="voucher_date" required
                           value="<?= esc(format_date_dmY((string) $row['voucher_date'])) ?>"
                           placeholder="يوم-شهر-سنة" dir="ltr" autocomplete="off">
                </label>
                <label class="field">
                    <span class="field-label">المبلغ *</span>
                    <input class="input" type="text" name="amount" id="fin-amount" required dir="ltr"
                           value="<?= (float) ($row['amount'] ?? 0) > 0 ? esc(format_money((float) $row['amount'])) : '' ?>">
                </label>
                <label class="field">
                    <span class="field-label">حساب النقدية *</span>
                    <select class="input" name="cash_account_id" required>
                        <?php foreach ($cashAccounts as $acc): ?>
                            <option value="<?= (int) $acc['id'] ?>"<?= (int) $row['cash_account_id'] === (int) $acc['id'] ? ' selected' : '' ?>>
                                <?= esc($acc['code'] . ' — ' . $acc['name_ar']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <div class="form-row">
                <label class="field">
                    <span class="field-label">نوع الطرف</span>
                    <select class="input" name="party_type" id="fin-party-type">
                        <option value="customer"<?= $partyTypeVal === 'customer' ? ' selected' : '' ?>>عميل</option>
                        <option value="supplier"<?= $partyTypeVal === 'supplier' ? ' selected' : '' ?>>مورد</option>
                        <option value="other"<?= $partyTypeVal === 'other' ? ' selected' : '' ?>>أخرى</option>
                    </select>
                </label>
                <div id="fin-pick-customer-wrap" class="fin-party-pick fin-party-pick-customer">
                    <?= customer_picker_field([
                        'id' => 'fin_cust_hidden',
                        'name' => null,
                        'value' => $custPickVal,
                        'label' => 'العميل',
                        'json_id' => 'fin-customers-json',
                        'manual_bind' => true,
                    ]) ?>
                </div>
                <label class="field fin-party-pick fin-party-pick-supplier" id="fin-pick-supplier-wrap">
                    <span class="field-label">المورد</span>
                    <div class="report-cust-pick" id="fin-supp-pick">
                        <input type="hidden" data-supp-id value="<?= $suppPickVal !== '' ? esc((string) $suppPickVal) : '' ?>">
                        <input type="text" class="input report-cust-pick-inp" data-supp-search autocomplete="off"
                               placeholder="ابحث بالاسم أو الرمز">
                        <div class="report-cust-pick-list" data-supp-list hidden></div>
                    </div>
                </label>
            </div>

            <div class="form-row">
                <?php if ($hasCheckNo): ?>
                <label class="field">
                    <span class="field-label">رقم الشيك</span>
                    <input class="input" name="check_no" value="<?= esc((string) ($row['check_no'] ?? '')) ?>">
                </label>
                <?php endif; ?>
                <label class="field field-grow">
                    <span class="field-label">البيان</span>
                    <input class="input" name="description" value="<?= esc((string) ($row['description'] ?? '')) ?>">
                </label>
            </div>

            <div style="margin-top:1rem;">
                <button type="submit" class="btn btn-primary">حفظ</button>
            </div>
        </form>
    </div>
    <script type="application/json" id="fin-suppliers-json"><?= json_encode($suppliers, JSON_UNESCAPED_UNICODE) ?: '[]' ?></script>
    <script src="<?= esc($jsUrl) ?>"></script>
    <?php
    return;
}

$q = trim((string) ($_GET['q'] ?? ''));
$sql = 'SELECT v.id, v.voucher_no, v.voucher_date, v.amount, v.description, v.party_type, v.party_id,
               a.name_ar AS cash_account_name
        FROM fin_voucher v
        INNER JOIN acc_account a ON a.id = v.cash_account_id
        WHERE v.voucher_type = ?';
$params = [$finVoucherType];
if ($q !== '') {
    $sql .= ' AND (v.voucher_no LIKE ? OR v.description LIKE ?)';
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
}
require_once app_path('includes/list_pagination.php');

$countSql = 'SELECT COUNT(*) FROM fin_voucher v WHERE v.voucher_type = ?';
$countParams = [$finVoucherType];
if ($q !== '') {
    $countSql .= ' AND (v.voucher_no LIKE ? OR v.description LIKE ?)';
    $countParams[] = $like;
    $countParams[] = $like;
}
$stCount = $pdo->prepare($countSql);
$stCount->execute($countParams);
$listTotal = (int) $stCount->fetchColumn();
$pager = list_pager_with_total(list_pager_from_request($pdo), $listTotal);
$listPagerUrl = list_pager_base_url($finVoucherRoute, $q !== '' ? ['q' => $q] : []);

$sql .= ' ORDER BY v.voucher_date DESC, v.id DESC' . list_pager_sql_limit($pager);
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>
<div class="toolbar">
    <a class="btn btn-primary btn-sm" href="<?= esc(app_url('index.php?r=' . rawurlencode($finVoucherRoute) . '&action=add')) ?>">+ <?= esc($finVoucherTitle) ?></a>
</div>
<?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>"><?= esc($flash['message']) ?></div>
<?php endif; ?>
<div class="card">
    <form method="get" class="form-row" style="margin-bottom:1rem;">
        <input type="hidden" name="r" value="<?= esc($finVoucherRoute) ?>">
        <label class="field field-grow">
            <span class="field-label">بحث</span>
            <input class="input" type="search" name="q" value="<?= esc($q) ?>" placeholder="رقم السند أو البيان">
        </label>
        <div class="field" style="align-self:flex-end;">
            <button type="submit" class="btn btn-secondary btn-sm">بحث</button>
        </div>
    </form>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>رقم السند</th>
                <th>التاريخ</th>
                <th>الطرف</th>
                <th>المبلغ</th>
                <th>الحساب النقدي</th>
                <th>البيان</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="7" class="muted" style="text-align:center;">لا توجد سندات.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $v):
                $pt = (string) ($v['party_type'] ?? 'other');
                $pid = (int) ($v['party_id'] ?? 0);
                if ($pt === 'customer') {
                    $partyLabel = 'عميل: ' . fin_voucher_party_name($pdo, 'customer', $pid);
                } elseif ($pt === 'supplier') {
                    $partyLabel = 'مورد: ' . fin_voucher_party_name($pdo, 'supplier', $pid);
                } else {
                    $partyLabel = '—';
                }
                ?>
                <tr>
                    <td><code><?= esc((string) $v['voucher_no']) ?></code></td>
                    <td><?= esc(format_date_dmY((string) $v['voucher_date'])) ?></td>
                    <td><?= esc($partyLabel) ?></td>
                    <td class="col-money"><?= esc(format_money((float) $v['amount'])) ?></td>
                    <td><?= esc((string) $v['cash_account_name']) ?></td>
                    <td><?= esc((string) ($v['description'] ?? '')) ?></td>
                    <td>
                        <div class="row-actions">
                            <a class="btn btn-secondary btn-sm" href="<?= esc(app_url('index.php?r=' . rawurlencode($finVoucherRoute) . '&action=edit&id=' . (int) $v['id'])) ?>">تعديل</a>
                            <form method="post" action="<?= esc($listUrl) ?>" data-confirm="حذف السند؟">
                                <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                                <input type="hidden" name="_action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm">حذف</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php list_pager_render($pager, $listPagerUrl); ?>
</div>
