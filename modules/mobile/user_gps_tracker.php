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
$trackApiUrl = app_url('api/user_gps_track_day.php');
$cssPath = app_path('assets/css/user-gps-tracker.css');
$jsPath = app_path('assets/js/user-gps-tracker.js');
$routeJsPath = app_path('assets/js/user-gps-route.js');
$cssV = is_file($cssPath) ? (string) filemtime($cssPath) : '';
$jsV = is_file($jsPath) ? (string) filemtime($jsPath) : '';
$routeJsV = is_file($routeJsPath) ? (string) filemtime($routeJsPath) : '';
$osm = app_osm_js_config();
$today = date('Y-m-d');
?>
<link rel="stylesheet" href="<?= esc(app_url('assets/css/user-gps-tracker.css')) ?><?= $cssV !== '' ? '?v=' . esc($cssV) : '' ?>">

<div class="ugt-page ugt-page--mobile" id="ugt-root"
     data-api="<?= esc($apiUrl) ?>"
     data-tile-url="<?= esc($osm['tileUrl']) ?>"
     data-attribution="<?= esc($osm['attribution']) ?>"
     data-poll-sec="5"
     data-online-seconds="60"
     data-stale-seconds="60"
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

    <div class="ugt-modeswitch ugt-modeswitch--mobile" role="tablist">
        <button type="button" class="ugt-modeswitch__btn is-active" id="ugt-mode-live">التتبّع الحي</button>
        <button type="button" class="ugt-modeswitch__btn" id="ugt-mode-route">المسار اليومي</button>
    </div>

    <div id="ugt-live-view">
        <div class="ugt-body ugt-body--mobile">
            <aside class="ugt-sidebar ugt-sidebar--drawer" id="ugt-sidebar" hidden>
                <div class="ugt-sidebar__head">المتصلون الآن</div>
                <input type="search" id="ugt-search" class="ugt-search" placeholder="بحث..." autocomplete="off">
                <div class="ugt-sidebar__list" id="ugt-list">
                    <div class="ugt-empty">جاري التحميل...</div>
                </div>
            </aside>
            <div class="ugt-map-wrap">
                <div id="ugt-map" class="ugt-map" role="application" aria-label="خريطة التتبّع"></div>
                <div class="ugt-legend ugt-legend--compact">
                    <span><i class="ugt-dot ugt-dot--online"></i> متصل الآن</span>
                </div>
                <div class="ugt-status" id="ugt-status"></div>
            </div>
        </div>
    </div>

    <div id="ugt-route-view" hidden>
        <div class="ugr-root ugr-root--mobile" id="ugr-root"
             data-track-api="<?= esc($trackApiUrl) ?>"
             data-tile-url="<?= esc($osm['tileUrl']) ?>"
             data-attribution="<?= esc($osm['attribution']) ?>"
             data-today="<?= esc($today) ?>"
             data-mode="mobile">
            <div class="ugr-controls">
                <label class="ugr-field">
                    <span>المندوب</span>
                    <select id="ugr-user" class="ugr-select"><option value="">— اختر —</option></select>
                </label>
                <label class="ugr-field">
                    <span>التاريخ</span>
                    <input type="date" id="ugr-date" class="ugr-date" value="<?= esc($today) ?>" max="<?= esc($today) ?>">
                </label>
                <button type="button" class="m-btn m-btn--ghost" id="ugr-prev">‹</button>
                <button type="button" class="m-btn m-btn--ghost" id="ugr-next">›</button>
                <button type="button" class="m-btn m-btn--primary" id="ugr-load">عرض</button>
            </div>
            <div class="ugr-summary" id="ugr-summary"></div>
            <div class="ugr-body">
                <div class="ugr-map-wrap">
                    <div id="ugr-map" class="ugr-map" role="application" aria-label="خريطة المسار"></div>
                    <div class="ugr-legend">
                        <span><i class="ugr-dot ugr-dot--start"></i> البداية</span>
                        <span><i class="ugr-dot ugr-dot--stop"></i> توقف</span>
                        <span><i class="ugr-dot ugr-dot--end"></i> النهاية</span>
                    </div>
                    <div class="ugr-status" id="ugr-status"></div>
                </div>
                <aside class="ugr-sidebar" id="ugr-stops">
                    <div class="ugr-sidebar__head">التوقفات</div>
                    <div class="ugr-empty">اختر مندوباً وتاريخاً ثم اضغط «عرض».</div>
                </aside>
            </div>
        </div>
    </div>
</div>

<script src="<?= esc(app_url('assets/js/user-gps-tracker.js')) ?><?= $jsV !== '' ? '?v=' . esc($jsV) : '' ?>"></script>
<script src="<?= esc(app_url('assets/js/user-gps-route.js')) ?><?= $routeJsV !== '' ? '?v=' . esc($routeJsV) : '' ?>"></script>
