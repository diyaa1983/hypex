<?php
declare(strict_types=1);

/**
 * بحث أماكن لتحديد موقع العميل على التابلت/الموبايل.
 * يستخدم مفتاح Google Maps المحفوظ في إعدادات الشركة إن وُجد،
 * وإلا Nominatim (OpenStreetMap) كبديل.
 *
 * GET q=...&lat=...&lng=...        → اقتراحات
 * GET place_id=ChIJ...             → تفاصيل نقطة Google
 * GET osm_id=...&osm_type=...      → تفاصيل Nominatim (اختياري — النتائج غالباً معها lat/lng)
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/mobile_auth.php');
require_once app_path('includes/mobile_gps_settings.php');
require_once app_path('includes/app_osm.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!is_logged_in() || !mobile_is_context() || !user_in_mobile_group()) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'error' => 'unauthorized',
        'message' => 'الجلسة منتهية. سجّل الدخول من جديد.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'الطريقة غير مسموحة.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$googleKey = mobile_gps_settings_google_maps_key();
$q = trim((string) ($_GET['q'] ?? ''));
$placeId = trim((string) ($_GET['place_id'] ?? ''));
$biasLat = isset($_GET['lat']) ? (float) $_GET['lat'] : null;
$biasLng = isset($_GET['lng']) ? (float) $_GET['lng'] : null;
if ($biasLat !== null && ($biasLat < -90 || $biasLat > 90)) {
    $biasLat = null;
}
if ($biasLng !== null && ($biasLng < -180 || $biasLng > 180)) {
    $biasLng = null;
}

/**
 * @return list<array{id:string,name:string,address:string,lat:?float,lng:?float,provider:string}>
 */
function mobile_places_normalize_rows(array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        if (!is_array($r)) {
            continue;
        }
        $id = trim((string) ($r['id'] ?? ''));
        $name = trim((string) ($r['name'] ?? ''));
        if ($id === '' || $name === '') {
            continue;
        }
        $lat = $r['lat'] ?? null;
        $lng = $r['lng'] ?? null;
        $out[] = [
            'id' => $id,
            'name' => $name,
            'address' => trim((string) ($r['address'] ?? '')),
            'lat' => is_numeric($lat) ? (float) $lat : null,
            'lng' => is_numeric($lng) ? (float) $lng : null,
            'provider' => (string) ($r['provider'] ?? 'unknown'),
        ];
        if (count($out) >= 10) {
            break;
        }
    }

    return $out;
}

/** @return array<string, mixed>|null */
function mobile_places_http_json(string $url): ?array
{
    $raw = app_osm_osrm_http_get($url);
    if ($raw === null || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);

    return is_array($data) ? $data : null;
}

/**
 * @return list<array{id:string,name:string,address:string,lat:?float,lng:?float,provider:string}>
 */
function mobile_places_google_autocomplete(string $key, string $q, ?float $lat, ?float $lng): array
{
    $params = [
        'input' => $q,
        'key' => $key,
        'language' => 'ar',
        'components' => 'country:jo',
    ];
    if ($lat !== null && $lng !== null) {
        $params['location'] = sprintf('%.6F,%.6F', $lat, $lng);
        $params['radius'] = '50000';
    }
    $url = 'https://maps.googleapis.com/maps/api/place/autocomplete/json?' . http_build_query($params);
    $data = mobile_places_http_json($url);
    if ($data === null) {
        return [];
    }
    $status = (string) ($data['status'] ?? '');
    if ($status !== 'OK' && $status !== 'ZERO_RESULTS') {
        return [];
    }
    $rows = [];
    foreach (($data['predictions'] ?? []) as $p) {
        if (!is_array($p)) {
            continue;
        }
        $pid = trim((string) ($p['place_id'] ?? ''));
        $desc = trim((string) ($p['description'] ?? ''));
        $main = trim((string) ($p['structured_formatting']['main_text'] ?? $desc));
        if ($pid === '' || $main === '') {
            continue;
        }
        $rows[] = [
            'id' => $pid,
            'name' => $main,
            'address' => $desc,
            'lat' => null,
            'lng' => null,
            'provider' => 'google',
        ];
    }

    return mobile_places_normalize_rows($rows);
}

/** @return array{id:string,name:string,address:string,lat:float,lng:float,provider:string}|null */
function mobile_places_google_details(string $key, string $placeId): ?array
{
    $url = 'https://maps.googleapis.com/maps/api/place/details/json?' . http_build_query([
        'place_id' => $placeId,
        'fields' => 'geometry,name,formatted_address',
        'key' => $key,
        'language' => 'ar',
    ]);
    $data = mobile_places_http_json($url);
    if ($data === null || (string) ($data['status'] ?? '') !== 'OK') {
        return null;
    }
    $r = $data['result'] ?? null;
    if (!is_array($r)) {
        return null;
    }
    $lat = $r['geometry']['location']['lat'] ?? null;
    $lng = $r['geometry']['location']['lng'] ?? null;
    if (!is_numeric($lat) || !is_numeric($lng)) {
        return null;
    }
    $name = trim((string) ($r['name'] ?? ''));
    $addr = trim((string) ($r['formatted_address'] ?? ''));
    if ($name === '') {
        $name = $addr !== '' ? $addr : 'موقع';
    }

    return [
        'id' => $placeId,
        'name' => $name,
        'address' => $addr,
        'lat' => (float) $lat,
        'lng' => (float) $lng,
        'provider' => 'google',
    ];
}

/**
 * بحث نصي عبر Geocoding API (بديل إن فشل Autocomplete).
 *
 * @return list<array{id:string,name:string,address:string,lat:?float,lng:?float,provider:string}>
 */
function mobile_places_google_geocode(string $key, string $q, ?float $lat, ?float $lng): array
{
    $params = [
        'address' => $q,
        'key' => $key,
        'language' => 'ar',
        'region' => 'jo',
        'components' => 'country:JO',
    ];
    if ($lat !== null && $lng !== null) {
        $params['bounds'] = sprintf(
            '%.5F,%.5F|%.5F,%.5F',
            $lat - 0.35,
            $lng - 0.35,
            $lat + 0.35,
            $lng + 0.35
        );
    }
    $url = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query($params);
    $data = mobile_places_http_json($url);
    if ($data === null) {
        return [];
    }
    $status = (string) ($data['status'] ?? '');
    if ($status !== 'OK' && $status !== 'ZERO_RESULTS') {
        return [];
    }
    $rows = [];
    foreach (($data['results'] ?? []) as $i => $r) {
        if (!is_array($r)) {
            continue;
        }
        $latV = $r['geometry']['location']['lat'] ?? null;
        $lngV = $r['geometry']['location']['lng'] ?? null;
        $addr = trim((string) ($r['formatted_address'] ?? ''));
        $pid = trim((string) ($r['place_id'] ?? ('geo_' . $i)));
        if (!is_numeric($latV) || !is_numeric($lngV) || $addr === '') {
            continue;
        }
        $rows[] = [
            'id' => $pid,
            'name' => $addr,
            'address' => $addr,
            'lat' => (float) $latV,
            'lng' => (float) $lngV,
            'provider' => 'google',
        ];
    }

    return mobile_places_normalize_rows($rows);
}

/**
 * @return list<array{id:string,name:string,address:string,lat:?float,lng:?float,provider:string}>
 */
function mobile_places_nominatim_search(string $q, ?float $lat, ?float $lng): array
{
    $params = [
        'q' => $q,
        'format' => 'jsonv2',
        'addressdetails' => 1,
        'limit' => 10,
        'accept-language' => 'ar,en',
        'countrycodes' => 'jo',
    ];
    if ($lat !== null && $lng !== null) {
        $params['viewbox'] = sprintf(
            '%.5F,%.5F,%.5F,%.5F',
            $lng - 0.45,
            $lat + 0.45,
            $lng + 0.45,
            $lat - 0.45
        );
        $params['bounded'] = 0;
    }
    $key = app_osm_nominatim_api_key();
    if ($key !== '') {
        $params['key'] = $key;
    }
    $base = defined('APP_OSM_NOMINATIM_SEARCH_URL')
        ? trim((string) APP_OSM_NOMINATIM_SEARCH_URL)
        : '';
    if ($base === '') {
        $base = 'https://nominatim.openstreetmap.org/search';
    }
    $url = rtrim($base, '?&') . '?' . http_build_query($params);
    $data = mobile_places_http_json($url);
    if (!is_array($data)) {
        return [];
    }
    // Nominatim يعيد مصفوفة مباشرة.
    $list = array_is_list($data) ? $data : ($data['results'] ?? []);
    if (!is_array($list)) {
        return [];
    }
    $rows = [];
    foreach ($list as $r) {
        if (!is_array($r)) {
            continue;
        }
        $latV = $r['lat'] ?? null;
        $lngV = $r['lon'] ?? ($r['lng'] ?? null);
        $name = trim((string) ($r['name'] ?? ''));
        $display = trim((string) ($r['display_name'] ?? ''));
        if ($name === '') {
            $name = $display !== '' ? explode(',', $display)[0] : '';
        }
        $osmType = (string) ($r['osm_type'] ?? 'N');
        $osmId = (string) ($r['osm_id'] ?? '');
        $id = $osmId !== '' ? ($osmType . $osmId) : ('nom_' . md5($display));
        if ($name === '' || !is_numeric($latV) || !is_numeric($lngV)) {
            continue;
        }
        $rows[] = [
            'id' => $id,
            'name' => $name,
            'address' => $display,
            'lat' => (float) $latV,
            'lng' => (float) $lngV,
            'provider' => 'nominatim',
        ];
    }

    return mobile_places_normalize_rows($rows);
}

// —— تفاصيل مكان Google ——
if ($placeId !== '') {
    if ($googleKey === '') {
        http_response_code(422);
        echo json_encode([
            'ok' => false,
            'message' => 'مفتاح Google Maps غير مُعدّ في إعدادات الشركة.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $detail = mobile_places_google_details($googleKey, $placeId);
    if ($detail === null) {
        http_response_code(404);
        echo json_encode([
            'ok' => false,
            'message' => 'تعذّر جلب إحداثيات المكان من Google.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode([
        'ok' => true,
        'provider' => 'google',
        'has_google' => true,
        'place' => $detail,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$qLen = function_exists('mb_strlen') ? mb_strlen($q, 'UTF-8') : strlen($q);
if ($qLen < 2) {
    echo json_encode([
        'ok' => true,
        'provider' => $googleKey !== '' ? 'google' : 'nominatim',
        'has_google' => $googleKey !== '',
        'results' => [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$provider = 'nominatim';
$results = [];

if ($googleKey !== '') {
    $results = mobile_places_google_autocomplete($googleKey, $q, $biasLat, $biasLng);
    if ($results === []) {
        $results = mobile_places_google_geocode($googleKey, $q, $biasLat, $biasLng);
    }
    if ($results !== []) {
        $provider = 'google';
    }
}

if ($results === []) {
    $results = mobile_places_nominatim_search($q, $biasLat, $biasLng);
    $provider = 'nominatim';
}

echo json_encode([
    'ok' => true,
    'provider' => $provider,
    'has_google' => $googleKey !== '',
    'results' => $results,
], JSON_UNESCAPED_UNICODE);
