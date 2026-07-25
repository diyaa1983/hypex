<?php
declare(strict_types=1);

/**
 * تتبّع المواقع الحية — خريطة الهاتف (/m).
 */
require_once app_path('includes/mobile_auth.php');
require_once app_path('includes/sys_user_location.php');
require_once app_path('includes/app_osm.php');

if (!sys_user_location_may_track()) {
    echo '<div class="m-alert m-alert--danger">لا توجد صلاحية لتتبّع المواقع الحية.</div>';
    return;
}

$apiUrl = app_url('api/user_gps_tracker_live.php');
$cssPath = app_path('assets/css/user-gps-tracker.css');
$jsPath = app_path('assets/js/user-gps-tracker.js');
$cssV = is_file($cssPath) ? (string) filemtime($cssPath) : '';
$jsV = is_file($jsPath) ? (string) filemtime($jsPath) : '';
$osm = app_osm_js_config();
?>
<link rel="stylesheet" href="<?= esc(app_url('assets/css/user-gps-tracker.css')) ?><?= $cssV !== '' ? '?v=' . esc($cssV) : '' ?>">

<div class="ugt-page ugt-page--mobile" id="ugt-root"
     data-api="<?= esc($apiUrl) ?>"
     data-tile-url="<?= esc($osm['tileUrl']) ?>"
     data-attribution="<?= esc($osm['attribution']) ?>"
     data-poll-sec="30"
     data-online-minutes="15"
     data-stale-minutes="120"
     data-mode="mobile">
    <div class="ugt-toolbar ugt-toolbar--mobile">
        <div class="ugt-toolbar__title">
            <strong>تتبّع المواقع</strong>
            <small id="ugt-mobile-summary">—</small>
        </div>
        <div class="ugt-toolbar__actions">
            <button type="button" class="m-btn m-btn--ghost" id="ugt-toggle-list" title="قائمة الأجهزة">☰</button>
            <button type="button" class="m-btn m-btn--primary" id="ugt-refresh">⟳</button>
        </div>
    </div>

    <div class="ugt-body ugt-body--mobile">
        <aside class="ugt-sidebar ugt-sidebar--drawer" id="ugt-sidebar" hidden>
            <div class="ugt-sidebar__head">
                الأجهزة
                <label class="ugt-toggle">
                    <input type="checkbox" id="ugt-include-stale" checked>
                    غير النشطين
                </label>
            </div>
            <input type="search" id="ugt-search" class="ugt-search" placeholder="بحث..." autocomplete="off">
            <div class="ugt-sidebar__list" id="ugt-list">
                <div class="ugt-empty">جاري التحميل...</div>
            </div>
        </aside>
        <div class="ugt-map-wrap">
            <div id="ugt-map" class="ugt-map" role="application" aria-label="خريطة التتبّع"></div>
            <div class="ugt-legend ugt-legend--compact">
                <span><i class="ugt-dot ugt-dot--online"></i> متصل</span>
                <span><i class="ugt-dot ugt-dot--away"></i> غير نشط</span>
            </div>
            <div class="ugt-status" id="ugt-status"></div>
        </div>
    </div>
</div>

<script src="<?= esc(app_url('assets/js/user-gps-tracker.js')) ?><?= $jsV !== '' ? '?v=' . esc($jsV) : '' ?>"></script>
