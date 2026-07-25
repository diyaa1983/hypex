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
$cssPath = app_path('assets/css/user-gps-tracker.css');
$jsPath = app_path('assets/js/user-gps-tracker.js');
$cssV = is_file($cssPath) ? (string) filemtime($cssPath) : '';
$jsV = is_file($jsPath) ? (string) filemtime($jsPath) : '';
$osm = app_osm_js_config();
$exitUrl = nav_exit_url('user_gps_tracker');
?>
<link rel="stylesheet" href="<?= esc(app_url('assets/css/user-gps-tracker.css')) ?><?= $cssV !== '' ? '?v=' . esc($cssV) : '' ?>">

<div class="ugt-page" id="ugt-root"
     data-api="<?= esc($apiUrl) ?>"
     data-tile-url="<?= esc($osm['tileUrl']) ?>"
     data-attribution="<?= esc($osm['attribution']) ?>"
     data-poll-sec="30"
     data-online-minutes="15"
     data-stale-minutes="120"
     data-mode="desktop">
    <div class="ugt-toolbar">
        <div class="ugt-toolbar__title">
            <span class="ugt-toolbar__icon" aria-hidden="true">📡</span>
            <div>
                <strong>تتبّع المواقع الحية</strong>
                <small>الأجهزة التي يعمل عليها النظام حالياً</small>
            </div>
        </div>
        <div class="ugt-toolbar__stats" id="ugt-stats">
            <span class="ugt-chip ugt-chip--online"><b id="ugt-cnt-online">0</b> متصل</span>
            <span class="ugt-chip ugt-chip--away"><b id="ugt-cnt-away">0</b> غير نشط</span>
            <span class="ugt-chip"><b id="ugt-cnt-total">0</b> إجمالي</span>
        </div>
        <div class="ugt-toolbar__actions">
            <label class="ugt-toggle">
                <input type="checkbox" id="ugt-include-stale" checked>
                إظهار غير النشطين
            </label>
            <input type="search" id="ugt-search" class="ugt-search" placeholder="بحث بالاسم..." autocomplete="off">
            <button type="button" class="btn btn-sm btn-primary" id="ugt-refresh">تحديث</button>
            <a class="btn btn-sm btn-secondary" href="<?= esc($exitUrl) ?>">خروج</a>
        </div>
    </div>

    <div class="ugt-body">
        <aside class="ugt-sidebar" id="ugt-sidebar">
            <div class="ugt-sidebar__head">الأجهزة</div>
            <div class="ugt-sidebar__list" id="ugt-list">
                <div class="ugt-empty">جاري التحميل...</div>
            </div>
        </aside>
        <div class="ugt-map-wrap">
            <div id="ugt-map" class="ugt-map" role="application" aria-label="خريطة التتبّع"></div>
            <div class="ugt-legend">
                <span><i class="ugt-dot ugt-dot--online"></i> متصل (آخر <?= 15 ?> د)</span>
                <span><i class="ugt-dot ugt-dot--away"></i> غير نشط</span>
            </div>
            <div class="ugt-status" id="ugt-status">تحديث تلقائي كل 30 ثانية</div>
        </div>
    </div>
</div>

<script src="<?= esc(app_url('assets/js/user-gps-tracker.js')) ?><?= $jsV !== '' ? '?v=' . esc($jsV) : '' ?>"></script>
