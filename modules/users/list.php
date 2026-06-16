<?php
declare(strict_types=1);

require_permission('users');

$pdo = db();
$listUrl = app_url('index.php?r=users');
$currentUserId = (int) (current_user()['id'] ?? 0);

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
        $username = trim((string) ($_POST['username'] ?? ''));
        $fullName = trim((string) ($_POST['full_name_ar'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
        $groupIdsRaw = $_POST['group_ids'] ?? [];
        if (!is_array($groupIdsRaw)) {
            $groupIdsRaw = [];
        }

        $validGroupIds = [];
        $allGroups = $pdo->query('SELECT id FROM sys_group')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $allGroupIdSet = array_map('intval', $allGroups);
        foreach ($groupIdsRaw as $gid) {
            $gid = (int) $gid;
            if ($gid > 0 && in_array($gid, $allGroupIdSet, true)) {
                $validGroupIds[$gid] = true;
            }
        }
        $validGroupIds = array_keys($validGroupIds);

        if ($username === '' || strlen($username) < 2) {
            throw new RuntimeException('اسم المستخدم مطلوب (حرفان على الأقل).');
        }
        if (preg_match('/\s/u', $username)) {
            throw new RuntimeException('اسم المستخدم لا يجب أن يحتوي على مسافات.');
        }
        if ($fullName === '') {
            throw new RuntimeException('الاسم الكامل مطلوب.');
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('البريد الإلكتروني مطلوب وصالح (يُستخدم لاستعادة كلمة المرور).');
        }
        if ($validGroupIds === []) {
            throw new RuntimeException('اختر مجموعة واحدة على الأقل للمستخدم.');
        }
        if ($id === $currentUserId && $isActive === 0) {
            throw new RuntimeException('لا يمكنك تعطيل حسابك الحالي.');
        }

        $isNew = $id < 1;
        if ($isNew) {
            if ($password === '') {
                throw new RuntimeException('كلمة المرور مطلوبة للمستخدم الجديد.');
            }
        }

        if ($password !== '' || $passwordConfirm !== '') {
            if ($password !== $passwordConfirm) {
                throw new RuntimeException('تأكيد كلمة المرور غير متطابق.');
            }
            if (strlen($password) < 6) {
                throw new RuntimeException('كلمة المرور يجب أن تكون 6 أحرف على الأقل.');
            }
        }

        $dup = $pdo->prepare('SELECT id FROM sys_user WHERE username = ? AND id <> ? LIMIT 1');
        $dup->execute([$username, $id]);
        if ($dup->fetch()) {
            throw new RuntimeException('اسم المستخدم مستخدم مسبقًا.');
        }

        $dupEmail = $pdo->prepare('SELECT id FROM sys_user WHERE LOWER(TRIM(email)) = LOWER(?) AND id <> ? LIMIT 1');
        $dupEmail->execute([$email, $id]);
        if ($dupEmail->fetch()) {
            throw new RuntimeException('البريد الإلكتروني مستخدم لحساب آخر.');
        }

        $pdo->beginTransaction();
        try {
            if ($isNew) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $ins = $pdo->prepare(
                    'INSERT INTO sys_user (username, password_hash, full_name_ar, email, is_active)
                     VALUES (?,?,?,?,?)'
                );
                $ins->execute([
                    $username,
                    $hash,
                    $fullName,
                    $email !== '' ? $email : null,
                    $isActive,
                ]);
                $id = (int) $pdo->lastInsertId();
            } else {
                $st = $pdo->prepare('SELECT id FROM sys_user WHERE id = ? LIMIT 1');
                $st->execute([$id]);
                if (!$st->fetch()) {
                    throw new RuntimeException('المستخدم غير موجود.');
                }

                $upd = $pdo->prepare(
                    'UPDATE sys_user SET username = ?, full_name_ar = ?, email = ?, is_active = ? WHERE id = ?'
                );
                $upd->execute([
                    $username,
                    $fullName,
                    $email !== '' ? $email : null,
                    $isActive,
                    $id,
                ]);

                if ($password !== '') {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $pdo->prepare('UPDATE sys_user SET password_hash = ? WHERE id = ?')->execute([$hash, $id]);
                }
            }

            $pdo->prepare('DELETE FROM sys_user_group WHERE user_id = ?')->execute([$id]);
            $insG = $pdo->prepare('INSERT INTO sys_user_group (user_id, group_id) VALUES (?, ?)');
            foreach ($validGroupIds as $gid) {
                $insG->execute([$id, $gid]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        if ($id === $currentUserId) {
            $_SESSION['permissions'] = load_user_permissions($currentUserId);
            $_SESSION['permissions_user_id'] = $currentUserId;
            $_SESSION['user']['username'] = $username;
            $_SESSION['user']['full_name_ar'] = $fullName;
            unset($_SESSION['is_system_admin']);
        }

        flash_set('success', $isNew ? 'تم إضافة المستخدم.' : 'تم حفظ بيانات المستخدم.');
        redirect($listUrl . '&id=' . $id);
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
        $redirectId = (int) ($_POST['id'] ?? 0);
        redirect($listUrl . ($redirectId > 0 ? '&id=' . $redirectId : '&id=new'));
    } catch (Throwable $e) {
        flash_set('error', 'تعذر حفظ المستخدم.');
        redirect($listUrl);
    }
}

$groups = $pdo->query('SELECT id, code, name_ar, description FROM sys_group ORDER BY name_ar, id')->fetchAll(PDO::FETCH_ASSOC) ?: [];

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
    $firstId = $pdo->query('SELECT id FROM sys_user ORDER BY id ASC LIMIT 1')->fetchColumn();
    if ($firstId !== false) {
        $editId = (int) $firstId;
    } else {
        $isNew = true;
    }
}

$row = [
    'id' => 0,
    'username' => '',
    'full_name_ar' => '',
    'email' => '',
    'is_active' => 1,
];
$memberGroupIds = [];

if (!$isNew && $editId > 0) {
    $st = $pdo->prepare('SELECT id, username, full_name_ar, email, is_active FROM sys_user WHERE id = ? LIMIT 1');
    $st->execute([$editId]);
    $dbRow = $st->fetch(PDO::FETCH_ASSOC);
    if (!$dbRow) {
        flash_set('error', 'المستخدم غير موجود.');
        redirect($listUrl . '&id=new');
    }
    $row = array_merge($row, $dbRow);

    $gSt = $pdo->prepare('SELECT group_id FROM sys_user_group WHERE user_id = ?');
    $gSt->execute([$editId]);
    $memberGroupIds = array_map('intval', $gSt->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

require_once app_path('includes/list_pagination.php');
$listTotal = (int) $pdo->query('SELECT COUNT(*) FROM sys_user')->fetchColumn();
$pager = list_pager_with_total(list_pager_from_request($pdo), $listTotal);
$listPagerUrl = list_pager_base_url('users');

$listSql = 'SELECT u.id, u.username, u.full_name_ar, u.email, u.is_active,
        GROUP_CONCAT(g.name_ar ORDER BY g.name_ar SEPARATOR "، ") AS groups_ar
        FROM sys_user u
        LEFT JOIN sys_user_group ug ON ug.user_id = u.id
        LEFT JOIN sys_group g ON g.id = ug.group_id
        GROUP BY u.id, u.username, u.full_name_ar, u.email, u.is_active
        ORDER BY u.id' . list_pager_sql_limit($pager);
$listRows = $pdo->query($listSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$flash = flash_get();
$usersJsUrl = app_url('assets/js/users-admin.js');
$usersCssUrl = app_url('assets/css/users-admin.css');
$formId = 'user-form';
?>
<link rel="stylesheet" href="<?= esc($usersCssUrl) ?>">

<?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>"><?= esc($flash['message']) ?></div>
<?php endif; ?>

<div class="users-admin">
    <div class="card users-admin-list">
        <h2 class="users-admin-heading">قائمة المستخدمين</h2>
        <p class="muted users-admin-hint">اختر مستخدمًا للتعديل، أو اضغط <strong>جديد</strong> في الشريط العلوي.</p>
        <div class="table-wrap">
            <table class="data-table users-admin-table">
                <thead>
                <tr>
                    <th>#</th>
                    <th>اسم المستخدم</th>
                    <th>الاسم</th>
                    <th>المجموعات</th>
                    <th>البريد</th>
                    <th>نشط</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($listRows === []): ?>
                    <tr><td colspan="6" class="muted" style="text-align:center;">لا يوجد مستخدمون بعد.</td></tr>
                <?php endif; ?>
                <?php foreach ($listRows as $u):
                    $uid = (int) $u['id'];
                    $selected = !$isNew && $editId === $uid;
                    ?>
                    <tr class="users-admin-row<?= $selected ? ' is-selected' : '' ?>"
                        data-user-id="<?= $uid ?>"
                        data-href="<?= esc($listUrl . '&id=' . $uid) ?>"
                        tabindex="0"
                        role="button">
                        <td><?= $uid ?></td>
                        <td><?= esc((string) $u['username']) ?></td>
                        <td><?= esc((string) $u['full_name_ar']) ?></td>
                        <td><?= esc((string) ($u['groups_ar'] ?? '—')) ?></td>
                        <td><?= esc((string) ($u['email'] ?? '—')) ?></td>
                        <td><?= (int) $u['is_active'] ? 'نعم' : 'لا' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php list_pager_render($pager, $listPagerUrl); ?>
    </div>

    <div class="card users-admin-form-card">
        <h2 class="users-admin-heading"><?= $isNew ? 'مستخدم جديد' : 'بيانات المستخدم' ?></h2>
        <form id="<?= esc($formId) ?>" method="post" class="users-admin-form" data-list-url="<?= esc($listUrl) ?>">
            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="_action" value="save">
            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">

            <div class="form-row">
                <label class="field">
                    <span class="field-label">اسم المستخدم *</span>
                    <input class="input" name="username" required maxlength="64" autocomplete="off"
                           value="<?= esc((string) $row['username']) ?>">
                </label>
                <label class="field">
                    <span class="field-label">الاسم الكامل *</span>
                    <input class="input" name="full_name_ar" required maxlength="150"
                           value="<?= esc((string) $row['full_name_ar']) ?>">
                </label>
            </div>

            <div class="form-row">
                <label class="field">
                    <span class="field-label">البريد الإلكتروني *</span>
                    <input class="input" name="email" type="email" maxlength="150" required
                           value="<?= esc((string) ($row['email'] ?? '')) ?>"
                           placeholder="user@company.com">
                    <span class="field-hint">مطلوب لاستعادة كلمة المرور من شاشة تسجيل الدخول.</span>
                </label>
                <label class="field users-admin-active-field">
                    <span class="field-label">الحالة</span>
                    <label class="users-admin-check-inline">
                        <input type="checkbox" name="is_active" value="1"
                            <?= (int) $row['is_active'] === 1 ? 'checked' : '' ?>
                            <?= (!$isNew && $editId === $currentUserId) ? ' disabled' : '' ?>>
                        <span>نشط</span>
                    </label>
                    <?php if (!$isNew && $editId === $currentUserId): ?>
                        <input type="hidden" name="is_active" value="1">
                    <?php endif; ?>
                </label>
            </div>

            <fieldset class="users-admin-fieldset">
                <legend>المجموعات (الصلاحيات)</legend>
                <p class="muted users-admin-groups-note">صلاحيات الشاشات تُحدد لكل مجموعة من شاشة «صلاحيات الشاشات والتقارير».</p>
                <?php if ($groups === []): ?>
                    <p class="muted">لا توجد مجموعات. أنشئ مجموعة أولًا من شاشة مجموعات المستخدمين.</p>
                <?php else: ?>
                    <div class="users-admin-groups">
                        <?php foreach ($groups as $g):
                            $gid = (int) $g['id'];
                            $checked = in_array($gid, $memberGroupIds, true);
                            ?>
                            <label class="users-admin-group-item">
                                <input type="checkbox" name="group_ids[]" value="<?= $gid ?>"
                                    <?= $checked ? 'checked' : '' ?>>
                                <span class="users-admin-group-name"><?= esc((string) $g['name_ar']) ?></span>
                                <?php if (!empty($g['description'])): ?>
                                    <span class="muted users-admin-group-desc"><?= esc((string) $g['description']) ?></span>
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </fieldset>

            <fieldset class="users-admin-fieldset users-admin-password-block">
                <legend><?= $isNew ? 'كلمة المرور *' : 'تغيير كلمة المرور' ?></legend>
                <?php if (!$isNew): ?>
                    <p class="muted users-admin-groups-note">اترك الحقلين فارغين إن لم ترد تغيير كلمة المرور.</p>
                <?php endif; ?>
                <div class="form-row">
                    <label class="field">
                        <span class="field-label"><?= $isNew ? 'كلمة المرور *' : 'كلمة مرور جديدة' ?></span>
                        <input class="input" name="password" type="password" autocomplete="new-password"
                               minlength="6" maxlength="72"<?= $isNew ? ' required' : '' ?>>
                    </label>
                    <label class="field">
                        <span class="field-label"><?= $isNew ? 'تأكيد كلمة المرور *' : 'تأكيد كلمة المرور' ?></span>
                        <input class="input" name="password_confirm" type="password" autocomplete="new-password"
                               minlength="6" maxlength="72"<?= $isNew ? ' required' : '' ?>>
                    </label>
                </div>
            </fieldset>
        </form>
    </div>
</div>

<script src="<?= esc($usersJsUrl) ?>" defer></script>
