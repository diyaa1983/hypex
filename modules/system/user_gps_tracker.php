<?php
declare(strict_types=1);

/**
 * تتبّع المواقع الحية — خريطة سطح المكتب (مثل تتبّع أسطول السيارات).
 */
require_once app_path('includes/sys_user_location.php');
require_once app_path('includes/app_osm.php');
require_once app_path('includes/nav_helpers.php');

if (!sys_user_location_may_track()) {
    http_response_code(403);
    echo '<div class="alert alert-danger">لا توجد صلاحية لتتبّع المواقع الحية.</div>';
    return;
}

$pdo = db();
sys_user_location_ensure_schema($pdo);

$apiUrl = app_url('api/user_gps_tracker_live.php');
$trackApiUrl = app_url('api/user_gps_track_day.php');
$cssPath = app_path('assets/css/user-gps-tracker.css');
$jsPath = app_path('assets/js/user-gps-tracker.js');
$routeJsPath = app_path('assets/js/user-gps-route.js');
$cssV = is_file($cssPath) ? (string) filemtime($cssPath) : '';
$jsV = is_file($jsPath) ? (string) filemtime($jsPath) : '';
$routeJsV = is_file($routeJsPath) ? (string) filemtime($routeJsPath) : '';
$osm = app_osm_js_config();
$exitUrl = nav_exit_url('user_gps_tracker');
$today = date('Y-m-d');
?>
<link rel="stylesheet" href="<?= esc(app_url('assets/css/user-gps-tracker.css')) ?><?= $cssV !== '' ? '?v=' . esc($cssV) : '' ?>">

<div class="ugt-page" id="ugt-root"
     data-api="<?= esc($apiUrl) ?>"
     data-tile-url="<?= esc($osm['tileUrl']) ?>"
     data-attribution="<?= esc($osm['attribution']) ?>"
     data-poll-sec="5"
     data-online-seconds="900"
     data-stale-seconds="7200"
     data-mode="desktop">
    <div class="ugt-toolbar">
        <div class="ugt-toolbar__title">
            <span class="ugt-toolbar__icon" aria-hidden="true">📡</span>
            <div>
                <strong>تتبّع المواقع</strong>
                <small>الأجهزة الحيّة الآن وخط السير اليومي</small>
            </div>
        </div>
        <div class="ugt-modeswitch" role="tablist">
            <button type="button" class="ugt-modeswitch__btn is-active" id="ugt-mode-live">التتبّع الحي</button>
            <button type="button" class="ugt-modeswitch__btn" id="ugt-mode-route">المسار اليومي</button>
        </div>
        <div class="ugt-toolbar__actions">
            <a class="btn btn-sm btn-secondary" href="<?= esc($exitUrl) ?>">خروج</a>
        </div>
    </div>

    <!-- عرض التتبّع الحي -->
    <div id="ugt-live-view">
        <div class="ugt-subbar">
                <div class="ugt-toolbar__stats" id="ugt-stats">
                <span class="ugt-chip ugt-chip--online"><b id="ugt-cnt-online">0</b> متصل الآن</span>
                <span class="ugt-chip ugt-chip--away"><b id="ugt-cnt-away">0</b> غير نشط</span>
                <span class="ugt-chip"><b id="ugt-cnt-total">0</b> على الخريطة</span>
            </div>
            <div class="ugt-subbar__actions">
                <label class="ugt-check"><input type="checkbox" id="ugt-include-stale" checked> إظهار غير النشطين (ساعتان)</label>
                <input type="search" id="ugt-search" class="ugt-search" placeholder="بحث بالاسم..." autocomplete="off">
                <button type="button" class="btn btn-sm btn-primary" id="ugt-refresh">تحديث</button>
            </div>
        </div>
        <div class="ugt-body">
            <aside class="ugt-sidebar" id="ugt-sidebar">
                <div class="ugt-sidebar__head">المتصلون الآن</div>
                <div class="ugt-sidebar__list" id="ugt-list">
                    <div class="ugt-empty">جاري التحميل...</div>
                </div>
            </aside>
            <div class="ugt-map-wrap">
                <div id="ugt-map" class="ugt-map" role="application" aria-label="خريطة التتبّع"></div>
                <div class="ugt-legend">
                    <span><i class="ugt-dot ugt-dot--online"></i> متصل (آخر 15 دقيقة)</span>
                    <span><i class="ugt-dot ugt-dot--away"></i> غير نشط (آخر ساعتين)</span>
                </div>
                <div class="ugt-status" id="ugt-status">تحديث لحظي كل 5 ثوانٍ</div>
            </div>
        </div>
    </div>

    <!-- عرض المسار اليومي -->
    <div id="ugt-route-view" hidden>
        <div class="ugr-root" id="ugr-root"
             data-track-api="<?= esc($trackApiUrl) ?>"
             data-tile-url="<?= esc($osm['tileUrl']) ?>"
             data-attribution="<?= esc($osm['attribution']) ?>"
             data-today="<?= esc($today) ?>"
             data-mode="desktop">
            <div class="ugr-controls">
                <label class="ugr-field">
                    <span>المندوب</span>
                    <select id="ugr-user" class="ugr-select"><option value="">— اختر —</option></select>
                </label>
                <label class="ugr-field">
                    <span>التاريخ</span>
                    <input type="date" id="ugr-date" class="ugr-date" value="<?= esc($today) ?>" max="<?= esc($today) ?>">
                </label>
                <button type="button" class="btn btn-sm btn-secondary" id="ugr-prev" title="اليوم السابق">‹</button>
                <button type="button" class="btn btn-sm btn-secondary" id="ugr-next" title="اليوم التالي">›</button>
                <button type="button" class="btn btn-sm btn-primary" id="ugr-load">عرض المسار</button>
            </div>
            <div class="ugr-summary" id="ugr-summary"></div>
            <div class="ugr-body">
                <aside class="ugr-sidebar" id="ugr-stops">
                    <div class="ugr-sidebar__head">التوقفات</div>
                    <div class="ugr-empty">اختر مندوباً وتاريخاً ثم اضغط «عرض المسار».</div>
                </aside>
                <div class="ugr-map-wrap">
                    <div id="ugr-map" class="ugr-map" role="application" aria-label="خريطة المسار"></div>
                    <div class="ugr-legend">
                        <span><i class="ugr-line"></i> خط السير على الشوارع</span>
                        <span><i class="ugr-dot ugr-dot--start"></i> البداية</span>
                        <span><i class="ugr-dot ugr-dot--stop"></i> توقف</span>
                        <span><i class="ugr-dot ugr-dot--end"></i> النهاية</span>
                    </div>
                    <div class="ugr-status" id="ugr-status"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?= esc(app_url('assets/js/user-gps-tracker.js')) ?><?= $jsV !== '' ? '?v=' . esc($jsV) : '' ?>"></script>
<script src="<?= esc(app_url('assets/js/user-gps-route.js')) ?><?= $routeJsV !== '' ? '?v=' . esc($routeJsV) : '' ?>"></script>
