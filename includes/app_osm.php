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
 * يحوّل سلسلة نقاط GPS إلى مسار يلتصق بالشوارع (مثل Google Maps).
 *
 * الترتيب:
 * 1) مطابقة الأثر OSRM match
 * 2) إن فشلت: توجيه متتابع عبر نقاط GPS كمحطات (route) ليلتصق بالشارع
 *
 * @param list<array{latitude?:float|int|string, longitude?:float|int|string, lat?:float|int|string, lng?:float|int|string, lon?:float|int|string, ts?:int, captured_at?:string, gps_accuracy?:float|int|null}> $points
 * @return list<array{latitude:float, longitude:float}>
 */
function app_osm_snap_route_to_roads(array $points): array
{
    $coords = [];
    $timestamps = [];
    $accuracies = [];
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
        // تخفيف الكثافة قبل الإرسال لـ OSRM (~12م) مع الإبقاء على شكل الحركة.
        if ($prevLat !== null && $prevLng !== null) {
            if (app_osm_haversine_meters($prevLat, $prevLng, $lat, $lng) < 12.0) {
                continue;
            }
        }
        $coords[] = [$lng, $lat];
        $ts = isset($p['ts']) ? (int) $p['ts'] : 0;
        if ($ts < 1 && !empty($p['captured_at'])) {
            $parsed = strtotime((string) $p['captured_at']);
            $ts = $parsed !== false ? $parsed : 0;
        }
        $timestamps[] = $ts > 0 ? $ts : 0;
        $acc = isset($p['gps_accuracy']) && is_numeric($p['gps_accuracy'])
            ? (float) $p['gps_accuracy']
            : 40.0;
        $accuracies[] = max(20.0, min(75.0, $acc > 0 ? $acc : 40.0));
        $prevLat = $lat;
        $prevLng = $lng;
    }

    $n = count($coords);
    if ($n < 2) {
        return [];
    }

    // توجيه عبر المحطات أولاً — يلتصق بمركز الشارع مثل Google Maps.
    // المطابقة (match) احتياطية إن فشل التوجيه.
    $routed = app_osm_osrm_route_through_waypoints($coords);
    if (count($routed) >= 2) {
        return $routed;
    }

    $matched = app_osm_osrm_match_geometry($coords, $timestamps, $accuracies);
    if (count($matched) >= 2) {
        return $matched;
    }

    return [];
}

function app_osm_haversine_meters(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r = 6371000.0;
    $p1 = deg2rad($lat1);
    $p2 = deg2rad($lat2);
    $dp = deg2rad($lat2 - $lat1);
    $dl = deg2rad($lng2 - $lng1);
    $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;

    return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/**
 * توجيه OSRM عبر نقاط GPS كمحطات بالترتيب ثم لصق الأشكال.
 *
 * @param list<array{0:float,1:float}> $lonLatPairs
 * @return list<array{latitude:float, longitude:float}>
 */
function app_osm_osrm_route_through_waypoints(array $lonLatPairs): array
{
    $n = count($lonLatPairs);
    if ($n < 2) {
        return [];
    }

    // محطات كل ~45م تقريباً حتى لا يزدحم الطلب وتبقى الدقة عالية.
    $waypoints = [$lonLatPairs[0]];
    $last = $lonLatPairs[0];
    for ($i = 1; $i < $n - 1; $i++) {
        $c = $lonLatPairs[$i];
        if (app_osm_haversine_meters($last[1], $last[0], $c[1], $c[0]) >= 45.0) {
            $waypoints[] = $c;
            $last = $c;
        }
    }
    $waypoints[] = $lonLatPairs[$n - 1];

    $chunkSize = 40;
    $path = [];
    $wN = count($waypoints);
    for ($offset = 0; $offset < $wN; $offset += $chunkSize - 1) {
        $slice = array_slice($waypoints, $offset, $chunkSize);
        if (count($slice) < 2) {
            break;
        }
        $part = app_osm_osrm_route_geometry($slice);
        if ($part === []) {
            // إن فشل مقطع طويل جرّب أزواج متتالية.
            $part = [];
            for ($i = 1; $i < count($slice); $i++) {
                $leg = app_osm_osrm_route_geometry([$slice[$i - 1], $slice[$i]]);
                if ($leg === []) {
                    $part[] = [
                        'latitude' => (float) $slice[$i - 1][1],
                        'longitude' => (float) $slice[$i - 1][0],
                    ];
                    $part[] = [
                        'latitude' => (float) $slice[$i][1],
                        'longitude' => (float) $slice[$i][0],
                    ];
                    continue;
                }
                if ($part !== [] && $leg !== []) {
                    array_shift($leg);
                }
                foreach ($leg as $pt) {
                    $part[] = $pt;
                }
            }
        }

        if ($path !== [] && $part !== []) {
            $lastPt = $path[count($path) - 1];
            $firstPt = $part[0];
            if (
                abs($lastPt['latitude'] - $firstPt['latitude']) < 0.00002
                && abs($lastPt['longitude'] - $firstPt['longitude']) < 0.00002
            ) {
                array_shift($part);
            }
        }
        foreach ($part as $pt) {
            $path[] = $pt;
        }

        if ($offset + $chunkSize >= $wN) {
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
    if (count($lonLatPairs) < 2) {
        return [];
    }
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
 * @param list<float>|null $accuracies meters per point
 * @return list<array{latitude:float, longitude:float}>
 */
function app_osm_osrm_match_geometry(
    array $lonLatPairs,
    ?array $timestamps = null,
    ?array $accuracies = null
): array {
    if (count($lonLatPairs) < 2) {
        return [];
    }

    // جرّب بدون timestamps أولاً — الخادم العام غالباً يرفض صيغ radiuses/timestamps.
    $attempts = [];
    $attempts[] = ['timestamps' => false, 'radiuses' => false];
    $attempts[] = ['timestamps' => false, 'radiuses' => true];
    if (is_array($timestamps) && count($timestamps) === count($lonLatPairs)) {
        $validTs = true;
        foreach ($timestamps as $t) {
            if ((int) $t < 1) {
                $validTs = false;
                break;
            }
        }
        if ($validTs) {
            $attempts[] = ['timestamps' => true, 'radiuses' => false];
        }
    }

    foreach ($attempts as $opt) {
        $coordStr = [];
        foreach ($lonLatPairs as $c) {
            $coordStr[] = sprintf('%.6F,%.6F', $c[0], $c[1]);
        }
        $url = app_osm_osrm_base_url()
            . '/match/v1/driving/'
            . implode(';', $coordStr)
            . '?overview=full&geometries=geojson&gaps=ignore';

        if (!empty($opt['radiuses'])) {
            $radiuses = [];
            foreach ($lonLatPairs as $i => $c) {
                $r = is_array($accuracies) && isset($accuracies[$i])
                    ? (float) $accuracies[$i]
                    : 50.0;
                $radiuses[] = (string) max(20, min(75, (int) round($r)));
            }
            $url .= '&radiuses=' . implode(';', $radiuses);
        }

        if (!empty($opt['timestamps']) && is_array($timestamps)) {
            $url .= '&timestamps=' . implode(';', array_map('intval', $timestamps));
        }

        $data = app_osm_osrm_fetch_json($url);
        if (!is_array($data) || ($data['code'] ?? '') !== 'Ok') {
            continue;
        }

        $matchings = $data['matchings'] ?? [];
        if (!is_array($matchings) || $matchings === []) {
            continue;
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
        if (count($out) >= 2) {
            return $out;
        }
    }

    return [];
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
    $raw = app_osm_osrm_http_get($url);
    if ($raw === null || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);

    return is_array($data) ? $data : null;
}

/**
 * جلب HTTPS لـ OSRM — يدعم curl / file_get_contents / PowerShell (XAMPP بدون openssl).
 */
function app_osm_osrm_http_get(string $url): ?string
{
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return null;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch !== false) {
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 25,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'User-Agent: ' . app_osm_contact(),
                ],
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (is_string($body) && $body !== '' && $code >= 200 && $code < 300) {
                return $body;
            }
        }
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => 'User-Agent: ' . app_osm_contact() . "\r\nAccept: application/json\r\n",
            'timeout' => 25,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if (is_string($body) && $body !== '' && ($body[0] === '{' || $body[0] === '[')) {
        return $body;
    }

    // XAMPP على Windows غالباً بلا openssl/curl — PowerShell يصل لـ HTTPS.
    if (stripos(PHP_OS, 'WIN') === 0) {
        $psBody = app_osm_osrm_http_get_powershell($url);
        if (is_string($psBody) && $psBody !== '') {
            return $psBody;
        }
    }

    return null;
}

function app_osm_osrm_http_get_powershell(string $url): ?string
{
    $ps = getenv('SystemRoot');
    $exe = (is_string($ps) && $ps !== '' ? $ps : 'C:\\Windows')
        . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
    if (!is_file($exe)) {
        $exe = 'powershell.exe';
    }

    $script = '$ProgressPreference=\'SilentlyContinue\';'
        . ' try {'
        . ' $r=Invoke-WebRequest -Uri $env:OSRM_URL -UseBasicParsing -TimeoutSec 25;'
        . ' [Console]::Out.Write($r.Content)'
        . ' } catch { }';

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $env = array_merge($_ENV, [
        'OSRM_URL' => $url,
    ]);
    // putenv for child process reliability on some PHP builds
    putenv('OSRM_URL=' . $url);

    $cmd = escapeshellarg($exe)
        . ' -NoProfile -NonInteractive -ExecutionPolicy Bypass -Command '
        . escapeshellarg($script);

    $proc = @proc_open($cmd, $descriptors, $pipes, null, null);
    if (!is_resource($proc)) {
        return null;
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    proc_close($proc);

    if (!is_string($out) || $out === '') {
        return null;
    }
    $out = trim($out);
    if ($out === '' || ($out[0] !== '{' && $out[0] !== '[')) {
        return null;
    }

    return $out;
}
