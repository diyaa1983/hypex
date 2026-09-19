<?php
declare(strict_types=1);

require_once app_path('includes/app_gps.php');

/** @return array{auto_enable:bool,interval_sec:int,min_distance_m:int,user_can_disable:bool,enabled:bool,rep_visit_geofence:bool,visit_radius_m:int} */
function mobile_gps_settings_defaults(): array
{
    return [
        'auto_enable' => true,
        'interval_sec' => 10,
        'min_distance_m' => 0,
        'user_can_disable' => false,
        'enabled' => true,
        'rep_visit_geofence' => false,
        'visit_radius_m' => 200,
    ];
}

function mobile_gps_settings_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $columns = [
        'gps_mobile_auto_enable' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'gps_mobile_interval_sec' => 'INT UNSIGNED NOT NULL DEFAULT 10',
        'gps_mobile_min_distance_m' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'gps_mobile_user_can_disable' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'gps_google_maps_api_key' => "VARCHAR(255) NOT NULL DEFAULT ''",
        'gps_map_provider' => "VARCHAR(16) NOT NULL DEFAULT 'esri'",
        'gps_map_engine' => "VARCHAR(16) NOT NULL DEFAULT 'leaflet'",
        'sales_rep_visit_geofence' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'sales_rep_visit_radius_m' => 'INT UNSIGNED NOT NULL DEFAULT 200',
    ];

    foreach ($columns as $col => $def) {
        try {
            $pdo->query('SELECT `' . $col . '` FROM sys_company_settings LIMIT 1');
        } catch (Throwable $e) {
            if (strpos($e->getMessage(), 'Unknown column') === false) {
                continue;
            }
            try {
                $pdo->exec('ALTER TABLE sys_company_settings ADD COLUMN `' . $col . '` ' . $def);
            } catch (Throwable $e2) {
                error_log('mobile_gps_settings_ensure_schema: ' . $e2->getMessage());
            }
        }
    }
}

/** @return array{auto_enable:bool,interval_sec:int,min_distance_m:int,user_can_disable:bool,enabled:bool,rep_visit_geofence:bool,visit_radius_m:int} */
function mobile_gps_settings(PDO $pdo = null): array
{
    $defaults = mobile_gps_settings_defaults();
    $defaults['enabled'] = app_gps_enabled();

    try {
        $pdo = $pdo ?? db();
        mobile_gps_settings_ensure_schema($pdo);
        $row = $pdo->query(
            'SELECT gps_mobile_auto_enable, gps_mobile_interval_sec,
                    gps_mobile_min_distance_m, gps_mobile_user_can_disable,
                    gps_google_maps_api_key, sales_rep_visit_geofence,
                    sales_rep_visit_radius_m
             FROM sys_company_settings WHERE id = 1 LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $defaults['auto_enable'] = (int) ($row['gps_mobile_auto_enable'] ?? 1) === 1;
            $defaults['interval_sec'] = mobile_gps_settings_normalize_interval(
                (int) ($row['gps_mobile_interval_sec'] ?? 10)
            );
            $defaults['min_distance_m'] = mobile_gps_settings_normalize_distance(
                (int) ($row['gps_mobile_min_distance_m'] ?? 0)
            );
            $defaults['user_can_disable'] = (int) ($row['gps_mobile_user_can_disable'] ?? 0) === 1;
            $defaults['rep_visit_geofence'] = (int) ($row['sales_rep_visit_geofence'] ?? 0) === 1;
            $defaults['visit_radius_m'] = mobile_gps_settings_normalize_visit_radius(
                (int) ($row['sales_rep_visit_radius_m'] ?? 200)
            );
        }
    } catch (Throwable $e) {
        // عمود نصف القطر قد لا يكون موجوداً بعد — حاول بدونها
        try {
            $pdo = $pdo ?? db();
            $row = $pdo->query(
                'SELECT gps_mobile_auto_enable, gps_mobile_interval_sec,
                        gps_mobile_min_distance_m, gps_mobile_user_can_disable,
                        sales_rep_visit_geofence
                 FROM sys_company_settings WHERE id = 1 LIMIT 1'
            )->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $defaults['auto_enable'] = (int) ($row['gps_mobile_auto_enable'] ?? 1) === 1;
                $defaults['interval_sec'] = mobile_gps_settings_normalize_interval(
                    (int) ($row['gps_mobile_interval_sec'] ?? 10)
                );
                $defaults['min_distance_m'] = mobile_gps_settings_normalize_distance(
                    (int) ($row['gps_mobile_min_distance_m'] ?? 0)
                );
                $defaults['user_can_disable'] = (int) ($row['gps_mobile_user_can_disable'] ?? 0) === 1;
                $defaults['rep_visit_geofence'] = (int) ($row['sales_rep_visit_geofence'] ?? 0) === 1;
            }
        } catch (Throwable $e2) {
            error_log('mobile_gps_settings: ' . $e2->getMessage());
        }
    }

    return $defaults;
}

function mobile_gps_settings_normalize_interval(int $seconds): int
{
    $allowed = [10, 15, 30, 60, 120, 300];
    if (in_array($seconds, $allowed, true)) {
        return $seconds;
    }

    return max(10, min(300, $seconds));
}

function mobile_gps_settings_normalize_distance(int $meters): int
{
    $allowed = [0, 15, 30, 50, 100];
    if (in_array($meters, $allowed, true)) {
        return $meters;
    }

    return max(0, min(500, $meters));
}

/** حدود منطقة العميل: 10–5000 م (افتراضي 200). */
function mobile_gps_settings_normalize_visit_radius(int $meters): int
{
    if ($meters < 10) {
        return 10;
    }
    if ($meters > 5000) {
        return 5000;
    }

    return $meters;
}

/** إعدادات تُرسل لتطبيق الهاتف مع الجلسة. */
function mobile_gps_settings_for_app(?PDO $pdo = null): array
{
    $s = mobile_gps_settings($pdo);

    return [
        'enabled' => $s['enabled'],
        'auto_enable' => $s['enabled'] && $s['auto_enable'],
        'interval_sec' => $s['interval_sec'],
        'min_distance_m' => $s['min_distance_m'],
        'user_can_disable' => $s['user_can_disable'],
        'rep_visit_geofence' => !empty($s['rep_visit_geofence']),
        'visit_radius_m' => (int) ($s['visit_radius_m'] ?? 200),
    ];
}

function mobile_gps_settings_interval_label(int $seconds): string
{
    return match ($seconds) {
        10 => 'كل 10 ثوانٍ',
        15 => 'كل 15 ثانية',
        30 => 'كل 30 ثانية',
        60 => 'كل دقيقة',
        120 => 'كل دقيقتين',
        300 => 'كل 5 دقائق',
        default => 'كل ' . $seconds . ' ثانية',
    };
}

function mobile_gps_settings_distance_label(int $meters): string
{
    return match ($meters) {
        0 => 'دائماً (بدون شرط مسافة)',
        15 => 'بعد 15 متر',
        30 => 'بعد 30 متر',
        50 => 'بعد 50 متر',
        100 => 'بعد 100 متر',
        default => 'بعد ' . $meters . ' متر',
    };
}

function mobile_gps_settings_google_maps_key(PDO $pdo = null): string
{
    try {
        $pdo = $pdo ?? db();
        mobile_gps_settings_ensure_schema($pdo);
        $row = $pdo->query(
            'SELECT gps_google_maps_api_key FROM sys_company_settings WHERE id = 1 LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return trim((string) ($row['gps_google_maps_api_key'] ?? ''));
        }
    } catch (Throwable $e) {
        error_log('mobile_gps_settings_google_maps_key: ' . $e->getMessage());
    }

    return '';
}

function mobile_gps_settings_map_engine(PDO $pdo = null): string
{
    $allowed = ['leaflet', 'arcgis'];
    try {
        $pdo = $pdo ?? db();
        mobile_gps_settings_ensure_schema($pdo);
        $row = $pdo->query(
            'SELECT gps_map_engine FROM sys_company_settings WHERE id = 1 LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $p = strtolower(trim((string) ($row['gps_map_engine'] ?? '')));
            if (in_array($p, $allowed, true)) {
                return $p;
            }
        }
    } catch (Throwable $e) {
        error_log('mobile_gps_settings_map_engine: ' . $e->getMessage());
    }

    return 'leaflet';
}

function mobile_gps_settings_map_provider(PDO $pdo = null): string
{
    $allowed = ['esri', 'natgeo', 'carto', 'google'];
    try {
        $pdo = $pdo ?? db();
        mobile_gps_settings_ensure_schema($pdo);
        $row = $pdo->query(
            'SELECT gps_map_provider FROM sys_company_settings WHERE id = 1 LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $p = strtolower(trim((string) ($row['gps_map_provider'] ?? '')));
            if (in_array($p, $allowed, true)) {
                return $p;
            }
        }
    } catch (Throwable $e) {
        error_log('mobile_gps_settings_map_provider: ' . $e->getMessage());
    }

    return 'esri';
}

function mobile_gps_settings_save(PDO $pdo, array $input): void
{
    mobile_gps_settings_ensure_schema($pdo);
    $auto = !empty($input['auto_enable']) ? 1 : 0;
    $interval = mobile_gps_settings_normalize_interval((int) ($input['interval_sec'] ?? 10));
    $distance = mobile_gps_settings_normalize_distance((int) ($input['min_distance_m'] ?? 0));
    $canDisable = !empty($input['user_can_disable']) ? 1 : 0;
    $repVisitGeofence = !empty($input['rep_visit_geofence']) ? 1 : 0;
    $visitRadius = mobile_gps_settings_normalize_visit_radius((int) ($input['visit_radius_m'] ?? 200));
    $googleKey = trim((string) ($input['google_maps_api_key'] ?? ''));
    $provider = strtolower(trim((string) ($input['map_provider'] ?? 'esri')));
    if (!in_array($provider, ['esri', 'natgeo', 'carto', 'google'], true)) {
        $provider = 'esri';
    }
    $engine = strtolower(trim((string) ($input['map_engine'] ?? 'leaflet')));
    if (!in_array($engine, ['leaflet', 'arcgis'], true)) {
        $engine = 'leaflet';
    }

    try {
        $pdo->prepare(
            'UPDATE sys_company_settings SET
                gps_mobile_auto_enable = ?,
                gps_mobile_interval_sec = ?,
                gps_mobile_min_distance_m = ?,
                gps_mobile_user_can_disable = ?,
                sales_rep_visit_geofence = ?,
                sales_rep_visit_radius_m = ?,
                gps_google_maps_api_key = ?,
                gps_map_provider = ?,
                gps_map_engine = ?
             WHERE id = 1'
        )->execute([
            $auto,
            $interval,
            $distance,
            $canDisable,
            $repVisitGeofence,
            $visitRadius,
            $googleKey,
            $provider,
            $engine,
        ]);
    } catch (Throwable $e) {
        // توافق إن لم يُرحّل عمود نصف القطر بعد
        $pdo->prepare(
            'UPDATE sys_company_settings SET
                gps_mobile_auto_enable = ?,
                gps_mobile_interval_sec = ?,
                gps_mobile_min_distance_m = ?,
                gps_mobile_user_can_disable = ?,
                sales_rep_visit_geofence = ?,
                gps_google_maps_api_key = ?,
                gps_map_provider = ?,
                gps_map_engine = ?
             WHERE id = 1'
        )->execute([
            $auto,
            $interval,
            $distance,
            $canDisable,
            $repVisitGeofence,
            $googleKey,
            $provider,
            $engine,
        ]);
    }
}
