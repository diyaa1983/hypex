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
 *   days_left?:?int
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

    return [
        'ok' => true,
        'error' => '',
        'payload' => $payload,
        'expires_on' => $expiresOn,
        'issued_to' => $issuedTo,
        'days_left' => $daysLeft,
    ];
}

/** @return string رقم تفعيل بصيغة LIC1 */
function license_generate_key(string $fingerprintHash, string $secret, ?string $expiresOn = null, string $issuedTo = ''): string
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
    $ready = true;
}

/** @return array<string,mixed>|null */
function license_fetch_row(PDO $pdo): ?array
{
    $st = $pdo->query('SELECT * FROM sys_license WHERE id = 1 LIMIT 1');
    $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
    return is_array($row) ? $row : null;
}

/** @param array{payload?:array<string,mixed>,expires_on?:?string,issued_to?:string} $validated */
function license_store_row(PDO $pdo, string $licenseKey, array $validated): void
{
    $payloadJson = json_encode($validated['payload'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payloadJson === false) {
        $payloadJson = '{}';
    }

    $fingerprintHash = strtolower((string) (($validated['payload']['fp'] ?? '') ?: license_fingerprint_hash()));
    $issuedTo = trim((string) ($validated['issued_to'] ?? ''));
    $expiresOn = $validated['expires_on'] ?? null;
    if ($expiresOn !== null && trim((string) $expiresOn) === '') {
        $expiresOn = null;
    }

    $sql = 'INSERT INTO sys_license
            (id, license_key, fingerprint_hash, issued_to, expires_on, payload_json, activated_at, updated_at)
            VALUES (1, ?, ?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                license_key = VALUES(license_key),
                fingerprint_hash = VALUES(fingerprint_hash),
                issued_to = VALUES(issued_to),
                expires_on = VALUES(expires_on),
                payload_json = VALUES(payload_json),
                updated_at = NOW()';
    $st = $pdo->prepare($sql);
    $st->execute([
        trim($licenseKey),
        $fingerprintHash,
        $issuedTo !== '' ? $issuedTo : null,
        $expiresOn,
        $payloadJson,
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
 *   expires_on:?string,
 *   days_left:?int,
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
        'expires_on' => null,
        'days_left' => null,
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
    $validation = license_validate_key_for_fingerprint($storedKey, $fingerprintHash);
    if (!$validation['ok']) {
        $base['valid'] = false;
        $base['message'] = (string) $validation['error'];
        return $base;
    }

    $base['valid'] = true;
    $base['message'] = 'الترخيص صالح ومفعّل على هذا الجهاز.';
    $base['issued_to'] = (string) ($validation['issued_to'] ?? '');
    $base['expires_on'] = $validation['expires_on'] ?? null;
    $base['days_left'] = $validation['days_left'] ?? null;
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
    license_store_row($pdo, $licenseKey, $validation);
    $status = license_status($pdo);

    return [
        'ok' => (bool) ($status['valid'] ?? false),
        'message' => (bool) ($status['valid'] ?? false) ? 'تم حفظ رقم التفعيل بنجاح.' : 'تم الحفظ لكن التحقق فشل.',
        'status' => $status,
    ];
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
    if ($script === 'activate_license.php') {
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
