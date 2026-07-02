<?php
declare(strict_types=1);

require_once app_path('includes/nav_helpers.php');
require_once app_path('includes/app_window_manager.php');

$cssPath = app_path('assets/css/dashboard.css');
$cssUrl = app_url('assets/css/dashboard.css');
if (is_file($cssPath)) {
    $cssUrl .= '?v=' . (string) filemtime($cssPath);
}
echo '<link rel="stylesheet" href="' . esc($cssUrl) . '">' . "\n";

$domainId = trim((string) ($_GET['d'] ?? ''));
$subId = trim((string) ($_GET['s'] ?? ''));
$nestedSubId = trim((string) ($_GET['ss'] ?? ''));

$domain = nav_find_domain($domainId);
if (!$domain) {
    http_response_code(404);
    echo '<div class="dashboard-ora nav-hub-ora"><div class="dashboard-ora-workspace"><div class="alert alert-error">المجال غير موجود.</div></div></div>';
    return;
}

$domainTitle = (string) ($domain['title'] ?? $domainId);

if ($subId === '') {
    $folders = [];
    foreach ($domain['subgroups'] as $sg) {
        if (!nav_subgroup_visible($sg)) {
            continue;
        }
        if (nav_subgroup_is_folder_container($sg)) {
            $nested = nav_subgroup_nested_folders($sg);
            if ($nested === []) {
                continue;
            }
            $folders[] = [
                'id' => (string) ($sg['id'] ?? ''),
                'title' => (string) ($sg['title'] ?? ''),
                'count' => count($nested),
                'meta' => count($nested) . ' مجلد',
            ];
            continue;
        }
        $items = nav_subgroup_allowed_items($sg);
        if ($items === []) {
            continue;
        }
        $folders[] = [
            'id' => (string) ($sg['id'] ?? ''),
            'title' => (string) ($sg['title'] ?? ''),
            'count' => count($items),
            'meta' => count($items) . ' شاشة',
        ];
    }

    if ($folders === []) {
        http_response_code(403);
        echo '<div class="dashboard-ora nav-hub-ora"><div class="dashboard-ora-workspace"><div class="alert alert-error">ليس لديك صلاحية لأي مجموعة في هذا المجال.</div></div></div>';
        return;
    }

    ?>
    <div class="dashboard-ora nav-hub-ora">
        <header class="dashboard-ora-screen-title" role="banner">
            <h1 class="dashboard-ora-screen-title__text"><?= esc($domainTitle) ?></h1>
            <span class="dashboard-ora-screen-title__meta">اختر مجلداً</span>
            <?php nav_render_screen_close('menu_hub'); ?>
        </header>
        <div class="dashboard-ora-workspace">
            <section class="dashboard-ora-panel" aria-label="مجلدات <?= esc($domainTitle) ?>">
                <h2 class="dashboard-ora-panel__title">المجلدات</h2>
                <p class="dashboard-ora-panel__sub">اختر مجلداً لعرض الشاشات والتقارير المتاحة</p>
                <div class="dashboard-ora-panel__body nav-hub-ora-grid-wrap">
                    <div class="nav-hub-ora-grid nav-hub-ora-grid--folders" role="list">
                        <?php foreach ($folders as $folder): ?>
                            <a
                                class="nav-hub-ora-tile nav-hub-ora-tile--folder"
                                role="listitem"
                                href="<?= esc(app_mdi_hub_nav_url(nav_hub_url($domainId, $folder['id']))) ?>"
                            >
                                <span class="nav-hub-ora-tile-icon" aria-hidden="true">📁</span>
                                <span class="nav-hub-ora-tile-label"><?= esc($folder['title']) ?></span>
                                <span class="nav-hub-ora-tile-meta"><?= esc((string) ($folder['meta'] ?? '')) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        </div>
    </div>
    <?php
    return;
}

$found = nav_find_subgroup_path($domainId, $subId, $nestedSubId);
if (!$found) {
    http_response_code(404);
    echo '<div class="dashboard-ora nav-hub-ora"><div class="dashboard-ora-workspace"><div class="alert alert-error">المجلد غير موجود.</div></div></div>';
    return;
}

$subgroup = $found['subgroup'];
$nestedSubgroup = $found['nested_subgroup'] ?? null;

if ($nestedSubId === '' && nav_subgroup_is_folder_container($subgroup)) {
    $nestedFolders = [];
    foreach (nav_subgroup_nested_folders($subgroup) as $child) {
        $childItems = nav_subgroup_allowed_items($child);
        if ($childItems === []) {
            continue;
        }
        $nestedFolders[] = [
            'id' => (string) ($child['id'] ?? ''),
            'title' => (string) ($child['title'] ?? ''),
            'count' => count($childItems),
        ];
    }

    if ($nestedFolders === []) {
        http_response_code(403);
        echo '<div class="dashboard-ora nav-hub-ora"><div class="dashboard-ora-workspace"><div class="alert alert-error">ليس لديك صلاحية لأي شاشة في هذا المجلد.</div></div></div>';
        return;
    }

    $subTitle = (string) ($subgroup['title'] ?? '');
    nav_hub_track_folder_visit($domainId, $subId, $subTitle);
    $prevFolderLink = nav_hub_previous_folder_link($domainId, $subId);
    $backUrl = nav_domain_hub_url($domainId);
    $visibleFolderCount = 0;
    foreach ($domain['subgroups'] as $sg) {
        if (nav_subgroup_visible($sg)) {
            $visibleFolderCount++;
        }
    }

    ?>
    <div class="dashboard-ora nav-hub-ora">
        <header class="dashboard-ora-screen-title" role="banner">
            <h1 class="dashboard-ora-screen-title__text"><?= esc($subTitle) ?></h1>
            <span class="dashboard-ora-screen-title__meta"><?= esc($domainTitle) ?></span>
            <?php nav_render_screen_close($activeRoute ?? 'menu_hub'); ?>
        </header>
        <div class="dashboard-ora-workspace">
            <?php if ($prevFolderLink !== null || $visibleFolderCount > 1): ?>
            <nav class="nav-hub-ora-breadcrumb" aria-label="تنقل المجلدات">
                <?php if ($prevFolderLink !== null): ?>
                    <a class="dashboard-ora-btn" href="<?= esc(app_mdi_hub_nav_url($prevFolderLink['url'])) ?>"
                       title="العودة إلى المجلد السابق">← <?= esc($prevFolderLink['label']) ?></a>
                <?php endif; ?>
                <?php if ($visibleFolderCount > 1): ?>
                    <a class="dashboard-ora-btn" href="<?= esc(app_mdi_hub_nav_url($backUrl)) ?>">← <?= esc($domainTitle) ?></a>
                <?php endif; ?>
            </nav>
            <?php endif; ?>

            <section class="dashboard-ora-panel" aria-label="مجلدات <?= esc($subTitle) ?>">
                <h2 class="dashboard-ora-panel__title">المجلدات</h2>
                <p class="dashboard-ora-panel__sub">اختر مجلداً لعرض الشاشات والتقارير</p>
                <div class="dashboard-ora-panel__body nav-hub-ora-grid-wrap">
                    <div class="nav-hub-ora-grid nav-hub-ora-grid--folders" role="list">
                        <?php foreach ($nestedFolders as $folder): ?>
                            <a
                                class="nav-hub-ora-tile nav-hub-ora-tile--folder"
                                role="listitem"
                                href="<?= esc(app_mdi_hub_nav_url(nav_hub_url($domainId, $subId, $folder['id']))) ?>"
                            >
                                <span class="nav-hub-ora-tile-icon" aria-hidden="true">📁</span>
                                <span class="nav-hub-ora-tile-label"><?= esc($folder['title']) ?></span>
                                <span class="nav-hub-ora-tile-meta"><?= (int) $folder['count'] ?> شاشة</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        </div>
    </div>
    <?php
    return;
}

$leafSubgroup = is_array($nestedSubgroup) ? $nestedSubgroup : $subgroup;
$items = nav_subgroup_allowed_items($leafSubgroup);

if ($items === []) {
    if ($domainId === 'favorites') {
        require app_path('modules/menu/favorites_empty.php');
        return;
    }
    http_response_code(403);
    echo '<div class="dashboard-ora nav-hub-ora"><div class="dashboard-ora-workspace"><div class="alert alert-error">ليس لديك صلاحية لأي شاشة في هذا المجلد.</div></div></div>';
    return;
}

$subTitle = (string) ($leafSubgroup['title'] ?? '');
if ($domainId !== 'favorites') {
    nav_hub_track_folder_visit($domainId, $subId, $subTitle, $nestedSubId);
}
$prevFolderLink = $domainId === 'favorites' ? null : nav_hub_previous_folder_link($domainId, $subId, $nestedSubId);
$backUrl = $nestedSubId !== ''
    ? nav_hub_url($domainId, $subId)
    : nav_domain_hub_url($domainId);
$visibleFolderCount = 0;
foreach ($domain['subgroups'] as $sg) {
    if (nav_subgroup_visible($sg)) {
        $visibleFolderCount++;
    }
}

$hubPageTitle = $domainId === 'favorites' ? $domainTitle : $subTitle;
$hubPageSub = $domainId === 'favorites'
    ? 'الشاشات والتقارير التي أضفتها بالنجمة ★'
    : ($nestedSubId !== '' ? (string) ($subgroup['title'] ?? $domainTitle) : $domainTitle);

?>
<div class="dashboard-ora nav-hub-ora">
    <header class="dashboard-ora-screen-title" role="banner">
        <h1 class="dashboard-ora-screen-title__text"><?= esc($hubPageTitle) ?></h1>
        <span class="dashboard-ora-screen-title__meta"><?= esc($hubPageSub) ?></span>
        <?php nav_render_screen_close($activeRoute ?? 'menu_hub'); ?>
    </header>
    <div class="dashboard-ora-workspace">
        <?php if ($prevFolderLink !== null || ($domainId !== 'favorites' && ($visibleFolderCount > 1 || $nestedSubId !== ''))): ?>
        <nav class="nav-hub-ora-breadcrumb" aria-label="تنقل المجلدات">
            <?php if ($prevFolderLink !== null): ?>
                <a class="dashboard-ora-btn" href="<?= esc(app_mdi_hub_nav_url($prevFolderLink['url'])) ?>"
                   title="العودة إلى المجلد السابق">← <?= esc($prevFolderLink['label']) ?></a>
            <?php endif; ?>
            <?php if ($domainId !== 'favorites' && ($visibleFolderCount > 1 || $nestedSubId !== '')): ?>
                <a class="dashboard-ora-btn" href="<?= esc(app_mdi_hub_nav_url($backUrl)) ?>">← <?= esc($nestedSubId !== '' ? (string) ($subgroup['title'] ?? $domainTitle) : $domainTitle) ?></a>
            <?php endif; ?>
        </nav>
        <?php endif; ?>

        <section class="dashboard-ora-panel" aria-label="شاشات <?= esc($hubPageTitle) ?>">
            <h2 class="dashboard-ora-panel__title">الشاشات والتقارير</h2>
            <p class="dashboard-ora-panel__sub"><?= (int) count($items) ?> عنصر متاح</p>
            <div class="dashboard-ora-panel__body nav-hub-ora-grid-wrap">
                <div class="nav-hub-ora-grid" role="list">
                    <?php foreach ($items as $it): ?>
                        <?php
                        $r = (string) $it['r'];
                        $url = nav_screen_url($r, $domainId, $subId, $nestedSubId);
                        $icon = (string) ($it['icon'] ?? '📄');
                        $label = (string) ($it['label'] ?? $r);
                        ?>
                        <a class="nav-hub-ora-tile" role="listitem" href="<?= esc(app_mdi_hub_nav_url($url, true)) ?>"<?= (app_mdi_is_embed_request() || app_mdi_is_park_menu_embed()) ? ' target="_parent"' : '' ?>>
                            <span class="nav-hub-ora-tile-icon" aria-hidden="true"><?= esc($icon) ?></span>
                            <span class="nav-hub-ora-tile-label"><?= esc($label) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    </div>
</div>
