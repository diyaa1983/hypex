<?php
declare(strict_types=1);

require_once app_path('includes/date_defaults.php');
require_once app_path('includes/sal_invoice_gps.php');

/**
 * تتبّع الأجهزة النشطة لكل مستخدم (تطبيق الهاتف) — تنبيه عند فتح الحساب من أكثر من جهاز.
 */
const MOBILE_DEVICE_ACTIVE_SECONDS = 120;

function mobile_device_session_now_sql(): string
{
    app_apply_timezone();

    return date('Y-m-d H:i:s');
}

function mobile_device_session_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    if (!sal_invoice_column_exists($pdo, 'sys_user_device_presence', 'user_id')) {
        try {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS sys_user_device_presence (
                    user_id INT UNSIGNED NOT NULL,
                    device_id VARCHAR(64) NOT NULL,
                    device_label VARCHAR(120) NULL DEFAULT NULL,
                    last_seen_at DATETIME NOT NULL,
                    last_latitude DECIMAL(10, 7) NULL DEFAULT NULL,
                    last_longitude DECIMAL(10, 7) NULL DEFAULT NULL,
                    PRIMARY KEY (user_id, device_id),
                    KEY idx_device_last_seen (last_seen_at),
                    CONSTRAINT fk_device_presence_user FOREIGN KEY (user_id) REFERENCES sys_user(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (Throwable $e) {
            error_log('mobile_device_session_ensure_schema: ' . $e->getMessage());
        }
    }

    $done = true;
}

function mobile_device_session_id_from_request(): string
{
    $id = trim((string) ($_POST['device_id'] ?? $_GET['device_id'] ?? ''));
    if ($id === '') {
        $id = trim((string) ($_SERVER['HTTP_X_DEVICE_ID'] ?? ''));
    }
    $id = preg_replace('/[^a-zA-Z0-9._-]/', '', $id) ?? '';

    return substr($id, 0, 64);
}

function mobile_device_session_touch(
    PDO $pdo,
    int $userId,
    string $deviceId,
    ?string $deviceLabel = null,
    ?float $latitude = null,
    ?float $longitude = null
): void {
    if ($userId < 1 || $deviceId === '') {
        return;
    }

    mobile_device_session_ensure_schema($pdo);
    $now = mobile_device_session_now_sql();
    $label = $deviceLabel !== null ? trim($deviceLabel) : '';
    if ($label === '') {
        $label = null;
    } elseif (strlen($label) > 120) {
        $label = substr($label, 0, 120);
    }

    $lat = ($latitude !== null && sal_invoice_gps_coords_valid($latitude, $longitude ?? 0.0))
        ? round($latitude, 7)
        : null;
    $lng = ($longitude !== null && sal_invoice_gps_coords_valid($latitude ?? 0.0, $longitude))
        ? round($longitude, 7)
        : null;

    $pdo->prepare(
        'INSERT INTO sys_user_device_presence (user_id, device_id, device_label, last_seen_at, last_latitude, last_longitude)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            device_label = COALESCE(VALUES(device_label), device_label),
            last_seen_at = VALUES(last_seen_at),
            last_latitude = COALESCE(VALUES(last_latitude), last_latitude),
            last_longitude = COALESCE(VALUES(last_longitude), last_longitude)'
    )->execute([$userId, $deviceId, $label, $now, $lat, $lng]);

    // تنظيف خفيف للأجهزة القديمة.
    if (mt_rand(1, 50) === 1) {
        try {
            $pdo->exec(
                'DELETE FROM sys_user_device_presence WHERE last_seen_at < DATE_SUB(NOW(), INTERVAL 14 DAY)'
            );
        } catch (Throwable $e) {
            error_log('mobile_device_session cleanup: ' . $e->getMessage());
        }
    }
}

/**
 * @return array{active:bool, other_count:int, message:string}|null
 */
function mobile_device_session_concurrent_warning(PDO $pdo, int $userId, string $deviceId): ?array
{
    if ($userId < 1 || $deviceId === '') {
        return null;
    }

    mobile_device_session_ensure_schema($pdo);
    $window = max(30, MOBILE_DEVICE_ACTIVE_SECONDS);
    $st = $pdo->prepare(
        'SELECT device_id, device_label, last_seen_at
         FROM sys_user_device_presence
         WHERE user_id = ?
           AND device_id <> ?
           AND last_seen_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
         ORDER BY last_seen_at DESC
         LIMIT 5'
    );
    $st->execute([$userId, $deviceId, $window]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($rows === []) {
        return null;
    }

    $count = count($rows);
    $latest = $rows[0];
    $label = trim((string) ($latest['device_label'] ?? ''));
    $seen = trim((string) ($latest['last_seen_at'] ?? ''));
    $seenHi = $seen !== '' ? app_format_time_hi($seen) : '';

    $msg = 'تنبيه: هذا الحساب مفتوح حالياً على جهاز آخر'
        . ($count > 1 ? " ($count أجهزة)" : '')
        . '. قد يؤثر ذلك على دقة تتبّع الموقع وخط السير.';
    if ($label !== '') {
        $msg .= ' آخر جهاز نشط: ' . $label;
    }
    if ($seenHi !== '') {
        $msg .= ' (آخر نشاط ' . $seenHi . ')';
    }
    $msg .= ' يُفضّل استخدام جهاز واحد فقط أثناء التتبّع.';

    return [
        'active' => true,
        'other_count' => $count,
        'message' => $msg,
    ];
}
