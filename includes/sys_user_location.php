<?php
declare(strict_types=1);

require_once app_path('includes/sal_invoice_gps.php');

const SYS_USER_LOCATION_MIN_INTERVAL_SEC = 600;
const SYS_USER_LOCATION_MIN_INTERVAL_MOBILE_SEC = 120;

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
 * «متصل» = آخر تحديث خلال onlineMinutes ثانية.
 *
 * @return list<array<string, mixed>>
 */
function sys_user_location_tracker_rows(
    PDO $pdo,
    int $onlineMinutes = 15,
    int $staleMinutes = 120,
    string $search = '',
    bool $includeStale = true
): array {
    sys_user_location_ensure_schema($pdo);

    $onlineMinutes = max(1, min(120, $onlineMinutes));
    $staleMinutes = max($onlineMinutes, min(24 * 60, $staleMinutes));
    $search = trim($search);
    $onlineSec = $onlineMinutes * 60;
    $staleSec = $staleMinutes * 60;

    $sql = 'SELECT ul.user_id, ul.latitude, ul.longitude, ul.gps_accuracy, ul.gps_source, ul.captured_at,
                   u.username, u.full_name_ar
            FROM sys_user_location ul
            INNER JOIN sys_user u ON u.id = ul.user_id AND u.is_active = 1
            WHERE ul.captured_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)';
    $params = [$includeStale ? $staleSec : $onlineSec];

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
    $now = time();
    $out = [];

    foreach ($rows as $row) {
        $lat = (float) ($row['latitude'] ?? 0);
        $lng = (float) ($row['longitude'] ?? 0);
        if (!sal_invoice_gps_coords_valid($lat, $lng)) {
            continue;
        }

        $capturedAt = (string) ($row['captured_at'] ?? '');
        $ts = $capturedAt !== '' ? strtotime($capturedAt) : false;
        $ageSec = ($ts !== false) ? max(0, $now - $ts) : 999999;
        $isOnline = $ageSec <= $onlineSec;
        $isRecent = !$isOnline && $ageSec <= $staleSec;

        if (!$includeStale && !$isOnline) {
            continue;
        }

        $rawSrc = isset($row['gps_source']) ? trim((string) $row['gps_source']) : '';
        $userLabel = sal_invoice_user_display_name(
            (string) ($row['full_name_ar'] ?? ''),
            (string) ($row['username'] ?? '')
        );

        $status = $isOnline ? 'online' : ($isRecent ? 'away' : 'offline');
        $statusLabel = $isOnline ? 'متصل' : ($isRecent ? 'غير نشط' : 'غير متصل');

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

function sys_user_location_age_label(int $ageSec): string
{
    if ($ageSec < 60) {
        return 'الآن';
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
 * آخر موقع مسجّل للمستخدم إن كان حديثاً (للترحيل التلقائي عند فشل GPS اللحظي).
 *
 * @return array{latitude:float, longitude:float, gps_accuracy:?float, gps_source:string, captured_at:string}|null
 */
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
            return ['ok' => true, 'skipped' => true, 'error' => null];
        }
    }

    $hasPlace = sys_user_location_has_place_column($pdo);
    $hasLandmark = sys_user_location_has_landmark_column($pdo);

    // لا جيوكود هنا — كان يُجمّد السيرفر عند كل جهاز على الشبكة (حتى 18 ثانية).
    // أسماء المنطقة/المعلم تُملأ لاحقاً عند فتح شاشة مواقع المستخدمين فقط.
    if ($hasPlace || $hasLandmark) {
        $pdo->prepare(
            'INSERT INTO sys_user_location (user_id, latitude, longitude, gps_accuracy, gps_source, gps_place, gps_landmark, captured_at)
             VALUES (?, ?, ?, ?, ?, NULL, NULL, NOW())
             ON DUPLICATE KEY UPDATE
                latitude = VALUES(latitude),
                longitude = VALUES(longitude),
                gps_accuracy = VALUES(gps_accuracy),
                gps_source = VALUES(gps_source),
                gps_place = NULL,
                gps_landmark = NULL,
                captured_at = NOW()'
        )->execute([
            $userId,
            round($lat, 7),
            round($lng, 7),
            $accuracy !== null && is_finite($accuracy) ? round($accuracy, 2) : null,
            $source,
        ]);
    } else {
        $pdo->prepare(
            'INSERT INTO sys_user_location (user_id, latitude, longitude, gps_accuracy, gps_source, captured_at)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                latitude = VALUES(latitude),
                longitude = VALUES(longitude),
                gps_accuracy = VALUES(gps_accuracy),
                gps_source = VALUES(gps_source),
                captured_at = NOW()'
        )->execute([
            $userId,
            round($lat, 7),
            round($lng, 7),
            $accuracy !== null && is_finite($accuracy) ? round($accuracy, 2) : null,
            $source,
        ]);
    }

    return ['ok' => true, 'skipped' => false, 'error' => null];
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
