<?php
declare(strict_types=1);

$listUrl = app_url('index.php?r=customers');

require_once app_path('includes/nav_helpers.php');
require_once app_path('includes/sales_oracle12_ui.php');

$pdo = db();
require_once app_path('includes/crm_sales_rep_schema.php');
require_once app_path('includes/crm_party_delete.php');
crm_sales_rep_ensure_customer_invoice_links($pdo);
$salesReps = crm_sales_rep_load_active($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        flash_set('error', 'جلسة غير صالحة، أعد المحاولة.');
        redirect($listUrl);
    }

    $act = (string) ($_POST['_action'] ?? '');

    try {
        if ($act === 'save') {
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim((string) ($_POST['name_ar'] ?? ''));
            $phone = trim((string) ($_POST['phone'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $tax = trim((string) ($_POST['tax_number'] ?? ''));
            $addr = trim((string) ($_POST['address_ar'] ?? ''));
            $repIdsRaw = $_POST['sales_rep_ids'] ?? [];
            if (!is_array($repIdsRaw)) {
                $repIdsRaw = [];
            }

            if ($name === '') {
                throw new RuntimeException('اسم العميل مطلوب.');
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('البريد الإلكتروني غير صالح.');
            }

            if ($id > 0) {
                $st = $pdo->prepare(
                    'UPDATE crm_customer SET name_ar=?, phone=?, email=?, tax_number=?, address_ar=? WHERE id=?'
                );
                $st->execute([
                    $name,
                    $phone !== '' ? $phone : null,
                    $email !== '' ? $email : null,
                    $tax !== '' ? $tax : null,
                    $addr !== '' ? $addr : null,
                    $id,
                ]);
                crm_customer_save_sales_reps($pdo, $id, $repIdsRaw);
                flash_set('success', 'تم تحديث بيانات العميل.');
            } else {
                $code = crm_customer_generate_code($pdo);
                $st = $pdo->prepare(
                    'INSERT INTO crm_customer (code, name_ar, phone, email, tax_number, address_ar, sales_rep_id, is_active) VALUES (?,?,?,?,?,?,?,1)'
                );
                $st->execute([
                    $code,
                    $name,
                    $phone !== '' ? $phone : null,
                    $email !== '' ? $email : null,
                    $tax !== '' ? $tax : null,
                    $addr !== '' ? $addr : null,
                    null,
                ]);
                $newId = (int) $pdo->lastInsertId();
                crm_customer_save_sales_reps($pdo, $newId, $repIdsRaw);
                flash_set('success', 'تم إضافة العميل.');
            }
        } elseif ($act === 'toggle') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id < 1) {
                throw new RuntimeException('معرّف غير صالح.');
            }
            $st = $pdo->prepare('UPDATE crm_customer SET is_active = 1 - is_active WHERE id = ?');
            $st->execute([$id]);
            flash_set('success', 'تم تحديث حالة العميل.');
        } elseif ($act === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id < 1) {
                throw new RuntimeException('معرّف غير صالح.');
            }
            $chk = crm_customer_delete_check($pdo, $id);
            if (!$chk['can_delete']) {
                throw new RuntimeException($chk['message']);
            }
            $st = $pdo->prepare('DELETE FROM crm_customer WHERE id = ?');
            $st->execute([$id]);
            flash_set('success', 'تم حذف العميل من النظام.');
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

if ($action === 'add' || $action === 'edit') {
    $row = [
        'id' => 0,
        'code' => '',
        'name_ar' => '',
        'phone' => '',
        'email' => '',
        'tax_number' => '',
        'address_ar' => '',
        'sales_rep_id' => '',
        'is_active' => 1,
    ];
    if ($action === 'edit') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id < 1) {
            flash_set('error', 'عميل غير موجود.');
            redirect($listUrl);
        }
        $st = $pdo->prepare('SELECT * FROM crm_customer WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $dbRow = $st->fetch();
        if (!$dbRow) {
            flash_set('error', 'عميل غير موجود.');
            redirect($listUrl);
        }
        $row = array_merge($row, $dbRow);
    }
    $selectedRepIds = (int) ($row['id'] ?? 0) > 0
        ? crm_customer_sales_rep_ids_for_customer($pdo, (int) $row['id'])
        : [];

    $formTitle = $action === 'add' ? 'إضافة عميل' : 'تعديل عميل';
    ?>
    <?php sales_ora12_enqueue_assets(); ?>

    <div class="dashboard-ora sales-ora12-screen customers-ora-screen customers-ora-form-page">
        <?php sales_ora12_render_title_bar($formTitle); ?>
        <?php sales_ora12_workspace_open(); ?>
        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> sales-ora-flash"><?= esc($flash['message']) ?></div>
        <?php endif; ?>

        <div class="sales-ora-toolbar toolbar">
            <a class="btn btn-secondary btn-sm" href="<?= esc($listUrl) ?>">↩ رجوع للقائمة</a>
        </div>

        <div class="sales-ora-panel card">
        <form method="post" action="<?= esc($listUrl) ?>" class="form-grid">
            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="_action" value="save">
            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">

            <div class="form-row">
                <label class="field">
                    <span class="field-label">رمز العميل</span>
                    <input class="input" value="<?= esc((string) $row['code']) ?>"
                           placeholder="يُولَّد تلقائياً عند الحفظ"
                           readonly tabindex="-1" aria-readonly="true">
                </label>
                <label class="field">
                    <span class="field-label">اسم العميل *</span>
                    <input class="input" name="name_ar" required value="<?= esc((string) $row['name_ar']) ?>">
                </label>
            </div>
            <div class="form-row">
                <label class="field">
                    <span class="field-label">الهاتف</span>
                    <input class="input" name="phone" value="<?= esc((string) ($row['phone'] ?? '')) ?>">
                </label>
                <label class="field">
                    <span class="field-label">البريد</span>
                    <input class="input" name="email" type="email" value="<?= esc((string) ($row['email'] ?? '')) ?>">
                </label>
            </div>
            <div class="form-row">
                <label class="field">
                    <span class="field-label">الرقم الضريبي</span>
                    <input class="input" name="tax_number" value="<?= esc((string) ($row['tax_number'] ?? '')) ?>">
                </label>
            </div>
            <fieldset class="field customers-reps-field">
                <legend class="field-label">مندوبو المبيعات</legend>
                <?php if (!$salesReps): ?>
                    <p class="muted">لا يوجد مندوبون نشطون — أضف مندوباً من شاشة المندوبين أولاً.</p>
                <?php else: ?>
                    <div class="customers-reps-checkboxes">
                        <?php foreach ($salesReps as $rep): ?>
                            <label class="customers-rep-check">
                                <input type="checkbox" name="sales_rep_ids[]" value="<?= (int) $rep['id'] ?>"
                                    <?= in_array((int) $rep['id'], $selectedRepIds, true) ? ' checked' : '' ?>>
                                <?= esc((string) $rep['name_ar']) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </fieldset>
            <label class="field">
                <span class="field-label">العنوان</span>
                <textarea class="input" name="address_ar"><?= esc((string) ($row['address_ar'] ?? '')) ?></textarea>
            </label>
            <div>
                <button class="btn btn-primary" type="submit">حفظ</button>
            </div>
        </form>
        </div>
        <?php sales_ora12_workspace_close(); ?>
    </div>
    <?php
    return;
}

$search = trim((string) ($_GET['q'] ?? ''));

$repNamesSub = '(SELECT GROUP_CONCAT(r2.name_ar ORDER BY csr2.sort_order, r2.name_ar SEPARATOR \'، \')
                FROM crm_customer_sales_rep csr2
                INNER JOIN crm_sales_rep r2 ON r2.id = csr2.sales_rep_id
                WHERE csr2.customer_id = c.id)';

$sql = "SELECT c.id, c.code, c.name_ar, c.phone, c.email, c.tax_number, c.is_active, c.created_at,
               COALESCE({$repNamesSub}, r.name_ar) AS sales_rep_name
        FROM crm_customer c
        LEFT JOIN crm_sales_rep r ON r.id = c.sales_rep_id";
$params = [];
if ($search !== '') {
    $sql .= ' WHERE (c.name_ar LIKE ? OR c.code LIKE ? OR c.phone LIKE ? OR c.email LIKE ?'
        . ' OR c.tax_number LIKE ? OR IFNULL(r.name_ar, \'\') LIKE ?'
        . ' OR IFNULL(' . $repNamesSub . ', \'\') LIKE ?'
        . ' OR EXISTS (
              SELECT 1 FROM crm_customer_sales_rep csr3
              INNER JOIN crm_sales_rep r3 ON r3.id = csr3.sales_rep_id
              WHERE csr3.customer_id = c.id AND r3.name_ar LIKE ?
           ))';
    $like = '%' . $search . '%';
    $params = array_fill(0, 8, $like);
}
require_once app_path('includes/list_pagination.php');

$countSql = 'SELECT COUNT(*) FROM crm_customer c LEFT JOIN crm_sales_rep r ON r.id = c.sales_rep_id';
if ($search !== '') {
    $countSql .= ' WHERE (c.name_ar LIKE ? OR c.code LIKE ? OR c.phone LIKE ? OR c.email LIKE ?'
        . ' OR c.tax_number LIKE ? OR IFNULL(r.name_ar, \'\') LIKE ?'
        . ' OR IFNULL(' . $repNamesSub . ', \'\') LIKE ?'
        . ' OR EXISTS (
              SELECT 1 FROM crm_customer_sales_rep csr3
              INNER JOIN crm_sales_rep r3 ON r3.id = csr3.sales_rep_id
              WHERE csr3.customer_id = c.id AND r3.name_ar LIKE ?
           ))';
}
$stCount = $pdo->prepare($countSql);
$stCount->execute($params);
$listTotal = (int) $stCount->fetchColumn();

$pager = list_pager_with_total(list_pager_from_request($pdo), $listTotal);
$listPagerUrl = list_pager_base_url('customers', $search !== '' ? ['q' => $search] : []);

$sql .= ' ORDER BY c.id DESC' . list_pager_sql_limit($pager);

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll() ?: [];
$customerUsageCounts = crm_customer_usage_counts($pdo);
$addUrl = app_url('index.php?r=customers&action=add');
?>
<?php sales_ora12_enqueue_assets(); ?>

<div class="dashboard-ora sales-ora12-screen customers-ora-screen customers-ora-list-page">
    <?php sales_ora12_render_title_bar('العملاء'); ?>
    <?php sales_ora12_workspace_open(); ?>
    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> sales-ora-flash"><?= esc($flash['message']) ?></div>
    <?php endif; ?>

    <div class="sales-ora-toolbar toolbar">
        <a class="btn btn-primary btn-sm" href="<?= esc($addUrl) ?>">➕ إضافة عميل</a>
    </div>

    <div class="sales-ora-panel card">
        <form method="get" action="<?= esc(app_url('index.php')) ?>" class="sales-ora-search-form form-row">
            <input type="hidden" name="r" value="customers">
            <label class="field customers-ora-search-field">
                <span class="field-label">بحث عن العميل</span>
                <input class="input" type="search" name="q" value="<?= esc($search) ?>"
                       placeholder="الاسم، الرمز، الهاتف، البريد، المندوب…" autocomplete="off" spellcheck="false">
            </label>
            <div class="customers-ora-search-actions">
                <button type="submit" class="btn btn-secondary btn-sm">بحث</button>
                <?php if ($search !== ''): ?>
                    <a class="btn btn-ghost btn-sm" href="<?= esc($listUrl) ?>">مسح</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="sales-ora-panel card">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>#</th>
                <th>الرمز</th>
                <th>الاسم</th>
                <th>الهاتف</th>
                <th>البريد</th>
                <th>المندوب</th>
                <th>الحالة</th>
                <th>إجراءات</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr>
                    <td colspan="8" class="muted">
                        <?= $search !== '' ? 'لا يوجد عميل مطابق لبحثك.' : 'لا يوجد عملاء بعد.' ?>
                    </td>
                </tr>
            <?php endif; ?>
            <?php foreach ($rows as $c):
                $custId = (int) $c['id'];
                $usageCount = (int) ($customerUsageCounts[$custId] ?? 0);
                $blockedDelete = $usageCount > 0;
                $custName = (string) $c['name_ar'];
                $deleteConfirm = 'حذف العميل «' . $custName . '» نهائياً من النظام؟';
                $blockTitle = 'تعذر الحذف: مرتبط بـ ' . $usageCount . ' حركة';
                ?>
                <tr>
                    <td><?= $custId ?></td>
                    <td><code><?= esc((string) $c['code']) ?></code></td>
                    <td><?= esc((string) $c['name_ar']) ?></td>
                    <td><?= esc((string) ($c['phone'] ?? '')) ?></td>
                    <td><?= esc((string) ($c['email'] ?? '')) ?></td>
                    <td><?= esc((string) ($c['sales_rep_name'] ?? '—')) ?></td>
                    <td>
                        <?php if ((int) $c['is_active']): ?>
                            <span class="badge badge-ok">نشط</span>
                        <?php else: ?>
                            <span class="badge badge-off">موقوف</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="row-actions">
                            <a class="btn btn-secondary btn-sm" href="<?= esc(app_url('index.php?r=customers&action=edit&id=' . (int) $c['id'])) ?>">تعديل</a>
                            <form method="post" action="<?= esc($listUrl) ?>" data-confirm="تغيير حالة العميل؟">
                                <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                                <input type="hidden" name="_action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                <button class="btn btn-danger btn-sm" type="submit"><?= (int) $c['is_active'] ? 'تعطيل' : 'تفعيل' ?></button>
                            </form>
                            <?php if ($blockedDelete): ?>
                                <button class="btn btn-danger btn-sm" type="button" disabled title="<?= esc($blockTitle) ?>">حذف</button>
                            <?php else: ?>
                                <form method="post" action="<?= esc($listUrl) ?>" data-confirm="<?= esc($deleteConfirm) ?>">
                                    <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                                    <input type="hidden" name="_action" value="delete">
                                    <input type="hidden" name="id" value="<?= $custId ?>">
                                    <button class="btn btn-danger btn-sm" type="submit">حذف</button>
                                </form>
                            <?php endif; ?>
                        </div>
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
