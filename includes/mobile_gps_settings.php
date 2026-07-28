<?php
declare(strict_types=1);

require_once app_path('includes/app_gps.php');

/** @return array{auto_enable:bool,interval_sec:int,min_distance_m:int,user_can_disable:bool,enabled:bool} */
function mobile_gps_settings_defaults(): array
{
    return [
        'auto_enable' => true,
        'interval_sec' => 10,
        'min_distance_m' => 0,
        'user_can_disable' => false,
        'enabled' => true,
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

/** @return array{auto_enable:bool,interval_sec:int,min_distance_m:int,user_can_disable:bool,enabled:bool} */
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
                    gps_google_maps_api_key
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
        }
    } catch (Throwable $e) {
        error_log('mobile_gps_settings: ' . $e->getMessage());
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

function mobile_gps_settings_save(PDO $pdo, array $input): void
{
    mobile_gps_settings_ensure_schema($pdo);
    $auto = !empty($input['auto_enable']) ? 1 : 0;
    $interval = mobile_gps_settings_normalize_interval((int) ($input['interval_sec'] ?? 10));
    $distance = mobile_gps_settings_normalize_distance((int) ($input['min_distance_m'] ?? 0));
    $canDisable = !empty($input['user_can_disable']) ? 1 : 0;
    $googleKey = trim((string) ($input['google_maps_api_key'] ?? ''));

    $pdo->prepare(
        'UPDATE sys_company_settings SET
            gps_mobile_auto_enable = ?,
            gps_mobile_interval_sec = ?,
            gps_mobile_min_distance_m = ?,
            gps_mobile_user_can_disable = ?,
            gps_google_maps_api_key = ?
         WHERE id = 1'
    )->execute([$auto, $interval, $distance, $canDisable, $googleKey]);
}
