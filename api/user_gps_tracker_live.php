<?php
declare(strict_types=1);

/**
 * تتبّع المواقع الحية — نقاط المستخدمين على الخريطة (تحديث لحظي).
 *
 * GET:
 *   online_seconds  (افتراضي 20) — شارة «متصل» إن وُجدت نبضة خلال هذه الثواني
 *   stale_seconds   (افتراضي = online) — لا يُستخدم عند include_stale=0
 *   include_stale   0|1 (افتراضي 0) — إظهار غير المتصلين ضمن النافذة
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
        : (isset($_GET['online_minutes']) ? (int) $_GET['online_minutes'] * 60 : 60);
    $staleSeconds = isset($_GET['stale_seconds'])
        ? (int) $_GET['stale_seconds']
        : (isset($_GET['stale_minutes']) ? (int) $_GET['stale_minutes'] * 60 : $onlineSeconds);
    // افتراضياً: المتصلون فقط (نبضة خلال online_seconds).
    $includeStale = isset($_GET['include_stale']) && (string) $_GET['include_stale'] === '1';
    // مندوبو اليوم: آخر موقع لليوم حتى لو خارج نافذة «متصل» — لعرض الجميع معاً.
    $includeDayReps = !isset($_GET['include_day_reps']) || (string) $_GET['include_day_reps'] === '1';
    $q = trim((string) ($_GET['q'] ?? ''));

    $onlineSeconds = max(15, min(12 * 3600, $onlineSeconds));
    $staleSeconds = max($onlineSeconds, min(24 * 3600, $staleSeconds));

    $rows = sys_user_location_tracker_rows(
        $pdo,
        1,
        1,
        $q,
        $includeStale,
        $onlineSeconds,
        $staleSeconds
    );

    // دمج مندوبي اليوم (آخر موقع اليوم) مع المتصلين — بدون تكرار.
    if ($includeDayReps && !$includeStale) {
        $byUser = [];
        foreach ($rows as $r) {
            $uid = (int) ($r['user_id'] ?? 0);
            if ($uid > 0) {
                $byUser[$uid] = $r;
            }
        }
        if (!function_exists('sys_gps_track_groups_user_filter_sql')) {
            require_once app_path('includes/sys_gps_track_groups.php');
        }
        [$trackSql, $trackParams] = sys_gps_track_groups_user_filter_sql($pdo, 'u');
        $daySql = 'SELECT ul.user_id, ul.latitude, ul.longitude, ul.gps_accuracy, ul.gps_source, ul.captured_at,
                          u.username, u.full_name_ar,
                          TIMESTAMPDIFF(SECOND, ul.captured_at, NOW()) AS age_sec
                   FROM sys_user_location ul
                   INNER JOIN sys_user u ON u.id = ul.user_id AND u.is_active = 1
                   WHERE DATE(ul.captured_at) = CURDATE()'
            . $trackSql;
        $dayParams = $trackParams;
        if ($q !== '') {
            $daySql .= ' AND (u.username LIKE ? OR u.full_name_ar LIKE ?)';
            $like = '%' . $q . '%';
            $dayParams[] = $like;
            $dayParams[] = $like;
        }
        $daySql .= ' ORDER BY ul.captured_at DESC LIMIT 500';
        $daySt = $pdo->prepare($daySql);
        $daySt->execute($dayParams);
        foreach ($daySt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $uid = (int) ($row['user_id'] ?? 0);
            if ($uid < 1 || isset($byUser[$uid])) {
                continue;
            }
            $lat = (float) ($row['latitude'] ?? 0);
            $lng = (float) ($row['longitude'] ?? 0);
            if (!function_exists('sal_invoice_gps_coords_valid')) {
                require_once app_path('includes/sal_invoice_gps.php');
            }
            if (!sal_invoice_gps_coords_valid($lat, $lng)) {
                continue;
            }
            $ageSec = max(0, (int) ($row['age_sec'] ?? 0));
            $isOnline = $ageSec <= $onlineSeconds;
            $capturedAt = (string) ($row['captured_at'] ?? '');
            $ts = $capturedAt !== '' ? strtotime($capturedAt) : false;
            $rawSrc = isset($row['gps_source']) ? trim((string) $row['gps_source']) : '';
            $userLabel = sal_invoice_user_display_name(
                (string) ($row['full_name_ar'] ?? ''),
                (string) ($row['username'] ?? '')
            );
            $byUser[$uid] = [
                'user_id' => $uid,
                'user_label' => $userLabel,
                'username' => (string) ($row['username'] ?? ''),
                'latitude' => $lat,
                'longitude' => $lng,
                'gps_accuracy' => isset($row['gps_accuracy']) && $row['gps_accuracy'] !== null && $row['gps_accuracy'] !== ''
                    ? (float) $row['gps_accuracy']
                    : null,
                'accuracy_label' => !empty($row['gps_accuracy'])
                    ? round((float) $row['gps_accuracy']) . ' م'
                    : '',
                'gps_source' => $rawSrc !== '' ? $rawSrc : null,
                'source_label' => sal_invoice_gps_source_label($rawSrc !== '' ? $rawSrc : null),
                'captured_at' => $capturedAt,
                'captured_at_dmy' => $ts !== false ? date('d-m-Y H:i', $ts) : '',
                'age_sec' => $ageSec,
                'age_label' => sys_user_location_age_label($ageSec),
                'is_online' => $isOnline,
                'status' => $isOnline ? 'online' : 'offline',
                'status_label' => $isOnline ? 'متصل' : 'غير متصل',
                'map_url' => sal_invoice_gps_map_url($lat, $lng),
            ];
        }
        $rows = array_values($byUser);
        usort($rows, static function (array $a, array $b): int {
            $ao = !empty($a['is_online']) ? 0 : 1;
            $bo = !empty($b['is_online']) ? 0 : 1;
            if ($ao !== $bo) {
                return $ao <=> $bo;
            }

            return ((int) ($a['age_sec'] ?? 0)) <=> ((int) ($b['age_sec'] ?? 0));
        });
    }

    $online = 0;
    $offline = 0;
    foreach ($rows as $r) {
        if (!empty($r['is_online'])) {
            $online++;
        } else {
            $offline++;
        }
    }

    if (!function_exists('sys_gps_track_group_ids')) {
        require_once app_path('includes/sys_gps_track_groups.php');
    }
    $trackGroupIds = sys_gps_track_group_ids($pdo);
    $lastPings = sys_user_location_recent_snapshots($pdo, 8);
    $hint = '';
    if ($trackGroupIds === []) {
        $hint = 'لم تُحدد مجموعات للتتبع. من شاشة الصلاحيات ضع علامة ✓ بجانب المجموعة ثم احفظ مجموعات التتبع.';
    } elseif ($rows === [] && $lastPings !== []) {
        $top = $lastPings[0];
        $hint = 'لا يوجد مندوب متصل من المجموعات المحددة. آخر موقع محفوظ: '
            . (string) ($top['user_label'] ?? '')
            . ' — '
            . (string) ($top['age_label'] ?? '')
            . '.';
        if (empty($top['coords_valid'])) {
            $hint .= ' الإحداثيات غير صالحة للعرض على الخريطة.';
        } elseif ((int) ($top['age_sec'] ?? 999999) > $onlineSeconds) {
            $hint .= ' النبضة أقدم من نافذة الاتصال (' . $onlineSeconds . ' ثانية).';
        }
    } elseif ($rows === []) {
        $hint = 'لا يوجد مندوب متصل من المجموعات المحددة. تأكد أن تطبيق المندوب يرسل الموقع وأن المستخدم ضمن مجموعة مفعّلة للتتبع.';
    }

    $osm = app_osm_js_config();

    echo json_encode([
        'ok' => true,
        'server_time' => date('c'),
        'online_seconds' => $onlineSeconds,
        'stale_seconds' => $staleSeconds,
        'include_stale' => $includeStale,
        'include_day_reps' => $includeDayReps,
        'online_minutes' => max(1, (int) ceil($onlineSeconds / 60)),
        'stale_minutes' => max(1, (int) ceil($staleSeconds / 60)),
        'counts' => [
            'total' => count($rows),
            'online' => $online,
            'away' => $offline,
            'offline' => $offline,
        ],
        'hint' => $hint,
        'last_pings' => $lastPings,
        'map' => [
            'tile_url' => $osm['tileUrl'],
            'attribution' => $osm['attribution'],
            'map_provider' => $osm['mapProvider'],
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
