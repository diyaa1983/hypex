<?php
declare(strict_types=1);

/**
 * تتبّع المواقع الحية — نقاط المستخدمين على الخريطة (تحديث لحظي).
 *
 * GET:
 *   online_seconds  (افتراضي 1800 = 30 دقيقة) — يعتبر «متصل» خلال هذه الثواني
 *   stale_seconds   (افتراضي 1800) — يظهر فقط من حدّث موقعه خلال هذه المدة
 *   include_stale   0|1 (افتراضي 0) — لا يُعرض المتوقفون افتراضياً
 *   q               بحث بالاسم
 *
 * توافق خلفي: online_minutes / stale_minutes ما زالا مقبولين.
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/sys_user_location.php');
require_once app_path('includes/app_osm.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (!sys_user_location_may_track()) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'forbidden',
        'message' => 'لا توجد صلاحية لتتبّع المواقع الحية.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = db();
    sys_user_location_ensure_schema($pdo);

    $onlineSeconds = isset($_GET['online_seconds'])
        ? (int) $_GET['online_seconds']
        : (isset($_GET['online_minutes']) ? (int) $_GET['online_minutes'] * 60 : 1800);
    $staleSeconds = isset($_GET['stale_seconds'])
        ? (int) $_GET['stale_seconds']
        : (isset($_GET['stale_minutes']) ? (int) $_GET['stale_minutes'] * 60 : 1800);
    // افتراضياً: لا نُظهر غير المتصلين — التتبّع الحي فقط.
    $includeStale = isset($_GET['include_stale']) && (string) $_GET['include_stale'] === '1';
    $q = trim((string) ($_GET['q'] ?? ''));

    $rows = sys_user_location_tracker_rows(
        $pdo,
        1,
        1,
        $q,
        $includeStale,
        $onlineSeconds,
        $staleSeconds
    );

    $online = 0;
    foreach ($rows as $r) {
        if (!empty($r['is_online'])) {
            $online++;
        }
    }

    $osm = app_osm_js_config();

    echo json_encode([
        'ok' => true,
        'server_time' => date('c'),
        'online_seconds' => max(15, min(600, $onlineSeconds)),
        'stale_seconds' => max(15, min(3600, $staleSeconds)),
        'online_minutes' => max(1, (int) ceil(max(15, min(600, $onlineSeconds)) / 60)),
        'stale_minutes' => max(1, (int) ceil(max(15, min(3600, $staleSeconds)) / 60)),
        'counts' => [
            'total' => count($rows),
            'online' => $online,
            'away' => 0,
            'offline' => 0,
        ],
        'map' => [
            'tile_url' => $osm['tileUrl'],
            'attribution' => $osm['attribution'],
            'default_lat' => 31.9539,
            'default_lng' => 35.9106,
            'default_zoom' => 8,
        ],
        'markers' => $rows,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('user_gps_tracker_live: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'server_error',
        'message' => 'حدث خطأ أثناء تحميل نقاط التتبّع.',
    ], JSON_UNESCAPED_UNICODE);
}
