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
