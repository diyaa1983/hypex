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
$permDomainFilters = [];
foreach ((array) ($navMenu['domains'] ?? []) as $domainBlock) {
    $domainId = trim((string) ($domainBlock['id'] ?? ''));
    if ($domainId === '') {
        continue;
    }
    $permDomainFilters[] = [
        'id' => $domainId,
        'title' => (string) ($domainBlock['title'] ?? $domainId),
    ];
}
$permDomainFilters[] = ['id' => 'actions', 'title' => 'صلاحيات الإجراءات'];
$permDomainFilters[] = ['id' => 'extras', 'title' => 'شاشات وتقارير إضافية'];

$idByCode = [];
$screenTypeByCode = [];
foreach ($screens as $sc) {
    $code = (string) $sc['code'];
    $idByCode[$code] = (int) $sc['id'];
    $screenTypeByCode[$code] = (string) ($sc['screen_type'] ?? 'screen');
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

    <div class="form-row no-print perm-filter-row" style="margin:0 0 1rem;">
        <label class="field">
            <span class="field-label">عرض حسب القسم</span>
            <select class="input" id="perm-domain-select">
                <option value="">كل الأقسام</option>
                <?php foreach ($permDomainFilters as $dom): ?>
                    <option value="<?= esc((string) $dom['id']) ?>"><?= esc((string) $dom['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field">
            <span class="field-label">عرض حسب القائمة</span>
            <select class="input" id="perm-subgroup-select" disabled>
                <option value="">كل القوائم</option>
            </select>
        </label>
        <label class="field">
            <span class="field-label">بحث عن شاشة / تقرير / صلاحية</span>
            <input class="input" type="search" id="perm-search-input"
                   placeholder="اكتب الاسم أو كود الصلاحية..."
                   autocomplete="off" spellcheck="false">
        </label>
    </div>
    <div id="perm-global-empty" class="alert alert-error no-print" hidden style="margin:0 0 1rem;">
        لا توجد نتائج مطابقة للفلاتر أو البحث الحالي.
    </div>

    <form method="post" class="form-grid" id="permissions-form" action="<?= esc($permPageUrl($groupId)) ?>">
        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="group_id" value="<?= (int) $groupId ?>">

        <?php
        $shownPermCodes = [];
        $permTypeLabel = static function (string $permCode) use ($screenTypeByCode): string {
            if (str_starts_with($permCode, 'action_')) {
                return 'إجراء';
            }
            $type = (string) ($screenTypeByCode[$permCode] ?? '');
            if ($type === 'report' || str_starts_with($permCode, 'report_')) {
                return 'تقرير';
            }

            return 'شاشة';
        };
        $renderPermTableRows = static function (
            array $items,
            array $allowed,
            array $idByCode,
            callable $permTypeLabel,
            bool $markShown = true
        ) use (&$shownPermCodes): void {
            $printed = 0;
            foreach ($items as $it) {
                $permCode = trim((string) ($it['code'] ?? ''));
                if ($permCode === '') {
                    $permCode = sys_screen_code_for_route((string) ($it['r'] ?? ''));
                }
                if ($permCode === '' || !isset($idByCode[$permCode])) {
                    continue;
                }
                if ($markShown && isset($shownPermCodes[$permCode])) {
                    continue;
                }
                if ($markShown) {
                    $shownPermCodes[$permCode] = true;
                }

                $sid = (int) $idByCode[$permCode];
                $label = trim((string) ($it['label'] ?? $it['name_ar'] ?? ''));
                if ($label === '') {
                    $label = $permCode;
                }
                $printed++;
                ?>
                <tr class="perm-row-entry">
                    <td style="width:4.5rem;text-align:center;">
                        <input type="checkbox" name="screens[]" value="<?= $sid ?>" <?= isset($allowed[$sid]) ? 'checked' : '' ?>>
                    </td>
                    <td><?= esc($label) ?></td>
                    <td><code><?= esc($permCode) ?></code></td>
                    <td style="width:6.5rem;"><?= esc((string) $permTypeLabel($permCode)) ?></td>
                </tr>
                <?php
            }
            if ($printed === 0): ?>
                <tr class="perm-row-empty-static">
                    <td colspan="4" class="muted" style="text-align:center;">لا توجد عناصر في هذا القسم.</td>
                </tr>
            <?php endif;
        };
        ?>

        <?php foreach ($navMenu['domains'] as $block): ?>
            <div class="perm-domain-block" data-perm-domain-id="<?= esc((string) ($block['id'] ?? '')) ?>">
                <h3 class="perm-domain-h"><?= esc((string) $block['title']) ?></h3>

                <?php foreach ($block['subgroups'] as $sg): ?>
                    <details class="perm-subfold" open
                             data-perm-subgroup-id="<?= esc((string) ($sg['id'] ?? '')) ?>"
                             data-perm-subgroup-title="<?= esc((string) ($sg['title'] ?? '')) ?>">
                        <summary class="perm-subfold-sum"><?= esc((string) $sg['title']) ?></summary>
                        <div class="table-wrap perm-table-wrap">
                            <table class="data-table perm-table">
                                <thead>
                                <tr>
                                    <th style="width:4.5rem;text-align:center;">تفعيل</th>
                                    <th>الصلاحية</th>
                                    <th>الكود</th>
                                    <th style="width:6.5rem;">النوع</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php
                                $sgItems = [];
                                foreach ((array) ($sg['items'] ?? []) as $it) {
                                    $sgItems[] = [
                                        'r' => (string) ($it['r'] ?? ''),
                                        'label' => (string) ($it['label'] ?? ''),
                                    ];
                                }
                                $renderPermTableRows($sgItems, $allowed, $idByCode, $permTypeLabel, true);
                                ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
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
            <div class="perm-domain-block" style="margin-top:1rem;"
                 data-perm-domain-id="actions"
                 data-perm-subgroup-id="<?= esc('action_' . md5((string) ($actionGroup['title'] ?? 'actions'))) ?>"
                 data-perm-subgroup-title="<?= esc((string) ($actionGroup['title'] ?? 'الإجراءات')) ?>">
                <h3 class="perm-domain-h">صلاحيات الإجراءات — <?= esc((string) $actionGroup['title']) ?></h3>
                <p class="muted" style="font-size:0.82rem;margin:0 0 0.5rem;">
                    أزرار الشريط العلوي وواجهات API المرتبطة (مستقلة عن فتح الشاشة نفسها).
                </p>
                <div class="table-wrap perm-table-wrap">
                    <table class="data-table perm-table">
                        <thead>
                        <tr>
                            <th style="width:4.5rem;text-align:center;">تفعيل</th>
                            <th>الصلاحية</th>
                            <th>الكود</th>
                            <th style="width:6.5rem;">النوع</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php
                        $actionItems = [];
                        foreach ((array) ($actionGroup['items'] ?? []) as $actionItem) {
                            $actionItems[] = [
                                'code' => (string) ($actionItem['code'] ?? ''),
                                'label' => (string) ($actionItem['name_ar'] ?? ''),
                            ];
                        }
                        $renderPermTableRows($actionItems, $allowed, $idByCode, $permTypeLabel, true);
                        ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>

        <?php
        $leftoverReports = [];
        $leftoverScreens = [];
        foreach ($screens as $screenRow) {
            $code = (string) ($screenRow['code'] ?? '');
            if ($code === '' || isset($shownPermCodes[$code])) {
                continue;
            }
            $sid = (int) ($screenRow['id'] ?? 0);
            if ($sid < 1 || !isset($idByCode[$code])) {
                continue;
            }
            if ((string) ($screenRow['screen_type'] ?? '') === 'report' || str_starts_with($code, 'report_')) {
                $leftoverReports[] = $screenRow;
            } else {
                $leftoverScreens[] = $screenRow;
            }
        }
        ?>
        <?php if ($leftoverReports !== [] || $leftoverScreens !== []): ?>
            <div class="perm-domain-block" style="margin-top:1rem;" data-perm-domain-id="extras">
                <h3 class="perm-domain-h">باقي الشاشات والتقارير (غير موجودة في القائمة)</h3>
                <p class="muted" style="font-size:0.82rem;margin:0 0 0.5rem;">
                    هذا القسم يعرض كل الصلاحيات المسجلة في النظام لضمان عدم فقدان أي شاشة أو تقرير.
                </p>

                <?php if ($leftoverReports !== []): ?>
                    <details class="perm-subfold" open data-perm-subgroup-id="extra_reports" data-perm-subgroup-title="تقارير إضافية">
                        <summary class="perm-subfold-sum">تقارير إضافية</summary>
                        <div class="table-wrap perm-table-wrap">
                            <table class="data-table perm-table">
                                <thead>
                                <tr>
                                    <th style="width:4.5rem;text-align:center;">تفعيل</th>
                                    <th>الصلاحية</th>
                                    <th>الكود</th>
                                    <th style="width:6.5rem;">النوع</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php
                                $leftoverReportItems = [];
                                foreach ($leftoverReports as $screenRow) {
                                    $leftoverReportItems[] = [
                                        'code' => (string) ($screenRow['code'] ?? ''),
                                        'label' => (string) ($screenRow['name_ar'] ?? ''),
                                    ];
                                }
                                $renderPermTableRows($leftoverReportItems, $allowed, $idByCode, $permTypeLabel, false);
                                ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                <?php endif; ?>

                <?php if ($leftoverScreens !== []): ?>
                    <details class="perm-subfold" open data-perm-subgroup-id="extra_screens" data-perm-subgroup-title="شاشات إضافية">
                        <summary class="perm-subfold-sum">شاشات إضافية</summary>
                        <div class="table-wrap perm-table-wrap">
                            <table class="data-table perm-table">
                                <thead>
                                <tr>
                                    <th style="width:4.5rem;text-align:center;">تفعيل</th>
                                    <th>الصلاحية</th>
                                    <th>الكود</th>
                                    <th style="width:6.5rem;">النوع</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php
                                $leftoverScreenItems = [];
                                foreach ($leftoverScreens as $screenRow) {
                                    $leftoverScreenItems[] = [
                                        'code' => (string) ($screenRow['code'] ?? ''),
                                        'label' => (string) ($screenRow['name_ar'] ?? ''),
                                    ];
                                }
                                $renderPermTableRows($leftoverScreenItems, $allowed, $idByCode, $permTypeLabel, false);
                                ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </form>
</div>
<?php
$permJsPath = app_path('assets/js/permissions-admin.js');
$permJsUrl = app_url('assets/js/permissions-admin.js')
    . (is_file($permJsPath) ? '?v=' . (string) filemtime($permJsPath) : '');
?>
<script src="<?= esc($permJsUrl) ?>" defer></script>
