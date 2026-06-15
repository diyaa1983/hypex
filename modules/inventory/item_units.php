<?php
declare(strict_types=1);

$listUrl = app_url('index.php?r=item_units');
$pdo = db();
require_once app_path('includes/inv_item_schema.php');
inv_item_ensure_extended_schema($pdo);

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

            if ($name === '') {
                throw new RuntimeException('اسم الوحدة مطلوب.');
            }
            if ($code === '') {
                $n = (int) $pdo->query('SELECT IFNULL(MAX(id), 0) FROM inv_unit')->fetchColumn();
                $code = 'UN-' . str_pad((string) ($n + 1), 4, '0', STR_PAD_LEFT);
            }

            if ($id > 0) {
                $chk = $pdo->prepare('SELECT id FROM inv_unit WHERE code = ? AND id <> ? LIMIT 1');
                $chk->execute([$code, $id]);
            } else {
                $chk = $pdo->prepare('SELECT id FROM inv_unit WHERE code = ? LIMIT 1');
                $chk->execute([$code]);
            }
            if ($chk->fetch()) {
                throw new RuntimeException('رمز الوحدة مستخدم مسبقًا.');
            }

            if ($id > 0) {
                $st = $pdo->prepare('UPDATE inv_unit SET code=?, name_ar=? WHERE id=?');
                $st->execute([$code, $name, $id]);
                $pdo->prepare('UPDATE inv_item SET unit_name = ? WHERE unit_id = ?')->execute([$name, $id]);
                flash_set('success', 'تم تحديث الوحدة.');
            } else {
                $st = $pdo->prepare('INSERT INTO inv_unit (code, name_ar, is_active) VALUES (?,?,1)');
                $st->execute([$code, $name]);
                flash_set('success', 'تم إضافة الوحدة.');
            }
        } elseif ($act === 'toggle') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id < 1) {
                throw new RuntimeException('معرّف غير صالح.');
            }
            $st = $pdo->prepare('UPDATE inv_unit SET is_active = 1 - is_active WHERE id = ?');
            $st->execute([$id]);
            flash_set('success', 'تم تحديث حالة الوحدة.');
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
    $row = ['id' => 0, 'code' => '', 'name_ar' => '', 'is_active' => 1];
    if ($action === 'edit') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id < 1) {
            flash_set('error', 'وحدة غير موجودة.');
            redirect($listUrl);
        }
        $st = $pdo->prepare('SELECT * FROM inv_unit WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $dbRow = $st->fetch();
        if (!$dbRow) {
            flash_set('error', 'وحدة غير موجودة.');
            redirect($listUrl);
        }
        $row = array_merge($row, $dbRow);
    }

    $formTitle = $action === 'add' ? 'إضافة وحدة' : 'تعديل وحدة';
    ?>
    <div class="toolbar">
        <h2 style="margin:0;font-size:1.05rem;"><?= esc($formTitle) ?></h2>
        <a class="btn btn-secondary btn-sm" href="<?= esc($listUrl) ?>">رجوع للقائمة</a>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>"><?= esc($flash['message']) ?></div>
    <?php endif; ?>

    <div class="card">
        <form method="post" action="<?= esc($listUrl) ?>" class="form-grid" style="max-width:560px;">
            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="_action" value="save">
            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">

            <div class="form-row">
                <label class="field">
                    <span class="field-label">الرمز (فارغ = تلقائي)</span>
                    <input class="input" name="code" value="<?= esc((string) $row['code']) ?>">
                </label>
                <label class="field">
                    <span class="field-label">اسم الوحدة *</span>
                    <input class="input" name="name_ar" required value="<?= esc((string) $row['name_ar']) ?>">
                </label>
            </div>
            <div>
                <button class="btn btn-primary" type="submit">حفظ</button>
            </div>
        </form>
    </div>
    <?php
    return;
}

require_once app_path('includes/list_pagination.php');
$listTotal = (int) $pdo->query('SELECT COUNT(*) FROM inv_unit')->fetchColumn();
$pager = list_pager_with_total(list_pager_from_request($pdo), $listTotal);
$listPagerUrl = list_pager_base_url('item_units');
$stList = $pdo->prepare(
    'SELECT id, code, name_ar, is_active, created_at FROM inv_unit ORDER BY name_ar ASC, id DESC'
    . list_pager_sql_limit($pager)
);
$stList->execute();
$rows = $stList->fetchAll() ?: [];
?>
<div class="toolbar">
    <a class="btn btn-primary btn-sm" href="<?= esc(app_url('index.php?r=item_units&action=add')) ?>">+ إضافة وحدة</a>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>"><?= esc($flash['message']) ?></div>
<?php endif; ?>

<div class="card">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>#</th>
                <th>الرمز</th>
                <th>الاسم</th>
                <th>الحالة</th>
                <th>إجراءات</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="5" class="muted" style="text-align:center;">لا توجد وحدات بعد.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $unit): ?>
                <tr>
                    <td><?= (int) $unit['id'] ?></td>
                    <td><code><?= esc((string) $unit['code']) ?></code></td>
                    <td><?= esc((string) $unit['name_ar']) ?></td>
                    <td>
                        <?php if ((int) $unit['is_active']): ?>
                            <span class="badge badge-ok">نشط</span>
                        <?php else: ?>
                            <span class="badge badge-off">موقوف</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="row-actions">
                            <a class="btn btn-secondary btn-sm" href="<?= esc(app_url('index.php?r=item_units&action=edit&id=' . (int) $unit['id'])) ?>">تعديل</a>
                            <form method="post" action="<?= esc($listUrl) ?>" data-confirm="تغيير حالة الوحدة؟">
                                <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                                <input type="hidden" name="_action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int) $unit['id'] ?>">
                                <button class="btn btn-danger btn-sm" type="submit"><?= (int) $unit['is_active'] ? 'تعطيل' : 'تفعيل' ?></button>
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
