<?php
declare(strict_types=1);

require_once app_path('includes/sal_invoice_gps.php');

const SYS_USER_LOCATION_MIN_INTERVAL_SEC = 600;
/** الحد الأدنى بين إرسالين من الهاتف — 5 ثوانٍ لتتبّع لحظي. */
const SYS_USER_LOCATION_MIN_INTERVAL_MOBILE_SEC = 10;

/** وقت محلي للتخزين في captured_at (يتوافق مع APP_TIMEZONE). */
function sys_user_location_now_sql(): string
{
    app_apply_timezone();

    return date('Y-m-d H:i:s');
}

function sys_user_location_min_interval_sec(string $source, bool $nativeChannel = false): int
{
    if ($source === 'mobile' || $nativeChannel) {
        return SYS_USER_LOCATION_MIN_INTERVAL_MOBILE_SEC;
    }

    return SYS_USER_LOCATION_MIN_INTERVAL_SEC;
}

function sys_user_location_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    if (!sal_invoice_column_exists($pdo, 'sys_user_location', 'user_id')) {
        sal_invoice_run_migration_file($pdo, '128_sys_user_location.sql');
        if (!sal_invoice_column_exists($pdo, 'sys_user_location', 'user_id')) {
            try {
                $pdo->exec(
                    'CREATE TABLE IF NOT EXISTS sys_user_location (
                        user_id INT UNSIGNED NOT NULL,
                        latitude DECIMAL(10, 7) NOT NULL,
                        longitude DECIMAL(10, 7) NOT NULL,
                        gps_accuracy DECIMAL(10, 2) NULL DEFAULT NULL,
                        gps_source ENUM(\'mobile\', \'desktop\') NOT NULL DEFAULT \'desktop\',
                        gps_place VARCHAR(500) NULL DEFAULT NULL,
                        gps_landmark VARCHAR(300) NULL DEFAULT NULL,
                        captured_at DATETIME NOT NULL,
                        PRIMARY KEY (user_id),
                        CONSTRAINT fk_sys_user_location_user FOREIGN KEY (user_id) REFERENCES sys_user(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
                );
            } catch (Throwable $e) {
                error_log('sys_user_location_ensure_schema: ' . $e->getMessage());
            }
        }
    }

    if (!sys_user_location_has_landmark_column($pdo)) {
        sal_invoice_run_migration_file($pdo, '130_sys_user_location_landmark.sql');
        if (!sys_user_location_has_landmark_column($pdo)) {
            try {
                $pdo->exec(
                    'ALTER TABLE sys_user_location ADD COLUMN gps_landmark VARCHAR(300) NULL DEFAULT NULL AFTER gps_source'
                );
            } catch (Throwable $e) {
                error_log('sys_user_location_ensure_schema landmark: ' . $e->getMessage());
            }
        }
    }

    if (!sys_user_location_has_place_column($pdo)) {
        sal_invoice_run_migration_file($pdo, '131_sys_user_location_place.sql');
        if (!sys_user_location_has_place_column($pdo)) {
            try {
                $pdo->exec(
                    'ALTER TABLE sys_user_location ADD COLUMN gps_place VARCHAR(500) NULL DEFAULT NULL AFTER gps_source'
                );
            } catch (Throwable $e) {
                error_log('sys_user_location_ensure_schema place: ' . $e->getMessage());
            }
        }
    }

    sys_user_location_track_ensure_schema($pdo);

    $done = true;
}

/** جدول تاريخ النقاط (خط السير اليومي). */
function sys_user_location_track_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    if (!sal_invoice_column_exists($pdo, 'sys_user_location_track', 'user_id')) {
        sal_invoice_run_migration_file($pdo, '223_user_gps_track_history.sql');
        if (!sal_invoice_column_exists($pdo, 'sys_user_location_track', 'user_id')) {
            try {
                $pdo->exec(
                    'CREATE TABLE IF NOT EXISTS sys_user_location_track (
                        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                        user_id INT UNSIGNED NOT NULL,
                        latitude DECIMAL(10, 7) NOT NULL,
                        longitude DECIMAL(10, 7) NOT NULL,
                        gps_accuracy DECIMAL(10, 2) NULL DEFAULT NULL,
                        gps_source ENUM(\'mobile\', \'desktop\') NOT NULL DEFAULT \'mobile\',
                        captured_at DATETIME NOT NULL,
                        PRIMARY KEY (id),
                        KEY idx_track_user_time (user_id, captured_at),
                        CONSTRAINT fk_sys_user_track_user FOREIGN KEY (user_id) REFERENCES sys_user(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
                );
            } catch (Throwable $e) {
                error_log('sys_user_location_track_ensure_schema: ' . $e->getMessage());
            }
        }
    }

    $done = true;
}

function sys_user_location_has_place_column(PDO $pdo): bool
{
    static $cached = false;
    if ($cached) {
        return true;
    }
    if (sal_invoice_column_exists($pdo, 'sys_user_location', 'gps_place')) {
        $cached = true;
    }

    return $cached;
}

function sys_user_location_has_landmark_column(PDO $pdo): bool
{
    static $cached = false;
    if ($cached) {
        return true;
    }
    if (sal_invoice_column_exists($pdo, 'sys_user_location', 'gps_landmark')) {
        $cached = true;
    }

    return $cached;
}

function sys_user_location_set_place(PDO $pdo, int $userId, string $place): void
{
    if (!sys_user_location_has_place_column($pdo)) {
        return;
    }

    $place = sal_invoice_gps_trim_place($place);
    if ($userId < 1 || $place === '') {
        return;
    }

    $pdo->prepare('UPDATE sys_user_location SET gps_place = ? WHERE user_id = ?')
        ->execute([$place, $userId]);
}

function sys_user_location_set_landmark(PDO $pdo, int $userId, string $landmark): void
{
    if (!sys_user_location_has_landmark_column($pdo)) {
        return;
    }

    $landmark = sal_invoice_gps_trim_landmark($landmark);
    if ($userId < 1 || $landmark === '') {
        return;
    }

    $pdo->prepare('UPDATE sys_user_location SET gps_landmark = ? WHERE user_id = ?')
        ->execute([$landmark, $userId]);
}

/**
 * @return array{place: ?string, landmark: ?string}
 */
function sys_user_location_resolve_location(float $lat, float $lng): array
{
    if (!sal_invoice_gps_coords_valid($lat, $lng)) {
        return ['place' => null, 'landmark' => null];
    }

    try {
        return sal_invoice_gps_resolve_location($lat, $lng);
    } catch (Throwable $e) {
        error_log('sys_user_location_resolve_location: ' . $e->getMessage());

        return ['place' => null, 'landmark' => null];
    }
}

/**
 * @return array{place: ?string, landmark: ?string}
 */
function sys_user_location_fill_location(PDO $pdo, int $userId, ?float $lat = null, ?float $lng = null): array
{
    sys_user_location_ensure_schema($pdo);
    if ($userId < 1) {
        return ['place' => null, 'landmark' => null];
    }

    $hasPlace = sys_user_location_has_place_column($pdo);
    $hasLandmark = sys_user_location_has_landmark_column($pdo);
    if (!$hasPlace && !$hasLandmark) {
        return ['place' => null, 'landmark' => null];
    }

    $select = 'SELECT latitude, longitude';
    $select .= $hasPlace ? ', gps_place' : ', NULL AS gps_place';
    $select .= $hasLandmark ? ', gps_landmark' : ', NULL AS gps_landmark';
    $select .= ' FROM sys_user_location WHERE user_id = ? LIMIT 1';

    $st = $pdo->prepare($select);
    $st->execute([$userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['place' => null, 'landmark' => null];
    }

    $cachedPlace = $hasPlace ? trim((string) ($row['gps_place'] ?? '')) : '';
    $cachedLandmark = $hasLandmark ? trim((string) ($row['gps_landmark'] ?? '')) : '';
    if ($cachedPlace !== '' && $cachedLandmark !== '') {
        return [
            'place' => $cachedPlace,
            'landmark' => $cachedLandmark,
        ];
    }

    $lat = $lat ?? (float) ($row['latitude'] ?? 0);
    $lng = $lng ?? (float) ($row['longitude'] ?? 0);
    $resolved = sys_user_location_resolve_location($lat, $lng);
    $place = $cachedPlace !== '' ? $cachedPlace : ($resolved['place'] ?? null);
    $landmark = $cachedLandmark !== '' ? $cachedLandmark : ($resolved['landmark'] ?? null);

    if ($hasPlace && $cachedPlace === '' && $place !== null && $place !== '') {
        sys_user_location_set_place($pdo, $userId, $place);
    }
    if ($hasLandmark && $cachedLandmark === '' && $landmark !== null && $landmark !== '') {
        sys_user_location_set_landmark($pdo, $userId, $landmark);
    }

    return [
        'place' => $place !== null && $place !== '' ? $place : null,
        'landmark' => $landmark !== null && $landmark !== '' ? $landmark : null,
    ];
}

function sys_user_location_may_view(PDO $pdo): bool
{
    return is_logged_in() && (
        user_can('user_gps_locations')
        || user_can('m_user_gps_locations')
        || user_can('user_gps_tracker')
        || user_can('m_user_gps_tracker')
    );
}

/** صلاحية شاشة تتبّع الخريطة الحية (ويندوز + هاتف). */
function sys_user_location_may_track(): bool
{
    return is_logged_in() && (
        user_can('user_gps_tracker')
        || user_can('m_user_gps_tracker')
        || user_can('user_gps_locations')
        || user_can('m_user_gps_locations')
    );
}

/**
 * نقاط تتبّع حية للخريطة (مثل تتبّع السيارات).
 * «متصل» = آخر تحديث خلال onlineSeconds.
 * القيمة الافتراضية قصيرة لعرض اللحظة الحالية فقط (لا ساعات).
 *
 * @return list<array<string, mixed>>
 */
function sys_user_location_tracker_rows(
    PDO $pdo,
    int $onlineMinutes = 15,
    int $staleMinutes = 120,
    string $search = '',
    bool $includeStale = true,
    ?int $onlineSeconds = null,
    ?int $staleSeconds = null
): array {
    sys_user_location_ensure_schema($pdo);

    // متصل = نبضة حديثة (افتراضي ~60 ثانية؛ هامش فوق إرسال كل 5–30 ث).
    $onlineSec = $onlineSeconds !== null
        ? max(15, min(12 * 3600, $onlineSeconds))
        : max(15, min(12 * 3600, max(1, min(24 * 60, $onlineMinutes)) * 60));
    $staleSec = $staleSeconds !== null
        ? max($onlineSec, min(24 * 3600, $staleSeconds))
        : max($onlineSec, min(24 * 3600, max(1, min(24 * 60, $staleMinutes)) * 60));
    $search = trim($search);
    // الرقم يُحقن كعدد صحيح فقط — بعض إصدارات MySQL/PDO لا تربط INTERVAL ? بشكل موثوق.
    $windowSec = (int) ($includeStale ? $staleSec : $onlineSec);

    $sql = 'SELECT ul.user_id, ul.latitude, ul.longitude, ul.gps_accuracy, ul.gps_source, ul.captured_at,
                   u.username, u.full_name_ar,
                   TIMESTAMPDIFF(SECOND, ul.captured_at, NOW()) AS age_sec
            FROM sys_user_location ul
            INNER JOIN sys_user u ON u.id = ul.user_id AND u.is_active = 1
            WHERE ul.captured_at >= DATE_SUB(NOW(), INTERVAL ' . $windowSec . ' SECOND)';
    $params = [];

    if ($search !== '') {
        $sql .= ' AND (u.username LIKE ? OR u.full_name_ar LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
    }

    $sql .= ' ORDER BY ul.captured_at DESC LIMIT 500';

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];

    foreach ($rows as $row) {
        $lat = (float) ($row['latitude'] ?? 0);
        $lng = (float) ($row['longitude'] ?? 0);
        if (!sal_invoice_gps_coords_valid($lat, $lng)) {
            continue;
        }

        $capturedAt = (string) ($row['captured_at'] ?? '');
        $ts = $capturedAt !== '' ? strtotime($capturedAt) : false;
        // عمر النبضة من MySQL فقط — لا نعتمد على PHP time()/strtotime (فرق المنطقة الزمنية
        // كان يستبعد نقاطاً حديثة بينما التشخيص يقول «الآن»).
        $ageSec = max(0, (int) ($row['age_sec'] ?? 0));
        $isOnline = $ageSec <= $onlineSec;

        if (!$includeStale && !$isOnline) {
            continue;
        }

        $rawSrc = isset($row['gps_source']) ? trim((string) $row['gps_source']) : '';
        $userLabel = sal_invoice_user_display_name(
            (string) ($row['full_name_ar'] ?? ''),
            (string) ($row['username'] ?? '')
        );

        // حالتان فقط للواجهة: متصل / غير متصل.
        $status = $isOnline ? 'online' : 'offline';
        $statusLabel = $isOnline ? 'متصل' : 'غير متصل';

        $out[] = [
            'user_id' => (int) ($row['user_id'] ?? 0),
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
            'status' => $status,
            'status_label' => $statusLabel,
            'map_url' => sal_invoice_gps_map_url($lat, $lng),
        ];
    }

    return $out;
}

/**
 * آخر مواقع محفوظة (للتشخيص على شاشة التتبّع) — بدون فلتر زمني ضيق.
 *
 * @return list<array<string,mixed>>
 */
function sys_user_location_recent_snapshots(PDO $pdo, int $limit = 8): array
{
    sys_user_location_ensure_schema($pdo);
    $limit = max(1, min(30, $limit));
    $st = $pdo->query(
        'SELECT ul.user_id, ul.captured_at, ul.gps_source, ul.latitude, ul.longitude,
                u.username, u.full_name_ar,
                TIMESTAMPDIFF(SECOND, ul.captured_at, NOW()) AS age_sec
         FROM sys_user_location ul
         INNER JOIN sys_user u ON u.id = ul.user_id AND u.is_active = 1
         ORDER BY ul.captured_at DESC
         LIMIT ' . $limit
    );
    $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    $out = [];
    foreach ($rows as $row) {
        $age = (int) ($row['age_sec'] ?? 0);
        $lat = isset($row['latitude']) ? (float) $row['latitude'] : 0.0;
        $lng = isset($row['longitude']) ? (float) $row['longitude'] : 0.0;
        $out[] = [
            'user_id' => (int) ($row['user_id'] ?? 0),
            'user_label' => sal_invoice_user_display_name(
                (string) ($row['full_name_ar'] ?? ''),
                (string) ($row['username'] ?? '')
            ),
            'captured_at' => (string) ($row['captured_at'] ?? ''),
            'age_sec' => $age,
            'age_label' => sys_user_location_age_label($age),
            'gps_source' => (string) ($row['gps_source'] ?? ''),
            'latitude' => $lat,
            'longitude' => $lng,
            'coords_valid' => sal_invoice_gps_coords_valid($lat, $lng),
        ];
    }

    return $out;
}

function sys_user_location_age_label(int $ageSec): string
{
    if ($ageSec < 15) {
        return 'الآن';
    }
    if ($ageSec < 60) {
        return 'قبل ' . $ageSec . ' ث';
    }
    if ($ageSec < 3600) {
        $m = (int) floor($ageSec / 60);

        return $m === 1 ? 'قبل دقيقة' : ('قبل ' . $m . ' دقيقة');
    }
    if ($ageSec < 86400) {
        $h = (int) floor($ageSec / 3600);

        return $h === 1 ? 'قبل ساعة' : ('قبل ' . $h . ' ساعة');
    }
    $d = (int) floor($ageSec / 86400);

    return $d === 1 ? 'قبل يوم' : ('قبل ' . $d . ' يوم');
}

/**
 * قائمة المستخدمين الذين لديهم مواقع/مسارات مسجّلة (لمنتقي المندوب في شاشة المسار).
 *
 * @return list<array{user_id:int, user_label:string, username:string}>
 */
function sys_user_location_track_users(PDO $pdo): array
{
    sys_user_location_ensure_schema($pdo);

    try {
        $sql = 'SELECT u.id AS user_id, u.username, u.full_name_ar
                FROM sys_user u
                WHERE u.is_active = 1
                  AND (
                    EXISTS (SELECT 1 FROM sys_user_location ul WHERE ul.user_id = u.id)
                    OR EXISTS (SELECT 1 FROM sys_user_location_track t WHERE t.user_id = u.id)
                  )
                ORDER BY u.full_name_ar, u.username';
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('sys_user_location_track_users: ' . $e->getMessage());

        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'user_id' => (int) ($row['user_id'] ?? 0),
            'user_label' => sal_invoice_user_display_name(
                (string) ($row['full_name_ar'] ?? ''),
                (string) ($row['username'] ?? '')
            ),
            'username' => (string) ($row['username'] ?? ''),
        ];
    }

    return $out;
}

/**
 * صياغة مدة بالثواني إلى نص عربي مختصر (٥ د، ١ س ٢٠ د).
 */
function sys_user_location_duration_label(int $seconds): string
{
    $seconds = max(0, $seconds);
    if ($seconds < 60) {
        return $seconds . ' ث';
    }
    $minutes = (int) round($seconds / 60);
    if ($minutes < 60) {
        return $minutes . ' د';
    }
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;

    return $m > 0 ? ($h . ' س ' . $m . ' د') : ($h . ' س');
}

/**
 * إزالة النقاط المتكررة/القريبة جداً من مسار الخريطة.
 *
 * @param list<array{latitude:float|int, longitude:float|int}> $path
 * @return list<array{latitude:float, longitude:float}>
 */
function sys_user_location_track_dedupe_path(array $path, float $minMeters = 8.0): array
{
    $out = [];
    $prevLat = null;
    $prevLng = null;
    foreach ($path as $pt) {
        if (!is_array($pt)) {
            continue;
        }
        $lat = (float) ($pt['latitude'] ?? 0);
        $lng = (float) ($pt['longitude'] ?? 0);
        if (!sal_invoice_gps_coords_valid($lat, $lng)) {
            continue;
        }
        if (
            $prevLat !== null
            && sys_user_location_distance_meters($prevLat, $prevLng, $lat, $lng) < $minMeters
        ) {
            // احتفظ بآخر نقطة في نفس المكان تقريباً (أدق زمنياً) دون إطالة الخط.
            if ($out !== []) {
                $out[count($out) - 1] = ['latitude' => $lat, 'longitude' => $lng];
            }
            $prevLat = $lat;
            $prevLng = $lng;
            continue;
        }
        $out[] = ['latitude' => $lat, 'longitude' => $lng];
        $prevLat = $lat;
        $prevLng = $lng;
    }

    return $out;
}

/**
 * تبسيط مسار Douglas–Peucker لإزالة تعرّجات GPS دون فقدان شكل الطريق.
 *
 * @param list<array{latitude:float, longitude:float}> $path
 * @return list<array{latitude:float, longitude:float}>
 */
function sys_user_location_track_simplify_path(array $path, float $toleranceMeters = 12.0): array
{
    $n = count($path);
    if ($n < 3) {
        return $path;
    }

    $keep = array_fill(0, $n, false);
    $keep[0] = true;
    $keep[$n - 1] = true;
    $stack = [[0, $n - 1]];

    while ($stack !== []) {
        [$start, $end] = array_pop($stack);
        if ($end <= $start + 1) {
            continue;
        }
        $maxDist = -1.0;
        $index = $start;
        $a = $path[$start];
        $b = $path[$end];
        for ($i = $start + 1; $i < $end; $i++) {
            $d = sys_user_location_point_to_segment_meters(
                (float) $path[$i]['latitude'],
                (float) $path[$i]['longitude'],
                (float) $a['latitude'],
                (float) $a['longitude'],
                (float) $b['latitude'],
                (float) $b['longitude']
            );
            if ($d > $maxDist) {
                $maxDist = $d;
                $index = $i;
            }
        }
        if ($maxDist > $toleranceMeters) {
            $keep[$index] = true;
            $stack[] = [$start, $index];
            $stack[] = [$index, $end];
        }
    }

    $out = [];
    for ($i = 0; $i < $n; $i++) {
        if (!empty($keep[$i])) {
            $out[] = $path[$i];
        }
    }

    return $out;
}

/** المسافة العمودية لنقطة عن قطعة مستقيمة (بالمتر تقريباً). */
function sys_user_location_point_to_segment_meters(
    float $lat,
    float $lng,
    float $lat1,
    float $lng1,
    float $lat2,
    float $lng2
): float {
    $x = deg2rad($lng);
    $y = deg2rad($lat);
    $x1 = deg2rad($lng1);
    $y1 = deg2rad($lat1);
    $x2 = deg2rad($lng2);
    $y2 = deg2rad($lat2);
    $dx = $x2 - $x1;
    $dy = $y2 - $y1;
    if (abs($dx) < 1e-12 && abs($dy) < 1e-12) {
        return sys_user_location_distance_meters($lat, $lng, $lat1, $lng1);
    }
    $t = (($x - $x1) * $dx + ($y - $y1) * $dy) / ($dx * $dx + $dy * $dy);
    $t = max(0.0, min(1.0, $t));
    $projLat = rad2deg($y1 + $t * $dy);
    $projLng = rad2deg($x1 + $t * $dx);

    return sys_user_location_distance_meters($lat, $lng, $projLat, $projLng);
}

/**
 * تنظيف نقاط GPS قبل الرسم: دقة ضعيفة، نتوءات، تذبذب حول نفس المكان.
 *
 * @param list<array<string,mixed>> $points
 * @return list<array<string,mixed>>
 */
function sys_user_location_track_clean_points(array $points): array
{
    $n = count($points);
    if ($n < 3) {
        return $points;
    }

    $filtered = [];
    foreach ($points as $pt) {
        $acc = $pt['gps_accuracy'] ?? null;
        // تجاهل النقاط ذات الدقة السيئة جداً (تسبب خطوطاً مزدوجة بجانب الشارع).
        if ($acc !== null && (float) $acc > 80) {
            continue;
        }
        $filtered[] = $pt;
    }
    if (count($filtered) < 2) {
        $filtered = $points;
    }

    // إزالة نتوءات: A→B→C حيث B بعيدة عن مسار A–C ثم تعود.
    $clean = [$filtered[0]];
    $m = count($filtered);
    for ($i = 1; $i < $m - 1; $i++) {
        $prev = $clean[count($clean) - 1];
        $cur = $filtered[$i];
        $next = $filtered[$i + 1];
        $dPrev = sys_user_location_distance_meters(
            (float) $prev['latitude'],
            (float) $prev['longitude'],
            (float) $cur['latitude'],
            (float) $cur['longitude']
        );
        $dNext = sys_user_location_distance_meters(
            (float) $cur['latitude'],
            (float) $cur['longitude'],
            (float) $next['latitude'],
            (float) $next['longitude']
        );
        $dDirect = sys_user_location_distance_meters(
            (float) $prev['latitude'],
            (float) $prev['longitude'],
            (float) $next['latitude'],
            (float) $next['longitude']
        );
        $gap = max(1, (int) ($cur['ts'] ?? 0) - (int) ($prev['ts'] ?? 0));
        // نتوء قصير: ابتعاد ثم عودة بسرعة غير منطقية.
        if ($dPrev > 40 && $dNext > 40 && $dDirect < ($dPrev * 0.45) && $gap <= 45) {
            continue;
        }
        $clean[] = $cur;
    }
    $clean[] = $filtered[$m - 1];

    return $clean;
}

/**
 * تجهيز خط سير واحد نظيف للرسم.
 *
 * @param list<array{latitude:float|int, longitude:float|int}> $path
 * @return list<array{latitude:float, longitude:float}>
 */
function sys_user_location_track_prepare_line(array $path): array
{
    $deduped = sys_user_location_track_dedupe_path($path, 8.0);
    if (count($deduped) < 2) {
        return [];
    }
    $simplified = sys_user_location_track_simplify_path($deduped, 10.0);
    if (count($simplified) < 2) {
        return $deduped;
    }

    return sys_user_location_track_dedupe_path($simplified, 5.0);
}

/**
 * خطوط جاهزة للرسم على الخريطة (مقطع أو أكثر) — خط واحد لكل مقطع بلا تكرار.
 *
 * @param list<list<array{latitude:float, longitude:float}>> $roadPaths
 * @param list<list<int>> $segments
 * @param list<array<string,mixed>> $points
 * @return list<list<array{latitude:float, longitude:float}>>
 */
function sys_user_location_track_lines(array $roadPaths, array $segments, array $points): array
{
    $lines = [];
    foreach ($roadPaths as $path) {
        if (!is_array($path)) {
            continue;
        }
        $prepared = sys_user_location_track_prepare_line($path);
        if (count($prepared) >= 2) {
            $lines[] = $prepared;
        }
    }
    if ($lines !== []) {
        return $lines;
    }

    foreach ($segments as $seg) {
        if (!is_array($seg) || count($seg) < 2) {
            continue;
        }
        $line = [];
        foreach ($seg as $idx) {
            $i = (int) $idx;
            if (!isset($points[$i])) {
                continue;
            }
            $line[] = [
                'latitude' => (float) ($points[$i]['latitude'] ?? 0),
                'longitude' => (float) ($points[$i]['longitude'] ?? 0),
            ];
        }
        $prepared = sys_user_location_track_prepare_line($line);
        if (count($prepared) >= 2) {
            $lines[] = $prepared;
        }
    }

    return $lines;
}

function sys_user_location_track_day(
    PDO $pdo,
    int $userId,
    string $dateIso,
    int $gapBreakMinutes = 30,
    int $stopRadiusMeters = 70,
    int $stopMinMinutes = 5
): array {
    sys_user_location_track_ensure_schema($pdo);

    $empty = [
        'points' => [],
        'segments' => [],
        'stops' => [],
        'summary' => [
            'points_count' => 0,
            'distance_km' => 0.0,
            'distance_label' => '0 كم',
            'first_time' => '',
            'last_time' => '',
            'active_label' => '',
            'stops_count' => 0,
            'road_matched' => false,
            'travel_segments' => 0,
            'presence_count' => 0,
        ],
        'road_path' => [],
        'road_paths' => [],
        'road_matched' => false,
        'track_lines' => [],
        'presence' => [],
    ];

    $ts = strtotime($dateIso);
    if ($userId < 1 || $ts === false) {
        return $empty;
    }
    $date = date('Y-m-d', $ts);

    $st = $pdo->prepare(
        'SELECT latitude, longitude, gps_accuracy, gps_source, captured_at,
                DATE_FORMAT(captured_at, \'%H:%i\') AS time,
                DATE_FORMAT(captured_at, \'%H:%i:%s\') AS time_full
         FROM sys_user_location_track
         WHERE user_id = ? AND DATE(captured_at) = ?
         ORDER BY captured_at ASC, id ASC'
    );
    $st->execute([$userId, $date]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($rows === []) {
        return $empty;
    }

    $points = [];
    foreach ($rows as $row) {
        $lat = (float) ($row['latitude'] ?? 0);
        $lng = (float) ($row['longitude'] ?? 0);
        if (!sal_invoice_gps_coords_valid($lat, $lng)) {
            continue;
        }
        $capturedAt = (string) ($row['captured_at'] ?? '');
        $pts = $capturedAt !== '' ? strtotime($capturedAt) : false;
        if ($pts === false) {
            continue;
        }
        $rawSrc = isset($row['gps_source']) ? trim((string) $row['gps_source']) : '';
        $points[] = [
            'latitude' => $lat,
            'longitude' => $lng,
            'gps_accuracy' => isset($row['gps_accuracy']) && $row['gps_accuracy'] !== null && $row['gps_accuracy'] !== ''
                ? (float) $row['gps_accuracy']
                : null,
            'accuracy_label' => !empty($row['gps_accuracy']) ? round((float) $row['gps_accuracy']) . ' م' : '',
            'gps_source' => $rawSrc !== '' ? $rawSrc : null,
            'source_label' => sal_invoice_gps_source_label($rawSrc !== '' ? $rawSrc : null),
            'captured_at' => $capturedAt,
            'ts' => $pts,
            'time' => trim((string) ($row['time'] ?? '')) !== ''
                ? trim((string) $row['time'])
                : app_format_time_hi($capturedAt),
            'time_full' => trim((string) ($row['time_full'] ?? '')) !== ''
                ? trim((string) $row['time_full'])
                : app_format_time_his($capturedAt),
        ];
    }

    $n = count($points);
    if ($n === 0) {
        return $empty;
    }

    $points = sys_user_location_track_clean_points($points);
    $n = count($points);
    if ($n === 0) {
        return $empty;
    }

    // مقاطع حركة متصلة: نقطع الخط فقط عند قفزة غير منطقية أو صمت طويل مع انتقال بعيد.
    $gapBreakSec = max(60, $gapBreakMinutes * 60);
    $maxSpeedMps = 120.0 / 3.6;
    $totalMeters = 0.0;
    $segments = [];
    $current = [0];
    for ($i = 1; $i < $n; $i++) {
        $prev = $points[$i - 1];
        $cur = $points[$i];
        $gap = max(0, $cur['ts'] - $prev['ts']);
        $d = sys_user_location_distance_meters(
            $prev['latitude'],
            $prev['longitude'],
            $cur['latitude'],
            $cur['longitude']
        );

        // قفزة مستحيلة السرعة، أو صمت طويل مع انتقال واضح (فتح من مكان آخر).
        $impossibleSpeed = $gap > 0 && ($d / $gap) > $maxSpeedMps && $d > 250;
        $remoteReopen = $gap > $gapBreakSec && $d > 180;

        if ($impossibleSpeed || $remoteReopen) {
            if ($current !== []) {
                $segments[] = $current;
            }
            $current = [$i];
            continue;
        }

        $totalMeters += $d;
        $current[] = $i;
    }
    if ($current !== []) {
        $segments[] = $current;
    }

    $stopMinSec = max(1, $stopMinMinutes) * 60;
    $stops = [];
    foreach ($segments as $seg) {
        $segN = count($seg);
        if ($segN < 2) {
            continue;
        }
        $si = 0;
        while ($si < $segN) {
            $sj = $si;
            $anchor = $points[$seg[$si]];
            while (
                $sj + 1 < $segN
                && sys_user_location_distance_meters(
                    $anchor['latitude'],
                    $anchor['longitude'],
                    $points[$seg[$sj + 1]]['latitude'],
                    $points[$seg[$sj + 1]]['longitude']
                ) <= $stopRadiusMeters
            ) {
                $sj++;
            }
            $i0 = $seg[$si];
            $i1 = $seg[$sj];
            $duration = $points[$i1]['ts'] - $points[$i0]['ts'];
            if ($sj > $si && $duration >= $stopMinSec) {
                $sumLat = 0.0;
                $sumLng = 0.0;
                for ($k = $si; $k <= $sj; $k++) {
                    $sumLat += $points[$seg[$k]]['latitude'];
                    $sumLng += $points[$seg[$k]]['longitude'];
                }
                $cnt = $sj - $si + 1;
                $stops[] = [
                    'latitude' => round($sumLat / $cnt, 7),
                    'longitude' => round($sumLng / $cnt, 7),
                    'arrive' => $points[$i0]['time'],
                    'leave' => $points[$i1]['time'],
                    'arrive_ts' => $points[$i0]['ts'],
                    'duration_sec' => $duration,
                    'duration_label' => sys_user_location_duration_label($duration),
                    'points' => $cnt,
                ];
                $si = $sj + 1;
            } else {
                $si++;
            }
        }
    }

    require_once app_path('includes/app_osm.php');
    $presence = [];
    $roadPaths = [];
    $minTravelMeters = 3.0;

    foreach ($segments as $seg) {
        $segPoints = [];
        foreach ($seg as $idx) {
            $segPoints[] = $points[$idx];
        }
        $segCount = count($segPoints);
        if ($segCount < 1) {
            continue;
        }

        $segMeters = 0.0;
        for ($i = 1; $i < $segCount; $i++) {
            $segMeters += sys_user_location_distance_meters(
                $segPoints[$i - 1]['latitude'],
                $segPoints[$i - 1]['longitude'],
                $segPoints[$i]['latitude'],
                $segPoints[$i]['longitude']
            );
        }

        $p0 = $segPoints[0];
        $p1 = $segPoints[$segCount - 1];

        // نقطة واحدة أو مكث بلا انتقال يُذكر ⇒ علامة تواجد (مع الإبقاء على خط قصير إن وُجدت نقطتان+).
        if ($segCount < 2 || $segMeters < $minTravelMeters) {
            $presence[] = [
                'latitude' => round((float) $p0['latitude'], 7),
                'longitude' => round((float) $p0['longitude'], 7),
                'time' => (string) $p0['time'],
                'time_to' => (string) $p1['time'],
                'points' => $segCount,
                'label' => $segCount > 1
                    ? ('تواجد ' . $p0['time'] . ' — ' . $p1['time'])
                    : ('تواجد ' . $p0['time']),
            ];
        }

        if ($segCount < 2) {
            continue;
        }

        // دائماً: خط سير من نقاط GPS المنظّفة (يظهر حتى لو كان قصيراً).
        $gpsPath = [];
        foreach ($segPoints as $sp) {
            $gpsPath[] = [
                'latitude' => (float) $sp['latitude'],
                'longitude' => (float) $sp['longitude'],
            ];
        }
        $gpsPath = sys_user_location_track_prepare_line($gpsPath);

        $path = [];
        if ($segMeters >= $minTravelMeters && count($gpsPath) >= 2) {
            try {
                $path = app_osm_snap_route_to_roads($segPoints);
                if (count($path) >= 2) {
                    $path = sys_user_location_track_prepare_line($path);
                }
            } catch (Throwable $e) {
                error_log('sys_user_location_track_day road snap: ' . $e->getMessage());
                $path = [];
            }
        }
        // مصدر واحد فقط لكل مقطع: شارع ملتصق أو GPS منظّف — لا نرسم الاثنين معاً.
        if (count($path) >= 2) {
            $roadPaths[] = $path;
        } elseif (count($gpsPath) >= 2) {
            $roadPaths[] = $gpsPath;
        }
    }

    $roadMatched = $roadPaths !== [];
    $roadPathFlat = $roadMatched ? $roadPaths[0] : [];
    $trackLines = sys_user_location_track_lines($roadPaths, $segments, $points);
    $km = $totalMeters / 1000.0;
    $activeSec = $points[$n - 1]['ts'] - $points[0]['ts'];

    return [
        'points' => $points,
        'segments' => $segments,
        'stops' => $stops,
        'presence' => $presence,
        'road_path' => $roadPathFlat,
        'road_paths' => $roadPaths,
        'road_matched' => $roadMatched,
        'track_lines' => $trackLines,
        'summary' => [
            'points_count' => $n,
            'distance_km' => round($km, 2),
            'distance_label' => $km >= 1
                ? (number_format($km, 1) . ' كم')
                : (round($totalMeters) . ' م'),
            'first_time' => $points[0]['time'],
            'last_time' => $points[$n - 1]['time'],
            'active_label' => sys_user_location_duration_label($activeSec),
            'stops_count' => count($stops),
            'road_matched' => $roadMatched,
            'travel_segments' => count($trackLines),
            'presence_count' => count($presence),
        ],
    ];
}


function sys_user_location_latest_for_user(PDO $pdo, int $userId, int $maxAgeSec = 3600): ?array
{
    if ($userId < 1 || $maxAgeSec < 1) {
        return null;
    }

    sys_user_location_ensure_schema($pdo);

    $st = $pdo->prepare(
        'SELECT latitude, longitude, gps_accuracy, gps_source, captured_at
         FROM sys_user_location
         WHERE user_id = ?
         LIMIT 1'
    );
    $st->execute([$userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $capturedAt = (string) ($row['captured_at'] ?? '');
    $ts = $capturedAt !== '' ? strtotime($capturedAt) : false;
    if ($ts === false || (time() - $ts) > $maxAgeSec) {
        return null;
    }

    $lat = (float) ($row['latitude'] ?? 0);
    $lng = (float) ($row['longitude'] ?? 0);
    if (!sal_invoice_gps_coords_valid($lat, $lng)) {
        return null;
    }

    $accuracy = isset($row['gps_accuracy']) && $row['gps_accuracy'] !== null && $row['gps_accuracy'] !== ''
        ? (float) $row['gps_accuracy']
        : null;

    return [
        'latitude' => $lat,
        'longitude' => $lng,
        'gps_accuracy' => $accuracy,
        'gps_source' => sal_invoice_gps_normalize_source((string) ($row['gps_source'] ?? 'mobile')),
        'captured_at' => $capturedAt,
    ];
}

function sys_user_location_distance_meters(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $earthRadius = 6371000.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadius * $c;
}

/**
 * لا نستبدل موقعاً دقيقاً سابقاً بقراءة أضعف بكثير إن لم يتحرك المستخدم.
 */
function sys_user_location_should_skip_worse_reading(
    ?float $prevLat,
    ?float $prevLng,
    ?float $prevAccuracy,
    float $newLat,
    float $newLng,
    ?float $newAccuracy
): bool {
    if ($prevAccuracy === null || $newAccuracy === null) {
        return false;
    }
    if ($prevLat === null || $prevLng === null) {
        return false;
    }
    if ($prevAccuracy > 55.0 || $newAccuracy <= $prevAccuracy) {
        return false;
    }

    $dist = sys_user_location_distance_meters($prevLat, $prevLng, $newLat, $newLng);
    if ($dist > 120.0) {
        return false;
    }

    return $newAccuracy >= ($prevAccuracy * 1.35 + 8.0);
}

/**
 * @return array{ok:bool, skipped:bool, error:?string}
 */
function sys_user_location_save_ping(
    PDO $pdo,
    int $userId,
    float $lat,
    float $lng,
    ?float $accuracy = null,
    string $source = 'desktop'
): array {
    if (!app_gps_enabled()) {
        return ['ok' => true, 'skipped' => true, 'error' => null];
    }
    sys_user_location_ensure_schema($pdo);

    if ($userId < 1 || !sal_invoice_gps_coords_valid($lat, $lng)) {
        return ['ok' => false, 'skipped' => false, 'error' => 'invalid'];
    }

    $source = sal_invoice_gps_normalize_source($source);
    $nativeChannel = isset($_POST['gps_channel']) && (string) $_POST['gps_channel'] === 'native_app';
    $minInterval = sys_user_location_min_interval_sec($source, $nativeChannel);

    $st = $pdo->prepare(
        'SELECT captured_at, latitude, longitude, gps_accuracy FROM sys_user_location WHERE user_id = ? LIMIT 1'
    );
    $st->execute([$userId]);
    $prevRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($prevRow) {
        $prev = $prevRow['captured_at'] ?? null;
        if ($prev !== false && $prev !== null && $prev !== '') {
            $prevTs = strtotime((string) $prev);
            if ($prevTs !== false && (time() - $prevTs) < $minInterval) {
                // نبضة حضور: نُحدّث الوقت فقط حتى يبقى «متصل الآن» على الخريطة.
                $now = sys_user_location_now_sql();
                $pdo->prepare('UPDATE sys_user_location SET captured_at = ? WHERE user_id = ?')
                    ->execute([$now, $userId]);

                return ['ok' => true, 'skipped' => true, 'error' => null];
            }
        }

        $prevAcc = isset($prevRow['gps_accuracy']) && $prevRow['gps_accuracy'] !== null && $prevRow['gps_accuracy'] !== ''
            ? (float) $prevRow['gps_accuracy']
            : null;
        if (sys_user_location_should_skip_worse_reading(
            isset($prevRow['latitude']) ? (float) $prevRow['latitude'] : null,
            isset($prevRow['longitude']) ? (float) $prevRow['longitude'] : null,
            $prevAcc,
            $lat,
            $lng,
            $accuracy
        )) {
            // نُبقي الإحداثيات الأدق، لكن نُحدّث الوقت حتى يبقى المستخدم «متصل الآن».
            $now = sys_user_location_now_sql();
            $pdo->prepare('UPDATE sys_user_location SET captured_at = ? WHERE user_id = ?')
                ->execute([$now, $userId]);

            return ['ok' => true, 'skipped' => true, 'error' => null];
        }
    }

    $hasPlace = sys_user_location_has_place_column($pdo);
    $hasLandmark = sys_user_location_has_landmark_column($pdo);

    // لا جيوكود هنا — كان يُجمّد السيرفر عند كل جهاز على الشبكة (حتى 18 ثانية).
    // أسماء المنطقة/المعلم تُملأ لاحقاً عند فتح شاشة مواقع المستخدمين فقط.
    $now = sys_user_location_now_sql();
    if ($hasPlace || $hasLandmark) {
        $pdo->prepare(
            'INSERT INTO sys_user_location (user_id, latitude, longitude, gps_accuracy, gps_source, gps_place, gps_landmark, captured_at)
             VALUES (?, ?, ?, ?, ?, NULL, NULL, ?)
             ON DUPLICATE KEY UPDATE
                latitude = VALUES(latitude),
                longitude = VALUES(longitude),
                gps_accuracy = VALUES(gps_accuracy),
                gps_source = VALUES(gps_source),
                gps_place = NULL,
                gps_landmark = NULL,
                captured_at = VALUES(captured_at)'
        )->execute([
            $userId,
            round($lat, 7),
            round($lng, 7),
            $accuracy !== null && is_finite($accuracy) ? round($accuracy, 2) : null,
            $source,
            $now,
        ]);
    } else {
        $pdo->prepare(
            'INSERT INTO sys_user_location (user_id, latitude, longitude, gps_accuracy, gps_source, captured_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                latitude = VALUES(latitude),
                longitude = VALUES(longitude),
                gps_accuracy = VALUES(gps_accuracy),
                gps_source = VALUES(gps_source),
                captured_at = VALUES(captured_at)'
        )->execute([
            $userId,
            round($lat, 7),
            round($lng, 7),
            $accuracy !== null && is_finite($accuracy) ? round($accuracy, 2) : null,
            $source,
            $now,
        ]);
    }

    // نقطة في تاريخ خط السير (لا تُفشل الـ ping إن تعذّر التسجيل).
    sys_user_location_record_track_point($pdo, $userId, $lat, $lng, $accuracy, $source);

    return ['ok' => true, 'skipped' => false, 'error' => null];
}

/** يُدرج نقطة في تاريخ خط السير + تنظيف احتمالي للنقاط القديمة. */
function sys_user_location_record_track_point(
    PDO $pdo,
    int $userId,
    float $lat,
    float $lng,
    ?float $accuracy,
    string $source
): void {
    try {
        sys_user_location_track_ensure_schema($pdo);
        $now = sys_user_location_now_sql();
        $pdo->prepare(
            'INSERT INTO sys_user_location_track (user_id, latitude, longitude, gps_accuracy, gps_source, captured_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId,
            round($lat, 7),
            round($lng, 7),
            $accuracy !== null && is_finite($accuracy) ? round($accuracy, 2) : null,
            $source,
            $now,
        ]);
    } catch (Throwable $e) {
        error_log('sys_user_location_record_track_point: ' . $e->getMessage());

        return;
    }

    // تنظيف احتمالي (~1%) للنقاط الأقدم من مدة الاحتفاظ.
    if (mt_rand(1, 100) === 1) {
        sys_user_location_track_cleanup($pdo);
    }
}

/** يحذف نقاط خط السير الأقدم من مدة الاحتفاظ. */
function sys_user_location_track_cleanup(PDO $pdo, int $retentionDays = 60): void
{
    $retentionDays = max(7, min(365, $retentionDays));
    try {
        $pdo->prepare(
            'DELETE FROM sys_user_location_track WHERE captured_at < DATE_SUB(NOW(), INTERVAL ? DAY)'
        )->execute([$retentionDays]);
    } catch (Throwable $e) {
        error_log('sys_user_location_track_cleanup: ' . $e->getMessage());
    }
}

/**
 * @return list<array<string, mixed>>
 */
function sys_user_location_list_rows(
    PDO $pdo,
    string $search = '',
    int $limit = 300,
    ?string $dateFrom = null,
    ?string $dateTo = null
): array {
    sys_user_location_ensure_schema($pdo);

    $limit = max(1, min(500, $limit));
    $search = trim($search);
    $dateFrom = $dateFrom !== null && $dateFrom !== '' ? $dateFrom : null;
    $dateTo = $dateTo !== null && $dateTo !== '' ? $dateTo : null;

    $hasPlace = sys_user_location_has_place_column($pdo);
    $hasLandmark = sys_user_location_has_landmark_column($pdo);
    $placeSelect = $hasPlace ? 'ul.gps_place' : 'NULL AS gps_place';
    $landmarkSelect = $hasLandmark ? 'ul.gps_landmark' : 'NULL AS gps_landmark';

    $sql = "SELECT ul.user_id, ul.latitude, ul.longitude, ul.gps_accuracy, ul.gps_source, {$placeSelect}, {$landmarkSelect}, ul.captured_at,
                   u.username, u.full_name_ar
            FROM sys_user_location ul
            INNER JOIN sys_user u ON u.id = ul.user_id AND u.is_active = 1
            WHERE 1=1";
    $params = [];

    if ($search !== '') {
        $sql .= ' AND (u.username LIKE ? OR u.full_name_ar LIKE ?';
        $like = '%' . $search . '%';
        $params = [$like, $like];
        if ($hasPlace) {
            $sql .= ' OR ul.gps_place LIKE ?';
            $params[] = $like;
        }
        if ($hasLandmark) {
            $sql .= ' OR ul.gps_landmark LIKE ?';
            $params[] = $like;
        }
        $sql .= ')';
    }

    if ($dateFrom !== null && $dateTo !== null) {
        $sql .= ' AND DATE(ul.captured_at) >= ? AND DATE(ul.captured_at) <= ?';
        $params[] = $dateFrom;
        $params[] = $dateTo;
    }

    $sql .= ' ORDER BY ul.captured_at DESC LIMIT ' . (int) $limit;

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $locationFillBudget = 5;

    foreach ($rows as &$row) {
        $lat = (float) ($row['latitude'] ?? 0);
        $lng = (float) ($row['longitude'] ?? 0);
        $row['user_label'] = sal_invoice_user_display_name(
            (string) ($row['full_name_ar'] ?? ''),
            (string) ($row['username'] ?? '')
        );
        $ts = !empty($row['captured_at']) ? strtotime((string) $row['captured_at']) : false;
        $row['captured_at_dmy'] = $ts !== false ? date('d-m-Y H:i', $ts) : '';
        $row['map_url'] = sal_invoice_gps_map_url($lat, $lng);
        $row['map_embed_url'] = sal_invoice_gps_embed_url($lat, $lng);
        $row['latitude'] = $lat;
        $row['longitude'] = $lng;
        $rawSrc = isset($row['gps_source']) ? trim((string) $row['gps_source']) : '';
        $row['gps_source'] = $rawSrc !== '' ? $rawSrc : null;
        $row['source_label'] = sal_invoice_gps_source_label($rawSrc !== '' ? $rawSrc : null);
        if ($rawSrc === 'mobile') {
            $row['source_badge_class'] = 'sal-gps-src--mobile';
        } elseif ($rawSrc === 'desktop') {
            $row['source_badge_class'] = 'sal-gps-src--desktop';
        } else {
            $row['source_badge_class'] = 'sal-gps-src--unknown';
        }
        if (!empty($row['gps_accuracy'])) {
            $row['accuracy_label'] = round((float) $row['gps_accuracy']) . ' م';
        } else {
            $row['accuracy_label'] = '';
        }

        $row['gps_place'] = trim((string) ($row['gps_place'] ?? ''));
        $row['place_label'] = $row['gps_place'];
        $row['gps_landmark'] = trim((string) ($row['gps_landmark'] ?? ''));
        $row['landmark_label'] = $row['gps_landmark'];
        $needsFill = ($hasPlace && $row['gps_place'] === '') || ($hasLandmark && $row['gps_landmark'] === '');
        if ($needsFill && $locationFillBudget > 0) {
            $filled = sys_user_location_fill_location($pdo, (int) ($row['user_id'] ?? 0), $lat, $lng);
            if (!empty($filled['place'])) {
                $row['gps_place'] = (string) $filled['place'];
                $row['place_label'] = (string) $filled['place'];
            }
            if (!empty($filled['landmark'])) {
                $row['gps_landmark'] = (string) $filled['landmark'];
                $row['landmark_label'] = (string) $filled['landmark'];
            }
            if (!empty($filled['place']) || !empty($filled['landmark'])) {
                $locationFillBudget--;
            }
        }
    }
    unset($row);

    return $rows;
}

/**
 * @param array<string, mixed> $row
 */
function sys_user_location_map_button_html(array $row): string
{
    $lat = (string) ($row['latitude'] ?? '');
    $lng = (string) ($row['longitude'] ?? '');
    $userId = (int) ($row['user_id'] ?? 0);
    $userLabel = (string) ($row['user_label'] ?? '');
    $place = (string) ($row['place_label'] ?? $row['gps_place'] ?? '');
    $landmark = (string) ($row['landmark_label'] ?? $row['gps_landmark'] ?? '');
    $embed = (string) ($row['map_embed_url'] ?? '');
    $external = (string) ($row['map_url'] ?? '');

    return '<button type="button" class="sal-gps-icon-btn sal-gps-map-open"'
        . ' data-lat="' . esc($lat) . '"'
        . ' data-lng="' . esc($lng) . '"'
        . ' data-invoice-id=""'
        . ' data-invoice=""'
        . ' data-customer="' . esc($userLabel) . '"'
        . ' data-place="' . esc($place) . '"'
        . ' data-landmark="' . esc($landmark) . '"'
        . ' data-embed="' . esc($embed) . '"'
        . ' data-external="' . esc($external) . '"'
        . ' title="عرض موقع المستخدم على الخريطة">'
        . '<span class="sal-gps-icon-btn__glyph">' . sal_invoice_gps_icon_svg() . '</span>'
        . '</button>';
}
