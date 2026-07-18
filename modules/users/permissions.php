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

$idByCode = [];
$screenTypeByCode = [];
$nameByCode = [];
foreach ($screens as $sc) {
    $code = (string) $sc['code'];
    $idByCode[$code] = (int) $sc['id'];
    $screenTypeByCode[$code] = (string) ($sc['screen_type'] ?? 'screen');
    $nameByCode[$code] = (string) ($sc['name_ar'] ?? $code);
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

$permKindForCode = static function (string $permCode) use ($screenTypeByCode, $actionCatalog): string {
    foreach ($actionCatalog['groups'] as $actionGroup) {
        foreach ((array) ($actionGroup['items'] ?? []) as $actionItem) {
            if ((string) ($actionItem['code'] ?? '') === $permCode) {
                return 'action';
            }
        }
    }
    if (str_starts_with($permCode, 'action_') || $permCode === 'sales_send_einvoice') {
        return 'action';
    }
    $type = (string) ($screenTypeByCode[$permCode] ?? '');
    if ($type === 'dashboard' || str_starts_with($permCode, 'dashboard_kpi_') || str_starts_with($permCode, 'dashboard_panel_')) {
        return 'dashboard';
    }
    if ($type === 'report' || str_starts_with($permCode, 'report_')) {
        return 'report';
    }

    return 'screen';
};

$permTypeLabelAr = static function (string $kind): string {
    return match ($kind) {
        'action' => 'إجراء',
        'report' => 'تقرير',
        'dashboard' => 'مؤشر',
        default => 'شاشة',
    };
};

/** @var list<array{code:string,name_ar:string,inherit_from:list<string>}> $flatActions */
$flatActions = action_permissions_flat();

$actionsForScreenCodes = static function (array $screenCodes) use ($flatActions, $idByCode): array {
    $set = [];
    foreach ($screenCodes as $c) {
        $c = trim((string) $c);
        if ($c !== '') {
            $set[$c] = true;
        }
    }
    $out = [];
    foreach ($flatActions as $actionItem) {
        $code = (string) ($actionItem['code'] ?? '');
        if ($code === '' || !isset($idByCode[$code])) {
            continue;
        }
        $inherit = (array) ($actionItem['inherit_from'] ?? []);
        $primary = null;
        foreach ($inherit as $parent) {
            $parent = trim((string) $parent);
            if ($parent !== '' && isset($idByCode[$parent])) {
                $primary = $parent;
                break;
            }
        }
        if ($primary === null || !isset($set[$primary])) {
            continue;
        }
        $out[] = [
            'code' => $code,
            'label' => (string) ($actionItem['name_ar'] ?? $code),
            'kind' => 'action',
        ];
    }

    return $out;
};

/** @var list<array{id:string,domain_id:string,domain_title:string,title:string,kind:string,items:list<array{code:string,label:string,kind:string}>}> $permPanels */
$permPanels = [];
$shownPermCodes = [];

foreach ($navMenu['domains'] as $block) {
    $blockDomainId = (string) ($block['id'] ?? '');
    if ($isMobilePermissionsGroup && $blockDomainId !== 'mobile') {
        continue;
    }
    foreach ((array) ($block['subgroups'] ?? []) as $sg) {
        $sgId = (string) ($sg['id'] ?? '');
        $panelId = $blockDomainId . '__' . $sgId;
        $items = [];
        $screenCodesInPanel = [];
        foreach ((array) ($sg['items'] ?? []) as $it) {
            $permCode = trim((string) ($it['code'] ?? ''));
            if ($permCode === '') {
                $permCode = sys_screen_code_for_route((string) ($it['r'] ?? ''));
            }
            if ($permCode === '' || !isset($idByCode[$permCode]) || isset($shownPermCodes[$permCode])) {
                continue;
            }
            if ($isMobilePermissionsGroup && !$permIsMobileCode($permCode)) {
                continue;
            }
            $kind = $permKindForCode($permCode);
            $items[] = [
                'code' => $permCode,
                'label' => trim((string) ($it['label'] ?? $nameByCode[$permCode] ?? $permCode)),
                'kind' => $kind,
            ];
            $screenCodesInPanel[] = $permCode;
            $shownPermCodes[$permCode] = true;
        }
        if (!$isMobilePermissionsGroup) {
            foreach ($actionsForScreenCodes($screenCodesInPanel) as $actionRow) {
                $ac = (string) $actionRow['code'];
                if ($ac === '' || isset($shownPermCodes[$ac])) {
                    continue;
                }
                $items[] = $actionRow;
                $shownPermCodes[$ac] = true;
            }
        }
        if ($items === []) {
            continue;
        }
        $permPanels[] = [
            'id' => $panelId,
            'domain_id' => $blockDomainId,
            'domain_title' => (string) ($block['title'] ?? $blockDomainId),
            'title' => (string) ($sg['title'] ?? $sgId),
            'kind' => 'menu',
            'items' => $items,
        ];
    }
}

if (!$isMobilePermissionsGroup) {
    foreach ($actionCatalog['groups'] as $actionGroup) {
        $title = (string) ($actionGroup['title'] ?? 'الإجراءات');
        $panelId = 'actions__' . md5($title);
        $items = [];
        foreach ((array) ($actionGroup['items'] ?? []) as $actionItem) {
            $code = (string) ($actionItem['code'] ?? '');
            if ($code === '' || !isset($idByCode[$code]) || isset($shownPermCodes[$code])) {
                continue;
            }
            $items[] = [
                'code' => $code,
                'label' => (string) ($actionItem['name_ar'] ?? $code),
                'kind' => 'action',
            ];
            $shownPermCodes[$code] = true;
        }
        if ($items === []) {
            continue;
        }
        $permPanels[] = [
            'id' => $panelId,
            'domain_id' => 'actions',
            'domain_title' => 'صلاحيات الإجراءات',
            'title' => $title,
            'kind' => 'actions',
            'items' => $items,
        ];
    }
}

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
    if (!isset($idByCode[$code])) {
        continue;
    }
    if ((string) ($screenRow['screen_type'] ?? '') === 'report' || str_starts_with($code, 'report_')) {
        $leftoverReports[] = $screenRow;
    } else {
        $leftoverScreens[] = $screenRow;
    }
}

if ($leftoverReports !== []) {
    $items = [];
    foreach ($leftoverReports as $screenRow) {
        $code = (string) ($screenRow['code'] ?? '');
        $items[] = [
            'code' => $code,
            'label' => (string) ($screenRow['name_ar'] ?? $code),
            'kind' => 'report',
        ];
    }
    $permPanels[] = [
        'id' => 'extras__reports',
        'domain_id' => 'extras',
        'domain_title' => 'شاشات وتقارير إضافية',
        'title' => 'تقارير إضافية',
        'kind' => 'extras',
        'items' => $items,
    ];
}
if ($leftoverScreens !== []) {
    $items = [];
    foreach ($leftoverScreens as $screenRow) {
        $code = (string) ($screenRow['code'] ?? '');
        $items[] = [
            'code' => $code,
            'label' => (string) ($screenRow['name_ar'] ?? $code),
            'kind' => $permKindForCode($code),
        ];
    }
    $permPanels[] = [
        'id' => 'extras__screens',
        'domain_id' => 'extras',
        'domain_title' => 'شاشات وتقارير إضافية',
        'title' => 'شاشات إضافية',
        'kind' => 'extras',
        'items' => $items,
    ];
}

if ($allWarehouses !== []) {
    $permPanels[] = [
        'id' => 'warehouses__access',
        'domain_id' => 'warehouses',
        'domain_title' => 'صلاحيات المستودعات',
        'title' => 'المستودعات',
        'kind' => 'warehouses',
        'items' => [],
    ];
}

/** @var array<string, list<array{id:string,title:string,count:int}>> $treeByDomain */
$treeByDomain = [];
foreach ($permPanels as $panel) {
    $dom = (string) $panel['domain_id'];
    if (!isset($treeByDomain[$dom])) {
        $treeByDomain[$dom] = [
            'title' => (string) $panel['domain_title'],
            'nodes' => [],
        ];
    }
    $count = $panel['kind'] === 'warehouses'
        ? count($allWarehouses)
        : count($panel['items']);
    $treeByDomain[$dom]['nodes'][] = [
        'id' => (string) $panel['id'],
        'title' => (string) $panel['title'],
        'count' => $count,
    ];
}

$firstPanelId = (string) ($permPanels[0]['id'] ?? '');

$missingPermCodes = [];
foreach ($flatActions as $actionItem) {
    if (!isset($idByCode[(string) $actionItem['code']])) {
        $missingPermCodes[] = (string) $actionItem['code'];
    }
}
foreach ($navMenu['domains'] as $block) {
    foreach ((array) ($block['subgroups'] ?? []) as $sg) {
        foreach ((array) ($sg['items'] ?? []) as $it) {
            $permCode = sys_screen_code_for_route((string) ($it['r'] ?? ''));
            if ($permCode !== '' && !isset($idByCode[$permCode])) {
                $missingPermCodes[] = $permCode;
            }
        }
    }
}
$missingPermCodes = array_values(array_unique(array_filter($missingPermCodes)));

$permCssPath = app_path('assets/css/permissions-oracle12.css');
$permCssUrl = app_url('assets/css/permissions-oracle12.css')
    . (is_file($permCssPath) ? '?v=' . (string) filemtime($permCssPath) : '');
?>
<?php sales_ora12_enqueue_assets(); ?>
<link rel="stylesheet" href="<?= esc($permCssUrl) ?>">

<div class="dashboard-ora sales-ora12-screen perm-ora12-page" data-exit-guard-root>
    <header class="dashboard-ora-screen-title no-print" role="banner">
        <h1 class="dashboard-ora-screen-title__text">صلاحيات القوائم والشاشات</h1>
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

    <?php if ($missingPermCodes !== []): ?>
        <div class="alert alert-error perm-missing-codes no-print">
            أكواد غير مسجّلة في قاعدة البيانات:
            <code><?= esc(implode(', ', $missingPermCodes)) ?></code>
        </div>
    <?php endif; ?>

    <section class="dashboard-ora-panel perm-ora12-group-bar no-print">
        <div class="dashboard-ora-panel__body">
            <form method="get" action="<?= esc(app_url('index.php')) ?>" class="form-row" id="permissions-group-form">
                <input type="hidden" name="r" value="permissions">
                <label class="field">
                    <span class="field-label">المجموعة</span>
                    <select class="input" name="group_id" id="permissions-group-select">
                        <?php foreach ($groups as $g): ?>
                            <option value="<?= (int) $g['id'] ?>" <?= $groupId === (int) $g['id'] ? 'selected' : '' ?>>
                                (<?= esc((string) $g['code']) ?>) <?= esc((string) $g['name_ar']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </form>
            <?php if ($isMobilePermissionsGroup): ?>
                <p class="perm-mobile-group-note">
                    مجموعة <strong>هاتف (MOBILE)</strong>: شاشات التطبيق وصلاحيات المستودعات فقط.
                </p>
            <?php endif; ?>
        </div>
    </section>

    <form method="post" class="perm-ora12-form" id="permissions-form"
          action="<?= esc($permPageUrl($groupId)) ?>"
          data-mobile-only-group="<?= $isMobilePermissionsGroup ? '1' : '0' ?>"
          data-initial-panel="<?= esc($firstPanelId) ?>">
        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="group_id" value="<?= (int) $groupId ?>">

        <div class="perm-split">
            <aside class="perm-tree-pane dashboard-ora-panel" aria-label="القوائم">
                <h2 class="dashboard-ora-panel__title">القوائم</h2>
                <div class="dashboard-ora-panel__body perm-tree-body">
                    <div class="perm-tree-search-row">
                        <input class="input" type="search" id="perm-tree-search"
                               placeholder="بحث في القوائم..." autocomplete="off" spellcheck="false">
                        <button type="button" class="btn btn-secondary btn-sm" id="perm-tree-search-btn">بحث</button>
                    </div>
                    <nav class="perm-tree" id="perm-tree">
                        <?php foreach ($treeByDomain as $domId => $domData): ?>
                            <div class="perm-tree-domain" data-tree-domain="<?= esc((string) $domId) ?>">
                                <div class="perm-tree-domain-title"><?= esc((string) $domData['title']) ?></div>
                                <ul class="perm-tree-list">
                                    <?php foreach ($domData['nodes'] as $node): ?>
                                        <li>
                                            <button type="button"
                                                    class="perm-tree-node<?= $firstPanelId === (string) $node['id'] ? ' is-active' : '' ?>"
                                                    data-panel-id="<?= esc((string) $node['id']) ?>">
                                                <span class="perm-tree-node-label"><?= esc((string) $node['title']) ?></span>
                                                <span class="perm-tree-node-count">(<?= (int) $node['count'] ?>)</span>
                                            </button>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endforeach; ?>
                    </nav>
                </div>
            </aside>

            <section class="perm-detail-pane dashboard-ora-panel" aria-label="الشاشات والتقارير">
                <h2 class="dashboard-ora-panel__title" id="perm-detail-title">الشاشات / التقارير</h2>
                <div class="dashboard-ora-panel__body">
                    <div class="perm-detail-toolbar">
                        <input class="input" type="search" id="perm-screen-search"
                               placeholder="بحث في الشاشات..." autocomplete="off" spellcheck="false">
                        <div class="perm-type-filters" role="radiogroup" aria-label="النوع">
                            <span class="perm-type-filters-label">النوع:</span>
                            <label class="perm-type-opt"><input type="radio" name="perm_type_filter" value="all" checked> الكل</label>
                            <label class="perm-type-opt"><input type="radio" name="perm_type_filter" value="screen"> شاشة</label>
                            <label class="perm-type-opt"><input type="radio" name="perm_type_filter" value="report"> تقرير</label>
                            <label class="perm-type-opt"><input type="radio" name="perm_type_filter" value="action"> إجراء</label>
                        </div>
                        <div class="perm-bulk-actions">
                            <button type="button" class="btn btn-secondary btn-sm" id="perm-select-all">تحديد الكل</button>
                            <button type="button" class="btn btn-secondary btn-sm" id="perm-clear-all">إلغاء الكل</button>
                        </div>
                    </div>

                    <?php foreach ($permPanels as $idx => $panel): ?>
                        <?php
                        $panelId = (string) $panel['id'];
                        $isFirst = $panelId === $firstPanelId;
                        $isWh = ($panel['kind'] ?? '') === 'warehouses';
                        ?>
                        <div class="perm-panel<?= $isFirst ? ' is-active' : '' ?>"
                             data-panel-id="<?= esc($panelId) ?>"
                             data-panel-title="<?= esc((string) $panel['title']) ?>"
                             <?= $isFirst ? '' : 'hidden' ?>>
                            <?php if ($isWh): ?>
                                <p class="perm-domain-note">
                                    حدّد المستودعات المسموحة. <strong>عرض</strong> = يرى الرصيد.
                                    <strong>صرف</strong> = يبيع ويصرف. إن لم يُحدَّد أي مستودع يبقى الوصول للكل.
                                </p>
                                <div class="table-wrap perm-table-wrap">
                                    <table class="data-table perm-table perm-table--warehouses">
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
                                            <tr class="perm-row-entry" data-perm-kind="screen">
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
                            <?php else: ?>
                                <div class="table-wrap perm-table-wrap">
                                    <table class="data-table perm-table">
                                        <thead>
                                        <tr>
                                            <th style="width:5.5rem;">النوع</th>
                                            <th style="width:14rem;">الكود</th>
                                            <th>الاسم</th>
                                            <th style="width:4.5rem;text-align:center;">تفعيل</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php if ($panel['items'] === []): ?>
                                            <tr class="perm-row-empty-static">
                                                <td colspan="4" class="muted" style="text-align:center;">لا توجد عناصر في هذا القسم.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($panel['items'] as $it): ?>
                                                <?php
                                                $permCode = (string) ($it['code'] ?? '');
                                                $sid = (int) ($idByCode[$permCode] ?? 0);
                                                if ($sid < 1) {
                                                    continue;
                                                }
                                                $kind = (string) ($it['kind'] ?? 'screen');
                                                $filterKind = $kind === 'dashboard' ? 'screen' : $kind;
                                                ?>
                                                <tr class="perm-row-entry" data-perm-kind="<?= esc($filterKind) ?>">
                                                    <td><?= esc($permTypeLabelAr($kind)) ?></td>
                                                    <td><code><?= esc($permCode) ?></code></td>
                                                    <td><?= esc((string) ($it['label'] ?? $permCode)) ?></td>
                                                    <td style="text-align:center;">
                                                        <input type="checkbox" name="screens[]" value="<?= $sid ?>"
                                                            <?= isset($allowed[$sid]) ? 'checked' : '' ?>>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <div id="perm-global-empty" class="alert alert-error perm-global-empty no-print" hidden>
                        لا توجد نتائج مطابقة للفلاتر أو البحث الحالي.
                    </div>
                </div>
            </section>
        </div>
    </form>
    </div>
</div>
<?php
$permJsPath = app_path('assets/js/permissions-admin.js');
$permJsUrl = app_url('assets/js/permissions-admin.js')
    . (is_file($permJsPath) ? '?v=' . (string) filemtime($permJsPath) : '');
?>
<script src="<?= esc($permJsUrl) ?>" defer></script>
