<?php
declare(strict_types=1);

$listUrl = app_url('index.php?r=item_categories');
$pdo = db();
require_once app_path('includes/inv_item_schema.php');
require_once app_path('includes/oracle_sync_service.php');
inv_item_ensure_extended_schema($pdo);
oracle_item_schema_ensure($pdo);

/** رقم تسلسلي تالي لرمز الفئة (أرقام فقط). */
function inv_category_next_code(PDO $pdo): string
{
    $max = 0;
    foreach ($pdo->query('SELECT code FROM inv_item_category')->fetchAll(PDO::FETCH_COLUMN) as $raw) {
        $c = trim((string) $raw);
        if ($c !== '' && ctype_digit($c)) {
            $max = max($max, (int) $c);
        }
    }
    $maxId = (int) $pdo->query('SELECT IFNULL(MAX(id), 0) FROM inv_item_category')->fetchColumn();

    return (string) (max($max, $maxId) + 1);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        flash_set('error', 'جلسة غير صالحة، أعد المحاولة.');
        redirect($listUrl);
    }

    $act = (string) ($_POST['_action'] ?? '');

    try {
        if ($act === 'oracle_sync') {
            $syncResult = oracle_run_continuous_sync($pdo, ['item_groups']);
            $g = is_array($syncResult['item_groups'] ?? null) ? $syncResult['item_groups'] : [];
            $sum = 'مزامنة مجموعات Oracle: +' . (int) ($g['inserted'] ?? 0)
                . ' محدّث ' . (int) ($g['updated'] ?? 0)
                . ' | ' . (int) ($syncResult['elapsed_ms'] ?? 0) . 'ms';
            if (!empty($syncResult['errors'])) {
                flash_set('error', $sum . ' — ' . implode(' | ', array_slice($syncResult['errors'], 0, 5)));
            } else {
                flash_set('success', $sum);
            }
            redirect($listUrl);
        }
        if ($act === 'save') {
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim((string) ($_POST['name_ar'] ?? ''));

            if ($name === '') {
                throw new RuntimeException('اسم الفئة مطلوب.');
            }

            if ($id > 0) {
                $st = $pdo->prepare('UPDATE inv_item_category SET name_ar=? WHERE id=?');
                $st->execute([$name, $id]);
                flash_set('success', 'تم تحديث الفئة.');
            } else {
                $code = inv_category_next_code($pdo);
                $chk = $pdo->prepare('SELECT id FROM inv_item_category WHERE code = ? LIMIT 1');
                $chk->execute([$code]);
                if ($chk->fetch()) {
                    $code = inv_category_next_code($pdo);
                }
                $st = $pdo->prepare('INSERT INTO inv_item_category (code, name_ar, is_active) VALUES (?,?,1)');
                $st->execute([$code, $name]);
                flash_set('success', 'تم إضافة الفئة. الرمز: ' . $code);
            }
        } elseif ($act === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id < 1) {
                throw new RuntimeException('معرّف غير صالح.');
            }
            $chk = inv_category_delete_check($pdo, $id);
            if (!$chk['can_delete']) {
                throw new RuntimeException($chk['message']);
            }
            $st = $pdo->prepare('DELETE FROM inv_item_category WHERE id = ?');
            $st->execute([$id]);
            flash_set('success', 'تم حذف الفئة من النظام.');
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
            flash_set('error', 'فئة غير موجودة.');
            redirect($listUrl);
        }
        $st = $pdo->prepare('SELECT * FROM inv_item_category WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $dbRow = $st->fetch();
        if (!$dbRow) {
            flash_set('error', 'فئة غير موجودة.');
            redirect($listUrl);
        }
        $row = array_merge($row, $dbRow);
    }

    $formTitle = $action === 'add' ? 'إضافة فئة' : 'تعديل فئة';
    $nextCodePreview = $action === 'add' ? inv_category_next_code($pdo) : '';
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
                    <span class="field-label">الرمز</span>
                    <?php if ($action === 'edit'): ?>
                        <input class="input" type="text" value="<?= esc((string) $row['code']) ?>" readonly tabindex="-1" style="background:#f1f5f9;cursor:not-allowed;">
                    <?php else: ?>
                        <input class="input" type="text" value="<?= esc($nextCodePreview) ?>" readonly tabindex="-1" style="background:#f1f5f9;cursor:not-allowed;" title="يُولَّد تلقائيًا عند الحفظ">
                        <span class="muted" style="font-size:0.8rem;">رقم تسلسلي — يُنشأ تلقائيًا ولا يُعدَّل لاحقًا</span>
                    <?php endif; ?>
                </label>
                <label class="field">
                    <span class="field-label">اسم الفئة *</span>
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
$listTotal = (int) $pdo->query('SELECT COUNT(*) FROM inv_item_category')->fetchColumn();
$pager = list_pager_with_total(list_pager_from_request($pdo), $listTotal);
$listPagerUrl = list_pager_base_url('item_categories');
$stList = $pdo->prepare(
    'SELECT c.id, c.code, c.name_ar,
        (SELECT COUNT(*) FROM inv_stock_move m INNER JOIN inv_item i ON i.id = m.item_id WHERE i.category_id = c.id) AS move_count
     FROM inv_item_category c
     ORDER BY c.name_ar ASC, c.id DESC' . list_pager_sql_limit($pager)
);
$stList->execute();
$rows = $stList->fetchAll() ?: [];
?>
<style>
.item-cat-list .data-table th,
.item-cat-list .data-table td { font-weight: 700; }
.item-cat-list .data-table code { font-weight: 700; }
.item-cat-list .toolbar h2 { font-weight: 700; }
</style>
<div class="toolbar">
    <a class="btn btn-primary btn-sm" href="<?= esc(app_url('index.php?r=item_categories&action=add')) ?>">+ إضافة فئة</a>
    <?php if (oracle_is_enabled()): ?>
        <form method="post" style="display:inline;" data-confirm="تحديث مجموعات المواد من Oracle الآن؟">
            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="_action" value="oracle_sync">
            <button type="submit" class="btn btn-secondary btn-sm">🔄 تحديث من Oracle</button>
        </form>
    <?php endif; ?>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>"><?= esc($flash['message']) ?></div>
<?php endif; ?>

<div class="card item-cat-list">
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
                <tr><td colspan="4" class="muted" style="text-align:center;">لا توجد فئات بعد.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $cat):
                $moveCount = (int) ($cat['move_count'] ?? 0);
                $catName = (string) $cat['name_ar'];
                $catCode = (string) $cat['code'];
                $deleteConfirm = 'حذف الفئة «' . $catName . '» (الرمز ' . $catCode . ') نهائياً من النظام؟';
                ?>
                <tr>
                    <td><?= (int) $cat['id'] ?></td>
                    <td><code><?= esc($catCode) ?></code></td>
                    <td><?= esc($catName) ?></td>
                    <td>
                        <div class="row-actions">
                            <a class="btn btn-secondary btn-sm" href="<?= esc(app_url('index.php?r=item_categories&action=edit&id=' . (int) $cat['id'])) ?>">تعديل</a>
                            <?php if ($moveCount > 0): ?>
                                <button class="btn btn-danger btn-sm" type="button" disabled
                                        title="تعذر الحذف: توجد <?= $moveCount ?> حركة مخزنية على مواد هذه الفئة">حذف</button>
                            <?php else: ?>
                                <form method="post" action="<?= esc($listUrl) ?>" data-confirm="<?= esc($deleteConfirm) ?>">
                                    <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                                    <input type="hidden" name="_action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $cat['id'] ?>">
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
