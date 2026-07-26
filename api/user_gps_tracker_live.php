<?php
declare(strict_types=1);

/**
 * تتبّع المواقع الحية — نقاط المستخدمين على الخريطة (تحديث لحظي).
 *
 * GET:
 *   online_seconds  (افتراضي 900 = 15 دقيقة) — شارة «متصل»
 *   stale_seconds   (افتراضي 7200 = ساعتان) — يظهر على الخريطة
 *   include_stale   0|1 (افتراضي 1) — إظهار غير النشطين ضمن النافذة
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
        : (isset($_GET['online_minutes']) ? (int) $_GET['online_minutes'] * 60 : 900);
    $staleSeconds = isset($_GET['stale_seconds'])
        ? (int) $_GET['stale_seconds']
        : (isset($_GET['stale_minutes']) ? (int) $_GET['stale_minutes'] * 60 : 7200);
    // افتراضياً نظهر من حدّث موقعه خلال ساعتين (مثل السلوك السابق قبل التضييق).
    $includeStale = !isset($_GET['include_stale']) || (string) $_GET['include_stale'] === '1';
    $q = trim((string) ($_GET['q'] ?? ''));

    $onlineSeconds = max(60, min(12 * 3600, $onlineSeconds));
    $staleSeconds = max($onlineSeconds, min(24 * 3600, $staleSeconds));

    $rows = sys_user_location_tracker_rows(
        $pdo,
        15,
        120,
        $q,
        $includeStale,
        $onlineSeconds,
        $staleSeconds
    );

    $online = 0;
    $away = 0;
    foreach ($rows as $r) {
        if (!empty($r['is_online'])) {
            $online++;
        } else {
            $away++;
        }
    }

    $lastPings = sys_user_location_recent_snapshots($pdo, 8);
    $hint = '';
    if ($rows === [] && $lastPings !== []) {
        $top = $lastPings[0];
        $hint = 'آخر موقع محفوظ: '
            . (string) ($top['user_label'] ?? '')
            . ' — '
            . (string) ($top['age_label'] ?? '')
            . '. التطبيق مفتوح لكن الموقع لا يُرسل إلى هذا السيرفر حالياً.';
    } elseif ($rows === []) {
        $hint = 'لا توجد مواقع محفوظة. تأكد أن تطبيق المندوب مضبوط على نفس عنوان هذا السيرفر، وأن إذن الموقع مفعّل، ثم اضغط «إرسال الآن» من الإعدادات.';
    }

    $osm = app_osm_js_config();

    echo json_encode([
        'ok' => true,
        'server_time' => date('c'),
        'online_seconds' => $onlineSeconds,
        'stale_seconds' => $staleSeconds,
        'include_stale' => $includeStale,
        'online_minutes' => max(1, (int) ceil($onlineSeconds / 60)),
        'stale_minutes' => max(1, (int) ceil($staleSeconds / 60)),
        'counts' => [
            'total' => count($rows),
            'online' => $online,
            'away' => $away,
            'offline' => 0,
        ],
        'hint' => $hint,
        'last_pings' => $lastPings,
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
