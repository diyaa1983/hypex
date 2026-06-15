<?php
declare(strict_types=1);

function license_is_enforced(): bool
{
    return defined('APP_LICENSE_ENFORCE') && APP_LICENSE_ENFORCE;
}

function license_secret(): string
{
    return defined('APP_LICENSE_SECRET') ? trim((string) APP_LICENSE_SECRET) : '';
}

function license_machine_id(): string
{
    $paths = [
        '/etc/machine-id',
        '/var/lib/dbus/machine-id',
    ];
    foreach ($paths as $path) {
        if (!is_readable($path)) {
            continue;
        }
        $raw = trim((string) file_get_contents($path));
        if ($raw !== '') {
            return $raw;
        }
    }

    return '';
}

/** @return array<string, string> */
function license_fingerprint_components(): array
{
    $parts = [
        'host' => strtolower(trim((string) (gethostname() ?: php_uname('n')))),
        'os' => strtolower(PHP_OS_FAMILY),
        'server_addr' => trim((string) ($_SERVER['SERVER_ADDR'] ?? '')),
        'app_root' => strtolower(str_replace('\\', '/', (string) realpath(APP_ROOT))),
        'machine_id' => strtolower(license_machine_id()),
        'computer_name' => strtolower(trim((string) getenv('COMPUTERNAME'))),
    ];

    foreach ($parts as $k => $v) {
        if ($v === '') {
            unset($parts[$k]);
        }
    }

    if ($parts === []) {
        $parts = [
            'fallback' => strtolower(PHP_OS_FAMILY . '|' . (string) php_uname()),
        ];
    }

    ksort($parts, SORT_STRING);
    return $parts;
}

function license_fingerprint_hash(): string
{
    $raw = json_encode(license_fingerprint_components(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($raw === false) {
        $raw = serialize(license_fingerprint_components());
    }

    return hash('sha256', (string) $raw);
}

function license_fingerprint_display(?string $hash = null): string
{
    $hash = strtolower(trim((string) ($hash ?? license_fingerprint_hash())));
    $short = strtoupper(substr($hash, 0, 32));
    return implode('-', str_split($short, 4));
}

function license_base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function license_base64url_decode(string $data): ?string
{
    if (!preg_match('/^[A-Za-z0-9\-_]+$/', $data)) {
        return null;
    }
    $padLen = (4 - (strlen($data) % 4)) % 4;
    $padded = $data . str_repeat('=', $padLen);
    $decoded = base64_decode(strtr($padded, '-_', '+/'), true);
    return $decoded === false ? null : $decoded;
}

function license_sign_payload(string $payloadB64, string $secret): string
{
    return hash_hmac('sha256', $payloadB64, $secret);
}

/** @return array{ok:bool,error:string,payload_b64?:string,payload?:array<string,mixed>,signature?:string} */
function license_parse_key(string $licenseKey): array
{
    $clean = preg_replace('/\s+/', '', trim($licenseKey)) ?? '';
    if ($clean === '') {
        return ['ok' => false, 'error' => 'أدخل رقم التفعيل.'];
    }
    if (!preg_match('/^LIC1\.([A-Za-z0-9\-_]+)\.([A-Fa-f0-9]{64})$/', $clean, $m)) {
        return ['ok' => false, 'error' => 'صيغة رقم التفعيل غير صحيحة.'];
    }

    $payloadB64 = (string) $m[1];
    $sig = strtolower((string) $m[2]);
    $payloadJson = license_base64url_decode($payloadB64);
    if ($payloadJson === null) {
        return ['ok' => false, 'error' => 'تعذر قراءة بيانات رقم التفعيل.'];
    }
    $payload = json_decode($payloadJson, true);
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'بيانات رقم التفعيل تالفة.'];
    }

    return [
        'ok' => true,
        'error' => '',
        'payload_b64' => $payloadB64,
        'payload' => $payload,
        'signature' => $sig,
    ];
}

/**
 * @return array{
 *   ok:bool,
 *   error:string,
 *   payload?:array<string,mixed>,
 *   expires_on?:?string,
 *   issued_to?:string,
 *   days_left?:?int,
 *   license_no?:string,
 *   max_users?:?int
 * }
 */
function license_validate_key_for_fingerprint(string $licenseKey, string $expectedFingerprintHash): array
{
    $secret = license_secret();
    if ($secret === '' || strlen($secret) < 16) {
        return ['ok' => false, 'error' => 'إعداد APP_LICENSE_SECRET غير مضبوط بشكل صحيح.'];
    }

    $parsed = license_parse_key($licenseKey);
    if (!$parsed['ok']) {
        return ['ok' => false, 'error' => $parsed['error']];
    }

    $payloadB64 = (string) ($parsed['payload_b64'] ?? '');
    $payload = (array) ($parsed['payload'] ?? []);
    $signature = (string) ($parsed['signature'] ?? '');

    $expectedSig = license_sign_payload($payloadB64, $secret);
    if (!hash_equals($expectedSig, $signature)) {
        return ['ok' => false, 'error' => 'رقم التفعيل غير موثق (توقيع غير صالح).'];
    }

    $licenseFp = strtolower(trim((string) ($payload['fp'] ?? '')));
    if (!preg_match('/^[a-f0-9]{64}$/', $licenseFp)) {
        return ['ok' => false, 'error' => 'رقم التفعيل لا يحتوي بصمة جهاز صحيحة.'];
    }

    $expectedFingerprintHash = strtolower(trim($expectedFingerprintHash));
    if (!hash_equals($expectedFingerprintHash, $licenseFp)) {
        return ['ok' => false, 'error' => 'رقم التفعيل لا يطابق بصمة هذا الجهاز.'];
    }

    $expiresOn = null;
    $daysLeft = null;
    $exp = trim((string) ($payload['exp'] ?? ''));
    if ($exp !== '') {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $exp, $mx)) {
            return ['ok' => false, 'error' => 'تاريخ انتهاء رقم التفعيل غير صالح.'];
        }
        $y = (int) $mx[1];
        $m = (int) $mx[2];
        $d = (int) $mx[3];
        if (!checkdate($m, $d, $y)) {
            return ['ok' => false, 'error' => 'تاريخ انتهاء رقم التفعيل غير صالح.'];
        }
        $expiresOn = sprintf('%04d-%02d-%02d', $y, $m, $d);
        $today = new DateTimeImmutable(date('Y-m-d'));
        $expDt = new DateTimeImmutable($expiresOn);
        $daysLeft = (int) $today->diff($expDt)->format('%r%a');
        if ($daysLeft < 0) {
            return ['ok' => false, 'error' => 'رقم التفعيل منتهي الصلاحية.'];
        }
    }

    $issuedTo = trim((string) ($payload['sub'] ?? $payload['customer'] ?? ''));
    $licenseNo = trim((string) ($payload['lic_no'] ?? $payload['license_no'] ?? ''));
    $maxUsers = null;
    if (array_key_exists('max_users', $payload) && trim((string) $payload['max_users']) !== '') {
        $tmpMaxUsers = (int) $payload['max_users'];
        if ($tmpMaxUsers <= 0) {
            return ['ok' => false, 'error' => 'قيمة الحد الأقصى للمستخدمين في رقم التفعيل غير صالحة.'];
        }
        $maxUsers = $tmpMaxUsers;
    }

    return [
        'ok' => true,
        'error' => '',
        'payload' => $payload,
        'expires_on' => $expiresOn,
        'issued_to' => $issuedTo,
        'days_left' => $daysLeft,
        'license_no' => $licenseNo,
        'max_users' => $maxUsers,
    ];
}

/** @return string رقم تفعيل بصيغة LIC1 */
function license_generate_key(
    string $fingerprintHash,
    string $secret,
    ?string $expiresOn = null,
    string $issuedTo = '',
    string $licenseNo = '',
    ?int $maxUsers = null
): string
{
    $fingerprintHash = strtolower(trim($fingerprintHash));
    if (!preg_match('/^[a-f0-9]{64}$/', $fingerprintHash)) {
        throw new InvalidArgumentException('Fingerprint hash must be 64-char hex SHA256.');
    }
    if (strlen($secret) < 16) {
        throw new InvalidArgumentException('Secret must be at least 16 chars.');
    }

    $payload = [
        'fp' => $fingerprintHash,
        'iat' => date('Y-m-d'),
    ];
    $issuedTo = trim($issuedTo);
    if ($issuedTo !== '') {
        $payload['sub'] = $issuedTo;
    }
    $licenseNo = trim($licenseNo);
    if ($licenseNo !== '') {
        $payload['lic_no'] = $licenseNo;
    }
    if ($maxUsers !== null) {
        if ($maxUsers <= 0) {
            throw new InvalidArgumentException('Max users must be greater than zero.');
        }
        $payload['max_users'] = $maxUsers;
    }
    if ($expiresOn !== null && trim($expiresOn) !== '') {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $expiresOn, $mx)) {
            throw new InvalidArgumentException('Expiry must be YYYY-MM-DD.');
        }
        if (!checkdate((int) $mx[2], (int) $mx[3], (int) $mx[1])) {
            throw new InvalidArgumentException('Expiry date is invalid.');
        }
        $payload['exp'] = sprintf('%04d-%02d-%02d', (int) $mx[1], (int) $mx[2], (int) $mx[3]);
    }

    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payloadJson === false) {
        throw new RuntimeException('Unable to encode license payload.');
    }
    $payloadB64 = license_base64url_encode($payloadJson);
    $sig = license_sign_payload($payloadB64, $secret);

    return 'LIC1.' . $payloadB64 . '.' . $sig;
}

function license_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    if (!function_exists('sql_migration_run_file_once')) {
        require_once app_path('includes/sql_migration.php');
    }
    sql_migration_run_file_once($pdo, 'database/migrations/151_sys_license.sql');
    sql_migration_run_file_once($pdo, 'database/migrations/152_sys_license_meta.sql');
    sql_migration_run_file_once($pdo, 'database/migrations/153_sys_user_license_no.sql');
    $ready = true;
}

/** @return array<string,mixed>|null */
function license_fetch_row(PDO $pdo): ?array
{
    $st = $pdo->query('SELECT * FROM sys_license WHERE id = 1 LIMIT 1');
    $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
    return is_array($row) ? $row : null;
}

/** @param array{payload?:array<string,mixed>,expires_on?:?string,issued_to?:string,license_no?:string,max_users?:?int} $validated */
function license_store_row(PDO $pdo, string $licenseKey, array $validated, ?array $actor = null): void
{
    $payloadJson = json_encode($validated['payload'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payloadJson === false) {
        $payloadJson = '{}';
    }

    $fingerprintHash = strtolower((string) (($validated['payload']['fp'] ?? '') ?: license_fingerprint_hash()));
    $issuedTo = trim((string) ($validated['issued_to'] ?? ''));
    $licenseNo = trim((string) ($validated['license_no'] ?? ''));
    $maxUsers = $validated['max_users'] ?? null;
    if ($maxUsers !== null) {
        $maxUsers = max(1, (int) $maxUsers);
    }
    $expiresOn = $validated['expires_on'] ?? null;
    if ($expiresOn !== null && trim((string) $expiresOn) === '') {
        $expiresOn = null;
    }
    $actorUserId = (int) ($actor['user_id'] ?? 0);
    $actorUsername = trim((string) ($actor['username'] ?? ''));
    $actorName = trim((string) ($actor['name'] ?? ''));

    $sql = 'INSERT INTO sys_license
            (id, license_key, fingerprint_hash, issued_to, license_no, max_users, expires_on, payload_json,
             activated_by_user_id, activated_by_username, activated_by_name, activated_at, updated_at)
            VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                license_key = VALUES(license_key),
                fingerprint_hash = VALUES(fingerprint_hash),
                issued_to = VALUES(issued_to),
                license_no = VALUES(license_no),
                max_users = VALUES(max_users),
                expires_on = VALUES(expires_on),
                payload_json = VALUES(payload_json),
                activated_by_user_id = VALUES(activated_by_user_id),
                activated_by_username = VALUES(activated_by_username),
                activated_by_name = VALUES(activated_by_name),
                updated_at = NOW()';
    $st = $pdo->prepare($sql);
    $st->execute([
        trim($licenseKey),
        $fingerprintHash,
        $issuedTo !== '' ? $issuedTo : null,
        $licenseNo !== '' ? $licenseNo : null,
        $maxUsers,
        $expiresOn,
        $payloadJson,
        $actorUserId > 0 ? $actorUserId : null,
        $actorUsername !== '' ? $actorUsername : null,
        $actorName !== '' ? $actorName : null,
    ]);
}

/** @return array{user_id:int,username:string,name:string} */
function license_activation_actor(): array
{
    $u = current_user();
    return [
        'user_id' => (int) ($u['id'] ?? 0),
        'username' => trim((string) ($u['username'] ?? '')),
        'name' => trim((string) ($u['full_name_ar'] ?? '')),
    ];
}

function license_count_active_users(PDO $pdo, ?string $licenseNo = null): int
{
    try {
        $licenseNo = strtoupper(trim((string) $licenseNo));
        if ($licenseNo !== '') {
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM sys_user
                 WHERE is_active = 1
                   AND UPPER(TRIM(COALESCE(license_no, ""))) = ?'
            );
            $st->execute([$licenseNo]);
            return (int) ($st->fetchColumn() ?: 0);
        }

        return (int) $pdo->query('SELECT COUNT(*) FROM sys_user WHERE is_active = 1')->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** @return list<array<string,mixed>> */
function license_active_linked_users(PDO $pdo, string $licenseNo, int $limit = 200): array
{
    $licenseNo = strtoupper(trim($licenseNo));
    if ($licenseNo === '') {
        return [];
    }
    $limit = max(1, min(500, $limit));
    try {
        $sql = 'SELECT id, username, full_name_ar, email
                FROM sys_user
                WHERE is_active = 1
                  AND UPPER(TRIM(COALESCE(license_no, ""))) = ?
                ORDER BY full_name_ar, username, id
                LIMIT ' . $limit;
        $st = $pdo->prepare($sql);
        $st->execute([$licenseNo]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        return [];
    }
}

/** @param array{issued_to?:string,license_no?:string,max_users?:?int} $validated */
function license_insert_activation_log(PDO $pdo, string $fingerprintHash, array $validated, array $actor): void
{
    $licenseNo = strtoupper(trim((string) ($validated['license_no'] ?? '')));
    $sql = 'INSERT INTO sys_license_activation_log
            (fingerprint_hash, license_no, issued_to, max_users, active_users,
             activated_by_user_id, activated_by_username, activated_by_name, activated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())';
    $st = $pdo->prepare($sql);
    $st->execute([
        $fingerprintHash,
        trim((string) ($validated['license_no'] ?? '')) !== '' ? trim((string) $validated['license_no']) : null,
        trim((string) ($validated['issued_to'] ?? '')) !== '' ? trim((string) $validated['issued_to']) : null,
        isset($validated['max_users']) && $validated['max_users'] !== null ? (int) $validated['max_users'] : null,
        license_count_active_users($pdo, $licenseNo),
        (int) ($actor['user_id'] ?? 0) > 0 ? (int) $actor['user_id'] : null,
        trim((string) ($actor['username'] ?? '')) !== '' ? trim((string) $actor['username']) : null,
        trim((string) ($actor['name'] ?? '')) !== '' ? trim((string) ($actor['name'] ?? '')) : null,
    ]);
}

function license_mask_key(string $key): string
{
    $key = trim($key);
    if ($key === '') {
        return '—';
    }
    if (strlen($key) <= 16) {
        return $key;
    }

    return substr($key, 0, 12) . '...' . substr($key, -8);
}

/**
 * @return array{
 *   enforced:bool,
 *   valid:bool,
 *   message:string,
 *   fingerprint_hash:string,
 *   fingerprint_display:string,
 *   issued_to:string,
 *   license_no:string,
 *   expires_on:?string,
 *   days_left:?int,
 *   max_users:?int,
 *   active_users:int,
 *   activated_by_username:string,
 *   activated_by_name:string,
 *   license_key_masked:string
 * }
 */
function license_status(PDO $pdo): array
{
    $fingerprintHash = license_fingerprint_hash();
    $base = [
        'enforced' => license_is_enforced(),
        'valid' => true,
        'message' => 'التحقق من الترخيص غير مفعّل حالياً.',
        'fingerprint_hash' => $fingerprintHash,
        'fingerprint_display' => license_fingerprint_display($fingerprintHash),
        'issued_to' => '',
        'license_no' => '',
        'expires_on' => null,
        'days_left' => null,
        'max_users' => null,
        'active_users' => 0,
        'activated_by_username' => '',
        'activated_by_name' => '',
        'license_key_masked' => '—',
    ];

    if (!$base['enforced']) {
        return $base;
    }

    if (license_secret() === '' || strlen(license_secret()) < 16) {
        $base['valid'] = false;
        $base['message'] = 'الترخيص مفعّل لكن المفتاح السري APP_LICENSE_SECRET غير مضبوط.';
        return $base;
    }

    license_ensure_schema($pdo);
    $row = license_fetch_row($pdo);
    if ($row === null) {
        $base['valid'] = false;
        $base['message'] = 'النظام غير مفعّل. أدخل رقم التفعيل أولاً.';
        return $base;
    }

    $storedKey = (string) ($row['license_key'] ?? '');
    $base['license_key_masked'] = license_mask_key($storedKey);
    $base['activated_by_username'] = trim((string) ($row['activated_by_username'] ?? ''));
    $base['activated_by_name'] = trim((string) ($row['activated_by_name'] ?? ''));
    $validation = license_validate_key_for_fingerprint($storedKey, $fingerprintHash);
    if (!$validation['ok']) {
        $base['valid'] = false;
        $base['message'] = (string) $validation['error'];
        return $base;
    }

    $base['valid'] = true;
    $base['message'] = 'الترخيص صالح ومفعّل على هذا الجهاز.';
    $base['issued_to'] = (string) ($validation['issued_to'] ?? '');
    $base['license_no'] = (string) ($validation['license_no'] ?? '');
    $base['expires_on'] = $validation['expires_on'] ?? null;
    $base['days_left'] = $validation['days_left'] ?? null;
    $base['max_users'] = $validation['max_users'] ?? null;
    $base['active_users'] = license_count_active_users($pdo, $base['license_no']);
    if ($base['license_no'] !== '' && $base['active_users'] <= 0) {
        $base['valid'] = false;
        $base['message'] = 'لا يوجد مستخدم مفعّل مرتبط بهذه النسخة. أدخل رقم تفعيل جديد أولاً.';
        return $base;
    }
    if ($base['max_users'] !== null && $base['active_users'] > (int) $base['max_users']) {
        $base['valid'] = false;
        $base['message'] = 'عدد المستخدمين النشطين يتجاوز الحد المرخّص به لهذا الرقم.';
    }
    return $base;
}

/** @return array{ok:bool,message:string,status:array<string,mixed>} */
function license_activate(PDO $pdo, string $licenseKey): array
{
    $fingerprintHash = license_fingerprint_hash();
    $validation = license_validate_key_for_fingerprint($licenseKey, $fingerprintHash);
    if (!$validation['ok']) {
        return [
            'ok' => false,
            'message' => (string) $validation['error'],
            'status' => license_status($pdo),
        ];
    }

    license_ensure_schema($pdo);
    $actor = license_activation_actor();
    license_store_row($pdo, $licenseKey, $validation, $actor);
    license_insert_activation_log($pdo, $fingerprintHash, $validation, $actor);
    $status = license_status($pdo);

    return [
        'ok' => (bool) ($status['valid'] ?? false),
        'message' => (bool) ($status['valid'] ?? false) ? 'تم حفظ رقم التفعيل بنجاح.' : 'تم الحفظ لكن التحقق فشل.',
        'status' => $status,
    ];
}

function license_deactivate(PDO $pdo): void
{
    license_ensure_schema($pdo);
    $pdo->prepare('DELETE FROM sys_license WHERE id = 1')->execute();
}

/** @return list<array<string,mixed>> */
function license_recent_activation_logs(PDO $pdo, int $limit = 20): array
{
    $limit = max(1, min(100, $limit));
    try {
        $sql = 'SELECT id, fingerprint_hash, license_no, issued_to, max_users, active_users,
                       activated_by_username, activated_by_name, activated_at
                FROM sys_license_activation_log
                ORDER BY id DESC
                LIMIT ' . $limit;
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        return [];
    }
}

function license_delete_activation_log(PDO $pdo, int $logId): void
{
    if ($logId <= 0) {
        return;
    }
    $st = $pdo->prepare('DELETE FROM sys_license_activation_log WHERE id = ?');
    $st->execute([$logId]);
}

/** @return array{ok:bool,error:string} */
function license_user_binding_check_for_login(PDO $pdo, int $userId): array
{
    if ($userId <= 0 || !license_is_enforced()) {
        return ['ok' => true, 'error' => ''];
    }
    if (user_is_system_admin($userId)) {
        return ['ok' => true, 'error' => ''];
    }

    $status = license_status($pdo);
    if (!($status['valid'] ?? false)) {
        return ['ok' => false, 'error' => (string) ($status['message'] ?? 'النظام غير مرخّص.')];
    }

    $currentLicenseNo = strtoupper(trim((string) ($status['license_no'] ?? '')));
    if ($currentLicenseNo === '') {
        return ['ok' => false, 'error' => 'رقم النسخة غير محدد في الترخيص الحالي.'];
    }

    $st = $pdo->prepare('SELECT license_no FROM sys_user WHERE id = ? LIMIT 1');
    $st->execute([$userId]);
    $userLicenseNo = strtoupper(trim((string) ($st->fetchColumn() ?: '')));
    if ($userLicenseNo === '') {
        return ['ok' => false, 'error' => 'حسابك غير مربوط برقم تفعيل. راجع مسؤول النظام.'];
    }
    if (!hash_equals($currentLicenseNo, $userLicenseNo)) {
        return ['ok' => false, 'error' => 'رقم تفعيل المستخدم لا يطابق رقم النسخة الحالية.'];
    }

    return ['ok' => true, 'error' => ''];
}

function license_safe_next_url(?string $next): string
{
    $fallback = app_url('index.php');
    $next = trim((string) $next);
    if ($next === '') {
        return $fallback;
    }
    if (str_starts_with($next, 'http://') || str_starts_with($next, 'https://')) {
        return $fallback;
    }
    if (!str_starts_with($next, '/')) {
        return $fallback;
    }

    $base = rtrim((string) APP_URL_BASE, '/');
    if ($base !== '' && $next !== $base && !str_starts_with($next, $base . '/')) {
        return $fallback;
    }

    return $next;
}

function license_guard_or_redirect(): void
{
    if (!license_is_enforced() || PHP_SAPI === 'cli') {
        return;
    }

    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (
        $script === 'activate_license.php'
        || $script === 'login.php'
        || $script === 'forgot_password.php'
        || $script === 'reset_password.php'
    ) {
        return;
    }

    $user = current_user();
    $currentUserId = (int) ($user['id'] ?? 0);
    if ($currentUserId > 0 && user_is_system_admin($currentUserId)) {
        // المستخدم من مجموعة ADMINS لا يحتاج تفعيل.
        return;
    }

    try {
        $status = license_status(db());
    } catch (Throwable $e) {
        $status = [
            'valid' => false,
            'message' => 'تعذر التحقق من حالة الترخيص: ' . $e->getMessage(),
        ];
    }

    if (!($status['valid'] ?? false)) {
        $next = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $target = app_url('activate_license.php');
        if ($next !== '' && !str_contains($next, 'activate_license.php')) {
            $target .= '?next=' . rawurlencode($next);
        }
        redirect($target);
    }
}
