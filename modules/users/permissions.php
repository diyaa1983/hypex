<?php
declare(strict_types=1);

require_once app_path('includes/sys_screens.php');
require_once app_path('includes/sys_action_permissions.php');
require_once app_path('includes/sql_migration.php');
$pdoPerm = db();
sql_migration_run_file_once($pdoPerm, 'database/migrations/208_mobile_rep_custody.sql');
sql_migration_run_file_once($pdoPerm, 'database/migrations/216_dashboard_widget_permissions.sql');
require_once app_path('includes/warehouse_access.php');
wh_access_ensure_schema($pdoPerm);
$syncedScreens = sys_sync_screens_from_routes($pdoPerm);
$repScreenCount = (int) $pdoPerm->query(
    "SELECT COUNT(*) FROM sys_screen WHERE code IN ('m_rep_load', 'm_rep_return', 'm_rep_stock')"
)->fetchColumn();
if ($repScreenCount < 3) {
    require_once app_path('includes/acc_coa_bootstrap.php');
    acc_coa_meta_set($pdoPerm, 'sys_sync_routes_mtime', '');
    $syncedScreens += sys_sync_screens_from_routes($pdoPerm);
}
$syncedActions = sys_sync_action_permissions($pdoPerm);
$actionCatalog = action_permissions_catalog();

require_once app_path('includes/mobile_auth.php');

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
$permDomainFilters[] = ['id' => 'warehouses', 'title' => 'صلاحيات المستودعات'];
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
$allWarehouses = $pdoPerm->query(
    'SELECT id, code, name_ar FROM inv_warehouse WHERE is_active = 1 ORDER BY name_ar'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$whGrants = wh_access_load_group($pdoPerm, $groupId);

$selectedGroupCode = '';
foreach ($groups as $g) {
    if ((int) $g['id'] === $groupId) {
        $selectedGroupCode = strtoupper(trim((string) ($g['code'] ?? '')));
        break;
    }
}
$isMobilePermissionsGroup = $selectedGroupCode === MOBILE_GROUP_CODE;

$permIsMobileCode = static function (string $code): bool {
    return str_starts_with($code, 'm_');
};

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
                $stGroupCode = $pdo->prepare('SELECT code FROM sys_group WHERE id = ? LIMIT 1');
                $stGroupCode->execute([$gid]);
                $saveGroupCode = strtoupper(trim((string) ($stGroupCode->fetchColumn() ?: '')));
                $saveMobileOnly = $saveGroupCode === MOBILE_GROUP_CODE;

                if ($saveMobileOnly) {
                    $del = $pdo->prepare(
                        'DELETE gp FROM sys_group_permission gp
                         INNER JOIN sys_screen s ON s.id = gp.screen_id
                         WHERE gp.group_id = ? AND s.code LIKE ?'
                    );
                    $del->execute([$gid, 'm_%']);
                } else {
                    $del = $pdo->prepare('DELETE FROM sys_group_permission WHERE group_id = ?');
                    $del->execute([$gid]);
                }

                $ins = $pdo->prepare('INSERT INTO sys_group_permission (group_id, screen_id, allowed) VALUES (?, ?, 1)');
                if ($saveMobileOnly) {
                    $allScreens = $pdo->query(
                        "SELECT id FROM sys_screen WHERE code LIKE 'm_%'"
                    )->fetchAll(PDO::FETCH_COLUMN);
                } else {
                    $allScreens = $pdo->query('SELECT id FROM sys_screen')->fetchAll(PDO::FETCH_COLUMN);
                }
                foreach ($allScreens as $rawSid) {
                    $sid = (int) $rawSid;
                    if ($sid > 0 && in_array($sid, $selectedIds, true)) {
                        $ins->execute([$gid, $sid]);
                    }
                }

                $whView = $_POST['wh_view'] ?? [];
                $whIssue = $_POST['wh_issue'] ?? [];
                if (!is_array($whView)) {
                    $whView = [];
                }
                if (!is_array($whIssue)) {
                    $whIssue = [];
                }
                wh_access_save_group($pdo, $gid, $whView, $whIssue);

                $pdo->commit();

                refresh_session_permissions();

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

require_once app_path('includes/nav_helpers.php');
require_once app_path('includes/sales_oracle12_ui.php');

$selectedGroupLabel = '';
foreach ($groups as $g) {
    if ((int) $g['id'] === $groupId) {
        $selectedGroupLabel = (string) $g['name_ar'] . ' (' . (string) $g['code'] . ')';
        break;
    }
}

$permCssPath = app_path('assets/css/permissions-oracle12.css');
$permCssUrl = app_url('assets/css/permissions-oracle12.css')
    . (is_file($permCssPath) ? '?v=' . (string) filemtime($permCssPath) : '');
?>
<?php sales_ora12_enqueue_assets(); ?>
<link rel="stylesheet" href="<?= esc($permCssUrl) ?>">

<div class="dashboard-ora sales-ora12-screen perm-ora12-page" data-exit-guard-root>
    <header class="dashboard-ora-screen-title no-print" role="banner">
        <h1 class="dashboard-ora-screen-title__text">صلاحيات الشاشات والتقارير</h1>
        <?php if ($selectedGroupLabel !== ''): ?>
            <span class="dashboard-ora-screen-title__meta"><?= esc($selectedGroupLabel) ?></span>
        <?php endif; ?>
        <?php nav_render_screen_close($activeRoute ?? 'permissions'); ?>
    </header>

    <div class="dashboard-ora-workspace">
    <?php if ($syncedScreens > 0 || $syncedActions > 0): ?>
        <div class="alert alert-success perm-ora12-flash no-print">
            <?php if ($syncedScreens > 0): ?>تمت إضافة <?= (int) $syncedScreens ?> شاشة/تقرير.<?php endif; ?>
            <?php if ($syncedActions > 0): ?> تمت إضافة <?= (int) $syncedActions ?> صلاحية إجراء.<?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= $messageType === 'success' ? 'success' : 'error' ?> perm-ora12-flash no-print"><?= esc($message) ?></div>
    <?php endif; ?>

    <section class="dashboard-ora-panel perm-ora12-filters no-print">
        <h2 class="dashboard-ora-panel__title">اختيار المجموعة والفلاتر</h2>
        <p class="dashboard-ora-panel__sub">عدّل الصلاحيات ثم اضغط <strong>حفظ</strong> في الشريط العلوي.</p>
        <?php if ($isMobilePermissionsGroup): ?>
        <p class="dashboard-ora-panel__sub perm-mobile-group-note">
            مجموعة <strong>هاتف (MOBILE)</strong>: شاشات التطبيق و<strong>صلاحيات المستودعات</strong>
            (حدّد أي مستودع يراه المندوب في الفواتير والعهدة — اترك المستودع الرئيسي بدون ✓ لإخفائه).
        </p>
        <?php endif; ?>
        <div class="dashboard-ora-panel__body">
            <form method="get" action="<?= esc(app_url('index.php')) ?>" class="form-row" id="permissions-group-form">
                <input type="hidden" name="r" value="permissions">
                <label class="field">
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

            <div class="form-row perm-filter-row" style="margin-top:0.55rem;">
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
            <div id="perm-global-empty" class="alert alert-error perm-global-empty no-print" hidden style="margin:0.55rem 0 0;">
                لا توجد نتائج مطابقة للفلاتر أو البحث الحالي.
            </div>
        </div>
    </section>

    <form method="post" class="perm-ora12-form" id="permissions-form"
          action="<?= esc($permPageUrl($groupId)) ?>"
          data-mobile-only-group="<?= $isMobilePermissionsGroup ? '1' : '0' ?>">
        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="group_id" value="<?= (int) $groupId ?>">

        <?php
        $shownPermCodes = [];
        $permTypeLabel = static function (string $permCode) use ($screenTypeByCode): string {
            if (str_starts_with($permCode, 'action_')) {
                return 'إجراء';
            }
            $type = (string) ($screenTypeByCode[$permCode] ?? '');
            if ($type === 'dashboard' || str_starts_with($permCode, 'dashboard_kpi_') || str_starts_with($permCode, 'dashboard_panel_')) {
                return 'مؤشر';
            }
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
        ) use (&$shownPermCodes, $isMobilePermissionsGroup, $permIsMobileCode): void {
            $printed = 0;
            foreach ($items as $it) {
                $permCode = trim((string) ($it['code'] ?? ''));
                if ($permCode === '') {
                    $permCode = sys_screen_code_for_route((string) ($it['r'] ?? ''));
                }
                if ($permCode === '' || !isset($idByCode[$permCode])) {
                    continue;
                }
                if ($isMobilePermissionsGroup && !$permIsMobileCode($permCode)) {
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

        $renderWarehousePermTable = static function () use ($allWarehouses, $whGrants): void {
            if ($allWarehouses === []) {
                return;
            }
            ?>
            <p class="perm-domain-note">
                حدّد المستودعات المسموحة. <strong>عرض</strong> = يرى الرصيد.
                <strong>صرف</strong> = يبيع ويصرف (فواتير الموبايل).
                إذا لم تُحدَّد أي مستودع يبقى الوصول لكل المستودعات.
            </p>
            <div class="table-wrap perm-table-wrap">
                <table class="data-table perm-table">
                    <thead>
                    <tr>
                        <th>المستودع</th>
                        <th style="width:5rem;text-align:center;">عرض</th>
                        <th style="width:5rem;text-align:center;">صرف</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($allWarehouses as $whRow): ?>
                        <?php
                        $whId = (int) ($whRow['id'] ?? 0);
                        if ($whId < 1) {
                            continue;
                        }
                        $whGrant = $whGrants[$whId] ?? ['view' => false, 'issue' => false];
                        ?>
                        <tr class="perm-row-entry">
                            <td>
                                <?= esc((string) ($whRow['name_ar'] ?? '')) ?>
                                <code><?= esc((string) ($whRow['code'] ?? '')) ?></code>
                            </td>
                            <td style="text-align:center;">
                                <input type="checkbox" name="wh_view[<?= $whId ?>]" value="1"
                                    <?= !empty($whGrant['view']) ? 'checked' : '' ?>>
                            </td>
                            <td style="text-align:center;">
                                <input type="checkbox" name="wh_issue[<?= $whId ?>]" value="1"
                                    <?= !empty($whGrant['issue']) ? 'checked' : '' ?>>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php
        };
        ?>

        <?php foreach ($navMenu['domains'] as $block): ?>
            <?php
            $blockDomainId = (string) ($block['id'] ?? '');
            if ($isMobilePermissionsGroup && $blockDomainId !== 'mobile') {
                continue;
            }
            ?>
            <div class="perm-domain-block" data-perm-domain-id="<?= esc($blockDomainId) ?>">
                <h3 class="perm-domain-h"><?= esc((string) $block['title']) ?></h3>
                <div class="perm-domain-body">
                <?php if ($blockDomainId === 'mobile' && $isMobilePermissionsGroup && $allWarehouses !== []): ?>
                    <details class="perm-subfold" open
                             data-perm-subgroup-id="mobile_warehouses"
                             data-perm-subgroup-title="صلاحيات المستودعات">
                        <summary class="perm-subfold-sum">صلاحيات المستودعات</summary>
                        <?php $renderWarehousePermTable(); ?>
                    </details>
                <?php endif; ?>
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
            <div class="alert alert-error perm-missing-codes no-print">
                أكواد غير مسجّلة في قاعدة البيانات (حدّث الصفحة أو راجع المزامنة):
                <code><?= esc(implode(', ', $missingPermCodes)) ?></code>
            </div>
        <?php endif; ?>

        <?php if (!$isMobilePermissionsGroup): ?>
        <?php foreach ($actionCatalog['groups'] as $actionGroup): ?>
            <div class="perm-domain-block"
                 data-perm-domain-id="actions"
                 data-perm-subgroup-id="<?= esc('action_' . md5((string) ($actionGroup['title'] ?? 'actions'))) ?>"
                 data-perm-subgroup-title="<?= esc((string) ($actionGroup['title'] ?? 'الإجراءات')) ?>">
                <h3 class="perm-domain-h">صلاحيات الإجراءات — <?= esc((string) $actionGroup['title']) ?></h3>
                <p class="perm-domain-note">
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
        <?php endif; ?>

        <?php
        $leftoverReports = [];
        $leftoverScreens = [];
        foreach ($screens as $screenRow) {
            $code = (string) ($screenRow['code'] ?? '');
            if ($code === '' || isset($shownPermCodes[$code])) {
                continue;
            }
            if ($isMobilePermissionsGroup && !$permIsMobileCode($code)) {
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
            <div class="perm-domain-block" data-perm-domain-id="extras">
                <h3 class="perm-domain-h">باقي الشاشات والتقارير (غير موجودة في القائمة)</h3>
                <p class="perm-domain-note">
                    هذا القسم يعرض كل الصلاحيات المسجلة في النظام لضمان عدم فقدان أي شاشة أو تقرير.
                </p>
                <div class="perm-domain-body">

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
            </div>
        <?php endif; ?>

        <?php if ($allWarehouses !== [] && !$isMobilePermissionsGroup): ?>
            <div class="perm-domain-block" data-perm-domain-id="warehouses">
                <h3 class="perm-domain-h">صلاحيات المستودعات</h3>
                <?php $renderWarehousePermTable(); ?>
            </div>
        <?php endif; ?>

    </form>
    </div>
</div>
<?php
$permJsPath = app_path('assets/js/permissions-admin.js');
$permJsUrl = app_url('assets/js/permissions-admin.js')
    . (is_file($permJsPath) ? '?v=' . (string) filemtime($permJsPath) : '');
?>
<script src="<?= esc($permJsUrl) ?>" defer></script>
