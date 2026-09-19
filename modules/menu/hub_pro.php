<?php
declare(strict_types=1);

require_once app_path('includes/nav_helpers.php');
require_once app_path('includes/app_window_manager.php');
require_once app_path('includes/hub_icons.php');

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
    <div class="dashboard-ora nav-hub-ora nav-hub-ora--pro">
        <div class="dashboard-ora-workspace">
            <section class="dashboard-ora-panel dashboard-ora-panel--hub" aria-label="مجلدات <?= esc($domainTitle) ?>">
                <div class="dashboard-ora-panel__head">
                    <h2 class="dashboard-ora-panel__title"><?= te('المجلدات') ?></h2>
                    <p class="dashboard-ora-panel__lead"><?= (int) count($folders) ?> <?= te('مجموعة متاحة') ?></p>
                </div>
                <div class="dashboard-ora-panel__body nav-hub-ora-grid-wrap">
                    <div class="nav-hub-ora-grid nav-hub-ora-grid--folders" role="list">
                        <?php foreach ($folders as $folder): ?>
                            <a
                                class="nav-hub-ora-tile nav-hub-ora-tile--folder"
                                role="listitem"
                                href="<?= esc(app_mdi_hub_nav_url(nav_hub_url($domainId, $folder['id']))) ?>"
                            >
                                <?= hub_icon_html((string) $folder['id'], (string) $folder['title'], true) ?>
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
    <div class="dashboard-ora nav-hub-ora nav-hub-ora--pro">
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

            <section class="dashboard-ora-panel dashboard-ora-panel--hub" aria-label="مجلدات <?= esc($subTitle) ?>">
                <div class="dashboard-ora-panel__head">
                    <h2 class="dashboard-ora-panel__title"><?= te('المجلدات') ?></h2>
                    <p class="dashboard-ora-panel__lead"><?= (int) count($nestedFolders) ?> <?= te('مجموعة متاحة') ?></p>
                </div>
                <div class="dashboard-ora-panel__body nav-hub-ora-grid-wrap">
                    <div class="nav-hub-ora-grid nav-hub-ora-grid--folders" role="list">
                        <?php foreach ($nestedFolders as $folder): ?>
                            <a
                                class="nav-hub-ora-tile nav-hub-ora-tile--folder"
                                role="listitem"
                                href="<?= esc(app_mdi_hub_nav_url(nav_hub_url($domainId, $subId, $folder['id']))) ?>"
                            >
                                <?= hub_icon_html((string) $folder['id'], (string) $folder['title'], true) ?>
                                <span class="nav-hub-ora-tile-label"><?= esc($folder['title']) ?></span>
                                <span class="nav-hub-ora-tile-meta"><?= (int) $folder['count'] ?> <?= te('شاشة') ?></span>
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
$isFavoritesHub = $domainId === 'favorites';

?>
<div class="dashboard-ora nav-hub-ora nav-hub-ora--pro<?= $isFavoritesHub ? ' nav-hub-ora--favorites' : '' ?>">
    <div class="dashboard-ora-workspace<?= $isFavoritesHub ? ' nav-fav-workspace' : '' ?>">
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

        <?php if ($isFavoritesHub): ?>
        <section class="nav-fav-gallery" aria-label="المفضلة" id="nav-fav-gallery">
            <div class="nav-fav-gallery__stage">
                <div class="nav-fav-search" role="search">
                    <label class="nav-fav-search__label" for="nav-fav-search-input">بحث في المفضلة</label>
                    <div class="nav-fav-search__field">
                        <span class="nav-fav-search__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="11" cy="11" r="7"></circle>
                                <path d="M20 20l-3.5-3.5"></path>
                            </svg>
                        </span>
                        <input
                            type="search"
                            id="nav-fav-search-input"
                            class="nav-fav-search__input"
                            placeholder="ابحث عن شاشة أو تقرير في المفضلة…"
                            autocomplete="off"
                            enterkeyhint="search"
                            spellcheck="false"
                        >
                        <button type="button" class="nav-fav-search__clear" id="nav-fav-search-clear" hidden aria-label="مسح البحث">×</button>
                    </div>
                    <p class="nav-fav-search__hint" id="nav-fav-search-hint" hidden>لا توجد نتائج مطابقة</p>
                </div>
                <p class="nav-fav-gallery__eyebrow">المفضلة</p>
                <div class="nav-fav-gallery__grid" role="list" id="nav-fav-grid">
                    <?php foreach ($items as $idx => $it): ?>
                        <?php
                        $r = (string) $it['r'];
                        $url = nav_screen_url($r, $domainId, $subId, $nestedSubId);
                        $label = (string) ($it['label'] ?? $r);
                        $delay = min(12, (int) $idx) * 45;
                        ?>
                        <a class="nav-fav-tile"
                           role="listitem"
                           href="<?= esc(app_mdi_hub_nav_url($url, true)) ?>"
                           style="--fav-delay: <?= (int) $delay ?>ms"
                           title="<?= esc($label) ?>"
                           data-fav-label="<?= esc(mb_strtolower($label, 'UTF-8')) ?>"
                           data-fav-route="<?= esc(mb_strtolower($r, 'UTF-8')) ?>"
                           <?= (app_mdi_is_embed_request() || app_mdi_is_park_menu_embed()) ? ' target="_parent"' : '' ?>>
                            <span class="nav-fav-tile__face">
                                <?= hub_icon_html($r, $label, false) ?>
                            </span>
                            <span class="nav-fav-tile__label"><?= esc($label) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <?php
        $favSearchJsPath = app_path('assets/js/nav-favorites-search.js');
        $favSearchJsV = is_file($favSearchJsPath) ? (string) filemtime($favSearchJsPath) : '';
        ?>
        <script src="<?= esc(app_url('assets/js/nav-favorites-search.js')) ?><?= $favSearchJsV !== '' ? '?v=' . esc($favSearchJsV) : '' ?>" defer></script>
        <?php else: ?>
        <section class="dashboard-ora-panel dashboard-ora-panel--hub" aria-label="شاشات <?= esc($hubPageTitle) ?>">
            <div class="dashboard-ora-panel__head">
                <h2 class="dashboard-ora-panel__title"><?= te('الشاشات والتقارير') ?></h2>
                <p class="dashboard-ora-panel__lead"><?= (int) count($items) ?> <?= te('عنصر متاح') ?></p>
            </div>
            <div class="dashboard-ora-panel__body nav-hub-ora-grid-wrap">
                <div class="nav-hub-ora-grid" role="list">
                    <?php foreach ($items as $it): ?>
                        <?php
                        $r = (string) $it['r'];
                        $url = nav_screen_url($r, $domainId, $subId, $nestedSubId);
                        $label = (string) ($it['label'] ?? $r);
                        ?>
                        <a class="nav-hub-ora-tile" role="listitem" href="<?= esc(app_mdi_hub_nav_url($url, true)) ?>"<?= (app_mdi_is_embed_request() || app_mdi_is_park_menu_embed()) ? ' target="_parent"' : '' ?>>
                            <?= hub_icon_html($r, $label, false) ?>
                            <span class="nav-hub-ora-tile-label"><?= esc($label) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <?php endif; ?>
    </div>
</div>
