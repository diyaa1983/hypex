<?php
declare(strict_types=1);

require_permission('groups');

$pdo = db();
$listUrl = app_url('index.php?r=groups');
$permissionsBaseUrl = app_url('index.php?r=permissions');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        flash_set('error', 'انتهت صلاحية الجلسة، أعد المحاولة.');
        redirect($listUrl);
    }

    $act = (string) ($_POST['_action'] ?? '');
    if ($act !== 'save') {
        flash_set('error', 'إجراء غير معروف.');
        redirect($listUrl);
    }

    try {
        $id = (int) ($_POST['id'] ?? 0);
        $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
        $code = (string) preg_replace('/[^A-Z0-9_]/', '', $code);
        $nameAr = trim((string) ($_POST['name_ar'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));

        if ($code === '' || strlen($code) < 2) {
            throw new RuntimeException('رمز المجموعة مطلوب (حرفان على الأقل، أحرف إنجليزية وأرقام و _).');
        }
        if ($nameAr === '') {
            throw new RuntimeException('اسم المجموعة مطلوب.');
        }

        $isNew = $id < 1;
        $existingCode = '';
        if (!$isNew) {
            $stCur = $pdo->prepare('SELECT code FROM sys_group WHERE id = ? LIMIT 1');
            $stCur->execute([$id]);
            $existingCode = (string) ($stCur->fetchColumn() ?: '');
            if ($existingCode === '') {
                throw new RuntimeException('المجموعة غير موجودة.');
            }
            if ($existingCode === 'ADMINS' && $code !== 'ADMINS') {
                throw new RuntimeException('لا يمكن تغيير رمز مجموعة مديري النظام.');
            }
        }

        $dup = $pdo->prepare('SELECT id FROM sys_group WHERE code = ? AND id <> ? LIMIT 1');
        $dup->execute([$code, $id]);
        if ($dup->fetch()) {
            throw new RuntimeException('رمز المجموعة مستخدم مسبقًا.');
        }

        if ($isNew) {
            $ins = $pdo->prepare(
                'INSERT INTO sys_group (code, name_ar, description) VALUES (?,?,?)'
            );
            $ins->execute([
                $code,
                $nameAr,
                $description !== '' ? $description : null,
            ]);
            $id = (int) $pdo->lastInsertId();
            flash_set('success', 'تمت إضافة المجموعة. يمكنك الآن ضبط صلاحيات الشاشات والتقارير.');
        } else {
            $upd = $pdo->prepare(
                'UPDATE sys_group SET code = ?, name_ar = ?, description = ? WHERE id = ?'
            );
            $upd->execute([
                $code,
                $nameAr,
                $description !== '' ? $description : null,
                $id,
            ]);
            flash_set('success', 'تم حفظ بيانات المجموعة.');
        }

        redirect($listUrl . '&id=' . $id);
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
        $redirectId = (int) ($_POST['id'] ?? 0);
        redirect($listUrl . ($redirectId > 0 ? '&id=' . $redirectId : '&id=new'));
    } catch (Throwable $e) {
        flash_set('error', 'تعذر حفظ المجموعة.');
        redirect($listUrl);
    }
}

$isNew = false;
$editId = 0;
if (isset($_GET['id'])) {
    if ((string) $_GET['id'] === 'new') {
        $isNew = true;
        $editId = 0;
    } else {
        $editId = (int) $_GET['id'];
        $isNew = false;
    }
} else {
    $firstId = $pdo->query('SELECT id FROM sys_group ORDER BY id ASC LIMIT 1')->fetchColumn();
    if ($firstId !== false) {
        $editId = (int) $firstId;
    } else {
        $isNew = true;
    }
}

$row = [
    'id' => 0,
    'code' => '',
    'name_ar' => '',
    'description' => '',
];
$memberCount = 0;
$permCount = 0;

if (!$isNew && $editId > 0) {
    $st = $pdo->prepare('SELECT id, code, name_ar, description FROM sys_group WHERE id = ? LIMIT 1');
    $st->execute([$editId]);
    $dbRow = $st->fetch(PDO::FETCH_ASSOC);
    if (!$dbRow) {
        flash_set('error', 'المجموعة غير موجودة.');
        redirect($listUrl . '&id=new');
    }
    $row = array_merge($row, $dbRow);

    $mc = $pdo->prepare('SELECT COUNT(*) FROM sys_user_group WHERE group_id = ?');
    $mc->execute([$editId]);
    $memberCount = (int) $mc->fetchColumn();

    $pc = $pdo->prepare('SELECT COUNT(*) FROM sys_group_permission WHERE group_id = ? AND allowed = 1');
    $pc->execute([$editId]);
    $permCount = (int) $pc->fetchColumn();
}

$listRows = $pdo->query(
    'SELECT g.id, g.code, g.name_ar, g.description,
            (SELECT COUNT(*) FROM sys_user_group ug WHERE ug.group_id = g.id) AS members,
            (SELECT COUNT(*) FROM sys_group_permission gp WHERE gp.group_id = g.id AND gp.allowed = 1) AS perms
     FROM sys_group g
     ORDER BY g.id'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$flash = flash_get();
$usersUrl = app_url('index.php?r=users');
$adminCssUrl = app_url('assets/css/users-admin.css');
$groupsJsUrl = app_url('assets/js/groups-admin.js');
$formId = 'group-form';
$permsUrlForGroup = !$isNew && $editId > 0
    ? $permissionsBaseUrl . '&group_id=' . $editId
    : $permissionsBaseUrl;
$codeReadonly = !$isNew && (string) ($row['code'] ?? '') === 'ADMINS';
?>
<link rel="stylesheet" href="<?= esc($adminCssUrl) ?>">

<?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>"><?= esc($flash['message']) ?></div>
<?php endif; ?>

<div class="users-admin">
    <div class="card users-admin-list">
        <h2 class="users-admin-heading">مجموعات المستخدمين</h2>
        <p class="muted users-admin-hint">اختر مجموعة للتعديل، أو اضغط <strong>جديد</strong> في الشريط العلوي.</p>
        <div class="table-wrap">
            <table class="data-table users-admin-table">
                <thead>
                <tr>
                    <th>#</th>
                    <th>الرمز</th>
                    <th>الاسم</th>
                    <th>مستخدمون</th>
                    <th>صلاحيات</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($listRows === []): ?>
                    <tr><td colspan="5" class="muted" style="text-align:center;">لا توجد مجموعات بعد.</td></tr>
                <?php endif; ?>
                <?php foreach ($listRows as $g):
                    $gid = (int) $g['id'];
                    $selected = !$isNew && $editId === $gid;
                    ?>
                    <tr class="users-admin-row<?= $selected ? ' is-selected' : '' ?>"
                        data-href="<?= esc($listUrl . '&id=' . $gid) ?>"
                        tabindex="0"
                        role="button">
                        <td><?= $gid ?></td>
                        <td><code><?= esc((string) $g['code']) ?></code></td>
                        <td><?= esc((string) $g['name_ar']) ?></td>
                        <td><?= (int) $g['members'] ?></td>
                        <td><?= (int) $g['perms'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card users-admin-form-card">
        <h2 class="users-admin-heading"><?= $isNew ? 'مجموعة جديدة' : 'بيانات المجموعة' ?></h2>

        <?php if (!$isNew && $editId > 0): ?>
            <p class="muted users-admin-hint" style="margin-top:0;">
                مرتبطة بـ <strong><?= $memberCount ?></strong> مستخدم —
                <strong><?= $permCount ?></strong> صلاحية شاشة/تقرير.
                <a href="<?= esc($usersUrl) ?>">إدارة المستخدمين</a>
            </p>
        <?php endif; ?>

        <form id="<?= esc($formId) ?>" method="post" class="users-admin-form" data-list-url="<?= esc($listUrl) ?>">
            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="_action" value="save">
            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">

            <div class="form-row">
                <label class="field">
                    <span class="field-label">الرمز *</span>
                    <input class="input" name="code" required maxlength="40" autocomplete="off"
                           pattern="[A-Za-z0-9_]{2,40}"
                           title="أحرف إنجليزية وأرقام و _ فقط"
                           value="<?= esc((string) $row['code']) ?>"
                        <?= $codeReadonly ? ' readonly style="background:#f1f5f9;"' : '' ?>>
                </label>
                <label class="field">
                    <span class="field-label">الاسم *</span>
                    <input class="input" name="name_ar" required maxlength="120"
                           value="<?= esc((string) $row['name_ar']) ?>">
                </label>
            </div>

            <label class="field">
                <span class="field-label">الوصف</span>
                <input class="input" name="description" maxlength="255"
                       value="<?= esc((string) ($row['description'] ?? '')) ?>"
                       placeholder="مثال: موظفو المبيعات">
            </label>

            <div class="users-admin-form-actions">
                <?php if (!$isNew && $editId > 0): ?>
                    <a class="btn btn-secondary" href="<?= esc($permsUrlForGroup) ?>">صلاحيات الشاشات والتقارير</a>
                <?php else: ?>
                    <p class="muted" style="margin:0;font-size:0.88rem;">بعد الحفظ يمكنك ضبط الصلاحيات من الزر أعلاه أو من شاشة «صلاحيات الشاشات والتقارير».</p>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<script src="<?= esc($groupsJsUrl) ?>" defer></script>
