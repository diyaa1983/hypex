<?php
declare(strict_types=1);

require_once app_path('includes/login_security_schema.php');

function login_recaptcha_ensure_schema(PDO $pdo): void
{
    login_security_ensure_schema($pdo);
}

/** @return array{enabled:bool,site_key:string,secret_key:string} */
function login_recaptcha_settings(?PDO $pdo = null): array
{
    $out = ['enabled' => false, 'site_key' => '', 'secret_key' => ''];
    try {
        $pdo = $pdo ?? db();
        login_recaptcha_ensure_schema($pdo);
        $row = $pdo->query(
            'SELECT login_recaptcha_enabled, login_recaptcha_site_key, login_recaptcha_secret_key
             FROM sys_company_settings WHERE id = 1 LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $site = trim((string) ($row['login_recaptcha_site_key'] ?? ''));
        $secret = trim((string) ($row['login_recaptcha_secret_key'] ?? ''));
        $enabled = (int) ($row['login_recaptcha_enabled'] ?? 0) === 1;
        $out['site_key'] = $site;
        $out['secret_key'] = $secret;
        $out['enabled'] = $enabled && $site !== '' && $secret !== '';
    } catch (Throwable $e) {
        // ignored
    }

    return $out;
}

/** تخطي reCAPTCHA محلياً (localhost) أو عند LOGIN_RECAPTCHA_SKIP في config/app.local.php */
function login_recaptcha_skip_on_local(): bool
{
    if (defined('LOGIN_RECAPTCHA_SKIP') && LOGIN_RECAPTCHA_SKIP) {
        return true;
    }

    $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
    if ($host === '') {
        return false;
    }
    $host = preg_replace('/:\d+$/', '', $host) ?? $host;

    return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

function login_recaptcha_is_active(?PDO $pdo = null): bool
{
    if (login_recaptcha_skip_on_local()) {
        return false;
    }

    return login_recaptcha_settings($pdo)['enabled'];
}

function login_recaptcha_verify(?PDO $pdo, ?string $responseToken): bool
{
    if (login_recaptcha_skip_on_local()) {
        return true;
    }
    $cfg = login_recaptcha_settings($pdo);
    if (!$cfg['enabled']) {
        return true;
    }
    $token = trim((string) $responseToken);
    if ($token === '') {
        return false;
    }

    $post = http_build_query([
        'secret' => $cfg['secret_key'],
        'response' => $token,
        'remoteip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
    ]);

    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $post,
            'timeout' => 10,
        ],
    ]);

    $raw = @file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $ctx);
    if ($raw === false && function_exists('curl_init')) {
        $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
    }

    if (!is_string($raw) || $raw === '') {
        return false;
    }

    $data = json_decode($raw, true);

    return is_array($data) && !empty($data['success']);
}

function login_recaptcha_render_widget(?PDO $pdo = null): void
{
    if (!login_recaptcha_is_active($pdo)) {
        return;
    }
    $cfg = login_recaptcha_settings($pdo);
    ?>
    <div class="login-recaptcha-wrap">
        <div class="g-recaptcha" data-sitekey="<?= esc($cfg['site_key']) ?>"></div>
    </div>
    <?php
}

function login_recaptcha_script_tag(?PDO $pdo = null): string
{
    if (!login_recaptcha_is_active($pdo)) {
        return '';
    }

    return '<script src="https://www.google.com/recaptcha/api.js" async defer></script>';
}
