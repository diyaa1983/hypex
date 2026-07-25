<?php
declare(strict_types=1);

/**
 * تتبّع المواقع الحية — نقاط المستخدمين على الخريطة (تحديث دوري).
 *
 * GET:
 *   online_minutes  (افتراضي 15) — يعتبر «متصل» خلال هذه المدة
 *   stale_minutes   (افتراضي 120) — يظهر آخر موقع حتى هذه المدة
 *   include_stale   0|1 (افتراضي 1)
 *   q               بحث بالاسم
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

    $onlineMinutes = isset($_GET['online_minutes']) ? (int) $_GET['online_minutes'] : 15;
    $staleMinutes = isset($_GET['stale_minutes']) ? (int) $_GET['stale_minutes'] : 120;
    $includeStale = !isset($_GET['include_stale']) || (string) $_GET['include_stale'] !== '0';
    $q = trim((string) ($_GET['q'] ?? ''));

    $rows = sys_user_location_tracker_rows(
        $pdo,
        $onlineMinutes,
        $staleMinutes,
        $q,
        $includeStale
    );

    $online = 0;
    $away = 0;
    foreach ($rows as $r) {
        if (!empty($r['is_online'])) {
            $online++;
        } elseif (($r['status'] ?? '') === 'away') {
            $away++;
        }
    }

    $osm = app_osm_js_config();

    echo json_encode([
        'ok' => true,
        'server_time' => date('c'),
        'online_minutes' => max(1, min(120, $onlineMinutes)),
        'stale_minutes' => max(1, min(24 * 60, $staleMinutes)),
        'counts' => [
            'total' => count($rows),
            'online' => $online,
            'away' => $away,
            'offline' => max(0, count($rows) - $online - $away),
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
