<?php
declare(strict_types=1);

require_once app_path('includes/sys_screens.php');
require_once app_path('includes/sys_action_permissions.php');
$pdoPerm = db();
$syncedScreens = sys_sync_screens_from_routes($pdoPerm);
$syncedActions = sys_sync_action_permissions($pdoPerm);
$actionCatalog = action_permissions_catalog();

$groups = db()->query('SELECT id, code, name_ar FROM sys_group ORDER BY id')->fetchAll();
if (!$groups) {
    echo '<div class="card"><p class="muted">لا توجد مجموعات.</p></div>';
    return;
}

$permPageUrl = static function (int $gid = 0): string {
    $url = app_url('index.php?r=permissions');
    return $gid > 0 ? $url . '&group_id=' . $gid : $url;
};

$groupId = (int) ($_GET['group_id'] ?? (int) $groups[0]['id']);
$validIds = array_map(static fn ($g) => (int) $g['id'], $groups);
if (!in_array($groupId, $validIds, true)) {
    $groupId = (int) $groups[0]['id'];
}

$screens = db()->query('SELECT id, code, name_ar, screen_type FROM sys_screen ORDER BY sort_order, id')->fetchAll();

$navMenu = require app_path('config/nav_menu.php');
$idByCode = [];
foreach ($screens as $sc) {
    $idByCode[(string) $sc['code']] = (int) $sc['id'];
}

$reloadAllowed = static function (int $gid) {
    $allowed = [];
    $st = db()->prepare('SELECT screen_id FROM sys_group_permission WHERE group_id = ? AND allowed = 1');
    $st->execute([$gid]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $sid) {
        $allowed[(int) $sid] = true;
    }
    return $allowed;
};

$allowed = $reloadAllowed($groupId);

$message = '';
$messageType = '';
$flash = flash_get();
if ($flash) {
    $message = $flash['message'];
    $messageType = $flash['type'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        $message = 'انتهت صلاحية الجلسة، أعد المحاولة.';
        $messageType = 'error';
    } else {
        $gid = (int) ($_POST['group_id'] ?? 0);
        if (!in_array($gid, $validIds, true)) {
            $message = 'مجموعة غير صالحة.';
            $messageType = 'error';
        } else {
            $selected = $_POST['screens'] ?? [];
            if (!is_array($selected)) {
                $selected = [];
            }
            $selectedIds = array_values(array_unique(array_map('intval', $selected)));

            $pdo = db();
            $pdo->beginTransaction();
            try {
                $del = $pdo->prepare('DELETE FROM sys_group_permission WHERE group_id = ?');
                $del->execute([$gid]);

                $ins = $pdo->prepare('INSERT INTO sys_group_permission (group_id, screen_id, allowed) VALUES (?, ?, 1)');
                $allScreens = $pdo->query('SELECT id FROM sys_screen')->fetchAll(PDO::FETCH_COLUMN);
                foreach ($allScreens as $rawSid) {
                    $sid = (int) $rawSid;
                    if ($sid > 0 && in_array($sid, $selectedIds, true)) {
                        $ins->execute([$gid, $sid]);
                    }
                }
                $pdo->commit();

                if (isset($_SESSION['user']['id'])) {
                    $permUid = (int) $_SESSION['user']['id'];
                    $_SESSION['permissions'] = load_user_permissions($permUid);
                    $_SESSION['permissions_user_id'] = $permUid;
                }

                flash_set('success', 'تم حفظ الصلاحيات.');
                redirect($permPageUrl($gid));
            } catch (Throwable $e) {
                $pdo->rollBack();
                $message = 'تعذر الحفظ.';
                $messageType = 'error';
            }
        }
    }
}
?>
<div class="card">
    <p class="muted">فعّل مربعات الشاشات والتقارير التي تريد أن تظهر وتُستخدم لهذه المجموعة، ثم صلاحيات الإجراءات الحساسة (فك الترحيل، الحذف، الترحيل، الفوترة…).</p>
    <p class="muted" style="font-size:0.82rem;margin-top:0.35rem;">
        الشاشات: <code>config/routes.php</code> و<code>config/nav_menu.php</code> — الإجراءات: <code>config/action_permissions.php</code>.
    </p>
    <?php if ($syncedScreens > 0 || $syncedActions > 0): ?>
        <div class="alert alert-success">
            <?php if ($syncedScreens > 0): ?>تمت إضافة <?= (int) $syncedScreens ?> شاشة/تقرير.<?php endif; ?>
            <?php if ($syncedActions > 0): ?> تمت إضافة <?= (int) $syncedActions ?> صلاحية إجراء.<?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= $messageType === 'success' ? 'success' : 'error' ?>"><?= esc($message) ?></div>
    <?php endif; ?>

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="form-grid" id="permissions-group-form" style="margin-bottom:1rem;">
        <input type="hidden" name="r" value="permissions">
        <label class="field" style="max-width:360px;">
            <span class="field-label">اختر المجموعة</span>
            <select class="input" name="group_id" id="permissions-group-select">
                <?php foreach ($groups as $g): ?>
                    <option value="<?= (int) $g['id'] ?>" <?= $groupId === (int) $g['id'] ? 'selected' : '' ?>>
                        <?= esc((string) $g['name_ar']) ?> (<?= esc((string) $g['code']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    </form>

    <p class="muted users-admin-hint" style="margin:0 0 1rem;">عدّل الصلاحيات ثم اضغط <strong>حفظ</strong> في الشريط العلوي.</p>

    <form method="post" class="form-grid" id="permissions-form" action="<?= esc($permPageUrl($groupId)) ?>">
        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="group_id" value="<?= (int) $groupId ?>">

        <?php
        $shownPermCodes = [];
        $renderPermRow = static function (array $it, array $allowed, array $idByCode) use (&$shownPermCodes): void {
            $permCode = sys_screen_code_for_route((string) ($it['r'] ?? ''));
            if ($permCode === '' || isset($shownPermCodes[$permCode])) {
                return;
            }
            if (!isset($idByCode[$permCode])) {
                return;
            }
            $shownPermCodes[$permCode] = true;
            $sid = $idByCode[$permCode];
            ?>
            <label class="perm-item">
                <input type="checkbox" name="screens[]" value="<?= $sid ?>" <?= isset($allowed[$sid]) ? 'checked' : '' ?>>
                <span><?= esc((string) $it['label']) ?></span>
            </label>
            <?php
        };
        ?>

        <?php foreach ($navMenu['domains'] as $block): ?>
            <div class="perm-domain-block">
                <h3 class="perm-domain-h"><?= esc((string) $block['title']) ?></h3>

                <?php foreach ($block['subgroups'] as $sg): ?>
                    <?php if (!empty($sg['flat'])): ?>
                        <div class="perm-grid perm-grid-nested">
                            <?php foreach ($sg['items'] as $it): ?>
                                <?php $renderPermRow($it, $allowed, $idByCode); ?>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <details class="perm-subfold" open>
                            <summary class="perm-subfold-sum"><?= esc((string) $sg['title']) ?></summary>
                            <div class="perm-grid perm-grid-nested">
                                <?php foreach ($sg['items'] as $it): ?>
                                    <?php $renderPermRow($it, $allowed, $idByCode); ?>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>

        <?php
        $missingPermCodes = [];
        foreach ($actionCatalog['groups'] as $actionGroup) {
            foreach ($actionGroup['items'] as $actionItem) {
                if (!isset($idByCode[(string) $actionItem['code']])) {
                    $missingPermCodes[] = (string) $actionItem['code'];
                }
            }
        }
        foreach ($navMenu['domains'] as $block) {
            foreach ($block['subgroups'] as $sg) {
                foreach ($sg['items'] as $it) {
                    $permCode = sys_screen_code_for_route((string) ($it['r'] ?? ''));
                    if ($permCode !== '' && !isset($idByCode[$permCode])) {
                        $missingPermCodes[] = $permCode;
                    }
                }
            }
        }
        $missingPermCodes = array_values(array_unique(array_filter($missingPermCodes)));
        ?>
        <?php if ($missingPermCodes !== []): ?>
            <div class="alert alert-error">
                أكواد غير مسجّلة في قاعدة البيانات (حدّث الصفحة أو راجع المزامنة):
                <code><?= esc(implode(', ', $missingPermCodes)) ?></code>
            </div>
        <?php endif; ?>

        <?php foreach ($actionCatalog['groups'] as $actionGroup): ?>
            <div class="perm-domain-block" style="margin-top:1rem;">
                <h3 class="perm-domain-h">صلاحيات الإجراءات — <?= esc((string) $actionGroup['title']) ?></h3>
                <p class="muted" style="font-size:0.82rem;margin:0 0 0.5rem;">
                    أزرار الشريط العلوي وواجهات API المرتبطة (مستقلة عن فتح الشاشة نفسها).
                </p>
                <div class="perm-grid perm-grid-nested">
                    <?php foreach ($actionGroup['items'] as $actionItem): ?>
                        <?php
                        $renderPermRow(
                            ['r' => (string) $actionItem['code'], 'label' => (string) $actionItem['name_ar']],
                            $allowed,
                            $idByCode
                        );
                        ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

    </form>
</div>
<?php
$permJsUrl = app_url('assets/js/permissions-admin.js');
?>
<script src="<?= esc($permJsUrl) ?>" defer></script>
