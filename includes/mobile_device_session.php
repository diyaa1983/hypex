<?php
declare(strict_types=1);

require_once app_path('includes/date_defaults.php');
require_once app_path('includes/sal_invoice_gps.php');

/**
 * قفل جهاز واحد لكل مستخدم في تطبيق الهاتف — منع الدخول من جهاز ثانٍ أثناء النشاط.
 */
const MOBILE_DEVICE_ACTIVE_SECONDS = 120;

function mobile_device_session_now_sql(): string
{
    app_apply_timezone();

    return date('Y-m-d H:i:s');
}

function mobile_device_session_cutoff_sql(): string
{
    app_apply_timezone();

    return date('Y-m-d H:i:s', time() - max(30, MOBILE_DEVICE_ACTIVE_SECONDS));
}

function mobile_device_session_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    if (!sal_invoice_column_exists($pdo, 'sys_user_mobile_device_lock', 'user_id')) {
        try {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS sys_user_mobile_device_lock (
                    user_id INT UNSIGNED NOT NULL,
                    device_id VARCHAR(64) NOT NULL,
                    device_label VARCHAR(120) NULL DEFAULT NULL,
                    heartbeat_at DATETIME NOT NULL,
                    PRIMARY KEY (user_id),
                    KEY idx_mobile_lock_heartbeat (heartbeat_at),
                    CONSTRAINT fk_mobile_lock_user FOREIGN KEY (user_id) REFERENCES sys_user(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (Throwable $e) {
            error_log('mobile_device_session_ensure_schema lock: ' . $e->getMessage());
        }
    }

    // جدول قديم — يُحدَّث للتوافق فقط.
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
            error_log('mobile_device_session_ensure_schema presence: ' . $e->getMessage());
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

/**
 * @return array{error:string, message:string}|null
 */
function mobile_device_session_require_id(): ?array
{
    if (mobile_device_session_id_from_request() !== '') {
        return null;
    }

    return [
        'error' => 'device_id_required',
        'message' => 'معرّف الجهاز مفقود. حدّث التطبيق إلى آخر إصدار.',
    ];
}

/**
 * @return array{active:bool, other_count:int, message:string, device_label?:string}|null
 */
function mobile_device_session_blocking_conflict(PDO $pdo, int $userId, string $deviceId): ?array
{
    if ($userId < 1 || $deviceId === '') {
        return null;
    }

    mobile_device_session_ensure_schema($pdo);
    $cutoff = mobile_device_session_cutoff_sql();

    $st = $pdo->prepare(
        'SELECT device_id, device_label, heartbeat_at
         FROM sys_user_mobile_device_lock
         WHERE user_id = ?
         LIMIT 1'
    );
    $st->execute([$userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }

    $lockedId = trim((string) ($row['device_id'] ?? ''));
    if ($lockedId === '' || $lockedId === $deviceId) {
        return null;
    }

    $seen = trim((string) ($row['heartbeat_at'] ?? ''));
    if ($seen === '' || $seen < $cutoff) {
        return null;
    }

    $label = trim((string) ($row['device_label'] ?? ''));
    $seenHi = app_format_time_hi($seen);

    $msg = 'هذا الحساب مستخدم حالياً على جهاز آخر. أغلِق التطبيق على ذلك الجهاز أو انتظر دقيقتين ثم حاول مجدداً.';
    if ($label !== '') {
        $msg .= ' الجهاز النشط: ' . $label;
    }
    if ($seenHi !== '') {
        $msg .= ' (آخر نشاط ' . $seenHi . ')';
    }

    return [
        'active' => true,
        'other_count' => 1,
        'message' => $msg,
        'device_label' => $label !== '' ? $label : null,
    ];
}

/**
 * يحجز الجهاز الحالي للمستخدم (مع قفل صفّي لمنع سباق الدخول المتزامن).
 */
function mobile_device_session_claim(
    PDO $pdo,
    int $userId,
    string $deviceId,
    ?string $deviceLabel = null,
    ?float $latitude = null,
    ?float $longitude = null
): bool {
    if ($userId < 1 || $deviceId === '') {
        return false;
    }

    mobile_device_session_ensure_schema($pdo);
    $now = mobile_device_session_now_sql();
    $cutoff = mobile_device_session_cutoff_sql();
    $label = $deviceLabel !== null ? trim($deviceLabel) : '';
    if ($label === '') {
        $label = null;
    } elseif (strlen($label) > 120) {
        $label = substr($label, 0, 120);
    }

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            'SELECT device_id, heartbeat_at
             FROM sys_user_mobile_device_lock
             WHERE user_id = ?
             FOR UPDATE'
        );
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (is_array($row)) {
            $lockedId = trim((string) ($row['device_id'] ?? ''));
            $seen = trim((string) ($row['heartbeat_at'] ?? ''));
            if ($lockedId !== '' && $lockedId !== $deviceId && $seen !== '' && $seen >= $cutoff) {
                $pdo->rollBack();

                return false;
            }
        }

        $pdo->prepare(
            'INSERT INTO sys_user_mobile_device_lock (user_id, device_id, device_label, heartbeat_at)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                device_id = VALUES(device_id),
                device_label = COALESCE(VALUES(device_label), device_label),
                heartbeat_at = VALUES(heartbeat_at)'
        )->execute([$userId, $deviceId, $label, $now]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('mobile_device_session_claim: ' . $e->getMessage());

        return false;
    }

    mobile_device_session_touch_presence($pdo, $userId, $deviceId, $label, $latitude, $longitude);

    return true;
}

function mobile_device_session_touch_presence(
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

    $upd = $pdo->prepare(
        'UPDATE sys_user_mobile_device_lock
         SET heartbeat_at = ?, device_label = COALESCE(?, device_label)
         WHERE user_id = ? AND device_id = ?'
    );
    $upd->execute([$now, $label, $userId, $deviceId]);

    if ($upd->rowCount() === 0) {
        if (!mobile_device_session_claim($pdo, $userId, $deviceId, $label, $latitude, $longitude)) {
            return;
        }

        return;
    }

    mobile_device_session_touch_presence($pdo, $userId, $deviceId, $label, $latitude, $longitude);

    if (mt_rand(1, 80) === 1) {
        try {
            $old = mobile_device_session_cutoff_sql();
            $pdo->prepare('DELETE FROM sys_user_mobile_device_lock WHERE heartbeat_at < ?')->execute([$old]);
            $pdo->prepare('DELETE FROM sys_user_device_presence WHERE last_seen_at < ?')->execute([$old]);
        } catch (Throwable $e) {
            error_log('mobile_device_session cleanup: ' . $e->getMessage());
        }
    }
}

function mobile_device_session_release(PDO $pdo, int $userId, string $deviceId): void
{
    if ($userId < 1 || $deviceId === '') {
        return;
    }

    mobile_device_session_ensure_schema($pdo);
    try {
        $pdo->prepare(
            'DELETE FROM sys_user_mobile_device_lock WHERE user_id = ? AND device_id = ?'
        )->execute([$userId, $deviceId]);
        $pdo->prepare(
            'DELETE FROM sys_user_device_presence WHERE user_id = ? AND device_id = ?'
        )->execute([$userId, $deviceId]);
    } catch (Throwable $e) {
        error_log('mobile_device_session_release: ' . $e->getMessage());
    }
}

/** @deprecated */
function mobile_device_session_concurrent_warning(PDO $pdo, int $userId, string $deviceId): ?array
{
    return mobile_device_session_blocking_conflict($pdo, $userId, $deviceId);
}
