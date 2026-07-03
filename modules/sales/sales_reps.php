<?php
declare(strict_types=1);

$listUrl = app_url('index.php?r=sales_reps');

require_once app_path('includes/nav_helpers.php');
require_once app_path('includes/sales_oracle12_ui.php');

$pdo = db();
require_once app_path('includes/crm_sales_rep_schema.php');
crm_sales_rep_ensure_mobile_custody_schema($pdo);
if (!crm_sales_rep_ensure_schema($pdo)) {
    ?>
    <div class="alert alert-error">
        تعذر إنشاء جدول مندوبي المبيعات. نفّذ من phpMyAdmin:
        <code>database/migrations/009_crm_sales_rep.sql</code>
        ثم حدّث الصفحة.
    </div>
    <?php
    return;
}

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
            $address = trim((string) ($_POST['address_ar'] ?? ''));
            $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
            $autoCreateWarehouse = isset($_POST['auto_create_warehouse']);

            if ($name === '') {
                throw new RuntimeException('اسم المندوب مطلوب.');
            }

            if ($code === '') {
                $n = (int) $pdo->query('SELECT IFNULL(MAX(id), 0) FROM crm_sales_rep')->fetchColumn();
                $code = 'REP-' . str_pad((string) ($n + 1), 4, '0', STR_PAD_LEFT);
            }

            if ($id > 0) {
                $chk = $pdo->prepare('SELECT id FROM crm_sales_rep WHERE code = ? AND id <> ? LIMIT 1');
                $chk->execute([$code, $id]);
            } else {
                $chk = $pdo->prepare('SELECT id FROM crm_sales_rep WHERE code = ? LIMIT 1');
                $chk->execute([$code]);
            }
            if ($chk->fetch()) {
                throw new RuntimeException('رمز المندوب مستخدم مسبقًا.');
            }

            if ($warehouseId > 0) {
                $whChk = $pdo->prepare('SELECT id FROM inv_warehouse WHERE id = ? AND is_active = 1 LIMIT 1');
                $whChk->execute([$warehouseId]);
                if (!$whChk->fetch()) {
                    throw new RuntimeException('مستودع العهدة المحدد غير موجود أو غير نشط.');
                }
            } else {
                $warehouseId = null;
            }

            $hasWhCol = crm_sales_rep_has_warehouse_link($pdo);

            if ($id > 0) {
                if ($hasWhCol) {
                    $st = $pdo->prepare(
                        'UPDATE crm_sales_rep SET code=?, name_ar=?, phone=?, address_ar=?, warehouse_id=? WHERE id=?'
                    );
                    $st->execute([
                        $code,
                        $name,
                        $phone !== '' ? $phone : null,
                        $address !== '' ? $address : null,
                        $warehouseId,
                        $id,
                    ]);
                } else {
                    $st = $pdo->prepare(
                        'UPDATE crm_sales_rep SET code=?, name_ar=?, phone=?, address_ar=? WHERE id=?'
                    );
                    $st->execute([
                        $code,
                        $name,
                        $phone !== '' ? $phone : null,
                        $address !== '' ? $address : null,
                        $id,
                    ]);
                }
                if ($autoCreateWarehouse && $warehouseId === null) {
                    crm_sales_rep_ensure_custody_warehouse($pdo, $id);
                }
                flash_set('success', 'تم تحديث بيانات المندوب.');
            } else {
                if ($hasWhCol) {
                    $st = $pdo->prepare(
                        'INSERT INTO crm_sales_rep (code, name_ar, phone, address_ar, warehouse_id, is_active) VALUES (?,?,?,?,?,1)'
                    );
                    $st->execute([
                        $code,
                        $name,
                        $phone !== '' ? $phone : null,
                        $address !== '' ? $address : null,
                        $warehouseId,
                    ]);
                } else {
                    $st = $pdo->prepare(
                        'INSERT INTO crm_sales_rep (code, name_ar, phone, address_ar, is_active) VALUES (?,?,?,?,1)'
                    );
                    $st->execute([
                        $code,
                        $name,
                        $phone !== '' ? $phone : null,
                        $address !== '' ? $address : null,
                    ]);
                }
                $newId = (int) $pdo->lastInsertId();
                if ($autoCreateWarehouse && $warehouseId === null && $newId > 0) {
                    crm_sales_rep_ensure_custody_warehouse($pdo, $newId);
                }
                flash_set('success', 'تم إضافة المندوب.');
            }
        } elseif ($act === 'toggle') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id < 1) {
                throw new RuntimeException('معرّف غير صالح.');
            }
            $st = $pdo->prepare('UPDATE crm_sales_rep SET is_active = 1 - is_active WHERE id = ?');
            $st->execute([$id]);
            flash_set('success', 'تم تحديث حالة المندوب.');
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
        'address_ar' => '',
        'warehouse_id' => null,
        'is_active' => 1,
    ];
    if ($action === 'edit') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id < 1) {
            flash_set('error', 'مندوب غير موجود.');
            redirect($listUrl);
        }
        $st = $pdo->prepare('SELECT * FROM crm_sales_rep WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $dbRow = $st->fetch();
        if (!$dbRow) {
            flash_set('error', 'مندوب غير موجود.');
            redirect($listUrl);
        }
        $row = array_merge($row, $dbRow);
    }

    $warehouses = $pdo->query(
        'SELECT id, code, name_ar FROM inv_warehouse WHERE is_active = 1 ORDER BY name_ar'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $formTitle = $action === 'add' ? 'إضافة مندوب' : 'تعديل مندوب';
    ?>
    <?php sales_ora12_enqueue_assets(); ?>

    <div class="dashboard-ora sales-ora12-screen sales-reps-ora-screen sales-reps-ora-form-page">
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

            <label class="field">
                <span class="field-label">اسم المندوب *</span>
                <input class="input" name="name_ar" required value="<?= esc((string) $row['name_ar']) ?>">
            </label>

            <label class="field">
                <span class="field-label">رقم التلفون</span>
                <input class="input" name="phone" type="tel" value="<?= esc((string) ($row['phone'] ?? '')) ?>" autocomplete="tel">
            </label>

            <label class="field">
                <span class="field-label">العنوان</span>
                <textarea class="input" name="address_ar" rows="3"><?= esc((string) ($row['address_ar'] ?? '')) ?></textarea>
            </label>

            <?php if (crm_sales_rep_has_warehouse_link($pdo)): ?>
            <label class="field">
                <span class="field-label">مستودع العهدة</span>
                <select class="input" name="warehouse_id">
                    <option value="">— اختيار لاحقًا / تلقائي —</option>
                    <?php
                    $currentWhId = (int) ($row['warehouse_id'] ?? 0);
                    foreach ($warehouses as $wh):
                        $wid = (int) $wh['id'];
                        ?>
                        <option value="<?= $wid ?>"<?= $currentWhId === $wid ? ' selected' : '' ?>>
                            <?= esc((string) $wh['name_ar']) ?> (<?= esc((string) $wh['code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="field-hint muted">مستودع يحمل فيه المندوب المواد من المستودع الرئيسي.</span>
            </label>
            <label class="field users-admin-check-inline" style="display:flex;align-items:center;gap:.5rem;">
                <input type="checkbox" name="auto_create_warehouse" value="1"
                    <?= $currentWhId < 1 ? 'checked' : '' ?>>
                <span>إنشاء مستودع عهدة تلقائيًا (VAN-رمز المندوب) إن لم يُحدَّد مستودع</span>
            </label>
            <?php endif; ?>

            <input type="hidden" name="code" value="<?= esc((string) $row['code']) ?>">

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

$sql = 'SELECT r.id, r.code, r.name_ar, r.phone, r.address_ar, r.is_active, r.created_at,
        w.name_ar AS warehouse_name_ar, w.code AS warehouse_code
        FROM crm_sales_rep r
        LEFT JOIN inv_warehouse w ON w.id = r.warehouse_id';
$params = [];
if ($search !== '') {
    $sql .= ' WHERE (r.name_ar LIKE ? OR r.code LIKE ? OR r.phone LIKE ? OR r.address_ar LIKE ?)';
    $like = '%' . $search . '%';
    $params = array_fill(0, 4, $like);
}
require_once app_path('includes/list_pagination.php');

$countSql = 'SELECT COUNT(*) FROM crm_sales_rep';
if ($search !== '') {
    $countSql .= ' WHERE (name_ar LIKE ? OR code LIKE ? OR phone LIKE ? OR address_ar LIKE ?)';
}
$stCount = $pdo->prepare($countSql);
$stCount->execute($params);
$listTotal = (int) $stCount->fetchColumn();
$pager = list_pager_with_total(list_pager_from_request($pdo), $listTotal);
$listPagerUrl = list_pager_base_url('sales_reps', $search !== '' ? ['q' => $search] : []);

$sql .= ' ORDER BY r.name_ar ASC, r.id DESC' . list_pager_sql_limit($pager);

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll() ?: [];
$addUrl = app_url('index.php?r=sales_reps&action=add');
?>
<?php sales_ora12_enqueue_assets(); ?>

<div class="dashboard-ora sales-ora12-screen sales-reps-ora-screen sales-reps-ora-list-page">
    <?php sales_ora12_render_title_bar('المندوبين'); ?>
    <?php sales_ora12_workspace_open(); ?>
    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> sales-ora-flash"><?= esc($flash['message']) ?></div>
    <?php endif; ?>

    <div class="sales-ora-toolbar toolbar">
        <a class="btn btn-primary btn-sm" href="<?= esc($addUrl) ?>">➕ إضافة مندوب</a>
    </div>

    <div class="sales-ora-panel card">
        <form method="get" action="<?= esc(app_url('index.php')) ?>" class="sales-ora-search-form form-row">
            <input type="hidden" name="r" value="sales_reps">
            <label class="field sales-reps-ora-search-field">
                <span class="field-label">بحث عن المندوب</span>
                <input class="input" type="search" name="q" value="<?= esc($search) ?>"
                       placeholder="الاسم، الرمز، التلفون، العنوان…" autocomplete="off" spellcheck="false">
            </label>
            <div class="sales-reps-ora-search-actions">
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
                <th>اسم المندوب</th>
                <th>التلفون</th>
                <th>مستودع العهدة</th>
                <th>العنوان</th>
                <th>الحالة</th>
                <th>إجراءات</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr>
                    <td colspan="8" class="muted">
                        <?= $search !== '' ? 'لا يوجد مندوب مطابق لبحثك.' : 'لا يوجد مندوبون بعد.' ?>
                    </td>
                </tr>
            <?php endif; ?>
            <?php foreach ($rows as $rep): ?>
                <tr>
                    <td><?= (int) $rep['id'] ?></td>
                    <td><code><?= esc((string) $rep['code']) ?></code></td>
                    <td><?= esc((string) $rep['name_ar']) ?></td>
                    <td><?= esc((string) ($rep['phone'] ?? '—')) ?></td>
                    <td>
                        <?php if (!empty($rep['warehouse_name_ar'])): ?>
                            <?= esc((string) $rep['warehouse_name_ar']) ?>
                            <span class="muted">(<?= esc((string) ($rep['warehouse_code'] ?? '')) ?>)</span>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="muted sales-reps-address-cell"><?= esc((string) ($rep['address_ar'] ?? '—')) ?></td>
                    <td>
                        <?php if ((int) $rep['is_active']): ?>
                            <span class="badge badge-ok">نشط</span>
                        <?php else: ?>
                            <span class="badge badge-off">موقوف</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="row-actions">
                            <a class="btn btn-secondary btn-sm" href="<?= esc(app_url('index.php?r=sales_reps&action=edit&id=' . (int) $rep['id'])) ?>">تعديل</a>
                            <form method="post" action="<?= esc($listUrl) ?>" data-confirm="تغيير حالة المندوب؟">
                                <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                                <input type="hidden" name="_action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int) $rep['id'] ?>">
                                <button class="btn btn-danger btn-sm" type="submit"><?= (int) $rep['is_active'] ? 'تعطيل' : 'تفعيل' ?></button>
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
    <?php sales_ora12_workspace_close(); ?>
</div>
