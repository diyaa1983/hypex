<?php
declare(strict_types=1);

/**
 * خط السير اليومي لمستخدم — نقاط GPS مرتّبة زمنياً + التوقفات + الملخّص.
 *
 * GET:
 *   user_id  (اختياري) — إن غاب تُرجَع قائمة المستخدمين فقط
 *   date     (Y-m-d، افتراضي اليوم)
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
        'message' => 'لا توجد صلاحية لعرض خط السير.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = db();
    sys_user_location_ensure_schema($pdo);

    $userId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
    $dateRaw = trim((string) ($_GET['date'] ?? ''));
    $date = '';
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $dateRaw, $m)) {
        $date = $m[1] . '-' . $m[2] . '-' . $m[3];
    } elseif (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/', $dateRaw, $m)) {
        $date = sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
    } else {
        $dateTsTmp = $dateRaw !== '' ? strtotime($dateRaw) : false;
        $date = $dateTsTmp !== false ? date('Y-m-d', $dateTsTmp) : date('Y-m-d');
    }
    $dateTs = strtotime($date . ' 00:00:00') ?: time();

    $users = sys_user_location_track_users($pdo);
    $osm = app_osm_js_config();

    $response = [
        'ok' => true,
        'server_time' => date('c'),
        'date' => $date,
        'date_dmy' => date('d-m-Y', $dateTs),
        'user_id' => $userId,
        'users' => $users,
        'map' => [
            'tile_url' => $osm['tileUrl'],
            'attribution' => $osm['attribution'],
            'map_provider' => $osm['mapProvider'],
            'default_lat' => 31.9539,
            'default_lng' => 35.9106,
            'default_zoom' => 8,
        ],
        'points' => [],
        'segments' => [],
        'stops' => [],
        'road_path' => [],
        'road_paths' => [],
        'track_lines' => [],
        'road_matched' => false,
        'presence' => [],
        'summary' => null,
    ];

    if ($userId > 0) {
        $track = sys_user_location_track_day($pdo, $userId, $date);
        $response['points'] = $track['points'];
        $response['segments'] = $track['segments'];
        $response['stops'] = $track['stops'];
        $response['presence'] = $track['presence'] ?? [];
        $response['road_path'] = $track['road_path'] ?? [];
        $response['road_paths'] = $track['road_paths'] ?? [];
        $response['track_lines'] = $track['track_lines'] ?? [];
        $response['road_matched'] = !empty($track['road_matched']);
        $response['summary'] = $track['summary'];

        $label = '';
        foreach ($users as $u) {
            if ((int) ($u['user_id'] ?? 0) === $userId) {
                $label = (string) ($u['user_label'] ?? '');
                break;
            }
        }
        $response['user_label'] = $label;
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('user_gps_track_day: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'server_error',
        'message' => 'حدث خطأ أثناء تحميل خط السير.',
    ], JSON_UNESCAPED_UNICODE);
}
