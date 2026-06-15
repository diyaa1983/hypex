<?php
declare(strict_types=1);

$listUrl = app_url('index.php?r=suppliers');

require_once app_path('includes/sales_oracle12_ui.php');

$pdo = db();
require_once app_path('includes/crm_party_delete.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        flash_set('error', 'جلسة غير صالحة، أعد المحاولة.');
        redirect($listUrl);
    }

    $act = (string) ($_POST['_action'] ?? '');

    try {
        if ($act === 'save') {
            $id = (int) ($_POST['id'] ?? 0);
            $code = trim((string) ($_POST['code'] ?? ''));
            $name = trim((string) ($_POST['name_ar'] ?? ''));
            $phone = trim((string) ($_POST['phone'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $tax = trim((string) ($_POST['tax_number'] ?? ''));
            $addr = trim((string) ($_POST['address_ar'] ?? ''));

            if ($name === '') {
                throw new RuntimeException('اسم المورد مطلوب.');
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('البريد الإلكتروني غير صالح.');
            }

            if ($code === '') {
                $n = (int) $pdo->query('SELECT IFNULL(MAX(id), 0) FROM crm_supplier')->fetchColumn();
                $code = 'S-' . str_pad((string) ($n + 1), 5, '0', STR_PAD_LEFT);
            }

            if ($id > 0) {
                $chk = $pdo->prepare('SELECT id FROM crm_supplier WHERE code = ? AND id <> ? LIMIT 1');
                $chk->execute([$code, $id]);
            } else {
                $chk = $pdo->prepare('SELECT id FROM crm_supplier WHERE code = ? LIMIT 1');
                $chk->execute([$code]);
            }
            if ($chk->fetch()) {
                throw new RuntimeException('رمز المورد مستخدم مسبقًا.');
            }

            if ($id > 0) {
                $st = $pdo->prepare('UPDATE crm_supplier SET code=?, name_ar=?, phone=?, email=?, tax_number=?, address_ar=? WHERE id=?');
                $st->execute([
                    $code,
                    $name,
                    $phone !== '' ? $phone : null,
                    $email !== '' ? $email : null,
                    $tax !== '' ? $tax : null,
                    $addr !== '' ? $addr : null,
                    $id,
                ]);
                flash_set('success', 'تم تحديث بيانات المورد.');
            } else {
                $st = $pdo->prepare('INSERT INTO crm_supplier (code, name_ar, phone, email, tax_number, address_ar, is_active) VALUES (?,?,?,?,?,?,1)');
                $st->execute([
                    $code,
                    $name,
                    $phone !== '' ? $phone : null,
                    $email !== '' ? $email : null,
                    $tax !== '' ? $tax : null,
                    $addr !== '' ? $addr : null,
                ]);
                flash_set('success', 'تم إضافة المورد.');
            }
        } elseif ($act === 'toggle') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id < 1) {
                throw new RuntimeException('معرّف غير صالح.');
            }
            $st = $pdo->prepare('UPDATE crm_supplier SET is_active = 1 - is_active WHERE id = ?');
            $st->execute([$id]);
            flash_set('success', 'تم تحديث حالة المورد.');
        } elseif ($act === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id < 1) {
                throw new RuntimeException('معرّف غير صالح.');
            }
            $chk = crm_supplier_delete_check($pdo, $id);
            if (!$chk['can_delete']) {
                throw new RuntimeException($chk['message']);
            }
            $st = $pdo->prepare('DELETE FROM crm_supplier WHERE id = ?');
            $st->execute([$id]);
            flash_set('success', 'تم حذف المورد من النظام.');
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
        'is_active' => 1,
    ];
    if ($action === 'edit') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id < 1) {
            flash_set('error', 'مورد غير موجود.');
            redirect($listUrl);
        }
        $st = $pdo->prepare('SELECT * FROM crm_supplier WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $dbRow = $st->fetch();
        if (!$dbRow) {
            flash_set('error', 'مورد غير موجود.');
            redirect($listUrl);
        }
        $row = array_merge($row, $dbRow);
    }

    $formTitle = $action === 'add' ? 'إضافة مورد' : 'تعديل مورد';
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
                    <span class="field-label">الرمز (فارغ = تلقائي)</span>
                    <input class="input" name="code" value="<?= esc((string) $row['code']) ?>" placeholder="مثال: S-00001">
                </label>
                <label class="field">
                    <span class="field-label">اسم المورد *</span>
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

require_once app_path('includes/list_pagination.php');

$listTotal = (int) $pdo->query('SELECT COUNT(*) FROM crm_supplier')->fetchColumn();
$pager = list_pager_with_total(list_pager_from_request($pdo), $listTotal);
$listPagerUrl = list_pager_base_url('suppliers');

$stList = $pdo->prepare(
    'SELECT id, code, name_ar, phone, email, is_active, created_at FROM crm_supplier ORDER BY id DESC'
    . list_pager_sql_limit($pager)
);
$stList->execute();
$rows = $stList->fetchAll() ?: [];
$supplierUsageCounts = crm_supplier_usage_counts($pdo);
$addUrl = app_url('index.php?r=suppliers&action=add');
?>
<?php sales_ora12_enqueue_assets(); ?>

<div class="dashboard-ora sales-ora12-screen customers-ora-screen customers-ora-list-page">
    <?php sales_ora12_render_title_bar('الموردون'); ?>
    <?php sales_ora12_workspace_open(); ?>
    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> sales-ora-flash"><?= esc($flash['message']) ?></div>
    <?php endif; ?>

    <div class="sales-ora-toolbar toolbar">
        <a class="btn btn-primary btn-sm" href="<?= esc($addUrl) ?>">➕ إضافة مورد</a>
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
                <th>الحالة</th>
                <th>إجراءات</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="7" class="muted" style="text-align:center;">لا يوجد موردون بعد.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $c):
                $supId = (int) $c['id'];
                $usageCount = (int) ($supplierUsageCounts[$supId] ?? 0);
                $blockedDelete = $usageCount > 0;
                $supName = (string) $c['name_ar'];
                $deleteConfirm = 'حذف المورد «' . $supName . '» نهائياً من النظام؟';
                $blockTitle = 'تعذر الحذف: مرتبط بـ ' . $usageCount . ' حركة';
                ?>
                <tr>
                    <td><?= $supId ?></td>
                    <td><code><?= esc((string) $c['code']) ?></code></td>
                    <td><?= esc((string) $c['name_ar']) ?></td>
                    <td><?= esc((string) ($c['phone'] ?? '')) ?></td>
                    <td><?= esc((string) ($c['email'] ?? '')) ?></td>
                    <td>
                        <?php if ((int) $c['is_active']): ?>
                            <span class="badge badge-ok">نشط</span>
                        <?php else: ?>
                            <span class="badge badge-off">موقوف</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="row-actions">
                            <a class="btn btn-secondary btn-sm" href="<?= esc(app_url('index.php?r=suppliers&action=edit&id=' . (int) $c['id'])) ?>">تعديل</a>
                            <form method="post" action="<?= esc($listUrl) ?>" data-confirm="تغيير حالة المورد؟">
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
                                    <input type="hidden" name="id" value="<?= $supId ?>">
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
