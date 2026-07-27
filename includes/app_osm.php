<?php
declare(strict_types=1);

/** بريد التواصل لـ User-Agent (مطلوب من سياسة Nominatim/OSM). */
function app_osm_contact(): string
{
    if (defined('APP_OSM_CONTACT_EMAIL') && trim((string) APP_OSM_CONTACT_EMAIL) !== '') {
        return 'ManagerAccounting/1.0 (' . trim((string) APP_OSM_CONTACT_EMAIL) . ')';
    }

    return 'ManagerAccounting-GPS/1.0';
}

function app_osm_nominatim_api_key(): string
{
    return defined('APP_OSM_NOMINATIM_API_KEY') ? trim((string) APP_OSM_NOMINATIM_API_KEY) : '';
}

function app_osm_tile_url(): string
{
    if (defined('APP_OSM_TILE_URL') && trim((string) APP_OSM_TILE_URL) !== '') {
        return trim((string) APP_OSM_TILE_URL);
    }

    return 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
}

function app_osm_nominatim_reverse_url(float $lat, float $lng): string
{
    $latS = sprintf('%.7F', $lat);
    $lngS = sprintf('%.7F', $lng);
    $key = app_osm_nominatim_api_key();

    $template = defined('APP_OSM_NOMINATIM_URL') ? trim((string) APP_OSM_NOMINATIM_URL) : '';
    if ($template === '') {
        $template = 'https://nominatim.openstreetmap.org/reverse';
    }

    if (str_contains($template, '{lat}')) {
        return str_replace(
            ['{lat}', '{lng}', '{lon}', '{key}'],
            [$latS, $lngS, $lngS, rawurlencode($key)],
            $template
        );
    }

    $base = rtrim($template, '?&');
    $url = $base . '?format=jsonv2'
        . '&lat=' . rawurlencode($latS)
        . '&lon=' . rawurlencode($lngS)
        . '&accept-language=ar,en'
        . '&zoom=18'
        . '&addressdetails=1'
        . '&namedetails=1'
        . '&extratags=1';
    if ($key !== '') {
        $url .= '&key=' . rawurlencode($key);
    }

    return $url;
}

/** @return array<string, mixed>|null */
function app_osm_nominatim_fetch(float $lat, float $lng): ?array
{
    $url = app_osm_nominatim_reverse_url($lat, $lng);
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => 'User-Agent: ' . app_osm_contact() . "\r\nAccept: application/json\r\n",
            'timeout' => 8,
        ],
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || $raw === '') {
        return null;
    }

    $data = json_decode($raw, true);

    return is_array($data) ? $data : null;
}

/** @return array{tileUrl:string, attribution:string} */
function app_osm_js_config(): array
{
    return [
        'tileUrl' => app_osm_tile_url(),
        'attribution' => '&copy; OpenStreetMap',
    ];
}

/**
 * عنوان خادم OSRM لمطابقة المسار مع الشوارع.
 * يمكن تجاوزه عبر APP_OSRM_BASE_URL (مثال: https://router.project-osrm.org).
 */
function app_osm_osrm_base_url(): string
{
    if (defined('APP_OSRM_BASE_URL') && trim((string) APP_OSRM_BASE_URL) !== '') {
        return rtrim(trim((string) APP_OSRM_BASE_URL), '/');
    }

    return 'https://router.project-osrm.org';
}

/**
 * يحوّل سلسلة نقاط GPS إلى مسار يلتصق بالشوارع التي مُرّ بها فعلياً.
 *
 * مهم: نستخدم مطابقة الأثر (match) وليس توجيه المسار (route).
 * route يخترع أقصر طريق بين نقطتين حتى لو لم يُسلك، وهذا غير مطلوب.
 *
 * @param list<array{latitude?:float|int|string, longitude?:float|int|string, lat?:float|int|string, lng?:float|int|string, lon?:float|int|string}> $points
 * @return list<array{latitude:float, longitude:float}>
 */
function app_osm_snap_route_to_roads(array $points): array
{
    $coords = [];
    $timestamps = [];
    $prevLat = null;
    $prevLng = null;
    foreach ($points as $p) {
        if (!is_array($p)) {
            continue;
        }
        $lat = isset($p['latitude']) ? (float) $p['latitude']
            : (isset($p['lat']) ? (float) $p['lat'] : null);
        $lng = isset($p['longitude']) ? (float) $p['longitude']
            : (isset($p['lng']) ? (float) $p['lng']
                : (isset($p['lon']) ? (float) $p['lon'] : null));
        if ($lat === null || $lng === null) {
            continue;
        }
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            continue;
        }
        if ($prevLat !== null && $prevLng !== null) {
            $dLat = abs($lat - $prevLat);
            $dLng = abs($lng - $prevLng);
            if ($dLat < 0.00005 && $dLng < 0.00005) {
                continue;
            }
        }
        $coords[] = [$lng, $lat];
        $ts = isset($p['ts']) ? (int) $p['ts'] : 0;
        if ($ts < 1 && !empty($p['captured_at'])) {
            $parsed = strtotime((string) $p['captured_at']);
            $ts = $parsed !== false ? $parsed : 0;
        }
        $timestamps[] = $ts > 0 ? $ts : time();
        $prevLat = $lat;
        $prevLng = $lng;
    }

    $n = count($coords);
    if ($n < 2) {
        return [];
    }

    $chunkSize = 80;
    $path = [];
    for ($offset = 0; $offset < $n; $offset += $chunkSize - 1) {
        $slice = array_slice($coords, $offset, $chunkSize);
        $sliceTs = array_slice($timestamps, $offset, $chunkSize);
        if (count($slice) < 2) {
            break;
        }

        // مطابقة الأثر الفعلي فقط — لا نستخدم route لأنه يخترع طريقاً لم يُسلك.
        $part = app_osm_osrm_match_geometry($slice, $sliceTs);
        if ($part === []) {
            $part = [];
            foreach ($slice as $c) {
                $part[] = [
                    'latitude' => (float) $c[1],
                    'longitude' => (float) $c[0],
                ];
            }
        }

        if ($path !== [] && $part !== []) {
            $last = $path[count($path) - 1];
            $first = $part[0];
            if (
                abs($last['latitude'] - $first['latitude']) < 0.00001
                && abs($last['longitude'] - $first['longitude']) < 0.00001
            ) {
                array_shift($part);
            }
        }
        foreach ($part as $pt) {
            $path[] = $pt;
        }

        if ($offset + $chunkSize >= $n) {
            break;
        }
    }

    return $path;
}

/**
 * @param list<array{0:float,1:float}> $lonLatPairs
 * @return list<array{latitude:float, longitude:float}>
 */
function app_osm_osrm_route_geometry(array $lonLatPairs): array
{
    $coordStr = [];
    foreach ($lonLatPairs as $c) {
        $coordStr[] = sprintf('%.6F,%.6F', $c[0], $c[1]);
    }
    $url = app_osm_osrm_base_url()
        . '/route/v1/driving/'
        . implode(';', $coordStr)
        . '?overview=full&geometries=geojson&continue_straight=true';

    $data = app_osm_osrm_fetch_json($url);
    if (!is_array($data) || ($data['code'] ?? '') !== 'Ok') {
        return [];
    }
    $coords = $data['routes'][0]['geometry']['coordinates'] ?? null;
    if (!is_array($coords) || $coords === []) {
        return [];
    }

    return app_osm_osrm_coords_to_latlng($coords);
}

/**
 * @param list<array{0:float,1:float}> $lonLatPairs
 * @param list<int>|null $timestamps Unix seconds aligned with $lonLatPairs
 * @return list<array{latitude:float, longitude:float}>
 */
function app_osm_osrm_match_geometry(array $lonLatPairs, ?array $timestamps = null): array
{
    $coordStr = [];
    $radiuses = [];
    foreach ($lonLatPairs as $c) {
        $coordStr[] = sprintf('%.6F,%.6F', $c[0], $c[1]);
        // نصف قطر معتدل: التصاق أدق بالشارع دون سحب المسار لطريق مجاور.
        $radiuses[] = '25';
    }
    $url = app_osm_osrm_base_url()
        . '/match/v1/driving/'
        . implode(';', $coordStr)
        . '?overview=full&geometries=geojson&tidy=true&radiuses='
        . implode(';', $radiuses);

    if (is_array($timestamps) && count($timestamps) === count($lonLatPairs) && count($timestamps) >= 2) {
        $url .= '&timestamps=' . implode(';', array_map('intval', $timestamps));
    }

    $data = app_osm_osrm_fetch_json($url);
    if (!is_array($data) || ($data['code'] ?? '') !== 'Ok') {
        return [];
    }

    $matchings = $data['matchings'] ?? [];
    if (!is_array($matchings) || $matchings === []) {
        return [];
    }
    $out = [];
    foreach ($matchings as $m) {
        $coords = $m['geometry']['coordinates'] ?? null;
        if (!is_array($coords)) {
            continue;
        }
        foreach (app_osm_osrm_coords_to_latlng($coords) as $pt) {
            $out[] = $pt;
        }
    }

    return $out;
}

/**
 * @param list<array{0:float|int|string,1:float|int|string}> $coords GeoJSON [lon,lat]
 * @return list<array{latitude:float, longitude:float}>
 */
function app_osm_osrm_coords_to_latlng(array $coords): array
{
    $out = [];
    foreach ($coords as $c) {
        if (!is_array($c) || count($c) < 2) {
            continue;
        }
        $out[] = [
            'latitude' => (float) $c[1],
            'longitude' => (float) $c[0],
        ];
    }

    return $out;
}

/** @return array<string, mixed>|null */
function app_osm_osrm_fetch_json(string $url): ?array
{
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => 'User-Agent: ' . app_osm_contact() . "\r\nAccept: application/json\r\n",
            'timeout' => 12,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);

    return is_array($data) ? $data : null;
}
