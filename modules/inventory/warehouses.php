<?php
declare(strict_types=1);

$listUrl = app_url('index.php?r=warehouses');
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
                throw new RuntimeException('اسم المستودع مطلوب.');
            }
            if ($code === '') {
                $n = (int) $pdo->query('SELECT IFNULL(MAX(id), 0) FROM inv_warehouse')->fetchColumn();
                $code = 'WH-' . str_pad((string) ($n + 1), 4, '0', STR_PAD_LEFT);
            }

            if ($id > 0) {
                $chk = $pdo->prepare('SELECT id FROM inv_warehouse WHERE code = ? AND id <> ? LIMIT 1');
                $chk->execute([$code, $id]);
            } else {
                $chk = $pdo->prepare('SELECT id FROM inv_warehouse WHERE code = ? LIMIT 1');
                $chk->execute([$code]);
            }
            if ($chk->fetch()) {
                throw new RuntimeException('رمز المستودع مستخدم مسبقًا.');
            }

            if ($id > 0) {
                $st = $pdo->prepare('UPDATE inv_warehouse SET code=?, name_ar=? WHERE id=?');
                $st->execute([$code, $name, $id]);
                flash_set('success', 'تم تحديث المستودع.');
            } else {
                $st = $pdo->prepare('INSERT INTO inv_warehouse (code, name_ar, is_active) VALUES (?,?,1)');
                $st->execute([$code, $name]);
                flash_set('success', 'تم إضافة المستودع.');
            }
        } elseif ($act === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id < 1) {
                throw new RuntimeException('معرّف غير صالح.');
            }
            $chk = inv_warehouse_delete_check($pdo, $id);
            if (!$chk['can_delete']) {
                throw new RuntimeException($chk['message']);
            }
            $st = $pdo->prepare('DELETE FROM inv_warehouse WHERE id = ?');
            $st->execute([$id]);
            flash_set('success', 'تم حذف المستودع من النظام.');
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
            flash_set('error', 'مستودع غير موجود.');
            redirect($listUrl);
        }
        $st = $pdo->prepare('SELECT * FROM inv_warehouse WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $dbRow = $st->fetch();
        if (!$dbRow) {
            flash_set('error', 'مستودع غير موجود.');
            redirect($listUrl);
        }
        $row = array_merge($row, $dbRow);
    }

    $formTitle = $action === 'add' ? 'إضافة مستودع' : 'تعديل مستودع';
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
                    <span class="field-label">اسم المستودع *</span>
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

require_once app_path('includes/inv_stock.php');
inv_stock_move_ensure_table($pdo);

require_once app_path('includes/list_pagination.php');
$listTotal = (int) $pdo->query('SELECT COUNT(*) FROM inv_warehouse')->fetchColumn();
$pager = list_pager_with_total(list_pager_from_request($pdo), $listTotal);
$listPagerUrl = list_pager_base_url('warehouses');

$stList = $pdo->prepare(
    'SELECT w.id, w.code, w.name_ar,
        (SELECT COUNT(*) FROM inv_item i WHERE i.default_warehouse_id = w.id) AS item_count,
        (SELECT COUNT(*) FROM inv_stock_move m WHERE m.warehouse_id = w.id) AS move_count
     FROM inv_warehouse w
     ORDER BY w.id DESC' . list_pager_sql_limit($pager)
);
$stList->execute();
$rows = $stList->fetchAll() ?: [];
?>
<div class="toolbar">
    <a class="btn btn-primary btn-sm" href="<?= esc(app_url('index.php?r=warehouses&action=add')) ?>">+ إضافة مستودع</a>
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
                <th>إجراءات</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="4" class="muted" style="text-align:center;">لا توجد مستودعات بعد.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $w):
                $itemCount = (int) ($w['item_count'] ?? 0);
                $moveCount = (int) ($w['move_count'] ?? 0);
                $blocked = $itemCount > 0 || $moveCount > 0;
                $whName = (string) $w['name_ar'];
                $whCode = (string) $w['code'];
                $deleteConfirm = 'حذف المستودع «' . $whName . '» (الرمز ' . $whCode . ') نهائياً من النظام؟';
                $blockTitle = 'تعذر الحذف: ';
                if ($itemCount > 0) {
                    $blockTitle .= $itemCount . ' مادة مرتبطة';
                }
                if ($moveCount > 0) {
                    $blockTitle .= ($itemCount > 0 ? ' و' : '') . $moveCount . ' حركة مخزنية';
                }
                ?>
                <tr>
                    <td><?= (int) $w['id'] ?></td>
                    <td><code><?= esc($whCode) ?></code></td>
                    <td><?= esc($whName) ?></td>
                    <td>
                        <div class="row-actions">
                            <a class="btn btn-secondary btn-sm" href="<?= esc(app_url('index.php?r=warehouses&action=edit&id=' . (int) $w['id'])) ?>">تعديل</a>
                            <?php if ($blocked): ?>
                                <button class="btn btn-danger btn-sm" type="button" disabled title="<?= esc($blockTitle) ?>">حذف</button>
                            <?php else: ?>
                                <form method="post" action="<?= esc($listUrl) ?>" data-confirm="<?= esc($deleteConfirm) ?>">
                                    <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                                    <input type="hidden" name="_action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $w['id'] ?>">
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
