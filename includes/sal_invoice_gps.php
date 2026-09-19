<?php
declare(strict_types=1);

require_once app_path('includes/sal_invoice_schema.php');

function sal_invoice_gps_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    if (!sal_invoice_column_exists($pdo, 'sal_invoice', 'post_latitude')) {
        sal_invoice_run_migration_file($pdo, '122_sal_invoice_post_gps.sql');
    }
    if (!sal_invoice_column_exists($pdo, 'sal_invoice', 'post_gps_source')) {
        sal_invoice_run_migration_file($pdo, '124_sal_invoice_post_gps_source.sql');
    }
    if (!sal_invoice_column_exists($pdo, 'sal_invoice', 'post_gps_place')) {
        sal_invoice_run_migration_file($pdo, '125_sal_invoice_post_gps_place.sql');
    }
    if (!sal_invoice_column_exists($pdo, 'sal_invoice', 'post_gps_landmark')) {
        sal_invoice_run_migration_file($pdo, '126_sal_invoice_post_gps_landmark.sql');
        if (!sal_invoice_column_exists($pdo, 'sal_invoice', 'post_gps_landmark')) {
            try {
                $pdo->exec(
                    'ALTER TABLE sal_invoice ADD COLUMN post_gps_landmark VARCHAR(300) NULL DEFAULT NULL'
                );
            } catch (Throwable $e) {
                error_log('sal_invoice_gps_ensure_schema landmark: ' . $e->getMessage());
            }
        }
    }
    sal_invoice_run_migration_file($pdo, '132_sal_invoice_post_gps_manual_source.sql');
    if (!sal_invoice_column_exists($pdo, 'sal_invoice', 'posted_by')) {
        sal_invoice_run_migration_file($pdo, '127_sal_invoice_posted_by.sql');
        if (!sal_invoice_column_exists($pdo, 'sal_invoice', 'posted_by')) {
            try {
                $pdo->exec('ALTER TABLE sal_invoice ADD COLUMN posted_by INT UNSIGNED NULL DEFAULT NULL');
            } catch (Throwable $e) {
                error_log('sal_invoice_gps_ensure_schema posted_by: ' . $e->getMessage());
            }
        }
    }

    $done = true;
}

function sal_invoice_gps_has_posted_by_column(PDO $pdo): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    sal_invoice_gps_ensure_schema($pdo);
    $cached = sal_invoice_column_exists($pdo, 'sal_invoice', 'posted_by');

    return $cached;
}

function sal_invoice_user_display_name(?string $fullName, ?string $username): string
{
    $full = trim((string) $fullName);
    if ($full !== '') {
        return $full;
    }

    $user = trim((string) $username);

    return $user !== '' ? $user : '';
}

/** @param array<string, mixed> $row */
function sal_invoice_gps_apply_user_labels(array &$row): void
{
    $created = sal_invoice_user_display_name(
        isset($row['created_user_name']) ? (string) $row['created_user_name'] : '',
        isset($row['created_username']) ? (string) $row['created_username'] : ''
    );
    $posted = sal_invoice_user_display_name(
        isset($row['posted_user_name']) ? (string) $row['posted_user_name'] : '',
        isset($row['posted_username']) ? (string) $row['posted_username'] : ''
    );

    $row['created_user_label'] = $created;
    $row['posted_user_label'] = $posted;

    if ($posted !== '' && $created !== '' && $posted === $created) {
        $row['user_label'] = $posted;
        $row['user_label_sub'] = '';
    } elseif ($posted !== '' && $created !== '') {
        $row['user_label'] = $posted;
        $row['user_label_sub'] = 'إصدار: ' . $created;
    } elseif ($posted !== '') {
        $row['user_label'] = $posted;
        $row['user_label_sub'] = '';
    } elseif ($created !== '') {
        $row['user_label'] = $created;
        $row['user_label_sub'] = '';
    } else {
        $row['user_label'] = '';
        $row['user_label_sub'] = '';
    }
}

function sal_invoice_gps_has_landmark_column(PDO $pdo): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    sal_invoice_gps_ensure_schema($pdo);
    $cached = sal_invoice_column_exists($pdo, 'sal_invoice', 'post_gps_landmark');

    return $cached;
}

function sal_invoice_gps_normalize_source(?string $source): string
{
    $source = trim((string) $source);
    if ($source === 'mobile' || $source === 'manual') {
        return $source;
    }

    return 'desktop';
}

function sal_invoice_gps_source_label(?string $source): string
{
    if ($source === 'mobile') {
        return 'هاتف';
    }
    if ($source === 'desktop') {
        return 'Windows';
    }
    if ($source === 'manual') {
        return 'خريطة يدوية';
    }

    return 'غير محدد';
}

function sal_invoice_gps_coords_valid(?float $lat, ?float $lng): bool
{
    if ($lat === null || $lng === null) {
        return false;
    }
    if (!is_finite($lat) || !is_finite($lng)) {
        return false;
    }
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        return false;
    }
    if (abs($lat) < 0.000001 && abs($lng) < 0.000001) {
        return false;
    }

    return true;
}

/**
 * مسح إحداثيات الترحيل عند فك الترحيل ليُحفظ موقع جديد عند إعادة الترحيل.
 * لا نستدعي ensure_schema إن الأعمدة موجودة مسبقاً (تجنّب DDL وسط transaction في MySQL).
 */
function sal_invoice_gps_clear_on_unpost(PDO $pdo, int $invoiceId): void
{
    if ($invoiceId < 1) {
        return;
    }

    if (!sal_invoice_column_exists($pdo, 'sal_invoice', 'post_latitude')) {
        sal_invoice_gps_ensure_schema($pdo);
    }
    if (!sal_invoice_column_exists($pdo, 'sal_invoice', 'post_latitude')) {
        return;
    }

    $sets = [
        'post_latitude = NULL',
        'post_longitude = NULL',
        'post_gps_accuracy = NULL',
        'post_gps_at = NULL',
    ];
    if (sal_invoice_column_exists($pdo, 'sal_invoice', 'post_gps_source')) {
        $sets[] = 'post_gps_source = NULL';
    }
    if (sal_invoice_column_exists($pdo, 'sal_invoice', 'post_gps_place')) {
        $sets[] = 'post_gps_place = NULL';
    }
    if (sal_invoice_gps_has_landmark_column($pdo)) {
        $sets[] = 'post_gps_landmark = NULL';
    }

    $pdo->prepare('UPDATE sal_invoice SET ' . implode(', ', $sets) . ' WHERE id = ?')
        ->execute([$invoiceId]);
}

/**
 * حفظ إحداثيات الترحيل الحالية (يُستبدل الموقع السابق عند إعادة الترحيل).
 */
function sal_invoice_gps_save_on_post(
    PDO $pdo,
    int $invoiceId,
    float $lat,
    float $lng,
    ?float $accuracy = null,
    string $source = 'desktop'
): bool {
    if (!app_gps_enabled()) {
        return false;
    }
    sal_invoice_gps_ensure_schema($pdo);
    if ($invoiceId < 1 || !sal_invoice_gps_coords_valid($lat, $lng)) {
        return false;
    }

    $source = sal_invoice_gps_normalize_source($source);

    $sets = [
        'post_latitude = ?',
        'post_longitude = ?',
        'post_gps_accuracy = ?',
        'post_gps_at = NOW()',
        'post_gps_source = ?',
    ];
    $params = [
        round($lat, 7),
        round($lng, 7),
        $accuracy !== null && is_finite($accuracy) ? round($accuracy, 2) : null,
        $source,
    ];
    if (sal_invoice_column_exists($pdo, 'sal_invoice', 'post_gps_place')) {
        $sets[] = 'post_gps_place = NULL';
    }
    if (sal_invoice_gps_has_landmark_column($pdo)) {
        $sets[] = 'post_gps_landmark = NULL';
    }
    $params[] = $invoiceId;

    $st = $pdo->prepare(
        'UPDATE sal_invoice SET ' . implode(', ', $sets) . ' WHERE id = ?'
    );
    $st->execute($params);

    return sal_invoice_gps_has_coords($pdo, $invoiceId);
}

function sal_invoice_gps_trim_place(string $place): string
{
    $place = trim(preg_replace('/\s+/u', ' ', $place) ?? $place);

    return mb_substr($place, 0, 500);
}

function sal_invoice_gps_trim_landmark(string $landmark): string
{
    $landmark = trim(preg_replace('/\s+/u', ' ', $landmark) ?? $landmark);

    return mb_substr($landmark, 0, 300);
}

function sal_invoice_gps_distance_m(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $earthRadius = 6371000.0;
    $phi1 = deg2rad($lat1);
    $phi2 = deg2rad($lat2);
    $dPhi = deg2rad($lat2 - $lat1);
    $dLambda = deg2rad($lng2 - $lng1);
    $a = sin($dPhi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dLambda / 2) ** 2;

    return 2 * $earthRadius * atan2(sqrt($a), sqrt(1 - $a));
}

/** @return array{0: ?string, 1: ?string} */
function sal_invoice_gps_poi_type_parts(?string $typeKey, ?string $typeValue): array
{
    if ($typeKey === null || $typeValue === null || $typeValue === '') {
        return [null, null];
    }

    $labels = [
        'restaurant' => 'مطعم',
        'cafe' => 'مقهى',
        'fast_food' => 'وجبات سريعة',
        'hospital' => 'مستشفى',
        'clinic' => 'عيادة',
        'pharmacy' => 'صيدلية',
        'school' => 'مدرسة',
        'university' => 'جامعة',
        'mosque' => 'مسجد',
        'place_of_worship' => 'مكان عبادة',
        'bank' => 'بنك',
        'atm' => 'صراف آلي',
        'supermarket' => 'سوبرماركت',
        'mall' => 'مول',
        'hotel' => 'فندق',
        'fuel' => 'محطة وقود',
        'parking' => 'موقف سيارات',
        'police' => 'شرطة',
        'library' => 'مكتبة',
        'museum' => 'متحف',
        'park' => 'حديقة',
        'stadium' => 'ملعب',
        'marketplace' => 'سوق',
        'government' => 'مبنى حكومي',
        'company' => 'شركة',
    ];

    $label = $labels[$typeValue] ?? null;

    return [$typeKey, $label ?? $typeValue];
}

function sal_invoice_gps_format_landmark_label(string $name, ?string $typeKey = null, ?string $typeValue = null): string
{
    $name = sal_invoice_gps_trim_landmark($name);
    if ($name === '') {
        return '';
    }

    [, $typeLabel] = sal_invoice_gps_poi_type_parts($typeKey, $typeValue);
    if ($typeLabel !== null && $typeLabel !== '') {
        return sal_invoice_gps_trim_landmark($typeLabel . ' — ' . $name);
    }

    return $name;
}

/** @param array<string, mixed> $address */
function sal_invoice_gps_place_from_address(array $address): string
{
    $parts = [];
    foreach (['suburb', 'neighbourhood', 'quarter', 'residential', 'road', 'hamlet'] as $key) {
        if (!empty($address[$key]) && is_string($address[$key])) {
            $parts[] = trim($address[$key]);
            break;
        }
    }
    foreach (['city_district', 'district', 'county', 'city', 'town', 'village', 'municipality'] as $key) {
        if (!empty($address[$key]) && is_string($address[$key])) {
            $parts[] = trim($address[$key]);
            break;
        }
    }
    foreach (['state', 'state_district', 'region'] as $key) {
        if (!empty($address[$key]) && is_string($address[$key])) {
            $parts[] = trim($address[$key]);
            break;
        }
    }
    if (!empty($address['country']) && is_string($address['country'])) {
        $parts[] = trim($address['country']);
    }

    $parts = array_values(array_unique(array_filter($parts, static fn (string $v): bool => $v !== '')));

    return sal_invoice_gps_trim_place(implode('، ', $parts));
}

/** @return array<string, mixed>|null */
function sal_invoice_gps_nominatim_reverse(float $lat, float $lng): ?array
{
    if (!sal_invoice_gps_coords_valid($lat, $lng)) {
        return null;
    }

    require_once app_path('includes/app_osm.php');

    return app_osm_nominatim_fetch($lat, $lng);
}

/** @param array<string, mixed> $data */
function sal_invoice_gps_place_from_nominatim(array $data): ?string
{
    $place = '';
    if (!empty($data['address']) && is_array($data['address'])) {
        $place = sal_invoice_gps_place_from_address($data['address']);
    }
    if ($place === '' && !empty($data['display_name']) && is_string($data['display_name'])) {
        $place = sal_invoice_gps_trim_place($data['display_name']);
    }

    return $place !== '' ? $place : null;
}

/** @param array<string, mixed> $data */
function sal_invoice_gps_landmark_from_nominatim(array $data): ?string
{
    $poiCategories = ['amenity', 'shop', 'tourism', 'historic', 'leisure', 'office', 'building', 'commercial'];
    $type = (string) ($data['type'] ?? '');
    $category = (string) ($data['category'] ?? '');
    $extratags = !empty($data['extratags']) && is_array($data['extratags']) ? $data['extratags'] : [];

    $name = '';
    if (!empty($data['name']) && is_string($data['name'])) {
        $name = trim($data['name']);
    } elseif (!empty($data['namedetails']) && is_array($data['namedetails'])) {
        foreach (['name:ar', 'name'] as $key) {
            if (!empty($data['namedetails'][$key]) && is_string($data['namedetails'][$key])) {
                $name = trim($data['namedetails'][$key]);
                break;
            }
        }
    }

    if ($name === '') {
        return null;
    }

    $isPoi = in_array($type, $poiCategories, true) || in_array($category, $poiCategories, true);
    if (!$isPoi) {
        return null;
    }

    $typeKey = null;
    $typeValue = null;
    foreach (['amenity', 'shop', 'tourism', 'historic', 'leisure', 'office', 'building'] as $key) {
        if (!empty($extratags[$key]) && is_string($extratags[$key])) {
            $typeKey = $key;
            $typeValue = trim($extratags[$key]);
            break;
        }
    }
    if ($typeValue === null && in_array($type, $poiCategories, true)) {
        $typeKey = $category !== '' ? $category : 'amenity';
        $typeValue = $type;
    }

    $label = sal_invoice_gps_format_landmark_label($name, $typeKey, $typeValue);

    return $label !== '' ? $label : null;
}

function sal_invoice_gps_overpass_nearest_landmark(float $lat, float $lng, int $radiusM = 700): ?string
{
    if (!sal_invoice_gps_coords_valid($lat, $lng)) {
        return null;
    }

    $radiusM = max(200, min(1500, $radiusM));
    $latStr = sprintf('%.6F', $lat);
    $lngStr = sprintf('%.6F', $lng);
    $around = static function (string $selector) use ($radiusM, $latStr, $lngStr): string {
        return $selector . '(around:' . $radiusM . ',' . $latStr . ',' . $lngStr . ')["name"]';
    };

    $query = '[out:json][timeout:8];('
        . $around('node') . '["amenity"];'
        . $around('node') . '["shop"];'
        . $around('node') . '["tourism"];'
        . $around('node') . '["historic"];'
        . $around('node') . '["leisure"];'
        . $around('node') . '["office"];'
        . $around('way') . '["amenity"];'
        . $around('way') . '["shop"];'
        . $around('way') . '["tourism"];'
        . $around('way') . '["historic"];'
        . $around('way') . '["leisure"];'
        . $around('way') . '["office"];'
        . $around('way') . '["building"];'
        . ');out center 20;';

    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "User-Agent: ManagerAccounting-GPS/1.0\r\nContent-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
            'content' => 'data=' . rawurlencode($query),
            'timeout' => 10,
        ],
    ]);

    $raw = @file_get_contents('https://overpass-api.de/api/interpreter', false, $ctx);
    if ($raw === false || $raw === '') {
        return null;
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['elements']) || !is_array($data['elements'])) {
        return null;
    }

    $bestName = null;
    $bestDistance = PHP_FLOAT_MAX;
    $bestTypeKey = null;
    $bestTypeValue = null;

    foreach ($data['elements'] as $element) {
        if (!is_array($element)) {
            continue;
        }

        $tags = !empty($element['tags']) && is_array($element['tags']) ? $element['tags'] : [];
        $name = trim((string) ($tags['name'] ?? ''));
        if ($name === '') {
            continue;
        }

        $elLat = isset($element['lat']) ? (float) $element['lat'] : (float) ($element['center']['lat'] ?? 0);
        $elLng = isset($element['lon']) ? (float) $element['lon'] : (float) ($element['center']['lon'] ?? 0);
        if (!sal_invoice_gps_coords_valid($elLat, $elLng)) {
            continue;
        }

        $distance = sal_invoice_gps_distance_m($lat, $lng, $elLat, $elLng);
        if ($distance >= $bestDistance) {
            continue;
        }

        $typeKey = null;
        $typeValue = null;
        foreach (['amenity', 'shop', 'tourism', 'historic', 'leisure', 'office', 'building'] as $key) {
            if (!empty($tags[$key]) && is_string($tags[$key])) {
                $typeKey = $key;
                $typeValue = trim($tags[$key]);
                break;
            }
        }

        $bestDistance = $distance;
        $bestName = $name;
        $bestTypeKey = $typeKey;
        $bestTypeValue = $typeValue;
    }

    if ($bestName === null) {
        return null;
    }

    $label = sal_invoice_gps_format_landmark_label($bestName, $bestTypeKey, $bestTypeValue);

    return $label !== '' ? $label : null;
}

/**
 * @return array{place: ?string, landmark: ?string}
 */
function sal_invoice_gps_resolve_location(float $lat, float $lng): array
{
    $place = null;
    $landmark = null;

    $nominatim = sal_invoice_gps_nominatim_reverse($lat, $lng);
    if ($nominatim !== null) {
        $place = sal_invoice_gps_place_from_nominatim($nominatim);
        $landmark = sal_invoice_gps_landmark_from_nominatim($nominatim);
    }

    if ($landmark === null) {
        $landmark = sal_invoice_gps_overpass_nearest_landmark($lat, $lng);
    }

    return [
        'place' => $place,
        'landmark' => $landmark,
    ];
}

function sal_invoice_gps_reverse_geocode(float $lat, float $lng): ?string
{
    $location = sal_invoice_gps_resolve_location($lat, $lng);

    return $location['place'];
}

function sal_invoice_gps_nearest_landmark(float $lat, float $lng): ?string
{
    $location = sal_invoice_gps_resolve_location($lat, $lng);

    return $location['landmark'];
}

function sal_invoice_gps_set_place(PDO $pdo, int $invoiceId, string $place): void
{
    sal_invoice_gps_ensure_schema($pdo);
    $place = sal_invoice_gps_trim_place($place);
    if ($invoiceId < 1 || $place === '') {
        return;
    }

    $pdo->prepare(
        'UPDATE sal_invoice SET post_gps_place = ? WHERE id = ?'
    )->execute([$place, $invoiceId]);
}

function sal_invoice_gps_set_landmark(PDO $pdo, int $invoiceId, string $landmark): void
{
    if (!sal_invoice_gps_has_landmark_column($pdo)) {
        return;
    }

    $landmark = sal_invoice_gps_trim_landmark($landmark);
    if ($invoiceId < 1 || $landmark === '') {
        return;
    }

    $pdo->prepare(
        'UPDATE sal_invoice SET post_gps_landmark = ? WHERE id = ?'
    )->execute([$landmark, $invoiceId]);
}

/**
 * @return array{place: ?string, landmark: ?string}
 */
function sal_invoice_gps_fill_location_for_invoice(PDO $pdo, int $invoiceId, ?float $lat = null, ?float $lng = null): array
{
    sal_invoice_gps_ensure_schema($pdo);
    if ($invoiceId < 1) {
        return ['place' => null, 'landmark' => null];
    }

    $hasLandmark = sal_invoice_gps_has_landmark_column($pdo);
    $sql = 'SELECT post_latitude, post_longitude, post_gps_place';
    if ($hasLandmark) {
        $sql .= ', post_gps_landmark';
    }
    $sql .= ' FROM sal_invoice WHERE id = ? LIMIT 1';

    $st = $pdo->prepare($sql);
    $st->execute([$invoiceId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['place' => null, 'landmark' => null];
    }

    $cachedPlace = trim((string) ($row['post_gps_place'] ?? ''));
    $cachedLandmark = $hasLandmark ? trim((string) ($row['post_gps_landmark'] ?? '')) : '';
    if ($cachedPlace !== '' && $cachedLandmark !== '') {
        return [
            'place' => $cachedPlace,
            'landmark' => $cachedLandmark,
        ];
    }

    $lat = $lat ?? (float) ($row['post_latitude'] ?? 0);
    $lng = $lng ?? (float) ($row['post_longitude'] ?? 0);
    if (!sal_invoice_gps_coords_valid($lat, $lng)) {
        return [
            'place' => $cachedPlace !== '' ? $cachedPlace : null,
            'landmark' => $cachedLandmark !== '' ? $cachedLandmark : null,
        ];
    }

    $place = $cachedPlace !== '' ? $cachedPlace : null;
    $landmark = $cachedLandmark !== '' ? $cachedLandmark : null;

    if ($landmark === null) {
        $nominatim = sal_invoice_gps_nominatim_reverse($lat, $lng);
        if ($nominatim !== null) {
            if ($place === null) {
                $place = sal_invoice_gps_place_from_nominatim($nominatim);
            }
            $landmark = sal_invoice_gps_landmark_from_nominatim($nominatim);
        }
        if ($landmark === null) {
            $landmark = sal_invoice_gps_overpass_nearest_landmark($lat, $lng);
        }
    } elseif ($place === null) {
        $nominatim = sal_invoice_gps_nominatim_reverse($lat, $lng);
        if ($nominatim !== null) {
            $place = sal_invoice_gps_place_from_nominatim($nominatim);
        }
    }

    if ($cachedPlace === '' && $place !== null && $place !== '') {
        sal_invoice_gps_set_place($pdo, $invoiceId, $place);
    }
    if ($cachedLandmark === '' && $landmark !== null && $landmark !== '') {
        sal_invoice_gps_set_landmark($pdo, $invoiceId, $landmark);
    }

    return [
        'place' => $place !== null && $place !== '' ? $place : null,
        'landmark' => $landmark !== null && $landmark !== '' ? $landmark : null,
    ];
}

function sal_invoice_gps_fill_place_for_invoice(PDO $pdo, int $invoiceId, ?float $lat = null, ?float $lng = null): ?string
{
    $location = sal_invoice_gps_fill_location_for_invoice($pdo, $invoiceId, $lat, $lng);

    return $location['place'];
}

function sal_invoice_gps_place_for_invoice(PDO $pdo, int $invoiceId, bool $fetchIfMissing = true): ?string
{
    $location = sal_invoice_gps_location_for_invoice($pdo, $invoiceId, $fetchIfMissing);

    return $location['place'];
}

/**
 * @return array{place: ?string, landmark: ?string}
 */
function sal_invoice_gps_location_for_invoice(PDO $pdo, int $invoiceId, bool $fetchIfMissing = true): array
{
    sal_invoice_gps_ensure_schema($pdo);
    if ($invoiceId < 1) {
        return ['place' => null, 'landmark' => null];
    }

    $hasLandmark = sal_invoice_gps_has_landmark_column($pdo);
    $sql = 'SELECT post_latitude, post_longitude, post_gps_place';
    if ($hasLandmark) {
        $sql .= ', post_gps_landmark';
    }
    $sql .= ' FROM sal_invoice WHERE id = ? LIMIT 1';

    $st = $pdo->prepare($sql);
    $st->execute([$invoiceId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || !sal_invoice_gps_coords_valid((float) ($row['post_latitude'] ?? 0), (float) ($row['post_longitude'] ?? 0))) {
        return ['place' => null, 'landmark' => null];
    }

    $cachedPlace = trim((string) ($row['post_gps_place'] ?? ''));
    $cachedLandmark = $hasLandmark ? trim((string) ($row['post_gps_landmark'] ?? '')) : '';
    if ($cachedPlace !== '' && $cachedLandmark !== '') {
        return [
            'place' => $cachedPlace,
            'landmark' => $cachedLandmark,
        ];
    }
    if (!$fetchIfMissing) {
        return [
            'place' => $cachedPlace !== '' ? $cachedPlace : null,
            'landmark' => $cachedLandmark !== '' ? $cachedLandmark : null,
        ];
    }

    return sal_invoice_gps_fill_location_for_invoice(
        $pdo,
        $invoiceId,
        (float) $row['post_latitude'],
        (float) $row['post_longitude']
    );
}

function sal_invoice_gps_icon_svg(): string
{
    return '<svg class="sal-gps-icon-btn__svg" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">'
        . '<path fill="#fff" d="M12 2.5a6.25 6.25 0 0 0-6.25 6.25c0 4.69 6.25 12.75 6.25 12.75S18.25 13.44 18.25 8.75A6.25 6.25 0 0 0 12 2.5Zm0 8.5a2.25 2.25 0 1 1 0-4.5 2.25 2.25 0 0 1 0 4.5Z"/>'
        . '</svg>';
}

/**
 * @param array<string, mixed> $row
 */
function sal_invoice_gps_map_button_html(array $row): string
{
    $lat = (string) ($row['post_latitude'] ?? '');
    $lng = (string) ($row['post_longitude'] ?? '');
    $invoiceId = (int) ($row['id'] ?? 0);
    $invoiceNo = (string) ($row['invoice_no'] ?? '');
    $customer = (string) ($row['customer_name'] ?? '');
    $place = (string) ($row['place_label'] ?? $row['post_gps_place'] ?? '');
    $landmark = (string) ($row['landmark_label'] ?? $row['post_gps_landmark'] ?? '');
    $embed = (string) ($row['map_embed_url'] ?? '');
    $external = (string) ($row['map_url'] ?? '');

    return '<button type="button" class="sal-gps-icon-btn sal-gps-map-open"'
        . ' data-lat="' . esc($lat) . '"'
        . ' data-lng="' . esc($lng) . '"'
        . ' data-invoice-id="' . $invoiceId . '"'
        . ' data-invoice="' . esc($invoiceNo) . '"'
        . ' data-customer="' . esc($customer) . '"'
        . ' data-place="' . esc($place) . '"'
        . ' data-landmark="' . esc($landmark) . '"'
        . ' data-embed="' . esc($embed) . '"'
        . ' data-external="' . esc($external) . '"'
        . ' title="عرض الموقع على الخريطة">'
        . '<span class="sal-gps-icon-btn__glyph">' . sal_invoice_gps_icon_svg() . '</span>'
        . '</button>';
}

function sal_invoice_gps_map_url(float $lat, float $lng): string
{
    return 'https://www.google.com/maps?q=' . rawurlencode(sprintf('%.7F,%.7F', $lat, $lng));
}

function sal_invoice_gps_has_coords(PDO $pdo, int $invoiceId): bool
{
    if ($invoiceId < 1) {
        return false;
    }
    sal_invoice_gps_ensure_schema($pdo);
    if (!sal_invoice_column_exists($pdo, 'sal_invoice', 'post_latitude')) {
        return false;
    }
    $st = $pdo->prepare(
        'SELECT post_latitude, post_longitude FROM sal_invoice WHERE id = ? LIMIT 1'
    );
    $st->execute([$invoiceId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }

    return sal_invoice_gps_coords_valid(
        (float) ($row['post_latitude'] ?? 0),
        (float) ($row['post_longitude'] ?? 0)
    );
}

/**
 * @param array<string, mixed>|null $source
 * @return array{latitude:float, longitude:float, gps_accuracy:?float, gps_source:string, gps_place:?string}|null
 */
function sal_invoice_gps_parse_request(?array $source = null): ?array
{
    $source = $source ?? $_POST;
    if (!is_array($source)) {
        return null;
    }

    if (!isset($source['latitude'], $source['longitude'])) {
        return null;
    }

    $lat = (float) $source['latitude'];
    $lng = (float) $source['longitude'];
    if (!sal_invoice_gps_coords_valid($lat, $lng)) {
        return null;
    }

    $accuracy = null;
    if (isset($source['gps_accuracy']) && $source['gps_accuracy'] !== '') {
        $accuracy = (float) $source['gps_accuracy'];
        if (!is_finite($accuracy) || $accuracy <= 0) {
            $accuracy = null;
        }
    }

    // تجاهل قراءات GPS تقريبية جداً فقط
    if ($accuracy !== null && $accuracy > 500) {
        return null;
    }

    $sourceNorm = sal_invoice_gps_normalize_source((string) ($source['gps_source'] ?? 'mobile'));

    $place = trim((string) ($source['gps_place'] ?? ''));
    if ($place !== '') {
        $place = sal_invoice_gps_trim_place($place);
    } else {
        $place = null;
    }

    return [
        'latitude' => $lat,
        'longitude' => $lng,
        'gps_accuracy' => $accuracy,
        'gps_source' => $sourceNorm,
        'gps_place' => $place,
    ];
}

function sal_invoice_gps_place_link_html(float $lat, float $lng, string $label, string $class = 'sal-gps-place-link'): string
{
    if (!sal_invoice_gps_coords_valid($lat, $lng)) {
        return esc($label);
    }
    $url = sal_invoice_gps_map_url($lat, $lng);
    $title = 'فتح على Google Maps';

    return '<a href="' . esc($url) . '" target="_blank" rel="noopener noreferrer"'
        . ' class="' . esc($class) . '" title="' . esc($title) . '">'
        . esc($label)
        . '</a>';
}

/** @return list<int> */
function sal_invoice_gps_user_ids_for_invoice(PDO $pdo, int $invoiceId, ?int $preferUserId = null): array
{
    $ids = [];
    if ($preferUserId !== null && $preferUserId > 0) {
        $ids[] = $preferUserId;
    }

    sal_invoice_gps_ensure_schema($pdo);
    $cols = ['created_by'];
    if (sal_invoice_gps_has_posted_by_column($pdo)) {
        $cols[] = 'posted_by';
    }
    $st = $pdo->prepare(
        'SELECT ' . implode(', ', $cols) . ' FROM sal_invoice WHERE id = ? LIMIT 1'
    );
    $st->execute([$invoiceId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach (['posted_by', 'created_by'] as $key) {
        $uid = (int) ($row[$key] ?? 0);
        if ($uid > 0 && !in_array($uid, $ids, true)) {
            $ids[] = $uid;
        }
    }

    return $ids;
}

/**
 * @return array{latitude:float, longitude:float, gps_accuracy:?float, gps_source:string, gps_place:?string}|null
 */
function sal_invoice_gps_lookup_from_users(PDO $pdo, array $userIds, int $maxAgeSec = 86400, float $maxAccuracyM = 150): ?array
{
    require_once app_path('includes/sys_user_location.php');
    foreach ($userIds as $rawUid) {
        $uid = (int) $rawUid;
        if ($uid < 1) {
            continue;
        }
        $latest = sys_user_location_latest_for_user($pdo, $uid, $maxAgeSec);
        if ($latest === null) {
            continue;
        }
        $acc = $latest['gps_accuracy'] ?? null;
        if ($acc !== null && is_numeric($acc) && (float) $acc > $maxAccuracyM) {
            continue;
        }

        return [
            'latitude' => (float) $latest['latitude'],
            'longitude' => (float) $latest['longitude'],
            'gps_accuracy' => $latest['gps_accuracy'] ?? null,
            'gps_source' => (string) ($latest['gps_source'] ?? 'mobile'),
            'gps_place' => null,
        ];
    }

    return null;
}

/**
 * @deprecated لا تُستخدم — كانت تنسخ موقع المستخدم الواحد (sys_user_location) لكل الفواتير.
 */
function sal_invoice_gps_backfill_recent(PDO $pdo, int $days = 14, int $limit = 200): int
{
    return 0;
}

/**
 * حفظ موقع الترحيل من الطلب أو آخر موقع مسجّل للمستخدم.
 *
 * @param array{latitude:float, longitude:float, gps_accuracy:?float, gps_source:string, gps_place:?string}|null $gps
 */
function sal_invoice_gps_apply_on_post(
    PDO $pdo,
    int $invoiceId,
    ?array $gps = null,
    ?int $userId = null,
    bool $allowReplace = false
): bool {
    if (!app_gps_enabled() || $invoiceId < 1) {
        return false;
    }
    if (sal_invoice_gps_has_coords($pdo, $invoiceId) && !$allowReplace) {
        return false;
    }

    if ($gps === null) {
        $gps = sal_invoice_gps_parse_request();
    }

    // إذا لم تُرسل إحداثيات: آخر موقع مسجّل للمستخدم (حتى 4 ساعات — من تتبع الجلسة).
    if ($gps === null && $userId !== null && $userId > 0) {
        $gps = sal_invoice_gps_lookup_from_users($pdo, [$userId], 14400, 500.0);
    }

    if ($gps === null) {
        return false;
    }

    $acc = $gps['gps_accuracy'] ?? null;
    if ($acc !== null && is_numeric($acc) && (float) $acc > 500) {
        return false;
    }

    $saved = sal_invoice_gps_save_on_post(
        $pdo,
        $invoiceId,
        (float) $gps['latitude'],
        (float) $gps['longitude'],
        $gps['gps_accuracy'] ?? null,
        (string) ($gps['gps_source'] ?? 'mobile')
    );
    if (!$saved) {
        return false;
    }

    $manualPlace = trim((string) ($gps['gps_place'] ?? ''));
    if ($manualPlace !== '') {
        sal_invoice_gps_set_place($pdo, $invoiceId, $manualPlace);
    }

    // اسم الشارع يُحدَّد لاحقاً عند فتح الخريطة (lazy geocode) — لا نبطئ الترحيل.

    return true;
}

/** خريطة تفاعلية داخل النظام (OpenStreetMap embed). */
function sal_invoice_gps_embed_url(float $lat, float $lng): string
{
    $latStr = sprintf('%.6F', $lat);
    $lngStr = sprintf('%.6F', $lng);
    $delta = 0.006;
    $bbox = sprintf(
        '%F,%F,%F,%F',
        $lng - $delta,
        $lat - $delta,
        $lng + $delta,
        $lat + $delta
    );

    return 'https://www.openstreetmap.org/export/embed.html'
        . '?bbox=' . rawurlencode($bbox)
        . '&layer=mapnik'
        . '&marker=' . rawurlencode($latStr . ',' . $lngStr);
}

/** صورة مصغّرة للموقع (خريطة ثابتة — بدون مفتاح API). */
function sal_invoice_gps_static_map_url(float $lat, float $lng, int $width = 300, int $height = 168): string
{
    $width = max(120, min(640, $width));
    $height = max(80, min(400, $height));
    $latStr = sprintf('%.6F', $lat);
    $lngStr = sprintf('%.6F', $lng);

    return 'https://staticmap.openstreetmap.de/staticmap.php'
        . '?center=' . rawurlencode($latStr . ',' . $lngStr)
        . '&zoom=16'
        . '&size=' . $width . 'x' . $height
        . '&markers=' . rawurlencode($latStr . ',' . $lngStr . ',red-pushpin');
}

/**
 * @return list<array<string, mixed>>
 */
function sal_invoice_gps_list_rows(
    PDO $pdo,
    string $search = '',
    int $limit = 200,
    ?string $dateFrom = null,
    ?string $dateTo = null
): array {
    sal_invoice_gps_ensure_schema($pdo);
    require_once app_path('includes/sal_documents_list.php');
    require_once app_path('includes/sal_invoice_post.php');

    $limit = max(1, min(500, $limit));
    $search = trim($search);
    $dateFrom = $dateFrom !== null && $dateFrom !== '' ? $dateFrom : null;
    $dateTo = $dateTo !== null && $dateTo !== '' ? $dateTo : null;
    $postedExpr = sal_invoice_sql_is_posted_expr('i');
    $hasLandmark = sal_invoice_gps_has_landmark_column($pdo);
    $hasPostedBy = sal_invoice_gps_has_posted_by_column($pdo);
    $landmarkSelect = $hasLandmark ? 'i.post_gps_landmark' : 'NULL AS post_gps_landmark';
    $postedBySelect = $hasPostedBy ? 'i.posted_by' : 'NULL AS posted_by';
    $postedJoinExpr = $hasPostedBy ? 'i.posted_by' : 'NULL';

    $sql = "SELECT i.id, i.invoice_no, i.invoice_date, i.created_by, {$postedBySelect},
                   i.post_latitude, i.post_longitude,
                   i.post_gps_accuracy, i.post_gps_at, i.post_gps_source, i.post_gps_place, {$landmarkSelect},
                   c.name_ar AS customer_name, c.code AS customer_code,
                   uc.full_name_ar AS created_user_name, uc.username AS created_username,
                   up.full_name_ar AS posted_user_name, up.username AS posted_username
            FROM sal_invoice i
            INNER JOIN crm_customer c ON c.id = i.customer_id
            LEFT JOIN sys_user uc ON uc.id = i.created_by
            LEFT JOIN sys_user up ON up.id = {$postedJoinExpr}
            WHERE i.status = 'confirmed'
              AND ({$postedExpr})";
    $params = [];

    if ($search !== '') {
        $sql .= ' AND (i.invoice_no LIKE ? OR c.name_ar LIKE ? OR c.code LIKE ? OR i.post_gps_place LIKE ?'
            . ' OR uc.full_name_ar LIKE ? OR uc.username LIKE ?';
        $like = '%' . $search . '%';
        $params = [$like, $like, $like, $like, $like, $like];
        if ($hasLandmark) {
            $sql .= ' OR i.post_gps_landmark LIKE ?';
            $params[] = $like;
        }
        if ($hasPostedBy) {
            $sql .= ' OR up.full_name_ar LIKE ? OR up.username LIKE ?';
            $params[] = $like;
            $params[] = $like;
        }
        $sql .= ')';
    }

    if ($dateFrom !== null && $dateTo !== null) {
        $sql .= ' AND DATE(COALESCE(i.post_gps_at, CONCAT(i.invoice_date, \' 00:00:00\'))) >= ?'
            . ' AND DATE(COALESCE(i.post_gps_at, CONCAT(i.invoice_date, \' 00:00:00\'))) <= ?';
        $params[] = $dateFrom;
        $params[] = $dateTo;
    }

    $sql .= ' ORDER BY COALESCE(i.post_gps_at, i.invoice_date) DESC, i.id DESC LIMIT ' . (int) $limit;

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as &$row) {
        $lat = (float) ($row['post_latitude'] ?? 0);
        $lng = (float) ($row['post_longitude'] ?? 0);
        $row['has_gps'] = sal_invoice_gps_coords_valid($lat, $lng);
        $row['invoice_date_dmy'] = format_date_dmY((string) ($row['invoice_date'] ?? ''));
        $gpsTs = !empty($row['post_gps_at']) ? strtotime((string) $row['post_gps_at']) : false;
        $row['gps_at_dmy'] = $gpsTs !== false ? date('d-m-Y H:i', $gpsTs) : '';
        $row['coords_label'] = $row['has_gps'] ? sprintf('%.6F, %.6F', $lat, $lng) : '';
        $row['map_url'] = $row['has_gps'] ? sal_invoice_gps_map_url($lat, $lng) : '';
        $row['map_embed_url'] = $row['has_gps'] ? sal_invoice_gps_embed_url($lat, $lng) : '';
        $row['post_latitude'] = $lat;
        $row['post_longitude'] = $lng;
        $row['post_gps_place'] = trim((string) ($row['post_gps_place'] ?? ''));
        $row['post_gps_landmark'] = trim((string) ($row['post_gps_landmark'] ?? ''));
        $row['place_label'] = $row['post_gps_place'];
        $row['landmark_label'] = $row['post_gps_landmark'];
        if (!empty($row['post_gps_accuracy'])) {
            $row['accuracy_label'] = round((float) $row['post_gps_accuracy']) . ' م';
        } else {
            $row['accuracy_label'] = '';
        }
        $rawSrc = isset($row['post_gps_source']) ? trim((string) $row['post_gps_source']) : '';
        $row['post_gps_source'] = $rawSrc !== '' ? $rawSrc : null;
        $row['source_label'] = sal_invoice_gps_source_label($rawSrc !== '' ? $rawSrc : null);
        if ($rawSrc === 'mobile') {
            $row['source_badge_class'] = 'sal-gps-src--mobile';
        } elseif ($rawSrc === 'desktop') {
            $row['source_badge_class'] = 'sal-gps-src--desktop';
        } else {
            $row['source_badge_class'] = 'sal-gps-src--unknown';
        }
        sal_invoice_gps_apply_user_labels($row);
    }
    unset($row);

    return $rows;
}
