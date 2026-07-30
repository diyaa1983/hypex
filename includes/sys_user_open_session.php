<?php
declare(strict_types=1);

require_once app_path('includes/date_defaults.php');
require_once app_path('includes/sal_invoice_gps.php');

/** مهلة اعتبار جلسة Windows نشطة (ثوانٍ). */
const OPEN_SESSION_WINDOWS_ACTIVE_SECONDS = 1800;
/** مهلة اعتبار جلسة الهاتف نشطة (ثوانٍ). */
const OPEN_SESSION_MOBILE_ACTIVE_SECONDS = 180;

function sys_user_open_session_now(): string
{
    app_apply_timezone();

    return date('Y-m-d H:i:s');
}

function sys_user_open_session_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo->query('SELECT id FROM sys_user_open_session LIMIT 1');
    } catch (Throwable $e) {
        if (strpos($e->getMessage(), 'exist') === false && strpos($e->getMessage(), "doesn't exist") === false) {
            // قد يكون الجدول موجوداً بأسماء أخرى — نحاول الإنشاء دائماً عند الفشل
        }
        try {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS sys_user_open_session (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id INT UNSIGNED NOT NULL,
                    session_token VARCHAR(128) NOT NULL,
                    client_type ENUM(\'windows\', \'mobile\') NOT NULL,
                    client_label VARCHAR(160) NULL DEFAULT NULL,
                    ip_address VARCHAR(45) NULL DEFAULT NULL,
                    user_agent VARCHAR(255) NULL DEFAULT NULL,
                    latitude DECIMAL(10, 7) NULL DEFAULT NULL,
                    longitude DECIMAL(10, 7) NULL DEFAULT NULL,
                    location_text VARCHAR(255) NULL DEFAULT NULL,
                    login_at DATETIME NOT NULL,
                    last_seen_at DATETIME NOT NULL,
                    revoked_at DATETIME NULL DEFAULT NULL,
                    revoked_by INT UNSIGNED NULL DEFAULT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_open_session_token (session_token),
                    KEY idx_open_session_user (user_id),
                    KEY idx_open_session_active (revoked_at, last_seen_at),
                    KEY idx_open_session_type (client_type),
                    CONSTRAINT fk_open_session_user FOREIGN KEY (user_id) REFERENCES sys_user(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (Throwable $e2) {
            error_log('sys_user_open_session_ensure_schema: ' . $e2->getMessage());
        }
    }
}

function sys_user_open_session_client_ip(): string
{
    $candidates = [
        (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''),
        (string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''),
        (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''),
        (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
    ];
    foreach ($candidates as $raw) {
        $raw = trim($raw);
        if ($raw === '') {
            continue;
        }
        if (strpos($raw, ',') !== false) {
            $raw = trim(explode(',', $raw)[0]);
        }
        if (filter_var($raw, FILTER_VALIDATE_IP)) {
            return substr($raw, 0, 45);
        }
    }

    return '';
}

function sys_user_open_session_user_agent(): string
{
    $ua = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($ua === '') {
        return '';
    }

    return substr($ua, 0, 255);
}

function sys_user_open_session_windows_token(): string
{
    $sid = session_id();
    if ($sid === '') {
        return '';
    }

    return 'w:' . substr($sid, 0, 120);
}

function sys_user_open_session_mobile_token(string $deviceId): string
{
    $deviceId = preg_replace('/[^a-zA-Z0-9._-]/', '', $deviceId) ?? '';
    $deviceId = substr($deviceId, 0, 64);
    if ($deviceId === '') {
        return '';
    }

    return 'm:' . $deviceId;
}

/**
 * @param array{latitude?:float|null,longitude?:float|null,location_text?:string|null,client_label?:string|null} $extra
 */
function sys_user_open_session_register(
    PDO $pdo,
    int $userId,
    string $sessionToken,
    string $clientType,
    array $extra = []
): void {
    if ($userId < 1 || $sessionToken === '') {
        return;
    }
    $clientType = $clientType === 'mobile' ? 'mobile' : 'windows';
    sys_user_open_session_ensure_schema($pdo);

    $now = sys_user_open_session_now();
    $ip = sys_user_open_session_client_ip();
    $ua = sys_user_open_session_user_agent();
    $label = trim((string) ($extra['client_label'] ?? ''));
    if ($label === '') {
        $label = $clientType === 'mobile' ? 'هاتف' : 'Windows';
    }
    $label = substr($label, 0, 160);

    $lat = null;
    $lng = null;
    $latIn = $extra['latitude'] ?? null;
    $lngIn = $extra['longitude'] ?? null;
    if ($latIn !== null && $lngIn !== null && sal_invoice_gps_coords_valid((float) $latIn, (float) $lngIn)) {
        $lat = round((float) $latIn, 7);
        $lng = round((float) $lngIn, 7);
    }
    $locText = trim((string) ($extra['location_text'] ?? ''));
    if ($locText === '') {
        $locText = null;
    } else {
        $locText = substr($locText, 0, 255);
    }

    $_SESSION['open_session_token'] = $sessionToken;

    $pdo->prepare(
        'INSERT INTO sys_user_open_session
            (user_id, session_token, client_type, client_label, ip_address, user_agent,
             latitude, longitude, location_text, login_at, last_seen_at, revoked_at, revoked_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL)
         ON DUPLICATE KEY UPDATE
            user_id = VALUES(user_id),
            client_type = VALUES(client_type),
            client_label = COALESCE(VALUES(client_label), client_label),
            ip_address = COALESCE(VALUES(ip_address), ip_address),
            user_agent = COALESCE(VALUES(user_agent), user_agent),
            latitude = COALESCE(VALUES(latitude), latitude),
            longitude = COALESCE(VALUES(longitude), longitude),
            location_text = COALESCE(VALUES(location_text), location_text),
            last_seen_at = VALUES(last_seen_at),
            login_at = IF(sys_user_open_session.revoked_at IS NULL, sys_user_open_session.login_at, VALUES(login_at)),
            revoked_at = NULL,
            revoked_by = NULL'
    )->execute([
        $userId,
        $sessionToken,
        $clientType,
        $label,
        $ip !== '' ? $ip : null,
        $ua !== '' ? $ua : null,
        $lat,
        $lng,
        $locText,
        $now,
        $now,
    ]);
}

function sys_user_open_session_register_windows(int $userId): void
{
    if ($userId < 1) {
        return;
    }
    $token = sys_user_open_session_windows_token();
    if ($token === '') {
        return;
    }
    try {
        sys_user_open_session_register(db(), $userId, $token, 'windows', [
            'client_label' => 'Windows',
        ]);
    } catch (Throwable $e) {
        error_log('sys_user_open_session_register_windows: ' . $e->getMessage());
    }
}

function sys_user_open_session_register_mobile(
    int $userId,
    string $deviceId,
    ?string $deviceLabel = null,
    ?float $latitude = null,
    ?float $longitude = null
): void {
    if ($userId < 1) {
        return;
    }
    $token = sys_user_open_session_mobile_token($deviceId);
    if ($token === '') {
        return;
    }
    $label = trim((string) $deviceLabel);
    if ($label === '') {
        $label = 'هاتف';
    }
    try {
        sys_user_open_session_register(db(), $userId, $token, 'mobile', [
            'client_label' => $label,
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);
    } catch (Throwable $e) {
        error_log('sys_user_open_session_register_mobile: ' . $e->getMessage());
    }
}

/**
 * @param array{latitude?:float|null,longitude?:float|null,location_text?:string|null,client_label?:string|null} $extra
 */
function sys_user_open_session_touch(PDO $pdo, string $sessionToken, array $extra = []): void
{
    if ($sessionToken === '') {
        return;
    }
    sys_user_open_session_ensure_schema($pdo);
    $now = sys_user_open_session_now();
    $ip = sys_user_open_session_client_ip();

    $sets = ['last_seen_at = ?'];
    $params = [$now];

    if ($ip !== '') {
        $sets[] = 'ip_address = ?';
        $params[] = $ip;
    }

    $label = trim((string) ($extra['client_label'] ?? ''));
    if ($label !== '') {
        $sets[] = 'client_label = ?';
        $params[] = substr($label, 0, 160);
    }

    $latIn = $extra['latitude'] ?? null;
    $lngIn = $extra['longitude'] ?? null;
    if ($latIn !== null && $lngIn !== null && sal_invoice_gps_coords_valid((float) $latIn, (float) $lngIn)) {
        $sets[] = 'latitude = ?';
        $sets[] = 'longitude = ?';
        $params[] = round((float) $latIn, 7);
        $params[] = round((float) $lngIn, 7);
    }

    $locText = trim((string) ($extra['location_text'] ?? ''));
    if ($locText !== '') {
        $sets[] = 'location_text = ?';
        $params[] = substr($locText, 0, 255);
    }

    $params[] = $sessionToken;
    $pdo->prepare(
        'UPDATE sys_user_open_session
         SET ' . implode(', ', $sets) . '
         WHERE session_token = ? AND revoked_at IS NULL'
    )->execute($params);
}

function sys_user_open_session_is_revoked(PDO $pdo, string $sessionToken): bool
{
    if ($sessionToken === '') {
        return false;
    }
    sys_user_open_session_ensure_schema($pdo);
    $st = $pdo->prepare(
        'SELECT 1 FROM sys_user_open_session
         WHERE session_token = ? AND revoked_at IS NOT NULL
         LIMIT 1'
    );
    $st->execute([$sessionToken]);

    return (bool) $st->fetchColumn();
}

function sys_user_open_session_close_token(PDO $pdo, string $sessionToken): void
{
    if ($sessionToken === '') {
        return;
    }
    sys_user_open_session_ensure_schema($pdo);
    $pdo->prepare(
        'UPDATE sys_user_open_session
         SET revoked_at = COALESCE(revoked_at, ?), last_seen_at = ?
         WHERE session_token = ? AND revoked_at IS NULL'
    )->execute([sys_user_open_session_now(), sys_user_open_session_now(), $sessionToken]);
}

function sys_user_open_session_close_current(): void
{
    $token = (string) ($_SESSION['open_session_token'] ?? '');
    if ($token === '') {
        $ctx = (string) ($_SESSION['app_context'] ?? 'desktop');
        $token = $ctx === 'mobile' ? '' : sys_user_open_session_windows_token();
    }
    if ($token === '') {
        return;
    }
    try {
        sys_user_open_session_close_token(db(), $token);
    } catch (Throwable $e) {
        error_log('sys_user_open_session_close_current: ' . $e->getMessage());
    }
}

/**
 * يتحقق من إنهاء الجلسة من الإدارة ويحدّث آخر نشاط.
 * @return bool true إذا كانت الجلسة مُنهَاة ويجب تسجيل الخروج
 */
function sys_user_open_session_guard_current(): bool
{
    if (!is_logged_in()) {
        return false;
    }

    $uid = (int) (current_user()['id'] ?? 0);
    if ($uid < 1) {
        return false;
    }

    $ctx = (string) ($_SESSION['app_context'] ?? 'desktop');
    $token = (string) ($_SESSION['open_session_token'] ?? '');
    if ($token === '') {
        if ($ctx === 'mobile') {
            return false;
        }
        $token = sys_user_open_session_windows_token();
        if ($token === '') {
            return false;
        }
        $_SESSION['open_session_token'] = $token;
        // جلسة قديمة قبل إضافة التتبّع — سجّلها الآن.
        sys_user_open_session_register_windows($uid);
    }

    try {
        $pdo = db();
        if (sys_user_open_session_is_revoked($pdo, $token)) {
            return true;
        }

        $lastTouch = (int) ($_SESSION['open_session_touch_ts'] ?? 0);
        if (time() - $lastTouch >= 25) {
            sys_user_open_session_touch($pdo, $token);
            $_SESSION['open_session_touch_ts'] = time();
        }
    } catch (Throwable $e) {
        error_log('sys_user_open_session_guard_current: ' . $e->getMessage());
    }

    return false;
}

/**
 * إنهاء جلسة من الإدارة.
 * @return array{ok:bool, message:string}
 */
function sys_user_open_session_kill(PDO $pdo, int $sessionId, ?int $revokedBy = null): array
{
    if ($sessionId < 1) {
        return ['ok' => false, 'message' => 'معرّف الجلسة غير صالح.'];
    }
    sys_user_open_session_ensure_schema($pdo);

    $st = $pdo->prepare(
        'SELECT id, user_id, session_token, client_type, revoked_at
         FROM sys_user_open_session WHERE id = ? LIMIT 1'
    );
    $st->execute([$sessionId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return ['ok' => false, 'message' => 'الجلسة غير موجودة.'];
    }
    if (!empty($row['revoked_at'])) {
        return ['ok' => true, 'message' => 'الجلسة منتهية مسبقاً.'];
    }

    $now = sys_user_open_session_now();
    $pdo->prepare(
        'UPDATE sys_user_open_session
         SET revoked_at = ?, revoked_by = ?, last_seen_at = ?
         WHERE id = ?'
    )->execute([$now, $revokedBy, $now, $sessionId]);

    $token = (string) ($row['session_token'] ?? '');
    $userId = (int) ($row['user_id'] ?? 0);
    $type = (string) ($row['client_type'] ?? '');

    if ($type === 'mobile' && $userId > 0 && str_starts_with($token, 'm:')) {
        $deviceId = substr($token, 2);
        try {
            require_once app_path('includes/mobile_device_session.php');
            mobile_device_session_release($pdo, $userId, $deviceId);
        } catch (Throwable $e) {
            error_log('sys_user_open_session_kill mobile release: ' . $e->getMessage());
        }
    }

    return ['ok' => true, 'message' => 'تم إنهاء الجلسة. سيُفصل المستخدم عند الطلب التالي.'];
}

/**
 * @return list<array<string,mixed>>
 */
function sys_user_open_session_list_active(PDO $pdo, string $search = '', ?string $clientType = null): array
{
    sys_user_open_session_ensure_schema($pdo);
    app_apply_timezone();

    $winCutoff = date('Y-m-d H:i:s', time() - OPEN_SESSION_WINDOWS_ACTIVE_SECONDS);
    $mobCutoff = date('Y-m-d H:i:s', time() - OPEN_SESSION_MOBILE_ACTIVE_SECONDS);

    $sql = 'SELECT s.id, s.user_id, s.session_token, s.client_type, s.client_label,
                   s.ip_address, s.user_agent, s.latitude, s.longitude, s.location_text,
                   s.login_at, s.last_seen_at,
                   u.username, u.full_name_ar,
                   loc.gps_place, loc.gps_landmark, loc.latitude AS user_lat, loc.longitude AS user_lng
            FROM sys_user_open_session s
            INNER JOIN sys_user u ON u.id = s.user_id
            LEFT JOIN sys_user_location loc ON loc.user_id = s.user_id
            WHERE s.revoked_at IS NULL
              AND (
                    (s.client_type = \'windows\' AND s.last_seen_at >= ?)
                 OR (s.client_type = \'mobile\' AND s.last_seen_at >= ?)
              )';
    $params = [$winCutoff, $mobCutoff];

    if ($clientType === 'windows' || $clientType === 'mobile') {
        $sql .= ' AND s.client_type = ?';
        $params[] = $clientType;
    }

    $search = trim($search);
    if ($search !== '') {
        $sql .= ' AND (u.username LIKE ? OR u.full_name_ar LIKE ? OR s.ip_address LIKE ? OR s.client_label LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $sql .= ' ORDER BY s.last_seen_at DESC, s.id DESC LIMIT 500';

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as &$r) {
        $r['client_type_label'] = ((string) ($r['client_type'] ?? '') === 'mobile') ? 'Mobile' : 'Windows';
        $lat = $r['latitude'] ?? $r['user_lat'] ?? null;
        $lng = $r['longitude'] ?? $r['user_lng'] ?? null;
        $place = trim((string) ($r['location_text'] ?? ''));
        if ($place === '') {
            $place = trim((string) ($r['gps_place'] ?? ''));
        }
        $landmark = trim((string) ($r['gps_landmark'] ?? ''));
        if ($place === '' && $landmark !== '') {
            $place = $landmark;
        } elseif ($place !== '' && $landmark !== '' && stripos($place, $landmark) === false) {
            $place .= ' — ' . $landmark;
        }
        if ($place === '' && $lat !== null && $lng !== null) {
            $place = number_format((float) $lat, 5) . ', ' . number_format((float) $lng, 5);
        }
        $r['place_display'] = $place !== '' ? $place : '—';
        $r['map_url'] = ($lat !== null && $lng !== null)
            ? ('https://www.google.com/maps?q=' . rawurlencode((string) $lat . ',' . (string) $lng))
            : '';
        $r['login_at_hi'] = sys_user_open_session_fmt_dt((string) ($r['login_at'] ?? ''));
        $r['last_seen_hi'] = sys_user_open_session_fmt_dt((string) ($r['last_seen_at'] ?? ''));
    }
    unset($r);

    return $rows;
}

function sys_user_open_session_fmt_dt(string $datetime): string
{
    $datetime = trim($datetime);
    if ($datetime === '') {
        return '—';
    }
    app_apply_timezone();
    $ts = strtotime($datetime);

    return $ts !== false ? date('d/m/Y H:i:s', $ts) : $datetime;
}
